<?php
// LEGACY inbound path: kept only for predictions created before the switch to
// worker-side polling (see includes/enrich_lib.php for why). New predictions are
// created WITHOUT a webhook and their results are fetched by search_worker.php.
require_once 'config/database.php';
require_once __DIR__ . '/includes/enrich_lib.php';

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data || !isset($data['id'])) {
    http_response_code(400);
    exit;
}

$leadId = intval($_GET['lead_id'] ?? 0);
$listId = intval($_GET['list_id'] ?? 0);

if (!$leadId || !$listId) {
    http_response_code(200);
    exit;
}

processEnrichmentResult($pdo, $leadId, $listId, $data);
http_response_code(200);
