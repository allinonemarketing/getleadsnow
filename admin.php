<?php
session_start();
require_once 'includes/auth.php';
require_once 'includes/email_service.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
} else {
    $data = [];
}

// SECURITY: every admin action below requires an authenticated ADMIN. This guard
// must run BEFORE the action handlers — previously they executed ahead of the
// page's admin check, so any logged-in user could POST toggle_admin/add_credits/
// change_plan directly and escalate themselves.
$__isAdminReq = false;
if (isLoggedIn()) {
    try {
        $__s = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
        $__s->execute([$_SESSION['user_id']]);
        $__isAdminReq = (bool)$__s->fetchColumn();
    } catch (Exception $e) { $__isAdminReq = false; }
}
if (!$__isAdminReq) {
    // Exception: an admin currently impersonating a user (session user_id is the
    // target's) must still be able to return — admin_original_id proves the session.
    $__returning = isset($data['action']) && $data['action'] === 'return_to_admin' && isset($_SESSION['admin_original_id']);
    if (!$__returning) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['action'])) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }
        header('Location: /dashboard');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($data['action']) && $data['action'] === 'toggle_admin') {
    header('Content-Type: application/json');
    try {
        if ((int)$data['user_id'] === 1) { echo json_encode(['success' => false, 'error' => 'Cannot modify master admin']); exit; }
        $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?")->execute([$data['is_admin'], $data['user_id']]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'error' => 'Database error']); }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($data['action']) && $data['action'] === 'change_plan') {
    header('Content-Type: application/json');
    try {
        $allowedPlans = ['none', 'business', 'agency', 'enterprise'];
        if (!in_array($data['plan'] ?? '', $allowedPlans, true)) { echo json_encode(['success' => false, 'error' => 'Invalid plan']); exit; }
        $pdo->prepare("UPDATE users SET subscription_plan = ? WHERE id = ?")->execute([$data['plan'], $data['user_id']]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'error' => 'Database error']); }
    exit;
}

