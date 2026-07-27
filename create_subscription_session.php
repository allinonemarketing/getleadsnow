<?php
session_start();
require_once 'includes/auth.php';
require_once 'config/stripe_config.php';
require_once 'config/subscription_config.php';

$STRIPE_PRICE_IDS = [
    'business' => STRIPE_PRICE_STARTER,
    'agency' => STRIPE_PRICE_GROWTH,
    'enterprise' => STRIPE_PRICE_ENTERPRISE
];

$PLAN_CREDITS = [
    STRIPE_PRICE_STARTER => PLAN_STARTER_CREDITS,
    STRIPE_PRICE_GROWTH => PLAN_GROWTH_CREDITS,
    STRIPE_PRICE_ENTERPRISE => PLAN_ENTERPRISE_CREDITS
];

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'User not logged in']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$priceId = $input['price_id'] ?? '';

if (!isset($PLAN_CREDITS[$priceId])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid price ID']);
    exit();
}

try {
    $session = \Stripe\Checkout\Session::create([
        'mode' => 'subscription',
        'customer_email' => $_SESSION['user_email'] ?? null,
        'client_reference_id' => $_SESSION['user_id'],
        'line_items' => [[
            'price' => $priceId,
            'quantity' => 1,
        ]],
        'subscription_data' => [
            'metadata' => [
                'user_id' => $_SESSION['user_id'],
                'credits' => $PLAN_CREDITS[$priceId]
            ]
        ],
        'success_url' => APP_URL . '/dashboard?success=true&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => APP_URL . '/pricing',
    ]);
    
    header('Content-Type: application/json');
    echo json_encode(['id' => $session->id]);
    
} catch (Exception $e) {
    error_log("Stripe subscription error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>