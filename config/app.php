<?php
require_once __DIR__ . '/env_loader.php';

define('APP_NAME', env('APP_NAME', 'Lead Gen SaaS'));
define('APP_URL', rtrim(env('APP_URL', ''), '/'));
define('APP_LOGO', env('APP_LOGO', '/assets/logo.svg'));
define('ADMIN_EMAIL', env('ADMIN_EMAIL', 'admin@localhost'));
define('SUPPORT_EMAIL', env('SUPPORT_EMAIL', 'support@localhost'));
define('FREE_SIGNUP_CREDITS', (int)env('FREE_SIGNUP_CREDITS', 3));
