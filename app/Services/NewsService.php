<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use App\Core\ValidationException;

/**
 * News: company announcements emailed to employees.
 *
 * Every News email is sent from the fixed sender below. It is not a setting and cannot be changed in
 * the HRMS; SMTP credentials come only from .env. Each recipient gets their own message (no CC/BCC
 * lists), so no one sees anyone else's address. Before a send, the SMTP server is asked whether it
 * accepts the sender; if sign-in or the sender is refused, nothing is marked as sent.
 */
final class NewsService
{
    public const SENDER_EMAIL = 'hr@menakantransport.com';
    public const SENDER_NAME = 'Menakan Transport HR Department';
    public const AUDIENCES = ['all' => 'All active employees', 'employees' => 'Selected employees', 'departments' => 'Departments', 'teams' => 'Teams (a manager and their direct reports)'];
    public const PLACEHOLDERS = ['{first_name}', '{last_name}', '{full_name}', '{employee_id}'];

    public static function sender(): array
    {
        return ['email' => self::SENDER_EMAIL, 'name' => self::SENDER_NAME];
    }

    /* ------------------------------------------------------------------ drafts, scheduling */

    /**
     * Saves a draft (new when $id is null). $in: title, subject, body, audience, audience_ids[], template_id.
     */
    public static function save(?int $id, array $in, int $userId): int
    {
        $data = self::validate($in);
        if ($id === null) {
            $id = DB::insert('news_posts', $data + ['status' => 'draft', 'created_by' => $userId]);
            AuditLogger::log('news.created', 'news', $id, 'Created news draft "' . $data['title'] . '"');
            return $id;
        }
        $post = self::editable($id);
        DB::update('news_posts', $data + ['status' => 'draft', 'scheduled_at' => null, 'updated_by' => $userId, 'updated_at' => now_utc()], 'id = ?', [$id]);
        AuditLogger::log('news.updated', 'news', $id, 'Updated news "' . $data['title'] . '"' . ($post['status'] === 'scheduled' ? ' (schedule cleared, back to draft)' : ''));
        return $id;
    }

