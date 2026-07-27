<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'dashboard-error.log');

session_start();

if (!file_exists('includes/auth.php')) {
    die('includes/auth.php is missing');
}

require_once 'includes/auth.php';

try {
    $pdo->query('SELECT 1');
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error. Please check the logs.");
}

try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'api_calls'")->rowCount() > 0;
    
    if (!$tableExists) {
        $pdo->exec("CREATE TABLE api_calls (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            credits_used INT NOT NULL,
            scraper_model VARCHAR(50) NOT NULL,
            url VARCHAR(2048) NOT NULL,
            input_params JSON,
            status VARCHAR(50),
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_date (user_id, created_at)
        )");
        error_log("Created api_calls table successfully");
    }
} catch (PDOException $e) {
    error_log("Table creation error: " . $e->getMessage());
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'subscription_plan'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN subscription_plan VARCHAR(50) DEFAULT 'none'");
        error_log("Added subscription_plan column successfully");
    }
} catch (PDOException $e) {
    error_log("Error checking/adding subscription_plan column: " . $e->getMessage());
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'shared_for_credits'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN shared_for_credits TINYINT DEFAULT 0");
    }
} catch (PDOException $e) {
    error_log("Error checking/adding shared_for_credits column: " . $e->getMessage());
}

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

// Hard gate: no app access without an active paid plan (admins/comped plans exempt).
requireActiveSubscription('pricing.php');

$userName = $_SESSION['user_name'] ?? 'User';

