<?php
require_once 'includes/auth.php';

$error = '';
$success = '';
$validToken = false;
$token = $_GET['token'] ?? $_POST['token'] ?? '';

if (empty($token)) {
    $error = 'No reset token provided.';
} else {
    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW() AND used = 0");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $error = 'This reset link is invalid or has expired.';
        } else {
            $validToken = true;
        }
    } catch (PDOException $e) {
        error_log("Reset password error: " . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
        $validToken = true;
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
        $validToken = true;
    } else {
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt->execute([$hashedPassword, $reset['email']]);

            $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
            $stmt->execute([$token]);

            header('Location: login.php?reset=success');
            exit;
        } catch (PDOException $e) {
            error_log("Reset password update error: " . $e->getMessage());
            $error = 'An error occurred. Please try again.';
            $validToken = true;
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
    <title>Reset Password — <?= $appName ?></title>
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
        .error-msg {
            background: #fff2f2;
            color: #d70015;
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
            <p>Set New Password</p>
        </div>

        <?php if (!empty($error) && !$validToken): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
            <a href="/forgot_password" class="btn" style="display:block;text-align:center;text-decoration:none;">Request a New Reset Link</a>
        <?php elseif ($validToken): ?>
            <?php if (!empty($error)): ?>
                <div class="error-msg"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" required minlength="6" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6" autocomplete="new-password">
                </div>
                <button type="submit" class="btn">Reset Password</button>
            </form>
            <a href="/login" class="back-link">Back to Login</a>
        <?php endif; ?>
    </div>
</body>
</html>
