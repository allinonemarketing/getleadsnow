<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function createMailer() {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = env('SMTP_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = env('SMTP_USER');
        $mail->Password = env('SMTP_PASS');
        // Accept SMTP_SECURITY (the name used in .env) or the older SMTP_SECURE.
        $smtpSecurity = strtolower((string) env('SMTP_SECURITY', env('SMTP_SECURE', 'tls')));
        $mail->SMTPSecure = ($smtpSecurity === 'tls' || $smtpSecurity === 'starttls')
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = (int)env('SMTP_PORT', 587);
        $mail->setFrom(env('SMTP_FROM_EMAIL', env('SMTP_USER')), env('SMTP_FROM_NAME', APP_NAME));
        return $mail;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Append a signup to a Google Sheet via a Google Apps Script web app.
 * No-op unless SIGNUP_SHEET_WEBHOOK is set in .env. Fire-and-forget: never
 * blocks or fails the signup if the sheet is unreachable.
 */
function sendSignupToSheet($data) {
    // Google Apps Script web app that appends the signup to the sheet.
    // Baked in so it works without a server .env edit; .env can override.
    $url = env('SIGNUP_SHEET_WEBHOOK', 'https://script.google.com/macros/s/AKfycbxopvufaoxaHoKAS-yZiWNfqZgv1LcfmjI59hzSjd8dLmozjX8bKKBYTmJrA--td8Xk/exec');
    if (!$url) return false;
    $payload = json_encode([
        'date'            => date('Y-m-d H:i:s'),
        'name'            => $data['name'] ?? '',
        'email'           => $data['email'] ?? '',
        'phone'           => $data['phone'] ?? '',
        'dnd'             => $data['dnd'] ?? '',
        'wants_ownership' => $data['wants_ownership'] ?? '',
        'source'          => $data['source'] ?? 'signup',
        'utm_source'      => $data['utm_source'] ?? '',
        'utm_medium'      => $data['utm_medium'] ?? '',
        'utm_campaign'    => $data['utm_campaign'] ?? '',
        'fbcampaignid'    => $data['fbcampaignid'] ?? '',
        'fbplacement'     => $data['fbplacement'] ?? '',
        'fbadsetid'       => $data['fbadsetid'] ?? '',
        'fbadid'          => $data['fbadid'] ?? '',
        'timezone'        => $data['timezone'] ?? '',
        'referrer'        => $data['referrer'] ?? '',
        'ip'              => $data['ip'] ?? '',
        'user_agent'      => $data['user_agent'] ?? '',
    ]);
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,   // Apps Script responds with a redirect
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
        ]);
        curl_exec($ch);
        curl_close($ch);
        return true;
    } catch (Exception $e) {
        error_log("Signup->Sheet failed: " . $e->getMessage());
        return false;
    }
}

function sendAdminNotification($userData) {
    $mail = createMailer();
    if (!$mail) return false;
    try {
        $mail->addAddress(ADMIN_EMAIL);
        $mail->isHTML(true);
        $wantsOwnership = ($userData['wants_ownership'] ?? 'no') === 'yes';
        $mail->Subject = ($wantsOwnership ? "New User (WANTS OWNERSHIP) - " : "New User Registration - ") . APP_NAME;
        $ownershipRow = $wantsOwnership
            ? "<span style='color:#127c2e;font-weight:700;'>YES — interested in owning &amp; reselling</span>"
            : "No";
        $mail->Body = "
        <html><body>
            <h2>New User Registration</h2>
            <table>
                <tr><td><strong>Name:</strong></td><td>{$userData['name']}</td></tr>
                <tr><td><strong>Email:</strong></td><td>{$userData['email']}</td></tr>
                <tr><td><strong>Wants to own &amp; resell:</strong></td><td>{$ownershipRow}</td></tr>
            </table>
        </body></html>";
        return $mail->send();
    } catch (Exception $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return false;
    }
}

function sendWelcomeEmail($userData) {
    $mail = createMailer();
    if (!$mail) return false;
    try {
        $mail->addAddress($userData['email'], $userData['name']);
        $mail->isHTML(true);
        $mail->Subject = "Welcome to " . APP_NAME . "!";
        $appName = APP_NAME;
        $appUrl = APP_URL;
        $mail->Body = "
        <html><body>
            <h2>Welcome {$userData['name']}!</h2>
            <p>Thank you for joining {$appName}. You've got <strong>" . (defined('FREE_TIER_CREDITS') ? FREE_TIER_CREDITS : 100) . " free credits</strong> to start finding leads right away — that's 1 credit per lead.</p>
            <p><a href='{$appUrl}/dashboard.php' style='background-color:#c85719;color:#fff;padding:10px 20px;text-decoration:none;border-radius:8px;font-weight:600;'>Go to Dashboard</a></p>
            <br><p>Best regards,<br>{$appName} Team</p>
        </body></html>";
        return $mail->send();
    } catch (Exception $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return false;
    }
}

