<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT id, name, email, credits FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("Database error in getCurrentUser: " . $e->getMessage());
        return null;
    }
}

if (isset($_GET['check_session'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'isLoggedIn' => isLoggedIn(),
        'user' => getCurrentUser()
    ]);
    exit;
}
