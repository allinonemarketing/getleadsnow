<?php
session_start();
require_once 'includes/auth.php';
require_once 'config/stripe_config.php';
require_once 'config/subscription_config.php';

// Build the price maps only from configured (non-empty) price IDs. If a price
// constant is empty (missing from .env) it must NOT become an empty-string key,
// otherwise an empty price_id would pass validation and hit Stripe with an
// empty line_items price.
$PLAN_CREDITS = [];
$PRICE_TO_PLAN = [];
if (STRIPE_PRICE_STARTER)    { $PLAN_CREDITS[STRIPE_PRICE_STARTER] = PLAN_STARTER_CREDITS;    $PRICE_TO_PLAN[STRIPE_PRICE_STARTER] = 'business'; }
if (STRIPE_PRICE_GROWTH)     { $PLAN_CREDITS[STRIPE_PRICE_GROWTH] = PLAN_GROWTH_CREDITS;      $PRICE_TO_PLAN[STRIPE_PRICE_GROWTH] = 'agency'; }
if (STRIPE_PRICE_ENTERPRISE) { $PLAN_CREDITS[STRIPE_PRICE_ENTERPRISE] = PLAN_ENTERPRISE_CREDITS; $PRICE_TO_PLAN[STRIPE_PRICE_ENTERPRISE] = 'enterprise'; }

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$priceId = trim($input['price_id'] ?? '');

if ($priceId === '' || !isset($PLAN_CREDITS[$priceId])) {
    echo json_encode(['error' => 'This plan isn\'t available for checkout yet. Please contact support.']);
    exit();
}

// Checkout-first: a logged-out visitor can pay without an account. The account
// is created by the Stripe webhook (checkout.session.completed) after payment,
// and they receive a set-password email. Logged-in users upgrade in place.
$loggedIn = isLoggedIn();
session_write_close();  // only reads session after this; frees the lock before the Stripe API call
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
            ? APP_URL . '/dashboard.php?payment_success=true&credits=' . $PLAN_CREDITS[$priceId] . '&section=section5'
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

    echo json_encode(['id' => $session->id, 'url' => $session->url]);

} catch (Exception $e) {
    error_log("Stripe subscription error: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
