<?php
require_once 'config/database.php';
require_once 'config/subscription_config.php';
require_once 'includes/email_service.php';
require_once 'includes/facebook.php';
require_once 'includes/ghl_signup.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$phone = trim($_POST['phone'] ?? '');
$wantsOwnership = (($_POST['wants_ownership'] ?? '') === 'yes') ? 'yes' : 'no';

// Attribution / context captured client-side + server-side.
$utmSource   = trim($_POST['utm_source'] ?? '');
$utmMedium   = trim($_POST['utm_medium'] ?? '');
$utmCampaign = trim($_POST['utm_campaign'] ?? '');
$fbCampaignId = trim($_POST['fbcampaignid'] ?? '');
$fbPlacement  = trim($_POST['fbplacement'] ?? '');
$fbAdsetId    = trim($_POST['fbadsetid'] ?? '');
$fbAdId       = trim($_POST['fbadid'] ?? '');
$timezone    = trim($_POST['timezone'] ?? '');
$referrer    = trim($_POST['referrer'] ?? '');
// Which landing page / channel the signup came from — tagged into the Google Sheet
// and GHL "source" field. Whitelisted so the client can't inject arbitrary values.
// /start (FB ads) => free_signup (default); /leads => email_referral; /1cent (FB "1 cent leads" ads) => fb_1cent.
$signupSource = $_POST['signup_source'] ?? 'free_signup';
if (!in_array($signupSource, ['free_signup', 'email_referral', 'fb_1cent'], true)) { $signupSource = 'free_signup'; }
// Safety net: derive the source from the ACTUAL landing-page URL (sent by every
// signup form as event_source_url). The page path is authoritative — it corrects
// stale hidden fields and makes attribution auditable against the referrer.
$srcPath = strtolower((string)(parse_url(trim($_POST['event_source_url'] ?? ''), PHP_URL_PATH) ?: ''));
if (strpos($srcPath, '/1cent') === 0)      { $signupSource = 'fb_1cent'; }
elseif (strpos($srcPath, '/leads') === 0)  { $signupSource = 'email_referral'; }
elseif (strpos($srcPath, '/start') === 0)  { $signupSource = 'free_signup'; }
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); } // first hop in a proxy chain
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

if (empty($name) || empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Name, email, and password are required.']);
    exit;
}

try {
    global $pdo;

    // Make sure the signup columns exist — once per deploy, not on every signup.
    $regSchemaFlag = sys_get_temp_dir() . '/getleadsnow_register_schema_v1.ok';
    if (!@is_file($regSchemaFlag)) {
        try { $pdo->query("SELECT wants_ownership FROM users LIMIT 1"); }
        catch (Exception $e) { $pdo->exec("ALTER TABLE users ADD COLUMN wants_ownership VARCHAR(3) DEFAULT NULL"); }
        try { $pdo->query("SELECT phone FROM users LIMIT 1"); }
        catch (Exception $e) { $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(40) DEFAULT NULL"); }
        @file_put_contents($regSchemaFlag, '1');
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    // Free tier: everyone starts with a one-time batch of free credits.
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, credits, wants_ownership, phone) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $hashedPassword, FREE_TIER_CREDITS, $wantsOwnership, $phone]);
    $userId = $pdo->lastInsertId();

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;

    // CRITICAL: release the session lock before the blocking signup side-effects
    // below (2 SMTP sends + external curls to Sheets/Facebook/GHL — up to ~50s
    // combined). The browser redirects to dashboard.php immediately after signup,
    // and dashboard.php's session_start() would otherwise block on THIS lock until
    // every curl finishes. No $_SESSION write happens after this point.
    session_write_close();

    // Respond to the browser NOW — the account is fully created. The marketing
    // side-effects below (2 SMTP sends + Sheets/Facebook/GHL curls, up to ~50s
    // worst case) continue AFTER the response is flushed, so the user lands on
    // the dashboard instantly instead of waiting on our emails and integrations.
    ignore_user_abort(true);
    echo json_encode(['success' => true, 'message' => 'Registration successful! Welcome aboard.']);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }

    sendAdminNotification(['name' => $name, 'email' => $email, 'wants_ownership' => $wantsOwnership]);
    sendWelcomeEmail(['name' => $name, 'email' => $email]);
    sendSignupToSheet([
        'name' => $name, 'email' => $email, 'phone' => $phone,
        'dnd' => isTexasNumber($phone) ? 'Yes (SMS)' : 'No',
        'wants_ownership' => $wantsOwnership, 'source' => $signupSource,
        'utm_source' => $utmSource, 'utm_medium' => $utmMedium, 'utm_campaign' => $utmCampaign,
        'fbcampaignid' => $fbCampaignId, 'fbplacement' => $fbPlacement,
        'fbadsetid' => $fbAdsetId, 'fbadid' => $fbAdId,
        'timezone' => $timezone, 'referrer' => $referrer, 'ip' => $ip, 'user_agent' => $userAgent,
    ]);

    // Meta Conversions API — server-side Lead (deduped with the Pixel via event_id).
    sendFacebookLead([
        'email' => $email, 'phone' => $phone,
        'event_id' => trim($_POST['event_id'] ?? ''),
        'event_source_url' => trim($_POST['event_source_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '')),
        'ip' => $ip, 'user_agent' => $userAgent,
        'fbp' => trim($_POST['fbp'] ?? ''), 'fbc' => trim($_POST['fbc'] ?? ''),
    ]);

    // Push the signup into the owner's GoHighLevel account.
    sendSignupToGHL([
        'name' => $name, 'email' => $email, 'phone' => $phone,
        'entry_date' => date('Y-m-d H:i:s'),
        'wants_ownership' => $wantsOwnership, 'source' => $signupSource,
        'utm_source' => $utmSource, 'utm_medium' => $utmMedium, 'utm_campaign' => $utmCampaign,
        'fbcampaignid' => $fbCampaignId, 'fbplacement' => $fbPlacement,
        'fbadsetid' => $fbAdsetId, 'fbadid' => $fbAdId,
        'timezone' => $timezone, 'referrer' => $referrer, 'ip' => $ip, 'user_agent' => $userAgent,
    ]);
    // (Success JSON was already sent before the side-effects above.)
} catch (PDOException $e) {
    error_log("Registration error: " . $e->getMessage());
    if (!headers_sent()) {
        echo json_encode(['success' => false, 'message' => 'An error occurred during registration. Please try again.']);
    }
}
