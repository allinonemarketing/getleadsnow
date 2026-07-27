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

/**
 * Hard gate for the app: only users with an active paid subscription may enter.
 * Admins and admin-assigned plans (subscription_plan set) are also allowed, since
 * the Stripe webhook maintains subscription_status but admins can comp a plan
 * manually. Anyone else is sent to the pricing page to choose a plan.
 *
 * Fails OPEN on any DB/schema error so a transient problem or a missing column
 * can never lock every user (including admins and paying customers) out of the app.
 */
function requireActiveSubscription($redirect = 'pricing.php') {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
    // An admin impersonating another user ("view as user") keeps full access.
    if (!empty($_SESSION['admin_original_id'])) {
        return;
    }
    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT is_admin, subscription_status, subscription_plan FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            header('Location: login.php');
            exit();
        }

        $isAdmin  = !empty($u['is_admin']);
        $activeSub = ($u['subscription_status'] ?? '') === 'active';
        $plan     = $u['subscription_plan'] ?? 'none';
        $hasPlan  = $plan !== '' && $plan !== 'none';

        if ($isAdmin || $activeSub || $hasPlan) {
            return; // access granted
        }

        header('Location: ' . $redirect);
        exit();
    } catch (PDOException $e) {
        error_log("requireActiveSubscription check failed (allowing access): " . $e->getMessage());
        return; // fail open
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
