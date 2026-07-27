<?php
require_once __DIR__ . '/config/database.php';

$now = gmdate('Y-m-d H:i:s');

$stmt = $pdo->prepare("
    SELECT il.*, gc.api_key, gc.location_id
    FROM ghl_import_logs il
    LEFT JOIN ghl_connections gc ON il.connection_id = gc.id
    WHERE il.status = 'pending'
      AND il.drip_enabled = 1
      AND il.drip_next_batch_at IS NOT NULL
      AND il.drip_next_batch_at <= ?
    ORDER BY il.drip_next_batch_at ASC
    LIMIT 5
");
$stmt->execute([$now]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($jobs)) exit;

$markStmt = $pdo->prepare("UPDATE lead_list_items SET outreach_email = 1, ghl_contact_id = ?, pipeline_stage = CASE WHEN pipeline_stage = 'new' THEN 'contacted' ELSE pipeline_stage END, first_contacted_at = COALESCE(first_contacted_at, NOW()) WHERE id = ? AND user_id = ?");
$updateItem = $pdo->prepare("UPDATE ghl_import_items SET status = ?, ghl_contact_id = ?, is_new = ?, error_message = ? WHERE id = ?");

foreach ($jobs as $job) {
    $importId = $job['id'];
    $userId = $job['user_id'];
    $apiKey = $job['api_key'];
    $locationId = $job['location_id'];

    if (empty($apiKey) || empty($locationId)) {
        if (!$job['connection_id']) {
            $fc = $pdo->prepare("SELECT api_key, location_id FROM ghl_connections WHERE user_id = ? ORDER BY created_at ASC LIMIT 1");
            $fc->execute([$userId]);
            $fallback = $fc->fetch(PDO::FETCH_ASSOC);
            $apiKey = $fallback['api_key'] ?? '';
            $locationId = $fallback['location_id'] ?? '';
        }
        if (empty($apiKey) || empty($locationId)) {
            $pdo->prepare("UPDATE ghl_import_logs SET status = 'failed', errors = JSON_SET(COALESCE(errors, '[]'), '$[0]', 'GHL credentials missing') WHERE id = ?")->execute([$importId]);
            continue;
        }
    }

    $tags = json_decode($job['tags'] ?: '[]', true);
    $workflowId = $job['workflow_id'];
    $batchSize = $job['drip_batch_size'] ?: 10;

    $pdo->prepare("UPDATE ghl_import_logs SET status = 'running', drip_next_batch_at = NULL WHERE id = ?")->execute([$importId]);

    $pendingStmt = $pdo->prepare("SELECT * FROM ghl_import_items WHERE import_log_id = ? AND status = 'pending' ORDER BY id ASC LIMIT ?");
    $pendingStmt->bindValue(1, (int)$importId, PDO::PARAM_INT);
    $pendingStmt->bindValue(2, $batchSize, PDO::PARAM_INT);
    $pendingStmt->execute();
    $items = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

    $results = ['imported' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [], 'processed' => 0];

    foreach ($items as $item) {
        $lead = json_decode($item['lead_data'], true);
        $contactData = [
            'locationId' => $locationId,
            'firstName' => $lead['firstName'] ?? '',
            'lastName' => $lead['lastName'] ?? '',
            'companyName' => $lead['companyName'] ?? '',
            'email' => $lead['email'] ?? '',
            'phone' => $lead['phone'] ?? '',
            'website' => $lead['website'] ?? '',
            'address1' => $lead['address'] ?? '',
            'city' => $lead['city'] ?? '',
            'state' => $lead['state'] ?? '',
            'tags' => !empty($tags) ? $tags : [],
            'source' => 'Lead Lists CRM'
        ];
        foreach (['firstName','lastName','companyName','email','phone','website','address1','city','state'] as $k) {
            if (empty($contactData[$k])) unset($contactData[$k]);
        }
        if (empty($contactData['tags'])) unset($contactData['tags']);

        $ch = curl_init('https://services.leadconnectorhq.com/contacts/upsert');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Version: 2021-07-28',
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($contactData),
            CURLOPT_TIMEOUT => 10
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $parsed = json_decode($resp, true);
        $contactId = $parsed['contact']['id'] ?? null;

        if ($code >= 200 && $code < 300 && $contactId) {
            $isNew = ($parsed['new'] ?? false) === true;
            if ($isNew) $results['imported']++;
            else $results['updated']++;

            $updateItem->execute(['success', $contactId, $isNew ? 1 : 0, null, $item['id']]);

            if ($workflowId && $contactId) {
                $wch = curl_init("https://services.leadconnectorhq.com/contacts/{$contactId}/workflow/{$workflowId}");
                curl_setopt_array($wch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $apiKey,
                        'Version: 2021-07-28',
                        'Content-Type: application/json'
                    ],
                    CURLOPT_POSTFIELDS => '{}',
                    CURLOPT_TIMEOUT => 5
                ]);
                curl_exec($wch);
                curl_close($wch);
            }

            if ($item['lead_id']) {
                $markStmt->execute([$contactId, $item['lead_id'], $userId]);
            }
        } else {
            $results['failed']++;
            $errMsg = $parsed['message'] ?? $parsed['error'] ?? "HTTP $code";
            $results['errors'][] = ($lead['companyName'] ?? 'Unknown') . ': ' . $errMsg;
            $updateItem->execute(['failed', null, 0, $errMsg, $item['id']]);
        }

        $results['processed']++;
        usleep(200000);
    }

    $existingErrors = $pdo->prepare("SELECT errors FROM ghl_import_logs WHERE id = ?");
    $existingErrors->execute([$importId]);
    $currentErrors = json_decode($existingErrors->fetchColumn() ?: '[]', true) ?: [];
    $mergedErrors = array_merge($currentErrors, $results['errors']);

    $pdo->prepare("UPDATE ghl_import_logs SET imported = imported + ?, updated = updated + ?, failed = failed + ?, processed = processed + ?, errors = ? WHERE id = ?")->execute([
        $results['imported'], $results['updated'], $results['failed'], $results['processed'],
        json_encode($mergedErrors), $importId
    ]);

    $remainStmt = $pdo->prepare("SELECT COUNT(*) FROM ghl_import_items WHERE import_log_id = ? AND status = 'pending'");
    $remainStmt->execute([$importId]);
    $remaining = (int)$remainStmt->fetchColumn();

    if ($remaining === 0) {
        $pdo->prepare("UPDATE ghl_import_logs SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$importId]);
    } else {
        $tz = new DateTimeZone($job['drip_timezone'] ?: 'UTC');
        $next = new DateTime('now', $tz);
        $next->modify('+' . $job['drip_interval_minutes'] . ' minutes');
        $next->setTimezone(new DateTimeZone('UTC'));
        $pdo->prepare("UPDATE ghl_import_logs SET status = 'pending', drip_next_batch_at = ? WHERE id = ?")->execute([$next->format('Y-m-d H:i:s'), $importId]);
    }
}
