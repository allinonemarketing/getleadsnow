<?php
require_once 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        exit;
    }

    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT id, name, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            // Every login AFTER the first lands on the "Get Leads For Less Than
            // 1¢" page (per account). Landing there also stands in for the penny
            // promo popup this session, so they aren't pitched twice.
            $redirect = '/dashboard';
            try {
                $pdo->prepare("UPDATE users SET login_count = login_count + 1 WHERE id = ?")->execute([$user['id']]);
                $lcStmt = $pdo->prepare("SELECT login_count FROM users WHERE id = ?");
                $lcStmt->execute([$user['id']]);
                if ((int)$lcStmt->fetchColumn() > 1) {
                    $redirect = '/dashboard?section=penny';
                    $_SESSION['penny_promo_shown'] = 1;
                }
            } catch (Throwable $e) {}
            echo json_encode(['success' => true, 'message' => 'Login successful.', 'redirect' => $redirect]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        }
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    }
    exit;
}

if (isset($_GET['check_session'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'isLoggedIn' => isLoggedIn(),
        'user' => getCurrentUser()
    ]);
    exit;
}

if (isset($_GET['logout'])) {
    header('Content-Type: application/json');
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
    exit;
}

if (isLoggedIn()) {
    header('Location: /dashboard');
    exit;
}

$appName = htmlspecialchars(APP_NAME);
$appLogo = htmlspecialchars(APP_LOGO);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= $appName ?></title>
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
            width: auto;
            height: auto;
            max-width: 220px;
            max-height: 56px;
            object-fit: contain;
            margin-bottom: 12px;
        }
        .logo-header h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1d1d1f;
            letter-spacing: -0.3px;
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
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .error-msg {
            background: #fff2f2;
            color: #d70015;
            font-size: 13px;
            padding: 10px 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            display: none;
        }
        .welcome-msg {
            text-align: center;
            padding: 20px 0;
        }
        .welcome-msg h2 {
            font-size: 20px;
            font-weight: 700;
            color: #1d1d1f;
            margin-bottom: 8px;
        }
        .welcome-msg p {
            font-size: 14px;
            color: #86868b;
            margin-bottom: 20px;
        }
        .links {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            font-size: 13px;
        }
        .links a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }
        .links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo-header">
            <img src="<?= $appLogo ?>" alt="<?= $appName ?>">
            <h1><?= $appName ?></h1>
        </div>

        <?php if (isset($_GET['checkout']) && $_GET['checkout'] === 'success'): ?>
            <div style="background:#f0faf0;color:#1a7a1a;font-size:14px;padding:14px 16px;border-radius:12px;margin-bottom:20px;line-height:1.5;">
                Payment received — thank you! We've emailed you a link to set your password. Once it's set, sign in below to access your account.
                <div style="margin-top:8px;font-size:13px;">Didn't get it? Check spam, or <a href="/forgot_password" style="color:var(--accent);font-weight:600;">resend the link</a>.</div>
            </div>
        <?php elseif (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
            <div style="background:#f0faf0;color:#1a7a1a;font-size:14px;padding:14px 16px;border-radius:12px;margin-bottom:20px;line-height:1.5;">
                Your password has been set. You can now sign in below.
            </div>
        <?php endif; ?>

        <div id="welcome" style="display:none;" class="welcome-msg">
            <h2>Welcome back!</h2>
            <p id="welcomeName"></p>
            <a href="/dashboard" class="btn" style="display:inline-block;text-decoration:none;text-align:center;">Go to Dashboard</a>
        </div>

        <form id="loginForm">
            <div class="error-msg" id="errorMsg"></div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn" id="loginBtn">Login</button>
            <div class="links">
                <a href="/forgot_password">Forgot password?</a>
                <a href="./">Back to Home</a>
            </div>
        </form>
    </div>

    <script>
        const form = document.getElementById('loginForm');
        const errorMsg = document.getElementById('errorMsg');
        const welcome = document.getElementById('welcome');
        const welcomeName = document.getElementById('welcomeName');
        const loginBtn = document.getElementById('loginBtn');

        fetch('login.php?check_session')
            .then(r => r.json())
            .then(data => {
                if (data.isLoggedIn && data.user) {
                    form.style.display = 'none';
                    welcomeName.textContent = 'Logged in as ' + data.user.name;
                    welcome.style.display = 'block';
                }
            })
            .catch(() => {});

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            errorMsg.style.display = 'none';
            loginBtn.disabled = true;
            loginBtn.textContent = 'Logging in…';

            try {
                const fd = new FormData(form);
                const res = await fetch('login.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    window.location.href = data.redirect || "/dashboard";
                } else {
                    errorMsg.textContent = data.message;
                    errorMsg.style.display = 'block';
                }
            } catch {
                errorMsg.textContent = 'Something went wrong. Please try again.';
                errorMsg.style.display = 'block';
            }

            loginBtn.disabled = false;
            loginBtn.textContent = 'Login';
        });
    </script>
</body>
</html>
