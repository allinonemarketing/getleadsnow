<?php
/**
 * Background search worker — processes queued Google-Maps searches.
 *
 * Run several copies under Supervisord (Cloudways → Application Settings →
 * Supervisord Jobs). Each worker claims one pending `search_jobs` row at a time,
 * calls RapidAPI, ingests the leads (shared billing logic in includes/search_lib.php),
 * and marks the job done. This is what lets the web request return instantly
 * instead of holding a PHP-FPM worker for the RapidAPI round-trip.
 *
 *   Command:  php /path/to/app/search_worker.php
 *
 * Concurrency = number of Supervisord processes. That number also throttles your
 * request rate against RapidAPI (e.g. 5 workers ≈ at most 5 in-flight calls).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

date_default_timezone_set('UTC');
require_once __DIR__ . '/config/env_loader.php';
require_once __DIR__ . '/config/rapidapi.php';
require_once __DIR__ . '/includes/search_lib.php';

const MAX_ATTEMPTS   = 5;
const IDLE_SLEEP_US  = 1000000;   // 1s when there's nothing to do
const ERR_SLEEP_US   = 2000000;   // 2s after a hard loop error
const RATELIMIT_SLEEP_US = 1500000; // cooldown after an upstream error/429
const STALE_EVERY    = 30;        // reclaim stuck jobs every N idle ticks

function db_connect(): PDO {
    $pdo = new PDO(
        "mysql:host=" . env('DB_HOST', 'localhost') . ";dbname=" . env('DB_NAME', '') . ";charset=utf8mb4",
        env('DB_USER', ''), env('DB_PASS', '')
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function claim_job(PDO $pdo, string $workerId) {
    // Unique token per claim; the UPDATE atomically row-locks one pending job.
    $token = $workerId . '-' . bin2hex(random_bytes(6));
    $upd = $pdo->prepare("UPDATE search_jobs SET status='processing', locked_by=?, started_at=NOW(), attempts=attempts+1 WHERE status='pending' ORDER BY id ASC LIMIT 1");
    $upd->execute([$token]);
    if ($upd->rowCount() < 1) return null;
    $sel = $pdo->prepare("SELECT * FROM search_jobs WHERE locked_by=? AND status='processing' ORDER BY started_at DESC LIMIT 1");
    $sel->execute([$token]);
    return $sel->fetch(PDO::FETCH_ASSOC) ?: null;
}

function process_job(PDO $pdo, array $job) {
    $id = (int)$job['id'];
    $query = $job['query'] . ' in ' . $job['city'] . ', ' . $job['state_name'];
    $r = rapidMapsSearch($query, (int)$job['per_city']);

    if (!$r['ok']) {
        // Rate-limited (429) or upstream/transport error. Requeue with backoff up
        // to MAX_ATTEMPTS, then give up. attempts was already incremented at claim.
        if ((int)$job['attempts'] >= MAX_ATTEMPTS) {
            $pdo->prepare("UPDATE search_jobs SET status='failed', finished_at=NOW(), error=? WHERE id=?")
                ->execute(['upstream http=' . $r['http'], $id]);
        } else {
            $pdo->prepare("UPDATE search_jobs SET status='pending', locked_by=NULL WHERE id=?")->execute([$id]);
            usleep(RATELIMIT_SLEEP_US);   // brief cooldown so we don't hammer a limited API
        }
        return;
    }

    $results = $r['data'];
    $leads = [];
    foreach ($results as $x) {
        if (!is_array($x)) continue;
        $x['city'] = $job['city'];
        $x['state'] = $job['state_name'];
        $leads[] = $x;
    }

    $counts = ['inserted' => 0, 'skipped' => 0, 'skipped_no_credit' => 0];
    if (!empty($leads)) {
        $counts = ingestLeads($pdo, (int)$job['user_id'], (int)$job['list_id'], $leads);
    }

    $pdo->prepare("UPDATE search_jobs SET status='done', finished_at=NOW(), results_found=?, inserted=?, skipped=?, skipped_no_credit=? WHERE id=?")
        ->execute([count($results), $counts['inserted'], $counts['skipped'], $counts['skipped_no_credit'], $id]);
    $pdo->prepare("UPDATE lead_lists SET updated_at = NOW() WHERE id = ? AND user_id = ?")->execute([(int)$job['list_id'], (int)$job['user_id']]);
}

// Return jobs left 'processing' by a crashed worker to the queue.
function reclaim_stale(PDO $pdo) {
    try {
        $pdo->exec("UPDATE search_jobs SET status='pending', locked_by=NULL
                    WHERE status='processing' AND started_at < NOW() - INTERVAL 5 MINUTE AND attempts < " . MAX_ATTEMPTS);
    } catch (Throwable $e) {}
}

$workerId = substr(gethostname() ?: 'w', 0, 40) . '-' . getmypid();
fwrite(STDERR, "[search_worker] $workerId started\n");
$pdo = db_connect();
$idleTicks = 0;

while (true) {
    try {
        try { $pdo->query('SELECT 1'); } catch (Throwable $e) { $pdo = db_connect(); }

        $job = claim_job($pdo, $workerId);
        if (!$job) {
            if ((++$idleTicks % STALE_EVERY) === 0) { reclaim_stale($pdo); }
            usleep(IDLE_SLEEP_US);
            continue;
        }
        $idleTicks = 0;
        process_job($pdo, $job);
    } catch (Throwable $e) {
        fwrite(STDERR, "[search_worker] loop error: " . $e->getMessage() . "\n");
        usleep(ERR_SLEEP_US);
        try { $pdo = db_connect(); } catch (Throwable $e2) {}
    }
}
