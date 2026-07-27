<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/env_loader.php';

$stripeSecretKey = env('STRIPE_SECRET_KEY');
$stripePublicKey = env('STRIPE_PUBLIC_KEY');

define('STRIPE_SECRET_KEY', $stripeSecretKey);
define('STRIPE_PUBLIC_KEY', $stripePublicKey);
define('STRIPE_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET'));
define('STRIPE_BILLING_PORTAL_URL', env('STRIPE_BILLING_PORTAL_URL'));

\Stripe\Stripe::setApiKey($stripeSecretKey);
