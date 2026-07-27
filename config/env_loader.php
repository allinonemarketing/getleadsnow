<?php
$envFile = __DIR__ . '/../.env';

if (!isset($GLOBALS['_ENV_LOADED']) && file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (preg_match('/^([\'"])(.*)\1$/', $value, $matches)) {
            $value = $matches[2];
        }

        $_ENV[$key] = $value;
        if (function_exists('putenv')) { @putenv("$key=$value"); }
    }
    $GLOBALS['_ENV_LOADED'] = true;
}

function env($key, $default = '') {
    return $_ENV[$key] ?? (getenv($key) ?: $default);
}
