<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Sends email. MAIL_DRIVER=smtp delivers through the SMTP server in .env (PHPMailer);
 * MAIL_DRIVER=log (default) writes each message, with its attachment names, to storage/logs/mail.log.
 */
final class Mailer
{
    /**
     * @param array<int, array{path:string, name:string, mime?:string}> $attachments
     * @param array{email:string, name:string}|null $sender  a fixed sender (the News module); null = MAIL_FROM / MAIL_FROM_NAME from .env
     * @throws \RuntimeException when the message could not be sent
     */
    public static function send(string $to, string $subject, string $body, array $attachments = [], ?array $sender = null): void
    {
        $mail = Config::get('app.mail');
        if ($sender) {
            $mail['from'] = $sender['email'];
            $mail['from_name'] = $sender['name'];
        }
        $body = self::withSignature($body);
        if (self::isLogOnly()) {
            $files = array_map(fn ($a) => $a['name'] . ' (' . number_format((int) @filesize($a['path'])) . ' bytes)', $attachments);
            $entry = sprintf(
                "==== %s ====\nFrom: %s\nTo: %s\nSubject: %s\n%s\n%s\n\n",
                gmdate('Y-m-d H:i:s') . ' UTC',
                $sender ? sprintf('%s <%s>', $mail['from_name'], $mail['from']) : ($mail['from'] ?? ''),
                $to,
                $subject,
                $files ? 'Attachments: ' . implode(', ', $files) . "\n" : '',
                $body
            );
            file_put_contents(Config::get('app.storage_path') . '/logs/mail.log', $entry, FILE_APPEND | LOCK_EX);
            return;
        }
        $m = self::smtp($mail);
        try {
            $m->setFrom((string) $mail['from'], (string) ($mail['from_name'] ?? 'Menakan Transport HR'));
            $m->addAddress($to);
            $m->Subject = $subject;
            $m->Body = $body;
            foreach ($attachments as $a) {
                $m->addAttachment($a['path'], $a['name'], PHPMailer::ENCODING_BASE64, $a['mime'] ?? 'application/octet-stream');
            }
            $m->send();
        } catch (MailException $e) {
            throw new \RuntimeException('Email could not be sent: ' . $m->ErrorInfo);
        }
    }

    /** A PHPMailer set up for the SMTP server in .env (credentials only ever come from the environment). */
    private static function smtp(array $mail): PHPMailer
    {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string) $mail['smtp_host'];
        $m->Port = (int) $mail['smtp_port'];
        $m->SMTPAuth = ($mail['smtp_username'] ?? '') !== '';
        $m->Username = (string) ($mail['smtp_username'] ?? '');
        $m->Password = (string) ($mail['smtp_password'] ?? '');
        $enc = strtolower((string) ($mail['smtp_encryption'] ?? 'tls'));
        $m->SMTPSecure = $enc === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($enc === 'none' ? '' : PHPMailer::ENCRYPTION_STARTTLS);
        $m->Timeout = 20;
        $m->CharSet = 'UTF-8';
        return $m;
    }

    /**
     * Checks, without sending anything, that the SMTP server lets us send as $from: it connects, signs in
     * with the .env credentials, then offers MAIL FROM:<$from> and RCPT TO:<$from> and resets. A server that
     * refuses the sign-in or the sender address fails the check.
     * @return array{ok:bool, message:string}
     */
    public static function verifySender(string $from): array
    {
        if (self::isLogOnly()) {
            return ['ok' => false, 'message' => 'Email is in test mode (MAIL_DRIVER is not smtp, or SMTP_HOST / SMTP_PASSWORD are empty in .env). Nothing can be delivered until SMTP is configured.'];
        }
        $mail = Config::get('app.mail');
        $m = self::smtp($mail);
        try {
            if (!$m->smtpConnect()) {
                return ['ok' => false, 'message' => 'Could not connect or sign in to ' . $mail['smtp_host'] . ': ' . $m->ErrorInfo];
            }
            $smtp = $m->getSMTPInstance();
            if (!$smtp->mail($from)) {
                return ['ok' => false, 'message' => "The SMTP server refused $from as the sender: " . self::smtpError($smtp)];
            }
            if (!$smtp->recipient($from)) {
                return ['ok' => false, 'message' => "The SMTP server accepted the sender but refused a test recipient: " . self::smtpError($smtp)];
            }
            $smtp->reset();
            return ['ok' => true, 'message' => sprintf('Signed in to %s as %s; the server accepts %s as the sender.', $mail['smtp_host'], $mail['smtp_username'], $from)];
        } catch (MailException $e) {
            return ['ok' => false, 'message' => 'SMTP sign-in failed: ' . ($m->ErrorInfo ?: $e->getMessage())];
        } finally {
            $m->smtpClose();
        }
    }

    private static function smtpError(SMTP $smtp): string
    {
        $e = $smtp->getError();
        return trim(($e['smtp_code'] ?? '') . ' ' . ($e['detail'] ?: ($e['error'] ?? 'unknown error')));
    }

    /** Appends the HR email signature from Settings (Settings > Email signature). */
    public static function withSignature(string $body): string
    {
        $sig = trim(str_replace("\r\n", "\n", (string) Settings::get('email_signature', '')));
        return $sig === '' ? $body : rtrim($body) . "\n\nKind regards,\n\n" . $sig . "\n";
    }

    /** Test mode: MAIL_DRIVER is not smtp, or SMTP is chosen but the host or password has not been filled in yet. */
    public static function isLogOnly(): bool
    {
        $m = Config::get('app.mail');
        return ($m['driver'] ?? 'log') !== 'smtp' || ($m['smtp_host'] ?? '') === '' || ($m['smtp_password'] ?? '') === '';
    }
}
