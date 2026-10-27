<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;

/**
 * News threads: conversations that already took place (an email chain, a chat, a meeting) recorded by HR
 * and published to employees in the portal. For each message the admin chooses the sender (a department
 * or an employee), the receivers (everyone, departments or employees) and the original date and time.
 *
 * Everyone who took part can read the thread in their portal once it is published, and is notified: each employee
 * who sent a message, and everyone who received one (the employees named, the members of the departments, or
 * everyone). The admin can add further readers.
 *
 * Employees can also start a thread from their portal (to employees or departments) and reply to any thread
 * they can read. They are always the sender and their messages carry the time they were sent; only admins
 * choose the sender and the date and time (custom_time = 1). Published messages are never changed.
 *
 * The entered times are shown as the time of each message, but the real time each message was recorded
 * and the thread published, and by whom, are kept, shown next to the thread and audited. No email is sent.
 */
final class NewsThreadService
{
    public const CHANNELS = ['email' => 'Email', 'chat' => 'Chat / messaging', 'meeting' => 'Meeting', 'phone' => 'Phone call', 'letter' => 'Letter', 'other' => 'Other', 'portal' => 'HRMS message'];
    public const SENDER_TYPES = ['department' => 'Department', 'employee' => 'Employee'];
    public const RECIPIENT_TYPES = ['all' => 'Everyone', 'department' => 'Departments', 'employee' => 'Employees', 'thread' => 'Everyone in this thread'];
    /** Who can read a thread besides the employees who sent or received its messages. */
    public const AUDIENCES = ['receivers' => 'Only the senders and receivers of the messages'] + NewsService::AUDIENCES;

    /* ------------------------------------------------------------------ threads */

    /** Saves a draft thread (new when $id is null). $in: title, channel, summary, audience, audience_ids[]. */
    public static function save(?int $id, array $in, int $userId): int
    {
        $title = trim((string) ($in['title'] ?? ''));
        $channel = (string) ($in['channel'] ?? '');
        $summary = trim(str_replace("\r\n", "\n", (string) ($in['summary'] ?? '')));
        $errors = array_filter([
            'title' => $title === '' ? 'Enter a title.' : (mb_strlen($title) > 200 ? 'Use 200 characters or fewer.' : null),
            'channel' => isset(self::CHANNELS[$channel]) ? null : 'Choose where the conversation took place.',
            'summary' => mb_strlen($summary) > 1000 ? 'Use 1000 characters or fewer.' : null,
        ]);
        $audience = ($in['audience'] ?? 'receivers') === 'receivers' ? ['audience' => 'receivers', 'audience_ids' => null] : NewsService::audienceFromInput($in, $errors);
        if ($errors) {
            throw new ValidationException('Please check the thread.', $errors);
        }
        $data = ['title' => $title, 'channel' => $channel, 'summary' => $summary !== '' ? $summary : null] + $audience;
        if ($id === null) {
            $id = DB::insert('news_threads', $data + ['status' => 'draft', 'created_by' => $userId]);
            AuditLogger::log('news_thread.created', 'news_thread', $id, 'Created news thread draft "' . $title . '"');
            return $id;
        }
        self::draft($id);
        DB::update('news_threads', $data + ['updated_by' => $userId, 'updated_at' => now_utc()], 'id = ?', [$id]);
        AuditLogger::log('news_thread.updated', 'news_thread', $id, 'Updated news thread "' . $title . '"');
        return $id;
    }

    /** Draft -> published. Publishing is final: its messages can no longer be changed; replies can be added. */
    public static function publish(int $id, int $userId): void
    {
        $t = self::draft($id);
        $count = (int) DB::value('SELECT COUNT(*) FROM news_thread_messages WHERE thread_id = ?', [$id]);
        if ($count === 0) {
            throw new ValidationException('Add at least one message before publishing.');
        }
        $readers = self::readers($t);
        if (!$readers) {
            throw new ValidationException('No active employee would be able to read this thread: check the receivers of the messages.');
        }
        DB::update('news_threads', ['status' => 'published', 'published_at' => now_utc(), 'published_by' => $userId], 'id = ? AND status = ?', [$id, 'draft']);
        [$from, $to] = array_values(DB::one('SELECT MIN(occurred_at) AS a, MAX(occurred_at) AS b FROM news_thread_messages WHERE thread_id = ?', [$id]));
        AuditLogger::log('news_thread.published', 'news_thread', $id, sprintf('Published news thread "%s" (%d message%s dated %s to %s) to %s',
            $t['title'], $count, $count === 1 ? '' : 's', uk_datetime($from), uk_datetime($to), self::readersLabel($t)), ['readers' => count($readers)]);
        foreach ($readers as $r) {
            Notifier::toEmployee((int) $r['id'], 'news', 'New in News: ' . $t['title'], 'A conversation record has been shared with you.', '/my/news/threads/' . $id);
        }
    }

