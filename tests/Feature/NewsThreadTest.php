<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\DB;
use App\Core\ValidationException;
use App\Services\NewsThreadService;

final class NewsThreadTest extends FeatureTestCase
{
    private int $office;
    private int $danUser;

    protected function setUp(): void
    {
        parent::setUp();
        // Drivers: Alice (admin's own record) and Bob. Office: Cara.
        $this->office = DB::insert('departments', ['name' => 'Office', 'code' => 'OFF']);
        DB::update('employees', ['department_id' => $this->office], 'id = ?', [self::$f['empB']]);
        // Dan works in the Workshop and takes no part in any thread.
        $workshop = DB::insert('departments', ['name' => 'Workshop', 'code' => 'WSH']);
        $dan = DB::insert('employees', ['employee_code' => \App\Services\EmployeeService::nextCode(), 'first_name' => 'Dan', 'last_name' => 'Mechanic', 'department_id' => $workshop,
            'job_title_id' => self::$f['job'], 'work_pattern_id' => self::$f['pattern'], 'start_date' => '2024-01-01', 'contract_hours' => 37.5, 'status' => 'active']);
        $this->danUser = DB::insert('users', ['username' => 'dan', 'role' => 'employee', 'employee_id' => $dan, 'password_hash' => password_hash('Password12345', PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    private function thread(array $over = []): int
    {
        return NewsThreadService::save(null, $over + ['title' => 'Rota change discussion', 'channel' => 'email', 'summary' => 'How the new rota was agreed.',
            'audience' => 'departments', 'audience_ids' => ['departments' => [self::$f['dept']]]], self::$f['admin']);
    }

    private function message(int $thread, string $when, array $over = []): int
    {
        return NewsThreadService::saveMessage($thread, null, $over + ['occurred_at' => $when, 'sender_type' => 'department', 'sender_id' => ['department' => $this->office],
            'recipient_type' => 'department', 'recipient_ids' => ['department' => [self::$f['dept']]], 'body' => 'Message sent ' . $when], self::$f['admin']);
    }

    public function testRecordsPastMessagesInDateOrderAndPublishesToReceiversAndAudience(): void
    {
        $mailLog = Config::get('app.storage_path') . '/logs/mail.log';
        $mailBefore = is_file($mailLog) ? filesize($mailLog) : 0;
        $id = $this->thread();
        $this->message($id, '2026-03-10T14:05');                        // entered second, took place last
        $this->message($id, '2026-03-02T09:30', ['sender_type' => 'employee', 'sender_id' => ['employee' => self::$f['empA']],
            'recipient_type' => 'employee', 'recipient_ids' => ['employee' => [self::$f['empB'], self::$f['empAdmin']]], 'body' => 'Can we change the rota?']);

        $messages = NewsThreadService::messages($id);
        $this->assertSame(['2026-03-02 09:30:00', '2026-03-10 14:05:00'], array_column($messages, 'occurred_at'));   // GMT in March: UK time = UTC
        $this->assertSame('Bob Driver', $messages[0]['sender_label']);   // no email on file: just the name, never the employee ID
        $this->assertSame('Alice Admin, Cara Courier', $messages[0]['recipient_label']);
        $this->assertSame(['Office', 'Drivers'], [$messages[1]['sender_label'], $messages[1]['recipient_label']]);
        $this->assertSame("Can we change the rota?\n\nBob Driver\nVan Driver", $messages[0]['body']);   // the sender's signature: name and job title
        $this->assertSame("Message sent 2026-03-10T14:05\n\nOffice", $messages[1]['body']);              // a department signs with its name

        NewsThreadService::publish($id, self::$f['admin']);
        $t = DB::one('SELECT * FROM news_threads WHERE id = ?', [$id]);
        $this->assertSame('published', $t['status']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'news_thread.published' AND entity_id = ?", [$id]));
        $this->assertSame($mailBefore, is_file($mailLog) ? filesize($mailLog) : 0);   // records only: nothing is emailed

        // Bob is in Drivers: he sees it, with the original times and the note that HR recorded them.
        $this->actingAs(self::$f['userA']);
        $this->assertStringContainsString('Rota change discussion', $this->get('/my/news')->body);
        $page = $this->get("/my/news/threads/$id");
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('<th scope="row">date:</th><td>2 Mar 2026, 09:30</td>', $page->body);
        $this->assertStringContainsString('<th scope="row">subject:</th><td>Rota change discussion</td>', $page->body);
        $this->assertStringContainsString('Can we change the rota?', $page->body);
        $this->assertStringContainsString('as recorded by HR', $page->body);
        $this->assertStringNotContainsString(from_utc($t['published_at'])->format('j M Y, H:i'), $page->body);   // not when HR published it
        $this->assertStringContainsString('10 Mar 2026, 14:05', $this->get('/my/news')->body);   // the list shows the latest message time
        $this->assertLessThan(strpos($page->body, 'Message sent 2026-03-10T14:05'), strpos($page->body, 'Can we change the rota?'));

        // Cara is in Office, outside the Drivers audience, but she received a message: she sees it too.
        $this->actingAs(self::$f['userB']);
        $this->assertStringContainsString('Rota change discussion', $this->get('/my/news')->body);
        $this->assertSame(200, $this->get("/my/news/threads/$id")->status);

        // Dan neither received a message nor is in the audience.
        $this->actingAs($this->danUser);
        $this->assertStringNotContainsString('Rota change discussion', $this->get('/my/news')->body);
        $this->assertSame(404, $this->get("/my/news/threads/$id")->status);
    }

    public function testReceiversGetTheThreadInMyNewsAndAreNotified(): void
    {
        $id = $this->thread(['audience' => 'receivers']);
        $this->message($id, '2026-04-01T11:00', ['recipient_type' => 'employee', 'recipient_ids' => ['employee' => [self::$f['empB']]], 'body' => 'Your new start time is 06:00.']);
        $this->assertSame([self::$f['empB']], array_map(fn ($r) => (int) $r['id'], NewsThreadService::readers(DB::one('SELECT * FROM news_threads WHERE id = ?', [$id]))));
        NewsThreadService::publish($id, self::$f['admin']);

        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND link = ?', [self::$f['userB'], "/my/news/threads/$id"]));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id IN (?, ?) AND link = ?', [self::$f['userA'], $this->danUser, "/my/news/threads/$id"]));
        $this->actingAs(self::$f['userB']);
        $this->assertStringContainsString('Your new start time is 06:00.', $this->get("/my/news/threads/$id")->body);
        $this->actingAs(self::$f['userA']);
        $this->assertSame(404, $this->get("/my/news/threads/$id")->status);

        // A message to a department reaches its current members; one to everyone reaches all.
        $dept = $this->thread(['title' => 'Office move', 'audience' => 'receivers']);
        $this->message($dept, '2026-04-02T09:00', ['recipient_type' => 'department', 'recipient_ids' => ['department' => [$this->office]]]);
        $row = DB::one('SELECT * FROM news_threads WHERE id = ?', [$dept]);
        $this->assertSame([self::$f['empB']], array_map(fn ($r) => (int) $r['id'], NewsThreadService::readers($row)));
        $this->message($dept, '2026-04-03T09:00', ['recipient_type' => 'all', 'recipient_ids' => []]);
        $this->assertCount((int) DB::value("SELECT COUNT(*) FROM employees WHERE deleted_at IS NULL AND status <> 'left'"), NewsThreadService::readers($row));
    }

    public function testAnEmployeeWhoWritesToAnotherSeesTheThreadToo(): void
    {
        $id = $this->thread(['title' => 'Shift swap', 'audience' => 'receivers']);
        $this->message($id, '2026-05-06T07:15', ['sender_type' => 'employee', 'sender_id' => ['employee' => self::$f['empA']],
            'recipient_type' => 'employee', 'recipient_ids' => ['employee' => [self::$f['empB']]], 'body' => 'Can you swap Friday with me?']);
        NewsThreadService::publish($id, self::$f['admin']);

        foreach ([self::$f['userA'] => 'Bob (sender)', self::$f['userB'] => 'Cara (receiver)'] as $user => $who) {
            $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND link = ?', [$user, "/my/news/threads/$id"]), $who);
            $this->actingAs($user);
            $this->assertStringContainsString('Shift swap', $this->get('/my/news')->body, $who);
            $this->assertStringContainsString('Can you swap Friday with me?', $this->get("/my/news/threads/$id")->body, $who);
        }
        $this->actingAs($this->danUser);
        $this->assertSame(404, $this->get("/my/news/threads/$id")->status);
    }

    public function testMessageRules(): void
    {
        $id = $this->thread();
        $errors = function (array $over) use ($id): array {
            try {
                $this->message($id, $over['occurred_at'] ?? '2026-01-05T10:00', $over);
            } catch (ValidationException $e) {
                return array_keys($e->errors);
            }
            return [];
        };
        $this->assertSame(['occurred_at'], $errors(['occurred_at' => uk_now()->modify('+1 day')->format('Y-m-d\TH:i')]));
        $this->assertSame(['occurred_at'], $errors(['occurred_at' => '2026-02-30T10:00']));
        $this->assertSame(['sender_id'], $errors(['sender_id' => ['department' => 999999]]));
        $this->assertSame(['sender_type'], $errors(['sender_type' => 'robot']));
        $this->assertSame(['recipient_ids'], $errors(['recipient_ids' => ['department' => []]]));
        $this->assertSame(['body'], $errors(['body' => '  ']));
        $this->assertSame([], $errors(['recipient_type' => 'all', 'recipient_ids' => []]));
        $this->assertSame('Everyone', DB::value('SELECT recipient_label FROM news_thread_messages WHERE thread_id = ?', [$id]));
    }

    public function testPublishedMessagesAreLockedButRepliesCanBeAdded(): void
    {
        $id = $this->thread();
        try {
            NewsThreadService::publish($id, self::$f['admin']);
            $this->fail('A thread without messages was published.');
        } catch (ValidationException) {
        }
        $mid = $this->message($id, '2026-02-01T08:00');
        NewsThreadService::publish($id, self::$f['admin']);

        foreach ([
            fn () => NewsThreadService::saveMessage($id, $mid, ['body' => 'changed'], self::$f['admin']),
            fn () => NewsThreadService::deleteMessage($id, $mid, self::$f['admin']),
            fn () => NewsThreadService::save($id, ['title' => 'x', 'channel' => 'email', 'audience' => 'all'], self::$f['admin']),
            fn () => NewsThreadService::withdraw($id, ' ', self::$f['admin']),
        ] as $i => $action) {
            try {
                $action();
                $this->fail("Action $i was allowed on a published thread.");
            } catch (ValidationException) {
            }
        }
        $this->assertStringStartsWith('Message sent 2026-02-01T08:00', DB::value('SELECT body FROM news_thread_messages WHERE id = ?', [$mid]));

        // An admin can still reply, with a sender and a date and time of their choosing; readers are notified.
        $reply = $this->message($id, '2026-02-03T10:30', ['recipient_type' => 'thread', 'recipient_ids' => [], 'body' => 'Agreed.']);
        $this->assertSame(['2026-02-03 10:30:00', 1, 'Everyone in this thread'], [DB::value('SELECT occurred_at FROM news_thread_messages WHERE id = ?', [$reply]),
            (int) DB::value('SELECT custom_time FROM news_thread_messages WHERE id = ?', [$reply]), DB::value('SELECT recipient_label FROM news_thread_messages WHERE id = ?', [$reply])]);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title LIKE 'New reply in:%'", [self::$f['userA']]));

        NewsThreadService::withdraw($id, 'Shared with the wrong team', self::$f['admin']);
        $this->assertSame('withdrawn', DB::value('SELECT status FROM news_threads WHERE id = ?', [$id]));
        $this->actingAs(self::$f['userA']);
        $this->assertSame(404, $this->get("/my/news/threads/$id")->status);
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM news_thread_messages WHERE thread_id = ?', [$id]));   // kept for the record
        $this->expectException(ValidationException::class);
        $this->message($id, '2026-02-04T10:30');   // no replies once withdrawn
    }

    public function testAdminCanDeleteAnyThread(): void
    {
        $id = $this->thread(['title' => 'Old rota', 'audience' => 'receivers']);
        $mid = $this->message($id, '2026-03-01T09:00', ['recipient_type' => 'employee', 'recipient_ids' => ['employee' => [self::$f['empA']]]]);
        NewsThreadService::publish($id, self::$f['admin']);

        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString("/news/threads/$id/delete", $this->get('/news/threads')->body);   // a delete button on the list
        $this->assertStringContainsString("/news/threads/$id/delete", $this->get("/news/threads/$id")->body);
        $res = $this->post("/news/threads/$id/delete");
        $this->assertStringEndsWith('/news/threads', $res->header('Location'));
        $this->assertNull(DB::value('SELECT id FROM news_threads WHERE id = ?', [$id]));
        $this->assertNull(DB::value('SELECT id FROM news_thread_messages WHERE id = ?', [$mid]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'news_thread.deleted' AND entity_id = ? AND summary LIKE '%published thread \"Old rota\" with 1 message%'", [$id]));

        // Gone from the employee's News too.
        $this->actingAs(self::$f['userA']);
        $this->assertStringNotContainsString('Old rota', $this->get('/my/news')->body);
        $this->assertSame(404, $this->get("/my/news/threads/$id")->status);

        // Employees cannot delete.
        $other = $this->thread(['title' => 'Keep me']);
        $this->assertSame(403, $this->post("/news/threads/$other/delete")->status);
        $this->assertNotNull(DB::value('SELECT id FROM news_threads WHERE id = ?', [$other]));
    }

    public function testEmployeesSendThreadsAtTheCurrentTimeAndCannotChooseSenderOrDate(): void
    {
        DB::update('employees', ['email' => 'bob@example.com'], 'id = ?', [self::$f['empA']]);
        $this->actingAs(self::$f['userA']);
        $this->assertSame(200, $this->get('/my/news/threads/create')->status);
        $before = now_utc();
        // Bob tries to backdate the message and send it as Cara: both are ignored.
        $res = $this->post('/my/news/threads', ['title' => 'Van 12 brakes', 'recipient_type' => 'employee', 'recipient_ids' => ['employee' => [self::$f['empB']]],
            'body' => 'The brakes on van 12 feel soft.', 'occurred_at' => '2024-01-01T09:00', 'sender_type' => 'employee', 'sender_id' => ['employee' => self::$f['empB']]]);
        $this->assertSame(302, $res->status);
        $t = DB::one("SELECT * FROM news_threads WHERE title = 'Van 12 brakes'");
        $this->assertSame(['published', 'portal', 'receivers'], [$t['status'], $t['channel'], $t['audience']]);
        $m = DB::one('SELECT * FROM news_thread_messages WHERE thread_id = ?', [$t['id']]);
        $this->assertSame([(int) self::$f['empA'], 'Bob Driver <bob@example.com>', 0], [(int) $m['sender_id'], $m['sender_label'], (int) $m['custom_time']]);
        $this->assertSame("The brakes on van 12 feel soft.\n\nBob Driver\nVan Driver", $m['body']);
        $this->assertGreaterThanOrEqual(substr($before, 0, 16), substr($m['occurred_at'], 0, 16));   // stamped now, not 2024
        $this->assertStringEndsWith('/my/news/threads/' . $t['id'], $res->header('Location'));

        // Cara gets it and a notification; Bob is not notified of his own message. No "recorded by HR" note on a live thread.
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND link = ?', [self::$f['userB'], '/my/news/threads/' . $t['id']]));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND link = ?', [self::$f['userA'], '/my/news/threads/' . $t['id']]));
        $this->actingAs(self::$f['userB']);
        $page = $this->get('/my/news/threads/' . $t['id'])->body;
        $this->assertStringContainsString('The brakes on van 12 feel soft.', $page);
        $this->assertStringNotContainsString('as recorded by HR', $page);

        // Cara replies; her reply also gets the current time, and Bob is notified.
        $this->post('/my/news/threads/' . $t['id'] . '/reply', ['body' => 'Booked into the workshop.', 'occurred_at' => '2024-01-01T09:00']);
        $r = DB::one('SELECT * FROM news_thread_messages WHERE thread_id = ? ORDER BY id DESC LIMIT 1', [$t['id']]);
        $this->assertSame([(int) self::$f['empB'], 'thread', 0], [(int) $r['sender_id'], $r['recipient_type'], (int) $r['custom_time']]);
        $this->assertGreaterThanOrEqual(substr($before, 0, 16), substr($r['occurred_at'], 0, 16));
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'New reply in: Van 12 brakes'", [self::$f['userA']]));

        // Dan is not in the thread: he cannot read or reply.
        $this->actingAs($this->danUser);
        $this->assertSame(404, $this->get('/my/news/threads/' . $t['id'])->status);
        $this->assertSame(404, $this->post('/my/news/threads/' . $t['id'] . '/reply', ['body' => 'Hi'])->status);
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM news_thread_messages WHERE thread_id = ?', [$t['id']]));
    }

    public function testEmployeesCannotSendToEveryone(): void
    {
        $this->actingAs(self::$f['userA']);
        $this->post('/my/news/threads', ['title' => 'Party', 'recipient_type' => 'all', 'body' => 'Party on Friday!']);
        $this->assertNull(DB::value("SELECT id FROM news_threads WHERE title = 'Party'"));
        $this->post('/my/news/threads', ['title' => 'Party', 'recipient_type' => 'department', 'recipient_ids' => ['department' => [$this->office]], 'body' => 'Party on Friday!']);
        $this->assertNotNull(DB::value("SELECT id FROM news_threads WHERE title = 'Party'"));
        try {
            NewsThreadService::startFromPortal(self::$f['empA'], ['title' => 'x', 'recipient_type' => 'thread', 'body' => 'y'], self::$f['userA']);
            $this->fail('A new thread was sent to "everyone in this thread".');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recipient_type', $e->errors);
        }
    }

    public function testAdminComposesAnEmailThatIsPublishedStraightAway(): void
    {
        $this->actingAs(self::$f['admin']);
        $form = $this->get('/news/threads/create')->body;
        $this->assertStringContainsString('New email', $form);
        $this->assertStringContainsString("data-signature=\"Bob Driver
Van Driver\"", $form);   // signature preview data: name and job title

        DB::update('employees', ['email' => 'alice@example.com'], 'id = ?', [self::$f['empAdmin']]);
        $res = $this->post('/news/threads', ['sender_type' => 'employee', 'sender_id' => ['employee' => self::$f['empAdmin'], 'department' => ''],
            'recipient_type' => 'department', 'recipient_ids' => ['department' => [$this->office]], 'occurred_at' => '2025-11-20T16:45',
            'subject' => 'Depot move', 'body' => 'The depot moves in January.']);
        $this->assertSame(302, $res->status);
        $t = DB::one("SELECT * FROM news_threads WHERE title = 'Depot move'");
        $this->assertSame(['published', 'email', 'receivers'], [$t['status'], $t['channel'], $t['audience']]);
        $this->assertStringEndsWith('/news/threads/' . $t['id'], $res->header('Location'));
        $m = DB::one('SELECT * FROM news_thread_messages WHERE thread_id = ?', [$t['id']]);
        $this->assertSame(['2025-11-20 16:45:00', 'Alice Admin <alice@example.com>', 'Office', 1],
            [$m['occurred_at'], $m['sender_label'], $m['recipient_label'], (int) $m['custom_time']]);
        $this->assertSame("The depot moves in January.\n\nAlice Admin\nVan Driver", $m['body']);

        // The receiver has it in My News straight away and was notified.
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND link = ?', [self::$f['userB'], '/my/news/threads/' . $t['id']]));
        $this->actingAs(self::$f['userB']);
        $this->assertStringContainsString('The depot moves in January.', $this->get('/my/news/threads/' . $t['id'])->body);

        // Nothing is published when something is missing.
        $this->actingAs(self::$f['admin']);
        $this->post('/news/threads', ['sender_type' => 'department', 'sender_id' => ['department' => $this->office], 'recipient_type' => 'employee', 'recipient_ids' => ['employee' => []],
            'occurred_at' => '2025-11-20T16:45', 'subject' => 'Incomplete', 'body' => 'x']);
        $this->post('/news/threads', ['sender_type' => 'department', 'sender_id' => ['department' => $this->office], 'recipient_type' => 'all',
            'occurred_at' => '2025-11-20T16:45', 'subject' => '', 'body' => 'x']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM news_threads WHERE title IN ('Incomplete', '')"));
    }

    public function testAdminScreens(): void
    {
        $this->actingAs(self::$f['admin']);
        $id = $this->thread(['title' => 'Depot move', 'channel' => 'meeting', 'audience' => 'all']);
        $this->assertSame(200, $this->get("/news/threads/$id/edit")->status);

        DB::update('employees', ['email' => 'alice@example.com'], 'id = ?', [self::$f['empAdmin']]);
        $this->post("/news/threads/$id/messages", ['occurred_at' => '2025-11-20T16:45', 'sender_type' => 'employee', 'sender_id' => ['employee' => self::$f['empAdmin'], 'department' => ''],
            'recipient_type' => 'department', 'recipient_ids' => ['department' => [self::$f['dept'], $this->office]], 'subject' => 'Depot move: dates', 'body' => 'The depot moves in January.']);
        $mid = (int) DB::value('SELECT id FROM news_thread_messages WHERE thread_id = ?', [$id]);
        $this->assertSame('2025-11-20 16:45:00', DB::value('SELECT occurred_at FROM news_thread_messages WHERE id = ?', [$mid]));
        $this->assertSame('Drivers, Office', DB::value('SELECT recipient_label FROM news_thread_messages WHERE id = ?', [$mid]));

        $show = $this->get("/news/threads/$id")->body;
        $this->assertStringContainsString('20 Nov 2025, 16:45', $show);
        $this->assertStringContainsString('<td>Alice Admin &lt;alice@example.com&gt;</td>', $show);
        $this->assertStringContainsString('Depot move: dates', $show);
        $this->assertStringNotContainsString('Recorded', $show);
        $this->assertStringNotContainsString('Recorded', $this->get('/news/threads')->body);
        $this->assertSame(200, $this->get("/news/threads/$id/messages/$mid/edit")->status);
        $this->assertStringContainsString('Depot move', $this->get('/news/threads')->body);
        $this->assertStringContainsString('/news/threads', $this->get('/news')->body);

        $this->post("/news/threads/$id/publish");
        $this->assertSame('published', DB::value('SELECT status FROM news_threads WHERE id = ?', [$id]));
        $this->assertSame(302, $this->get("/news/threads/$id/edit")->status);
        $this->assertSame(302, $this->get("/news/threads/$id/messages/$mid/edit")->status);
    }
}
