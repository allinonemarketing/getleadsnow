<?php
session_start();
require_once 'includes/auth.php';
require_once 'config/stripe_config.php';
require_once 'config/subscription_config.php';

if (isset($_GET['action']) && $_GET['action'] === 'get_credits') {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
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

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$userName = $_SESSION['user_name'] ?? 'User';

try {
    $stmt = $pdo->prepare("SELECT credits, subscription_plan FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    $userCredits = $userData['credits'] ?? 0;
    $userPlan = $userData['subscription_plan'] ?? 'none';
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $userCredits = 0;
    $userPlan = 'none';
}

if (isset($_GET['success']) && $_GET['success'] === 'true' && isset($_GET['session_id'])) {
    try {
        $session = \Stripe\Checkout\Session::retrieve($_GET['session_id']);
        
        $stmt = $pdo->prepare("SELECT id FROM credit_transactions WHERE transaction_id = ?");
        $stmt->execute([$session->payment_intent]);
        $existingTransaction = $stmt->fetch();
        
        if (!$existingTransaction && $session->metadata->user_id == $_SESSION['user_id']) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Plans & Pricing</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://js.stripe.com/v3/"></script>
    <script>
        const stripe = Stripe('<?php echo htmlspecialchars($stripePublicKey); ?>');
    </script>

    <style>
        :root {
            --accent: #c85719;
            --accent-light: #fce8dc;
            --green: #34C759;
            --text-primary: #1d1d1f;
            --text-secondary: #6e6e73;
            --text-tertiary: #aeaeb2;
            --bg: #ffffff;
            --bg-secondary: #f5f5f7;
            --card-border: rgba(0,0,0,0.06);
            --card-bg: rgba(255,255,255,0.8);
        }
        * { margin:0; padding:0; box-sizing:border-box; -webkit-font-smoothing:antialiased; }
        body { background:var(--bg); color:var(--text-primary); font-family:'Inter',system-ui,-apple-system,sans-serif; overflow-x:hidden; min-height:100vh; }
        .container { max-width:1120px; margin:0 auto; padding:0 24px; }
        section { padding:80px 0; }
        h1,h2,h3 { font-weight:700; line-height:1.15; letter-spacing:-0.02em; }
        h1 { font-size:clamp(2.2rem,5vw,3.5rem); color:var(--text-primary); }
        h1 span { color:var(--accent); }
        h2 { font-size:clamp(1.6rem,3vw,2.4rem); }
        p { color:var(--text-secondary); font-size:1.05rem; line-height:1.7; margin-bottom:1.5rem; }

        .page-wrapper { position:relative; display:flex; flex-direction:column; min-height:100vh; }

        header { position:fixed; top:0; width:100%; padding:16px 0; z-index:100; background:rgba(255,255,255,0.85); backdrop-filter:blur(20px); border-bottom:1px solid var(--card-border); }
        nav { display:flex; justify-content:space-between; align-items:center; }
        .logo { font-weight:800; font-size:1.15rem; display:flex; align-items:center; gap:8px; color:var(--text-primary); text-decoration:none; }
        .logo img { height:28px; border-radius:6px; }
        .nav-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 18px; border-radius:980px; font-weight:600; font-size:13px; text-decoration:none; cursor:pointer; border:none; font-family:inherit; transition:all 0.2s; }
        .nav-btn-primary { background:var(--accent); color:#fff; }
        .nav-btn-primary:hover { background:#0066DD; }
        .nav-btn-ghost { background:transparent; color:var(--text-primary); }
        .nav-btn-ghost:hover { background:var(--bg-secondary); }

        .badge { display:inline-block; padding:6px 14px; border-radius:980px; font-size:12px; font-weight:600; letter-spacing:0.02em; margin-bottom:16px; }
        .badge-plan { background:var(--accent-light); color:var(--accent); }
        .badge-current { background:#E5F8ED; color:var(--green); }

        .pricing-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:20px; margin-top:48px; }
        .pricing-card { background:var(--card-bg); border:1px solid var(--card-border); border-radius:20px; padding:40px 32px; text-align:center; position:relative; transition:transform 0.3s, box-shadow 0.3s; display:flex; flex-direction:column; }
        .pricing-card:hover { transform:translateY(-4px); box-shadow:0 12px 40px rgba(0,0,0,0.06); }
        .pricing-card.featured { border-color:var(--accent); box-shadow:0 8px 30px rgba(200,87,25,0.1); }
        .pricing-card.featured::before { content:'Most Popular'; position:absolute; top:-12px; left:50%; transform:translateX(-50%); background:var(--accent); color:#fff; font-size:11px; font-weight:700; padding:4px 14px; border-radius:980px; }
        .pricing-card h3 { font-size:1.15rem; color:var(--text-secondary); margin-bottom:8px; font-weight:600; }
        .price { font-size:3.2rem; font-weight:800; margin:8px 0 20px; color:var(--text-primary); }
        .price span { font-size:1rem; font-weight:400; color:var(--text-tertiary); }
        .features-list { list-style:none; text-align:left; margin:20px 0; flex-grow:1; }
        .features-list li { padding:8px 0; color:var(--text-secondary); font-size:14px; display:flex; align-items:center; gap:10px; }
        .features-list i { color:var(--accent); font-size:13px; }
        .select-btn { display:block; width:100%; padding:14px; border-radius:12px; font-weight:600; font-size:15px; cursor:pointer; border:none; font-family:inherit; transition:all 0.2s; }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-primary:hover { background:#0066DD; }
        .btn-secondary { background:var(--bg-secondary); color:var(--text-primary); }
        .btn-secondary:hover { background:#e8e8ed; }

        .faq-grid { display:grid; gap:16px; max-width:800px; margin:40px auto 0; }
        .faq-item { padding:24px; background:var(--bg); border:1px solid var(--card-border); border-radius:16px; }
        .faq-question { font-weight:600; color:var(--text-primary); margin-bottom:8px; font-size:15px; }
        .faq-answer { color:var(--text-secondary); font-size:14px; margin-bottom:0; line-height:1.6; }

        footer { border-top:1px solid var(--card-border); padding:48px 0; background:var(--bg); margin-top:auto; }
        .footer-grid { display:grid; grid-template-columns:1fr 2fr; gap:48px; }
        .footer-links { display:flex; gap:48px; }
        .footer-links a { display:block; color:var(--text-tertiary); text-decoration:none; margin-bottom:10px; font-size:14px; }
        .footer-links a:hover { color:var(--text-primary); }

        .success-toast { position:fixed; top:20px; left:50%; transform:translateX(-50%); background:#E5F8ED; border:1px solid var(--green); color:var(--text-primary); padding:14px 28px; border-radius:14px; z-index:1000; text-align:center; opacity:0; transition:opacity 0.5s ease; box-shadow:0 8px 30px rgba(0,0,0,0.08); }

        @media (max-width:768px) {
            .footer-grid { grid-template-columns:1fr; }
            .footer-links { flex-direction:column; gap:24px; }
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <header>
            <div class="container">
                <nav>
                    <a href="index.php" class="logo"><img src="<?php echo APP_LOGO; ?>" alt="<?php echo APP_NAME; ?>"></a>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:13px;color:var(--text-secondary);">Credits: <strong style="color:var(--text-primary);"><?php echo number_format($userCredits); ?></strong></span>
                        <a href="dashboard.php" class="nav-btn nav-btn-ghost">Dashboard</a>
                    </div>
                </nav>
            </div>
        </header>

        <section style="padding-top:140px;">
            <div class="container">
                <div style="text-align:center;max-width:700px;margin:0 auto;">
                    <div class="badge badge-plan">Simple Pricing</div>
                    <h1>Scale Your <span>Lead Generation</span></h1>
                    <p>Choose the perfect plan for your business. Extract verified business leads from Google Maps in minutes.</p>
                </div>

                <div class="pricing-grid">
                    <div class="pricing-card" <?php echo $userPlan === 'business' ? 'style="border-color:var(--green);"' : ''; ?>>
                        <?php if ($userPlan === 'business'): ?>
                            <div class="badge badge-current">Current Plan</div>
                        <?php endif; ?>
                        <h3>Starter</h3>
                        <div class="price">$<?php echo PLAN_STARTER_PRICE; ?><span>/mo</span></div>
                        <ul class="features-list">
                            <li><i class="fas fa-check"></i> 1,000 Leads / month</li>
                            <li><i class="fas fa-check"></i> Free AI Email Enrichment</li>
                            <li><i class="fas fa-check"></i> CSV Export</li>
                            <li><i class="fas fa-check"></i> Lead List CRM</li>
                        </ul>
                        <button class="select-btn <?php echo $userPlan === 'business' ? 'btn-secondary' : 'btn-primary'; ?>"
                                data-price-id="<?php echo STRIPE_PRICE_STARTER; ?>">
                            <?php echo $userPlan === 'business' ? 'Current Plan' : 'Get Started'; ?>
                        </button>
                    </div>

                    <div class="pricing-card featured" <?php echo $userPlan === 'agency' ? 'style="border-color:var(--green);"' : ''; ?>>
                        <?php if ($userPlan === 'agency'): ?>
                            <div class="badge badge-current" style="position:absolute;top:-12px;left:50%;transform:translateX(-50%);">Current Plan</div>
                        <?php endif; ?>
                        <h3>Growth</h3>
                        <div class="price">$<?php echo PLAN_GROWTH_PRICE; ?><span>/mo</span></div>
                        <ul class="features-list">
                            <li><i class="fas fa-check"></i> <strong>6,000 Leads / month</strong></li>
                            <li><i class="fas fa-check"></i> Free AI Email Enrichment</li>
                            <li><i class="fas fa-check"></i> Priority Support</li>
                            <li><i class="fas fa-check"></i> Public Share Links</li>
                            <li><i class="fas fa-check"></i> GHL Integration</li>
                        </ul>
                        <button class="select-btn <?php echo $userPlan === 'agency' ? 'btn-secondary' : 'btn-primary'; ?>"
                                data-price-id="<?php echo STRIPE_PRICE_GROWTH; ?>">
                            <?php echo $userPlan === 'agency' ? 'Current Plan' : 'Get Started'; ?>
                        </button>
                    </div>

                    <div class="pricing-card" <?php echo $userPlan === 'enterprise' ? 'style="border-color:var(--green);"' : ''; ?>>
                        <?php if ($userPlan === 'enterprise'): ?>
                            <div class="badge badge-current">Current Plan</div>
                        <?php endif; ?>
                        <h3>Pro</h3>
                        <div class="price">$<?php echo PLAN_ENTERPRISE_PRICE; ?><span>/mo</span></div>
                        <ul class="features-list">
                            <li><i class="fas fa-check"></i> <strong>17,000 Leads / month</strong></li>
                            <li><i class="fas fa-check"></i> Free AI Email Enrichment</li>
                            <li><i class="fas fa-check"></i> Priority Support</li>
                            <li><i class="fas fa-check"></i> GHL Integration</li>
                        </ul>
                        <button class="select-btn <?php echo $userPlan === 'enterprise' ? 'btn-secondary' : 'btn-primary'; ?>"
                                data-price-id="<?php echo STRIPE_PRICE_ENTERPRISE; ?>">
                            <?php echo $userPlan === 'enterprise' ? 'Current Plan' : 'Get Started'; ?>
                        </button>
                        <p style="margin-top:12px;margin-bottom:0;font-size:12px;color:var(--text-tertiary);">Need more? <a href="mailto:<?php echo SUPPORT_EMAIL; ?>" style="color:var(--accent);text-decoration:none;font-weight:500;">Email us</a></p>
                    </div>
                </div>
            </div>
        </section>

        <section style="padding-top:0;background:var(--bg-secondary);">
            <div class="container">
                <div style="text-align:center;margin-bottom:40px;">
                    <h2>Frequently Asked Questions</h2>
                </div>
                <div class="faq-grid">
                    <div class="faq-item">
                        <div class="faq-question">How do credits work?</div>
                        <div class="faq-answer">1 credit = 1 lead. Each lead a search returns costs 1 credit, so a search returning 500 leads uses 500 credits. Enriching leads for their email &amp; social profiles is free. Your plan's credits are added to your balance each billing cycle, and any unused credits roll over.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-question">Can I cancel anytime?</div>
                        <div class="faq-answer">Yes, cancel your subscription at any time from your dashboard or the Stripe billing portal. You keep credits until the end of the billing cycle.</div>
                    </div>
                    <div class="faq-item">
                        <div class="faq-question">Do credits roll over?</div>
                        <div class="faq-answer">Yes. Your plan's credits are added to your balance each billing cycle, and any unused credits carry over as long as your subscription stays active.</div>
                    </div>
                </div>
            </div>
        </section>

        <footer>
            <div class="container">
                <div class="footer-grid">
                    <div>
                        <a href="/" class="logo" style="margin-bottom:12px;"><img src="<?php echo APP_LOGO; ?>" alt="<?php echo APP_NAME; ?>"></a>
                        <p style="font-size:13px;margin-bottom:0;color:var(--text-tertiary);">Automating the boring parts of sales.</p>
                    </div>
                    <div class="footer-links">
                        <div>
                            <h4 style="margin-bottom:16px;font-size:14px;">Product</h4>
                            <a href="dashboard.php">Dashboard</a>
                            <a href="pricing.php">Pricing</a>
                        </div>
                        <div>
                            <h4 style="margin-bottom:16px;font-size:14px;">Legal</h4>
                            <a href="https://allinonemarketing.com/terms-conditions/" target="_blank" rel="noopener">Terms</a>
                            <a href="https://allinonemarketing.com/privacy-policy" target="_blank" rel="noopener">Privacy</a>
                        </div>
                    </div>
                </div>
                <div style="margin-top:36px;font-size:12px;color:var(--text-tertiary);text-align:center;">
                    &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. All rights reserved.
                </div>
            </div>
        </footer>
    </div>

    <script>
        const selectBtns = document.querySelectorAll('.select-btn');
        const currentPlan = '<?php echo $userPlan; ?>';
        
        selectBtns.forEach(btn => {
            btn.addEventListener('click', async () => {
                if (btn.innerText.includes('Current Plan')) {
                    if (confirm('Manage your subscription in the Stripe billing portal?\n\nYou can update payment methods, view invoices, and cancel your subscription.')) {
                        window.open('<?php echo STRIPE_BILLING_PORTAL_URL; ?>', '_blank');
                    }
                    return;
                }

                if (currentPlan !== 'none' && currentPlan !== '') {
                    const planNames = {
                        'business': 'Business Plan ($<?php echo PLAN_STARTER_PRICE; ?>/mo)',
                        'agency': 'Agency Plan ($<?php echo PLAN_GROWTH_PRICE; ?>/mo)',
                        'enterprise': 'Enterprise Plan ($<?php echo PLAN_ENTERPRISE_PRICE; ?>/mo)'
                    };
                    
                    const message = `You are currently on the ${planNames[currentPlan] || 'a plan'}.\n\nTo change plans:\n1. Cancel your current subscription in the Stripe billing portal first.\n2. Then return here to subscribe to the new plan.\n\nProceed to billing portal now?`;
                    
                    if (confirm(message)) {
                         window.open('<?php echo STRIPE_BILLING_PORTAL_URL; ?>', '_blank');
                         return;
                    }
                    
                    if (!confirm("Do you want to continue to checkout for the new plan anyway?")) {
                        return;
                    }
                }

                const priceId = btn.getAttribute('data-price-id');
                const originalText = btn.innerText;
                btn.innerText = 'Loading...';
                btn.style.opacity = '0.7';
                
                try {
                    const response = await fetch('create_subscription_session.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            price_id: priceId
                        })
                    });
                
                    const session = await response.json();
                    
                    if (session.error) {
                        alert(session.error);
                        btn.innerText = originalText;
                        btn.style.opacity = '1';
                        return;
                    }
                
                    const result = await stripe.redirectToCheckout({
                        sessionId: session.id
                    });
                
                    if (result.error) {
                        alert(result.error.message);
                        btn.innerText = originalText;
                        btn.style.opacity = '1';
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                    btn.innerText = originalText;
                    btn.style.opacity = '1';
                }
            });
        });

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('success') === 'true') {
            const toast = document.createElement('div');
            toast.className = 'success-toast';
            toast.innerHTML = `
                <h3 style="margin-bottom: 5px; font-size: 1.1rem;">Subscription Activated!</h3>
                <p style="margin-bottom: 0; font-size: 0.9rem; color: var(--text-secondary);">Welcome to your new plan.</p>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => toast.style.opacity = '1', 100);
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 500);
            }, 5000);
        }
    </script>
</body>
</html>