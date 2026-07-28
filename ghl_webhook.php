<?php
/**
 * GoHighLevel provisioning webhook.
 *
 * Creates (or renews) an account from a GHL webhook automation. These users pay
 * outside this app, so no Stripe subscription is involved — they're granted the
 * plan's credits directly and marked active so the app's subscription gate lets
 * them in.
 *
 * Security: requires a shared secret (GHL_WEBHOOK_SECRET in .env). Fails closed
 * if the secret isn't configured. Send the secret from GHL as the header
 * `X-Webhook-Secret`, a `secret` body field, or a `?key=` query param.
 *
 * Expected JSON (or form) body: { name, email, password, idempotency_key? }
 *   - name: full name (or first_name + last_name)
 *   - email, password: required
 *   - idempotency_key: OPTIONAL but recommended for the monthly renewal call —
 *     a value unique per billing period (e.g. "<email>-2026-08"). Prevents a
 *     retried webhook from topping up twice.
 *
 * Behaviour:
 *   - New email  -> create account (hashed password), grant plan credits, active.
 *   - Existing   -> add the plan's credits (rollover). Password is NOT changed.
 */

require_once 'config/database.php'; // provides $pdo and env()

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

// --- Parse payload (JSON preferred, form-encoded fallback) -------------------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }

// --- Authenticate via shared secret (timing-safe) ----------------------------
$expected = (string) env('GHL_WEBHOOK_SECRET');
if ($expected === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Webhook not configured']);
    exit;
}
$provided = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? ($_GET['key'] ?? ($data['secret'] ?? ''));
if (!hash_equals($expected, (string) $provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// --- Extract & validate fields ----------------------------------------------
$email = trim((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');
$name = trim((string) ($data['name'] ?? ''));
if ($name === '') {
    $name = trim(((string) ($data['first_name'] ?? '')) . ' ' . ((string) ($data['last_name'] ?? '')));
}
if ($name === '' && $email !== '') { $name = trim(explode('@', $email)[0]); }
$idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'A valid email is required']);
    exit;
}
if ($password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'password is required']);
    exit;
}

// Monthly credit allotment (1,000 by default — same as the Starter plan).
$planCredits = (int) env('GHL_PLAN_CREDITS', 1000);
$txnId = $idempotencyKey !== '' ? ('GHL_' . $idempotencyKey) : '';

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existingId = $stmt->fetchColumn();

    // Idempotency guard: if this exact key was already processed, do nothing.
    if ($txnId !== '') {
        $c = $pdo->prepare("SELECT 1 FROM credit_transactions WHERE transaction_id = ? LIMIT 1");
        $c->execute([$txnId]);
        if ($c->fetch()) {
            echo json_encode(['success' => true, 'action' => 'duplicate_ignored', 'user_id' => (int) $existingId]);
            exit;
        }
    }

    $pdo->beginTransaction();

    if ($existingId) {
        // Existing user — add this cycle's credits (rollover); leave password alone.
        $userId = (int) $existingId;
        $pdo->prepare("
            UPDATE users
            SET credits = credits + ?, subscription_plan = 'business',
                subscription_status = 'active', monthly_credits = ?
            WHERE id = ?
        ")->execute([$planCredits, $planCredits, $userId]);
        $action = 'renewed';
        $notes = 'GHL monthly top-up';
    } else {
        // New user — create the account provisioned by GHL.
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("
            INSERT INTO users (name, email, password, credits, subscription_plan, subscription_status, monthly_credits)
            VALUES (?, ?, ?, ?, 'business', 'active', ?)
        ")->execute([$name, $email, $hash, $planCredits, $planCredits]);
        $userId = (int) $pdo->lastInsertId();
        $action = 'created';
        $notes = 'GHL provisioning';
    }

    // Ledger entry (amount 0 — they pay outside this app). Use the idempotency
    // key as the transaction id when provided, else a unique per-event id.
    $ledgerId = $txnId !== '' ? $txnId : ('GHL_' . $userId . '_' . time());
    $pdo->prepare("
        INSERT INTO credit_transactions (user_id, credits, amount, transaction_id, notes)
        VALUES (?, ?, 0, ?, ?)
    ")->execute([$userId, $planCredits, $ledgerId, $notes]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'action'  => $action,
        'user_id' => $userId,
        'credits_granted' => $planCredits,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("GHL webhook error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