// Delete one user and everything they own. Returns null on success or an error
// string. Protections: master admin (#1), the acting admin, and other admins.
function adminDeleteUser(PDO $pdo, int $uid, int $actingId): ?string {
    if ($uid === 1) return 'Cannot delete the master admin';
    if ($uid === $actingId) return 'You cannot delete your own account';
    $chk = $pdo->prepare("SELECT is_admin, email FROM users WHERE id = ?");
    $chk->execute([$uid]);
    $target = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$target) return 'User not found';
    if (!empty($target['is_admin'])) return 'Remove admin rights first, then delete';
    // Some tables may not exist on older installs — ignore per-table failures,
    // always delete the user row last.
    foreach ([
        "DELETE FROM lead_list_items WHERE user_id = ?",
        "DELETE FROM lead_list_searches WHERE user_id = ?",
        "DELETE FROM lead_lists WHERE user_id = ?",
        "DELETE FROM search_jobs WHERE user_id = ?",
        "DELETE FROM ghl_import_items WHERE user_id = ?",
        "DELETE FROM ghl_import_logs WHERE user_id = ?",
        "DELETE FROM ghl_connections WHERE user_id = ?",
        "DELETE FROM api_calls WHERE user_id = ?",
        "DELETE FROM credit_transactions WHERE user_id = ?",
    ] as $sql) {
        try { $pdo->prepare($sql)->execute([$uid]); } catch (Exception $e) {}
    }
    try { $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$target['email']]); } catch (Exception $e) {}
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($data['action']) && $data['action'] === 'delete_user') {
    header('Content-Type: application/json');
    try {
        // Accepts a single user_id or a user_ids array (bulk).
        $ids = [];
        if (!empty($data['user_ids']) && is_array($data['user_ids'])) { $ids = array_map('intval', $data['user_ids']); }
        elseif (!empty($data['user_id'])) { $ids = [(int)$data['user_id']]; }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) { echo json_encode(['success' => false, 'error' => 'No users selected']); exit; }
        $deleted = 0; $errors = [];
        foreach ($ids as $uid) {
            $err = adminDeleteUser($pdo, $uid, (int)$_SESSION['user_id']);
            if ($err === null) $deleted++; else $errors[] = "#$uid: $err";
        }
        echo json_encode(['success' => $deleted > 0 || empty($errors), 'deleted' => $deleted, 'errors' => $errors, 'error' => $deleted === 0 && $errors ? implode('; ', $errors) : null]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'error' => 'Database error']); }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($data['action']) && $data['action'] === 'add_credits') {
    header('Content-Type: application/json');
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET credits = credits + ? WHERE id = ?")->execute([(int)$data['credits'], $data['user_id']]);
        $pdo->prepare("INSERT INTO credit_transactions (user_id, credits, amount, transaction_id, notes) VALUES (?, ?, 0, 'ADMIN_ADDED', ?)")->execute([$data['user_id'], (int)$data['credits'], $data['reason'] ?? '']);
        if (!empty($data['send_email'])) {
            $stmt = $pdo->prepare("SELECT name, email, credits FROM users WHERE id = ?");
            $stmt->execute([$data['user_id']]);
            $ud = $stmt->fetch(PDO::FETCH_ASSOC);
            $ud['new_balance'] = $ud['credits'];
            sendCreditAddedEmail($ud, (int)$data['credits'], $data['reason'] ?? '');
        }
        $pdo->commit();
        $cr = $pdo->prepare("SELECT credits FROM users WHERE id = ?"); $cr->execute([$data['user_id']]);
        echo json_encode(['success' => true, 'new_credits' => (int)$cr->fetchColumn()]);
    } catch (Exception $e) { $pdo->rollBack(); echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($data['action']) && $data['action'] === 'send_email') {
    header('Content-Type: application/json');
    try {
        $mail = createMailer();
        if (!$mail) throw new Exception('Mailer not configured');
        $mail->addAddress($data['to_email'], $data['to_name'] ?? '');
        $mail->Subject = $data['subject'];
        $mail->isHTML(true);
        $mail->Body = nl2br(htmlspecialchars($data['body']));
        $mail->send();
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($data['action']) && $data['action'] === 'login_as_user') {
    header('Content-Type: application/json');
    try {
        $adminUser = getCurrentUser();
        if (!$adminUser) throw new Exception('Not logged in');
        $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$adminUser['id']]);
        if (!$stmt->fetchColumn()) throw new Exception('Not admin');
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
        $stmt->execute([$data['user_id']]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) throw new Exception('User not found');
        $_SESSION['admin_original_id'] = $adminUser['id'];
        $_SESSION['admin_original_name'] = $adminUser['name'];
        $_SESSION['user_id'] = $target['id'];
        $_SESSION['user_name'] = $target['name'];
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($data['action']) && $data['action'] === 'return_to_admin') {
    header('Content-Type: application/json');
    if (isset($_SESSION['admin_original_id'])) {
        $_SESSION['user_id'] = $_SESSION['admin_original_id'];
        $_SESSION['user_name'] = $_SESSION['admin_original_name'];
        unset($_SESSION['admin_original_id'], $_SESSION['admin_original_name']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No admin session']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_user') {
    header('Content-Type: application/json');
    try {
        $uid = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT u.*, COUNT(DISTINCT ll.id) as list_count, (SELECT COUNT(*) FROM lead_list_items WHERE user_id = u.id) as lead_count, (SELECT COUNT(*) FROM api_calls WHERE user_id = u.id) as api_call_count, (SELECT MAX(created_at) FROM api_calls WHERE user_id = u.id) as last_api_call FROM users u LEFT JOIN lead_lists ll ON u.id = ll.user_id WHERE u.id = ? GROUP BY u.id");
        $stmt->execute([$uid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) { echo json_encode(['success' => false]); exit; }
        $lists = $pdo->prepare("SELECT id, name, (SELECT COUNT(*) FROM lead_list_items WHERE list_id = lead_lists.id) as leads FROM lead_lists WHERE user_id = ? ORDER BY created_at DESC");
        $lists->execute([$uid]);
        $user['lists'] = $lists->fetchAll(PDO::FETCH_ASSOC);
        $txns = $pdo->prepare("SELECT * FROM credit_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
        $txns->execute([$uid]);
        $user['recent_transactions'] = $txns->fetchAll(PDO::FETCH_ASSOC);
        $calls = $pdo->prepare("SELECT * FROM api_calls WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
        $calls->execute([$uid]);
        $user['recent_api_calls'] = $calls->fetchAll(PDO::FETCH_ASSOC);
        try {
            $ghlStmt = $pdo->prepare("SELECT l.*, ll.name as list_name FROM ghl_import_logs l LEFT JOIN lead_lists ll ON l.list_id = ll.id WHERE l.user_id = ? ORDER BY l.created_at DESC LIMIT 20");
            $ghlStmt->execute([$uid]);
            $user['ghl_imports'] = $ghlStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($user['ghl_imports'] as &$g) { $g['tags'] = json_decode($g['tags'] ?: '[]', true); }
        } catch (Exception $e) { $user['ghl_imports'] = []; }
        unset($user['password']);
        echo json_encode(['success' => true, 'user' => $user]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    exit;
}

try { $pdo->query("SHOW COLUMNS FROM credit_transactions LIKE 'notes'"); } catch (Exception $e) {}
try { $s = $pdo->query("SHOW COLUMNS FROM credit_transactions LIKE 'notes'"); if ($s->rowCount() == 0) $pdo->exec("ALTER TABLE credit_transactions ADD COLUMN notes TEXT DEFAULT NULL"); } catch (Exception $e) {}
try {
    $s = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_admin'");
    if ($s->rowCount() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_admin BOOLEAN DEFAULT FALSE");
    }
} catch (Exception $e) {}
try { $s = $pdo->query("SHOW COLUMNS FROM users LIKE 'subscription_plan'"); if ($s->rowCount() == 0) $pdo->exec("ALTER TABLE users ADD COLUMN subscription_plan VARCHAR(50) DEFAULT 'none'"); } catch (Exception $e) {}
try { $s = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_active_at'"); if ($s->rowCount() == 0) $pdo->exec("ALTER TABLE users ADD COLUMN last_active_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
try { $s = $pdo->query("SHOW TABLES LIKE 'api_calls'"); if ($s->rowCount() == 0) { $pdo->exec("CREATE TABLE api_calls (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, credits_used INT NOT NULL, scraper_model VARCHAR(50) NOT NULL, url VARCHAR(2048) NULL, search_query VARCHAR(2048) NULL, input_params JSON, status VARCHAR(50), error_message TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user_date (user_id, created_at))"); } } catch (Exception $e) {}

if (!isLoggedIn()) { header('Location: login.php'); exit(); }

$curUser = getCurrentUser();
$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->execute([$curUser['id']]);
$isAdmin = $stmt->fetchColumn();
if (!$isAdmin) { header('Location: dashboard.php'); exit(); }

$pdo->prepare("UPDATE users SET last_active_at = NOW() WHERE id = ?")->execute([$curUser['id']]);

$stats = $pdo->query("SELECT SUM(CASE WHEN created_at >= CURDATE() THEN amount ELSE 0 END) as daily_sales, SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN amount ELSE 0 END) as weekly_sales, SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as monthly_sales, SUM(amount) as all_time_sales FROM credit_transactions WHERE amount > 0")->fetch(PDO::FETCH_ASSOC);
$conversion_stats = $pdo->query("SELECT COUNT(*) as total_users, SUM(CASE WHEN EXISTS (SELECT 1 FROM credit_transactions WHERE credit_transactions.user_id = users.id AND amount > 0) THEN 1 ELSE 0 END) as paying_users FROM users")->fetch(PDO::FETCH_ASSOC);
$total_users = $conversion_stats['total_users'];
$paying_users = $conversion_stats['paying_users'];
$conversion_rate = $total_users > 0 ? round(($paying_users / $total_users) * 100, 1) : 0;

$active_today = $pdo->query("SELECT COUNT(*) FROM users WHERE last_active_at >= CURDATE()")->fetchColumn() ?: 0;
$new_today = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
// DB timestamps are UTC (server clock). Display them in Eastern time.
function estTime($ts, $fmt = 'M j, g:ia') {
    if (!$ts) return 'Never';
    try {
        $d = new DateTime($ts, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('America/New_York'));
        return $d->format($fmt);
    } catch (Exception $e) { return $ts; }
}

$scrapes_today = $pdo->query("SELECT COUNT(*) FROM api_calls WHERE created_at >= CURDATE()")->fetchColumn() ?: 0;
$leads_today   = $pdo->query("SELECT COUNT(*) FROM lead_list_items WHERE created_at >= CURDATE()")->fetchColumn() ?: 0;
$leads_total   = $pdo->query("SELECT COUNT(*) FROM lead_list_items")->fetchColumn() ?: 0;

$plan_stats = $pdo->query("SELECT subscription_plan, COUNT(*) as c FROM users GROUP BY subscription_plan")->fetchAll(PDO::FETCH_ASSOC);
$plan_counts = ['none'=>0,'business'=>0,'agency'=>0,'enterprise'=>0];
foreach ($plan_stats as $p) $plan_counts[$p['subscription_plan'] ?? 'none'] = $p['c'];

$users_json = $pdo->query("SELECT u.id, u.name, u.email, u.credits, u.is_admin, u.subscription_plan, u.created_at, u.last_active_at, COUNT(DISTINCT t.id) as total_transactions, COALESCE(SUM(t.amount), 0) as total_spent, (SELECT COUNT(*) FROM lead_lists WHERE user_id = u.id) as list_count, (SELECT COUNT(*) FROM lead_list_items WHERE user_id = u.id) as lead_count, (SELECT COUNT(*) FROM api_calls WHERE user_id = u.id) as api_calls FROM users u LEFT JOIN credit_transactions t ON u.id = t.user_id AND t.amount > 0 GROUP BY u.id ORDER BY u.last_active_at DESC, u.id DESC")->fetchAll(PDO::FETCH_ASSOC);

$recent_activity = $pdo->query("(SELECT 'scrape' as type, a.user_id, u.name as user_name, COALESCE(a.search_query, a.url) as detail, a.created_at FROM api_calls a JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 20) UNION ALL (SELECT 'payment' as type, t.user_id, u.name as user_name, CONCAT('$', t.amount, ' - ', t.credits, ' credits') as detail, t.created_at FROM credit_transactions t JOIN users u ON t.user_id = u.id WHERE t.amount > 0 ORDER BY t.created_at DESC LIMIT 10) UNION ALL (SELECT 'signup' as type, u.id as user_id, u.name as user_name, u.email as detail, u.created_at FROM users u ORDER BY u.created_at DESC LIMIT 10) ORDER BY created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

$transactions_json = $pdo->query("SELECT t.*, u.name as user_name, u.email as user_email FROM credit_transactions t JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$api_calls_json = $pdo->query("SELECT a.*, u.name as user_name, u.email as user_email FROM api_calls a JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$userName = $curUser['name'] ?? 'Admin';

$ghl_imports = [];
try {
    $ghl_imports = $pdo->query("SELECT l.*, u.name as user_name, u.email as user_email, ll.name as list_name FROM ghl_import_logs l JOIN users u ON l.user_id = u.id LEFT JOIN lead_lists ll ON l.list_id = ll.id ORDER BY l.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ghl_imports as &$gi) {
        $gi['tags'] = json_decode($gi['tags'] ?: '[]', true);
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin - <?php echo APP_NAME; ?></title>
<link rel="icon" type="image/jpeg" href="<?php echo APP_LOGO; ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root { --accent:#c85719; --green:#34C759; --red:#FF3B30; --orange:#ca942a; --purple:#337f83; --text-primary:#1d1d1f; --text-secondary:#6e6e73; --text-tertiary:#aeaeb2; --bg:#f5f5f7; --card-bg:#fff; --card-border:rgba(0,0,0,0.06); --sidebar-w:240px; }
*{margin:0;padding:0;box-sizing:border-box;-webkit-font-smoothing:antialiased;}
body{background:var(--bg);color:var(--text-primary);font-family:'Inter',-apple-system,sans-serif;display:flex;height:100vh;overflow:hidden;}

.admin-sidebar{width:var(--sidebar-w);height:100vh;background:var(--card-bg);border-right:1px solid var(--card-border);display:flex;flex-direction:column;flex-shrink:0;position:fixed;z-index:100;}
.admin-sidebar .logo{padding:24px 20px 20px;display:flex;align-items:center;gap:10px;font-weight:700;font-size:15px;border-bottom:1px solid var(--card-border);}
.admin-sidebar .logo img{width:28px;height:28px;border-radius:8px;}
.admin-sidebar .nav{flex:1;padding:12px 10px;overflow-y:auto;}
.admin-sidebar .nav-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:500;color:var(--text-secondary);transition:all 0.15s;margin-bottom:2px;}
.admin-sidebar .nav-item:hover{background:var(--bg);color:var(--text-primary);}
.admin-sidebar .nav-item.active{background:rgba(200,87,25,0.08);color:var(--accent);font-weight:600;}
.admin-sidebar .nav-item i{width:18px;text-align:center;font-size:14px;}
.admin-sidebar .nav-bottom{padding:14px 16px;border-top:1px solid var(--card-border);font-size:12px;color:var(--text-tertiary);}
.admin-sidebar .nav-bottom a{color:var(--accent);text-decoration:none;font-weight:500;}

.admin-main{margin-left:var(--sidebar-w);flex:1;height:100vh;overflow-y:auto;padding:28px 32px;}
.admin-main::-webkit-scrollbar{width:6px;} .admin-main::-webkit-scrollbar-thumb{background:rgba(0,0,0,0.1);border-radius:3px;}

.tab-panel{display:none;} .tab-panel.active{display:block;}

.card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:16px;padding:24px;margin-bottom:20px;}
.card h2{font-size:16px;font-weight:700;margin-bottom:16px;}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px;}
.stat-box{background:var(--bg);border:1px solid var(--card-border);border-radius:12px;padding:16px;text-align:center;}
.stat-box .val{font-size:26px;font-weight:800;line-height:1;}
.stat-box .lbl{font-size:11px;color:var(--text-secondary);margin-top:4px;text-transform:uppercase;letter-spacing:0.03em;}
.stat-box.accent .val{color:var(--accent);}
.stat-box.green .val{color:var(--green);}
.stat-box.purple .val{color:var(--purple);}
.stat-box.orange .val{color:var(--orange);}

.feed{max-height:420px;overflow-y:auto;}
.feed::-webkit-scrollbar{width:4px;} .feed::-webkit-scrollbar-thumb{background:rgba(0,0,0,0.08);border-radius:2px;}
.feed-item{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid var(--card-border);}
.feed-item:last-child{border-bottom:none;}
.feed-dot{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.feed-dot.scrape{background:rgba(200,87,25,0.1);color:var(--accent);}
.feed-dot.payment{background:rgba(52,199,89,0.1);color:var(--green);}
.feed-dot.signup{background:rgba(175,82,222,0.1);color:var(--purple);}
.feed-text{flex:1;min-width:0;}
.feed-text .who{font-weight:600;font-size:13px;}
.feed-text .what{font-size:12px;color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.feed-text .when{font-size:11px;color:var(--text-tertiary);margin-top:2px;}

.tbl-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--card-border);}
th{font-size:11px;font-weight:600;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:0.03em;background:var(--bg);position:sticky;top:0;z-index:1;}
tr:hover{background:rgba(200,87,25,0.02);}
tr{cursor:pointer;}

.search-bar{display:flex;gap:10px;margin-bottom:16px;}
.search-bar input{flex:1;padding:10px 14px;border:1px solid var(--card-border);border-radius:10px;font-size:13px;font-family:inherit;outline:none;background:var(--bg);}
.search-bar input:focus{border-color:var(--accent);background:#fff;}

.badge{display:inline-flex;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;}
.badge-blue{background:rgba(200,87,25,0.1);color:var(--accent);}
.badge-green{background:rgba(52,199,89,0.1);color:var(--green);}
.badge-purple{background:rgba(175,82,222,0.1);color:var(--purple);}
.badge-orange{background:rgba(255,149,0,0.1);color:var(--orange);}
.badge-red{background:rgba(255,59,48,0.1);color:var(--red);}
.badge-gray{background:var(--bg);color:var(--text-secondary);}

.online-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px;}
.online-dot.on{background:var(--green);box-shadow:0 0 6px rgba(52,199,89,0.4);}
.online-dot.off{background:var(--text-tertiary);}

.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:600;font-family:inherit;border:none;cursor:pointer;transition:all 0.15s;}
.btn-primary{background:var(--accent);color:#fff;} .btn-primary:hover{background:#a84615;}
.btn-secondary{background:var(--bg);color:var(--text-primary);border:1px solid var(--card-border);} .btn-secondary:hover{background:#eee;}
.btn-danger{background:var(--red);color:#fff;} .btn-danger:hover{background:#E0332B;}
.btn-sm{padding:5px 12px;font-size:11px;}

.slide-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.25);z-index:500;display:none;}
.slide-backdrop.open{display:block;}
.slide-panel{position:fixed;right:-520px;top:0;bottom:0;width:520px;max-width:100%;background:var(--card-bg);z-index:501;transition:right 0.3s ease;overflow-y:auto;box-shadow:-8px 0 40px rgba(0,0,0,0.08);}
.slide-panel.open{right:0;}
.slide-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px;border-bottom:1px solid var(--card-border);position:sticky;top:0;background:var(--card-bg);z-index:1;}
.slide-body{padding:24px;}
.slide-section{margin-bottom:24px;}
.slide-section h3{font-size:13px;font-weight:600;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:0.03em;margin-bottom:10px;}
.info-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--card-border);font-size:13px;}
.info-row .label{color:var(--text-secondary);}
.info-row .value{font-weight:500;}
.user-avatar{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#fff;flex-shrink:0;}

.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(6px);z-index:600;display:none;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:var(--card-bg);border-radius:16px;padding:28px;max-width:460px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.15);}
.modal-box h2{font-size:18px;margin-bottom:16px;}
.modal-box .form-group{margin-bottom:14px;}
.modal-box label{display:block;font-size:12px;font-weight:500;color:var(--text-secondary);margin-bottom:4px;}
.modal-box input,.modal-box textarea,.modal-box select{width:100%;padding:10px 12px;border:1px solid var(--card-border);border-radius:8px;font-size:13px;font-family:inherit;outline:none;background:var(--bg);}
.modal-box input:focus,.modal-box textarea:focus{border-color:var(--accent);background:#fff;}
.modal-box textarea{resize:vertical;min-height:100px;}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:18px;}
</style>
</head>
<body>

<div class="admin-sidebar">
    <div class="logo">
        <img src="<?php echo APP_LOGO; ?>" alt="Logo">
        <span>Admin Panel</span>
    </div>
    <div class="nav">
        <div class="nav-item active" onclick="switchTab('dashboard')"><i class="fas fa-chart-line"></i> Dashboard</div>
        <div class="nav-item" onclick="switchTab('users')"><i class="fas fa-users"></i> Users</div>
        <div class="nav-item" onclick="switchTab('transactions')"><i class="fas fa-receipt"></i> Transactions</div>
        <div class="nav-item" onclick="switchTab('api_calls')"><i class="fas fa-code"></i> API Calls</div>
        <div class="nav-item" onclick="switchTab('ghl_imports')"><i class="fas fa-paper-plane"></i> GHL Imports</div>
    </div>
    <div class="nav-bottom">
        Logged in as <strong><?php echo htmlspecialchars($userName); ?></strong><br>
        <a href="dashboard.php"><i class="fas fa-arrow-left"></i> Back to App</a>
    </div>
</div>

<div class="admin-main">

<div id="tab-dashboard" class="tab-panel active">
    <h1 style="font-size:24px;font-weight:800;margin-bottom:24px;">Dashboard</h1>

    <div class="stats-row">
        <div class="stat-box accent"><div class="val"><?php echo number_format($total_users); ?></div><div class="lbl">Total Users</div></div>
        <div class="stat-box green"><div class="val"><?php echo $active_today; ?></div><div class="lbl">Active Today</div></div>
        <div class="stat-box purple"><div class="val"><?php echo $new_today; ?></div><div class="lbl">New Today</div></div>
        <div class="stat-box orange"><div class="val"><?php echo number_format($scrapes_today); ?></div><div class="lbl">Searches Today</div></div>
        <div class="stat-box blue"><div class="val"><?php echo number_format($leads_today); ?></div><div class="lbl">Leads Today</div></div>
        <div class="stat-box purple"><div class="val"><?php echo number_format($leads_total); ?></div><div class="lbl">Total Leads Pulled</div></div>
        <div class="stat-box green"><div class="val"><?php echo number_format($paying_users); ?></div><div class="lbl">Paying Users</div></div>
        <div class="stat-box"><div class="val"><?php echo $conversion_rate; ?>%</div><div class="lbl">Conversion</div></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
        <div class="card">
            <h2><i class="fas fa-dollar-sign" style="color:var(--green);margin-right:6px;"></i>Revenue</h2>
            <div class="stats-row" style="grid-template-columns:1fr 1fr;">
                <div class="stat-box green"><div class="val">$<?php echo number_format($stats['daily_sales'] ?? 0, 0); ?></div><div class="lbl">Today</div></div>
                <div class="stat-box"><div class="val">$<?php echo number_format($stats['weekly_sales'] ?? 0, 0); ?></div><div class="lbl">7 Days</div></div>
                <div class="stat-box"><div class="val">$<?php echo number_format($stats['monthly_sales'] ?? 0, 0); ?></div><div class="lbl">30 Days</div></div>
                <div class="stat-box accent"><div class="val">$<?php echo number_format($stats['all_time_sales'] ?? 0, 0); ?></div><div class="lbl">All Time</div></div>
            </div>
        </div>

        <div class="card">
            <h2><i class="fas fa-tags" style="color:var(--purple);margin-right:6px;"></i>Plans</h2>
            <div class="stats-row" style="grid-template-columns:1fr 1fr;">
                <div class="stat-box"><div class="val"><?php echo $plan_counts['none']; ?></div><div class="lbl">Free</div></div>
                <div class="stat-box"><div class="val"><?php echo $plan_counts['business']; ?></div><div class="lbl">Starter</div></div>
                <div class="stat-box accent"><div class="val"><?php echo $plan_counts['agency']; ?></div><div class="lbl">Growth</div></div>
                <div class="stat-box purple"><div class="val"><?php echo $plan_counts['enterprise']; ?></div><div class="lbl">Enterprise</div></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2><i class="fas fa-bolt" style="color:var(--orange);margin-right:6px;"></i>Live Activity</h2>
        <div class="feed">
            <?php foreach ($recent_activity as $act): 
                $cls = $act['type'];
                $icon = $act['type'] === 'scrape' ? 'fa-search' : ($act['type'] === 'payment' ? 'fa-dollar-sign' : 'fa-user-plus');
                $ago = time() - strtotime($act['created_at']);
                if ($ago < 60) $agoStr = $ago.'s ago';
                elseif ($ago < 3600) $agoStr = floor($ago/60).'m ago';
                elseif ($ago < 86400) $agoStr = floor($ago/3600).'h ago';
                else $agoStr = floor($ago/86400).'d ago';
            ?>
            <div class="feed-item" onclick="openUser(<?php echo $act['user_id']; ?>)" style="cursor:pointer;">
                <div class="feed-dot <?php echo $cls; ?>"><i class="fas <?php echo $icon; ?>"></i></div>
                <div class="feed-text">
                    <div class="who"><?php echo htmlspecialchars($act['user_name']); ?></div>
                    <div class="what"><?php echo htmlspecialchars(substr($act['detail'] ?? '', 0, 80)); ?></div>
                    <div class="when"><?php echo $agoStr; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div id="tab-users" class="tab-panel">
    <h1 style="font-size:24px;font-weight:800;margin-bottom:24px;">Users <span style="font-weight:400;font-size:14px;color:var(--text-tertiary);">(<?php echo $total_users; ?>)</span></h1>
    <div id="bulkBar" style="display:none;align-items:center;gap:12px;background:#fee2e2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;margin-bottom:12px;">
        <span style="font-weight:700;font-size:13px;color:#b91c1c;"><span id="bulkCount">0</span> selected</span>
        <button class="btn btn-sm" style="background:#b91c1c;color:#fff;" onclick="bulkDeleteUsers()"><i class="fas fa-trash"></i> Delete selected</button>
        <button class="btn btn-secondary btn-sm" onclick="clearUserSelection()">Clear</button>
    </div>
    <div class="search-bar">
        <input type="text" id="userSearch" placeholder="Search users by name or email..." oninput="filterUsers()">
    </div>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="tbl-wrap" style="max-height:calc(100vh - 200px);overflow-y:auto;">
            <table>
                <thead><tr>
                    <th style="width:36px;"><input type="checkbox" id="selectAllUsers" onclick="toggleAllUsers(this)" title="Select all" style="accent-color:var(--accent);cursor:pointer;"></th><th>User</th><th>Status</th><th>Plan</th><th>Credits</th><th>Lists</th><th>Leads</th><th>Spent</th><th>Last Active</th>
                </tr></thead>
                <tbody id="usersBody">
                <?php foreach ($users_json as $u):
                    $isOnline = $u['last_active_at'] && (time() - strtotime($u['last_active_at'])) < 900;
                    $planBadge = $u['subscription_plan'] === 'none' ? 'badge-gray' : ($u['subscription_plan'] === 'enterprise' ? 'badge-purple' : ($u['subscription_plan'] === 'agency' ? 'badge-blue' : 'badge-green'));
                ?>
                <tr onclick="openUser(<?php echo $u['id']; ?>)" data-search="<?php echo strtolower(htmlspecialchars($u['name'].' '.$u['email'])); ?>">
                    <td onclick="event.stopPropagation();"><?php if (!$u['is_admin'] && (int)$u['id'] !== 1): ?><input type="checkbox" class="user-cb" value="<?php echo (int)$u['id']; ?>" onchange="updateBulkBar()" style="accent-color:var(--accent);cursor:pointer;"><?php endif; ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div class="user-avatar" style="width:32px;height:32px;border-radius:8px;font-size:13px;background:<?php echo '#'.substr(md5($u['email']),0,6); ?>;"><?php echo strtoupper(substr($u['name'],0,1)); ?></div>
                            <div>
                                <div style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars($u['name']); ?><?php if($u['is_admin']) echo ' <span class="badge badge-blue">Admin</span>'; ?></div>
                                <div style="font-size:11px;color:var(--text-tertiary);"><?php echo htmlspecialchars($u['email']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="online-dot <?php echo $isOnline ? 'on' : 'off'; ?>"></span><?php echo $isOnline ? 'Online' : 'Offline'; ?></td>
                    <td><span class="badge <?php echo $planBadge; ?>"><?php $plMap = ['none' => 'Free', 'business' => 'Starter', 'agency' => 'Growth', 'enterprise' => 'Pro']; echo $plMap[$u['subscription_plan'] ?? 'none'] ?? ucfirst($u['subscription_plan']); ?></span></td>
                    <td><?php echo number_format($u['credits']); ?></td>
                    <td><?php echo $u['list_count']; ?></td>
                    <td><?php echo number_format($u['lead_count']); ?></td>
                    <td>$<?php echo number_format($u['total_spent'], 0); ?></td>
                    <td style="font-size:12px;color:var(--text-tertiary);"><?php echo estTime($u['last_active_at']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="tab-transactions" class="tab-panel">
    <h1 style="font-size:24px;font-weight:800;margin-bottom:24px;">Transactions</h1>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="tbl-wrap" style="max-height:calc(100vh - 160px);overflow-y:auto;">
            <table>
                <thead><tr><th>Date</th><th>User</th><th>Credits</th><th>Amount</th><th>Type</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($transactions_json as $t): ?>
                <tr onclick="openUser(<?php echo $t['user_id']; ?>)">
                    <td><?php echo estTime($t['created_at']); ?></td>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($t['user_name']); ?></td>
                    <td><?php echo number_format($t['credits']); ?></td>
                    <td style="color:var(--green);font-weight:600;">$<?php echo number_format($t['amount'], 2); ?></td>
                    <td><span class="badge <?php echo $t['amount'] > 0 ? 'badge-green' : 'badge-gray'; ?>"><?php echo $t['amount'] > 0 ? 'Purchase' : 'Admin'; ?></span></td>
                    <td style="color:var(--text-tertiary);font-size:12px;"><?php echo htmlspecialchars($t['notes'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="tab-api_calls" class="tab-panel">
    <h1 style="font-size:24px;font-weight:800;margin-bottom:24px;">API Calls</h1>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="tbl-wrap" style="max-height:calc(100vh - 160px);overflow-y:auto;">
            <table>
                <thead><tr><th>Date</th><th>User</th><th>Request</th><th>Model</th><th>Credits</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($api_calls_json as $c): ?>
                <tr onclick="openUser(<?php echo $c['user_id']; ?>)">
                    <td><?php echo estTime($c['created_at']); ?></td>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($c['user_name']); ?></td>
                    <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars(!empty($c['search_query']) ? $c['search_query'] : ($c['url'] ?? '')); ?></td>
                    <td><span class="badge badge-gray"><?php echo htmlspecialchars($c['scraper_model']); ?></span></td>
                    <td><?php echo $c['credits_used']; ?></td>
                    <td><span class="badge <?php echo ($c['status'] ?? '') === 'completed' ? 'badge-green' : (($c['status'] ?? '') === 'failed' ? 'badge-red' : 'badge-orange'); ?>"><?php echo htmlspecialchars($c['status'] ?? 'pending'); ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="tab-ghl_imports" class="tab-panel">
    <h1 style="font-size:24px;font-weight:800;margin-bottom:24px;">GHL Imports <span style="font-weight:400;font-size:14px;color:var(--text-tertiary);">(<?php echo count($ghl_imports); ?>)</span></h1>
    <div class="search-bar">
        <input type="text" id="ghlImportSearch" placeholder="Search by user, list, or status..." oninput="filterGHLImports()" style="padding:10px 16px;border:1px solid var(--card-border);border-radius:10px;font-size:14px;font-family:inherit;width:300px;background:#fff;outline:none;">
        <select id="ghlImportStatusFilter" onchange="filterGHLImports()" style="padding:10px 16px;border:1px solid var(--card-border);border-radius:10px;font-size:14px;font-family:inherit;background:#fff;outline:none;">
            <option value="all">All Statuses</option>
            <option value="running">Running</option>
            <option value="pending">Pending</option>
            <option value="paused">Paused</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
            <option value="failed">Failed</option>
        </select>
    </div>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="tbl-wrap" style="max-height:calc(100vh - 200px);overflow-y:auto;">
            <table id="ghlImportsTable">
                <thead><tr>
                    <th>Date</th><th>User</th><th>List</th><th>Connection</th><th>Status</th><th>Progress</th><th>New</th><th>Updated</th><th>Failed</th><th>Tags</th><th>Workflow</th><th>Drip</th>
                </tr></thead>
                <tbody>
                <?php foreach ($ghl_imports as $gi): ?>
                <tr class="ghl-import-row" data-user="<?php echo htmlspecialchars(strtolower($gi['user_name'] . ' ' . $gi['user_email'])); ?>" data-status="<?php echo $gi['status']; ?>" data-list="<?php echo htmlspecialchars(strtolower($gi['list_name'] ?? '')); ?>" onclick="openUser(<?php echo $gi['user_id']; ?>)">
                    <td style="white-space:nowrap;"><?php echo estTime($gi['created_at']); ?></td>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($gi['user_name']); ?></td>
                    <td><?php echo htmlspecialchars($gi['list_name'] ?? 'List #' . $gi['list_id']); ?></td>
                    <td style="font-size:12px;"><?php echo htmlspecialchars($gi['connection_name'] ?? '—'); ?></td>
                    <td>
                        <span class="badge <?php
                            $sc = $gi['status'];
                            echo $sc === 'completed' ? 'badge-green' : ($sc === 'running' ? 'badge-blue' : ($sc === 'failed' ? 'badge-red' : ($sc === 'paused' ? 'badge-orange' : ($sc === 'cancelled' ? 'badge-gray' : 'badge-purple'))));
                        ?>"><?php echo $gi['status']; ?></span>
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <div style="flex:1;height:4px;background:var(--card-border);border-radius:99px;overflow:hidden;min-width:60px;">
                                <div style="height:100%;background:var(--accent);border-radius:99px;width:<?php echo $gi['total_contacts'] > 0 ? round(($gi['processed'] / $gi['total_contacts']) * 100) : 0; ?>%;"></div>
                            </div>
                            <span style="font-size:11px;color:var(--text-tertiary);white-space:nowrap;"><?php echo $gi['processed']; ?>/<?php echo $gi['total_contacts']; ?></span>
                        </div>
                    </td>
                    <td style="color:var(--green);font-weight:600;"><?php echo $gi['imported']; ?></td>
                    <td style="color:var(--accent);font-weight:600;"><?php echo $gi['updated']; ?></td>
                    <td style="color:<?php echo $gi['failed'] > 0 ? 'var(--red)' : 'var(--text-tertiary)'; ?>;font-weight:600;"><?php echo $gi['failed']; ?></td>
                    <td><?php $tags = $gi['tags'] ?? []; echo count($tags) > 0 ? '<span style="font-size:11px;background:var(--accent);color:#fff;padding:2px 8px;border-radius:99px;">' . count($tags) . ' tag' . (count($tags) > 1 ? 's' : '') . '</span>' : '—'; ?></td>
                    <td style="font-size:12px;"><?php echo $gi['workflow_name'] ? htmlspecialchars($gi['workflow_name']) : '—'; ?></td>
                    <td>
                        <?php if ($gi['drip_enabled']): ?>
                            <span style="font-size:11px;background:#EDE9FE;color:#5B21B6;padding:2px 8px;border-radius:99px;font-weight:600;"><i class="fas fa-clock"></i> <?php echo $gi['drip_batch_size']; ?>/<?php echo $gi['drip_interval_minutes']; ?>m</span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div>

<div class="slide-backdrop" id="slideBackdrop" onclick="closeUser()"></div>
<div class="slide-panel" id="slidePanel">
    <div class="slide-header">
        <div style="font-weight:700;font-size:16px;">User Details</div>
        <button class="btn btn-secondary btn-sm" onclick="closeUser()"><i class="fas fa-times"></i></button>
    </div>
    <div class="slide-body" id="slideBody">
        <div style="text-align:center;padding:40px;color:var(--text-tertiary);"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
    </div>
</div>

<div class="modal-overlay" id="emailModal">
    <div class="modal-box">
        <h2><i class="fas fa-envelope" style="color:var(--accent);margin-right:8px;"></i>Send Email</h2>
        <div class="form-group"><label>To</label><input id="emailTo" readonly></div>
        <div class="form-group"><label>Subject</label><input id="emailSubject" placeholder="Subject line..."></div>
        <div class="form-group"><label>Message</label><textarea id="emailBody" placeholder="Write your message..."></textarea></div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="document.getElementById('emailModal').classList.remove('open')">Cancel</button>
            <button class="btn btn-primary" onclick="sendEmail()"><i class="fas fa-paper-plane"></i> Send</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="creditsModal">
    <div class="modal-box">
        <h2><i class="fas fa-coins" style="color:var(--orange);margin-right:8px;"></i>Add Credits</h2>
        <input type="hidden" id="creditsUserId">
        <div class="form-group"><label>Credits to Add</label><input type="number" id="creditsAmount" placeholder="e.g. 10"></div>
        <div class="form-group"><label>Reason (optional)</label><input id="creditsReason" placeholder="e.g. Bonus for feedback"></div>
        <div class="form-group" style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="creditsSendEmail" checked style="width:auto;"><label style="margin:0;">Send email notification</label></div>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="document.getElementById('creditsModal').classList.remove('open')">Cancel</button>
            <button class="btn btn-primary" onclick="addCredits()"><i class="fas fa-plus"></i> Add Credits</button>
        </div>
    </div>
</div>

<script>
const COLORS = ['#c85719','#34C759','#337f83','#ca942a','#FF3B30','#1460a6','#FF2D55','#FFCC00'];
function avatarColor(email) { let h = 0; for (let i = 0; i < email.length; i++) h = email.charCodeAt(i) + ((h << 5) - h); return COLORS[Math.abs(h) % COLORS.length]; }
function timeAgo(d) { const s = Math.floor((Date.now() - new Date(d)) / 1000); if (s < 60) return s+'s ago'; if (s < 3600) return Math.floor(s/60)+'m ago'; if (s < 86400) return Math.floor(s/3600)+'h ago'; return Math.floor(s/86400)+'d ago'; }

function switchTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.getElementById('tab-'+tab).classList.add('active');
    event.currentTarget.classList.add('active');
}

function filterUsers() {
    const q = document.getElementById('userSearch').value.toLowerCase();
    document.querySelectorAll('#usersBody tr').forEach(r => {
        r.style.display = r.dataset.search.includes(q) ? '' : 'none';
    });
}

let currentUserId = null;
async function openUser(id) {
    currentUserId = id;
    document.getElementById('slideBackdrop').classList.add('open');
    document.getElementById('slidePanel').classList.add('open');
    document.getElementById('slideBody').innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-tertiary);"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    const res = await fetch('admin.php?action=get_user&id='+id);
    const data = await res.json();
    if (!data.success) { document.getElementById('slideBody').innerHTML = '<p>Error loading user</p>'; return; }
    const u = data.user;
    const isOnline = u.last_active_at && (Date.now() - new Date(u.last_active_at)) < 900000;
    const color = avatarColor(u.email);
    let html = `
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;">
            <div class="user-avatar" style="background:${color};">${u.name.charAt(0).toUpperCase()}</div>
            <div>
                <div style="font-size:18px;font-weight:700;">${esc(u.name)}</div>
                <div style="font-size:13px;color:var(--text-secondary);">${esc(u.email)}</div>
                <div style="margin-top:4px;"><span class="online-dot ${isOnline?'on':'off'}"></span><span style="font-size:12px;color:var(--text-tertiary);">${isOnline?'Online now':'Last seen '+(u.last_active_at?timeAgo(u.last_active_at):'Never')}</span></div>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;">
            <button class="btn btn-primary btn-sm" onclick="openEmailModal('${esc(u.email)}','${esc(u.name)}')"><i class="fas fa-envelope"></i> Email</button>
            <button class="btn btn-secondary btn-sm" onclick="openCreditsModal(${u.id})"><i class="fas fa-coins"></i> Add Credits</button>
            <button class="btn btn-secondary btn-sm" onclick="loginAsUser(${u.id},'${esc(u.name)}')"><i class="fas fa-right-to-bracket"></i> Login As</button>
            <button class="btn btn-sm" style="background:#fee2e2;color:#b91c1c;" onclick="deleteUser(${u.id},'${esc(u.name)}')"><i class="fas fa-trash"></i> Delete</button>
        </div>
        <div class="slide-section"><h3>Account</h3>
            <div class="info-row"><span class="label">User ID</span><span class="value">#${u.id}</span></div>
            <div class="info-row"><span class="label">Plan</span><span class="value"><select class="badge" style="border:none;cursor:pointer;font-family:inherit;font-size:12px;padding:4px 8px;border-radius:6px;" onchange="changePlan(${u.id},this.value)">
                <option value="none" ${(u.subscription_plan||'none')==='none'?'selected':''}>Free</option>
                <option value="business" ${u.subscription_plan==='business'?'selected':''}>Starter</option>
                <option value="agency" ${u.subscription_plan==='agency'?'selected':''}>Growth</option>
                <option value="enterprise" ${u.subscription_plan==='enterprise'?'selected':''}>Pro</option>
            </select></span></div>
            <div class="info-row"><span class="label">Credits</span><span class="value" id="slideCredits">${Number(u.credits).toLocaleString()}</span></div>
            <div class="info-row"><span class="label">Joined</span><span class="value">${new Date(u.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</span></div>
            <div class="info-row"><span class="label">Admin</span><span class="value"><input type="checkbox" ${u.is_admin?'checked':''} ${u.id==1?'disabled':''} onchange="toggleAdmin(${u.id},this.checked)" style="accent-color:var(--accent);"></span></div>
        </div>
        <div class="slide-section"><h3>Activity</h3>
            <div class="stats-row" style="grid-template-columns:1fr 1fr 1fr;">
                <div class="stat-box"><div class="val">${u.list_count||0}</div><div class="lbl">Lists</div></div>
                <div class="stat-box"><div class="val">${Number(u.lead_count||0).toLocaleString()}</div><div class="lbl">Leads</div></div>
                <div class="stat-box"><div class="val">${Number(u.api_call_count||0).toLocaleString()}</div><div class="lbl">API Calls</div></div>
            </div>
        </div>`;

    if (u.lists && u.lists.length > 0) {
        html += `<div class="slide-section"><h3>Lead Lists</h3>`;
        u.lists.forEach(l => { html += `<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--card-border);font-size:13px;"><span style="font-weight:500;">${esc(l.name)}</span><span class="badge badge-gray">${l.leads} leads</span></div>`; });
        html += `</div>`;
    }

    if (u.ghl_imports && u.ghl_imports.length > 0) {
        html += `<div class="slide-section"><h3><i class="fas fa-paper-plane" style="color:var(--accent);margin-right:6px;"></i>GHL Imports (${u.ghl_imports.length})</h3>`;
        u.ghl_imports.forEach(g => {
            const pct = g.total_contacts > 0 ? Math.round((g.processed / g.total_contacts) * 100) : 0;
            const statusColor = g.status==='completed'?'badge-green':g.status==='running'?'badge-blue':g.status==='failed'?'badge-red':g.status==='paused'?'badge-orange':g.status==='cancelled'?'badge-gray':'badge-purple';
            const tags = g.tags || [];
            html += `<div style="background:var(--bg);border:1px solid var(--card-border);border-radius:8px;padding:10px 12px;margin-bottom:8px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                    <span style="font-weight:600;font-size:12px;">${esc(g.list_name || 'List #'+g.list_id)}</span>
                    <span class="badge ${statusColor}" style="font-size:10px;">${g.status}</span>
                </div>
                <div style="display:flex;gap:10px;font-size:11px;color:var(--text-secondary);margin-bottom:4px;">
                    <span><i class="fas fa-users"></i> ${g.total_contacts}</span>
                    <span style="color:var(--green);"><i class="fas fa-plus"></i> ${g.imported}</span>
                    <span style="color:var(--accent);"><i class="fas fa-sync"></i> ${g.updated}</span>
                    ${g.failed > 0 ? '<span style="color:var(--red);"><i class="fas fa-times"></i> '+g.failed+'</span>' : ''}
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <div style="flex:1;height:3px;background:var(--card-border);border-radius:99px;overflow:hidden;"><div style="height:100%;background:var(--accent);border-radius:99px;width:${pct}%;"></div></div>
                    <span style="font-size:10px;color:var(--text-tertiary);">${pct}%</span>
                </div>
                <div style="font-size:10px;color:var(--text-tertiary);margin-top:4px;">${timeAgo(g.created_at)}${g.connection_name ? ' · <i class="fas fa-bolt"></i> '+esc(g.connection_name) : ''}${g.drip_enabled ? ' · <i class="fas fa-clock"></i> Drip '+g.drip_batch_size+'/'+g.drip_interval_minutes+'m' : ''}${g.workflow_name ? ' · '+esc(g.workflow_name) : ''}</div>
                ${tags.length ? '<div style="margin-top:4px;">'+tags.map(t => '<span style="display:inline-block;padding:1px 6px;border-radius:99px;font-size:9px;font-weight:600;background:var(--accent);color:#fff;margin-right:2px;">'+esc(t)+'</span>').join('')+'</div>' : ''}
            </div>`;
        });
        html += `</div>`;
    }

    if (u.recent_api_calls && u.recent_api_calls.length > 0) {
        html += `<div class="slide-section"><h3>Recent Activity</h3>`;
        u.recent_api_calls.forEach(c => {
            const detail = c.search_query || c.url || '';
            html += `<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--card-border);">
                <div class="feed-dot scrape" style="width:28px;height:28px;font-size:11px;"><i class="fas fa-search"></i></div>
                <div style="flex:1;min-width:0;"><div style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(detail.substring(0,60))}</div><div style="font-size:11px;color:var(--text-tertiary);">${timeAgo(c.created_at)}</div></div>
            </div>`;
        });
        html += `</div>`;
    }

    document.getElementById('slideBody').innerHTML = html;
}

function closeUser() {
    document.getElementById('slideBackdrop').classList.remove('open');
    document.getElementById('slidePanel').classList.remove('open');
    currentUserId = null;
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

async function toggleAdmin(userId, isAdmin) {
    if (!confirm(isAdmin ? 'Make this user an admin?' : 'Remove admin privileges?')) { location.reload(); return; }
    await fetch('admin.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'toggle_admin',user_id:userId,is_admin:isAdmin}) });
}

function selectedUserIds() {
    return Array.from(document.querySelectorAll('.user-cb:checked')).map(cb => parseInt(cb.value, 10));
}
function updateBulkBar() {
    const n = selectedUserIds().length;
    document.getElementById('bulkBar').style.display = n ? 'flex' : 'none';
    document.getElementById('bulkCount').textContent = n;
    const all = document.querySelectorAll('.user-cb'), checked = document.querySelectorAll('.user-cb:checked');
    const sa = document.getElementById('selectAllUsers');
    if (sa) { sa.checked = all.length > 0 && checked.length === all.length; sa.indeterminate = checked.length > 0 && checked.length < all.length; }
}
function toggleAllUsers(master) {
    // Only affect rows currently visible (respects the search filter).
    document.querySelectorAll('#usersBody tr').forEach(tr => {
        if (tr.style.display === 'none') return;
        const cb = tr.querySelector('.user-cb'); if (cb) cb.checked = master.checked;
    });
    updateBulkBar();
}
function clearUserSelection() {
    document.querySelectorAll('.user-cb').forEach(cb => cb.checked = false);
    updateBulkBar();
}
async function bulkDeleteUsers() {
    const ids = selectedUserIds();
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' user' + (ids.length > 1 ? 's' : '') + ' and ALL their data (lists, leads, transactions)? This cannot be undone.')) return;
    if (!confirm('Are you absolutely sure? This permanently deletes ' + ids.length + ' account' + (ids.length > 1 ? 's' : '') + '.')) return;
    const res = await fetch('admin.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'delete_user', user_ids: ids}) });
    const d = await res.json();
    if (d.errors && d.errors.length) alert('Deleted ' + (d.deleted || 0) + '. Skipped:\n' + d.errors.join('\n'));
    location.reload();
}

async function deleteUser(userId, userName) {
    if (!confirm('Delete "' + userName + '" and ALL their data (lists, leads, transactions)? This cannot be undone.')) return;
    if (!confirm('Are you absolutely sure? This permanently deletes the account.')) return;
    const res = await fetch('admin.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'delete_user',user_id:userId}) });
    const d = await res.json();
    if (d.success) { location.reload(); } else { alert(d.error || 'Delete failed'); }
}

async function changePlan(userId, plan) {
    await fetch('admin.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'change_plan',user_id:userId,plan}) });
}

function openEmailModal(email, name) {
    document.getElementById('emailTo').value = email;
    document.getElementById('emailSubject').value = '';
    document.getElementById('emailBody').value = '';
    document.getElementById('emailModal').classList.add('open');
    document.getElementById('emailModal')._toName = name;
}

async function sendEmail() {
    const modal = document.getElementById('emailModal');
    const res = await fetch('admin.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
        action:'send_email',
        to_email: document.getElementById('emailTo').value,
        to_name: modal._toName || '',
        subject: document.getElementById('emailSubject').value,
        body: document.getElementById('emailBody').value
    }) });
    const data = await res.json();
    if (data.success) { alert('Email sent!'); modal.classList.remove('open'); }
    else alert('Failed: ' + (data.error || 'Unknown error'));
}

function openCreditsModal(userId) {
    document.getElementById('creditsUserId').value = userId;
    document.getElementById('creditsAmount').value = '';
    document.getElementById('creditsReason').value = '';
    document.getElementById('creditsModal').classList.add('open');
}

async function addCredits() {
    const res = await fetch('admin.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({
        action:'add_credits',
        user_id: document.getElementById('creditsUserId').value,
        credits: document.getElementById('creditsAmount').value,
        reason: document.getElementById('creditsReason').value,
        send_email: document.getElementById('creditsSendEmail').checked
    }) });
    const data = await res.json();
    if (data.success) {
        alert('Credits added!');
        document.getElementById('creditsModal').classList.remove('open');
        const el = document.getElementById('slideCredits');
        if (el) el.textContent = Number(data.new_credits).toLocaleString();
    } else alert('Failed: ' + (data.error || 'Unknown'));
}

async function loginAsUser(userId, userName) {
    if (!confirm('Login as ' + userName + '? You will be redirected to the dashboard as this user.')) return;
    const res = await fetch('admin.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'login_as_user',user_id:userId}) });
    const data = await res.json();
    if (data.success) window.location.href = 'dashboard.php';
    else alert('Failed: ' + (data.error || 'Unknown'));
}

function filterGHLImports() {
    const search = (document.getElementById('ghlImportSearch')?.value || '').toLowerCase();
    const statusFilter = document.getElementById('ghlImportStatusFilter')?.value || 'all';
    document.querySelectorAll('.ghl-import-row').forEach(row => {
        const user = row.dataset.user || '';
        const list = row.dataset.list || '';
        const status = row.dataset.status || '';
        const matchSearch = !search || user.includes(search) || list.includes(search);
        const matchStatus = statusFilter === 'all' || status === statusFilter;
        row.style.display = matchSearch && matchStatus ? '' : 'none';
    });
}
</script>
</body>
</html>