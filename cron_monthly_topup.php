<?php
/**
 * Monthly credit top-up for externally-provisioned (GoHighLevel) users.
 *
 * These accounts have no Stripe subscription — they're created by
 * ghl_webhook.php and pay outside this app — so the Stripe invoice renewal
 * never fires for them. This job grants each of them their monthly credits on
 * their OWN anniversary day: someone who signed up on the 5th is renewed on the
 * 5th of each month; someone on the 22nd, on the 22nd, and so on. (If their
 * signup day doesn't exist in a given month — e.g. the 31st in February — it
 * falls back to that month's last day.)
 *
 * Targets: active accounts with NO Stripe subscription id (Stripe subscribers
 * always have one; their renewals come from invoice.payment_succeeded instead).
 *
 * Idempotent per CYCLE: each user is topped up at most once per monthly cycle,
 * keyed on credit_transactions.transaction_id = 'GHLCYCLE_<userId>_<cycleStart>'
 * where cycleStart is the most recent anniversary date on/before today. That
 * makes it safe to run as often as you like (daily is recommended) — it only
 * grants once per user per cycle. Credits ROLL OVER (added, not reset), matching
 * the paid plans. The signup cycle is pre-tagged by ghl_webhook.php so a new
 * user isn't granted twice in their first cycle.
 *
 * Run it from Cloudways cron (recommended, DAILY):
 *     php /path/to/application/public_html/cron_monthly_topup.php
 * Or via an authenticated URL (web cron):
 *     https://<host>/cron_monthly_topup.php?key=<GHL_WEBHOOK_SECRET>
 */

require_once __DIR__ . '/config/database.php'; // $pdo + env()

/**
 * The most recent monthly anniversary of $anchorDay on/before $today.
 * Clamps to the month's last day when $anchorDay overflows (e.g. 31 in Feb).
 */
function cycleStartFor(DateTimeImmutable $today, int $anchorDay): DateTimeImmutable {
    $y = (int) $today->format('Y');
    $m = (int) $today->format('n');
    $thisDim = (int) $today->format('t');
    $thisMonth = $today->setDate($y, $m, min($anchorDay, $thisDim));
    if ($thisMonth <= $today) {
        return $thisMonth;
    }
    $prev = $today->modify('first day of previous month');
    $prevDim = (int) $prev->format('t');
    return $prev->setDate((int) $prev->format('Y'), (int) $prev->format('n'), min($anchorDay, $prevDim));
}

$isCli = (php_sapi_name() === 'cli');

// Web invocation must present the shared secret; CLI (cron) is trusted.
if (!$isCli) {
    header('Content-Type: application/json');
    $secret = (string) env('GHL_WEBHOOK_SECRET');
    $provided = $_GET['key'] ?? ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');
    if ($secret === '' || !hash_equals($secret, (string) $provided)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

$defaultCredits = (int) env('GHL_PLAN_CREDITS', 1000);
$today = new DateTimeImmutable('today');

$granted = 0; $skipped = 0; $errors = 0;

try {
    $users = $pdo->query("
        SELECT id, monthly_credits, created_at
        FROM users
        WHERE subscription_status = 'active'
          AND (subscription_id IS NULL OR subscription_id = '')
          AND subscription_plan IS NOT NULL
          AND subscription_plan <> 'none'
    ")->fetchAll(PDO::FETCH_ASSOC);

    $chk = $pdo->prepare("SELECT 1 FROM credit_transactions WHERE transaction_id = ? LIMIT 1");
    $add = $pdo->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
    $log = $pdo->prepare("
        INSERT INTO credit_transactions (user_id, credits, amount, transaction_id, notes)
        VALUES (?, ?, 0, ?, 'GHL monthly auto top-up')
    ");

    foreach ($users as $u) {
        $userId = (int) $u['id'];
        $credits = ((int) $u['monthly_credits']) > 0 ? (int) $u['monthly_credits'] : $defaultCredits;

        // Anchor the renewal cycle to the user's signup day.
        try {
            $signup = !empty($u['created_at']) ? new DateTimeImmutable($u['created_at']) : $today;
        } catch (Exception $e) {
            $signup = $today;
        }
        $anchorDay = (int) $signup->format('j');
        $cycleStart = cycleStartFor($today, $anchorDay);
        $txnId = 'GHLCYCLE_' . $userId . '_' . $cycleStart->format('Y-m-d');

        try {
            $chk->execute([$txnId]);
            if ($chk->fetch()) { $skipped++; continue; }   // already topped up this cycle

            $pdo->beginTransaction();
            $add->execute([$credits, $userId]);
            $log->execute([$userId, $credits, $txnId]);
            $pdo->commit();
            $granted++;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors++;
            error_log("GHL cron top-up failed for user $userId: " . $e->getMessage());
        }
    }

    $summary = "GHL anniversary top-up [" . $today->format('Y-m-d') . "] granted=$granted skipped=$skipped errors=$errors";
    error_log($summary);
    if ($isCli) {
        echo $summary . "\n";
    } else {
        echo json_encode(['success' => true, 'date' => $today->format('Y-m-d'), 'granted' => $granted, 'skipped' => $skipped, 'errors' => $errors]);
    }
} catch (PDOException $e) {
    error_log("GHL cron fatal: " . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, "fatal: " . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
