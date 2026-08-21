<?php
// --- Clean-URL front controller ---------------------------------------------
// Cloudways' Nginx routes extensionless URLs (e.g. /login, /dashboard) to this
// homepage file instead of the matching .php script. Map the path to its real
// .php file so those URLs work without the extension. Only bare top-level names
// (letters, digits, _ and -) are allowed — no slashes or dots — so there's no
// path traversal, and "index" is excluded to avoid recursing into this file.
$__cleanPath = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
if ($__cleanPath !== '' && $__cleanPath !== 'index' && preg_match('/^[A-Za-z0-9_\-]+$/', $__cleanPath)) {
    $__routeFile = __DIR__ . '/' . $__cleanPath . '.php';
    if (is_file($__routeFile)) {
        require $__routeFile;
        exit;
    }
}
// ----------------------------------------------------------------------------

session_start();
require_once 'includes/auth.php';
require_once 'config/stripe_config.php';
require_once 'config/subscription_config.php';
// This page only reads the session (which still works after close). Release the
// lock so the Stripe-return retrieve and the polled get_credits branch don't hold it.
session_write_close();

if (isset($_GET['action']) && $_GET['action'] === 'get_credits' && isLoggedIn()) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $credits = $stmt->fetch(PDO::FETCH_ASSOC)['credits'] ?? 0;
        echo json_encode(['success' => true, 'credits' => $credits]);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

$userName = $_SESSION['user_name'] ?? 'User';
$userCredits = 0;
$userPlan = 'none';