    /** Published -> withdrawn: hidden from employees, kept for the record. */
    public static function withdraw(int $id, string $reason, int $userId): void
    {
        $t = DB::one("SELECT * FROM news_threads WHERE id = ? AND status = 'published'", [$id]) ?? throw new ValidationException('Only a published thread can be withdrawn.');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new ValidationException('Give a reason for withdrawing the thread (500 characters or fewer).', ['reason' => 'Enter a reason.']);
        }
        DB::update('news_threads', ['status' => 'withdrawn', 'withdrawn_at' => now_utc(), 'withdrawn_by' => $userId, 'withdrawn_reason' => $reason], 'id = ?', [$id]);
        AuditLogger::log('news_thread.withdrawn', 'news_thread', $id, 'Withdrew news thread "' . $t['title'] . '": ' . $reason);
    }

    /**
     * Admin: deletes a thread (draft, published or withdrawn) and all its messages. It disappears from every
     * employee's News at once; the audit log keeps a note of what was deleted and by whom.
     */
    public static function delete(int $id, int $userId): void
    {
        $t = DB::one('SELECT * FROM news_threads WHERE id = ?', [$id]) ?? throw new ValidationException('Thread not found.');
        $count = (int) DB::value('SELECT COUNT(*) FROM news_thread_messages WHERE thread_id = ?', [$id]);
        DB::query('DELETE FROM news_threads WHERE id = ?', [$id]);   // messages go with it (ON DELETE CASCADE)
        AuditLogger::log('news_thread.deleted', 'news_thread', $id, sprintf('Deleted the %s thread "%s" with %d message%s', $t['status'], $t['title'], $count, $count === 1 ? '' : 's'),
            ['status' => $t['status'], 'messages' => $count], $userId);
    }

    /* ------------------------------------------------------------------ messages */

    /**
     * Admin: adds a message to a draft thread or a reply to a published one, or changes a message of a draft
     * ($messageId). The admin chooses the sender and the date and time. $in: occurred_at (UK local, from a
     * datetime-local input), sender_type, sender_id, recipient_type, recipient_ids[<type>][], subject (optional), body.
     * Messages already published are never changed.
     */
    public static function saveMessage(int $threadId, ?int $messageId, array $in, int $userId): int
    {
        $t = DB::one('SELECT * FROM news_threads WHERE id = ?', [$threadId]) ?? throw new ValidationException('Thread not found.');
        if ($t['status'] !== 'draft' && !($t['status'] === 'published' && $messageId === null)) {
            throw new ValidationException($t['status'] === 'published'
                ? 'Messages in a published thread cannot be changed. You can add a reply.'
                : 'This thread has been withdrawn and can no longer be changed.');
        }
        if ($messageId !== null && !DB::value('SELECT id FROM news_thread_messages WHERE id = ? AND thread_id = ?', [$messageId, $threadId])) {
            throw new ValidationException('Message not found.');
        }
        $data = self::validateMessage($in, $threadId, null, $messageId === null);
        if ($messageId === null) {
            $messageId = DB::insert('news_thread_messages', $data + ['thread_id' => $threadId, 'custom_time' => 1, 'created_by' => $userId]);
            $what = $t['status'] === 'published' ? 'Added a reply' : 'Added a message';
        } else {
            DB::update('news_thread_messages', $data + ['updated_by' => $userId, 'updated_at' => now_utc()], 'id = ?', [$messageId]);
            $what = 'Changed a message';
        }
        AuditLogger::log('news_thread.message_saved', 'news_thread', $threadId, sprintf('%s in "%s": %s to %s, dated %s',
            $what, $t['title'], $data['sender_label'], mb_strimwidth($data['recipient_label'], 0, 80, '…'), uk_datetime($data['occurred_at'])), ['message_id' => $messageId]);
        if ($t['status'] === 'published') {
            self::notify($t, $data['sender_type'] === 'employee' ? $data['sender_id'] : null, 'New reply in: ');
        }
        return $messageId;
    }

    /**
     * Employee portal: starts a thread and sends its first message straight away. The employee is always the
     * sender and the time is now; it goes to employees or departments. $in: title, recipient_type,
     * recipient_ids[<type>][], body. @return int the new thread id
     */
    public static function startFromPortal(int $employeeId, array $in, int $userId): int
    {
        $title = trim((string) ($in['title'] ?? ''));
        $errors = $title === '' ? ['title' => 'Enter a subject.'] : (mb_strlen($title) > 200 ? ['title' => 'Use 200 characters or fewer.'] : []);
        try {
            $data = self::validateMessage(['subject' => ''] + $in, null, $employeeId, true);
        } catch (ValidationException $e) {
            throw new ValidationException('Please check the message.', $errors + $e->errors);
        }
        if ($errors) {
            throw new ValidationException('Please check the message.', $errors);
        }
        $id = DB::transaction(function () use ($title, $data, $userId) {
            $id = DB::insert('news_threads', ['title' => $title, 'channel' => 'portal', 'audience' => 'receivers', 'status' => 'published',
                'published_at' => now_utc(), 'published_by' => $userId, 'created_by' => $userId]);
            DB::insert('news_thread_messages', $data + ['thread_id' => $id, 'custom_time' => 0, 'created_by' => $userId]);
            return $id;
        });
        $t = DB::one('SELECT * FROM news_threads WHERE id = ?', [$id]);
        AuditLogger::log('news_thread.sent', 'news_thread', $id, sprintf('%s sent the thread "%s" to %s', $data['sender_label'], $title, mb_strimwidth($data['recipient_label'], 0, 80, '…')));
        self::notify($t, $employeeId, 'New in News: ');
        return $id;
    }

    /** Employee portal: replies to everyone in a published thread the employee can read. The time is now. $in: body. */
    public static function replyFromPortal(int $threadId, int $employeeId, array $in, int $userId): int
    {
        $t = self::findForEmployee($threadId, $employeeId) ?? throw new ValidationException('You can only reply to a thread you can read.');
        $data = self::validateMessage(['recipient_type' => 'thread', 'subject' => ''] + $in, $threadId, $employeeId, true);
        $messageId = DB::insert('news_thread_messages', $data + ['thread_id' => $threadId, 'custom_time' => 0, 'created_by' => $userId]);
        AuditLogger::log('news_thread.replied', 'news_thread', $threadId, sprintf('%s replied in "%s"', $data['sender_label'], $t['title']), ['message_id' => $messageId]);
        self::notify($t, $employeeId, 'New reply in: ');
        return $messageId;
    }

    /** Notifies every reader except the employee who sent the message. */
    private static function notify(array $thread, ?int $exceptEmployeeId, string $prefix): void
    {
        foreach (self::readers($thread) as $r) {
            if ((int) $r['id'] !== $exceptEmployeeId) {
                Notifier::toEmployee((int) $r['id'], 'news', $prefix . $thread['title'], null, '/my/news/threads/' . $thread['id']);
            }
        }
    }

    /**
     * Admin: writes one email and publishes it straight away, as a new thread. The admin chooses the sender
     * (a department or an employee), the receivers and the date and time; the sender's signature (name and
     * job title) is added at the end. $in: subject, occurred_at, sender_type, sender_id[], recipient_type,
     * recipient_ids[<type>][], body. @return int the new thread id
     */
    public static function compose(array $in, int $userId): int
    {
        $title = trim((string) ($in['subject'] ?? ''));
        $errors = $title === '' ? ['subject' => 'Enter a subject.'] : (mb_strlen($title) > 200 ? ['subject' => 'Use 200 characters or fewer.'] : []);
        if (($in['recipient_type'] ?? '') === 'thread') {
            $errors['recipient_type'] = 'Choose who receives the email.';
        }
        try {
            $data = self::validateMessage(['subject' => ''] + $in, null, null, true);
        } catch (ValidationException $e) {
            throw new ValidationException('Please check the email.', $errors + $e->errors);
        }
        if ($errors) {
            throw new ValidationException('Please check the email.', $errors);
        }
        $thread = ['id' => 0, 'title' => $title, 'audience' => 'receivers', 'audience_ids' => null];
        if (!self::readers($thread, [$data])) {
            throw new ValidationException('No active employee would receive this email: check the sender and receivers.', ['recipient_ids' => 'No active employee here.']);
        }
        $id = DB::transaction(function () use ($title, $data, $userId) {
            $id = DB::insert('news_threads', ['title' => $title, 'channel' => 'email', 'audience' => 'receivers', 'status' => 'published',
                'published_at' => now_utc(), 'published_by' => $userId, 'created_by' => $userId]);
            DB::insert('news_thread_messages', $data + ['thread_id' => $id, 'custom_time' => 1, 'created_by' => $userId]);
            return $id;
        });
        AuditLogger::log('news_thread.published', 'news_thread', $id, sprintf('Published the email "%s": %s to %s, dated %s', $title, $data['sender_label'],
            mb_strimwidth($data['recipient_label'], 0, 80, '…'), uk_datetime($data['occurred_at'])));
        self::notify(DB::one('SELECT * FROM news_threads WHERE id = ?', [$id]), $data['sender_type'] === 'employee' ? $data['sender_id'] : null, 'New in News: ');
        return $id;
    }

    /** The signature added under an email: the employee's name and job title, or the department's name. */
    public static function signature(string $senderType, int $senderId): string
    {
        if ($senderType === 'department') {
            return (string) DB::value('SELECT name FROM departments WHERE id = ?', [$senderId]);
        }
        $e = DB::one('SELECT e.first_name, e.last_name, j.title AS job_title FROM employees e LEFT JOIN job_titles j ON j.id = e.job_title_id WHERE e.id = ?', [$senderId]);
        return $e ? trim(employee_name($e) . "\n" . (string) $e['job_title']) : '';
    }

    public static function deleteMessage(int $threadId, int $messageId, int $userId): void
    {
        $t = self::draft($threadId);
        $m = DB::one('SELECT * FROM news_thread_messages WHERE id = ? AND thread_id = ?', [$messageId, $threadId]) ?? throw new ValidationException('Message not found.');
        DB::query('DELETE FROM news_thread_messages WHERE id = ?', [$messageId]);
        AuditLogger::log('news_thread.message_deleted', 'news_thread', $threadId, sprintf('Removed a message from "%s" (%s, dated %s)', $t['title'], $m['sender_label'], uk_datetime($m['occurred_at'])));
    }

    /** Messages in the order they took place. */
    public static function messages(int $threadId): array
    {
        return DB::all('SELECT m.*, u.username AS recorded_by FROM news_thread_messages m LEFT JOIN users u ON u.id = m.created_by
                         WHERE m.thread_id = ? ORDER BY m.occurred_at, m.id', [$threadId]);
    }

    /* ------------------------------------------------------------------ portal */

    /** Published threads the employee can read, latest message first. */
    public static function forEmployee(int $employeeId): array
    {
        $last = 'FROM news_thread_messages l WHERE l.thread_id = t.id ORDER BY l.occurred_at DESC, l.id DESC LIMIT 1';
        $rows = DB::all("SELECT t.*, (SELECT MAX(occurred_at) FROM news_thread_messages m WHERE m.thread_id = t.id) AS last_message_at,
                                (SELECT COUNT(*) FROM news_thread_messages m WHERE m.thread_id = t.id) AS message_count,
                                (SELECT l.sender_label $last) AS last_sender, (SELECT l.sender_type $last) AS last_sender_type, (SELECT LEFT(l.body, 200) $last) AS last_body
                           FROM news_threads t WHERE t.status = 'published' ORDER BY last_message_at DESC, t.id DESC");
        return array_values(array_filter($rows, fn ($t) => self::canRead($t, $employeeId)));
    }

    /** One published thread, only if the employee is in its audience. */
    public static function findForEmployee(int $id, int $employeeId): ?array
    {
        $t = DB::one("SELECT * FROM news_threads WHERE id = ? AND status = 'published'", [$id]);
        return $t && self::canRead($t, $employeeId) ? $t : null;
    }

    /* ------------------------------------------------------------------ readers */

    /**
     * Active employees who can read the thread once published: every employee who sent one of its messages,
     * everyone who received one, plus the extra audience. @return array<int, array> rows of id, employee_code, first_name, last_name, email
     */
    public static function readers(array $thread, ?array $messages = null): array
    {
        $base = "SELECT id, employee_code, first_name, last_name, email FROM employees WHERE deleted_at IS NULL AND status <> 'left'";
        $deptIds = $employeeIds = [];
        foreach ($messages ?? DB::all('SELECT sender_type, sender_id, recipient_type, recipient_ids FROM news_thread_messages WHERE thread_id = ?', [(int) $thread['id']]) as $m) {
            if ($m['sender_type'] === 'employee') {
                $employeeIds[] = (int) $m['sender_id'];
            }
            if ($m['recipient_type'] === 'all') {
                return DB::all("$base ORDER BY employee_code");
            }
            $ids = NewsService::ids($m['recipient_ids']);
            match ($m['recipient_type']) {
                'department' => array_push($deptIds, ...$ids),
                'employee' => array_push($employeeIds, ...$ids),
                default => null,   // a reply to everyone in the thread adds no one new
            };
        }
        $where = [];
        $params = [];
        if ($deptIds = array_values(array_unique($deptIds))) {
            $where[] = 'department_id IN (' . DB::placeholders($deptIds) . ')';
            array_push($params, ...$deptIds);
        }
        if ($employeeIds = array_values(array_unique($employeeIds))) {
            $where[] = 'id IN (' . DB::placeholders($employeeIds) . ')';
            array_push($params, ...$employeeIds);
        }
        $rows = [];
        foreach ($where ? DB::all("$base AND (" . implode(' OR ', $where) . ')', $params) : [] as $r) {
            $rows[(int) $r['id']] = $r;
        }
        if (($thread['audience'] ?? 'receivers') !== 'receivers') {
            foreach (NewsService::recipients($thread) as $r) {
                $rows[(int) $r['id']] = $r;
            }
        }
        uasort($rows, fn ($a, $b) => strcmp((string) $a['employee_code'], (string) $b['employee_code']));
        return array_values($rows);
    }

    public static function canRead(array $thread, int $employeeId): bool
    {
        return in_array($employeeId, array_map(fn ($r) => (int) $r['id'], self::readers($thread)), true);
    }

    public static function readersLabel(array $thread): string
    {
        return ($thread['audience'] ?? 'receivers') === 'receivers' ? 'The senders and receivers of the messages' : 'The senders and receivers of the messages, plus ' . lcfirst(NewsService::audienceLabel($thread));
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * Checks one message. $threadId: the thread it joins (null for a new one), for replies to "everyone in this
     * thread". $asEmployeeId: sent from the portal by this employee, who is always the sender, at the current
     * time, to employees, departments or the thread; only admins (null) choose the sender and the date and time.
     */
    private static function validateMessage(array $in, ?int $threadId, ?int $asEmployeeId, bool $sign = false): array
    {
        $errors = [];
        if ($asEmployeeId !== null) {
            $in = ['occurred_at' => uk_now()->format('Y-m-d H:i'), 'sender_type' => 'employee', 'sender_id' => $asEmployeeId] + $in;
            unset($in['recipient_ids']['all']);
            if (($in['recipient_type'] ?? '') === 'all') {
                $errors['recipient_type'] = 'Choose employees or departments.';
            }
        }
        $when = str_replace('T', ' ', trim((string) ($in['occurred_at'] ?? '')));
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $when, uk_tz());
        if (!$dt || $dt->format('Y-m-d H:i') !== $when) {
            $errors['occurred_at'] = 'Enter the date and time the message was sent.';
        } elseif (to_utc($when) > now_utc()) {
            $errors['occurred_at'] = 'The date and time cannot be in the future: threads record messages that have already been sent.';
        }

        $senderType = (string) ($in['sender_type'] ?? '');
        $senderId = (int) (is_array($in['sender_id'] ?? null) ? ($in['sender_id'][$senderType] ?? 0) : ($in['sender_id'] ?? 0));
        $senderLabel = match ($senderType) {
            'department' => DB::value('SELECT name FROM departments WHERE id = ?', [$senderId]),
            'employee' => ($e = DB::one('SELECT first_name, last_name, employee_code, email FROM employees WHERE id = ? AND deleted_at IS NULL', [$senderId]))
                ? self::address($e) : null,
            default => null,
        };
        if (!isset(self::SENDER_TYPES[$senderType])) {
            $errors['sender_type'] = 'Choose who sent the message.';
        } elseif (!$senderLabel) {
            $errors['sender_id'] = 'Choose the sending ' . ($senderType === 'department' ? 'department.' : 'employee.');
        }

        $recipientType = (string) ($in['recipient_type'] ?? '');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['recipient_ids'][$recipientType] ?? [])))));
        $names = match ($recipientType) {
            'department' => $ids ? DB::column('SELECT name FROM departments WHERE id IN (' . DB::placeholders($ids) . ') ORDER BY name', $ids) : [],
            'employee' => $ids ? array_map(fn ($e) => self::address($e),
                DB::all('SELECT first_name, last_name, employee_code, email FROM employees WHERE deleted_at IS NULL AND id IN (' . DB::placeholders($ids) . ') ORDER BY first_name, last_name', $ids)) : [],
            default => [],
        };
        if (isset($errors['recipient_type'])) {
            // already explained
        } elseif (!isset(self::RECIPIENT_TYPES[$recipientType])) {
            $errors['recipient_type'] = 'Choose who received the message.';
        } elseif ($recipientType === 'thread') {
            if (!$threadId || !DB::value('SELECT id FROM news_thread_messages WHERE thread_id = ? LIMIT 1', [$threadId])) {
                $errors['recipient_type'] = 'The first message needs its own receivers; "Everyone in this thread" is for replies.';
            }
            $ids = [];
        } elseif ($recipientType !== 'all' && (!$ids || count($names) !== count($ids))) {
            $errors['recipient_ids'] = 'Choose at least one receiving ' . ($recipientType === 'department' ? 'department.' : 'employee.');
        }

        $subject = trim((string) ($in['subject'] ?? ''));
        if (mb_strlen($subject) > 200) {
            $errors['subject'] = 'Use 200 characters or fewer.';
        }
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        if ($body === '') {
            $errors['body'] = 'Enter the message.';
        } elseif (mb_strlen($body) > 20000) {
            $errors['body'] = 'The message is too long.';
        }
        if ($errors) {
            throw new ValidationException('Please check the message.', $errors);
        }
        if ($sign) {
            $body .= "\n\n" . self::signature($senderType, $senderId);
        }
        return ['occurred_at' => to_utc($when), 'sender_type' => $senderType, 'sender_id' => $senderId, 'sender_label' => mb_substr((string) $senderLabel, 0, 200),
            'recipient_type' => $recipientType, 'recipient_ids' => in_array($recipientType, ['all', 'thread'], true) ? null : implode(',', $ids),
            'recipient_label' => mb_strimwidth(match ($recipientType) { 'all' => 'Everyone', 'thread' => 'Everyone in this thread', default => implode(', ', $names) }, 0, 1000, '…'),
            'subject' => $subject !== '' ? $subject : null, 'body' => $body];
    }

    /* ------------------------------------------------------------------ display */

    /** Splits a stored "Name <email>" line. @return array{name:string, email:?string} */
    public static function person(string $label): array
    {
        return preg_match('/^(.*?)\s*<([^<>]+)>$/', $label, $m) ? ['name' => $m[1], 'email' => $m[2]] : ['name' => $label, 'email' => null];
    }

    /** A receivers line with the addresses left out: "Birinder Singh, Mounish Reddy Gaddam". */
    public static function names(string $label): string
    {
        return (string) preg_replace('/\s*<[^<>]+>/', '', $label);
    }

    /** Initials and a steady colour for a sender's avatar. @return array{initials:string, color:string} */
    public static function avatar(string $name): array
    {
        $palette = ['#1f5fa8', '#0f7b6c', '#8e44ad', '#c0392b', '#d35400', '#2c3e50', '#16a085', '#7d5ba6', '#b7791f', '#2f855a'];
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1) . (count($words) > 1 ? mb_substr((string) end($words), 0, 1) : ''));
        return ['initials' => $initials, 'color' => $palette[crc32(mb_strtolower($name)) % count($palette)]];
    }

    /** An employee as an email address line: "Name <email>", or just the name when they have no valid email. No employee ID. */
    private static function address(array $e): string
    {
        $email = trim((string) ($e['email'] ?? ''));
        return employee_name($e) . (filter_var($email, FILTER_VALIDATE_EMAIL) ? ' <' . $email . '>' : '');
    }

    private static function draft(int $id): array
    {
        return DB::one("SELECT * FROM news_threads WHERE id = ? AND status = 'draft'", [$id])
            ?? throw new ValidationException('Only draft threads can be changed. Published threads are kept exactly as they were published.');
    }
}
