<?php
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");         

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

    exit(0);
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/subscription_debug.log');

session_start();

error_log("REQUEST DATA: " . json_encode([
    'GET' => $_GET,
    'SESSION' => $_SESSION,
    'SERVER' => [
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
        'REQUEST_URI' => $_SERVER['REQUEST_URI']
    ]
]));

require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'config/stripe_config.php';
require_once 'config/subscription_config.php';

error_log("All required files loaded");

try {
    \Stripe\Stripe::setApiKey($stripeSecretKey);
    error_log("Stripe initialized with key: " . substr($stripeSecretKey, 0, 4) . '...');
} catch (Exception $e) {
    error_log("Stripe initialization error: " . $e->getMessage());
}

$subscription_config_path = __DIR__ . '/config/subscription_config.php';
if (!file_exists($subscription_config_path)) {
    die('Subscription config file not found at: ' . $subscription_config_path);
}
require_once $subscription_config_path;

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$userName = $_SESSION['user_name'] ?? 'User';

try {
    $stmt = $pdo->prepare("SELECT subscription_status, monthly_credits FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userSub = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasSubscription = $userSub && $userSub['subscription_status'] === 'active';
    $monthlyCredits = $userSub ? $userSub['monthly_credits'] : 0;
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $hasSubscription = false;
    $monthlyCredits = 0;
}

if (isset($_GET['success']) && $_GET['success'] === 'true' && isset($_GET['session_id'])) {
    error_log("Processing successful payment return. Session ID: " . $_GET['session_id']);
    
    try {
        $session = \Stripe\Checkout\Session::retrieve($_GET['session_id']);
        error_log("Retrieved Stripe session: " . json_encode($session));
        
        if ($session && $session->payment_status === 'paid') {
            $planName = '';
            $monthlyCredits = 0;
            
            switch ($session->metadata->price_id ?? '') {
                case STRIPE_PRICE_STARTER:
                    $planName = "Starter";
                    $monthlyCredits = PLAN_STARTER_CREDITS;
                    break;
                case STRIPE_PRICE_GROWTH:
                    $planName = "Growth";
                    $monthlyCredits = PLAN_GROWTH_CREDITS;
                    break;
                case STRIPE_PRICE_ENTERPRISE:
                    $planName = "Enterprise";
                    $monthlyCredits = PLAN_ENTERPRISE_CREDITS;
                    break;
            }
            
            error_log("Plan determined: $planName with $monthlyCredits credits");
            
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET subscription_status = 'active',
                        monthly_credits = ?,
                        credits = credits + ?
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $monthlyCredits,
                    $monthlyCredits,
                    $_SESSION['user_id']
                ]);
                
                $stmt = $pdo->prepare("
                    INSERT INTO subscription_log 
                    (user_id, plan_name, amount, credits, stripe_session_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $_SESSION['user_id'],
                    $planName,
                    $session->amount_total / 100,
                    $monthlyCredits,
                    $session->id
                ]);
                
                $pdo->commit();
                
                $_SESSION['subscription_success'] = [
                    'plan_name' => $planName,
                    'credits' => $monthlyCredits,
                    'amount' => $session->amount_total / 100
                ];
                
                error_log("Successfully processed subscription");
                
                header('Location: subscription.php');
                exit();
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Database error: " . $e->getMessage());
                $_SESSION['subscription_error'] = "Database error occurred. Please contact support.";
            }
        } else {
            error_log("Payment not confirmed. Status: " . $session->payment_status);
            $_SESSION['subscription_error'] = "Payment not confirmed. Please contact support.";
        }
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log("Stripe API error: " . $e->getMessage());
        $_SESSION['subscription_error'] = "Unable to verify payment. Please contact support.";
    } catch (Exception $e) {
        error_log("General error: " . $e->getMessage());
        $_SESSION['subscription_error'] = "An error occurred. Please contact support.";
    }
}

$showSuccess = false;
$showError = false;
$successMessage = [];
$errorMessage = '';

if (isset($_SESSION['subscription_success'])) {
    $showSuccess = true;
    $successMessage = $_SESSION['subscription_success'];
    unset($_SESSION['subscription_success']);
}

