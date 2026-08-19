<?php
/**
 * Shared search + lead-ingest library.
 *
 * Used by BOTH the synchronous addLeads endpoint (leadlists.php) and the
 * background search worker (search_worker.php) so there is a single source of
 * truth for billing (1 credit per lead actually inserted) and for the RapidAPI
 * call. Do not duplicate this logic anywhere else.
 */

require_once __DIR__ . '/../config/rapidapi.php';

/**
 * Insert a batch of leads into a list, charging 1 credit per lead actually
 * inserted. Dedups against existing business_ids in the list. Concurrency-safe:
 * the credit reserve is an atomic, row-locked step (prevents double-spend across
 * parallel per-city ingests) and is released before the inserts.
 *
 * @return array{inserted:int, skipped:int, skipped_no_credit:int}
 */
function ingestLeads(PDO $pdo, int $userId, int $listId, array $leads): array {
    if (empty($leads)) {
        return ['inserted' => 0, 'skipped' => 0, 'skipped_no_credit' => 0];
    }

    // Existing business_ids in this list, for dedup.
    $existingIds = [];
    $chk = $pdo->prepare("SELECT business_id FROM lead_list_items WHERE list_id = ? AND user_id = ? AND business_id IS NOT NULL");
    $chk->execute([$listId, $userId]);
    while ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
        $existingIds[$row['business_id']] = true;
    }

    $skipped = 0;
    $toInsert = [];
    foreach ($leads as $lead) {
        $bid = $lead['business_id'] ?? null;
        if ($bid && isset($existingIds[$bid])) { $skipped++; continue; }
        if ($bid) $existingIds[$bid] = true;
        $toInsert[] = $lead;
    }
    $n = count($toInsert);

    // Reserve credits in ONE atomic, row-locked step (1 credit/lead).
    $charge = 0;
    if ($n > 0) {
        try {
            $pdo->beginTransaction();
            $bs = $pdo->prepare("SELECT credits FROM users WHERE id = ? FOR UPDATE");
            $bs->execute([$userId]);
            $bal = (int)$bs->fetchColumn();
            $charge = min($n, $bal);
            if ($charge > 0) {
                $pdo->prepare("UPDATE users SET credits = credits - ? WHERE id = ?")->execute([$charge, $userId]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("ingestLeads: credit reserve failed: " . $e->getMessage());
            $charge = 0;
        }
    }
    $skippedNoCredit = $n - $charge;

    // Batched multi-row insert of the first $charge leads.
    $inserted = 0;
    $chargedLeads = array_slice($toInsert, 0, $charge);
    $colCount = 22;
    $rowPh = '(' . rtrim(str_repeat('?,', $colCount), ',') . ')';
    $insCols = "(list_id, user_id, business_id, business_name, address, city, state, phone, website,
         rating, review_count, types, latitude, longitude, emails, social_media_links, raw_data,
         has_email, has_socials, has_phone, has_website, has_notes)";
    $rowVals = function($lead) use ($listId, $userId) {
        $leadEmails = $lead['emails'] ?? [];
        $leadSocials = $lead['social_media_links'] ?? [];
        $leadPhone = $lead['phone_number'] ?? $lead['phone'] ?? '';
        $leadWebsite = $lead['website'] ?? '';
        return [
            $listId, $userId,
            $lead['business_id'] ?? null,
            $lead['name'] ?? $lead['business_name'] ?? '',
            $lead['full_address'] ?? $lead['address'] ?? '',
            $lead['city'] ?? '',
            $lead['state'] ?? '',
            $leadPhone,
            $leadWebsite,
            $lead['rating'] ?? null,
            $lead['review_count'] ?? 0,
            is_array($lead['types'] ?? null) ? implode(', ', $lead['types']) : ($lead['types'] ?? ''),
            $lead['latitude'] ?? null,
            $lead['longitude'] ?? null,
            json_encode($leadEmails),
            json_encode($leadSocials),
            json_encode($lead),
            !empty($leadEmails) ? 1 : 0,
            !empty($leadSocials) ? 1 : 0,
            ($leadPhone !== '') ? 1 : 0,
            ($leadWebsite !== '') ? 1 : 0,
            0
        ];
    };
    $stmtOne = $pdo->prepare("INSERT INTO lead_list_items $insCols VALUES $rowPh");
    foreach (array_chunk($chargedLeads, 100) as $chunk) {
        $params = [];
        foreach ($chunk as $lead) { foreach ($rowVals($lead) as $v) { $params[] = $v; } }
        $sqlValues = rtrim(str_repeat($rowPh . ',', count($chunk)), ',');
        try {
            $pdo->prepare("INSERT INTO lead_list_items $insCols VALUES $sqlValues")->execute($params);
            $inserted += count($chunk);
        } catch (Exception $e) {
            error_log("ingestLeads: batch insert failed, retrying row-by-row: " . $e->getMessage());
            foreach ($chunk as $lead) {
                try { $stmtOne->execute($rowVals($lead)); $inserted++; }
                catch (Exception $e2) { error_log("ingestLeads: row insert failed: " . $e2->getMessage()); }
            }
        }
    }

    // Refund credits for any charged leads that ultimately failed to insert.
    if ($charge > $inserted) {
        try { $pdo->prepare("UPDATE users SET credits = credits + ? WHERE id = ?")->execute([$charge - $inserted, $userId]); } catch (Exception $e) {}
    }

    return ['inserted' => $inserted, 'skipped' => $skipped, 'skipped_no_credit' => $skippedNoCredit];
}

/**
 * Run one RapidAPI Google-Maps search.
 *
 * @return array{ok:bool, http:int, data:array} ok=false means a transport/HTTP
 *         error (including 429 rate-limit) — caller should retry. data is the
 *         array of raw business results (possibly empty on a genuine no-match).
 */
function rapidMapsSearch(string $query, int $limit): array {
    $url = 'https://maps-data.p.rapidapi.com/searchmaps.php?query=' . urlencode($query) . '&limit=' . $limit . '&country=us&lang=en';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['x-rapidapi-host: ' . RAPIDAPI_HOST, 'x-rapidapi-key: ' . RAPIDAPI_KEY],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20
    ]);
    $result = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result === false) {
        return ['ok' => false, 'http' => 0, 'data' => []];
    }
    $decoded = json_decode($result, true);
    $ok = ($http >= 200 && $http < 300);
    return ['ok' => $ok, 'http' => $http, 'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : []];
}
