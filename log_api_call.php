<?php
header('Content-Type: application/json');
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];

// NOTE: Billing moved to a per-lead model.
//   - 1 credit per NEW lead saved  -> charged in leadlists.php (addLeads)
//   - 1 credit per enrichment ATTEMPT -> charged in leadlists.php (fireAllScrapes / retryFailedEnrichments)
// This endpoint no longer deducts credits or blocks on balance. It only records the
// search in api_calls for history/analytics, and always succeeds.

if (!isset($data['scraper_model'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Search model is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO api_calls
        (user_id, credits_used, scraper_model, url, search_query, input_params, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $url          = $data['url'] ?? null;
    $search_query = $data['search_query'] ?? '';
    $status       = $data['status'] ?? 'pending';

    // Log only. credits_used is recorded for reference but is NOT deducted here anymore.
    $creditsUsed = isset($data['credits_used']) && is_numeric($data['credits_used']) ? (int)$data['credits_used'] : 0;

    $input_params = null;
    if (isset($data['input_params'])) {
        $input_params = is_string($data['input_params']) ? $data['input_params'] : json_encode($data['input_params']);
    }

    $stmt->execute([
        $_SESSION['user_id'],
        $creditsUsed,
        $data['scraper_model'],
        $url,
        $search_query,
        $input_params,
        $status
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    // Even if logging fails, never block the search — billing happens elsewhere.
    error_log('log_api_call logging error: ' . $e->getMessage());
    echo json_encode(['success' => true]);
}
