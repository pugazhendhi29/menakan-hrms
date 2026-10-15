<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;

final class AccessControlTest extends FeatureTestCase
{
    private function routes(callable $filter): array
    {
        return array_values(array_filter($this->kernel->router()->routes(), $filter));
    }

    public function testGuestsAreSentToSignInFromEveryProtectedPage(): void
    {
        $routes = $this->routes(fn ($r) => $r['method'] === 'GET' && in_array('auth', $r['middleware'], true));
        $this->assertGreaterThan(50, count($routes));
        foreach ($routes as $r) {
            $res = $this->get($this->sampleUrl($r['pattern']));
            $this->assertSame(302, $res->status, $r['pattern']);
            $this->assertStringEndsWith('/login', $res->header('Location'), $r['pattern']);
        }
    }

    public function testEmployeesGet403OnEveryAdminRoute(): void
    {
        $this->actingAs(self::$f['userA']);
        $routes = $this->routes(fn ($r) => in_array('role:admin', $r['middleware'], true));
        $this->assertGreaterThan(70, count($routes));
        foreach ($routes as $r) {
            $url = $this->sampleUrl($r['pattern']);
            $res = $r['method'] === 'GET' ? $this->get($url) : $this->post($url, ['id' => '1']);
            $this->assertSame(403, $res->status, $r['method'] . ' ' . $r['pattern']);
        }
    }

    public function testAdminOnlyAccountWithoutEmployeeRecordCannotUseSelfService(): void
    {
        $this->actingAs(self::$f['admin2']);
        $this->assertSame(403, $this->get('/my')->status);
        $this->assertSame(200, $this->get('/dashboard')->status);
    }

    public function testSelfServiceRoutesNeverTakeAnEmployeeId(): void
    {
        foreach ($this->routes(fn ($r) => str_starts_with($r['pattern'], '/my')) as $r) {
            $this->assertStringNotContainsString('employee', $r['pattern']);
        }
    }

    public function testEveryPostIsCsrfProtected(): void
    {
        $this->actingAs(self::$f['admin']);
        foreach ($this->routes(fn ($r) => $r['method'] === 'POST') as $r) {
            $this->assertSame(419, $this->post($this->sampleUrl($r['pattern']), [], false)->status, $r['pattern']);
        }
    }

    public function testEmployeeCannotOpenOtherPeoplesPayslipsOrDrafts(): void
    {
        $other = DB::insert('payslips', ['employee_id' => self::$f['empB'], 'period_year' => 2026, 'period_month' => 9, 'original_filename' => 'b.pdf',
            'storage_key' => $this->storedPdf(), 'sha256' => str_repeat('a', 64), 'size_bytes' => 10, 'status' => 'published', 'is_current' => 1]);
        $draft = DB::insert('payslips', ['employee_id' => self::$f['empA'], 'period_year' => 2026, 'period_month' => 9, 'original_filename' => 'a.pdf',
            'storage_key' => $this->storedPdf(), 'sha256' => str_repeat('b', 64), 'size_bytes' => 10, 'status' => 'draft', 'is_current' => 1]);
        $this->actingAs(self::$f['userA']);
        $this->assertSame(404, $this->get("/files/payslip/$other")->status);
        $this->assertSame(404, $this->get("/files/payslip/$draft")->status);
        DB::update('payslips', ['status' => 'published'], 'id = ?', [$draft]);
        $res = $this->get("/files/payslip/$draft");
        $this->assertSame(200, $res->status);
        $this->assertTrue($res->isFile());
        $this->assertSame('application/pdf', $res->header('Content-Type'));
        $this->actingAs(self::$f['admin']);
        $this->assertSame(200, $this->get("/files/payslip/$other")->status);
    }

