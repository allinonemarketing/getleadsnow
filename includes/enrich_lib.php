<?php
/**
 * Shared enrichment-result handling.
 *
 * Used by BOTH webhook_scrape.php (legacy inbound path, kept for predictions
 * already in flight) and search_worker.php (the current path: workers POLL
 * Replicate for results). Polling replaced webhooks because the inbound
 * multi-MB webhook POSTs were inspected by the host's WAF (Imunify), which
 * ballooned to 3GB+ RSS and took the server down. Outbound polling is never
 * WAF-inspected. Processing is idempotent — double-handling a result is safe.
 */

require_once __DIR__ . '/../config/rapidapi.php';

/**
 * Apply one Replicate prediction result to a lead.
 * Returns 'completed', 'failed', or 'pending' (prediction not finished yet).
 */
function processEnrichmentResult(PDO $pdo, int $leadId, int $listId, array $prediction): string {
    $replicateId = $prediction['id'] ?? '';
    if ($replicateId) {
        $pdo->prepare("UPDATE lead_list_items SET replicate_id = COALESCE(replicate_id, ?) WHERE id = ? AND list_id = ?")
            ->execute([$replicateId, $leadId, $listId]);
    }

    $status = $prediction['status'] ?? '';
    if ($status !== 'succeeded') {
        if (in_array($status, ['failed', 'canceled'])) {
            $pdo->prepare("UPDATE lead_list_items SET enriched_at = NOW(), enrichment_status = 'failed' WHERE id = ? AND list_id = ?")
                ->execute([$leadId, $listId]);
            return 'failed';
        }
        return 'pending';   // starting / processing — try again later
    }

    $output = $prediction['output'] ?? [];

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
        return 'completed';
    }

    $stmt = $pdo->prepare("SELECT emails, social_media_links FROM lead_list_items WHERE id = ? AND list_id = ?");
    $stmt->execute([$leadId, $listId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $existingEmails = json_decode($existing['emails'] ?: '[]', true) ?: [];
        $existingSocials = json_decode($existing['social_media_links'] ?: '[]', true) ?: [];

        $mergedEmails = array_values(array_unique(array_merge($existingEmails, $cleanEmails)));
        $mergedSocials = array_values(array_unique(array_merge($existingSocials, $socialLinks)));

        $pdo->prepare("UPDATE lead_list_items SET emails = ?, social_media_links = ?, has_email = ?, has_socials = ?, enriched_at = NOW(), enrichment_status = 'completed' WHERE id = ? AND list_id = ?")
            ->execute([json_encode($mergedEmails), json_encode($mergedSocials), !empty($mergedEmails) ? 1 : 0, !empty($mergedSocials) ? 1 : 0, $leadId, $listId]);
    }
    return 'completed';
}

/**
 * Fetch one prediction from Replicate (outbound GET — never WAF-inspected).
 * Returns the decoded prediction array, or null on transport/HTTP error.
 */
function replicateFetchPrediction(string $replicateId): ?array {
    $ch = curl_init('https://api.replicate.com/v1/predictions/' . urlencode($replicateId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . REPLICATE_API_KEY],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15
    ]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $http < 200 || $http >= 300) return null;
    $decoded = json_decode($res, true);
    return is_array($decoded) ? $decoded : null;
}
