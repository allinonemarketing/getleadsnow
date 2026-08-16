<?php
require_once __DIR__ . '/env_loader.php';

// Live Stripe price IDs for the current plans ($95 / $295 / $495 per month),
// created via create_stripe_products.php. Set STRIPE_PRICE_* in .env to override.
// The stale *_PRICE_ID lines in the live .env are intentionally no longer used.
define('STRIPE_PRICE_STARTER',    env('STRIPE_PRICE_STARTER')    ?: 'price_1U55g4KOBtDhxvblkIJjjRV4');
define('STRIPE_PRICE_GROWTH',     env('STRIPE_PRICE_GROWTH')     ?: 'price_1U55g5KOBtDhxvblJKym4ILT');
define('STRIPE_PRICE_ENTERPRISE', env('STRIPE_PRICE_ENTERPRISE') ?: 'price_1U55g5KOBtDhxvbl8vtX6Bnj');

define('PLAN_STARTER_CREDITS', (int)env('PLAN_STARTER_CREDITS', 1000));
define('PLAN_GROWTH_CREDITS', (int)env('PLAN_GROWTH_CREDITS', 6000));
define('PLAN_ENTERPRISE_CREDITS', (int)env('PLAN_ENTERPRISE_CREDITS', 17000));

define('PLAN_STARTER_PRICE', (int)env('PLAN_STARTER_PRICE', 95));
define('PLAN_GROWTH_PRICE', (int)env('PLAN_GROWTH_PRICE', 295));
define('PLAN_ENTERPRISE_PRICE', (int)env('PLAN_ENTERPRISE_PRICE', 495));

// One-time free credits granted at signup (free tier).
define('FREE_TIER_CREDITS', (int)env('FREE_TIER_CREDITS', 100));

$PLAN_CREDITS = [];
if (STRIPE_PRICE_STARTER) $PLAN_CREDITS[STRIPE_PRICE_STARTER] = PLAN_STARTER_CREDITS;
if (STRIPE_PRICE_GROWTH) $PLAN_CREDITS[STRIPE_PRICE_GROWTH] = PLAN_GROWTH_CREDITS;
if (STRIPE_PRICE_ENTERPRISE) $PLAN_CREDITS[STRIPE_PRICE_ENTERPRISE] = PLAN_ENTERPRISE_CREDITS;
