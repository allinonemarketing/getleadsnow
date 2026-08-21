<?php
/**
 * Landing-page analytics beacon — records page views, time-on-page and scroll
 * depth for the public ad pages. Feeds the "Landing Pages" card in the admin.
 *
 * Deliberately session-free (a beacon must never queue behind the PHP session
 * lock) and fire-and-forget: any failure returns empty JSON and the visitor
 * never notices.
 */

header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('{}'); }

// Bots (especially the Facebook link crawler, which hits ad URLs constantly)
// would drown the stats in fake views.
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua === '' || preg_match('/bot|crawl|spider|preview|facebookexternalhit|headless|lighthouse|pingdom/i', $ua)) { exit('{}'); }

require_once __DIR__ . '/config/env_loader.php';
try {
    $pdo = new PDO(
        "mysql:host=" . env('DB_HOST', 'localhost') . ";dbname=" . env('DB_NAME', '') . ";charset=utf8mb4",
        env('DB_USER', ''), env('DB_PASS', '')
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) { exit('{}'); }

// One-time schema, gated by a tmp flag so this costs nothing per request.
$flag = sys_get_temp_dir() . '/getleadsnow_lp_schema_v1.ok';
if (!file_exists($flag)) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lp_views (
            id INT AUTO_INCREMENT PRIMARY KEY,
            page VARCHAR(20) NOT NULL,
            vid VARCHAR(40) NOT NULL,
            seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            scroll_pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_page_created (page, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @touch($flag);
    } catch (Throwable $e) {}
}

$a = $_POST['a'] ?? '';
try {
    if ($a === 'view') {
        $page = strtolower((string)($_POST['p'] ?? ''));
        if (!in_array($page, ['start', 'leads', '1cent', '100leads', 'startnow', '100free', 'get1centleads'], true)) exit('{}');
        $vid = substr(preg_replace('/[^a-z0-9]/i', '', (string)($_POST['v'] ?? '')), 0, 40);
        if ($vid === '') exit('{}');
        $st = $pdo->prepare("INSERT INTO lp_views (page, vid) VALUES (?, ?)");
        $st->execute([$page, $vid]);
        echo json_encode(['id' => (int)$pdo->lastInsertId()]);
        exit;
    }
    if ($a === 'fin') {
        // Repeated beacons per view are expected (tab hidden, tab back, page
        // close) — GREATEST keeps the longest dwell / deepest scroll seen.
        $id = (int)($_POST['id'] ?? 0);
        $s  = max(0, min(3600, (int)($_POST['s'] ?? 0)));
        $sc = max(0, min(100, (int)($_POST['sc'] ?? 0)));
        if ($id > 0) {
            $pdo->prepare("UPDATE lp_views SET seconds = GREATEST(seconds, ?), scroll_pct = GREATEST(scroll_pct, ?) WHERE id = ?")
                ->execute([$s, $sc, $id]);
        }
    }
} catch (Throwable $e) {}
echo '{}';
