<?php

/**
 * Sends a test email with the current mail settings (.env) and reports the result.
 *
 *   php bin/test-mail.php someone@example.com
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Core\Config;
use App\Services\Mailer;

$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/test-mail.php recipient@example.com\n");
    exit(1);
}
$m = Config::get('app.mail');
fwrite(STDOUT, sprintf("Driver: %s | Server: %s:%d (%s) | Login: %s | From: %s\n", $m['driver'], $m['smtp_host'] ?: '-', $m['smtp_port'],
    $m['smtp_encryption'], $m['smtp_username'] ?: '-', $m['from']));
if (Mailer::isLogOnly()) {
    fwrite(STDOUT, "Test mode: SMTP_PASSWORD (or MAIL_DRIVER/SMTP_HOST) is not set in .env, so the message goes to storage/logs/mail.log only.\n");
}
try {
    Mailer::send($to, 'Menakan HRMS test email', "This is a test message from the Menakan Transport HR portal.\nIf you received it, payslip emails will be delivered.");
    fwrite(STDOUT, Mailer::isLogOnly() ? "Written to storage/logs/mail.log.\n" : "Sent to $to. Check the inbox (and spam folder).\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
