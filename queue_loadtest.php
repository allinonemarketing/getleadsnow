<?php
/**
 * Load-test the search queue: enqueue N city jobs at once and time how fast the
 * workers drain them, surfacing any RapidAPI rate-limit / error. This stresses the
 * exact launch bottleneck (workers + RapidAPI concurrency) without a browser.
 *
 *   php queue_loadtest.php [jobs] [per_city]
 *   php queue_loadtest.php 50 5      # 50 cities, 5 leads each  (default)
 *
 * Cost note: the FIRST run inserts real leads = real credits (jobs x per_city max).
 * Re-runs on the same list are ~free (all dedup) but still hit RapidAPI + workers,
 * so you can hammer it repeatedly to test throughput/rate-limits cheaply.
 * Delete this file before launch.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/config/env_loader.php';
require_once __DIR__ . '/config/database.php';

$JOBS     = max(1, (int)($argv[1] ?? 50));
$PER_CITY = max(1, (int)($argv[2] ?? 5));
$QUERY    = 'coffee shops';

// A spread of real US cities so jobs hit different RapidAPI queries (not all dedup).
$CITIES = [
    ['Austin','Texas'],['Dallas','Texas'],['Houston','Texas'],['San Antonio','Texas'],
    ['Miami','Florida'],['Orlando','Florida'],['Tampa','Florida'],['Jacksonville','Florida'],
    ['Atlanta','Georgia'],['Savannah','Georgia'],['Phoenix','Arizona'],['Tucson','Arizona'],
    ['Denver','Colorado'],['Boulder','Colorado'],['Seattle','Washington'],['Spokane','Washington'],
    ['Portland','Oregon'],['Chicago','Illinois'],['Nashville','Tennessee'],['Memphis','Tennessee'],
    ['Charlotte','North Carolina'],['Raleigh','North Carolina'],['Columbus','Ohio'],['Cleveland','Ohio'],
    ['Detroit','Michigan'],['Minneapolis','Minnesota'],['Kansas City','Missouri'],['St. Louis','Missouri'],
    ['Las Vegas','Nevada'],['Salt Lake City','Utah'],['San Diego','California'],['Sacramento','California'],
    ['Fresno','California'],['Boston','Massachusetts'],['Philadelphia','Pennsylvania'],['Pittsburgh','Pennsylvania'],
    ['Indianapolis','Indiana'],['Milwaukee','Wisconsin'],['New Orleans','Louisiana'],['Oklahoma City','Oklahoma'],
];

$u = $pdo->query("SELECT id, credits FROM users WHERE credits >= 5 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$u) { exit("No user with >=5 credits found. Add credits to a test account first.\n"); }
$userId = (int)$u['id'];

$l = $pdo->prepare("SELECT id FROM lead_lists WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$l->execute([$userId]);
$listId = (int)($l->fetchColumn() ?: 0);
if (!$listId) {
    $pdo->prepare("INSERT INTO lead_lists (user_id, name) VALUES (?, 'Load Test')")->execute([$userId]);
    $listId = (int)$pdo->lastInsertId();
}

echo "Load test: enqueuing $JOBS jobs (per_city=$PER_CITY) for user $userId, list $listId (credits {$u['credits']})\n";

$batch = bin2hex(random_bytes(12));
$rows = []; $vals = [];
for ($i = 0; $i < $JOBS; $i++) {
    [$city, $state] = $CITIES[$i % count($CITIES)];
    $rows[] = '(?,?,?,?,?,?,?)';
    array_push($vals, $batch, $userId, $listId, $QUERY, $city, $state, $PER_CITY);
}
$pdo->prepare("INSERT INTO search_jobs (batch_id, user_id, list_id, query, city, state_name, per_city) VALUES " . implode(',', $rows))->execute($vals);

$start = microtime(true);
echo "Enqueued at " . date('H:i:s') . ". Draining...\n";

$prev = -1;
while (true) {
    usleep(1000000);
    $s = $pdo->prepare("SELECT
        SUM(status='pending') p, SUM(status='processing') pr,
        SUM(status='done') d, SUM(status='failed') f,
        COALESCE(SUM(results_found),0) found, COALESCE(SUM(inserted),0) ins
        FROM search_jobs WHERE batch_id = ?");
    $s->execute([$batch]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    $done = (int)$r['d'] + (int)$r['f'];
    $elapsed = round(microtime(true) - $start, 1);
    if ($done !== $prev) {
        printf("  t=%5.1fs  done=%d/%d  (processing=%d pending=%d failed=%d)  found=%d inserted=%d\n",
            $elapsed, $done, $JOBS, (int)$r['pr'], (int)$r['p'], (int)$r['f'], (int)$r['found'], (int)$r['ins']);
        $prev = $done;
    }
    if ($done >= $JOBS) {
        $rate = $elapsed > 0 ? round($JOBS / $elapsed, 2) : 0;
        echo "\n==== RESULT ====\n";
        echo "Drained $JOBS jobs in {$elapsed}s  =>  {$rate} cities/sec\n";
        echo "Leads found={$r['found']}  inserted={$r['ins']}  failed jobs={$r['f']}\n";
        if ((int)$r['f'] > 0) {
            echo "\nFailed job errors (look for http=429 = RapidAPI RATE LIMIT):\n";
            $e = $pdo->prepare("SELECT error, COUNT(*) c FROM search_jobs WHERE batch_id=? AND status='failed' GROUP BY error");
            $e->execute([$batch]);
            foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $row) { echo "  {$row['c']}x  {$row['error']}\n"; }
        } else {
            echo "No failures — no rate-limiting hit at this concurrency.\n";
        }
        exit;
    }
}
