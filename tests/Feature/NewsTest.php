<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\DB;
use App\Core\ValidationException;
use App\Services\NewsService;

final class NewsTest extends FeatureTestCase
{
    private function mailLog(): string
    {
        return Config::get('app.storage_path') . '/logs/mail.log';
    }

    private function draft(array $over = []): int
    {
        return NewsService::save(null, $over + ['title' => 'Depot closure', 'subject' => 'Depot closed on {employee_id} day', 'body' => "Dear {first_name},\n\nThe depot is closed on Friday.",
            'audience' => 'all'], self::$f['admin']);
    }

    private function withEmails(): void
    {
        DB::update('employees', ['email' => 'alice@example.com'], 'id = ?', [self::$f['empAdmin']]);
        DB::update('employees', ['email' => 'bob@example.com'], 'id = ?', [self::$f['empA']]);
        DB::update('employees', ['email' => 'cara@example.com'], 'id = ?', [self::$f['empB']]);
    }

    public function testSendsOneMessagePerRecipientFromTheFixedSender(): void
    {
        $this->withEmails();
        $before = is_file($this->mailLog()) ? filesize($this->mailLog()) : 0;
        $id = $this->draft();
        $r = NewsService::send($id, self::$f['admin']);
        $this->assertSame(['sent', 3, 0], [$r['status'], $r['sent'], $r['failed']]);
        $log = substr((string) file_get_contents($this->mailLog()), $before);
        $entries = array_values(array_filter(array_map('trim', preg_split('/^==== .* ====$/m', $log))));
        $this->assertCount(3, $entries);
        foreach ($entries as $entry) {
            $this->assertStringContainsString('From: Menakan Transport HR Department <hr@menakantransport.com>', $entry);
            $this->assertSame(1, preg_match_all('/^To: /m', $entry));            // one address per message, no CC/BCC lists
            $this->assertStringContainsString('Menakan Transport Ltd | Human Resources Department', $entry);
        }
        $bob = current(array_filter($entries, fn ($e) => str_contains($e, 'To: bob@example.com')));
        $this->assertStringContainsString('Dear Bob,', $bob);
        $this->assertStringNotContainsString('cara@example.com', $bob);         // recipients never see each other
        $this->assertStringContainsString('Subject: Depot closed on ' . DB::value('SELECT employee_code FROM employees WHERE id = ?', [self::$f['empA']]), $bob);
        $post = DB::one('SELECT * FROM news_posts WHERE id = ?', [$id]);
        $this->assertSame(['sent', 3, 3, 1], [$post['status'], (int) $post['recipients_total'], (int) $post['sent_count'], (int) $post['test_mode']]);
        $this->assertSame(3, (int) DB::value("SELECT COUNT(*) FROM news_deliveries WHERE news_id = ? AND status = 'logged'", [$id]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'news.sent' AND entity_id = ?", [$id]));
        // Sent announcements are locked.
        $this->expectException(ValidationException::class);
        NewsService::send($id, self::$f['admin']);
    }

    public function testSenderCannotBeChangedFromTheInterface(): void
    {
        $this->withEmails();
        $this->actingAs(self::$f['admin']);
        $before = is_file($this->mailLog()) ? filesize($this->mailLog()) : 0;
        $this->post('/news', ['title' => 'T', 'subject' => 'S', 'body' => 'B', 'audience' => 'employees', 'audience_ids' => ['employees' => [self::$f['empA']]],
            'from' => 'boss@evil.example', 'from_name' => 'Someone else', 'sender' => 'x@y.z', 'intent' => 'send']);
        $log = substr((string) file_get_contents($this->mailLog()), $before);
        $this->assertStringContainsString('From: Menakan Transport HR Department <hr@menakantransport.com>', $log);
        $this->assertStringNotContainsString('evil.example', $log);
        $this->assertStringNotContainsString('name="from', $this->get('/news/create')->body);
        $this->assertStringNotContainsString('news_sender', $this->get('/settings')->body);
    }

    public function testAudiences(): void
    {
        $this->withEmails();
        $dept2 = DB::insert('departments', ['name' => 'Office', 'code' => 'OFF']);
        DB::update('employees', ['department_id' => $dept2], 'id = ?', [self::$f['empB']]);
        DB::update('employees', ['line_manager_id' => self::$f['empAdmin']], 'id = ?', [self::$f['empA']]);
        $ids = fn (array $post) => array_map(fn ($r) => (int) $r['id'], NewsService::recipients($post));
        $this->assertSame([self::$f['empB']], $ids(['audience' => 'departments', 'audience_ids' => (string) $dept2]));
        $this->assertEqualsCanonicalizing([self::$f['empAdmin'], self::$f['empA']], $ids(['audience' => 'teams', 'audience_ids' => (string) self::$f['empAdmin']]));
        $this->assertSame([self::$f['empB']], $ids(['audience' => 'employees', 'audience_ids' => (string) self::$f['empB']]));
        // People who have left or are archived never receive News.
        DB::update('employees', ['status' => 'left'], 'id = ?', [self::$f['empB']]);
        $this->assertNotContains(self::$f['empB'], $ids(['audience' => 'all', 'audience_ids' => null]));
        $this->assertSame([], $ids(['audience' => 'departments', 'audience_ids' => (string) $dept2]));
        // A targeted audience needs at least one choice.
        $this->expectException(ValidationException::class);
        $this->draft(['audience' => 'departments', 'audience_ids' => []]);
    }

    public function testDraftScheduleAndScheduledSend(): void
    {
        $this->withEmails();
        $id = $this->draft();
        try {
            NewsService::schedule($id, '2020-01-01T09:00', self::$f['admin']);
            $this->fail('Past schedule must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('scheduled_at', $e->errors);
        }
        NewsService::schedule($id, uk_now()->modify('+2 days')->format('Y-m-d\TH:i'), self::$f['admin']);
        $this->assertSame('scheduled', DB::value('SELECT status FROM news_posts WHERE id = ?', [$id]));
        $this->assertSame(0, NewsService::sendDue());                          // not due yet
        DB::update('news_posts', ['scheduled_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = ?', [$id]);
        $this->assertSame(1, NewsService::sendDue());
        $this->assertSame('sent', DB::value('SELECT status FROM news_posts WHERE id = ?', [$id]));
        // Editing a scheduled post puts it back to draft; drafts can be deleted, sent posts cannot.
        $d = $this->draft(['title' => 'Second']);
        NewsService::schedule($d, uk_now()->modify('+1 day')->format('Y-m-d\TH:i'), self::$f['admin']);
        NewsService::save($d, ['title' => 'Second v2', 'subject' => 'S', 'body' => 'B', 'audience' => 'all'], self::$f['admin']);
        $this->assertSame(['draft', null], array_values(DB::one('SELECT status, scheduled_at FROM news_posts WHERE id = ?', [$d])));
        NewsService::delete($d, self::$f['admin']);
        $this->assertNull(DB::value('SELECT id FROM news_posts WHERE id = ?', [$d]));
        $this->expectException(ValidationException::class);
        NewsService::delete($id, self::$f['admin']);
    }

    public function testSmtpFailureIsShownLoggedAndNothingIsMarkedSent(): void
    {
        $this->withEmails();
        $id = $this->draft();
        $saved = Config::get('app.mail');
        // A real SMTP configuration pointing at a closed port: the sign-in cannot succeed.
        Config::set('app.mail', ['driver' => 'smtp', 'from' => 'hr@menakantransport.com', 'from_name' => 'x', 'smtp_host' => '127.0.0.1', 'smtp_port' => 1,
            'smtp_username' => 'hr@menakantransport.com', 'smtp_password' => 'wrong', 'smtp_encryption' => 'none']);
        $newsLog = Config::get('app.storage_path') . '/logs/news-mail.log';
        $before = is_file($newsLog) ? filesize($newsLog) : 0;
        try {
            $this->actingAs(self::$f['admin']);
            $res = $this->post("/news/$id/send");
            $this->assertSame(302, $res->status);
            $page = $this->get("/news/$id")->body;
        } finally {
            Config::set('app.mail', $saved);
        }
        $post = DB::one('SELECT * FROM news_posts WHERE id = ?', [$id]);
        $this->assertSame(['failed', 0], [$post['status'], (int) $post['sent_count']]);
        $this->assertNotEmpty($post['last_error']);
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM news_deliveries WHERE news_id = ? AND status IN ('sent','logged')", [$id]));
        $this->assertSame(3, (int) DB::value("SELECT COUNT(*) FROM news_deliveries WHERE news_id = ? AND status = 'failed'", [$id]));
        $this->assertStringContainsString('was not sent', $page);
        $this->assertStringContainsString('Retry failed', $page);
        $this->assertStringContainsString('Sender check failed', substr((string) file_get_contents($newsLog), $before));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'news.send_failed' AND entity_id = ?", [$id]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'news.sender_check_failed'"));
        // Once mail works again, a retry delivers to the failed recipients.
        $r = NewsService::send($id, self::$f['admin']);
        $this->assertSame(['sent', 3], [$r['status'], $r['sent']]);
    }

    public function testEmployeesWithoutEmailAreSkippedAndListed(): void
    {
        $this->withEmails();
        DB::update('employees', ['email' => null], 'id = ?', [self::$f['empB']]);
        $id = $this->draft();
        $r = NewsService::send($id, self::$f['admin']);
        $this->assertSame(['sent', 2, 1], [$r['status'], $r['sent'], $r['skipped']]);
        $this->assertStringContainsString('No valid email address', (string) DB::value("SELECT error FROM news_deliveries WHERE news_id = ? AND status = 'skipped'", [$id]));
    }

    public function testEmployeeSeesOnlyAnnouncementsSentToThem(): void
    {
        $this->withEmails();
        $mine = $this->draft(['title' => 'For Bob', 'audience' => 'employees', 'audience_ids' => [self::$f['empA']]]);
        $other = $this->draft(['title' => 'For Cara', 'audience' => 'employees', 'audience_ids' => [self::$f['empB']]]);
        NewsService::send($mine, self::$f['admin']);
        NewsService::send($other, self::$f['admin']);
        $draft = $this->draft(['title' => 'Unsent draft']);
        $this->actingAs(self::$f['userA']);
        $list = $this->get('/my/news')->body;
        $this->assertStringContainsString('For Bob', $list);
        $this->assertStringNotContainsString('For Cara', $list);
        $this->assertStringNotContainsString('Unsent draft', $list);
        $this->assertStringContainsString('Dear Bob,', $this->get("/my/news/$mine")->body);
        $this->assertSame(404, $this->get("/my/news/$other")->status);
        $this->assertSame(404, $this->get("/my/news/$draft")->status);
        $this->assertSame(403, $this->get('/news')->status);
        $this->assertSame(403, $this->post("/news/$draft/send")->status);
    }

    public function testTemplatesPrefillAndAreAudited(): void
    {
        $this->actingAs(self::$f['admin']);
        $this->post('/news/templates', ['name' => 'Payday notice', 'subject' => 'Payday is moving', 'body' => 'Dear {first_name}, payday moves.']);
        $tid = (int) DB::value("SELECT id FROM news_templates WHERE name = 'Payday notice'");
        $this->assertGreaterThan(0, $tid);
        $form = $this->get('/news/create', ['template' => (string) $tid])->body;
        $this->assertStringContainsString('Payday is moving', $form);
        $this->assertStringContainsString('Dear {first_name}, payday moves.', $form);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'news_template.created' AND entity_id = ?", [$tid]));
        $this->post('/news/templates', ['name' => 'Payday notice', 'subject' => 'x', 'body' => 'y']);   // duplicate name
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM news_templates WHERE name = 'Payday notice'"));
        $this->assertStringContainsString('From: <b>Menakan Transport HR Department &lt;hr@menakantransport.com&gt;</b>', $form);
    }
}
