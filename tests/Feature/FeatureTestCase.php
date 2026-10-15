<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth;
use App\Core\Config;
use App\Core\DB;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Settings;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs requests through the real Kernel in-process against the test database.
 * The schema is rebuilt once per run; every test runs inside a transaction that is rolled back.
 */
abstract class FeatureTestCase extends TestCase
{
    private static bool $schemaLoaded = false;
    protected static array $f = [];
    protected Kernel $kernel;

    public static function setUpBeforeClass(): void
    {
        if (!self::$schemaLoaded) {
            $c = Config::get('database');
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']), $c['username'], $c['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(BASE_PATH . '/database/database.sql'));
            foreach (preg_split('/;\s*$/m', $sql) as $stmt) {
                if (trim($stmt) !== '') {
                    $pdo->exec($stmt);
                }
            }
            DB::setPdo(null);
            self::seedFixtures();
            self::$schemaLoaded = true;
        }
    }

    private static function seedFixtures(): void
    {
        $f = [];
        $f['dept'] = DB::insert('departments', ['name' => 'Drivers', 'code' => 'DRV']);
        $f['job'] = DB::insert('job_titles', ['title' => 'Van Driver', 'department_id' => $f['dept']]);
        $f['pattern'] = DB::insert('work_patterns', ['name' => 'Monday to Friday']);
        $f['annual'] = DB::insert('leave_types', ['name' => 'Annual leave', 'code' => 'AL', 'deducts_balance' => 1]);
        $f['sick'] = DB::insert('leave_types', ['name' => 'Sickness', 'code' => 'SICK', 'employee_can_request' => 0]);
        $f['cat'] = DB::insert('document_categories', ['name' => 'Right to work', 'code' => 'RTW']);
        DB::insert('document_requirements', ['category_id' => $f['cat']]);
        foreach ([['2026-12-25', 'Christmas Day'], ['2026-12-28', 'Boxing Day (substitute day)'], ['2026-08-31', 'Summer bank holiday'], ['2026-05-25', 'Spring bank holiday'],
                     ['2026-05-04', 'Early May bank holiday'], ['2026-04-06', 'Easter Monday'], ['2026-04-03', 'Good Friday'], ['2026-01-01', "New Year's Day"]] as [$d, $n]) {
            DB::insert('public_holidays', ['holiday_date' => $d, 'name' => $n]);
        }
        $mk = fn (string $first, string $last) => DB::insert('employees', [
            'employee_code' => \App\Services\EmployeeService::nextCode(), 'first_name' => $first, 'last_name' => $last, 'department_id' => $f['dept'],
            'job_title_id' => $f['job'], 'work_pattern_id' => $f['pattern'], 'start_date' => '2024-01-01', 'contract_hours' => 37.5, 'status' => 'active']);
        $f['empAdmin'] = $mk('Alice', 'Admin');
        $f['empA'] = $mk('Bob', 'Driver');
        $f['empB'] = $mk('Cara', 'Courier');
        $hash = password_hash('Password12345', PASSWORD_BCRYPT, ['cost' => 4]);
        $f['admin'] = DB::insert('users', ['username' => 'admin', 'role' => 'admin', 'employee_id' => $f['empAdmin'], 'password_hash' => $hash]);
        $f['admin2'] = DB::insert('users', ['username' => 'admin2', 'role' => 'admin', 'password_hash' => $hash]);
        $f['userA'] = DB::insert('users', ['username' => 'bob', 'role' => 'employee', 'employee_id' => $f['empA'], 'password_hash' => $hash]);
        $f['userB'] = DB::insert('users', ['username' => 'cara', 'role' => 'employee', 'employee_id' => $f['empB'], 'password_hash' => $hash]);
        self::$f = $f;
    }

    protected function setUp(): void
    {
        $this->kernel = new Kernel();
        $_SESSION = [];
        Settings::flush();
        Auth::reset();
        DB::pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::pdo()->inTransaction()) {
            DB::pdo()->rollBack();
        }
        $_SESSION = [];
        Auth::reset();
    }

    protected function actingAs(int $userId): void
    {
        $_SESSION = [];
        Session::start();
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        Auth::login($user, new Request('GET', '/', server: ['REMOTE_ADDR' => '127.0.0.1']));
    }

    protected function get(string $path, array $query = []): Response
    {
        return $this->kernel->handle(new Request('GET', $path, $query, [], [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    protected function post(string $path, array $data = [], bool $withCsrf = true): Response
    {
        Session::start();
        if ($withCsrf) {
            $data['_csrf'] = Session::csrfToken();
        }
        return $this->kernel->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    /** A sample URL for a route pattern, with every parameter filled in. */
    protected function sampleUrl(string $pattern): string
    {
        return preg_replace_callback('/\{(\w+)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}/', fn ($m) => match ($m[1]) {
            'token' => str_repeat('a', str_contains($m[2] ?? '', '16') ? 16 : 64),
            'key', 'table' => 'departments',
            'kind' => 'experience',
            default => '1',
        }, $pattern);
    }

    protected function storedPdf(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tmp, "%PDF-1.4\n% test\n%%EOF\n");
        return \App\Services\FileStore::storeExisting($tmp, 'payslips', 'pdf');
    }
}