function sendCreditAddedEmail($userData, $credits, $reason = '') {
    $mail = createMailer();
    if (!$mail) return false;
    try {
        $mail->addAddress($userData['email'], $userData['name']);
        $mail->isHTML(true);
        $mail->Subject = "Credits Added to Your Account!";
        $appName = APP_NAME;
        $appUrl = APP_URL;
        $mail->Body = "
        <html><body>
            <h2>Good news, {$userData['name']}!</h2>
            <p>" . number_format($credits) . " credits have been added to your account!</p>
            " . ($reason ? "<p>Reason: {$reason}</p>" : "") . "
            <p>Your updated balance is now: " . number_format($userData['new_balance']) . " credits</p>
            <p><a href='{$appUrl}/dashboard.php?section=section5' style='background-color:#c85719;color:#fff;padding:10px 20px;text-decoration:none;border-radius:8px;font-weight:600;'>View Your Credits</a></p>
            <br><p>Best regards,<br>{$appName} Team</p>
        </body></html>";
        return $mail->send();
    } catch (Exception $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return false;
    }
}

function sendPasswordResetEmail($email, $name, $token) {
    $mail = createMailer();
    if (!$mail) return false;
    try {
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = "Password Reset - " . APP_NAME;
        $appName = APP_NAME;
        $resetUrl = APP_URL . "/reset_password?token=" . urlencode($token);
        $mail->Body = "
        <html><body>
            <h2>Password Reset Request</h2>
            <p>Hi {$name},</p>
            <p>We received a request to reset your password for your {$appName} account.</p>
            <p><a href='{$resetUrl}' style='background-color:#c85719;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:600;display:inline-block;'>Reset Your Password</a></p>
            <p>This link will expire in 1 hour.</p>
            <p>If you didn't request this, you can safely ignore this email.</p>
            <br><p>Best regards,<br>{$appName} Team</p>
        </body></html>";
        return $mail->send();
    } catch (Exception $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return false;
    }
}

function sendSetPasswordEmail($email, $name, $token, $planLabel = '') {
    $mail = createMailer();
    if (!$mail) return false;
    try {
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = "Set your password - " . APP_NAME;
        $appName = APP_NAME;
        $setUrl = APP_URL . "/reset_password?token=" . urlencode($token);
        $planLine = $planLabel ? "<p>Your <strong>{$planLabel}</strong> plan is active and your credits have been added.</p>" : "";
        $mail->Body = "
        <html><body>
            <h2>Welcome to {$appName}!</h2>
            <p>Hi {$name},</p>
            <p>Thanks for your purchase. We've created your account — set a password to log in and start finding leads.</p>
            {$planLine}
            <p><a href='{$setUrl}' style='background-color:#c85719;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:600;display:inline-block;'>Set Your Password</a></p>
            <p>This link will expire in 24 hours. If it expires, use \"Forgot password\" on the login page to get a new one.</p>
            <br><p>Best regards,<br>{$appName} Team</p>
        </body></html>";
        return $mail->send();
    } catch (Exception $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return false;
    }
}

function sendSubscriptionEmail($userEmail, $planName, $credits, $amount) {
    $mail = createMailer();
    if (!$mail) return false;
    try {
        $mail->addAddress($userEmail);
        $mail->isHTML(true);
        $mail->Subject = "Welcome to " . APP_NAME . " {$planName} Plan!";
        $appName = APP_NAME;
        $appUrl = APP_URL;
        $mail->Body = "
        <html><body>
            <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                <h1 style='color:#c85719;text-align:center;'>Welcome to {$appName}!</h1>
                <div style='padding:20px;'>
                    <h2>Your Subscription is Active!</h2>
                    <ul>
                        <li>Plan: {$planName}</li>
                        <li>Credits Added: " . number_format($credits) . "</li>
                        <li>Amount: $" . number_format($amount, 2) . "</li>
                    </ul>
                    <p>Your subscription has been successfully activated!</p>
                    <div style='text-align:center;margin-top:20px;'>
                        <a href='{$appUrl}/dashboard.php' style='background-color:#c85719;color:#fff;padding:10px 20px;text-decoration:none;border-radius:8px;font-weight:600;'>Go to Dashboard</a>
                    </div>
                </div>
            </div>
        </body></html>";
        return $mail->send();
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}
