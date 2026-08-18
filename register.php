<?php
require_once 'config/database.php';
require_once 'config/subscription_config.php';
require_once 'includes/email_service.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$wantsOwnership = (($_POST['wants_ownership'] ?? '') === 'yes') ? 'yes' : 'no';

if (empty($name) || empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Name, email, and password are required.']);
    exit;
}

try {
    global $pdo;

    // Make sure the ownership-interest column exists (asked at signup).
    try { $pdo->query("SELECT wants_ownership FROM users LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE users ADD COLUMN wants_ownership VARCHAR(3) DEFAULT NULL"); }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    // Free tier: everyone starts with a one-time batch of free credits.
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, credits, wants_ownership) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $hashedPassword, FREE_TIER_CREDITS, $wantsOwnership]);
    $userId = $pdo->lastInsertId();

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;

    sendAdminNotification(['name' => $name, 'email' => $email, 'wants_ownership' => $wantsOwnership]);
    sendWelcomeEmail(['name' => $name, 'email' => $email]);

    echo json_encode(['success' => true, 'message' => 'Registration successful! Welcome aboard.']);
} catch (PDOException $e) {
    error_log("Registration error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred during registration. Please try again.']);
}
