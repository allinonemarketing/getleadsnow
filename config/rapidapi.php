<?php
require_once __DIR__ . '/env_loader.php';

define('RAPIDAPI_KEY', env('RAPIDAPI_KEY'));
define('RAPIDAPI_HOST', env('RAPIDAPI_HOST', 'maps-data.p.rapidapi.com'));
define('REPLICATE_API_KEY', env('REPLICATE_API_KEY'));
