<?php
require_once 'config/stripe_config.php';
require_once 'config/database.php';
require_once 'config/subscription_config.php';
require_once 'includes/email_service.php';

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$endpoint_secret = STRIPE_WEBHOOK_SECRET;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpoint_secret
    );
} catch(\UnexpectedValueException $e) {
    error_log("Invalid payload: " . $e->getMessage());
    http_response_code(400);
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    error_log("Invalid signature: " . $e->getMessage());
    http_response_code(400);
    exit();
}

if ($event->type == 'checkout.session.completed') {
    $session = $event->data->object;
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$session->metadata->user_id]);
        $userEmail = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
        $stmt->execute([
            $session->metadata->credits,
            $session->metadata->user_id
        ]);
        
        $stmt = $pdo->prepare("
            INSERT INTO credit_transactions 
            (user_id, credits, amount, transaction_id, notes) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $session->metadata->user_id,
            $session->metadata->credits,
            $session->amount_total / 100,
            $session->payment_intent,
            'Subscription purchase: ' . ($session->metadata->plan_name ?? 'Unknown Plan')
        ]);
        
        $stmt = $pdo->prepare("
            INSERT INTO subscription_log 
            (user_id, plan_name, amount, credits, stripe_session_id) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $session->metadata->user_id,
            $session->metadata->plan_name ?? 'Unknown Plan',
            $session->amount_total / 100,
            $session->metadata->credits,
            $session->id
        ]);
        
        $pdo->commit();
        
        if ($userEmail) {
            sendSubscriptionEmail(
                $userEmail,
                $session->metadata->plan_name ?? 'Unknown Plan',
                $session->metadata->credits,
                $session->amount_total / 100
            );
        }
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Database error in subscription handling: " . $e->getMessage());
    }
}

if ($event->type == 'customer.subscription.created' || 
    $event->type == 'customer.subscription.updated') {
    
    $subscription = $event->data->object;
    $userId = $subscription->metadata->user_id;
    
    $monthlyCredits = 0;
    $planName = 'none';
    switch ($subscription->items->data[0]->price->id) {
        case STRIPE_PRICE_STARTER:
            $monthlyCredits = PLAN_STARTER_CREDITS;
            $planName = 'business';
            break;
        case STRIPE_PRICE_GROWTH:
            $monthlyCredits = PLAN_GROWTH_CREDITS;
            $planName = 'agency';
            break;
        case STRIPE_PRICE_ENTERPRISE:
            $monthlyCredits = PLAN_ENTERPRISE_CREDITS;
            $planName = 'enterprise';
            break;
    }

    // Only record the plan (which grants app access) while the subscription is
    // actually active — an incomplete/past_due sub must not unlock the app.
    $planToStore = ($subscription->status === 'active') ? $planName : 'none';

    try {
        $stmt = $pdo->prepare("
            UPDATE users
            SET subscription_id = ?,
                subscription_status = ?,
                subscription_plan = ?,
                monthly_credits = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $subscription->id,
            $subscription->status,
            $planToStore,
            $monthlyCredits,
            $userId
        ]);
        
        if ($subscription->status === 'active') {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET credits = credits + ? 
                WHERE id = ?
            ");
            $stmt->execute([$monthlyCredits, $userId]);
            
            $stmt = $pdo->prepare("
                INSERT INTO credit_transactions 
                (user_id, credits, amount, transaction_id, notes) 
                VALUES (?, ?, 0, ?, 'Monthly subscription credits')
            ");
            $stmt->execute([
                $userId,
                $monthlyCredits,
                'SUB_' . $subscription->id
            ]);
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
    }
}

if ($event->type == 'customer.subscription.deleted') {
    $subscription = $event->data->object;
    $userId = $subscription->metadata->user_id;
    
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
        error_log("Database error: " . $e->getMessage());
    }
}

http_response_code(200);