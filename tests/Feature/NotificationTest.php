<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Services\Notifier;

/** Notifications (the bell) are for admins only: they are turned off for employees. */
final class NotificationTest extends FeatureTestCase
{
    public function testEmployeesGetNoNotificationsAndNoBell(): void
    {
        Notifier::toEmployee(self::$f['empA'], 'leave', 'Leave approved');
        Notifier::toUser(self::$f['userA'], 'news', 'New in News: test');
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [self::$f['userA']]));

        $this->actingAs(self::$f['userA']);
        $this->assertStringNotContainsString('class="bell"', $this->get('/my')->body);
        $this->assertSame(403, $this->get('/notifications')->status);
    }

    public function testAdminsStillGetThem(): void
    {
        Notifier::toAdmins('leave', 'Leave request from Bob');
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [self::$f['admin']]));
        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('class="bell"', $this->get('/dashboard')->body);
        $this->assertStringContainsString('Leave request from Bob', $this->get('/notifications')->body);
    }
}
