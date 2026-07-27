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
        $mail->SMTPSecure = env('SMTP_SECURE', 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = (int)env('SMTP_PORT', 465);
        $mail->setFrom(env('SMTP_FROM_EMAIL', env('SMTP_USER')), env('SMTP_FROM_NAME', APP_NAME));
        return $mail;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $e->getMessage());
        return null;
    }
}

function sendAdminNotification($userData) {
    $mail = createMailer();
    if (!$mail) return false;
    try {
        $mail->addAddress(ADMIN_EMAIL);
        $mail->isHTML(true);
        $mail->Subject = "New User Registration - " . APP_NAME;
        $mail->Body = "
        <html><body>
            <h2>New User Registration</h2>
            <table>
                <tr><td><strong>Name:</strong></td><td>{$userData['name']}</td></tr>
                <tr><td><strong>Email:</strong></td><td>{$userData['email']}</td></tr>
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
            <p>Thank you for joining {$appName}. We're excited to have you on board!</p>
            <p>You've been given " . FREE_SIGNUP_CREDITS . " free credits to get started.</p>
            <p><a href='{$appUrl}/dashboard' style='background-color:#c85719;color:#fff;padding:10px 20px;text-decoration:none;border-radius:8px;font-weight:600;'>Go to Dashboard</a></p>
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
            <p><a href='{$appUrl}/dashboard?section=section5' style='background-color:#c85719;color:#fff;padding:10px 20px;text-decoration:none;border-radius:8px;font-weight:600;'>View Your Credits</a></p>
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
        $resetUrl = APP_URL . "/reset_password.php?token=" . urlencode($token);
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
                        <a href='{$appUrl}/dashboard' style='background-color:#c85719;color:#fff;padding:10px 20px;text-decoration:none;border-radius:8px;font-weight:600;'>Go to Dashboard</a>
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
