<?php
session_start();
require_once 'includes/auth.php';
require_once 'config/stripe_config.php';
require_once 'config/subscription_config.php';

$PLAN_CREDITS = [
    STRIPE_PRICE_STARTER => PLAN_STARTER_CREDITS,
    STRIPE_PRICE_GROWTH => PLAN_GROWTH_CREDITS,
    STRIPE_PRICE_ENTERPRISE => PLAN_ENTERPRISE_CREDITS
];

// Internal plan-name used across the app (matches subscription_plan values).
$PRICE_TO_PLAN = [
    STRIPE_PRICE_STARTER => 'business',
    STRIPE_PRICE_GROWTH => 'agency',
    STRIPE_PRICE_ENTERPRISE => 'enterprise'
];

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$priceId = $input['price_id'] ?? '';

if (!isset($PLAN_CREDITS[$priceId])) {
    echo json_encode(['error' => 'Invalid price ID']);
    exit();
}

// Checkout-first: a logged-out visitor can pay without an account. The account
// is created by the Stripe webhook (checkout.session.completed) after payment,
// and they receive a set-password email. Logged-in users upgrade in place.
$loggedIn = isLoggedIn();
$metadata = [
    'price_id'   => $priceId,
    'credits'    => $PLAN_CREDITS[$priceId],
    'plan_name'  => $PRICE_TO_PLAN[$priceId],
];
if ($loggedIn) {
    $metadata['user_id'] = $_SESSION['user_id'];
}

try {
    $params = [
        'mode' => 'subscription',
        'line_items' => [[
            'price' => $priceId,
            'quantity' => 1,
        ]],
        // Session-level metadata so checkout.session.completed can resolve the plan.
        'metadata' => $metadata,
        // Copied onto the Subscription so lifecycle events carry the same data.
        'subscription_data' => [
            'metadata' => $metadata
        ],
        'success_url' => $loggedIn
            ? APP_URL . '/dashboard?payment_success=true&credits=' . $PLAN_CREDITS[$priceId] . '&section=section5'
            : APP_URL . '/login.php?checkout=success',
        'cancel_url' => $loggedIn
            ? APP_URL . '/pricing.php'
            : APP_URL . '/#pricing',
    ];

    if ($loggedIn) {
        $params['client_reference_id'] = $_SESSION['user_id'];
        if (!empty($_SESSION['user_email'])) {
            $params['customer_email'] = $_SESSION['user_email'];
        }
    }
    // For guests we let Stripe Checkout collect the email itself.

    $session = \Stripe\Checkout\Session::create($params);

    echo json_encode(['id' => $session->id]);

} catch (Exception $e) {
    error_log("Stripe subscription error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
