<?php
session_start();
require_once 'config/database.php';
require_once 'config/rapidapi.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['data' => [], 'error' => 'Not authenticated']);
    exit;
}

// Release the session lock now that we've authenticated. PHP holds an exclusive
// lock on the session file for the whole request; without this, the up-to-30s
// external API curl below would block every other request from the same user
// (page navigation, other searches) until it finishes — making the whole app
// feel frozen during a search. We don't touch $_SESSION past this point.
session_write_close();

header('Content-Type: application/json');

$type = $_GET['type'] ?? 'search';

if ($type === 'search') {
    $term = trim($_GET['term'] ?? '');
    $city = trim($_GET['city'] ?? '');
    $state = trim($_GET['state'] ?? '');
    if ($term !== '' && ($city !== '' || $state !== '')) {
        $location = trim($city . ($city !== '' && $state !== '' ? ', ' : '') . $state);
        $query = $term . ' in ' . $location;
    } else {
        $query = $_GET['query'] ?? '';
    }
    $limit = intval($_GET['limit'] ?? 20);
    if (!$query) { echo json_encode(['data' => []]); exit; }
    $url = 'https://maps-data.p.rapidapi.com/searchmaps.php?query=' . urlencode($query) . '&limit=' . $limit . '&country=us&lang=en';
    $headers = ['x-rapidapi-host: ' . RAPIDAPI_HOST, 'x-rapidapi-key: ' . RAPIDAPI_KEY];
} elseif ($type === 'reviews') {
    $businessId = $_GET['business_id'] ?? '';
    $sort = $_GET['sort'] ?? 'most_relevant';
    if (!$businessId) { echo json_encode(['data' => []]); exit; }
    $url = 'https://maps-data.p.rapidapi.com/reviews.php?business_id=' . urlencode($businessId) . '&country=us&lang=en&limit=20&sort=' . urlencode($sort);
    $headers = ['x-rapidapi-host: ' . RAPIDAPI_HOST, 'x-rapidapi-key: ' . RAPIDAPI_KEY];
} elseif ($type === 'scrape') {
    $siteUrl = $_GET['url'] ?? '';
    if (!$siteUrl) { echo json_encode(['success' => false]); exit; }
    $url = 'https://website-scraper-api.p.rapidapi.com/scrape2.php?url=' . urlencode($siteUrl);
    $headers = ['x-rapidapi-host: website-scraper-api.p.rapidapi.com', 'x-rapidapi-key: ' . RAPIDAPI_KEY];
} else {
    echo json_encode(['error' => 'Invalid type']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    // Bound how long one worker is pinned on the upstream API. Without a connect
    // timeout a black-holed RapidAPI endpoint would hold a worker for the full 30s;
    // at hundreds of concurrent searches that exhausts the FPM pool.
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15
]);
$result = curl_exec($ch);
curl_close($ch);

echo $result ?: json_encode(['data' => []]);
