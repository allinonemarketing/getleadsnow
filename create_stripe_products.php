<?php
/**
 * CLI-only one-time setup: creates the three subscription products + monthly
 * prices in Stripe and prints the price IDs to paste into .env.
 *
 *   php create_stripe_products.php
 *
 * Uses the STRIPE_SECRET_KEY already in your .env (live key = live products).
 * DELETE THIS FILE after you've copied the price IDs.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/config/stripe_config.php';

$plans = [
    ['env' => 'STARTER_PRICE_ID',    'name' => 'GetLeadsNow Starter — 1,000 leads / month',  'amount' => 9500,  'leads' => 1000],
    ['env' => 'GROWTH_PRICE_ID',     'name' => 'GetLeadsNow Growth — 6,000 leads / month',   'amount' => 29500, 'leads' => 6000],
    ['env' => 'ENTERPRISE_PRICE_ID', 'name' => 'GetLeadsNow Pro — 17,000 leads / month',     'amount' => 49500, 'leads' => 17000],
];

echo "Creating Stripe products + monthly prices...\n\n";

foreach ($plans as $p) {
    try {
        $product = \Stripe\Product::create(['name' => $p['name']]);
        $price = \Stripe\Price::create([
            'product'     => $product->id,
            'unit_amount' => $p['amount'],
            'currency'    => 'usd',
            'recurring'   => ['interval' => 'month'],
        ]);
        printf("%s=%s   # %s ($%s/mo, %s leads)\n",
            $p['env'], $price->id, $p['name'], number_format($p['amount'] / 100, 2), number_format($p['leads']));
    } catch (Exception $e) {
        echo "FAILED for {$p['name']}: " . $e->getMessage() . "\n";
    }
}

echo "\n^ Paste those three lines into your .env (replacing the old *_PRICE_ID lines), then delete this file.\n";
