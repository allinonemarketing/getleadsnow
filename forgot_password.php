<?php
require_once 'includes/auth.php';
require_once 'includes/email_service.php';

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;
    $email = trim($_POST['email'] ?? '');

    if (!empty($email)) {
        try {
            global $pdo;
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
                $stmt->execute([$email, $token]);
                sendPasswordResetEmail($user['email'], $user['name'], $token);
            }
        } catch (Exception $e) {
            error_log("Forgot password error: " . $e->getMessage());
        }
    }
}

$appName = htmlspecialchars(APP_NAME);
$appLogo = htmlspecialchars(APP_LOGO);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — <?= $appName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #c85719;
            --bg: #f5f5f7;
            --card-bg: #ffffff;
            --card-border: rgba(0,0,0,0.06);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 48px 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.04);
        }
        .logo-header {
            text-align: center;
            margin-bottom: 32px;
        }
        .logo-header img {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            object-fit: cover;
            margin-bottom: 12px;
        }
        .logo-header h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1d1d1f;
            letter-spacing: -0.3px;
        }
        .logo-header p {
            font-size: 14px;
            color: #86868b;
            margin-top: 6px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1d1d1f;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d2d2d7;
            border-radius: 12px;
            font-size: 15px;
            font-family: inherit;
            background: #fafafa;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }
        .form-group input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(200,87,25,0.12);
            background: #fff;
        }
        .btn {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 12px;
            background: var(--accent);
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: opacity 0.2s;
            margin-top: 6px;
        }
        .btn:hover { opacity: 0.88; }
        .success-msg {
            background: #f0faf0;
            color: #1a7a1a;
            font-size: 14px;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo-header">
            <img src="<?= $appLogo ?>" alt="<?= $appName ?>">
            <h1><?= $appName ?></h1>
            <p>Reset your password</p>
        </div>

        <?php if ($submitted): ?>
            <div class="success-msg">
                If an account exists with that email, we've sent a password reset link. Please check your inbox.
            </div>
            <a href="/login" class="btn" style="display:block;text-align:center;text-decoration:none;">Back to Login</a>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required autocomplete="email" placeholder="you@example.com">
                </div>
                <button type="submit" class="btn">Send Reset Link</button>
            </form>
            <a href="/login" class="back-link">Back to Login</a>
        <?php endif; ?>
    </div>
</body>
</html>
