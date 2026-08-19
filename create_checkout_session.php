<?php
session_start();
require_once 'includes/auth.php';
require_once 'config/stripe_config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
session_write_close();  // frees the session lock before the Stripe checkout create call

$data = json_decode(file_get_contents('php://input'), true);
$credits = $data['credits'];
$amount = $data['amount'];

try {
    $checkout_session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'unit_amount' => round($amount * 100),
                'product_data' => [
                    'name' => "$credits API Credits",
                    'description' => "Purchase of $credits API credits for Lead Generation API",
                ],
            ],
            'quantity' => 1,
        ]],
        'mode' => 'payment',
        'success_url' => APP_URL . '/pricing.php?success=true&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => APP_URL . '/pricing.php',
        'metadata' => [
            'user_id' => $_SESSION['user_id'],
            'credits' => $credits
        ]
    ]);

    echo json_encode(['id' => $checkout_session->id]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} 