    /** $localWhen = UK local "Y-m-d H:i" (from a datetime-local input). */
    public static function schedule(int $id, string $localWhen, int $userId): void
    {
        $post = self::editable($id);
        $localWhen = str_replace('T', ' ', trim($localWhen));
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $localWhen, uk_tz());
        if (!$dt || $dt->format('Y-m-d H:i') !== $localWhen) {
            throw new ValidationException('Enter the date and time to send.', ['scheduled_at' => 'Enter a valid date and time.']);
        }
        $utc = to_utc($localWhen);
        if ($utc <= now_utc()) {
            throw new ValidationException('The scheduled time must be in the future. Use "Send now" to send straight away.', ['scheduled_at' => 'Choose a future time.']);
        }
        if (!self::recipients($post)) {
            throw new ValidationException('No active employees match the chosen audience.');
        }
        DB::update('news_posts', ['status' => 'scheduled', 'scheduled_at' => $utc, 'updated_by' => $userId, 'updated_at' => now_utc()], 'id = ?', [$id]);
        AuditLogger::log('news.scheduled', 'news', $id, 'Scheduled news "' . $post['title'] . '" for ' . uk_datetime($utc));
    }

    /** Scheduled -> draft. */
    public static function unschedule(int $id, int $userId): void
    {
        $post = DB::one("SELECT * FROM news_posts WHERE id = ? AND status = 'scheduled'", [$id]) ?? throw new ValidationException('Only a scheduled announcement can be unscheduled.');
        DB::update('news_posts', ['status' => 'draft', 'scheduled_at' => null, 'updated_by' => $userId, 'updated_at' => now_utc()], 'id = ?', [$id]);
        AuditLogger::log('news.unscheduled', 'news', $id, 'Cancelled the schedule for "' . $post['title'] . '" (back to draft)');
    }

    /** Only drafts that were never sent can be deleted; everything else is kept for the record. */
    public static function delete(int $id, int $userId): void
    {
        $post = DB::one("SELECT * FROM news_posts WHERE id = ? AND status = 'draft'", [$id]) ?? throw new ValidationException('Only drafts can be deleted.');
        if (DB::value('SELECT id FROM news_deliveries WHERE news_id = ? LIMIT 1', [$id])) {
            throw new ValidationException('This announcement has a delivery history and cannot be deleted.');
        }
        DB::query('DELETE FROM news_posts WHERE id = ?', [$id]);
        AuditLogger::log('news.deleted', 'news', $id, 'Deleted news draft "' . $post['title'] . '"');
    }

    /* ------------------------------------------------------------------ audience */

    /** Active employees (not archived, not left) the post goes to, by id, with their email. */
    public static function recipients(array $post): array
    {
        $ids = self::ids($post['audience_ids'] ?? '');
        $base = "SELECT id, employee_code, first_name, last_name, email FROM employees WHERE deleted_at IS NULL AND status <> 'left'";
        switch ($post['audience']) {
            case 'all':
                return DB::all("$base ORDER BY employee_code");
            case 'employees':
                return $ids ? DB::all("$base AND id IN (" . DB::placeholders($ids) . ') ORDER BY employee_code', $ids) : [];
            case 'departments':
                return $ids ? DB::all("$base AND department_id IN (" . DB::placeholders($ids) . ') ORDER BY employee_code', $ids) : [];
            case 'teams':
                return $ids ? DB::all("$base AND (line_manager_id IN (" . DB::placeholders($ids) . ') OR id IN (' . DB::placeholders($ids) . ')) ORDER BY employee_code', [...$ids, ...$ids]) : [];
        }
        return [];
    }

    /** Managers with at least one current direct report: the "teams" an announcement can target. */
    public static function teams(): array
    {
        return DB::all("SELECT m.id, m.employee_code, m.first_name, m.last_name, COUNT(r.id) AS members
                          FROM employees m JOIN employees r ON r.line_manager_id = m.id AND r.deleted_at IS NULL AND r.status <> 'left'
                         WHERE m.deleted_at IS NULL GROUP BY m.id, m.employee_code, m.first_name, m.last_name ORDER BY m.first_name, m.last_name");
    }

    public static function audienceLabel(array $post): string
    {
        $ids = self::ids($post['audience_ids'] ?? '');
        return match ($post['audience']) {
            'all' => 'All active employees',
            'employees' => count($ids) . ' selected employee' . (count($ids) === 1 ? '' : 's'),
            'departments' => 'Departments: ' . ($ids ? implode(', ', DB::column('SELECT name FROM departments WHERE id IN (' . DB::placeholders($ids) . ') ORDER BY name', $ids)) : '—'),
            'teams' => 'Teams of: ' . ($ids ? implode(', ', array_map(fn ($r) => employee_name($r), DB::all('SELECT first_name, last_name FROM employees WHERE id IN (' . DB::placeholders($ids) . ') ORDER BY first_name', $ids))) : '—'),
            default => '—',
        };
    }

    /* ------------------------------------------------------------------ sending */

    /**
     * Checks that the SMTP server accepts hr@menakantransport.com as the sender, records the result and
     * logs a failure. @return array{ok:bool, message:string, test_mode:bool}
     */
    public static function checkSender(?int $userId): array
    {
        $testMode = Mailer::isLogOnly();
        $r = $testMode ? ['ok' => true, 'message' => 'Test mode: emails are written to storage/logs/mail.log and not delivered.'] : Mailer::verifySender(self::SENDER_EMAIL);
        DB::insert('news_sender_checks', ['sender' => self::SENDER_EMAIL, 'ok' => $r['ok'] ? 1 : 0, 'message' => mb_substr($r['message'], 0, 500), 'checked_by' => $userId]);
        if (!$r['ok']) {
            self::logFailure('Sender check failed for ' . self::SENDER_EMAIL . ': ' . $r['message']);
        }
        AuditLogger::log($r['ok'] ? 'news.sender_verified' : 'news.sender_check_failed', 'news', null, ($r['ok'] ? 'Sender check passed: ' : 'Sender check failed: ') . $r['message']);
        return $r + ['test_mode' => $testMode];
    }

    public static function lastSenderCheck(): ?array
    {
        return DB::one('SELECT * FROM news_sender_checks ORDER BY id DESC LIMIT 1');
    }

    /**
     * Sends a draft or scheduled post now (or retries the failed / pending recipients of a post that was
     * partly sent). Returns a summary; never throws for delivery problems.
     * @return array{status:string, sent:int, failed:int, skipped:int, error:?string, test_mode:bool}
     */
    public static function send(int $id, ?int $userId): array
    {
        // Claim the post so two sends (e.g. the scheduler and an admin) cannot run at once.
        $claimed = DB::query("UPDATE news_posts SET status = 'sending', updated_at = ? WHERE id = ? AND status IN ('draft','scheduled','partially_sent','failed')", [now_utc(), $id])->rowCount();
        if (!$claimed) {
            throw new ValidationException('This announcement has already been sent, or is being sent right now.');
        }
        $post = DB::one('SELECT * FROM news_posts WHERE id = ?', [$id]);

        // Recipients are fixed at the first send; a retry only revisits the ones not yet delivered.
        if (!DB::value('SELECT id FROM news_deliveries WHERE news_id = ? LIMIT 1', [$id])) {
            foreach (self::recipients($post) as $r) {
                $email = trim((string) $r['email']);
                $valid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
                DB::insert('news_deliveries', ['news_id' => $id, 'employee_id' => (int) $r['id'], 'email' => $valid ? $email : null,
                    'status' => $valid ? 'pending' : 'skipped', 'error' => $valid ? null : 'No valid email address in the employee Basic information']);
            }
        }
        $todo = DB::all("SELECT d.*, e.first_name, e.last_name, e.employee_code FROM news_deliveries d JOIN employees e ON e.id = d.employee_id
                          WHERE d.news_id = ? AND d.status IN ('pending','failed') ORDER BY d.id", [$id]);
        if (!$todo && !DB::value("SELECT id FROM news_deliveries WHERE news_id = ? AND status IN ('sent','logged') LIMIT 1", [$id])) {
            return self::finish($post, 'failed', 'No active employee in the chosen audience has a valid email address.', $userId);
        }

        // Do not assume the mailbox or SMTP access exists: ask the server first.
        $check = self::checkSender($userId);
        if (!$check['ok']) {
            DB::query("UPDATE news_deliveries SET status = 'failed', error = ?, attempted_at = ?, attempts = attempts + 1 WHERE news_id = ? AND status IN ('pending','failed')",
                [mb_substr('Not sent: ' . $check['message'], 0, 500), now_utc(), $id]);
            return self::finish($post, null, $check['message'], $userId);
        }

        $abort = null;
        foreach ($todo as $d) {
            if ($abort) {
                DB::update('news_deliveries', ['status' => 'failed', 'error' => mb_substr('Not attempted: ' . $abort, 0, 500)], 'id = ?', [(int) $d['id']]);
                continue;
            }
            $vars = ['{first_name}' => $d['first_name'], '{last_name}' => $d['last_name'], '{full_name}' => trim($d['first_name'] . ' ' . $d['last_name']), '{employee_id}' => $d['employee_code']];
            try {
                Mailer::send((string) $d['email'], strtr($post['subject'], $vars), strtr($post['body'], $vars), [], self::sender());
                DB::update('news_deliveries', ['status' => $check['test_mode'] ? 'logged' : 'sent', 'error' => null, 'attempted_at' => now_utc(), 'sent_at' => now_utc(),
                    'attempts' => (int) $d['attempts'] + 1], 'id = ?', [(int) $d['id']]);
            } catch (\Throwable $e) {
                $msg = mb_substr($e->getMessage(), 0, 500);
                DB::update('news_deliveries', ['status' => 'failed', 'error' => $msg, 'attempted_at' => now_utc(), 'attempts' => (int) $d['attempts'] + 1], 'id = ?', [(int) $d['id']]);
                self::logFailure("News #$id to employee {$d['employee_code']}: $msg");
                // Sign-in or sender refusals will fail for everyone: stop rather than hammer the server.
                if (preg_match('/authenticat|could not connect|sender|not (allowed|permitted|authori[sz]ed)|relay|5\.7\.\d/i', $msg)) {
                    $abort = $msg;
                }
            }
        }
        return self::finish($post, null, $abort, $userId, $check['test_mode']);
    }

    /** Sends every scheduled post that is due. Run by bin/send-scheduled-news.php. @return int posts processed */
    public static function sendDue(): int
    {
        $n = 0;
        foreach (DB::column("SELECT id FROM news_posts WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at", [now_utc()]) as $id) {
            try {
                self::send((int) $id, null);
                $n++;
            } catch (ValidationException) {
                // Already picked up by someone else.
            }
        }
        return $n;
    }

    /** Totals the delivery log and sets the post's final status. */
    private static function finish(array $post, ?string $forceStatus, ?string $error, ?int $userId, bool $testMode = false): array
    {
        $id = (int) $post['id'];
        $c = DB::one("SELECT COUNT(*) AS total, SUM(status IN ('sent','logged')) AS sent, SUM(status = 'failed') AS failed, SUM(status = 'skipped') AS skipped
                        FROM news_deliveries WHERE news_id = ?", [$id]);
        $sent = (int) $c['sent'];
        $failed = (int) $c['failed'];
        $status = $forceStatus ?? ($sent === 0 ? 'failed' : ($failed > 0 ? 'partially_sent' : 'sent'));
        DB::update('news_posts', ['status' => $status, 'recipients_total' => (int) $c['total'], 'sent_count' => $sent, 'failed_count' => $failed,
            'sent_at' => $sent > 0 ? now_utc() : $post['sent_at'], 'test_mode' => $testMode ? 1 : 0, 'last_error' => $error ? mb_substr($error, 0, 500) : null], 'id = ?', [$id]);
        AuditLogger::log($status === 'sent' ? 'news.sent' : ($status === 'partially_sent' ? 'news.partially_sent' : 'news.send_failed'), 'news', $id,
            sprintf('"%s": %d sent%s, %d failed, %d skipped (no email)%s', $post['title'], $sent, $testMode ? ' (test mode, mail log only)' : '', $failed, (int) $c['skipped'], $error ? '. Error: ' . $error : ''),
            ['sent' => $sent, 'failed' => $failed, 'skipped' => (int) $c['skipped'], 'by' => $userId === null ? 'scheduler' : 'admin']);
        return ['status' => $status, 'sent' => $sent, 'failed' => $failed, 'skipped' => (int) $c['skipped'], 'error' => $error, 'test_mode' => $testMode];
    }

    private static function logFailure(string $line): void
    {
        @file_put_contents(Config::get('app.storage_path') . '/logs/news-mail.log', '[' . gmdate('Y-m-d H:i:s') . " UTC] $line\n", FILE_APPEND | LOCK_EX);
    }

    /* ------------------------------------------------------------------ templates */

    public static function saveTemplate(?int $id, array $in, int $userId): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        $subject = trim((string) ($in['subject'] ?? ''));
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        $errors = array_filter([
            'name' => $name === '' ? 'Enter a name.' : (mb_strlen($name) > 120 ? 'Use 120 characters or fewer.' : null),
            'subject' => $subject === '' ? 'Enter a subject.' : (mb_strlen($subject) > 200 ? 'Use 200 characters or fewer.' : null),
            'body' => $body === '' ? 'Enter the message.' : null,
        ]);
        if (!$errors && DB::value('SELECT id FROM news_templates WHERE name = ? AND id <> ?', [$name, (int) $id])) {
            $errors['name'] = 'A template with this name already exists.';
        }
        if ($errors) {
            throw new ValidationException('Please check the template.', $errors);
        }
        $data = ['name' => $name, 'subject' => $subject, 'body' => $body, 'is_active' => empty($in['is_active']) && $id !== null ? 0 : 1];
        if ($id === null) {
            $id = DB::insert('news_templates', $data + ['created_by' => $userId]);
            AuditLogger::log('news_template.created', 'news_template', $id, 'Created News template "' . $name . '"');
        } else {
            DB::update('news_templates', $data + ['updated_by' => $userId, 'updated_at' => now_utc()], 'id = ?', [$id]);
            AuditLogger::log('news_template.updated', 'news_template', $id, 'Updated News template "' . $name . '"');
        }
        return $id;
    }

    public static function deleteTemplate(int $id, int $userId): void
    {
        $t = DB::one('SELECT * FROM news_templates WHERE id = ?', [$id]) ?? throw new ValidationException('Template not found.');
        DB::query('DELETE FROM news_templates WHERE id = ?', [$id]);   // posts keep their own copy of the text
        AuditLogger::log('news_template.deleted', 'news_template', $id, 'Deleted News template "' . $t['name'] . '"');
    }

    /* ------------------------------------------------------------------ helpers */

    private static function validate(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        $subject = trim((string) ($in['subject'] ?? ''));
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        $errors = array_filter([
            'title' => $title === '' ? 'Enter a title.' : (mb_strlen($title) > 200 ? 'Use 200 characters or fewer.' : null),
            'subject' => $subject === '' ? 'Enter the email subject.' : (mb_strlen($subject) > 200 ? 'Use 200 characters or fewer.' : null),
            'body' => $body === '' ? 'Enter the message.' : (mb_strlen($body) > 20000 ? 'The message is too long.' : null),
        ]);
        $audience = self::audienceFromInput($in, $errors);
        if ($errors) {
            throw new ValidationException('Please check the announcement.', $errors);
        }
        $template = (int) ($in['template_id'] ?? 0);
        return ['title' => $title, 'subject' => $subject, 'body' => $body] + $audience
            + ['template_id' => $template && DB::value('SELECT id FROM news_templates WHERE id = ?', [$template]) ? $template : null];
    }

    /**
     * Reads the audience picker (audience + audience_ids[<audience>][]) shared by announcements and threads.
     * Adds to $errors on a problem. @return array{audience:string, audience_ids:?string}
     */
    public static function audienceFromInput(array $in, array &$errors): array
    {
        $audience = (string) ($in['audience'] ?? 'all');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['audience_ids'][$audience] ?? $in['audience_ids'] ?? [])))));
        if (!isset(self::AUDIENCES[$audience])) {
            $errors['audience'] = 'Choose who receives it.';
        } elseif (!$errors && $audience !== 'all' && !$ids) {
            $errors['audience_ids'] = 'Choose at least one ' . ['employees' => 'employee', 'departments' => 'department', 'teams' => 'team'][$audience] . '.';
        }
        return ['audience' => $audience, 'audience_ids' => $audience === 'all' ? null : implode(',', $ids)];
    }

    /** True when the employee is in the audience (as worked out by recipients()). */
    public static function inAudience(array $post, int $employeeId): bool
    {
        return in_array($employeeId, array_map(fn ($r) => (int) $r['id'], self::recipients($post)), true);
    }

    private static function editable(int $id): array
    {
        return DB::one("SELECT * FROM news_posts WHERE id = ? AND status IN ('draft','scheduled')", [$id])
            ?? throw new ValidationException('Only drafts and scheduled announcements can be changed. Sent announcements are kept as they were sent.');
    }

    /** @return int[] */
    public static function ids(?string $csv): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $csv))));
    }
}
