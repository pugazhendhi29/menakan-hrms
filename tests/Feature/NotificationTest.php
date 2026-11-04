<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Services\Notifier;

/**
 * Notifications (the bell): admins get every kind; employees only get management report notifications
 * (type "report_..."), everything else stays turned off for them.
 */
final class NotificationTest extends FeatureTestCase
{
    public function testEmployeesOnlyGetManagementReportNotifications(): void
    {
        Notifier::toEmployee(self::$f['empA'], 'leave', 'Leave approved');
        Notifier::toUser(self::$f['userA'], 'news', 'New in News: test');
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [self::$f['userA']]));

        Notifier::toUser(self::$f['userA'], 'report_approved', 'Your daily report was approved');
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [self::$f['userA']]));

        $this->actingAs(self::$f['userA']);
        $this->assertStringContainsString('class="bell"', $this->get('/my')->body);
        $page = $this->get('/notifications');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Your daily report was approved', $page->body);
        $this->assertStringNotContainsString('Leave approved', $page->body);
    }

    public function testAdminsStillGetThem(): void
    {
        Notifier::toAdmins('leave', 'Leave request from Bob');
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [self::$f['admin']]));
        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('class="bell"', $this->get('/dashboard')->body);
        $this->assertStringContainsString('Leave request from Bob', $this->get('/notifications')->body);
    }

    public function testNobodyOpensSomeoneElsesNotification(): void
    {
        Notifier::toUser(self::$f['admin'], 'leave', 'For the admin');
        $id = (int) DB::value('SELECT id FROM notifications WHERE user_id = ?', [self::$f['admin']]);
        $this->actingAs(self::$f['userA']);
        $this->assertSame(404, $this->get("/notifications/$id/open")->status);
    }
}
