<?php
require_once __DIR__ . '/env_loader.php';

$host = env('DB_HOST', 'localhost');
$dbname = env('DB_NAME', '');
$username = env('DB_USER', '');
$password = env('DB_PASS', '');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please check your .env file.");
}
