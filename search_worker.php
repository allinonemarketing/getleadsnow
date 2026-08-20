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
require_once __DIR__ . '/includes/enrich_lib.php';

const MAX_ATTEMPTS   = 5;
const IDLE_SLEEP_US  = 1000000;   // 1s when there's nothing to do
const ERR_SLEEP_US   = 2000000;   // 2s after a hard loop error
const RATELIMIT_SLEEP_US = 1500000; // cooldown after an upstream error/429
const STALE_EVERY    = 30;        // reclaim stuck jobs every N idle ticks
// Self-recycle so a long-running CLI process can't leak memory and so redeploys
// are picked up. The cron watchdog (worker_launch.sh) restarts it within a minute.
const MAX_RUNTIME_S  = 1800;      // exit after 30 min ...
const MAX_JOBS       = 300;       // ... or after this many jobs, whichever first

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
    // FAIR claim: pick the oldest pending job of the user with the fewest jobs
    // currently in flight, so one giant search can't monopolize all workers and
    // bury everyone else (happened: a 36k-city search blocked the queue for hours).
    // Optimistic two-step: select a candidate, then atomically claim it; retry on race.
    $token = $workerId . '-' . bin2hex(random_bytes(6));
    for ($try = 0; $try < 3; $try++) {
        // Pick the next USER fairly (fewest in-flight jobs, then oldest work),
        // then claim that user's oldest pending job. Grouping by user keeps this
        // fast at any backlog size — the correlated count runs once per distinct
        // user, not once per pending row (22k pending rows froze the old version).
        $sel = $pdo->query("SELECT s.user_id, MIN(s.id) AS oldest FROM search_jobs s WHERE s.status='pending'
            GROUP BY s.user_id
            ORDER BY (SELECT COUNT(*) FROM search_jobs p WHERE p.status='processing' AND p.user_id = s.user_id) ASC, oldest ASC
            LIMIT 1");
        $row = $sel ? $sel->fetch(PDO::FETCH_ASSOC) : null;
        $id = $row ? $row['oldest'] : null;
        if (!$id) return null;
        $upd = $pdo->prepare("UPDATE search_jobs SET status='processing', locked_by=?, started_at=NOW(), attempts=attempts+1 WHERE id=? AND status='pending'");
        $upd->execute([$token, $id]);
        if ($upd->rowCount() > 0) {
            $s2 = $pdo->prepare("SELECT * FROM search_jobs WHERE id=?");
            $s2->execute([$id]);
            return $s2->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        // Another worker won the race — pick a new candidate.
    }
    return null;
}

function process_job(PDO $pdo, array $job) {
    $id = (int)$job['id'];

    // Out of credits => leads couldn't be saved anyway. Fail the job WITHOUT
    // calling RapidAPI, so a broke account can't burn API quota (13k requests
    // were once spent on a user who could afford about 100 leads).
    $cs = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
    $cs->execute([(int)$job['user_id']]);
    if ((int)$cs->fetchColumn() < 1) {
        $pdo->prepare("UPDATE search_jobs SET status='failed', finished_at=NOW(), error='out_of_credits' WHERE id=?")->execute([$id]);
        return;
    }

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

// Poll Replicate for enrichment results (replaces inbound webhooks — outbound
// GETs are never WAF-inspected). Claims up to 5 leads atomically via a token,
// with a 30s per-lead cooldown so many workers never poll the same lead at once
// and we stay far under Replicate's API rate limits.
function sweep_enrichment(PDO $pdo, string $workerId): int {
    $token = $workerId . '-' . bin2hex(random_bytes(4));
    try {
        $upd = $pdo->prepare("UPDATE lead_list_items SET enrich_poll_token = ?, enrich_poll_at = NOW()
            WHERE enrichment_status = 'processing' AND replicate_id IS NOT NULL
              AND (enrich_poll_at IS NULL OR enrich_poll_at < NOW() - INTERVAL 30 SECOND)
            LIMIT 5");
        $upd->execute([$token]);
        if ($upd->rowCount() < 1) return 0;
    } catch (Throwable $e) {
        return 0;   // poll columns not migrated yet (load the dashboard once)
    }
    $sel = $pdo->prepare("SELECT id, list_id, replicate_id FROM lead_list_items WHERE enrich_poll_token = ? AND enrichment_status = 'processing'");
    $sel->execute([$token]);
    $done = 0;
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pred = replicateFetchPrediction((string)$row['replicate_id']);
        if (!$pred) continue;   // transient error / rate limit — cooldown retries in 30s
        $res = processEnrichmentResult($pdo, (int)$row['id'], (int)$row['list_id'], $pred);
        if ($res !== 'pending') $done++;
    }
    return $done;
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
$startedAt = time();
$jobsDone = 0;
$scriptMtime = @filemtime(__FILE__);

while (true) {
    if ((time() - $startedAt) >= MAX_RUNTIME_S || $jobsDone >= MAX_JOBS) {
        fwrite(STDERR, "[search_worker] $workerId recycling after $jobsDone job(s)\n");
        exit(0);
    }
    // Hot-swap on deploy: if this script file changed on disk, exit — the cron
    // watchdog respawns us on the new code within a minute. (SSH users can't
    // pkill the cron-owned workers, so deploys must recycle them automatically.)
    clearstatcache(true, __FILE__);
    if ($scriptMtime && @filemtime(__FILE__) !== $scriptMtime) {
        fwrite(STDERR, "[search_worker] $workerId exiting — new code deployed\n");
        exit(0);
    }
    try {
        try { $pdo->query('SELECT 1'); } catch (Throwable $e) { $pdo = db_connect(); }

        $job = claim_job($pdo, $workerId);
        if (!$job) {
            // Idle: use the time to poll enrichment results instead of sleeping.
            $swept = sweep_enrichment($pdo, $workerId);
            if ((++$idleTicks % STALE_EVERY) === 0) { reclaim_stale($pdo); }
            if ($swept === 0) usleep(IDLE_SLEEP_US);
            continue;
        }
        $idleTicks = 0;
        process_job($pdo, $job);
        $jobsDone++;
    } catch (Throwable $e) {
        fwrite(STDERR, "[search_worker] loop error: " . $e->getMessage() . "\n");
        usleep(ERR_SLEEP_US);
        try { $pdo = db_connect(); } catch (Throwable $e2) {}
    }
}
