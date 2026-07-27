<?php
require_once 'config/database.php';

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data || !isset($data['id'])) {
    http_response_code(400);
    exit;
}

$leadId = intval($_GET['lead_id'] ?? 0);
$listId = intval($_GET['list_id'] ?? 0);
$replicateId = $data['id'] ?? '';

if (!$leadId || !$listId) {
    http_response_code(200);
    exit;
}

if ($replicateId) {
    $pdo->prepare("UPDATE lead_list_items SET replicate_id = COALESCE(replicate_id, ?) WHERE id = ? AND list_id = ?")
        ->execute([$replicateId, $leadId, $listId]);
}

$status = $data['status'] ?? '';
if ($status !== 'succeeded') {
    if (in_array($status, ['failed', 'canceled'])) {
        $pdo->prepare("UPDATE lead_list_items SET enriched_at = NOW(), enrichment_status = 'failed' WHERE id = ? AND list_id = ?")
            ->execute([$leadId, $listId]);
    }
    http_response_code(200);
    exit;
}

$output = $data['output'] ?? [];

$rawEmails = $output['emails'] ?? [];
if (!is_array($rawEmails)) $rawEmails = [];
$cleanEmails = [];
foreach ($rawEmails as $email) {
    if (is_array($email)) $email = $email['email'] ?? $email['value'] ?? reset($email);
    $email = strtolower(trim((string)$email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    $cleanEmails[] = $email;
}
$cleanEmails = array_values(array_unique(array_slice($cleanEmails, 0, 10)));

$rawSocials = $output['social_links'] ?? [];
$socialLinks = [];
if (is_array($rawSocials)) {
    foreach ($rawSocials as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $url) {
                if (is_string($url) && $url !== '') $socialLinks[] = $url;
            }
        } elseif (is_string($value) && $value !== '') {
            $socialLinks[] = $value;
        }
    }
}
$socialLinks = array_values(array_unique(array_slice($socialLinks, 0, 20)));

if (empty($cleanEmails) && empty($socialLinks)) {
    $pdo->prepare("UPDATE lead_list_items SET enriched_at = NOW(), enrichment_status = 'completed' WHERE id = ? AND list_id = ?")
        ->execute([$leadId, $listId]);
    http_response_code(200);
    exit;
}

$stmt = $pdo->prepare("SELECT emails, social_media_links FROM lead_list_items WHERE id = ? AND list_id = ?");
$stmt->execute([$leadId, $listId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    $existingEmails = json_decode($existing['emails'] ?: '[]', true);
    $existingSocials = json_decode($existing['social_media_links'] ?: '[]', true);

    $mergedEmails = array_values(array_unique(array_merge($existingEmails, $cleanEmails)));
    $mergedSocials = array_values(array_unique(array_merge($existingSocials, $socialLinks)));

    $pdo->prepare("UPDATE lead_list_items SET emails = ?, social_media_links = ?, has_email = ?, has_socials = ?, enriched_at = NOW(), enrichment_status = 'completed' WHERE id = ? AND list_id = ?")
        ->execute([json_encode($mergedEmails), json_encode($mergedSocials), !empty($mergedEmails) ? 1 : 0, !empty($mergedSocials) ? 1 : 0, $leadId, $listId]);
}

http_response_code(200);