try {
    $stmt = $pdo->prepare("SELECT credits, subscription_plan, shared_for_credits, is_admin FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    $userCredits = $userData['credits'] ?? 0;
    $userPlan = $userData['subscription_plan'] ?? 'none';
    $hasShared = $userData['shared_for_credits'] ?? 0;
    $isAdmin = !empty($userData['is_admin']);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $userCredits = 0;
    $userPlan = 'none';
    $hasShared = 0;
}

$current_section = isset($_GET['section']) ? $_GET['section'] : 'lead_lists';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Dashboard</title>
    <link rel="icon" type="image/jpeg" href="<?php echo APP_LOGO; ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #f5f5f7;
            --accent-primary: #c85719;
            --accent-secondary: #1460a6;
            --text-main: #1d1d1f;
            --text-muted: #86868b;
            --sidebar-bg: rgba(255, 255, 255, 0.72);
            --sidebar-border: rgba(0, 0, 0, 0.06);
            --glass-bg: rgba(255, 255, 255, 0.6);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body {
            display: flex;
            background-color: var(--bg-color);
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Inter', 'Helvetica Neue', sans-serif;
            height: 100vh;
            overflow: hidden;
        }

        .menu-toggle {
            position: fixed;
            top: 1rem;
            left: 1rem;
            font-size: 1.25rem;
            cursor: pointer;
            z-index: 1001;
            color: var(--text-main);
            display: none;
            background: var(--sidebar-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 10px;
            border-radius: 12px;
            border: 1px solid var(--sidebar-border);
        }

        .sidebar {
            width: 260px;
            height: 100vh;
            background: var(--sidebar-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid var(--sidebar-border);
            padding: 24px 16px;
            position: fixed;
            transition: transform 0.3s cubic-bezier(0.25, 0.1, 0.25, 1);
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 32px;
            padding: 0 8px;
            font-weight: 700;
            font-size: 15px;
            color: var(--text-main);
            letter-spacing: -0.02em;
        }

        .logo img {
            width: 32px;
            height: 32px;
            border-radius: 8px;
        }

        .nav-links {
            list-style: none;
            flex: 1;
            overflow-y: auto;
        }

        .nav-links::-webkit-scrollbar { width: 4px; }
        .nav-links::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 4px; }

        .nav-item {
            margin-bottom: 2px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.2s ease;
            font-size: 14px;
            font-weight: 500;
        }

        .nav-link:hover {
            background: rgba(0, 0, 0, 0.04);
            color: var(--text-main);
        }

        .nav-link.active {
            background: rgba(0, 122, 255, 0.1);
            color: var(--accent-primary);
        }

        .nav-link.active i {
            color: var(--accent-primary);
        }

        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 15px;
            color: var(--text-muted);
            transition: color 0.2s ease;
        }

        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 0;
            width: calc(100% - 260px);
            height: 100vh;
            position: relative;
            overflow: hidden;
        }

        .content-section {
            display: none;
            height: 100%;
        }

        .content-section.active {
            display: block;
            height: 100%;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .submenu {
            list-style: none;
            margin-left: 0;
            padding-left: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, opacity 0.3s ease;
            max-height: 0;
            opacity: 0;
        }

        .submenu.active {
            max-height: 500px;
            opacity: 1;
            margin-top: 2px;
            margin-bottom: 8px;
        }

        .submenu-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px 8px 42px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.2s ease;
            font-size: 13px;
            border-radius: 8px;
        }

        .submenu-link:hover {
            color: var(--text-main);
            background: rgba(0,0,0,0.03);
        }

        .submenu-link.active {
            color: var(--accent-primary);
            background: rgba(0, 122, 255, 0.06);
        }

        .submenu-link i {
             width: 16px;
             text-align: center;
        }

        .nav-link .fa-chevron-down {
            margin-left: auto;
            font-size: 0.7rem;
            transition: transform 0.3s ease;
        }

        .nav-link.expanded .fa-chevron-down {
            transform: rotate(180deg);
        }

        .plan-badge {
            position: fixed;
            bottom: 16px;
            left: 16px;
            width: 228px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid var(--sidebar-border);
            z-index: 1001;
        }

        .plan-badge-label {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .plan-badge-value {
            font-size: 15px;
            color: var(--text-main);
            font-weight: 600;
            text-transform: capitalize;
        }

        #loading {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(245, 245, 247, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }

        .spinner {
            width: 36px;
            height: 36px;
            border: 3px solid rgba(0, 0, 0, 0.08);
            border-top: 3px solid var(--accent-primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        iframe {
            width: 100%;
            height: 100%;
            border: none;
            background: var(--bg-color);
        }

        [id$="-content"] {
            height: 100%;
        }

        .credits-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            background: rgba(0, 122, 255, 0.08);
            color: var(--accent-primary);
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-left: auto;
        }

        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; width: 100%; }
            .plan-badge { display: none; }
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.12); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.2); }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['admin_original_id'])): ?>
    <div id="adminBanner" style="position:fixed;top:0;left:0;right:0;z-index:99999;background:linear-gradient(135deg,#FF3B30,#FF2D55);color:#fff;padding:8px 20px;font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:12px;font-family:'Inter',sans-serif;">
        <i class="fas fa-user-secret"></i> Viewing as <?php echo htmlspecialchars($_SESSION['user_name']); ?>
        <button onclick="returnToAdmin()" style="background:rgba(255,255,255,0.2);color:#fff;border:none;padding:5px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;">Return to Admin</button>
    </div>
    <script>
    async function returnToAdmin() {
        const res = await fetch('admin.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'return_to_admin'}) });
        const data = await res.json();
        if (data.success) window.location.href = 'admin.php';
    }
    document.body.style.paddingTop = '40px';
    </script>
    <?php endif; ?>
    <div class="plan-badge">
        <div class="plan-badge-label">Current Plan</div>
        <div class="plan-badge-value"><?php echo $userPlan === 'none' ? 'No Plan' : ucfirst($userPlan); ?></div>
    </div>

    <div class="menu-toggle">
        <i class="fas fa-bars"></i>
    </div>

    <div class="sidebar">
        <div class="logo">
            <img src="<?php echo APP_LOGO; ?>" alt="<?php echo APP_NAME; ?>">
        </div>
        <ul class="nav-links">
            <li class="nav-item">
                <a href="?section=lead_lists" class="nav-link <?php echo $current_section === 'lead_lists' ? 'active' : ''; ?>" data-section="lead_lists">
                    <i class="fas fa-folder-open"></i>
                    Lead Lists
                </a>
            </li>
            <li class="nav-item">
                <a href="?section=section5" class="nav-link <?php echo $current_section === 'section5' ? 'active' : ''; ?>" data-section="section5">
                    <i class="fas fa-credit-card"></i>
                    Plans <span class="credits-pill"><?php echo number_format($userCredits); ?></span>
                </a>
            </li>
            <?php if ($isAdmin): ?>
            <li class="nav-item">
                <a href="admin.php" class="nav-link">
                    <i class="fas fa-user-shield"></i>
                    Admin Panel
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="#" class="nav-link" id="logoutBtn">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div id="lead_lists" class="content-section <?php echo $current_section === 'lead_lists' ? 'active' : ''; ?>">
            <div id="lead_lists-content"></div>
        </div>
        <div id="section5" class="content-section <?php echo $current_section === 'section5' ? 'active' : ''; ?>">
            <div id="section5-content"></div>
        </div>
    </div>

    <div id="loading">
        <div class="spinner"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.nav-link');
            const contentSections = document.querySelectorAll('.content-section');
            const loading = document.getElementById('loading');
            const menuToggle = document.querySelector('.menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            const logoutBtn = document.getElementById('logoutBtn');
            
            const urls = {
                'lead_lists': 'leadlists.php',
                'section5': 'pricing.php'
            };

            const currentSection = '<?php echo $current_section; ?>';
            loadContent(currentSection);

            navLinks.forEach(link => {
                if (!link.id || link.id !== 'logoutBtn') {
                    link.addEventListener('click', function(e) {
                        const sectionId = this.getAttribute('data-section');
                        if (!sectionId) return;
                        e.preventDefault();
                        
                        navLinks.forEach(l => l.classList.remove('active'));
                        contentSections.forEach(s => s.classList.remove('active'));
                        
                        this.classList.add('active');
                        document.getElementById(sectionId).classList.add('active');
                        
                        window.history.pushState({}, '', `?section=${sectionId}`);
                        
                        loadContent(sectionId);
                    });
                }
            });

            logoutBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                try {
                    const response = await fetch('logout.php');
                    const data = await response.json();
                    if (data.success) {
                        window.location.href = 'login.php';
                    }
                } catch (error) {
                    console.error('Logout error:', error);
                }
            });

            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
            });

            async function loadContent(sectionId) {
                try {
                    loading.style.display = 'flex';
                    const contentDiv = document.getElementById(`${sectionId}-content`);
                    if (contentDiv) {
                        contentDiv.innerHTML = `<iframe src="${urls[sectionId]}"></iframe>`;
                    }
                } catch (error) {
                    console.error('Error loading content:', error);
                    if (document.getElementById(`${sectionId}-content`)) {
                        document.getElementById(`${sectionId}-content`).innerHTML = 'Error loading content';
                    }
                } finally {
                    loading.style.display = 'none';
                }
            }

            window.addEventListener('popstate', function() {
                const params = new URLSearchParams(window.location.search);
                const section = params.get('section') || 'lead_lists';
                loadContent(section);
            });

            window.addEventListener('pageshow', function() {
                if (loading) loading.style.display = 'none';
            });

            setTimeout(() => {
                if (loading && loading.style.display !== 'none') {
                    console.warn('Loading spinner timed out, forcing hide');
                    loading.style.display = 'none';
                }
            }, 3000);

            const urlParams = new URLSearchParams(window.location.search);
            const paymentSuccess = urlParams.get('payment_success');
            const credits = urlParams.get('credits');

            if (paymentSuccess === 'true' && credits) {
                function createConfetti() {
                    const confetti = document.createElement('div');
                    confetti.style.width = '10px';
                    confetti.style.height = '10px';
                    confetti.style.background = `hsl(${Math.random() * 360}, 100%, 50%)`;
                    confetti.style.position = 'fixed';
                    confetti.style.top = '-10px';
                    confetti.style.left = `${Math.random() * 100}vw`;
                    confetti.style.borderRadius = '50%';
                    confetti.style.zIndex = '1000';
                    confetti.style.boxShadow = `0 0 5px ${confetti.style.background}`;
                    document.body.appendChild(confetti);

                    const animation = confetti.animate([
                        { transform: 'translateY(0) rotate(0deg)', opacity: 1 },
                        { transform: `translateY(100vh) rotate(${Math.random() * 360}deg)`, opacity: 0 }
                    ], {
                        duration: Math.random() * 3000 + 2000,
                        easing: 'cubic-bezier(0,0,0.2,1)'
                    });

                    animation.onfinish = () => confetti.remove();
                }

                for (let i = 0; i < 100; i++) {
                    setTimeout(createConfetti, i * 20);
                }
                
                let confettiCount = 0;
                const confettiInterval = setInterval(() => {
                    createConfetti();
                    confettiCount++;
                    if (confettiCount > 50) {
                        clearInterval(confettiInterval);
                    }
                }, 100);

                const successMessage = document.createElement('div');
                successMessage.style.position = 'fixed';
                successMessage.style.top = '24px';
                successMessage.style.left = '50%';
                successMessage.style.transform = 'translateX(-50%)';
                successMessage.style.background = 'rgba(255, 255, 255, 0.9)';
                successMessage.style.backdropFilter = 'blur(20px)';
                successMessage.style.webkitBackdropFilter = 'blur(20px)';
                successMessage.style.color = '#1d1d1f';
                successMessage.style.padding = '20px 32px';
                successMessage.style.borderRadius = '16px';
                successMessage.style.boxShadow = '0 8px 32px rgba(0, 0, 0, 0.12)';
                successMessage.style.border = '1px solid rgba(0, 0, 0, 0.06)';
                successMessage.style.zIndex = '1000';
                successMessage.style.textAlign = 'center';
                successMessage.innerHTML = `
                    <h3 style="margin-bottom: 8px; font-size: 18px; font-weight: 700;">Payment Successful!</h3>
                    <p style="color: #86868b; font-size: 15px;">${new Intl.NumberFormat().format(credits)} credits have been added to your account.</p>
                `;
                document.body.appendChild(successMessage);

                setTimeout(() => {
                    successMessage.style.opacity = '0';
                    successMessage.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => successMessage.remove(), 500);
                }, 5000);

                window.history.replaceState({}, '', '?section=lead_lists');
            }

            function updateCredits() {
                fetch('pricing.php?action=get_credits')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const credits = parseInt(data.credits);
                            if (!isNaN(credits)) {
                                document.querySelectorAll('.credits-pill').forEach(el => {
                                    el.textContent = new Intl.NumberFormat().format(credits);
                                });
                                if (credits === 0 && window.location.search.indexOf('section=section5') === -1) {
                                    window.location.href = '?section=section5';
                                }
                            }
                        }
                    })
                    .catch(error => console.error('Error updating credits:', error));
            }

            window.addEventListener('message', function(event) {
                if (event.data.type === 'creditUpdate') {
                    const credits = parseInt(event.data.credits);
                    if (!isNaN(credits)) {
                        document.querySelectorAll('.credits-pill').forEach(el => {
                            el.textContent = new Intl.NumberFormat().format(credits);
                        });
                        if (credits === 0 && window.location.search.indexOf('section=section5') === -1) {
                            window.location.href = '?section=section5';
                        }
                    }
                }
            });

            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'visible') updateCredits();
            });

            document.querySelectorAll('.nav-link').forEach(link => {
                if (link.querySelector('.fa-chevron-down')) {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        const submenu = this.nextElementSibling;
                        const chevron = this.querySelector('.fa-chevron-down');
                        
                        this.classList.toggle('expanded');
                        submenu.classList.toggle('active');
                    });
                }
            });

            document.querySelectorAll('.submenu-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const sectionId = this.getAttribute('href').replace('?section=', '');
                    
                    document.querySelectorAll('.submenu-link').forEach(l => l.classList.remove('active'));
                    document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
                    
                    this.classList.add('active');
                    document.getElementById(sectionId).classList.add('active');
                    
                    loadContent(sectionId);
                    
                    window.history.pushState({}, '', `?section=${sectionId}`);

                    if (window.innerWidth <= 768) {
                        sidebar.classList.remove('active');
                    }
                });
            });
        });
    </script>
</body>
</html>