    public function testEmployeeCannotCancelSomeoneElsesLeave(): void
    {
        $id = DB::insert('leave_requests', ['employee_id' => self::$f['empB'], 'leave_type_id' => self::$f['annual'], 'start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'days' => 1]);
        $this->actingAs(self::$f['userA']);
        $res = $this->post("/my/leave/$id/cancel");
        $this->assertSame(404, $res->status);
        $this->assertSame('pending', DB::value('SELECT status FROM leave_requests WHERE id = ?', [$id]));
    }

    public function testRevokedSessionIsSignedOut(): void
    {
        $this->actingAs(self::$f['userA']);
        $this->assertSame(200, $this->get('/my')->status);
        DB::update('user_sessions', ['revoked_at' => now_utc()], 'user_id = ?', [self::$f['userA']]);
        $res = $this->get('/my');
        $this->assertSame(302, $res->status);
        $this->assertStringEndsWith('/login', $res->header('Location'));
    }

    public function testIdleSessionTimesOut(): void
    {
        $this->actingAs(self::$f['userA']);
        $_SESSION['last_activity'] = time() - 31 * 60;
        $res = $this->get('/my');
        $this->assertSame(302, $res->status);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testDeactivatedUserLosesAccessImmediately(): void
    {
        $this->actingAs(self::$f['userA']);
        DB::update('users', ['is_active' => 0], 'id = ?', [self::$f['userA']]);
        $this->assertSame(302, $this->get('/my')->status);
    }

    public function testPortalPagesHideTheRemovedDates(): void
    {
        DB::insert('payslips', ['employee_id' => self::$f['empA'], 'period_year' => 2026, 'period_month' => 2, 'original_filename' => 'feb.pdf',
            'storage_key' => 'payslips/2026/02/' . str_repeat('c', 32) . '.pdf', 'sha256' => str_repeat('0', 64), 'size_bytes' => 10, 'status' => 'published',
            'published_at' => '2026-03-01 09:00:00', 'uploaded_by' => self::$f['admin']]);
        $this->actingAs(self::$f['userA']);
        $payslips = $this->get('/my/payslips')->body;
        $this->assertStringContainsString('February 2026', $payslips);
        $this->assertStringNotContainsString('Published', $payslips);
        $this->assertStringNotContainsString('01/03/2026', $payslips);
        $account = $this->get('/account/password')->body;
        $this->assertStringContainsString('Change password', $account);
        $this->assertStringNotContainsString('Active sessions', $account);
        $this->assertStringNotContainsString('IP address', $account);
    }

    public function testCompanyLogoAndFaviconAreUsed(): void
    {
        foreach (['/login' => null, '/dashboard' => self::$f['admin']] as $path => $user) {
            if ($user) {
                $this->actingAs($user);
            }
            $body = $this->get($path)->body;
            $this->assertStringContainsString('rel="icon" href="' . url('/favicon.ico') . '"', $body, $path);
            $this->assertStringContainsString('images/favicon-32.png', $body, $path);
            $this->assertStringContainsString('rel="apple-touch-icon"', $body, $path);
            $this->assertStringContainsString('images/logo-mark.png', $body, $path);
            $this->assertStringNotContainsString('favicon.svg', $body, $path);
        }
        foreach (['public/favicon.ico', 'public/assets/images/logo.png', 'public/assets/images/logo-mark.png', 'public/assets/images/favicon-32.png', 'public/assets/images/apple-touch-icon.png'] as $file) {
            $this->assertFileExists(BASE_PATH . '/' . $file);
        }
    }

    public function testMyEmailOpensTheCompanyWebmail(): void
    {
        $url = 'https://ns3-777.333servers.com:2096/';
        foreach ([self::$f['admin'], self::$f['userA']] as $user) {
            $this->actingAs($user);
            $res = $this->get('/my-email');
            $this->assertSame(200, $res->status);
            $this->assertStringContainsString('href="' . $url . '" target="_blank" rel="noopener noreferrer"', $res->body);
            $this->assertStringNotContainsString('type="password"', $res->body);    // no email password is ever asked for here
            $this->assertStringNotContainsString('<iframe', $res->body);            // the webmail server forbids embedding
            $this->assertStringContainsString('bi-envelope-at', $res->body);
        }
        // Sidebar order: directly after the pay items.
        $this->actingAs(self::$f['admin']);
        $nav = $this->get('/dashboard')->body;
        $this->assertTrue(strpos($nav, url('/p60')) < strpos($nav, url('/my-email')) && strpos($nav, url('/my-email')) < strpos($nav, url('/news')));
        $this->actingAs(self::$f['userA']);
        $nav = $this->get('/my')->body;
        $this->assertTrue(strpos($nav, url('/my/p60')) < strpos($nav, url('/my-email')) && strpos($nav, url('/my-email')) < strpos($nav, url('/my/id-card')));
        // Guests are sent to sign in; a non-https setting is never linked.
        $_SESSION = [];
        \App\Core\Auth::reset();
        $this->assertSame(302, $this->get('/my-email')->status);
        $saved = \App\Core\Config::get('app.webmail_url');
        \App\Core\Config::set('app.webmail_url', 'javascript:alert(1)');
        try {
            $this->actingAs(self::$f['userA']);
            $body = $this->get('/my-email')->body;
            $this->assertStringContainsString('Webmail is not set up yet', $body);
            $this->assertStringNotContainsString('javascript:', $body);
        } finally {
            \App\Core\Config::set('app.webmail_url', $saved);
        }
    }
}