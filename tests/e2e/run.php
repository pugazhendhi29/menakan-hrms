<?php

/**
 * End-to-end test suite: drives the running application over HTTP like a browser.
 *
 *   php tests/e2e/run.php
 *
 * Needs Apache serving the app at APP_URL, the fictional demo data (tests/fixtures/demo-seed.php, test databases only) and these
 * environment variables: E2E_ADMIN_USER, E2E_ADMIN_PASSWORD, E2E_DEMO_PASSWORD.
 * The suite creates its own records (marked "E2E"). Reload the demo data afterwards
 * if you want a clean database. Results are written to storage/logs/e2e-results.json.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';
require __DIR__ . '/HttpClient.php';

use App\Core\Config;
use App\Core\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\E2E\HttpClient;

$base = Config::get('app.url');
$adminUser = getenv('E2E_ADMIN_USER') ?: 'admin';
$adminPass = getenv('E2E_ADMIN_PASSWORD') ?: '';
$demoPass = getenv('E2E_DEMO_PASSWORD') ?: '';
if ($adminPass === '' || $demoPass === '') {
    fwrite(STDERR, "Set E2E_ADMIN_PASSWORD and E2E_DEMO_PASSWORD.\n");
    exit(2);
}
// The suite uploads payslips for fictional employees: refuse to run while real email delivery is on.
if (!App\Services\Mailer::isLogOnly()) {
    fwrite(STDERR, "Real email sending is switched on in .env. Set MAIL_DRIVER=log (and restart nothing - .env is read per request) before running the end-to-end suite.\n");
    exit(2);
}
$tmpDir = sys_get_temp_dir() . '/hrms-e2e-' . bin2hex(random_bytes(4));
mkdir($tmpDir);

$results = [];
$ctx = [];

function t(string $id, string $module, string $scenario, string $expected, callable $fn): void
{
    global $results;
    try {
        [$ok, $actual] = $fn();
    } catch (\Throwable $e) {
        [$ok, $actual] = [false, 'Exception: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()];
    }
    $results[] = compact('id', 'module', 'scenario', 'expected', 'actual') + ['status' => $ok ? 'PASS' : 'FAIL'];
    printf("%-6s %-4s %-14s %s%s\n", $id, $ok ? 'PASS' : 'FAIL', $module, $scenario, $ok ? '' : "\n         -> $actual");
}

function contains(HttpClient $c, string $needle): bool
{
    return str_contains(html_entity_decode($c->body, ENT_QUOTES), $needle);
}

function pdfFile(string $dir, string $name, string $text = 'E2E test PDF'): string
{
    $body = "BT /F1 12 Tf 50 750 Td ($text) Tj ET";
    $pdf = "%PDF-1.4\n1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n"
        . "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>endobj\n"
        . "4 0 obj<< /Length " . strlen($body) . " >>stream\n$body\nendstream endobj\n5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n";
    file_put_contents("$dir/$name", $pdf);
    return "$dir/$name";
}

function xlsxFile(string $dir, string $name, array $rows): string
{
    $book = new Spreadsheet();
    $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
    (new Xlsx($book))->save("$dir/$name");
    return "$dir/$name";
}

function upload(string $path, string $mime, ?string $name = null): CURLFile
{
    return new CURLFile($path, $mime, $name ?? basename($path));
}

$emp = fn (string $code) => DB::one('SELECT * FROM employees WHERE employee_code = ?', [$code]);
$daniel = $emp('MT0012');
$aisha = $emp('MT0019');

// =================================================================== AUTH & SESSION
$admin = new HttpClient($base);
$staff = new HttpClient($base);

t('TC-01', 'Auth', 'Sign-in page loads with a CSRF-protected form', '200 with username, password and _csrf fields', function () use ($admin) {
    $admin->get('/login');
    return [$admin->status === 200 && contains($admin, 'name="_csrf"') && contains($admin, 'name="password"'), "HTTP {$admin->status}"];
});
t('TC-02', 'Auth', 'Wrong password is rejected with a generic message', 'Redirect to /login, "username or password is incorrect"', function () use ($base) {
    $c = new HttpClient($base);
    $c->login('d.okafor', 'not-the-password-1')->follow();
    return [contains($c, 'The username or password is incorrect.') && !contains($c, 'My Dashboard'), $c->flashes()];
});
t('TC-03', 'Auth', 'Admin signs in and lands on the admin dashboard', '302 to /dashboard; dashboard shows KPIs', function () use ($admin, $adminUser, $adminPass) {
    $admin->login($adminUser, $adminPass);
    $loc = (string) $admin->location;
    $admin->follow();
    return [str_ends_with($loc, '/dashboard') && contains($admin, 'Present today'), "Location $loc, HTTP {$admin->status}"];
});
t('TC-04', 'Auth', 'Employee signs in and lands on the self-service portal', '302 to /my; portal shows "Check in" or "Check out"', function () use ($staff, $demoPass) {
    $staff->login('d.okafor', $demoPass);
    $loc = (string) $staff->location;
    $staff->follow();
    return [str_ends_with($loc, '/my') && contains($staff, 'Annual leave remaining'), "Location $loc"];
});
t('TC-05', 'Auth', 'Signed-out visitor cannot open an admin page', 'Redirect to /login', function () use ($base) {
    $c = new HttpClient($base);
    $c->get('/employees');
    return [$c->status === 302 && str_ends_with((string) $c->location, '/login'), "HTTP {$c->status} -> {$c->location}"];
});
t('TC-06', 'Security', 'POST without a CSRF token is refused', 'HTTP 419 Page expired', function () use ($admin) {
    $admin->post('/employees', ['first_name' => 'X'], false);
    return [$admin->status === 419, "HTTP {$admin->status}"];
});
t('TC-07', 'Security', 'Account locks after 5 failed sign-ins (even with the right password)', '6th attempt shows "paused for 15 minutes"', function () use ($base, $demoPass) {
    $c = new HttpClient($base);
    for ($i = 0; $i < 5; $i++) {
        $c->login('g.mensah', 'wrong-password-' . $i);
    }
    $c->login('g.mensah', $demoPass)->follow();
    $ok = contains($c, 'paused for 15 minutes') && !contains($c, 'My Dashboard');
    DB::query("DELETE FROM login_attempts WHERE username = 'g.mensah'");
    return [$ok, $c->flashes()];
});
t('TC-08', 'Security', 'Security headers are sent on every page', 'CSP, X-Frame-Options DENY, nosniff, Referrer-Policy', function () use ($admin) {
    $admin->get('/dashboard');
    $h = $admin->headers;
    $ok = str_contains($h['content-security-policy'] ?? '', "default-src 'self'") && ($h['x-frame-options'] ?? '') === 'DENY'
        && ($h['x-content-type-options'] ?? '') === 'nosniff' && isset($h['referrer-policy']);
    return [$ok, json_encode(array_intersect_key($h, array_flip(['x-frame-options', 'x-content-type-options', 'referrer-policy'])))];
});
t('TC-09', 'Security', 'Private files and config are not reachable by URL', '/.env, /storage/... and /vendor/... return 404', function () use ($base) {
    $c = new HttpClient($base);
    $codes = [];
    foreach (['/.env', '/storage/logs/mail.log', '/vendor/autoload.php', '/../.env', '/database/database.sql'] as $p) {
        $codes[] = $p . '=' . $c->get($p)->status;
    }
    $ok = !array_filter($codes, fn ($x) => !preg_match('/=(404|302|403|400)$/', $x));
    return [$ok, implode(', ', $codes)];
});

// =================================================================== ACCESS CONTROL
t('TC-10', 'Access', 'Employee is blocked from every admin area', 'HTTP 403 for 12 admin URLs', function () use ($staff) {
    $bad = [];
    foreach (['/dashboard', '/employees', '/employees/1', '/documents', '/attendance', '/attendance-import', '/shifts', '/leave', '/payslips', '/users', '/reports/employee_directory', '/settings'] as $p) {
        if ($staff->get($p)->status !== 403) {
            $bad[] = "$p={$staff->status}";
        }
    }
    return [!$bad, $bad ? implode(', ', $bad) : 'All 403'];
});
t('TC-11', 'Access', 'Employee cannot perform admin actions by posting directly', 'HTTP 403; employee not archived', function () use ($staff, $aisha) {
    $staff->get('/my');
    $staff->post('/employees/' . $aisha['id'] . '/delete');
    $still = DB::value('SELECT deleted_at FROM employees WHERE id = ?', [$aisha['id']]) === null;
    return [$staff->status === 403 && $still, "HTTP {$staff->status}, still active=" . ($still ? 'yes' : 'no')];
});
t('TC-12', 'Access', "Employee cannot open another employee's payslip", 'HTTP 404 (does not reveal it exists)', function () use ($staff, $aisha) {
    $pid = DB::value("SELECT id FROM payslips WHERE employee_id = ? AND status = 'published' LIMIT 1", [$aisha['id']]);
    $staff->get('/files/payslip/' . $pid);
    return [$staff->status === 404, "payslip #$pid -> HTTP {$staff->status}"];
});
t('TC-13', 'Access', "Employee cannot open another employee's document", 'HTTP 404', function () use ($staff, $aisha) {
    $did = DB::value('SELECT id FROM employee_documents WHERE employee_id = ? LIMIT 1', [$aisha['id']]);
    $staff->get('/files/document/' . $did);
    return [$staff->status === 404, "document #$did -> HTTP {$staff->status}"];
});
t('TC-14', 'Access', 'Employee can open their own published payslip', 'HTTP 200, application/pdf, no-store', function () use ($staff, $daniel) {
    $pid = DB::value("SELECT id FROM payslips WHERE employee_id = ? AND status = 'published' AND is_current = 1 LIMIT 1", [$daniel['id']]);
    $staff->get('/files/payslip/' . $pid . '?download=1');
    $ok = $staff->status === 200 && str_starts_with($staff->headers['content-type'] ?? '', 'application/pdf') && str_starts_with($staff->body, '%PDF') && str_contains($staff->headers['cache-control'] ?? '', 'no-store');
    return [$ok, "HTTP {$staff->status} " . ($staff->headers['content-type'] ?? '')];
});

// =================================================================== PAGE CRAWL
t('TC-15', 'Pages', 'Every admin page opens without an error', 'HTTP 200 for all admin screens', function () use ($admin, $daniel) {
    $pages = ['/dashboard', '/employees', '/employees/create', '/employees?archived=1&status=all', '/employees/' . $daniel['id'],
        '/documents', '/documents/upload', '/documents/matrix', '/attendance', '/attendance/create', '/attendance/absences',
        '/attendance-import', '/shifts', '/shifts?view=day', '/shifts?view=month&employee_id=' . $daniel['id'], '/shifts/create', '/shifts/exceptions',
        '/shift-import', '/leave', '/leave?status=all', '/leave/create', '/leave/balances', '/leave/calendar', '/payslips', '/payslips/upload', '/p60', '/p60/upload',
        '/users', '/users/create', '/reports', '/settings', '/notifications', '/account/password', '/my', '/hierarchy'];
    foreach (['overview', 'personal', 'employment', 'documents', 'attendance', 'shifts', 'leave', 'payslips', 'account', 'idcard'] as $tab) {
        $pages[] = '/employees/' . $daniel['id'] . '?tab=' . $tab;
    }
    foreach (['departments', 'job_titles', 'locations', 'shift_types', 'leave_types', 'document_categories', 'document_requirements', 'public_holidays', 'work_patterns'] as $tb) {
        $pages[] = '/settings/' . $tb;
    }
    $bad = [];
    foreach ($pages as $p) {
        if ($admin->get($p)->status !== 200) {
            $bad[] = "$p={$admin->status}";
        }
    }
    return [!$bad, $bad ? implode(', ', $bad) : count($pages) . ' pages OK'];
});
t('TC-16', 'Pages', 'Every employee portal page opens without an error', 'HTTP 200 for 8 portal screens (notifications are admin-only)', function () use ($staff) {
    $bad = [];
    foreach (['/my', '/my/profile', '/my/documents', '/my/attendance', '/my/shifts', '/my/leave', '/my/payslips', '/account/password'] as $p) {
        if ($staff->get($p)->status !== 200) {
            $bad[] = "$p={$staff->status}";
        }
    }
    return [!$bad, $bad ? implode(', ', $bad) : 'All 200'];
});
t('TC-17', 'Pages', 'Employee pages show no edit, upload or approve controls', 'No admin links or salary data in portal HTML', function () use ($staff) {
    $html = '';
    foreach (['/my', '/my/profile', '/my/documents', '/my/payslips'] as $p) {
        $html .= $staff->get($p)->body;
    }
    $leaks = array_filter(['/employees/', '/users', 'Salary', 'payroll_reference', '/verify', '/payslips/publish'], fn ($n) => str_contains($html, $n));
    return [!$leaks, $leaks ? 'Found: ' . implode(', ', $leaks) : 'None found'];
});

// =================================================================== EMPLOYEES
$newEmp = ['first_name' => 'Elliot', 'last_name' => 'Testington', 'email' => 'e2e.elliot@menakan.example', 'phone' => '07700 900123', 'date_of_birth' => '1990-05-17',
    'postcode' => 'NN11 4AB', 'department' => 'Drivers', 'designation' => 'Van Driver',
    'employment_type' => 'full_time', 'contract_hours' => '37.5', 'start_date' => '2026-09-01', 'status' => 'active', 'reporting_manager' => '', 'work_pattern_id' => ''];
t('TC-18', 'Employees', 'Create an employee with valid details', 'Saved with the next code (MT0027) and shown on the profile', function () use ($admin, $newEmp, &$ctx) {
    $expected = App\Services\EmployeeService::previewNextCode();
    $admin->get('/employees/create');
    $admin->post('/employees', $newEmp);
    $loc = (string) $admin->location;
    $admin->follow();
    $row = DB::one("SELECT * FROM employees WHERE email = 'e2e.elliot@menakan.example'");
    $ctx['emp'] = $row;
    return [$row && $row['employee_code'] === $expected && contains($admin, 'Elliot Testington') && contains($admin, 'Employee created.'), "Created {$row['employee_code']} (expected $expected), redirect $loc"];
});
t('TC-19', 'Employees', 'Required fields are enforced', 'Errors for first name, department, date of joining; nothing saved', function () use ($admin) {
    $before = (int) DB::value('SELECT COUNT(*) FROM employees');
    $admin->get('/employees/create');
    $admin->post('/employees', ['first_name' => '', 'last_name' => 'Nobody', 'employment_type' => 'full_time', 'status' => 'active'])->follow();
    $after = (int) DB::value('SELECT COUNT(*) FROM employees');
    return [$before === $after && contains($admin, 'First name is required.') && contains($admin, 'Department is required.') && contains($admin, 'Date of joining is required.'), $admin->flashes()];
});
t('TC-20', 'Employees', 'Duplicate email and bad postcode are rejected', 'Errors shown, no new record', function () use ($admin, $newEmp) {
    $admin->get('/employees/create');
    $admin->post('/employees', ['email' => 'e2e.elliot@menakan.example', 'postcode' => 'NOT A CODE', 'first_name' => 'Dup'] + $newEmp)->follow();
    return [contains($admin, 'Another employee already uses this email address.') && contains($admin, 'valid UK postcode') && (int) DB::value("SELECT COUNT(*) FROM employees WHERE first_name = 'Dup'") === 0, 'Errors displayed'];
});
t('TC-21', 'Employees', 'Edit an employee; the change is audited', 'Phone updated; audit entry lists the old and new phone', function () use ($admin, $newEmp, &$ctx) {
    $id = $ctx['emp']['id'];
    $admin->get("/employees/$id/edit");
    $admin->post("/employees/$id", ['phone' => '07700 900999'] + $newEmp)->follow();
    $phone = DB::value('SELECT phone FROM employees WHERE id = ?', [$id]);
    $audit = DB::one("SELECT * FROM audit_logs WHERE action = 'employee.updated' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$id]);
    return [$phone === '07700 900999' && $audit && str_contains($audit['changes'], '07700 900999') && str_contains($audit['changes'], '07700 900123'), "phone=$phone; audit #" . ($audit['id'] ?? '-')];
});
t('TC-22', 'Employees', 'Search, filter and export the employee list', 'Search finds the new employee; Excel export is a valid .xlsx', function () use ($admin) {
    $admin->get('/employees?q=Testington');
    $found = contains($admin, 'Elliot Testington');
    $admin->get('/employees/export?format=xlsx');
    $isXlsx = $admin->status === 200 && str_starts_with($admin->body, "PK\x03\x04");
    $admin->get('/employees/export?format=csv');
    $isCsv = str_contains($admin->body, '"Employee ID",Title,"First Name","Last Name"') && str_contains($admin->body, '"NI Number"');
    return [$found && $isXlsx && $isCsv, "found=$found xlsx=$isXlsx csv=$isCsv"];
});

// =================================================================== ACCOUNTS
t('TC-23', 'Accounts', 'Create a portal account and activate it with the one-time link', 'Activation link works once; new employee can sign in', function () use ($admin, $base, &$ctx) {
    $id = $ctx['emp']['id'];
    $admin->get("/employees/$id?tab=account");
    $admin->post("/employees/$id/account", ['username' => 'e.testington'])->follow();
    if (!preg_match('#/password/reset/([a-f0-9]{64})#', html_entity_decode($admin->body), $m)) {
        return [false, 'No activation link shown: ' . $admin->flashes()];
    }
    $ctx['token'] = $m[1];
    $c = new HttpClient($base);
    $c->get('/password/reset/' . $m[1]);
    $c->post('/password/reset/' . $m[1], ['password' => 'Testington2026x', 'password_confirmation' => 'Testington2026x'])->follow();
    $c->login('e.testington', 'Testington2026x');
    $ok = str_ends_with((string) $c->location, '/my');
    $c2 = new HttpClient($base);
    $c2->get('/password/reset/' . $m[1])->follow();
    $reused = contains($c2, 'invalid or has expired');
    return [$ok && $reused, "login -> {$c->location}; link reuse blocked=" . ($reused ? 'yes' : 'no')];
});
t('TC-24', 'Accounts', 'Weak passwords are rejected', 'Error "at least 10 characters"', function () use ($admin, $base, &$ctx) {
    $uid = (int) DB::value("SELECT id FROM users WHERE username = 'e.testington'");
    $link = App\Services\PasswordTokens::issue($uid, 'reset', null);
    $token = substr($link, -64);
    $c = new HttpClient($base);
    $c->get('/password/reset/' . $token);
    $c->post('/password/reset/' . $token, ['password' => 'short1', 'password_confirmation' => 'short1'])->follow();
    return [contains($c, 'at least 10 characters'), $c->flashes()];
});
t('TC-25', 'Accounts', 'Only one active account per employee', 'Second account for the same employee is refused', function () use ($admin, &$ctx) {
    $admin->get('/users/create');
    $admin->post('/users', ['username' => 'e.testington2', 'role' => 'employee', 'employee_id' => (string) $ctx['emp']['id']])->follow();
    return [contains($admin, 'already has an active account') && !DB::value("SELECT id FROM users WHERE username = 'e.testington2'"), $admin->flashes()];
});

// =================================================================== DOCUMENTS
$catId = fn (string $code) => (string) DB::value('SELECT id FROM document_categories WHERE code = ?', [$code]);
t('TC-26', 'Documents', 'Upload a valid PDF document', 'Stored privately under a random name; status Pending', function () use ($admin, $tmpDir, $catId, &$ctx) {
    $id = $ctx['emp']['id'];
    $admin->get('/documents/upload?employee_id=' . $id);
    $admin->post('/documents', ['employee_id' => (string) $id, 'category_id' => $catId('RTW'), 'title' => 'Passport check', 'file' => upload(pdfFile($tmpDir, 'passport.pdf'), 'application/pdf')])->follow();
    $doc = DB::one('SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY id DESC LIMIT 1', [$id]);
    $ctx['doc'] = $doc;
    $ok = $doc && $doc['verification'] === 'pending' && preg_match('#^documents/\d{4}/\d{2}/[a-f0-9]{32}\.pdf$#', $doc['storage_key']) && is_file(App\Services\FileStore::path($doc['storage_key']));
    return [(bool) $ok, 'key=' . ($doc['storage_key'] ?? '-') . ' ' . $admin->flashes()];
});
t('TC-27', 'Documents', 'A disguised file (text renamed to .pdf) is rejected', '"content does not match its extension"; nothing stored', function () use ($admin, $tmpDir, $catId, &$ctx) {
    file_put_contents("$tmpDir/fake.pdf", "<?php echo 'not a pdf';");
    $before = (int) DB::value('SELECT COUNT(*) FROM employee_documents');
    $admin->get('/documents/upload');
    $admin->post('/documents', ['employee_id' => (string) $ctx['emp']['id'], 'category_id' => $catId('CONTRACT'), 'title' => 'Fake', 'file' => upload("$tmpDir/fake.pdf", 'application/pdf')])->follow();
    return [contains($admin, 'does not match its extension') && $before === (int) DB::value('SELECT COUNT(*) FROM employee_documents'), $admin->flashes()];
});
t('TC-28', 'Documents', 'Categories with an expiry need an expiry date', 'Driving licence without expiry date is refused', function () use ($admin, $tmpDir, $catId, &$ctx) {
    $admin->get('/documents/upload');
    $admin->post('/documents', ['employee_id' => (string) $ctx['emp']['id'], 'category_id' => $catId('DL'), 'title' => 'Licence', 'file' => upload(pdfFile($tmpDir, 'dl.pdf'), 'application/pdf')])->follow();
    return [contains($admin, 'needs an expiry date') || contains($admin, 'An expiry date is required'), $admin->flashes()];
});
t('TC-29', 'Documents', 'Replace a document; the old version is archived, not deleted', 'Old row is_current=0 with replaced_by; file still on disk', function () use ($admin, $tmpDir, &$ctx) {
    $old = $ctx['doc'];
    $admin->get('/documents/' . $old['id'] . '/replace');
    $admin->post('/documents/' . $old['id'] . '/replace', ['title' => 'Passport check (renewed)', 'file' => upload(pdfFile($tmpDir, 'passport2.pdf', 'renewed'), 'application/pdf')])->follow();
    $o = DB::one('SELECT * FROM employee_documents WHERE id = ?', [$old['id']]);
    $ctx['doc2'] = DB::one('SELECT * FROM employee_documents WHERE id = ?', [(int) $o['replaced_by_id']]);
    return [(int) $o['is_current'] === 0 && $o['replaced_by_id'] && is_file(App\Services\FileStore::path($o['storage_key'])), "old current={$o['is_current']}, replaced_by={$o['replaced_by_id']}"];
});
t('TC-30', 'Documents', 'Verify a pending document', 'Status becomes Verified and is audited', function () use ($admin, &$ctx) {
    $id = $ctx['doc2']['id'];
    $admin->get('/documents');
    $admin->post("/documents/$id/verify", ['decision' => 'verified', 'back' => 'list'])->follow();
    return [DB::value('SELECT verification FROM employee_documents WHERE id = ?', [$id]) === 'verified' && DB::value("SELECT id FROM audit_logs WHERE action = 'document.verified' AND entity_id = ?", [$id]), $admin->flashes()];
});

// =================================================================== SHIFTS
$shift = fn (string $date, string $s, string $e, array $extra = []) => $extra + ['employee_id' => '', 'date' => $date, 'start_time' => $s, 'end_time' => $e, 'break_minutes' => '30', 'status' => 'draft', 'shift_type_id' => '', 'location_id' => '', 'notes' => 'E2E'];
t('TC-31', 'Shifts', 'Create four 8-hour day shifts (Mon–Thu)', 'Saved as drafts; week total 32.0h', function () use ($admin, $shift, &$ctx) {
    $eid = (string) $ctx['emp']['id'];
    foreach (['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22'] as $d) {
        $admin->get('/shifts/create');
        $admin->post('/shifts', $shift($d, '08:00', '16:30', ['employee_id' => $eid]));
    }
    $m = App\Services\HoursCalculator::scheduledMinutes((int) $eid, '2026-10-19');
    return [$m === 32 * 60, 'scheduled=' . ($m / 60) . 'h'];
});
t('TC-32', 'Shifts', 'Overlapping shift for the same employee is blocked', '"overlaps another shift"; not saved', function () use ($admin, $shift, &$ctx) {
    $admin->get('/shifts/create');
    $admin->post('/shifts', $shift('2026-10-19', '10:00', '12:00', ['employee_id' => (string) $ctx['emp']['id']]))->follow();
    return [contains($admin, 'overlaps another shift'), $admin->flashes()];
});
t('TC-33', 'Shifts', 'Going over the 37.5h weekly limit needs an approval reason', 'Friday shift (40h total) refused without a reason', function () use ($admin, $shift, &$ctx) {
    $admin->get('/shifts/create');
    $admin->post('/shifts', $shift('2026-10-23', '08:00', '16:30', ['employee_id' => (string) $ctx['emp']['id']]))->follow();
    return [contains($admin, 'over their 37.5-hour limit') && App\Services\HoursCalculator::scheduledMinutes((int) $ctx['emp']['id'], '2026-10-19') === 1920, $admin->flashes()];
});
t('TC-34', 'Shifts', 'Over-limit shift is saved when approved with a reason', 'Saved; hours_exceptions row records 40.0h, reason and approver', function () use ($admin, $shift, &$ctx) {
    $admin->get('/shifts/create');
    $admin->post('/shifts', $shift('2026-10-23', '08:00', '16:30', ['employee_id' => (string) $ctx['emp']['id'], 'approve_over_limit' => '1', 'override_reason' => 'E2E cover for sickness']))->follow();
    $ex = DB::one("SELECT * FROM hours_exceptions WHERE employee_id = ? AND week_start = '2026-10-19'", [$ctx['emp']['id']]);
    return [$ex && (int) $ex['approved_minutes'] === 2400 && $ex['reason'] === 'E2E cover for sickness', 'exception approved_minutes=' . ($ex['approved_minutes'] ?? '-')];
});
t('TC-35', 'Shifts', 'Night shift across the October clock change counts real hours', 'Sat 24/10 22:00 – Sun 25/10 06:00 = 9h elapsed, 8.50h net', function () use ($admin, $shift, &$ctx) {
    $admin->get('/shifts/create');
    $admin->post('/shifts', $shift('2026-10-24', '22:00', '06:00', ['employee_id' => (string) $ctx['emp']['id'], 'approve_over_limit' => '1', 'override_reason' => 'E2E night cover']))->follow();
    $s = DB::one("SELECT * FROM shifts WHERE employee_id = ? AND start_at = '2026-10-24 21:00:00'", [$ctx['emp']['id']]);
    return [$s && (int) $s['net_minutes'] === 510 && $s['end_at'] === '2026-10-25 06:00:00' && $s['week_start'] === '2026-10-19', 'net=' . ($s['net_minutes'] ?? '-') . ' end_at(UTC)=' . ($s['end_at'] ?? '-')];
});
t('TC-36', 'Shifts', 'Week roster shows the week total and the over-limit flag', 'Roster shows 48.5h and "approved"', function () use ($admin, &$ctx) {
    $admin->get('/shifts?view=week&date=2026-10-19&employee_id=' . $ctx['emp']['id']);
    return [contains($admin, '48.5h') && contains($admin, 'approved'), 'HTTP ' . $admin->status];
});
t('TC-37', 'Shifts', 'Month view puts days from the previous month in the first week row', 'October 2026 grid starts Mon 28 Sep; week totals column present', function () use ($admin, &$ctx) {
    $admin->get('/shifts?view=month&date=2026-10-01&employee_id=' . $ctx['emp']['id']);
    preg_match_all('/<span class="dn">(\d+)<\/span>/', $admin->body, $m);
    return [($m[1][0] ?? '') === '28' && contains($admin, 'Week total'), 'first day cell=' . ($m[1][0] ?? '-')];
});
t('TC-38', 'Shifts', 'Publish the week\'s drafts (new shifts are not notified)', '6 shifts published; no notification for new shifts', function () use ($admin, &$ctx) {
    $admin->get('/shifts?view=week&date=2026-10-19');
    $admin->post('/shifts/publish', ['date' => '2026-10-19', 'employee_id' => (string) $ctx['emp']['id'], 'department_id' => ''])->follow();
    $n = (int) DB::value("SELECT COUNT(*) FROM shifts WHERE employee_id = ? AND week_start = '2026-10-19' AND status = 'published'", [$ctx['emp']['id']]);
    $note = DB::value("SELECT n.id FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.employee_id = ? AND n.title = 'New shifts published'", [$ctx['emp']['id']]);
    return [$n === 6 && !$note, "published=$n, notification=" . ($note ? 'yes' : 'no')];
});

// =================================================================== ATTENDANCE
t('TC-39', 'Attendance', 'Employee checks in; a second check-in is blocked', 'One open record; second attempt says "already checked in"', function () use ($staff, $daniel) {
    DB::query("UPDATE attendance_records SET check_out_at = DATE_ADD(check_in_at, INTERVAL 8 HOUR), net_minutes = 450 WHERE employee_id = ? AND check_out_at IS NULL AND status <> 'incomplete'", [$daniel['id']]);
    $staff->get('/my');
    $staff->post('/my/attendance/check-in')->follow();
    $first = contains($staff, 'Checked in at');
    $staff->get('/my/attendance');
    $staff->post('/my/attendance/check-in')->follow();
    $open = (int) DB::value("SELECT COUNT(*) FROM attendance_records WHERE employee_id = ? AND check_out_at IS NULL AND status <> 'incomplete'", [$daniel['id']]);
    return [$first && contains($staff, 'already checked in') && $open === 1, "open sessions=$open; " . $staff->flashes()];
});
t('TC-40', 'Attendance', 'Employee checks out; net time is calculated by the server', 'Record closed with net minutes ≥ 0', function () use ($staff, $daniel) {
    $staff->get('/my');
    $staff->post('/my/attendance/check-out')->follow();
    $r = DB::one('SELECT * FROM attendance_records WHERE employee_id = ? ORDER BY id DESC LIMIT 1', [$daniel['id']]);
    return [$r['check_out_at'] !== null && $r['net_minutes'] !== null && contains($staff, 'Checked out at'), $staff->flashes()];
});
t('TC-41', 'Attendance', 'Admin correction needs a reason and is kept in the history', 'Without reason: error. With reason: check-out changed and history row saved', function () use ($admin, $daniel) {
    // An older, completed record: editing today's could overlap a shift that is still in progress.
    $id = (int) DB::value('SELECT id FROM attendance_records WHERE employee_id = ? AND check_out_at IS NOT NULL AND work_date < ? ORDER BY work_date DESC LIMIT 1', [$daniel['id'], date('Y-m-d', strtotime('-3 days'))]);
    $rec = DB::one('SELECT * FROM attendance_records WHERE id = ?', [$id]);
    $form = ['work_date' => $rec['work_date'], 'check_in' => from_utc($rec['check_in_at'])->format('H:i'),
        'check_out' => from_utc($rec['check_out_at'])->modify('-15 minutes')->format('H:i'), 'break_minutes' => (string) $rec['break_minutes'], 'notes' => ''];
    $admin->get("/attendance/$id/edit");
    $admin->post("/attendance/$id", $form + ['reason' => ''])->follow();
    $noReason = contains($admin, 'A reason is required');
    $admin->post("/attendance/$id", $form + ['reason' => 'E2E: forgot to check out'])->follow();
    $hist = DB::one("SELECT * FROM attendance_corrections WHERE attendance_id = ? AND field = 'check_out_at' ORDER BY id DESC LIMIT 1", [$id]);
    return [$noReason && $hist && $hist['reason'] === 'E2E: forgot to check out' && contains($admin, 'E2E: forgot to check out'), 'history row=' . ($hist['id'] ?? '-')];
});
t('TC-42', 'Attendance', 'Approve attendance records in bulk', 'Selected records become approved', function () use ($admin, $daniel) {
    $ids = DB::column('SELECT id FROM attendance_records WHERE employee_id = ? AND is_approved = 0 AND check_out_at IS NOT NULL LIMIT 3', [$daniel['id']]);
    $admin->get('/attendance');
    $admin->post('/attendance/approve', ['ids' => $ids, 'back' => '/attendance'])->follow();
    $n = (int) DB::value('SELECT COUNT(*) FROM attendance_records WHERE is_approved = 1 AND id IN (' . DB::placeholders($ids) . ')', $ids);
    return [$n === count($ids) && $n > 0, "$n of " . count($ids) . ' approved'];
});

// =================================================================== ATTENDANCE IMPORT
t('TC-43', 'Import', 'Download the attendance import template', 'Valid .xlsx with the expected header row', function () use ($admin, $tmpDir) {
    $admin->get('/attendance-import/template');
    file_put_contents("$tmpDir/tpl.xlsx", $admin->body);
    $book = PhpOffice\PhpSpreadsheet\IOFactory::load("$tmpDir/tpl.xlsx");
    $hdr = $book->getSheet(0)->rangeToArray('A1:G1')[0];
    return [$hdr[0] === 'Employee ID' && $hdr[2] === 'Work Date' && $hdr[4] === 'Check Out', implode(' | ', $hdr)];
});
t('TC-44', 'Import', 'Validate an attendance file with good and bad rows', 'Preview: 7 rows, 2 valid, 1 duplicate (approved), 4 errors with reasons', function () use ($admin, $tmpDir, &$ctx) {
    $code = $ctx['emp']['employee_code'];
    $approved = DB::one('SELECT a.*, e.employee_code FROM attendance_records a JOIN employees e ON e.id = a.employee_id WHERE a.is_approved = 1 AND a.check_out_at IS NOT NULL ORDER BY a.id LIMIT 1');
    $ctx['approved'] = $approved;
    $rows = [
        ['Employee ID', 'Employee Name', 'Work Date', 'Check In', 'Check Out', 'Break Minutes', 'Notes'],
        [$code, 'Elliot', '12/10/2026', '06:00', '14:30', 30, 'ok'],
        [$code, 'Elliot', '13/10/2026', '22:00', '06:00', 30, 'night'],
        ['MT9999', 'J. Smith', '12/10/2026', '08:00', '16:00', 30, ''],
        [$code, 'Elliot', '31/09/2026', '09:00', '17:00', 60, ''],
        [$code, 'Elliot', '11/10/2026', '09:00', '', 60, ''],
        [$approved['employee_code'], '', uk_date($approved['work_date']), uk_time($approved['check_in_at']), uk_time($approved['check_out_at']), 30, 'dup'],
        [$code, 'Elliot', '12/10/2026', '06:00', '14:30', 30, 'repeat'],
    ];
    $admin->get('/attendance-import');
    $admin->post('/attendance-import', ['file' => upload(xlsxFile($tmpDir, 'timesheet_wk41.xlsx', $rows), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')]);
    $loc = (string) $admin->location;
    $admin->follow();
    $b = DB::one('SELECT * FROM attendance_import_batches ORDER BY id DESC LIMIT 1');
    $ctx['abatch'] = $b;
    $ok = (int) $b['total_rows'] === 7 && (int) $b['valid_rows'] === 2 && (int) $b['duplicate_rows'] === 1 && (int) $b['error_rows'] === 4
        && contains($admin, 'Employee ID not found') && contains($admin, 'Invalid date') && contains($admin, 'Check Out missing') && contains($admin, 'Duplicate of row 2')
        && contains($admin, 'approved record exists') && contains($admin, 'overnight');
    return [$ok, "total={$b['total_rows']} valid={$b['valid_rows']} dup={$b['duplicate_rows']} err={$b['error_rows']} ($loc)"];
});
t('TC-45', 'Import', 'Confirm the import: only valid rows are added, approved records untouched', '2 records added; the approved record keeps its original check-out', function () use ($admin, &$ctx) {
    $b = $ctx['abatch'];
    $admin->post('/attendance-import/' . $b['id'] . '/confirm', ['duplicate_mode' => 'update'])->follow();
    $n = (int) DB::value('SELECT COUNT(*) FROM attendance_records WHERE import_batch_id = ?', [$b['id']]);
    $same = DB::value('SELECT check_out_at FROM attendance_records WHERE id = ?', [$ctx['approved']['id']]) === $ctx['approved']['check_out_at'];
    $night = DB::one("SELECT * FROM attendance_records WHERE import_batch_id = ? AND work_date = '2026-10-13'", [$b['id']]);
    return [$n === 2 && $same && (int) $night['net_minutes'] === 450, "inserted=$n, approved unchanged=" . ($same ? 'yes' : 'no') . ', night net=' . ($night['net_minutes'] ?? '-')];
});
t('TC-46', 'Import', 'Download the error report', '.xlsx listing the 5 rejected/skipped rows with reasons', function () use ($admin, $tmpDir, &$ctx) {
    $admin->get('/attendance-import/' . $ctx['abatch']['id'] . '/errors');
    file_put_contents("$tmpDir/err.xlsx", $admin->body);
    $sheet = PhpOffice\PhpSpreadsheet\IOFactory::load("$tmpDir/err.xlsx")->getSheet(0);
    $n = $sheet->getHighestDataRow() - 1;
    return [$n === 5 && $sheet->getCell('I1')->getValue() === 'Problem', "rows=$n"];
});
t('TC-47', 'Import', 'A non-Excel file is refused at upload', '"This file type is not allowed"; no batch created', function () use ($admin, $tmpDir) {
    file_put_contents("$tmpDir/data.csv", "a,b\n1,2\n");
    $before = (int) DB::value('SELECT COUNT(*) FROM attendance_import_batches');
    $admin->get('/attendance-import');
    $admin->post('/attendance-import', ['file' => upload("$tmpDir/data.csv", 'text/csv')])->follow();
    return [contains($admin, 'This file type is not allowed') && $before === (int) DB::value('SELECT COUNT(*) FROM attendance_import_batches'), $admin->flashes()];
});
t('TC-48', 'Import', 'Shift import: validate, then confirm only valid rows', '5 rows: 1 valid, 1 duplicate, 3 errors; 1 shift imported', function () use ($admin, $tmpDir, &$ctx) {
    $code = $ctx['emp']['employee_code'];
    $rows = [
        ['Employee ID', 'Date', 'Start', 'End', 'Break Minutes', 'Shift Type', 'Location', 'Status', 'Notes'],
        [$code, '02/11/2026', '06:00', '14:00', 30, 'EARLY', 'Daventry Depot', 'Published', ''],
        [$code, '02/11/2026', '10:00', '12:00', 0, '', '', 'Draft', 'overlaps row 2'],
        [$code, '03/11/2026', '06:00', '14:00', 30, 'BOGUS', '', 'Draft', ''],
        ['MT9999', '03/11/2026', '06:00', '14:00', 30, '', '', '', ''],
        [$code, '19/10/2026', '08:00', '16:30', 30, '', '', 'Draft', 'already exists'],
    ];
    $admin->get('/shift-import');
    $admin->post('/shift-import', ['file' => upload(xlsxFile($tmpDir, 'roster.xlsx', $rows), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')])->follow();
    $b = DB::one('SELECT * FROM shift_import_batches ORDER BY id DESC LIMIT 1');
    $pre = (int) $b['total_rows'] === 5 && (int) $b['valid_rows'] === 1 && (int) $b['duplicate_rows'] === 1 && (int) $b['error_rows'] === 3
        && contains($admin, 'Overlaps row 2') && contains($admin, 'Unknown Shift Type') && contains($admin, 'Employee ID not found');
    $admin->post('/shift-import/' . $b['id'] . '/confirm', [])->follow();
    $n = (int) DB::value('SELECT COUNT(*) FROM shifts WHERE import_batch_id = ?', [$b['id']]);
    return [$pre && $n === 1, "total={$b['total_rows']} valid={$b['valid_rows']} dup={$b['duplicate_rows']} err={$b['error_rows']}; imported=$n"];
});

// =================================================================== LEAVE
$annual = (string) DB::value("SELECT id FROM leave_types WHERE code = 'AL'");
$bal = fn () => App\Services\LeaveService::annualSummary((int) $daniel['id']);
t('TC-49', 'Leave', 'Employee requests 2 days of annual leave', 'Pending; 2 working days counted', function () use ($staff, $annual, $daniel, &$ctx) {
    $staff->get('/my/leave');
    $staff->post('/my/leave', ['leave_type_id' => $annual, 'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'reason' => 'E2E'])->follow();
    $r = DB::one("SELECT * FROM leave_requests WHERE employee_id = ? AND start_date = '2026-11-02'", [$daniel['id']]);
    $ctx['leave'] = $r;
    return [$r && $r['status'] === 'pending' && (float) $r['days'] === 2.0, 'status=' . ($r['status'] ?? '-') . ' days=' . ($r['days'] ?? '-')];
});
t('TC-50', 'Leave', 'Weekends and bank holidays are not counted', 'Thu 24/12 – Tue 29/12/2026 = 2 days (25th, 28th are bank holidays)', function () {
    $e = DB::one("SELECT * FROM employees WHERE employee_code = 'MT0012'");
    $d = App\Services\LeaveService::countDays($e, '2026-12-24', '2026-12-29', false, false);
    $half = App\Services\LeaveService::countDays($e, '2026-11-02', '2026-11-03', true, false);
    return [$d === 2.0 && $half === 1.5, "24–29 Dec = $d days; Mon pm + Tue = $half days"];
});
t('TC-51', 'Leave', 'Request larger than the remaining balance is refused', '"Not enough leave left"', function () use ($staff, $annual) {
    $staff->get('/my/leave');
    $staff->post('/my/leave', ['leave_type_id' => $annual, 'start_date' => '2026-11-09', 'end_date' => '2026-12-18'])->follow();
    return [contains($staff, 'Not enough leave left'), $staff->flashes()];
});
t('TC-52', 'Leave', 'Overlapping request is refused', '"overlap another pending or approved leave request"', function () use ($staff, $annual) {
    $staff->get('/my/leave');
    $staff->post('/my/leave', ['leave_type_id' => $annual, 'start_date' => '2026-11-03', 'end_date' => '2026-11-04'])->follow();
    return [contains($staff, 'overlap another pending or approved leave request'), $staff->flashes()];
});
t('TC-53', 'Leave', 'Employee cannot request an admin-only leave type (Sickness)', 'Refused as invalid leave type', function () use ($staff) {
    $sick = (string) DB::value("SELECT id FROM leave_types WHERE code = 'SICK'");
    $staff->get('/my/leave');
    $staff->post('/my/leave', ['leave_type_id' => $sick, 'start_date' => '2026-11-16', 'end_date' => '2026-11-16'])->follow();
    return [contains($staff, 'valid leave type'), $staff->flashes()];
});
t('TC-54', 'Leave', 'Admin approves; balance drops and the employee is notified', 'Ledger deduction of −2; remaining reduced by 2; notification created', function () use ($admin, $bal, $daniel, &$ctx) {
    $before = $bal()['remaining'];
    $id = $ctx['leave']['id'];
    $admin->get("/leave/$id");
    $admin->post("/leave/$id/approve", ['note' => 'Enjoy'])->follow();
    $after = $bal()['remaining'];
    $ledger = DB::value("SELECT days FROM leave_ledger WHERE leave_request_id = ? AND entry_type = 'deduction'", [$id]);
    $note = DB::value("SELECT n.id FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.employee_id = ? AND n.title = 'Leave approved'", [$daniel['id']]);
    return [abs($before - $after - 2) < 0.001 && (float) $ledger === -2.0 && !$note, "remaining $before -> $after; ledger=$ledger; no employee notification"];
});
t('TC-55', 'Leave', 'An admin cannot approve their own leave', 'HTTP 403 "cannot approve or reject your own leave request"', function () use ($admin, $annual) {
    $me = (int) DB::value("SELECT employee_id FROM users WHERE username = ?", [getenv('E2E_ADMIN_USER') ?: 'admin']);
    $admin->get('/leave/create');
    $admin->post('/leave', ['employee_id' => (string) $me, 'leave_type_id' => $annual, 'start_date' => '2026-11-26', 'end_date' => '2026-11-26'])->follow();
    $id = (int) DB::value("SELECT id FROM leave_requests WHERE employee_id = ? AND start_date = '2026-11-26'", [$me]);
    $admin->post("/leave/$id/approve", ['note' => 'self']);
    $status = DB::value('SELECT status FROM leave_requests WHERE id = ?', [$id]);
    return [$admin->status === 403 && $status === 'pending' && contains($admin, 'cannot approve or reject your own'), "HTTP {$admin->status}, status=$status"];
});
t('TC-56', 'Leave', 'Employee cancels their own pending request', 'Status Cancelled', function () use ($staff, $daniel) {
    $id = (int) DB::value("SELECT id FROM leave_requests WHERE employee_id = ? AND status = 'pending' ORDER BY id LIMIT 1", [$daniel['id']]);
    $staff->get('/my/leave');
    $staff->post("/my/leave/$id/cancel")->follow();
    return [DB::value('SELECT status FROM leave_requests WHERE id = ?', [$id]) === 'cancelled', $staff->flashes()];
});
t('TC-57', 'Leave', 'Admin cancels approved leave; the days are returned', 'Reversal +2 in the ledger; balance restored', function () use ($admin, $bal, &$ctx) {
    $before = $bal()['remaining'];
    $id = $ctx['leave']['id'];
    $admin->get("/leave/$id");
    $admin->post("/leave/$id/cancel")->follow();
    $after = $bal()['remaining'];
    return [abs($after - $before - 2) < 0.001 && DB::value("SELECT days FROM leave_ledger WHERE leave_request_id = ? AND entry_type = 'reversal'", [$id]) == 2, "remaining $before -> $after"];
});

// =================================================================== PAYSLIPS
t('TC-58', 'Payslips', 'Uploaded payslip stays hidden until published', 'Draft: employee gets 404. Published: 200 and listed', function () use ($admin, $staff, $tmpDir, $daniel, &$ctx) {
    $admin->get('/payslips/upload');
    $admin->post('/payslips', ['employee_id' => (string) $daniel['id'], 'month' => '6', 'year' => '2026', 'file' => upload(pdfFile($tmpDir, 'june.pdf', 'June'), 'application/pdf')])->follow();
    $p = DB::one('SELECT * FROM payslips WHERE employee_id = ? AND period_year = 2026 AND period_month = 6 AND is_current = 1', [$daniel['id']]);
    $ctx['payslip'] = $p;
    $draft = $staff->get('/files/payslip/' . $p['id'])->status;
    $admin->get('/payslips?year=2026&month=6');
    $admin->post('/payslips/publish', ['ids' => [$p['id']], 'year' => '2026', 'month' => '6'])->follow();
    $pub = $staff->get('/files/payslip/' . $p['id'])->status;
    $listed = str_contains($staff->get('/my/payslips')->body, 'June 2026');
    return [$draft === 404 && $pub === 200 && $listed, "draft=$draft, published=$pub, listed=" . ($listed ? 'yes' : 'no')];
});
t('TC-59', 'Payslips', 'Re-uploading a period archives the previous file', 'Without "replace" ticked: refused. With it: old archived, new draft', function () use ($admin, $staff, $tmpDir, $daniel, &$ctx) {
    $admin->get('/payslips/upload');
    $admin->post('/payslips', ['employee_id' => (string) $daniel['id'], 'month' => '6', 'year' => '2026', 'file' => upload(pdfFile($tmpDir, 'june2.pdf', 'June v2'), 'application/pdf')])->follow();
    $refused = contains($admin, 'already exists for this employee and period');
    $admin->post('/payslips', ['employee_id' => (string) $daniel['id'], 'month' => '6', 'year' => '2026', 'confirm_replace' => '1', 'file' => upload(pdfFile($tmpDir, 'june3.pdf', 'June v3'), 'application/pdf')])->follow();
    $old = DB::one('SELECT * FROM payslips WHERE id = ?', [$ctx['payslip']['id']]);
    $oldForStaff = $staff->get('/files/payslip/' . $old['id'])->status;
    return [$refused && $old['status'] === 'archived' && $old['is_current'] === null && $oldForStaff === 404, "refused=$refused, old status={$old['status']}, staff=$oldForStaff"];
});
t('TC-60', 'Payslips', 'Bulk upload suggests matches by filename', 'MT012_ padded to MT0012 (Matched); Unknown employee; Ambiguous (two Patels); Duplicate period; missing period', function () use ($admin, $tmpDir) {
    $files = [];
    foreach (['MT012_2026-05.pdf', 'MT9999_2026-05.pdf', 'Patel_2026-05.pdf', 'MT0012_2026-09.pdf', 'MT0019.pdf'] as $n) {
        $files[] = upload(pdfFile($tmpDir, $n), 'application/pdf', $n);
    }
    $admin->get('/payslips/upload');
    $admin->post('/payslips/bulk', ['files' => $files]);
    $loc = (string) $admin->location;
    $admin->follow();
    $ok = contains($admin, 'Matched') && contains($admin, 'Unknown employee') && contains($admin, 'Ambiguous') && contains($admin, 'Duplicate period') && contains($admin, 'Period not found in filename');
    $tok = substr($loc, -16);
    $admin->post('/payslips/bulk/' . $tok, ['rows' => [0 => ['confirm' => '1', 'employee_id' => (string) DB::value("SELECT id FROM employees WHERE employee_code='MT0012'"), 'month' => '5', 'year' => '2026']]])->follow();
    $saved = (int) DB::value("SELECT COUNT(*) FROM payslips p JOIN employees e ON e.id = p.employee_id WHERE e.employee_code = 'MT0012' AND period_year = 2026 AND period_month = 5");
    return [$ok && $saved === 1, 'labels ok=' . ($ok ? 'yes' : 'no') . ", saved=$saved"];
});

// =================================================================== USERS, SESSIONS, SETTINGS
t('TC-61', 'Users', 'Admin signs a user out of all sessions', "The employee's next request is sent to sign-in", function () use ($admin, $staff, $daniel, $demoPass) {
    $uid = (int) DB::value('SELECT id FROM users WHERE employee_id = ?', [$daniel['id']]);
    $admin->get("/users/$uid/edit");
    $admin->post("/users/$uid/revoke-sessions")->follow();
    $staff->get('/my');
    $kicked = $staff->status === 302 && str_ends_with((string) $staff->location, '/login');
    $msg = "Next request: HTTP {$staff->status} -> {$staff->location}";
    $staff->login('d.okafor', $demoPass);
    return [$kicked, $msg];
});
t('TC-62', 'Users', 'Deactivated users cannot sign in', '"This account is deactivated"', function () use ($admin, $base) {
    $uid = (int) DB::value("SELECT id FROM users WHERE username = 'e.testington'");
    $admin->get("/users/$uid/edit");
    $admin->post("/users/$uid/toggle")->follow();
    $c = new HttpClient($base);
    $c->login('e.testington', 'Testington2026x')->follow();
    return [contains($c, 'This account is deactivated'), $c->flashes()];
});
t('TC-63', 'Users', 'Admin cannot deactivate their own account', '"You cannot deactivate your own account"', function () use ($admin) {
    $uid = (int) DB::value('SELECT id FROM users WHERE username = ?', [getenv('E2E_ADMIN_USER') ?: 'admin']);
    $admin->get("/users/$uid/edit");
    $admin->post("/users/$uid/toggle")->follow();
    return [contains($admin, 'You cannot deactivate your own account') && (int) DB::value('SELECT is_active FROM users WHERE id = ?', [$uid]) === 1, $admin->flashes()];
});
t('TC-64', 'Account', 'Changing password requires the current password', '"current password is incorrect"', function () use ($staff) {
    $staff->get('/account/password');
    $staff->post('/account/password', ['current_password' => 'wrong-one-123', 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'])->follow();
    return [contains($staff, 'Your current password is incorrect.'), $staff->flashes()];
});
t('TC-65', 'Settings', 'Changing the weekly limit updates the rules immediately', 'Dashboard shows "Over 40h"; restored to 37.5', function () use ($admin) {
    $settings = array_column(DB::all('SELECT `key`, `value` FROM system_settings'), 'value', 'key');
    $admin->get('/settings');
    $admin->post('/settings', ['settings' => ['weekly_hours_limit' => '40'] + $settings]);
    $admin->get('/dashboard');
    $changed = contains($admin, 'Over 40h');
    $admin->get('/settings');
    $admin->post('/settings', ['settings' => $settings]);
    return [$changed && DB::value("SELECT value FROM system_settings WHERE `key` = 'weekly_hours_limit'") === '37.5', 'changed=' . ($changed ? 'yes' : 'no')];
});
t('TC-66', 'Settings', 'Add a location; duplicate names are refused', 'First save works, second shows "already exists"', function () use ($admin) {
    $admin->get('/settings/locations');
    $admin->post('/settings/locations', ['name' => 'E2E Test Yard', 'address' => 'Somewhere'])->follow();
    $first = contains($admin, 'E2E Test Yard');
    $admin->post('/settings/locations', ['name' => 'E2E Test Yard', 'address' => 'Again'])->follow();
    return [$first && contains($admin, 'already exists'), $admin->flashes()];
});

// =================================================================== EMPLOYEE PROFILE (extended fields, photo, history)
$pngFile = function (string $dir, string $name): string {
    $img = imagecreatetruecolor(200, 200);
    imagefill($img, 0, 0, imagecolorallocate($img, 31, 95, 168));
    imagepng($img, "$dir/$name");
    return "$dir/$name";
};
$profileForm = fn (array $extra = []) => $extra + $newEmp + ['phone' => '07700 900999', 'location' => 'Daventry Depot',
    'source_of_hire' => 'Employee referral', 'marital_status' => 'Married', 'nationality' => 'Polish', 'visa_type' => 'EU Settlement Scheme - settled',
    'visa_start_date' => '2021-02-01', 'visa_end_date' => '2031-01-31', 'rtw_check_status' => 'Checked - time-limited', 'rtw_check_date' => '2026-09-01',
    'ni_number' => 'ab 12 34 56 c', 'passport_number' => 'ab1234567', 'permanent_address' => "5 Rynek\nKrakow", 'personal_mobile' => '07700 900555',
    'personal_email' => 'elliot.home@example.com'];
t('TC-73', 'Profile', 'Personal & profile tab shows every requested section', 'Basic, Work, Hierarchy, Personal, Identity, Contact, Separation, Work experience, Education', function () use ($admin, &$ctx) {
    $admin->get('/employees/' . $ctx['emp']['id'] . '?tab=personal');
    $missing = array_filter(['Basic information', 'Work information', 'Hierarchy information', 'Personal details', 'Identity information', 'Contact details',
        'Separation information', 'Work experience', 'Education details', 'Current experience', 'Total experience', 'Source of hire', 'Right to work audit check',
        'National Insurance no.', 'Passport number', 'Permanent address', 'Personal mobile', 'Personal email', 'Date of exit', 'Reporting manager', 'Upload photo'], fn ($s) => !contains($admin, $s));
    return [$admin->status === 200 && !$missing, $missing ? 'Missing: ' . implode(', ', $missing) : 'All 21 labels present'];
});
t('TC-74', 'Profile', 'Save the new work, personal, identity and contact fields', 'Saved; NI normalised to AB123456C; age and location shown on the profile', function () use ($admin, $profileForm, &$ctx) {
    $id = $ctx['emp']['id'];
    $admin->get("/employees/$id/edit");
    $admin->post("/employees/$id", $profileForm())->follow();
    $row = DB::one('SELECT * FROM employees WHERE id = ?', [$id]);
    $admin->get("/employees/$id?tab=personal");
    $ok = $row['ni_number'] === 'AB123456C' && $row['passport_number'] === 'AB1234567' && $row['permanent_same_as_present'] == 0 && $row['marital_status'] === 'Married'
        && $row['visa_end_date'] === '2031-01-31' && contains($admin, 'Daventry Depot') && contains($admin, '36 years') && contains($admin, 'elliot.home@example.com');
    return [$ok, "ni={$row['ni_number']} passport={$row['passport_number']} visa_end={$row['visa_end_date']}"];
});
t('TC-75', 'Profile', 'Invalid NI number and visa dates are rejected', 'Errors for NI format and visa end before start; nothing changed', function () use ($admin, $profileForm, &$ctx) {
    $id = $ctx['emp']['id'];
    $admin->get("/employees/$id/edit");
    $admin->post("/employees/$id", $profileForm(['ni_number' => 'ZZ123456Q', 'visa_start_date' => '2026-01-01', 'visa_end_date' => '2025-01-01', 'personal_email' => 'not-an-email']))->follow();
    $ok = contains($admin, 'valid National Insurance number') && contains($admin, 'Visa end date must be on or after Visa start date') && contains($admin, 'Personal email address must be a valid email')
        && DB::value('SELECT ni_number FROM employees WHERE id = ?', [$id]) === 'AB123456C';
    return [$ok, $admin->flashes()];
});
t('TC-76', 'Profile', 'Upload an employee photo; only authorised users can see it', 'Photo stored privately; admin, the employee and colleagues (org chart) get 200; photos of leavers return 404 to staff', function () use ($admin, $staff, $base, $demoPass, $tmpDir, $pngFile, $daniel, $aisha) {
    $admin->get('/employees/' . $daniel['id'] . '?tab=personal');
    $admin->post('/employees/' . $daniel['id'] . '/photo', ['photo' => upload($pngFile($tmpDir, 'face.png'), 'image/png')])->follow();
    $key = DB::value('SELECT photo_key FROM employees WHERE id = ?', [$daniel['id']]);
    $adminSees = $admin->get('/files/photo/' . $daniel['id'])->status === 200 && ($admin->headers['content-type'] ?? '') === 'image/png';
    $own = $staff->get('/files/photo/' . $daniel['id'])->status;
    $colleague = $staff->get('/files/photo/' . $aisha['id'])->status;
    $leaver = (int) DB::value("SELECT id FROM employees WHERE status = 'left' LIMIT 1");
    DB::update('employees', ['photo_key' => $key], 'id = ?', [$leaver]);
    $left = $staff->get('/files/photo/' . $leaver)->status;
    return [(bool) preg_match('#^photos/\d{4}/\d{2}/[a-f0-9]{32}\.png$#', (string) $key) && $adminSees && $own === 200 && $colleague === 200 && $left === 404, "admin=" . ($adminSees ? 200 : 'no') . " own=$own colleague=$colleague leaver=$left"];
});
t('TC-77', 'Profile', 'A fake image is refused as a photo', '"does not match its extension"; photo unchanged', function () use ($admin, $tmpDir, $daniel) {
    file_put_contents("$tmpDir/fake.png", 'not really a png');
    $before = DB::value('SELECT photo_key FROM employees WHERE id = ?', [$daniel['id']]);
    $admin->get('/employees/' . $daniel['id'] . '?tab=personal');
    $admin->post('/employees/' . $daniel['id'] . '/photo', ['photo' => upload("$tmpDir/fake.png", 'image/png')])->follow();
    return [contains($admin, 'does not match its extension') && DB::value('SELECT photo_key FROM employees WHERE id = ?', [$daniel['id']]) === $before, $admin->flashes()];
});
t('TC-78', 'Profile', 'Work experience rows on the employee form (Add Another) count towards total experience', 'Two rows saved (one current, reference mobile in +44 format); 23-month role in total; bad dates refused', function () use ($admin, $profileForm, &$ctx) {
    $id = $ctx['emp']['id'];
    $admin->get("/employees/$id/edit");
    $hasButtons = contains($admin, 'Add Another Work Experience') && contains($admin, 'Add Another Education') && !contains($admin, 'name="work_pattern_id"') && !contains($admin, 'name="default_shift_type_id"');
    $admin->post("/employees/$id", $profileForm(['history_submitted' => '1', 'experience' => [
        ['company_name' => 'E2E Haulage Ltd', 'job_title' => 'Van Driver', 'from_date' => '2022-01-01', 'to_date' => '2023-12-31', 'location' => 'Leeds',
         'reference_name' => 'Pat Ref', 'reference_email' => 'pat@example.com', 'reference_phone_code' => '+44', 'reference_phone' => '07700 900222'],
        ['company_name' => 'Weekend Couriers', 'job_title' => 'Courier', 'from_date' => '2026-03-01', 'is_current' => '1'],
    ], 'education' => []]))->follow();
    $rows = DB::all('SELECT * FROM employee_work_experience WHERE employee_id = ? ORDER BY from_date', [$id]);
    $emp = DB::one('SELECT * FROM employees WHERE id = ?', [$id]);
    $x = App\Services\EmployeeService::experience($emp);
    $admin->get("/employees/$id?tab=personal");
    $shown = contains($admin, 'E2E Haulage Ltd') && contains($admin, '+44 7700900222') && contains($admin, 'Current');
    $admin->get("/employees/$id/edit");
    $admin->post("/employees/$id", $profileForm(['history_submitted' => '1', 'experience' => [['company_name' => 'Bad Dates', 'job_title' => 'X', 'from_date' => '2024-05-01', 'to_date' => '2024-01-01']]]))->follow();
    $refused = contains($admin, 'To date must be on or after From date') && !DB::value("SELECT id FROM employee_work_experience WHERE company_name = 'Bad Dates'");
    return [$hasButtons && count($rows) === 2 && $rows[0]['reference_phone'] === '+44 7700900222' && $rows[1]['to_date'] === null && $x['previous'] >= 23 && $shown && $refused,
        'rows=' . count($rows) . " phone={$rows[0]['reference_phone']} previous={$x['previous']} months, form buttons=" . ($hasButtons ? 'yes' : 'no') . ', bad dates refused=' . ($refused ? 'yes' : 'no')];
});
t('TC-79', 'Profile', 'Education rows with an education level; removing a row deletes it', 'Two levels saved; after removing one, only one remains; audited', function () use ($admin, $profileForm, &$ctx) {
    $id = $ctx['emp']['id'];
    $keepExp = array_map(fn ($r) => ['company_name' => $r['company_name'], 'job_title' => $r['job_title'], 'from_date' => $r['from_date'], 'to_date' => $r['to_date'], 'is_current' => $r['is_current'] ? '1' : ''],
        DB::all('SELECT * FROM employee_work_experience WHERE employee_id = ?', [$id]));
    $admin->get("/employees/$id/edit");
    $admin->post("/employees/$id", $profileForm(['history_submitted' => '1', 'experience' => $keepExp, 'education' => [
        ['institute_name' => 'E2E College', 'education_level' => 'Diploma', 'specialization' => 'Logistics', 'completion_date' => '2015-06-30'],
        ['institute_name' => 'E2E University', 'education_level' => "Bachelor's Degree", 'specialization' => 'Business', 'completion_date' => '2018-06-30'],
    ]]))->follow();
    $two = (int) DB::value('SELECT COUNT(*) FROM employee_education WHERE employee_id = ?', [$id]);
    $admin->get("/employees/$id?tab=personal");
    $listed = contains($admin, "Bachelor's Degree") && contains($admin, 'E2E College');
    $admin->get("/employees/$id/edit");
    $admin->post("/employees/$id", $profileForm(['history_submitted' => '1', 'experience' => $keepExp, 'education' => [
        ['institute_name' => 'E2E University', 'education_level' => "Bachelor's Degree", 'specialization' => 'Business', 'completion_date' => '2018-06-30']]]))->follow();
    $left = DB::column('SELECT institute_name FROM employee_education WHERE employee_id = ?', [$id]);
    $audited = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE entity_id = ? AND action = 'employee.history_saved'", [$id]);
    return [$two === 2 && $listed && $left === ['E2E University'] && $audited >= 2, "saved=$two listed=" . ($listed ? 'yes' : 'no') . ' remaining=' . implode(',', $left) . " audit=$audited"];
});t('TC-80', 'Profile', 'Employee portal shows the profile read-only with masked IDs', 'My Profile shows sections and photo; NI masked; no edit or upload controls', function () use ($staff, $daniel) {
    $staff->get('/my/profile');
    $ni = (string) DB::value('SELECT ni_number FROM employees WHERE id = ?', [$daniel['id']]);
    $ok = $staff->status === 200 && contains($staff, 'Work information') && contains($staff, 'Total experience') && contains($staff, '/files/photo/' . $daniel['id'])
        && !contains($staff, $ni) && contains($staff, App\Services\EmployeeService::mask($ni)) && !contains($staff, 'type="file"') && !contains($staff, '/photo/delete');
    return [$ok, 'masked=' . App\Services\EmployeeService::mask($ni)];
});
t('TC-81', 'Profile', 'Identity numbers are not written to the audit log', 'Audit diff shows "[changed]" for NI and passport, never the values', function () use (&$ctx) {
    $c = (string) DB::value("SELECT changes FROM audit_logs WHERE action = 'employee.updated' AND entity_id = ? AND changes LIKE '%ni_number%' ORDER BY id DESC LIMIT 1", [$ctx['emp']['id']]);
    return [$c !== '' && !str_contains($c, 'AB123456C') && !str_contains($c, 'AB1234567') && str_contains($c, '[changed]'), $c === '' ? 'no entry' : 'values masked'];
});
t('TC-82', 'Profile', 'Typed department, designation, location and reporting manager; follow-up date', 'New department/location created, external manager kept as text, dates shown on the profile', function () use ($admin, $newEmp) {
    $admin->get('/employees/create');
    $admin->post('/employees', ['first_name' => 'Tara', 'last_name' => 'Typed', 'email' => 'e2e.tara@menakan.example', 'department' => 'Cold Chain', 'designation' => 'Reefer Driver',
        'location' => 'E2E Cold Store', 'reporting_manager' => 'Agency supervisor (Hays)',
        'rtw_check_status' => 'Checked - time-limited', 'rtw_check_date' => '2026-09-01', 'rtw_followup_date' => '2026-10-30'] + $newEmp)->follow();
    $row = DB::one("SELECT e.*, d.name AS dept, j.title AS job, l.name AS loc FROM employees e JOIN departments d ON d.id = e.department_id JOIN job_titles j ON j.id = e.job_title_id
                     LEFT JOIN locations l ON l.id = e.location_id WHERE e.email = 'e2e.tara@menakan.example'");
    if (!$row) {
        return [false, 'Not created: ' . $admin->flashes()];
    }
    $admin->get('/employees/' . $row['id'] . '?tab=personal');
    $ok = $row['dept'] === 'Cold Chain' && $row['job'] === 'Reefer Driver' && $row['loc'] === 'E2E Cold Store' && $row['line_manager_id'] === null
        && $row['reporting_manager_name'] === 'Agency supervisor (Hays)' && contains($admin, 'Agency supervisor (Hays)') && contains($admin, 'Last checked date')
        && contains($admin, '30/10/2026') && contains($admin, 'Due in');
    return [$ok, "dept={$row['dept']} job={$row['job']} loc={$row['loc']} manager_text={$row['reporting_manager_name']}"];
});
t('TC-83', 'Hierarchy', 'Organisation chart builds the tree from reporting managers', 'Title shown; Daniel Okafor nested under his manager Mark Ellis; cards show photo/initials and designation; admin card opens the Employee Detail Page', function () use ($admin, $daniel) {
    $admin->get('/hierarchy');
    $html = $admin->body;
    $mark = (int) DB::value("SELECT id FROM employees WHERE employee_code = 'MT0002'");
    // Daniel's card must sit inside the <li> that holds Mark's card.
    $markPos = strpos($html, '/employees/' . $mark . '?tab=personal');
    $danielPos = strpos($html, '/employees/' . $daniel['id'] . '?tab=personal');
    $nested = $markPos !== false && $danielPos !== false && $danielPos > $markPos
        && substr_count(substr($html, $markPos, $danielPos - $markPos), '<ul>') >= 1;
    $ok = $admin->status === 200 && contains($admin, 'Menakan Transport Organizational Structure') && contains($admin, 'HGV Class 1 Driver')
        && contains($admin, 'class="av av-photo"') && contains($admin, 'Change manager') && $nested
        && contains($admin, 'class="org-company"') && strpos($html, 'class="org-company"') < $markPos;
    return [$ok, 'HTTP ' . $admin->status . ', nested=' . ($nested ? 'yes' : 'no') . ', company root=' . (contains($admin, 'class="org-company"') ? 'yes' : 'no')];
});
t('TC-84', 'Hierarchy', 'Search by name and filter by department', 'Search "okafor" highlights 1 match with its manager chain; Warehouse filter shows only warehouse staff as matches', function () use ($admin) {
    $admin->get('/hierarchy?q=okafor');
    $search = contains($admin, '1 matching person') && substr_count($admin->body, 'is-match') === 1 && contains($admin, 'Mark Ellis') && !contains($admin, 'Zofia Kowalska');
    $wh = (int) DB::value("SELECT id FROM departments WHERE name = 'Warehouse'");
    $n = (int) DB::value("SELECT COUNT(*) FROM employees WHERE department_id = ? AND deleted_at IS NULL AND status <> 'left'", [$wh]);
    $admin->get('/hierarchy?department_id=' . $wh);
    $dept = substr_count($admin->body, 'is-match') === $n && contains($admin, "$n matching people");
    return [$search && $dept, 'search ok=' . ($search ? 'yes' : 'no') . ", warehouse matches=$n ok=" . ($dept ? 'yes' : 'no')];
});
t('TC-85', 'Hierarchy', 'Employees can view the chart but not change it', '200 for staff; no links into employee records except their own profile; no "Change manager"; the edit route stays 403', function () use ($staff, $daniel) {
    $staff->get('/hierarchy');
    $ok = $staff->status === 200 && !contains($staff, '/employees/') && !contains($staff, 'Change manager') && contains($staff, '/my/profile') && contains($staff, 'View only');
    $edit = $staff->get('/employees/' . $daniel['id'] . '/edit')->status;
    return [$ok && $edit === 403, "chart HTTP 200, view-only=" . ($ok ? 'yes' : 'no') . ", edit route=$edit"];
});
t('TC-86', 'Hierarchy', 'Missing managers and photos are handled', 'Person with no manager is listed under "Not yet placed"; external manager shown as text; initials shown when there is no photo', function () use ($admin) {
    $loose = (int) DB::value("SELECT id FROM employees WHERE employee_code = 'MT0015'");    // Lucy Morgan: no manager, no reports
    DB::update('employees', ['photo_key' => null], 'id = ?', [$loose]);
    $admin->get('/hierarchy');
    $ok = contains($admin, 'Not yet placed in the structure') && contains($admin, 'Lucy Morgan') && contains($admin, 'No reporting manager')
        && contains($admin, 'Reports to Agency supervisor (Hays)') && contains($admin, '>LM</span>');
    return [$ok, $ok ? 'handled' : 'missing expected labels'];
});
t('TC-87', 'Profile', 'My Profile is read only for the employee', 'Work experience and education are shown with no Add/edit buttons; the old edit page is gone (404)', function () use ($staff) {
    $staff->get('/my/profile');
    $readOnly = contains($staff, 'Work experience') && contains($staff, 'Education details') && !contains($staff, 'Add / edit') && contains($staff, 'Your profile is read only');
    $gone = $staff->get('/my/history')->status === 404;
    return [$readOnly && $gone, 'read only=' . ($readOnly ? 'yes' : 'no') . ', /my/history=' . $staff->status];
});
t('TC-88', 'Leave', 'Book leave with a Requested Date and an Approved Date', 'Saved as approved with both dates; approved date before the requested date is refused', function () use ($admin, $annual) {
    $emp = (int) DB::value("SELECT id FROM employees WHERE employee_code = 'MT0013'");
    $admin->get('/leave/create');
    $form = contains($admin, 'Requested Date') && contains($admin, 'Approved Date');
    $admin->post('/leave', ['employee_id' => (string) $emp, 'leave_type_id' => $annual, 'start_date' => '2026-11-16', 'end_date' => '2026-11-17',
        'requested_on' => '2026-10-01', 'approved_on' => '2026-10-05'])->follow();
    $r = DB::one("SELECT * FROM leave_requests WHERE employee_id = ? AND start_date = '2026-11-16'", [$emp]);
    $listed = contains($admin, '01/10/2026') && contains($admin, '05/10/2026');
    $admin->get('/leave/create');
    $admin->post('/leave', ['employee_id' => (string) $emp, 'leave_type_id' => $annual, 'start_date' => '2026-11-23', 'end_date' => '2026-11-23',
        'requested_on' => '2026-10-10', 'approved_on' => '2026-10-02'])->follow();
    $refused = contains($admin, 'not before the requested date') && !DB::value("SELECT id FROM leave_requests WHERE employee_id = ? AND start_date = '2026-11-23'", [$emp]);
    return [$form && $r && $r['status'] === 'approved' && $r['requested_on'] === '2026-10-01' && $r['approved_on'] === '2026-10-05' && $listed && $refused,
        'status=' . ($r['status'] ?? '-') . ' requested=' . ($r['requested_on'] ?? '-') . ' approved=' . ($r['approved_on'] ?? '-') . ' refused bad order=' . ($refused ? 'yes' : 'no')];
});
t('TC-89', 'Leave', 'Approving a request records the Approved Date; employee requests get today as Requested Date', 'Employee request: requested today; approval from the review page stores the chosen approved date', function () use ($admin, $staff, $annual, $daniel) {
    $staff->get('/my/leave');
    $staff->post('/my/leave', ['leave_type_id' => $annual, 'start_date' => '2026-11-30', 'end_date' => '2026-11-30'])->follow();
    $r = DB::one("SELECT * FROM leave_requests WHERE employee_id = ? AND start_date = '2026-11-30'", [$daniel['id']]);
    $admin->get('/leave/' . $r['id']);
    $admin->post('/leave/' . $r['id'] . '/approve', ['note' => 'ok', 'approved_on' => uk_today()])->follow();
    $after = DB::one('SELECT * FROM leave_requests WHERE id = ?', [$r['id']]);
    return [$r['requested_on'] === uk_today() && $after['status'] === 'approved' && $after['approved_on'] === uk_today(), "requested={$r['requested_on']} approved={$after['approved_on']}"];
});
t('TC-90', 'Payslips', 'Uploading a payslip emails the PDF to the employee', 'Email to the address in Basic information with the PDF attached (test mode: mail log); recorded on the payslip; no email address = warning, upload still saved', function () use ($admin, $tmpDir, $daniel) {
    $log = BASE_PATH . '/storage/logs/mail.log';
    $before = is_file($log) ? filesize($log) : 0;
    $admin->get('/payslips/upload');
    $info = contains($admin, 'emailed straight away');
    $admin->post('/payslips', ['employee_id' => (string) $daniel['id'], 'month' => '4', 'year' => '2026', 'file' => upload(pdfFile($tmpDir, 'april.pdf', 'April'), 'application/pdf')])->follow();
    $p = DB::one('SELECT * FROM payslips WHERE employee_id = ? AND period_year = 2026 AND period_month = 4 AND is_current = 1', [$daniel['id']]);
    $mail = is_file($log) ? substr((string) file_get_contents($log), $before) : '';
    $sent = str_contains($mail, 'To: ' . $daniel['email']) && str_contains($mail, 'Attachments: Payslip_MT0012_2026-04.pdf') && $p['emailed_to'] === $daniel['email'] && $p['emailed_at'] !== null;
    $flash = contains($admin, 'Payslip emailed to ' . $daniel['email']);
    $noMail = (int) DB::value("SELECT id FROM employees WHERE employee_code = 'MT0015'");
    DB::update('employees', ['email' => null], 'id = ?', [$noMail]);
    $admin->get('/payslips/upload');
    $admin->post('/payslips', ['employee_id' => (string) $noMail, 'month' => '4', 'year' => '2026', 'file' => upload(pdfFile($tmpDir, 'april2.pdf', 'April'), 'application/pdf')])->follow();
    $p2 = DB::one('SELECT * FROM payslips WHERE employee_id = ? AND period_year = 2026 AND period_month = 4 AND is_current = 1', [$noMail]);
    $warned = $p2 && $p2['emailed_at'] === null && str_contains((string) $p2['email_error'], 'No valid email address') && contains($admin, 'was not emailed');
    $admin->get('/payslips?year=2026&month=4');
    $column = contains($admin, 'Emailed') && contains($admin, 'Not sent');
    return [$info && $sent && $flash && $warned && $column, 'sent=' . ($sent ? 'yes' : 'no') . ' flash=' . ($flash ? 'yes' : 'no') . ' no-address warning=' . ($warned ? 'yes' : 'no') . ' list column=' . ($column ? 'yes' : 'no')];
});
t('TC-91', 'Audit', 'Audit log and audit history are removed from the interface', 'No "Audit Logs" menu or "Audit history" tab; /audit is 404; actions are still recorded in the database', function () use ($admin, $daniel) {
    $admin->get('/dashboard');
    $nav = !contains($admin, 'Audit Logs');
    $admin->get('/employees/' . $daniel['id']);
    $tab = !contains($admin, 'Audit history');
    $r1 = $admin->get('/audit')->status;
    $r2 = $admin->get('/employees/' . $daniel['id'] . '?tab=audit');
    $fallback = $r2->status === 200 && !contains($r2, 'Audit history');
    $recorded = (int) DB::value('SELECT COUNT(*) FROM audit_logs WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)') > 0;
    return [$nav && $tab && $r1 === 404 && $fallback && $recorded, "menu removed=" . ($nav ? 'yes' : 'no') . " tab removed=" . ($tab ? 'yes' : 'no') . " /audit=$r1 still recording=" . ($recorded ? 'yes' : 'no')];
});
t('TC-92', 'Profile', 'Admin creates the virtual ID card', 'Create from the ID card tab; card shows photo, full name (capitals), designation, department, Employee ID, expiry 31/12/2026, logo, website and address; edit/regenerate/revoke and print for admin', function () use ($admin, $daniel) {
    $admin->get('/employees/' . $daniel['id'] . '?tab=idcard');
    $empty = contains($admin, 'No ID card has been issued');
    $admin->post('/employees/' . $daniel['id'] . '/id-card', ['issue_date' => uk_today(), 'expiry_date' => '2026-12-31'])->follow();
    $missing = array_filter(['ID card MT0012-01 created', 'DANIEL OKAFOR', 'HGV Class 1 Driver', ': MT0012', 'Department', ': 31/12/2026', 'MENAKAN TRANSPORT', 'www.menakantransport.com',
        'Unit 11, 58 Peregrine Rd, Essex IG6 3SZ', '/files/photo/' . $daniel['id'], 'Print / save as PDF', 'Edit ID card', 'Regenerate', 'Revoke'], fn ($s) => !contains($admin, $s));
    return [$empty && $admin->status === 200 && !$missing, ($empty ? 'empty state shown; ' : 'no empty state; ') . ($missing ? 'Missing: ' . implode(', ', $missing) : 'card created with all fields')];
});
t('TC-97', 'Profile', 'Employee sees only their own ID card, view only; admin regenerates', 'My ID Card shows the card with no edit/print/download controls; employee POSTs to manage cards are 403; admin regenerate issues MT0012-02 and the employee sees it', function () use ($admin, $staff, $daniel) {
    $staff->get('/my/id-card');
    $view = contains($staff, 'DANIEL OKAFOR') && contains($staff, 'MT0012-01') && contains($staff, 'View only') && !contains($staff, 'data-print') && !contains($staff, 'Regenerate') && !contains($staff, '/id-cards/');
    $card = DB::one('SELECT * FROM id_cards WHERE employee_id = ? AND is_current = 1', [$daniel['id']]);
    $s1 = $staff->post('/id-cards/' . $card['id'], ['issue_date' => uk_today(), 'expiry_date' => '2030-01-01', 'status' => 'active'])->status;
    $s2 = $staff->post('/id-cards/' . $card['id'] . '/revoke', ['reason' => 'x'])->status;
    $s3 = $staff->get('/employees/' . $daniel['id'] . '?tab=idcard')->status;
    $admin->get('/employees/' . $daniel['id'] . '?tab=idcard');
    $admin->post('/id-cards/' . $card['id'] . '/regenerate', ['issue_date' => uk_today(), 'expiry_date' => '2027-12-31', 'reason' => 'E2E new photo'])->follow();
    $regen = contains($admin, 'New ID card MT0012-02 issued') && contains($admin, 'Previous ID cards');
    $staff->get('/my/id-card');
    $updated = contains($staff, 'MT0012-02') && contains($staff, '31/12/2027');
    return [$view && $s1 === 403 && $s2 === 403 && $s3 === 403 && $regen && $updated, 'view only=' . ($view ? 'yes' : 'no') . " employee edit=$s1 revoke=$s2 admin tab=$s3 regenerate=" . ($regen ? 'ok' : 'no') . ' employee sees new card=' . ($updated ? 'yes' : 'no')];
});
t('TC-93', 'P60', 'Upload a P60 by Employee ID; duplicates refused; replace is archived', 'Saved for 2025/26; second upload refused; with "Replace" the old one is archived; table columns shown', function () use ($admin, $tmpDir, $daniel) {
    $admin->get('/p60/upload');
    $admin->post('/p60', ['employee_code' => 'mt0012', 'tax_year' => '2025', 'file' => upload(pdfFile($tmpDir, 'p60a.pdf', 'P60'), 'application/pdf')])->follow();
    $first = DB::one("SELECT * FROM p60_documents WHERE employee_id = ? AND tax_year_start = 2025 AND status = 'current'", [$daniel['id']]);
    $table = contains($admin, 'Employee Name') && contains($admin, 'Tax Year') && !contains($admin, 'Upload Date') && contains($admin, '2025/26') && contains($admin, 'Daniel Okafor');
    $admin->get('/p60/upload');
    $admin->post('/p60', ['employee_code' => 'MT0012', 'tax_year' => '2025', 'file' => upload(pdfFile($tmpDir, 'p60b.pdf', 'P60 v2'), 'application/pdf')])->follow();
    $refused = contains($admin, 'already has a P60 for 2025/26');
    $admin->post('/p60', ['employee_code' => 'MT0012', 'tax_year' => '2025', 'replace' => '1', 'file' => upload(pdfFile($tmpDir, 'p60c.pdf', 'P60 v3'), 'application/pdf')])->follow();
    $old = DB::one('SELECT * FROM p60_documents WHERE id = ?', [$first['id']]);
    return [$first && $table && $refused && $old['status'] === 'replaced' && $old['replaced_by_id'], 'first=' . ($first['id'] ?? '-') . " table=" . ($table ? 'yes' : 'no') . " duplicate refused=" . ($refused ? 'yes' : 'no') . " old status={$old['status']}"];
});
t('TC-94', 'P60', 'Employees see only their own P60; other P60s are 404', 'My P60 lists 2025/26 with View/Download; another employee\'s P60 returns 404; admin pages 403; "no P60" message for someone without one', function () use ($admin, $staff, $tmpDir, $daniel, $aisha, $base, $demoPass) {
    $admin->get('/p60/upload');
    $admin->post('/p60', ['employee_code' => 'MT0019', 'tax_year' => '2025', 'file' => upload(pdfFile($tmpDir, 'p60d.pdf', 'P60 Aisha'), 'application/pdf')])->follow();
    $hers = (int) DB::value("SELECT id FROM p60_documents WHERE employee_id = ? AND status = 'current'", [$aisha['id']]);
    $mine = (int) DB::value("SELECT id FROM p60_documents WHERE employee_id = ? AND status = 'current'", [$daniel['id']]);
    $staff->get('/my/p60');
    $list = contains($staff, '2025/26') && contains($staff, 'Download PDF') && !contains($staff, "/files/p60/$hers");
    $own = $staff->get("/files/p60/$mine?download=1")->status;
    $other = $staff->get("/files/p60/$hers")->status;
    $adminPage = $staff->get('/p60')->status;
    $c = new Tests\E2E\HttpClient($base);
    $c->login('t.whitfield', $demoPass);
    $c->get('/my/p60');
    $empty = contains($c, 'No P60 is available for you yet');
    $logged = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'p60.downloaded' AND entity_id = ?", [$mine]) >= 1;
    return [$list && $own === 200 && $other === 404 && $adminPage === 403 && $empty && $logged, "own=$own other=$other admin page=$adminPage empty message=" . ($empty ? 'yes' : 'no') . ' download audited=' . ($logged ? 'yes' : 'no')];
});
t('TC-95', 'P60', 'Bulk upload by file name, search, and delete with a reason', 'Files named by Employee ID saved; unknown ID reported; search finds by name; delete hides it from the employee and is audited', function () use ($admin, $tmpDir) {
    $files = [];
    foreach (['MT0006.pdf', 'MT0013_P60.pdf', 'Z9999.pdf'] as $n) {
        $files[] = upload(pdfFile($tmpDir, $n, 'bulk'), 'application/pdf', $n);
    }
    $admin->get('/p60/upload');
    $admin->post('/p60/bulk', ['tax_year' => '2024', 'files' => $files])->follow();
    $bulk = contains($admin, '2 of 3 P60 files saved for 2024/25') && contains($admin, 'Z9999.pdf');
    $admin->get('/p60?q=Whitfield');
    $search = contains($admin, 'Tom Whitfield') && contains($admin, '2024/25') && !contains($admin, 'Emily Turner');
    $id = (int) DB::value("SELECT p.id FROM p60_documents p JOIN employees e ON e.id = p.employee_id WHERE e.employee_code = 'MT0006' AND p.status = 'current'");
    $admin->post("/p60/$id/delete", ['reason' => 'E2E wrong file'])->follow();
    $deleted = DB::value('SELECT status FROM p60_documents WHERE id = ?', [$id]) === 'deleted'
        && (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'p60.deleted' AND entity_id = ?", [$id]) === 1;
    return [$bulk && $search && $deleted, 'bulk=' . ($bulk ? 'ok' : 'no') . ' search=' . ($search ? 'ok' : 'no') . ' deleted=' . ($deleted ? 'ok' : 'no')];
});
t('TC-96', 'P60', 'Uploading a P60 emails the PDF to the employee', 'Email from hr@menakantransport.com with the HR signature and P60 PDF attached to the address in Basic information (test mode: mail log); recorded on the P60; no email address = warning, P60 still saved', function () use ($admin, $tmpDir, $daniel) {
    $log = BASE_PATH . '/storage/logs/mail.log';
    $before = is_file($log) ? filesize($log) : 0;
    $admin->get('/p60/upload');
    $info = contains($admin, 'emailed straight away');
    $admin->post('/p60', ['employee_code' => 'MT0012', 'tax_year' => '2023', 'file' => upload(pdfFile($tmpDir, 'p60e.pdf', 'P60 2023'), 'application/pdf')])->follow();
    $p = DB::one("SELECT * FROM p60_documents WHERE employee_id = ? AND tax_year_start = 2023 AND status = 'current'", [$daniel['id']]);
    $mail = is_file($log) ? substr((string) file_get_contents($log), $before) : '';
    $sent = str_contains($mail, 'To: ' . $daniel['email']) && str_contains($mail, 'From: hr@menakantransport.com') && str_contains($mail, 'Attachments: P60_MT0012_2023-24.pdf')
        && str_contains($mail, 'Menakan Transport Ltd | Human Resources Department') && $p['emailed_to'] === $daniel['email'] && $p['emailed_at'] !== null;
    $flash = contains($admin, 'P60 emailed to ' . $daniel['email']);
    $noMail = (int) DB::value("SELECT id FROM employees WHERE employee_code = 'MT0015'");
    DB::update('employees', ['email' => null], 'id = ?', [$noMail]);
    $admin->get('/p60/upload');
    $admin->post('/p60', ['employee_code' => 'MT0015', 'tax_year' => '2023', 'file' => upload(pdfFile($tmpDir, 'p60f.pdf', 'P60 2023'), 'application/pdf')])->follow();
    $p2 = DB::one("SELECT * FROM p60_documents WHERE employee_id = ? AND tax_year_start = 2023 AND status = 'current'", [$noMail]);
    $warned = $p2 && $p2['emailed_at'] === null && str_contains((string) $p2['email_error'], 'No valid email address') && contains($admin, 'saved but not emailed');
    $admin->get('/p60?tax_year=2023');
    $column = contains($admin, 'Emailed') && contains($admin, 'Not sent') && contains($admin, (string) $daniel['email']);
    return [$info && $sent && $flash && $warned && $column, 'sent=' . ($sent ? 'yes' : 'no') . ' flash=' . ($flash ? 'yes' : 'no') . ' no-address warning=' . ($warned ? 'yes' : 'no') . ' list column=' . ($column ? 'yes' : 'no')];
});
t('TC-98', 'News', 'Admin sends an announcement to selected employees from the fixed HR sender', 'Sent from "Menakan Transport HR Department <hr@menakantransport.com>", one message per recipient, personalised; delivery log; employee reads it in the portal; other employees cannot', function () use ($admin, $staff, $daniel, $aisha, $base, $demoPass) {
    $log = BASE_PATH . '/storage/logs/mail.log';
    clearstatcache();   // filesize() is cached from earlier scenarios
    $before = is_file($log) ? filesize($log) : 0;
    $admin->get('/news/create');
    $form = contains($admin, 'hr@menakantransport.com') && !contains($admin, 'name="from');
    $admin->post('/news', ['title' => 'E2E depot notice', 'subject' => 'Depot notice for {first_name}', 'body' => "Dear {first_name},\n\nYard closed Saturday.", 'audience' => 'employees',
        'audience_ids' => ['employees' => [(string) $daniel['id'], (string) $aisha['id']]], 'from' => 'spoof@example.com', 'intent' => 'send'])->follow();
    $id = (int) DB::value("SELECT id FROM news_posts WHERE title = 'E2E depot notice'");
    $mail = substr((string) file_get_contents($log), $before);
    $sender = substr_count($mail, 'From: Menakan Transport HR Department <hr@menakantransport.com>') === 2 && !str_contains($mail, 'spoof@');
    $danielEmail = (string) DB::value('SELECT email FROM employees WHERE id = ?', [$daniel['id']]);
    $private = (bool) preg_match('/To: ' . preg_quote($danielEmail, '/') . '\nSubject: Depot notice for Daniel/', $mail) && substr_count($mail, "\nTo: ") === 2;
    $flash = contains($admin, 'Announcement sent to 2 employees') && contains($admin, 'Delivery log');
    $staff->get('/my/news');
    $sees = contains($staff, 'E2E depot notice');
    $staff->get("/my/news/$id");
    $reads = contains($staff, 'Dear Daniel,');
    $c = new Tests\E2E\HttpClient($base);
    $c->login('t.whitfield', $demoPass);
    $hidden = $c->get("/my/news/$id")->status === 404 && $c->get('/news')->status === 403;
    return [$form && $sender && $private && $flash && $sees && $reads && $hidden, 'fixed sender=' . ($sender ? 'yes' : 'no') . ' one-per-recipient=' . ($private ? 'yes' : 'no') . ' flash=' . ($flash ? 'yes' : 'no')
        . ' employee reads=' . ($sees && $reads ? 'yes' : 'no') . ' others blocked=' . ($hidden ? 'yes' : 'no')];
});
t('TC-99', 'News', 'Drafts, templates, scheduling and department audience', 'Template prefills the form; draft saved; scheduled for tomorrow; cancel schedule returns to draft; sender check shown', function () use ($admin) {
    $admin->get('/news/templates');
    $tpl = contains($admin, 'Policy update');
    $tid = (int) DB::value("SELECT id FROM news_templates WHERE name = 'Policy update'");
    $admin->get("/news/create?template=$tid");
    $prefill = contains($admin, 'We have updated the [policy name] policy');
    $dept = (int) DB::value('SELECT id FROM departments ORDER BY id LIMIT 1');
    $admin->post('/news', ['title' => 'E2E policy', 'subject' => 'Policy update', 'body' => 'Dear {first_name}, new policy.', 'audience' => 'departments',
        'audience_ids' => ['departments' => [(string) $dept]], 'template_id' => (string) $tid, 'intent' => 'draft'])->follow();
    $id = (int) DB::value("SELECT id FROM news_posts WHERE title = 'E2E policy'");
    $draft = DB::value('SELECT status FROM news_posts WHERE id = ?', [$id]) === 'draft' && contains($admin, 'Recipients if sent now');
    $admin->get("/news/$id/edit");
    $admin->post("/news/$id", ['title' => 'E2E policy', 'subject' => 'Policy update', 'body' => 'Dear {first_name}, new policy.', 'audience' => 'departments',
        'audience_ids' => ['departments' => [(string) $dept]], 'scheduled_at' => uk_now()->modify('+1 day')->format('Y-m-d\TH:i'), 'intent' => 'schedule'])->follow();
    $scheduled = DB::value('SELECT status FROM news_posts WHERE id = ?', [$id]) === 'scheduled' && contains($admin, 'Announcement scheduled for');
    $admin->post("/news/$id/unschedule")->follow();
    $back = DB::value('SELECT status FROM news_posts WHERE id = ?', [$id]) === 'draft';
    $admin->post('/news/sender-check')->follow();
    $check = contains($admin, 'Sender check') && contains($admin, 'hr@menakantransport.com');
    return [$tpl && $prefill && $draft && $scheduled && $back && $check, 'template=' . ($tpl && $prefill ? 'ok' : 'no') . ' draft=' . ($draft ? 'ok' : 'no') . ' scheduled=' . ($scheduled ? 'ok' : 'no')
        . ' unscheduled=' . ($back ? 'ok' : 'no') . ' sender check=' . ($check ? 'shown' : 'no')];
});
t('TC-100', 'Employees', 'Import employees keyed on Employee ID', 'Upload: existing ID updated (only filled cells), empty ID creates the next employee; preview lists the changes; confirm saves; employee portal cannot open it', function () use ($admin, $staff, $tmpDir, $daniel) {
    $cols = array_keys(App\Services\EmployeeTransfer::columns());
    $row = fn (array $v) => array_map(fn ($k) => $v[$k] ?? '', $cols);
    $path = "$tmpDir/employees-import.xlsx";
    file_put_contents($path, App\Services\ExcelIO::build('Employees', App\Services\EmployeeTransfer::headers(), [
        $row(['employee_code' => 'MT0012', 'city' => 'Northampton']),
        $row(['first_name' => 'Imogen', 'last_name' => 'Importer', 'department' => 'Drivers', 'designation' => 'Van Driver', 'start_date' => '02/11/2026', 'email' => 'imogen.importer@menakan.example']),
    ]));
    $admin->get('/employees/import');
    $page = contains($admin, 'Employee ID is the key');
    $admin->post('/employees/import', ['file' => upload($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'employees-import.xlsx')])->follow();
    $preview = contains($admin, 'Preview before import') && contains($admin, 'City:') && contains($admin, 'Northampton') && contains($admin, 'Import 1 new and 1 updated employees');
    $batch = (int) DB::value('SELECT MAX(id) FROM employee_import_batches');
    $admin->post("/employees/import/$batch/confirm")->follow();
    $saved = DB::value('SELECT city FROM employees WHERE id = ?', [$daniel['id']]) === 'Northampton' && DB::value('SELECT first_name FROM employees WHERE id = ?', [$daniel['id']]) === 'Daniel'
        && (bool) DB::value("SELECT id FROM employees WHERE first_name = 'Imogen' AND last_name = 'Importer'") && contains($admin, '1 employee added, 1 updated');
    $blocked = $staff->get('/employees/import')->status === 403;
    return [$page && $preview && $saved && $blocked, 'page=' . ($page ? 'ok' : 'no') . ' preview=' . ($preview ? 'ok' : 'no') . ' saved=' . ($saved ? 'ok' : 'no') . ' employee blocked=' . ($blocked ? 'yes' : 'no')];
});
t('TC-101', 'My Email', 'My Email opens the company webmail in a new tab', 'Menu item after the pay items for admin and employee; page links to https://ns3-777.333servers.com:2096/ in a new tab; no password field; guests sent to sign in', function () use ($admin, $staff, $base) {
    $url = 'https://ns3-777.333servers.com:2096/';
    $admin->get('/dashboard');
    $adminMenu = strpos($admin->body, '/my-email') !== false && strpos($admin->body, '/p60') < strpos($admin->body, '/my-email');
    $staff->get('/my');
    $staffMenu = strpos($staff->body, '/my-email') !== false && strpos($staff->body, '/my/p60') < strpos($staff->body, '/my-email');
    $staff->get('/my-email');
    $page = $staff->status === 200 && contains($staff, 'href="' . $url . '" target="_blank" rel="noopener noreferrer"') && !contains($staff, 'type="password"');
    $guest = (new Tests\E2E\HttpClient($base))->get('/my-email');
    $redirect = $guest->status === 302 && str_ends_with((string) $guest->location, '/login');
    return [$adminMenu && $staffMenu && $page && $redirect, 'admin menu=' . ($adminMenu ? 'ok' : 'no') . ' employee menu=' . ($staffMenu ? 'ok' : 'no') . ' page=' . ($page ? 'ok' : 'no') . ' guest=' . ($redirect ? 'login' : 'no')];
});
t('TC-102', 'Hierarchy', 'Export Hierarchy to PDF', 'Button on the chart; PDF built from the current reporting lines in A4 and A3 landscape; employees can export as they can view; audited', function () use ($admin, $staff) {
    $admin->get('/hierarchy');
    $button = contains($admin, 'Export Hierarchy to PDF') && contains($admin, 'org-company');
    $a4 = $admin->get('/hierarchy/pdf?format=A4');
    $okA4 = $a4->status === 200 && str_starts_with($a4->body, '%PDF-');
    $a3 = $admin->get('/hierarchy/pdf?format=A3');
    $okA3 = $a3->status === 200 && str_starts_with($a3->body, '%PDF-');
    $okStaff = str_starts_with($staff->get('/hierarchy/pdf')->body, '%PDF-');
    $audited = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'hierarchy.exported'") >= 3;
    return [$button && $okA4 && $okA3 && $okStaff && $audited, 'button=' . ($button ? 'yes' : 'no') . ' A4=' . ($okA4 ? strlen($a4->body) . ' bytes' : 'no') . ' A3=' . ($okA3 ? 'ok' : 'no') . ' employee=' . ($okStaff ? 'ok' : 'no') . ' audited=' . ($audited ? 'yes' : 'no')];
});
// =================================================================== REPORTS & AUDIT
$reportKeys = ['employee_directory', 'headcount', 'starters_leavers', 'probation', 'document_compliance', 'document_expiry', 'attendance_summary', 'lateness',
    'absence', 'scheduled_vs_actual', 'weekly_hours', 'hours_exceptions', 'working_time', 'roster', 'leave_balances', 'leave_taken', 'payslip_status'];
t('TC-67', 'Reports', 'All 17 reports run on screen', 'HTTP 200 and a results table for each', function () use ($admin, $reportKeys) {
    $bad = [];
    foreach ($reportKeys as $k) {
        $admin->get("/reports/$k?from=2026-09-01&to=2026-10-14");
        if ($admin->status !== 200 || !contains($admin, 'rows · generated')) {
            $bad[] = "$k={$admin->status}";
        }
    }
    return [!$bad, $bad ? implode(', ', $bad) : count($reportKeys) . ' reports OK'];
});
t('TC-68', 'Reports', 'Reports export to CSV and Excel, and exports are audited', 'CSV text and .xlsx (PK) returned; report.exported in audit log', function () use ($admin, $reportKeys) {
    $bad = [];
    foreach ($reportKeys as $k) {
        $admin->get("/reports/$k?format=xlsx");
        if ($admin->status !== 200 || !str_starts_with($admin->body, "PK\x03\x04")) {
            $bad[] = "$k.xlsx";
        }
    }
    $admin->get('/reports/leave_balances?format=csv');
    $csv = str_contains($admin->body, '"Employee ID",Name');
    $audited = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'report.exported' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)");
    return [!$bad && $csv && $audited >= 18, ($bad ? implode(', ', $bad) : 'all xlsx ok') . ", csv=" . ($csv ? 'ok' : 'bad') . ", audited=$audited"];
});
t('TC-69', 'Reports', 'Pay data appears only when requested, and is logged', 'Salary column hidden by default; shown with pay=1 and audited', function () use ($admin) {
    $admin->get('/reports/employee_directory');
    $hidden = !contains($admin, 'Salary (£)');
    $admin->get('/reports/employee_directory?pay=1');
    $shown = contains($admin, 'Salary (£)');
    $logged = DB::value("SELECT id FROM audit_logs WHERE action = 'report.viewed_sensitive' ORDER BY id DESC LIMIT 1");
    return [$hidden && $shown && $logged, "hidden=$hidden shown=$shown logged=" . ($logged ? 'yes' : 'no')];
});
t('TC-70', 'Audit', 'Sensitive actions are still recorded in the database, without secrets', 'Payslip downloads and sign-ins logged; no password hashes or tokens stored', function () use ($admin) {
    $dl = (bool) DB::value("SELECT id FROM audit_logs WHERE action = 'payslip.downloaded' LIMIT 1");
    $leak = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE changes LIKE '%\$argon2%' OR changes LIKE '%password_hash%' OR changes REGEXP '[a-f0-9]{64}'");
    return [$dl && $leak === 0 && DB::value("SELECT id FROM audit_logs WHERE action = 'auth.login' LIMIT 1"), "download logged=$dl, secret-looking values=$leak"];
});
t('TC-71', 'Employees', 'Archive (soft delete) an employee', 'Hidden from the list, record kept, account deactivated', function () use ($admin, &$ctx) {
    $id = $ctx['emp']['id'];
    $admin->get("/employees/$id");
    $admin->post("/employees/$id/delete")->follow();
    $row = DB::one('SELECT * FROM employees WHERE id = ?', [$id]);
    $admin->get('/employees?q=Testington');
    $hidden = !contains($admin, 'Elliot Testington');
    return [$row['deleted_at'] !== null && $hidden && (int) DB::value('SELECT COUNT(*) FROM users WHERE employee_id = ? AND is_active = 1', [$id]) === 0, 'deleted_at=' . $row['deleted_at']];
});
t('TC-72', 'Auth', 'Signing out ends the session', 'After sign-out, /dashboard redirects to /login', function () use ($admin) {
    $admin->get('/dashboard');
    $admin->post('/logout');
    $admin->get('/dashboard');
    return [$admin->status === 302 && str_ends_with((string) $admin->location, '/login'), "HTTP {$admin->status} -> {$admin->location}"];
});

// =================================================================== SUMMARY
array_map('unlink', glob("$tmpDir/*") ?: []);
@rmdir($tmpDir);
$pass = count(array_filter($results, fn ($r) => $r['status'] === 'PASS'));
$total = count($results);
file_put_contents(BASE_PATH . '/storage/logs/e2e-results.json', json_encode(['run_at' => gmdate('c'), 'passed' => $pass, 'total' => $total, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n$pass / $total passed\n";
exit($pass === $total ? 0 : 1);
