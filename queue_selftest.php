<?php
/**
 * One-off CLI smoke test for the search queue. Run it (with the worker running,
 * either by hand or under Supervisord):
 *
 *   php queue_selftest.php
 *
 * It picks a user that has credits, finds/creates a list, enqueues a single
 * "coffee shops in Austin, Texas" job, and waits for a worker to process it —
 * printing the status so you can confirm the whole pipeline end to end.
 * Safe to delete afterwards.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/config/env_loader.php';
require_once __DIR__ . '/config/database.php';   // provides $pdo

$u = $pdo->query("SELECT id, credits FROM users WHERE credits >= 5 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$u) { exit("No user with >=5 credits found. Add credits to a test account first, then re-run.\n"); }
$userId = (int)$u['id'];
echo "Using user_id=$userId (credits {$u['credits']})\n";

$l = $pdo->prepare("SELECT id, name FROM lead_lists WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$l->execute([$userId]);
$list = $l->fetch(PDO::FETCH_ASSOC);
if (!$list) {
    $pdo->prepare("INSERT INTO lead_lists (user_id, name, description) VALUES (?, 'Queue Self-Test', '')")->execute([$userId]);
    $listId = (int)$pdo->lastInsertId();
    echo "Created list id=$listId\n";
} else {
    $listId = (int)$list['id'];
    echo "Using list id=$listId ({$list['name']})\n";
}

$batch = bin2hex(random_bytes(12));
$pdo->prepare("INSERT INTO search_jobs (batch_id, user_id, list_id, query, city, state_name, per_city) VALUES (?,?,?,?,?,?,?)")
    ->execute([$batch, $userId, $listId, 'coffee shops', 'Austin', 'Texas', 5]);
echo "Enqueued batch=$batch — waiting for a worker to process it...\n";

for ($i = 0; $i < 30; $i++) {
    sleep(2);
    $s = $pdo->prepare("SELECT status, results_found, inserted, error FROM search_jobs WHERE batch_id = ?");
    $s->execute([$batch]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    echo "  status={$row['status']} found={$row['results_found']} inserted={$row['inserted']}" . ($row['error'] ? " error={$row['error']}" : "") . "\n";
    if ($row['status'] === 'done') { echo "\n✅ SUCCESS — the worker ran the search and saved {$row['inserted']} lead(s) to list $listId. Pipeline works.\n"; exit; }
    if ($row['status'] === 'failed') { echo "\n❌ FAILED — {$row['error']}\n"; exit; }
}
echo "\n⏳ Still pending after 60s — is a worker running? Start one with: php search_worker.php\n";
