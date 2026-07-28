<?php
/**
 * CLI-only email diagnostic. Prints the full SMTP conversation so you can see
 * whether mail sends or exactly why it fails (auth, connection, TLS, etc.).
 *
 *   php test_email.php you@example.com
 *
 * DELETE THIS FILE after you're done testing.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/includes/email_service.php';

use PHPMailer\PHPMailer\Exception;

$to = $argv[1] ?? '';
if ($to === '') {
    fwrite(STDERR, "Usage: php test_email.php recipient@example.com\n");
    exit(1);
}

$mail = createMailer();
if (!$mail) {
    echo "createMailer() returned null — check SMTP config in .env.\n";
    exit(1);
}

$mail->SMTPDebug = 2;                 // show the SMTP conversation
$mail->addAddress($to);
$mail->isHTML(true);
$mail->Subject = 'Test email from ' . APP_NAME;
$mail->Body    = 'If you can read this, SMTP is working.';

try {
    $mail->send();
    echo "\n==> SUCCESS: test email accepted for delivery to {$to}\n";
} catch (Exception $e) {
    echo "\n==> FAILED: " . $mail->ErrorInfo . "\n";
}