if (isLoggedIn()) {
    try {
        $stmt = $pdo->prepare("SELECT credits, subscription_plan FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        $userCredits = $userData['credits'] ?? 0;
        $userPlan = $userData['subscription_plan'] ?? 'none';
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
    }
}

if (isset($_GET['success']) && $_GET['success'] === 'true' && isset($_GET['session_id'])) {
    try {
        $session = \Stripe\Checkout\Session::retrieve($_GET['session_id']);
        
        $stmt = $pdo->prepare("SELECT id FROM credit_transactions WHERE transaction_id = ?");
        $stmt->execute([$session->payment_intent]);
        $existingTransaction = $stmt->fetch();
        
        if (!$existingTransaction && isset($_SESSION['user_id']) && $session->metadata->user_id == $_SESSION['user_id']) {
            $pdo->beginTransaction();
            
            try {
                $stmt = $pdo->prepare("UPDATE users SET credits = credits + ? WHERE id = ?");
                $stmt->execute([
                    $session->metadata->credits,
                    $session->metadata->user_id
                ]);
                
                $stmt = $pdo->prepare("INSERT INTO credit_transactions (user_id, credits, amount, transaction_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $session->metadata->user_id,
                    $session->metadata->credits,
                    $session->amount_total / 100,
                    $session->payment_intent
                ]);
                
                $pdo->commit();
                
                header('Location: ' . APP_URL . '/dashboard?section=section5&payment_success=true&credits=' . $session->metadata->credits);
                exit();
                
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Transaction failed: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("Error processing success payment: " . $e->getMessage());
    }
}

// Logged-in users go straight to the dashboard — the marketing homepage is for
// visitors. Placed AFTER the Stripe checkout-return block above so payment
// credits are still granted first (that path issues its own redirect on success).
if (isLoggedIn()) {
    header('Location: /dashboard');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(APP_NAME); ?> | Automated B2B Lead Generation</title>
    <link rel="icon" type="image/jpeg" href="<?php echo htmlspecialchars(APP_LOGO); ?>">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://js.stripe.com/v3/"></script>
    <script>const stripe = '<?php echo htmlspecialchars($stripePublicKey); ?>' ? Stripe('<?php echo htmlspecialchars($stripePublicKey); ?>') : null;</script>

    <!-- Meta Pixel -->
    <script>
      !function(f,b,e,v,n,t,s)
      {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};
      if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
      n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;s=b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t,s)}(window, document,'script',
      'https://connect.facebook.net/en_US/fbevents.js');
      fbq('init', '1131224344235309');
      fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
      src="https://www.facebook.com/tr?id=1131224344235309&ev=PageView&noscript=1"/></noscript>
    <!-- End Meta Pixel -->
    <style>
        :root {
            --accent: #c85719;
            --accent-light: #fce8dc;
            --green: #34C759;
            --purple: #337f83;
            --text-primary: #1d1d1f;
            --text-secondary: #6e6e73;
            --text-tertiary: #aeaeb2;
            --bg: #ffffff;
            --bg-secondary: #f5f5f7;
            --card-border: rgba(0,0,0,0.06);
            --card-bg: rgba(255,255,255,0.8);
        }
        * { margin:0; padding:0; box-sizing:border-box; -webkit-font-smoothing:antialiased; }
        body { background:var(--bg); color:var(--text-primary); font-family:'Inter',system-ui,-apple-system,sans-serif; overflow-x:hidden; }
        .container { max-width:1120px; margin:0 auto; padding:0 24px; }
        section { padding:100px 0; }
        h1,h2,h3 { font-weight:700; line-height:1.15; letter-spacing:-0.02em; }
        h1 { font-size:clamp(2.5rem,5.5vw,4.2rem); }
        h2 { font-size:clamp(1.8rem,3.5vw,2.8rem); }
        p.sub { font-size:1.15rem; line-height:1.7; color:var(--text-secondary); max-width:580px; }

        /* Header */
        header { position:fixed; top:0; width:100%; padding:18px 0; z-index:100; background:rgba(255,255,255,0.85); backdrop-filter:blur(20px); border-bottom:1px solid var(--card-border); }
        nav { display:flex; justify-content:space-between; align-items:center; }
        .logo { font-weight:800; font-size:1.2rem; display:flex; align-items:center; gap:8px; color:var(--text-primary); text-decoration:none; }
        .logo img { height:28px; border-radius:6px; }
        .nav-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 20px; border-radius:980px; font-weight:600; font-size:14px; text-decoration:none; cursor:pointer; border:none; font-family:inherit; transition:all 0.2s; }
        .nav-btn-primary { background:var(--accent); color:#fff; }
        .nav-btn-primary:hover { background:#0066DD; }
        .nav-btn-ghost { background:transparent; color:var(--text-primary); }
        .nav-btn-ghost:hover { background:var(--bg-secondary); }

        /* Hero */
        .hero { padding:160px 0 100px; text-align:center; background:linear-gradient(180deg, var(--bg) 0%, var(--bg-secondary) 100%); }
        .hero h1 { color:var(--text-primary); margin-bottom:20px; }
        .hero h1 span { color:var(--accent); }
        .hero p.sub { margin:0 auto 36px; }
        .hero-actions { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; }
        .hero-btn { display:inline-flex; align-items:center; gap:8px; padding:14px 32px; border-radius:14px; font-weight:600; font-size:16px; text-decoration:none; cursor:pointer; border:none; font-family:inherit; transition:all 0.2s; }
        .hero-btn-primary { background:var(--accent); color:#fff; box-shadow:0 4px 14px rgba(200,87,25,0.3); }
        .hero-btn-primary:hover { background:#0066DD; transform:translateY(-1px); box-shadow:0 6px 20px rgba(200,87,25,0.35); }
        .hero-btn-secondary { background:var(--bg); color:var(--text-primary); border:1px solid var(--card-border); }
        .hero-btn-secondary:hover { background:var(--bg-secondary); }
        .hero-stats { display:flex; gap:48px; justify-content:center; margin-top:56px; }
        .hero-stat { text-align:center; }
        .hero-stat .num { font-size:2.2rem; font-weight:800; color:var(--text-primary); }
        .hero-stat .label { font-size:13px; color:var(--text-tertiary); margin-top:4px; }

        /* Features */
        .features-section { background:var(--bg); }
        .features-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:20px; margin-top:48px; }
        .feature-card { background:var(--card-bg); border:1px solid var(--card-border); border-radius:20px; padding:36px; transition:transform 0.3s, box-shadow 0.3s; }
        .feature-card:hover { transform:translateY(-4px); box-shadow:0 12px 40px rgba(0,0,0,0.06); }
        .feature-icon { width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:22px; margin-bottom:20px; }
        .feature-card h3 { font-size:1.25rem; margin-bottom:10px; }
        .feature-card p { font-size:14px; line-height:1.6; color:var(--text-secondary); }

        /* Demo */
        .demo-section { background:var(--bg-secondary); }
        .demo-table { background:var(--bg); border:1px solid var(--card-border); border-radius:16px; overflow:hidden; box-shadow:0 8px 30px rgba(0,0,0,0.04); max-width:900px; margin:40px auto 0; }
        .demo-row { display:grid; grid-template-columns:2fr 1.2fr 1.2fr 1.5fr; padding:14px 24px; border-bottom:1px solid var(--card-border); align-items:center; font-size:14px; }
        .demo-head { font-weight:600; font-size:12px; color:var(--text-tertiary); text-transform:uppercase; letter-spacing:0.04em; background:var(--bg-secondary); }
        .verified { color:var(--green); display:flex; align-items:center; gap:6px; font-weight:500; }
        .blur-row { filter:blur(5px); opacity:0.4; user-select:none; }

        /* Pricing */
        .pricing-section { background:var(--bg); }
        .pricing-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:18px; margin-top:48px; }
        @media (max-width:1000px){ .pricing-grid { grid-template-columns:repeat(2,1fr); } }
        @media (max-width:560px){ .pricing-grid { grid-template-columns:1fr; } }
        .pricing-card { background:var(--card-bg); border:1px solid var(--card-border); border-radius:20px; padding:40px 32px; text-align:center; position:relative; transition:transform 0.3s, box-shadow 0.3s; }
        .pricing-card:hover { transform:translateY(-4px); box-shadow:0 12px 40px rgba(0,0,0,0.06); }
        .pricing-card.featured { border-color:var(--accent); box-shadow:0 8px 30px rgba(200,87,25,0.1); }
        .pricing-card.featured::before { content:'Most Popular'; position:absolute; top:-12px; left:50%; transform:translateX(-50%); background:var(--accent); color:#fff; font-size:11px; font-weight:700; padding:4px 14px; border-radius:980px; }
        .pricing-card h3 { font-size:1.15rem; color:var(--text-secondary); margin-bottom:8px; font-weight:600; }
        .price { font-size:3.2rem; font-weight:800; margin:8px 0 20px; color:var(--text-primary); }
        .price span { font-size:1rem; font-weight:400; color:var(--text-tertiary); }
        .features-list { list-style:none; text-align:left; margin:24px 0; }
        .features-list li { padding:8px 0; color:var(--text-secondary); font-size:14px; display:flex; align-items:center; gap:10px; }
        .features-list i { color:var(--accent); font-size:13px; }
        .pricing-btn { display:block; width:100%; padding:14px; border-radius:12px; font-weight:600; font-size:15px; cursor:pointer; border:none; font-family:inherit; transition:all 0.2s; }
        .pricing-btn-primary { background:var(--accent); color:#fff; }
        .pricing-btn-primary:hover { background:#0066DD; }
        .pricing-btn-secondary { background:var(--bg-secondary); color:var(--text-primary); }
        .pricing-btn-secondary:hover { background:#e8e8ed; }

        /* CTA */
        .cta-section { background:var(--bg-secondary); text-align:center; }
        .cta-card { background:var(--bg); border:1px solid var(--card-border); border-radius:24px; padding:64px 48px; max-width:700px; margin:0 auto; box-shadow:0 8px 30px rgba(0,0,0,0.04); }
        .cta-form { display:flex; justify-content:center; gap:8px; max-width:420px; margin:28px auto 0; }
        .cta-form input { flex:1; padding:14px 16px; border:1px solid var(--card-border); border-radius:12px; font-size:15px; font-family:inherit; outline:none; background:var(--bg-secondary); }
        .cta-form input:focus { border-color:var(--accent); }
        .cta-form button { padding:14px 24px; border-radius:12px; background:var(--accent); color:#fff; font-weight:600; font-size:15px; border:none; cursor:pointer; font-family:inherit; white-space:nowrap; }

        /* Footer */
        footer { border-top:1px solid var(--card-border); padding:48px 0; background:var(--bg); }
        .footer-inner { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px; }
        .footer-links a { color:var(--text-tertiary); text-decoration:none; font-size:14px; margin-left:24px; }
        .footer-links a:hover { color:var(--text-primary); }

        /* Modal */
        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); backdrop-filter:blur(8px); z-index:1000; }
        .modal-content { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:var(--bg); border:1px solid var(--card-border); padding:40px; border-radius:20px; width:420px; max-width:92%; box-shadow:0 20px 60px rgba(0,0,0,0.12); }
        .modal-content h2 { margin-bottom:20px; text-align:center; font-size:1.4rem; }
        .form-group { margin-bottom:16px; }
        .form-group label { display:block; margin-bottom:6px; color:var(--text-secondary); font-size:13px; font-weight:500; }
        .form-group input { width:100%; padding:12px 14px; background:var(--bg-secondary); border:1px solid var(--card-border); border-radius:10px; font-size:15px; font-family:inherit; outline:none; color:var(--text-primary); }
        .form-group input:focus { border-color:var(--accent); }
        .close { position:absolute; top:16px; right:20px; font-size:22px; cursor:pointer; color:var(--text-tertiary); transition:color 0.2s; background:none; border:none; }
        .close:hover { color:var(--text-primary); }
        .modal .nav-btn-primary { width:100%; justify-content:center; padding:13px; font-size:15px; border-radius:12px; }
        .loading-spinner { display:inline-block; width:18px; height:18px; border:2px solid rgba(255,255,255,0.3); border-radius:50%; border-top-color:#fff; animation:spin 0.8s linear infinite; margin-right:8px; }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* Responsive */
        @media (max-width:768px) {
            .demo-row { grid-template-columns:1fr 1fr; }
            .demo-row > *:nth-child(n+3) { display:none; }
            .hero-stats { gap:24px; }
            .cta-form { flex-direction:column; }
            .footer-inner { flex-direction:column; text-align:center; }
            .footer-links a { margin:0 12px; }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <nav>
                <a href="/" class="logo"><img src="<?php echo htmlspecialchars(APP_LOGO); ?>" alt="<?php echo htmlspecialchars(APP_NAME); ?>"></a>
                <div style="display:flex;align-items:center;gap:8px;">
                    <?php if (isLoggedIn()): ?>
                        <span class="nav-btn nav-btn-ghost">Hi, <?php echo htmlspecialchars($userName); ?></span>
                        <a href="/dashboard" class="nav-btn nav-btn-primary">Dashboard</a>
                    <?php else: ?>
                        <a href="#" onclick="openAuthModal(); if (authMode === 'signup') toggleAuthMode(); return false;" class="nav-btn nav-btn-ghost">Sign In</a>
                        <a href="#" onclick="openSignup(); return false;" class="nav-btn nav-btn-primary">Get Started</a>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
    </header>

    <section class="hero">
        <div class="container">
            <h1>Need Targeted Business Leads?<br><span>Search Google Maps in Seconds.</span></h1>
            <p class="sub">Enter any keyword and city — get business names, phone numbers, emails, and social profiles instantly. Build lead lists, track outreach, and close deals faster.</p>
            <div class="hero-actions">
                <?php if (isLoggedIn()): ?>
                    <a href="/dashboard" class="hero-btn hero-btn-primary"><i class="fas fa-rocket"></i> Go to Dashboard</a>
                <?php else: ?>
                    <a href="#" onclick="openSignup(); return false;" class="hero-btn hero-btn-primary"><i class="fas fa-rocket"></i> Start Free</a>
                <?php endif; ?>
            </div>
            <div class="hero-stats">
                <div class="hero-stat"><div class="num">12M+</div><div class="label">Leads Generated</div></div>
                <div class="hero-stat"><div class="num">98%</div><div class="label">Email Accuracy</div></div>
                <div class="hero-stat"><div class="num">50K+</div><div class="label">Cities Available</div></div>
            </div>
        </div>
    </section>

    <section class="features-section" id="features">
        <div class="container">
            <div style="text-align:center;">
                <h2>How It Works</h2>
                <p class="sub" style="margin:12px auto 0;">Three simple steps to a full pipeline of qualified leads.</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon" style="background:var(--accent-light);color:var(--accent);"><i class="fas fa-search"></i></div>
                    <h3>Search Any Business Type</h3>
                    <p>Type "dentists", "roofing contractors", or any keyword. Select your cities and states. Hit search.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon" style="background:#F0E6FA;color:var(--purple);"><i class="fas fa-magic"></i></div>
                    <h3>AI Enriches Every Lead</h3>
                    <p>We automatically visit each business website to find emails, social media profiles, and contact details.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon" style="background:#E5F8ED;color:var(--green);"><i class="fas fa-file-export"></i></div>
                    <h3>Export & Start Outreach</h3>
                    <p>Download your leads as CSV, import to your CRM, or share a public link with your team. Ready to go.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="demo-section" id="demo">
        <div class="container">
            <div style="text-align:center;">
                <h2>Real Data. Real Results.</h2>
                <p class="sub" style="margin:12px auto 0;">Here's a preview of what a search looks like — clean, organized, and ready to use.</p>
            </div>
            <div class="demo-table">
                <div class="demo-row demo-head">
                    <div>Business</div>
                    <div>Location</div>
                    <div>Email</div>
                    <div>Socials</div>
                </div>
                <div class="demo-row">
                    <div><strong>Apex Dental</strong><br><span style="color:var(--text-tertiary);font-size:12px;">Dentist · 4.8★</span></div>
                    <div>Austin, TX</div>
                    <div class="verified"><i class="fas fa-circle-check"></i> Verified</div>
                    <div style="display:flex;gap:8px;color:var(--text-tertiary);"><i class="fa-brands fa-facebook"></i> <i class="fa-brands fa-instagram"></i></div>
                </div>
                <div class="demo-row">
                    <div><strong>Summit Roofing Co</strong><br><span style="color:var(--text-tertiary);font-size:12px;">Roofing · 4.9★</span></div>
                    <div>Denver, CO</div>
                    <div class="verified"><i class="fas fa-circle-check"></i> Verified</div>
                    <div style="display:flex;gap:8px;color:var(--text-tertiary);"><i class="fa-brands fa-linkedin"></i> <i class="fa-brands fa-youtube"></i></div>
                </div>
                <div class="demo-row">
                    <div><strong>Bright Smile Family</strong><br><span style="color:var(--text-tertiary);font-size:12px;">Dentist · 4.7★</span></div>
                    <div>Miami, FL</div>
                    <div class="verified"><i class="fas fa-circle-check"></i> Verified</div>
                    <div style="display:flex;gap:8px;color:var(--text-tertiary);"><i class="fa-brands fa-instagram"></i> <i class="fa-brands fa-tiktok"></i></div>
                </div>
                <div class="demo-row blur-row">
                    <div>Premier Chiropractic</div>
                    <div>Phoenix, AZ</div>
                    <div>hello@premier...</div>
                    <div>Facebook, Yelp</div>
                </div>
                <div class="demo-row blur-row">
                    <div>Elite Plumbing Services</div>
                    <div>Dallas, TX</div>
                    <div>info@eliteplum...</div>
                    <div>Instagram</div>
                </div>
            </div>
            <div style="text-align:center;margin-top:32px;">
                <?php if (isLoggedIn()): ?>
                    <a href="/dashboard" class="hero-btn hero-btn-primary">Open Dashboard</a>
                <?php else: ?>
                    <a href="#" onclick="openSignup(); return false;" class="hero-btn hero-btn-primary">Start Free</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="pricing-section" id="pricing">
        <div class="container">
            <div style="text-align:center;max-width:600px;margin:0 auto;">
                <h2>Start Free — 100 Leads on Us</h2>
                <p class="sub" style="margin:14px auto 28px;">Create your free account and pull your first 100 leads right away. No credit card required — upgrade only when you need more.</p>
                <?php if (isLoggedIn()): ?>
                    <a href="/dashboard" class="hero-btn hero-btn-primary" style="text-decoration:none;"><i class="fas fa-rocket"></i> Go to Dashboard</a>
                <?php else: ?>
                    <a href="#" onclick="openSignup(); return false;" class="hero-btn hero-btn-primary" style="text-decoration:none;"><i class="fas fa-rocket"></i> Get Started — 100 Free Leads</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="container">
            <div class="cta-card">
                <h2>Ready to Fill Your Pipeline?</h2>
                <p class="sub" style="margin:12px auto 0;">Sign up and find your first leads.</p>
                <form class="cta-form" onsubmit="event.preventDefault(); openSignup();">
                    <button type="submit">Start Free</button>
                </form>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="footer-inner">
                <div style="font-size:13px;color:var(--text-tertiary);">&copy; 2026 <?php echo htmlspecialchars(APP_NAME); ?>. All rights reserved.</div>
                <div class="footer-links">
                    <a href="#features">Features</a>
                    <a href="#pricing">Get Started</a>
                    <a href="#demo">Demo</a>
                </div>
            </div>
        </div>
    </footer>

    <div id="authModal" class="modal">
        <div class="modal-content">
            <button class="close" onclick="closeModal()">&times;</button>
            <div id="authFormWrap">
                <h2 id="authTitle">Create Your Account</h2>
                <p id="authSubtitle" style="text-align:center;font-size:13px;color:var(--text-secondary);margin-bottom:20px;">Create an account or sign in to start finding leads instantly.</p>
                <div id="authError" style="display:none;background:#FEE2E2;color:#DC2626;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:16px;text-align:center;"></div>
                <form onsubmit="handleAuth(event)">
                    <div class="form-group" id="nameGroup">
                        <label>Full Name</label>
                        <input type="text" id="authName" placeholder="John Doe">
                    </div>
                    <div class="form-group" id="phoneGroup">
                        <label>Phone Number</label>
                        <input type="tel" id="authPhone" placeholder="(555) 123-4567">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="authEmail" required placeholder="you@company.com">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" id="authPassword" required placeholder="Create a password" minlength="6">
                    </div>
                    <div class="form-group" id="ownershipGroup">
                        <label style="line-height:1.45;">Do you want to own this software, get leads even cheaper, and be able to sell it yourself to make money?</label>
                        <div style="display:flex;gap:20px;margin-top:10px;">
                            <label style="display:flex;align-items:center;gap:7px;font-weight:400;cursor:pointer;font-size:14px;">
                                <input type="radio" name="wantsOwnership" value="yes" style="accent-color:var(--accent);"> Yes
                            </label>
                            <label style="display:flex;align-items:center;gap:7px;font-weight:400;cursor:pointer;font-size:14px;">
                                <input type="radio" name="wantsOwnership" value="no" style="accent-color:var(--accent);"> No
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="nav-btn nav-btn-primary" id="authSubmitBtn" style="width:100%;justify-content:center;padding:13px;font-size:15px;border-radius:12px;">
                        Create Account
                    </button>
                </form>
                <div style="text-align:center;margin-top:16px;">
                    <a href="#" id="authToggle" onclick="handleAuthToggle(); return false;" style="font-size:13px;color:var(--accent);text-decoration:none;font-weight:500;">Already have an account? Sign in</a>
                </div>
                <div id="authForgot" style="text-align:center;margin-top:10px;display:none;">
                    <a href="/forgot_password" style="font-size:13px;color:var(--text-tertiary);text-decoration:underline;font-weight:500;">Forgot password?</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('a[href^="#"]').forEach(a => {
            a.addEventListener('click', e => {
                const href = a.getAttribute('href');
                if (href === '#') return;
                const target = document.querySelector(href);
                if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); }
            });
        });

        let selectedPlanId = null;
        let authMode = 'signup';
        const isLoggedIn = <?php echo json_encode(isLoggedIn()); ?>;

        // Capture attribution once on landing: UTM/Facebook params (persisted so
        // they survive navigation), plus browser timezone and referrer.
        const signupTracking = (function () {
            const p = new URLSearchParams(location.search);
            const keys = ['utm_source', 'utm_medium', 'utm_campaign', 'fbcampaignid', 'fbplacement', 'fbadsetid', 'fbadid'];
            const t = {};
            let hasNew = false;
            keys.forEach(k => { const v = p.get(k); if (v) { t[k] = v; hasNew = true; } });
            try {
                if (hasNew) { localStorage.setItem('signupTracking', JSON.stringify(t)); }
                else { const s = localStorage.getItem('signupTracking'); if (s) { Object.assign(t, JSON.parse(s)); } }
            } catch (e) {}
            keys.forEach(k => { if (!t[k]) t[k] = ''; });
            try { t.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { t.timezone = ''; }
            t.referrer = document.referrer || '';
            return t;
        })();

        // Read a cookie value (for Meta _fbp / _fbc match keys).
        function getCookie(name) {
            const m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
            return m ? m.pop() : '';
        }

        function openAuthModal(email = '') {
            document.getElementById('authModal').style.display = 'block';
            document.getElementById('authError').style.display = 'none';
            if (email) document.getElementById('authEmail').value = email;
        }
        function closeModal() {
            document.getElementById('authModal').style.display = 'none';
            selectedPlanId = null;
        }
        function handleAuthToggle() {
            // In login mode the link is "Don't have an account? Sign up" — send them
            // to pricing to pick a plan (no free accounts). In signup mode it toggles
            // to the sign-in form as before.
            if (authMode === 'login') {
                closeModal();
                const target = document.getElementById('pricing');
                if (target) target.scrollIntoView({ behavior: 'smooth' });
            } else {
                toggleAuthMode();
            }
        }
        function toggleAuthMode() {
            authMode = authMode === 'signup' ? 'login' : 'signup';
            const nameGroup = document.getElementById('nameGroup');
            const phoneGroup = document.getElementById('phoneGroup');
            const ownershipGroup = document.getElementById('ownershipGroup');
            const title = document.getElementById('authTitle');
            const subtitle = document.getElementById('authSubtitle');
            const btn = document.getElementById('authSubmitBtn');
            const toggle = document.getElementById('authToggle');
            if (authMode === 'login') {
                nameGroup.style.display = 'none';
                phoneGroup.style.display = 'none';
                ownershipGroup.style.display = 'none';
                title.textContent = 'Welcome Back';
                subtitle.textContent = 'Sign in to access your leads and lists.';
                btn.textContent = 'Sign In';
                toggle.textContent = "Don't have an account? Sign up";
                document.getElementById('authForgot').style.display = 'block';
            } else {
                nameGroup.style.display = 'block';
                phoneGroup.style.display = 'block';
                ownershipGroup.style.display = 'block';
                title.textContent = 'Create Your Account';
                subtitle.textContent = 'Create an account or sign in to start finding leads instantly.';
                btn.textContent = 'Create Account';
                toggle.textContent = 'Already have an account? Sign in';
                document.getElementById('authForgot').style.display = 'none';
            }
            document.getElementById('authError').style.display = 'none';
        }

        function handleAuth(e) {
            e.preventDefault();
            const email = document.getElementById('authEmail').value;
            const password = document.getElementById('authPassword').value;
            const btn = document.getElementById('authSubmitBtn');
            const errEl = document.getElementById('authError');
            errEl.style.display = 'none';

            btn.innerHTML = '<span class="loading-spinner"></span>' + (authMode === 'login' ? 'Signing in...' : 'Creating account...');
            btn.disabled = true;

            if (authMode === 'login') {
                const fd = new FormData();
                fd.append('email', email);
                fd.append('password', password);
                fetch('login.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        if (selectedPlanId) proceedToCheckout(selectedPlanId);
                        else window.location.href = data.redirect || "/dashboard";
                    } else {
                        errEl.textContent = data.message || 'Invalid email or password';
                        errEl.style.display = 'block';
                        btn.textContent = 'Sign In';
                        btn.disabled = false;
                    }
                }).catch(() => { btn.textContent = 'Sign In'; btn.disabled = false; });
            } else {
                const name = document.getElementById('authName').value.trim();
                if (!name) { errEl.textContent = 'Please enter your name'; errEl.style.display = 'block'; btn.textContent = 'Create Account'; btn.disabled = false; return; }
                const phone = document.getElementById('authPhone').value.trim();
                if (!phone) { errEl.textContent = 'Please enter your phone number'; errEl.style.display = 'block'; btn.textContent = 'Create Account'; btn.disabled = false; return; }
                const ownershipEl = document.querySelector('input[name="wantsOwnership"]:checked');
                if (!ownershipEl) { errEl.textContent = 'Please answer the ownership question (Yes or No).'; errEl.style.display = 'block'; btn.textContent = 'Create Account'; btn.disabled = false; return; }
                const fd = new FormData();
                fd.append('name', name);
                fd.append('email', email);
                fd.append('password', password);
                fd.append('phone', phone);
                fd.append('wants_ownership', ownershipEl.value);
                Object.keys(signupTracking).forEach(k => fd.append(k, signupTracking[k]));
                // Meta Lead event: shared event_id lets the Pixel + Conversions API
                // deduplicate the same conversion.
                const leadEventId = 'lead.' + Date.now() + '.' + Math.floor(Math.random() * 1e9);
                fd.append('event_id', leadEventId);
                fd.append('event_source_url', location.href);
                fd.append('fbp', getCookie('_fbp'));
                fd.append('fbc', getCookie('_fbc'));
                fd.append('experience', 'Not Specified');
                fd.append('role', 'Not Specified');
                fd.append('project', '');
                fd.append('developer', 'no');
                fetch('register.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        try { if (window.fbq) fbq('track', 'Lead', {}, { eventID: leadEventId }); } catch (e) {}
                        if (selectedPlanId) proceedToCheckout(selectedPlanId);
                        else window.location.href = "/dashboard";
                    } else {
                        errEl.textContent = data.message || 'Registration failed';
                        errEl.style.display = 'block';
                        btn.textContent = 'Create Account';
                        btn.disabled = false;
                    }
                }).catch(() => { btn.textContent = 'Create Account'; btn.disabled = false; });
            }
        }

        // Open the auth modal in SIGN-UP mode (free account).
        function openSignup() {
            openAuthModal();
            if (authMode === 'login') toggleAuthMode();
        }
        async function handlePlanSelection(priceId) {
            // Logged-in users upgrade straight to Stripe. Logged-out users create a
            // free account first; handleAuth then continues to checkout for this plan.
            selectedPlanId = priceId;
            if (isLoggedIn) proceedToCheckout(priceId);
            else openSignup();
        }
        async function proceedToCheckout(priceId) {
            if (!priceId) { alert("This plan isn't available for checkout yet. Please contact support."); return; }
            if (!stripe) { alert('Payments are not configured yet. Please contact support.'); return; }
            try {
                const response = await fetch('create_subscription_session.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ price_id: priceId })
                });
                const session = await response.json();
                if (session.error) { alert(session.error); return; }
                await stripe.redirectToCheckout({ sessionId: session.id });
            } catch (error) { alert('An error occurred during checkout.'); }
        }
        window.onclick = e => { if (e.target === document.getElementById('authModal')) closeModal(); };
    </script>
</body>
</html>
