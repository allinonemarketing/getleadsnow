<?php
session_start();
require_once 'config/database.php';
require_once 'config/app.php';

$token = $_GET['token'] ?? '';
if (!$token || strlen($token) !== 64 || !ctype_xdigit($token)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><h1>Not Found</h1></body></html>';
    exit;
}

$stmt = $pdo->prepare("SELECT l.*, u.id as owner_id, u.name as owner_name FROM lead_lists l JOIN users u ON u.id = l.user_id WHERE l.public_token = ? AND l.is_public = 1");
$stmt->execute([$token]);
$list = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$list) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><h1>Not Found</h1></body></html>';
    exit;
}

// Ensure list_claims table exists
try {
    $pdo->query("SELECT 1 FROM list_claims LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE list_claims (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_list_id INT NOT NULL,
        owner_user_id INT NOT NULL,
        claimed_by_user_id INT NOT NULL,
        claimed_user_name VARCHAR(255),
        claimed_user_email VARCHAR(255),
        new_list_id INT NOT NULL,
        claim_type ENUM('signup','login') NOT NULL DEFAULT 'signup',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_source (source_list_id),
        INDEX idx_owner (owner_user_id)
    )");
}

// Handle signup + duplicate via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim') {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($name) || empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }

    try {
        $existing = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $existing->execute([$email]);
        if ($existing->fetch()) {
            echo json_encode(['success' => false, 'message' => 'An account with this email already exists. Please log in instead.']);
            exit;
        }

        $pdo->beginTransaction();

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $creditsToGive = defined('FREE_SIGNUP_CREDITS') ? FREE_SIGNUP_CREDITS : 3;
        $ins = $pdo->prepare("INSERT INTO users (name, email, password, credits) VALUES (?, ?, ?, ?)");
        $ins->execute([$name, $email, $hashedPassword, $creditsToGive]);
        $newUserId = $pdo->lastInsertId();

        $newToken = bin2hex(random_bytes(32));
        $dupList = $pdo->prepare("INSERT INTO lead_lists (user_id, name, description, is_public, public_token) VALUES (?, ?, ?, 0, ?)");
        $dupList->execute([$newUserId, $list['name'], $list['description'] ?? '', $newToken]);
        $newListId = $pdo->lastInsertId();

        $cols = 'business_id, business_name, address, city, state, phone, website, rating, review_count, types, latitude, longitude, emails, social_media_links, visited_socials, notes, raw_data';
        $dupItems = $pdo->prepare("
            INSERT INTO lead_list_items (list_id, user_id, $cols)
            SELECT ?, ?, $cols FROM lead_list_items WHERE list_id = ?
        ");
        $dupItems->execute([$newListId, $newUserId, $list['id']]);

        $pdo->prepare("INSERT INTO list_claims (source_list_id, owner_user_id, claimed_by_user_id, claimed_user_name, claimed_user_email, new_list_id, claim_type) VALUES (?,?,?,?,?,?,?)")
            ->execute([$list['id'], $list['owner_id'], $newUserId, $name, $email, $newListId, 'signup']);

        $pdo->commit();

        $_SESSION['user_id'] = $newUserId;
        $_SESSION['user_name'] = $name;

        echo json_encode(['success' => true, 'redirect' => APP_URL . '/dashboard?section=lead_lists']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Claim list error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    }
    exit;
}

// Handle login + duplicate via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim_login') {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        exit;
    }

    try {
        $userStmt = $pdo->prepare("SELECT id, name, password FROM users WHERE email = ?");
        $userStmt->execute([$email]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            exit;
        }

        $pdo->beginTransaction();

        $newToken = bin2hex(random_bytes(32));
        $dupList = $pdo->prepare("INSERT INTO lead_lists (user_id, name, description, is_public, public_token) VALUES (?, ?, ?, 0, ?)");
        $dupList->execute([$user['id'], $list['name'], $list['description'] ?? '', $newToken]);
        $newListId = $pdo->lastInsertId();

        $cols = 'business_id, business_name, address, city, state, phone, website, rating, review_count, types, latitude, longitude, emails, social_media_links, visited_socials, notes, raw_data';
        $dupItems = $pdo->prepare("
            INSERT INTO lead_list_items (list_id, user_id, $cols)
            SELECT ?, ?, $cols FROM lead_list_items WHERE list_id = ?
        ");
        $dupItems->execute([$newListId, $user['id'], $list['id']]);

        $pdo->prepare("INSERT INTO list_claims (source_list_id, owner_user_id, claimed_by_user_id, claimed_user_name, claimed_user_email, new_list_id, claim_type) VALUES (?,?,?,?,?,?,?)")
            ->execute([$list['id'], $list['owner_id'], $user['id'], $user['name'], $email, $newListId, 'login']);

        $pdo->commit();

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];

        echo json_encode(['success' => true, 'redirect' => APP_URL . '/dashboard?section=lead_lists']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Claim login error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
    }
    exit;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM lead_list_items WHERE list_id = ?");
$countStmt->execute([$list['id']]);
$leadCount = $countStmt->fetchColumn();

$statsStmt = $pdo->prepare("
    SELECT
        COUNT(CASE WHEN phone IS NOT NULL AND phone != '' THEN 1 END) as with_phone,
        COUNT(CASE WHEN JSON_LENGTH(COALESCE(emails, '[]')) > 0 THEN 1 END) as with_email,
        COUNT(CASE WHEN JSON_LENGTH(COALESCE(social_media_links, '[]')) > 0 THEN 1 END) as with_socials
    FROM lead_list_items WHERE list_id = ?
");
$statsStmt->execute([$list['id']]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$previewLimit = min(10, $leadCount);
$previewStmt = $pdo->prepare("SELECT business_name, city, state, phone, website, emails FROM lead_list_items WHERE list_id = ? ORDER BY id LIMIT ?");
$previewStmt->bindValue(1, $list['id'], PDO::PARAM_INT);
$previewStmt->bindValue(2, $previewLimit, PDO::PARAM_INT);
$previewStmt->execute();
$previewLeads = $previewStmt->fetchAll(PDO::FETCH_ASSOC);

$lockedCount = max(0, $leadCount - $previewLimit);

$claimCountStmt = $pdo->prepare("SELECT COUNT(*) FROM list_claims WHERE source_list_id = ?");
$claimCountStmt->execute([$list['id']]);
$totalClaims = (int)$claimCountStmt->fetchColumn();

$phonePercent = $leadCount > 0 ? round(($stats['with_phone'] / $leadCount) * 100) : 0;
$emailPercent = $leadCount > 0 ? round(($stats['with_email'] / $leadCount) * 100) : 0;
$socialPercent = $leadCount > 0 ? round(($stats['with_socials'] / $leadCount) * 100) : 0;
$avgDataPoints = $leadCount > 0 ? round(($stats['with_phone'] + $stats['with_email'] + $stats['with_socials']) / $leadCount, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($list['name']) ?> — <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= APP_LOGO ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --accent: #c85719;
            --accent-light: #fce8dc;
            --accent-hover: #a84615;
            --green: #34C759;
            --teal: #337f83;
            --blue: #1460a6;
            --gold: #ca942a;
            --text: #1d1d1f;
            --text-secondary: #6e6e73;
            --text-tertiary: #aeaeb2;
            --bg: #ffffff;
            --bg-secondary: #f5f5f7;
            --card-border: rgba(0,0,0,0.06);
            --radius: 16px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

        .topbar {
            background: var(--accent); color: #fff; text-align: center;
            padding: 10px 16px; font-size: 13px; font-weight: 600;
            letter-spacing: 0.2px;
        }
        .topbar i { margin-right: 4px; }

        .hero {
            padding: 72px 24px 80px;
            text-align: center;
            background: linear-gradient(180deg, var(--bg) 0%, var(--bg-secondary) 100%);
            position: relative;
        }
        .hero-eyebrow {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--accent-light); color: var(--accent);
            padding: 7px 16px; border-radius: 980px; font-size: 13px; font-weight: 600;
            margin-bottom: 24px;
        }
        .hero h1 {
            font-size: clamp(2rem, 5vw, 3.2rem); font-weight: 800; line-height: 1.1;
            letter-spacing: -0.03em; margin-bottom: 16px;
            max-width: 680px; margin-left: auto; margin-right: auto;
        }
        .hero h1 span { color: var(--accent); }
        .hero-sub {
            font-size: 17px; color: var(--text-secondary); max-width: 520px;
            margin: 0 auto 36px; line-height: 1.65;
        }
        .hero-cta {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--accent); color: #fff; border: none; border-radius: 14px;
            padding: 16px 36px; font-size: 16px; font-weight: 700;
            cursor: pointer; font-family: inherit;
            box-shadow: 0 4px 14px rgba(200,87,25,0.3);
            transition: all 0.2s;
        }
        .hero-cta:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(200,87,25,0.35); }
        .hero-trust {
            margin-top: 20px; display: flex; align-items: center; justify-content: center; gap: 20px;
            font-size: 13px; color: var(--text-tertiary);
        }
        .hero-trust span { display: inline-flex; align-items: center; gap: 5px; }
        .hero-trust i { color: var(--green); font-size: 12px; }

        .stats-row {
            display: flex; justify-content: center; gap: 48px;
            padding: 40px 24px; flex-wrap: wrap;
        }
        .stat-item { text-align: center; }
        .stat-item .num { font-size: 2rem; font-weight: 800; color: var(--text); letter-spacing: -0.02em; }
        .stat-item .lbl { font-size: 12px; color: var(--text-tertiary); font-weight: 500; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.5px; }

        .section { max-width: 900px; margin: 0 auto; padding: 0 20px; }
        .section-header { text-align: center; margin-bottom: 32px; padding-top: 48px; }
        .section-header h2 { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 6px; }
        .section-header p { font-size: 15px; color: var(--text-secondary); }

        .preview-card {
            background: var(--bg); border: 1px solid var(--card-border);
            border-radius: var(--radius); overflow: hidden;
            box-shadow: 0 2px 20px rgba(0,0,0,0.04);
        }
        .preview-header {
            padding: 16px 24px; border-bottom: 1px solid var(--card-border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .preview-header h3 { font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .preview-header h3 i { color: var(--accent); }
        .preview-header .badge {
            font-size: 11px; font-weight: 600; color: var(--accent);
            background: var(--accent-light); padding: 4px 12px; border-radius: 980px;
        }

        .lead-table { width: 100%; border-collapse: collapse; }
        .lead-table th {
            text-align: left; font-size: 11px; font-weight: 600; color: var(--text-tertiary);
            text-transform: uppercase; letter-spacing: 0.4px;
            padding: 12px 16px; border-bottom: 1px solid var(--card-border);
            background: var(--bg-secondary);
        }
        .lead-table td {
            padding: 13px 16px; font-size: 13px; border-bottom: 1px solid rgba(0,0,0,0.03);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;
            color: var(--text-secondary);
        }
        .lead-table tr:last-child td { border-bottom: none; }
        .lead-table tr:hover td { background: var(--bg-secondary); }
        .lead-table .biz { font-weight: 600; color: var(--text); }
        .lead-table .has-data { color: var(--green); }
        .lead-table .no-data { color: var(--text-tertiary); }

        .locked-section { position: relative; overflow: hidden; }
        .locked-rows { filter: blur(6px); pointer-events: none; user-select: none; opacity: 0.5; }
        .locked-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.95) 40%, #fff 100%);
            display: flex; align-items: center; justify-content: center;
        }
        .locked-cta { text-align: center; padding: 32px 24px; }
        .locked-cta .lock-icon {
            width: 64px; height: 64px; border-radius: 50%;
            background: var(--accent-light); color: var(--accent);
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 16px; font-size: 24px;
        }
        .locked-cta h3 { font-size: 22px; font-weight: 800; margin-bottom: 8px; letter-spacing: -0.02em; }
        .locked-cta h3 span { color: var(--accent); }
        .locked-cta p { font-size: 14px; color: var(--text-secondary); margin-bottom: 20px; max-width: 400px; margin-left: auto; margin-right: auto; line-height: 1.6; }
        .locked-cta .unlock-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--accent); color: #fff; border: none; border-radius: 14px;
            padding: 16px 36px; font-size: 16px; font-weight: 700;
            cursor: pointer; font-family: inherit;
            box-shadow: 0 4px 14px rgba(200,87,25,0.3);
            transition: all 0.2s;
        }
        .locked-cta .unlock-btn:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(200,87,25,0.35); }

        .stack-section { max-width: 640px; margin: 0 auto; padding: 48px 20px 56px; }
        .stack-list { list-style: none; }
        .stack-item {
            display: flex; align-items: center; gap: 16px;
            padding: 16px 20px; background: var(--bg);
            border: 1px solid var(--card-border); border-radius: 14px;
            margin-bottom: 8px; transition: border-color 0.2s;
        }
        .stack-item:hover { border-color: rgba(200,87,25,0.2); }
        .stack-item .si-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .stack-item .si-text { flex: 1; }
        .stack-item .si-text strong { font-size: 14px; font-weight: 600; display: block; margin-bottom: 2px; }
        .stack-item .si-text span { font-size: 12px; color: var(--text-tertiary); }
        .stack-item .si-check { color: var(--green); font-size: 16px; flex-shrink: 0; }

        .final-cta-section {
            text-align: center; padding: 56px 20px 64px;
            background: var(--bg-secondary);
        }
        .final-cta-section h2 { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 10px; }
        .final-cta-section p { font-size: 16px; color: var(--text-secondary); margin-bottom: 28px; max-width: 480px; margin-left: auto; margin-right: auto; line-height: 1.6; }

        .footer-promo {
            text-align: center; padding: 28px 16px;
            border-top: 1px solid var(--card-border);
        }
        .footer-promo p { font-size: 12px; color: var(--text-tertiary); margin-bottom: 4px; }
        .footer-promo a { color: var(--accent); font-weight: 600; text-decoration: none; font-size: 13px; }

        /* MODAL */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.4); backdrop-filter: blur(8px);
            z-index: 1000; align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: var(--bg); border-radius: 20px; padding: 40px 36px;
            max-width: 420px; width: 92%;
            box-shadow: 0 25px 80px rgba(0,0,0,0.15);
            position: relative;
        }
        .modal-close {
            position: absolute; top: 16px; right: 16px;
            background: none; border: none; font-size: 16px; color: var(--text-tertiary);
            cursor: pointer; width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .modal-close:hover { background: var(--bg-secondary); color: var(--text); }
        .modal-badge { text-align: center; margin-bottom: 20px; }
        .modal-badge .mb-icon {
            width: 56px; height: 56px; border-radius: 50%;
            background: var(--accent-light); color: var(--accent);
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 12px; font-size: 22px;
        }
        .modal h2 { font-size: 22px; font-weight: 800; text-align: center; margin-bottom: 4px; letter-spacing: -0.02em; }
        .modal .subtitle { font-size: 13px; color: var(--text-secondary); text-align: center; margin-bottom: 22px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 5px; }
        .form-group input {
            width: 100%; padding: 12px 14px; border: 1.5px solid var(--card-border);
            border-radius: 10px; font-size: 14px; font-family: inherit;
            outline: none; transition: border-color 0.2s;
            background: var(--bg); color: var(--text);
        }
        .form-group input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(200,87,25,0.08); }
        .form-group input::placeholder { color: var(--text-tertiary); }
        .submit-btn {
            width: 100%; padding: 14px; margin-top: 8px;
            background: var(--accent); color: #fff; border: none; border-radius: 12px;
            font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit;
            transition: all 0.2s;
            box-shadow: 0 4px 14px rgba(200,87,25,0.25);
        }
        .submit-btn:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 6px 20px rgba(200,87,25,0.3); }
        .submit-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }
        .form-error { color: #FF3B30; font-size: 12px; margin-top: 8px; display: none; text-align: center; }
        .form-guarantee {
            text-align: center; margin-top: 14px; font-size: 11px; color: var(--text-tertiary);
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .form-guarantee i { color: var(--green); }
        .toggle-link { text-align: center; margin-top: 16px; font-size: 13px; color: var(--text-secondary); }
        .toggle-link a { color: var(--accent); font-weight: 600; text-decoration: none; cursor: pointer; }
        .toggle-link a:hover { text-decoration: underline; }

        @media (max-width: 640px) {
            .hero h1 { font-size: 1.75rem; }
            .hero-sub { font-size: 15px; }
            .hero-cta { font-size: 15px; padding: 14px 28px; }
            .stats-row { gap: 24px; }
            .stat-item .num { font-size: 1.5rem; }
            .lead-table th:nth-child(n+4), .lead-table td:nth-child(n+4) { display: none; }
            .modal { padding: 28px 24px; }
            .stack-item { flex-wrap: wrap; }
            .hero-trust { flex-wrap: wrap; gap: 12px; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <i class="fas fa-bolt"></i> <?= number_format($leadCount) ?> verified leads available — claim yours free
</div>

<div style="padding:16px 24px;display:flex;align-items:center;justify-content:center;gap:8px;border-bottom:1px solid var(--card-border);background:var(--bg);">
    <img src="<?= APP_LOGO ?>" alt="<?= APP_NAME ?>" style="height:28px;border-radius:6px;">
    <span style="font-weight:800;font-size:1.1rem;color:var(--text);letter-spacing:-0.02em;"><?= APP_NAME ?></span>
</div>

<div class="hero">
    <div class="hero-eyebrow"><i class="fas fa-gift"></i> Free Lead List</div>
    <h1>Get <span><?= number_format($leadCount) ?> Ready-to-Work</span> Leads, Instantly</h1>
    <p class="hero-sub"><?php if ($list['description']): ?><?= htmlspecialchars($list['description']) ?><?php else: ?>Verified leads with phone numbers, emails, and social profiles. Create a free account and they're yours.<?php endif; ?></p>
    <button class="hero-cta" onclick="openModal()"><i class="fas fa-arrow-right"></i> Claim Free Leads</button>
    <div class="hero-trust">
        <span><i class="fas fa-check-circle"></i> 100% Free</span>
        <span><i class="fas fa-check-circle"></i> Instant Access</span>
    </div>
</div>

<div class="stats-row">
    <div class="stat-item"><div class="num"><?= number_format($leadCount) ?></div><div class="lbl">Total Leads</div></div>
    <div class="stat-item"><div class="num"><?= number_format($stats['with_phone']) ?></div><div class="lbl">Phone Numbers</div></div>
    <div class="stat-item"><div class="num"><?= number_format($stats['with_email']) ?></div><div class="lbl">Email Addresses</div></div>
    <div class="stat-item"><div class="num"><?= number_format($stats['with_socials']) ?></div><div class="lbl">Social Profiles</div></div>
</div>

<div class="section">
    <div class="preview-card">
        <div class="preview-header">
            <h3><i class="fas fa-table"></i> Lead Preview — <?= htmlspecialchars($list['name']) ?></h3>
            <span class="badge">Top <?= $previewLimit ?> of <?= number_format($leadCount) ?></span>
        </div>
        <div style="overflow-x:auto;">
            <table class="lead-table">
                <thead>
                    <tr>
                        <th>Business</th>
                        <th>Location</th>
                        <th>Phone</th>
                        <th>Website</th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewLeads as $lead): ?>
                    <?php $emails = json_decode($lead['emails'] ?: '[]', true); ?>
                    <tr>
                        <td class="biz"><?= htmlspecialchars($lead['business_name'] ?: '—') ?></td>
                        <td><?= htmlspecialchars(trim(($lead['city'] ?? '') . ', ' . ($lead['state'] ?? ''), ', ')) ?: '—' ?></td>
                        <td><?php if ($lead['phone']): ?><span class="has-data"><i class="fas fa-check-circle"></i></span><?php else: ?><span class="no-data">—</span><?php endif; ?></td>
                        <td><?php if ($lead['website']): ?><span class="has-data"><i class="fas fa-check-circle"></i></span><?php else: ?><span class="no-data">—</span><?php endif; ?></td>
                        <td><?php if (!empty($emails)): ?><span class="has-data"><i class="fas fa-check-circle"></i></span><?php else: ?><span class="no-data">—</span><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($lockedCount > 0): ?>
        <div class="locked-section">
            <div class="locked-rows">
                <table class="lead-table">
                    <tbody>
                        <?php for ($i = 0; $i < min(6, $lockedCount); $i++): ?>
                        <tr>
                            <td>████████ ███████</td>
                            <td>██████, ██</td>
                            <td>███-███-████</td>
                            <td>████████.com</td>
                            <td>████@████.com</td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <div class="locked-overlay">
                <div class="locked-cta">
                    <div class="lock-icon"><i class="fas fa-lock"></i></div>
                    <h3>+<?= number_format($lockedCount) ?> <span>more leads</span></h3>
                    <p>Create a free account to unlock all <?= number_format($leadCount) ?> leads and start working them from your dashboard.</p>
                    <button class="unlock-btn" onclick="openModal()"><i class="fas fa-arrow-right"></i> Unlock All Leads — Free</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="stack-section">
    <div class="section-header">
        <h2>Everything included, free</h2>
        <p>Here's what you get when you claim this list</p>
    </div>
    <ul class="stack-list">
        <li class="stack-item">
            <div class="si-icon" style="background:var(--accent-light);color:var(--accent);"><i class="fas fa-database"></i></div>
            <div class="si-text">
                <strong><?= number_format($leadCount) ?> Verified Business Leads</strong>
                <span>Name, address, phone, website for every lead</span>
            </div>
            <div class="si-check"><i class="fas fa-check-circle"></i></div>
        </li>
        <?php if ($stats['with_phone'] > 0): ?>
        <li class="stack-item">
            <div class="si-icon" style="background:rgba(52,199,89,0.1);color:var(--green);"><i class="fas fa-phone-alt"></i></div>
            <div class="si-text">
                <strong><?= number_format($stats['with_phone']) ?> Direct Phone Numbers</strong>
                <span><?= $phonePercent ?>% of leads have a phone number</span>
            </div>
            <div class="si-check"><i class="fas fa-check-circle"></i></div>
        </li>
        <?php endif; ?>
        <?php if ($stats['with_email'] > 0): ?>
        <li class="stack-item">
            <div class="si-icon" style="background:rgba(20,96,166,0.1);color:var(--blue);"><i class="fas fa-at"></i></div>
            <div class="si-text">
                <strong><?= number_format($stats['with_email']) ?> Email Addresses</strong>
                <span><?= $emailPercent ?>% of leads have an email</span>
            </div>
            <div class="si-check"><i class="fas fa-check-circle"></i></div>
        </li>
        <?php endif; ?>
        <?php if ($stats['with_socials'] > 0): ?>
        <li class="stack-item">
            <div class="si-icon" style="background:rgba(51,127,131,0.1);color:var(--teal);"><i class="fas fa-share-nodes"></i></div>
            <div class="si-text">
                <strong><?= number_format($stats['with_socials']) ?> Social Media Profiles</strong>
                <span>Facebook, Instagram, LinkedIn & more</span>
            </div>
            <div class="si-check"><i class="fas fa-check-circle"></i></div>
        </li>
        <?php endif; ?>
        <li class="stack-item">
            <div class="si-icon" style="background:rgba(202,148,42,0.1);color:var(--gold);"><i class="fas fa-tachometer-alt"></i></div>
            <div class="si-text">
                <strong>Full Dashboard Access</strong>
                <span>Manage, sort, filter, and export your leads</span>
            </div>
            <div class="si-check"><i class="fas fa-check-circle"></i></div>
        </li>
    </ul>
</div>

<div class="final-cta-section">
    <h2>Ready to get started?</h2>
    <p>These <?= number_format($leadCount) ?> leads are ready to work. Create your free account and start closing.</p>
    <button class="hero-cta" onclick="openModal()"><i class="fas fa-arrow-right"></i> Claim My Free Leads</button>
    <div class="hero-trust" style="margin-top:16px;">
        <span><i class="fas fa-check-circle"></i> 100% Free</span>
        <span><i class="fas fa-check-circle"></i> Instant Access</span>
    </div>
</div>

<div class="footer-promo">
    <a href="<?= APP_URL ?>" target="_blank" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;">
        <img src="<?= APP_LOGO ?>" alt="<?= APP_NAME ?>" style="height:24px;border-radius:5px;">
        <span style="font-weight:700;font-size:14px;color:var(--text);"><?= APP_NAME ?></span>
    </a>
    <p style="margin-top:6px;">Powered by <?= APP_NAME ?></p>
</div>

<div class="modal-overlay" id="authModal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>

        <div id="signupView">
            <div class="modal-badge">
                <div class="mb-icon"><i class="fas fa-gift"></i></div>
            </div>
            <h2>Claim Your Leads</h2>
            <p class="subtitle">Create a free account and <?= number_format($leadCount) ?> leads are instantly yours.</p>
            <form onsubmit="handleSignup(event)">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" id="signupName" placeholder="John Smith" required>
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="signupEmail" placeholder="john@company.com" required>
                </div>
                <div class="form-group">
                    <label>Create Password</label>
                    <input type="password" id="signupPassword" placeholder="Min 6 characters" minlength="6" required>
                </div>
                <div class="form-error" id="signupError"></div>
                <button type="submit" class="submit-btn" id="signupBtn"><i class="fas fa-arrow-right"></i> Get My Free Leads</button>
            </form>
            <div class="form-guarantee"><i class="fas fa-shield-alt"></i> Instant access.</div>
            <div class="toggle-link">Already have an account? <a onclick="showLogin()">Sign in</a></div>
        </div>

        <div id="loginView" style="display:none;">
            <div class="modal-badge">
                <div class="mb-icon"><i class="fas fa-sign-in-alt"></i></div>
            </div>
            <h2>Welcome Back</h2>
            <p class="subtitle">Sign in to import these <?= number_format($leadCount) ?> leads into your account.</p>
            <form onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="loginEmail" placeholder="john@company.com" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="loginPassword" required>
                </div>
                <div class="form-error" id="loginError"></div>
                <button type="submit" class="submit-btn" id="loginBtn"><i class="fas fa-sign-in-alt"></i> Sign In & Claim Leads</button>
            </form>
            <div class="toggle-link">Don't have an account? <a onclick="showSignup()">Create one free</a></div>
        </div>
    </div>
</div>

<script>
function openModal() { document.getElementById('authModal').classList.add('open'); }
function closeModal() { document.getElementById('authModal').classList.remove('open'); }
function showLogin() { document.getElementById('signupView').style.display='none'; document.getElementById('loginView').style.display='block'; }
function showSignup() { document.getElementById('loginView').style.display='none'; document.getElementById('signupView').style.display='block'; }
document.getElementById('authModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

function handleSignup(e) {
    e.preventDefault();
    const btn = document.getElementById('signupBtn');
    const err = document.getElementById('signupError');
    err.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating account...';

    const fd = new FormData();
    fd.append('action', 'claim');
    fd.append('name', document.getElementById('signupName').value);
    fd.append('email', document.getElementById('signupEmail').value);
    fd.append('password', document.getElementById('signupPassword').value);

    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check"></i> Redirecting...';
            window.location.href = data.redirect;
        } else {
            err.textContent = data.message;
            err.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-arrow-right"></i> Get My Free Leads';
        }
    })
    .catch(() => {
        err.textContent = 'Network error. Please try again.';
        err.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Get My Free Leads';
    });
}

function handleLogin(e) {
    e.preventDefault();
    const btn = document.getElementById('loginBtn');
    const err = document.getElementById('loginError');
    err.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';

    const fd = new FormData();
    fd.append('action', 'claim_login');
    fd.append('email', document.getElementById('loginEmail').value);
    fd.append('password', document.getElementById('loginPassword').value);

    fetch(window.location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check"></i> Redirecting...';
            window.location.href = data.redirect;
        } else {
            err.textContent = data.message;
            err.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In & Claim Leads';
        }
    })
    .catch(() => {
        err.textContent = 'Network error. Please try again.';
        err.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign In & Claim Leads';
    });
}
</script>

</body>
</html>