if (isset($_SESSION['subscription_error'])) {
    $showError = true;
    $errorMessage = $_SESSION['subscription_error'];
    unset($_SESSION['subscription_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo APP_NAME; ?> - Monthly Subscriptions</title>
    <script src="https://js.stripe.com/v3/"></script>
    <script>
        const stripe = Stripe('<?php echo htmlspecialchars($stripePublicKey); ?>');
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Orbitron', sans-serif;
        }

        body {
            background: #000011;
            color: #0ff;
            margin: 0;
            padding: 0;
        }

        .container {
            width: 90%;
            max-width: 1200px;
            margin: 50px auto;
            padding: 0 20px;
        }

        .welcome-message {
            text-align: center;
            margin-bottom: 30px;
            font-size: 24px;
            color: #0ff;
            text-shadow: 0 0 10px #0ff;
        }

        .credit-balance {
            margin-top: 10px;
            font-size: 20px;
            color: #99ffff;
        }

        .credit-amount {
            color: #0ff;
            font-weight: bold;
            font-size: 24px;
            text-shadow: 0 0 10px #0ff;
        }

        .limited-offer {
            background: linear-gradient(45deg, #0ff, #00ffff);
            color: #000033;
            padding: 15px;
            text-align: center;
            font-weight: bold;
            font-size: 18px;
            margin-bottom: 30px;
            border-radius: 10px;
            animation: blink 1s infinite;
            box-shadow: 0 0 15px rgba(0,255,255,0.5);
        }

        @keyframes blink {
            0% { opacity: 1; box-shadow: 0 0 15px rgba(0,255,255,0.5); }
            50% { opacity: 0.8; box-shadow: 0 0 25px rgba(0,255,255,0.8); }
            100% { opacity: 1; box-shadow: 0 0 15px rgba(0,255,255,0.5); }
        }

        .pricing-header {
            text-align: center;
            margin-bottom: 50px;
        }

        .pricing-header h2 {
            font-size: 48px;
            color: #0ff;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
            text-shadow: 0 0 10px #0ff, 0 0 20px #0ff, 0 0 30px #0ff;
        }

        @keyframes pulse {
            0% { transform: scale(1); text-shadow: 0 0 10px #0ff, 0 0 20px #0ff, 0 0 30px #0ff; }
            50% { transform: scale(1.05); text-shadow: 0 0 15px #0ff, 0 0 25px #0ff, 0 0 35px #0ff; }
            100% { transform: scale(1); text-shadow: 0 0 10px #0ff, 0 0 20px #0ff, 0 0 30px #0ff; }
        }

        .pricing-options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-top: 50px;
        }

        .pricing-plan {
            background: linear-gradient(to bottom, #000033, #000066);
            padding: 40px 30px;
            border-radius: 15px;
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid #0ff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,255,255,0.1);
        }

        .pricing-plan:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,255,255,0.2);
            border-color: #00ffff;
        }

        .plan-name {
            font-size: 28px;
            color: #0ff;
            margin-bottom: 20px;
            text-shadow: 0 0 10px #0ff;
            font-weight: 700;
        }

        .plan-price {
            font-size: 42px;
            color: #fff;
            margin-bottom: 20px;
            text-shadow: 0 0 15px #0ff;
            font-weight: 700;
        }

        .plan-credits {
            font-size: 24px;
            color: #0ff;
            margin-bottom: 30px;
            text-shadow: 0 0 5px #0ff;
            font-weight: 500;
        }

        .plan-features {
            list-style: none;
            padding: 0;
            margin-bottom: 30px;
        }

        .plan-features li {
            margin: 15px 0;
            color: #99ffff;
            font-size: 16px;
            text-shadow: 0 0 2px #0ff;
        }

        .popular-badge {
            position: absolute;
            top: 15px;
            right: -50px;
            background: #0ff;
            color: #000033;
            padding: 8px 60px;
            transform: rotate(45deg);
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 0 10px rgba(0,255,255,0.5);
            z-index: 1;
            width: 200px;
        }

        .select-btn {
            padding: 15px 30px;
            background: linear-gradient(45deg, #0ff, #00ffff);
            color: #000033;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 600;
            font-size: 20px;
            width: 100%;
            max-width: 250px;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 20px;
            box-shadow: 0 5px 15px rgba(0,255,255,0.4);
        }

        .select-btn:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,255,255,0.6);
        }

        .faq-section {
            margin-top: 80px;
            padding: 40px;
            background: linear-gradient(to bottom, #000033, #000066);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,255,255,0.1);
        }

        .faq-section h3 {
            color: #0ff;
            margin-bottom: 40px;
            font-size: 32px;
            text-align: center;
            text-shadow: 0 0 10px #0ff;
        }

        .faq-item {
            margin-bottom: 30px;
            padding: 25px;
            background: rgba(0,0,51,0.5);
            border-radius: 10px;
            transition: all 0.3s ease;
            border: 1px solid rgba(0,255,255,0.1);
        }

        .faq-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,255,255,0.1);
            border-color: rgba(0,255,255,0.3);
        }

        .faq-question {
            font-weight: bold;
            color: #0ff;
            margin-bottom: 15px;
            font-size: 20px;
            text-shadow: 0 0 5px #0ff;
        }

        .faq-answer {
            color: #99ffff;
            line-height: 1.8;
            font-size: 16px;
        }

        .need-more-credits {
            text-align: center;
            margin-top: 50px;
            padding: 30px;
            background: linear-gradient(to bottom, #000033, #000066);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,255,255,0.1);
        }

        .need-more-credits a {
            color: #0ff;
            text-decoration: none;
            font-weight: 600;
            font-size: 18px;
            transition: all 0.3s ease;
            text-shadow: 0 0 5px #0ff;
        }

        .need-more-credits a:hover {
            color: #00ffff;
            text-decoration: underline;
            text-shadow: 0 0 10px #00ffff;
        }

        @media (max-width: 768px) {
            .pricing-options {
                grid-template-columns: 1fr;
            }
            .pricing-header h2 {
                font-size: 36px;
            }
            .plan-price {
                font-size: 36px;
            }
            .plan-credits {
                font-size: 22px;
            }
            .select-btn {
                font-size: 18px;
            }
            .container {
                padding: 0 15px;
            }
        }

        .savings-badge {
            background: linear-gradient(45deg, #ff0099, #ff3300);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            margin: 10px auto;
            width: fit-content;
            font-size: 16px;
            text-shadow: 0 0 5px rgba(255,255,255,0.5);
            box-shadow: 0 0 10px rgba(255,0,153,0.3);
            animation: glow 1.5s infinite alternate;
        }

        @keyframes glow {
            from {
                box-shadow: 0 0 10px rgba(255,0,153,0.3);
            }
            to {
                box-shadow: 0 0 20px rgba(255,0,153,0.6);
            }
        }

        .success-message {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 255, 0, 0.1);
            border: 2px solid #0ff;
            padding: 20px;
            border-radius: 10px;
            z-index: 1000;
            text-align: center;
            animation: slideDown 0.5s ease-out, glow 2s infinite;
            box-shadow: 0 0 20px rgba(0, 255, 255, 0.3);
            max-width: 90%;
            width: 500px;
        }

        .success-message h3 {
            color: #0ff;
            margin-bottom: 10px;
            font-size: 24px;
            text-shadow: 0 0 10px rgba(0, 255, 255, 0.5);
        }

        .success-message p {
            color: #fff;
            margin: 5px 0;
            font-size: 16px;
        }

        @keyframes slideDown {
            from { transform: translate(-50%, -100%); }
            to { transform: translate(-50%, 0); }
        }

        @keyframes glow {
            0% { box-shadow: 0 0 20px rgba(0, 255, 255, 0.3); }
            50% { box-shadow: 0 0 30px rgba(0, 255, 255, 0.5); }
            100% { box-shadow: 0 0 20px rgba(0, 255, 255, 0.3); }
        }

        .error-message {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(255, 0, 0, 0.1);
            border: 2px solid #ff0000;
            padding: 20px;
            border-radius: 10px;
            z-index: 1000;
            text-align: center;
            animation: slideDown 0.5s ease-out;
            box-shadow: 0 0 20px rgba(255, 0, 0, 0.3);
            max-width: 90%;
            width: 500px;
            color: #ff0000;
        }
    </style>
</head>
<body>
    <?php if ($showSuccess): ?>
    <div class="success-message">
        <h3>🎉 Subscription Activated! 🎉</h3>
        <p>Welcome to the <?php echo htmlspecialchars($successMessage['plan_name']); ?> Plan</p>
        <p><?php echo number_format($successMessage['credits']); ?> credits have been added to your account</p>
        <p>Amount paid: $<?php echo number_format($successMessage['amount'], 2); ?></p>
        <p>Thank you for choosing <?php echo APP_NAME; ?>!</p>
    </div>
    <?php endif; ?>

    <?php if ($showError): ?>
    <div class="error-message">
        <?php echo htmlspecialchars($errorMessage); ?>
    </div>
    <?php endif; ?>

    <div class="container">
        <div class="welcome-message">
            Welcome, <?php echo htmlspecialchars($userName); ?>!
            <?php if ($hasSubscription): ?>
            <div class="credit-balance">
                Your Monthly Credits: <span class="credit-amount"><?php echo number_format($monthlyCredits); ?></span> Credits
            </div>
            <?php endif; ?>
        </div>

        <div class="limited-offer">
            🚀 Save Up To 75% With Monthly Plans vs One-Time Purchases! 🚀
        </div>

        <div class="pricing-header">
            <h2>Choose Your Lead Generation Plan</h2>
        </div>

        <div class="pricing-options">
            <div class="pricing-plan">
                <div class="plan-name">Starter</div>
                <div class="plan-price">$<?php echo PLAN_STARTER_PRICE; ?>/month</div>
                <div class="plan-credits"><?php echo number_format(PLAN_STARTER_CREDITS); ?> Credits/month</div>
                <div class="savings-badge">Save 74%</div>
                <ul class="plan-features">
                    <li>💰 Only $0.0054 per credit</li>
                    <li>🌐 Website Search API Access</li>
                    <li>🤖 AI-Powered Web Crawler</li>
                    <li>🗺️ Google Maps Business Data</li>
                    <li>🛍️ Amazon Product Data</li>
                    <li>📊 Basic Analytics Dashboard</li>
                    <li>🔄 Credits Roll Over (up to 2x)</li>
                    <li>📧 Email Support</li>
                </ul>
                <button class="select-btn" onclick="subscribe('<?php echo STRIPE_PRICE_STARTER; ?>')">
                    Start Starter Plan
                </button>
            </div>

            <div class="pricing-plan">
                <div class="popular-badge">MOST POPULAR</div>
                <div class="plan-name">Growth</div>
                <div class="plan-price">$<?php echo PLAN_GROWTH_PRICE; ?>/month</div>
                <div class="plan-credits"><?php echo number_format(PLAN_GROWTH_CREDITS); ?> Credits/month</div>
                <div class="savings-badge">Save 85%</div>
                <ul class="plan-features">
                    <li>💰 Only $0.00323 per credit</li>
                    <li>🌐 Advanced Website Search API</li>
                    <li>🤖 AI-Powered Web Crawler</li>
                    <li>🗺️ Bulk Maps Location Search</li>
                    <li>🛍️ Amazon Product Research API</li>
                    <li>📊 Advanced Analytics & Exports</li>
                    <li>🔄 Credits Roll Over (up to 3x)</li>
                    <li>🔑 Multiple API Keys</li>
                    <li>⚡ Priority API Rate Limits</li>
                    <li>📱 Priority Support</li>
                </ul>
                <button class="select-btn" onclick="subscribe('<?php echo STRIPE_PRICE_GROWTH; ?>')">
                    Start Growth Plan
                </button>
            </div>

            <div class="pricing-plan">
                <div class="plan-name">Enterprise</div>
                <div class="plan-price">$<?php echo PLAN_ENTERPRISE_PRICE; ?>/month</div>
                <div class="plan-credits"><?php echo number_format(PLAN_ENTERPRISE_CREDITS); ?> Credits/month</div>
                <div class="savings-badge">Save 90%</div>
                <ul class="plan-features">
                    <li>💰 Only $0.001988 per credit</li>
                    <li>🌐 Enterprise Website Search API</li>
                    <li>🤖 Custom AI Search Rules</li>
                    <li>🗺 Bulk Maps Search</li>
                    <li>🛍️ Real-time Amazon Data API</li>
                    <li>📊 Custom Analytics & Reporting</li>
                    <li>🔄 100,000 Credits / month</li>
                    <li>🔑 Multiple API Keys</li>
                    <li>⚡ Maximum API Rate Limits</li>
                    <li>🤝 Dedicated Account Manager</li>
                    <li>📞 24/7 Priority Support</li>
                    <li>⚙️ Custom Feature Development</li>
                </ul>
                <button class="select-btn" onclick="subscribe('<?php echo STRIPE_PRICE_ENTERPRISE; ?>')">
                    Start Enterprise Plan
                </button>
            </div>
        </div>

        <div class="faq-section">
            <h3>Frequently Asked Questions</h3>
            <div class="faq-item">
                <div class="faq-question">How do monthly subscriptions work?</div>
                <div class="faq-answer">Your subscription automatically renews each month, providing fresh credits on your billing date. Unused credits from the previous month roll over (limits apply based on your plan), ensuring you never waste your search power.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question">Can I upgrade or downgrade my plan?</div>
                <div class="faq-answer">Yes! You can change your plan at any time. When upgrading, you'll get immediate access to the new benefits, and we'll prorate your billing. When downgrading, the change takes effect at the start of your next billing cycle.</div>
            </div>
            <div class="faq-item">
                <div class="faq-question">What happens to my remaining credits if I cancel?</div>
                <div class="faq-answer">Your credits remain valid until the end of your current billing period. After that, any unused credits will expire. We recommend using them before cancellation!</div>
            </div>
        </div>

        <div class="need-more-credits">
            Need a custom enterprise solution? Contact us at <a href="mailto:<?php echo SUPPORT_EMAIL; ?>"><?php echo SUPPORT_EMAIL; ?></a>
        </div>
    </div>

    <script>
        async function subscribe(priceId) {
            try {
                const response = await fetch('create_subscription_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ priceId: priceId })
                });
                
                const session = await response.json();
                
                const result = await stripe.redirectToCheckout({
                    sessionId: session.id
                });
                
                if (result.error) {
                    alert(result.error.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            ['success-message', 'error-message'].forEach(className => {
                const element = document.querySelector('.' + className);
                if (element) {
                    setTimeout(() => {
                        element.style.opacity = '0';
                        element.style.transition = 'opacity 0.5s ease';
                        setTimeout(() => element.remove(), 500);
                    }, 10000);
                }
            });
        });
    </script>
</body>
</html>