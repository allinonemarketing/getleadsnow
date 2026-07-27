<?php
require_once 'config/stripe_config.php';
require_once 'config/database.php';
require_once 'config/subscription_config.php';
require_once 'includes/email_service.php';

// Price -> internal plan name / monthly credits / display label.
$PRICE_PLAN = [
    STRIPE_PRICE_STARTER    => ['plan' => 'business',   'credits' => PLAN_STARTER_CREDITS,    'label' => 'Starter'],
    STRIPE_PRICE_GROWTH     => ['plan' => 'agency',     'credits' => PLAN_GROWTH_CREDITS,     'label' => 'Growth'],
    STRIPE_PRICE_ENTERPRISE => ['plan' => 'enterprise', 'credits' => PLAN_ENTERPRISE_CREDITS, 'label' => 'Enterprise'],
];

/**
 * Keep a user's subscription fields in sync and grant the plan's credits exactly
 * once per subscription. Idempotent: the credit grant is keyed on
 * transaction_id = 'SUB_<subscriptionId>', so checkout.session.completed and
 * customer.subscription.created can both call this without double-crediting,
 * regardless of the order Stripe delivers them.
 *
 * Returns true if credits were granted on this call, false otherwise.
 */
function applySubscription($pdo, $userId, $subId, $priceId, $status, $amount = 0, $sessionId = null) {
    global $PRICE_PLAN;
    $info     = $PRICE_PLAN[$priceId] ?? null;
    $planName = $info ? $info['plan'] : 'none';
    $credits  = $info ? (int)$info['credits'] : 0;
    $isActive = ($status === 'active');

    // A plan only unlocks the app while the subscription is actually active.
    $planToStore = $isActive ? $planName : 'none';
    $monthly     = $isActive ? $credits : 0;

    $stmt = $pdo->prepare("
        UPDATE users
        SET subscription_id = ?, subscription_status = ?, subscription_plan = ?, monthly_credits = ?
        WHERE id = ?
    ");
    $stmt->execute([$subId, $status, $planToStore, $monthly, $userId]);

    if ($isActive && $credits > 0) {
        $txnId = 'SUB_' . $subId;
        $chk = $pdo->prepare("SELECT 1 FROM credit_transactions WHERE transaction_id = ? LIMIT 1");
        $chk->execute([$txnId]);
        if (!$chk->fetch()) {
            $pdo->prepare("UPDATE users SET credits = credits + ? WHERE id = ?")
                ->execute([$credits, $userId]);
            $pdo->prepare("
                INSERT INTO credit_transactions (user_id, credits, amount, transaction_id, notes)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$userId, $credits, $amount, $txnId, 'Subscription: ' . $planName]);
            try {
                $pdo->prepare("
                    INSERT INTO subscription_log (user_id, plan_name, amount, credits, stripe_session_id)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$userId, $planName, $amount, $credits, $sessionId ?? $txnId]);
            } catch (Exception $e) {
                error_log("subscription_log insert failed: " . $e->getMessage());
            }
            return true;
        }
    }
    return false;
}

/** Resolve the user id tied to a subscription: metadata first, then DB by subscription_id. */
function resolveUserForSubscription($pdo, $subscription) {
    $userId = $subscription->metadata->user_id ?? null;
    if ($userId) return $userId;
    $stmt = $pdo->prepare("SELECT id FROM users WHERE subscription_id = ?");
    $stmt->execute([$subscription->id]);
    return $stmt->fetchColumn() ?: null;
}

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpoint_secret = STRIPE_WEBHOOK_SECRET;

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch (\UnexpectedValueException $e) {
    error_log("Invalid payload: " . $e->getMessage());
    http_response_code(400);
    exit();
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    error_log("Invalid signature: " . $e->getMessage());
    http_response_code(400);
    exit();
}

if ($event->type == 'checkout.session.completed') {
    $session = $event->data->object;
    $md      = $session->metadata;
    $priceId = $md->price_id ?? null;
    $subId   = $session->subscription ?? null;
    $details = $session->customer_details ?? null;
    $email   = ($details && isset($details->email)) ? $details->email : ($session->customer_email ?? null);
    $custName = ($details && isset($details->name)) ? $details->name : null;
    $amount  = isset($session->amount_total) ? $session->amount_total / 100 : 0;
    $userId  = $md->user_id ?? null; // present when a logged-in user upgraded

    // Only subscription checkouts with a known plan are handled here.
    if ($priceId && $subId) {
        $isNew = false;
        $name  = null;
        try {
            $pdo->beginTransaction();

            if (!$userId && $email) {
                $s = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $s->execute([$email]);
                $existingId = $s->fetchColumn();
                if ($existingId) {
                    $userId = $existingId;
                } else {
                    // Checkout-first: create the account now that payment succeeded.
                    $name = $custName;
                    if (!$name) { $name = trim(explode('@', $email)[0]); }
                    $randomPw = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (name, email, password, credits) VALUES (?, ?, ?, 0)")
                        ->execute([$name, $email, $randomPw]);
                    $userId = $pdo->lastInsertId();
                    $isNew = true;
                }
            }

            if ($userId) {
                applySubscription($pdo, $userId, $subId, $priceId, 'active', $amount, $session->id);
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("checkout.session.completed handling failed: " . $e->getMessage());
            $userId = null; // don't proceed to emails on failure
        }

        // Attach the user id to the Stripe subscription so future lifecycle
        // events (renewals, cancellation) can resolve the account.
        if ($userId && $subId) {
            try {
                \Stripe\Subscription::update($subId, ['metadata' => ['user_id' => $userId]]);
            } catch (Exception $e) {
                error_log("Failed to set subscription metadata user_id: " . $e->getMessage());
            }
        }

        if ($userId && $email) {
            $label = $PRICE_PLAN[$priceId]['label'] ?? '';
            $credits = $PRICE_PLAN[$priceId]['credits'] ?? 0;
            if ($isNew) {
                // New account: send a set-password link (valid 24h).
                try {
                    $token = bin2hex(random_bytes(32));
                    $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))")
                        ->execute([$email, $token]);
                    sendSetPasswordEmail($email, $name ?? $email, $token, $label);
                } catch (Exception $e) {
                    error_log("Set-password email failed: " . $e->getMessage());
                }
            } else {
                // Existing account upgraded/purchased.
                try { sendSubscriptionEmail($email, $label, $credits, $amount); } catch (Exception $e) {}
            }
        }
    }
}

if ($event->type == 'customer.subscription.created' ||
    $event->type == 'customer.subscription.updated') {

    $subscription = $event->data->object;
    $priceId = $subscription->items->data[0]->price->id ?? null;
    $userId  = resolveUserForSubscription($pdo, $subscription);

    // For guest checkout this event may arrive before the account exists; in that
    // case checkout.session.completed does the initial grant and there's nothing
    // to do here yet.
    if ($userId && $priceId) {
        try {
            applySubscription($pdo, $userId, $subscription->id, $priceId, $subscription->status, 0, $subscription->id);
        } catch (Exception $e) {
            error_log("subscription.$event->type handling failed: " . $e->getMessage());
        }
    }
}

if ($event->type == 'customer.subscription.deleted') {
    $subscription = $event->data->object;
    $userId = resolveUserForSubscription($pdo, $subscription);

    if ($userId) {
        try {
            $stmt = $pdo->prepare("
                UPDATE users
                SET subscription_id = NULL,
                    subscription_status = 'canceled',
                    subscription_plan = 'none',
                    monthly_credits = 0
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            error_log("subscription.deleted handling failed: " . $e->getMessage());
        }
    }
}

http_response_code(200);
