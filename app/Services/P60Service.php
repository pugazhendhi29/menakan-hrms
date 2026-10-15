<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use App\Core\ValidationException;

/**
 * Stores official P60 PDFs (they are never generated here). One current P60 per employee per
 * UK tax year (6 April to 5 April); replacing archives the old file, deleting keeps it for the record.
 */
final class P60Service
{
    /** Start year of the tax year containing $date: 5 April 2026 is in 2025/26, 6 April 2026 starts 2026/27. */
    public static function taxYearStartFor(string $date): int
    {
        $y = (int) substr($date, 0, 4);
        return substr($date, 5, 5) >= '04-06' ? $y : $y - 1;
    }

    /** "2025/26" */
    public static function label(int $start): string
    {
        return sprintf('%d/%02d', $start, ($start + 1) % 100);
    }

    /** "6 Apr 2025 – 5 Apr 2026" */
    public static function range(int $start): string
    {
        return "6 Apr $start – 5 Apr " . ($start + 1);
    }

    /** Most recent tax year that has ended (P60s are issued after the year ends). */
    public static function latestCompleted(?string $today = null): int
    {
        return self::taxYearStartFor($today ?? uk_today()) - 1;
    }

    /** Selectable tax years, newest first. */
    public static function yearOptions(int $count = 10): array
    {
        $last = self::latestCompleted();
        return range($last, $last - $count + 1);
    }

    public static function findEmployee(string $code): array
    {
        $code = strtoupper(trim($code));
        $e = $code === '' ? null : DB::one('SELECT id, employee_code, first_name, last_name FROM employees WHERE employee_code = ? AND deleted_at IS NULL', [$code]);
        if (!$e) {
            throw new ValidationException('No employee has the Employee ID "' . $code . '".', ['employee_code' => 'Employee ID not found.']);
        }
        return $e;
    }

    private static function checkYear(int $start): void
    {
        if ($start < 2000 || $start > self::latestCompleted()) {
            throw new ValidationException('Choose a tax year that has ended. The latest is ' . self::label(self::latestCompleted()) . '.', ['tax_year' => 'Choose a completed tax year.']);
        }
    }

    public static function current(int $employeeId, int $start): ?array
    {
        return DB::one('SELECT * FROM p60_documents WHERE employee_id = ? AND tax_year_start = ? AND is_current = 1', [$employeeId, $start]);
    }

    /**
     * Uploads a P60 for the employee with this Employee ID. A duplicate for the same tax year is refused
     * unless $replace is true, in which case the existing one is archived and the replacement is audited.
     */
    public static function upload(string $employeeCode, int $start, ?array $file, bool $replace, int $userId): int
    {
        self::checkYear($start);
        $e = self::findEmployee($employeeCode);
        $existing = self::current((int) $e['id'], $start);
        if ($existing && !$replace) {
            throw new ValidationException(sprintf('%s already has a P60 for %s (uploaded %s). Tick "Replace existing P60" to replace it.',
                $e['employee_code'], self::label($start), uk_date(substr($existing['created_at'], 0, 10))), ['employee_code' => 'A P60 already exists for this tax year.']);
        }
        [$ext, , $size] = FileStore::validateUpload($file, ['pdf'], Config::get('app.upload_limits.payslips'));
        $key = FileStore::store($file['tmp_name'], 'p60', $ext);
        $id = self::save($e, $start, $key, mb_substr(basename((string) $file['name']), 0, 255), $size, $existing, $userId);
        self::emailToEmployee($id);
        return $id;
    }

    /** Replaces a specific current P60 with a new file. */
    public static function replace(int $id, ?array $file, int $userId): int
    {
        $old = DB::one("SELECT * FROM p60_documents WHERE id = ? AND status = 'current'", [$id]);
        if (!$old) {
            throw new ValidationException('Only a current P60 can be replaced.');
        }
        $e = self::findEmployee($old['employee_code']);
        [$ext, , $size] = FileStore::validateUpload($file, ['pdf'], Config::get('app.upload_limits.payslips'));
        $key = FileStore::store($file['tmp_name'], 'p60', $ext);
        $id = self::save($e, (int) $old['tax_year_start'], $key, mb_substr(basename((string) $file['name']), 0, 255), $size, $old, $userId);
        self::emailToEmployee($id);
        return $id;
    }

    /**
     * Emails the P60 PDF to the address in the employee's Basic information (employees.email), from the
     * HR mailbox with the HR signature. Never blocks the upload; the outcome is stored on the P60.
     * @return array{sent:bool, to:?string, error:?string}
     */
    public static function emailToEmployee(int $id): array
    {
        $p = DB::one('SELECT p.*, e.first_name, e.email FROM p60_documents p JOIN employees e ON e.id = p.employee_id WHERE p.id = ?', [$id]);
        if (!$p) {
            return ['sent' => false, 'to' => null, 'error' => 'P60 not found'];
        }
        $to = trim((string) $p['email']);
        $year = self::label((int) $p['tax_year_start']);
        $error = null;
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $error = 'No valid email address in the employee Basic information';
        } else {
            try {
                Mailer::send($to, "Your P60 for the tax year $year - Menakan Transport",
                    "Dear {$p['first_name']},\n\nPlease find attached your P60 for the tax year $year (" . self::range((int) $p['tax_year_start']) . ").\n\n"
                    . "Your P60 shows your total pay and the tax deducted in that tax year. Please keep it safe: you may need it to claim back overpaid tax, apply for tax credits or as proof of income.\n\n"
                    . 'This email and its attachment are confidential. If you were not expecting it, please delete it and tell HR.',
                    [['path' => FileStore::path($p['storage_key']), 'name' => sprintf('P60_%s_%d-%02d.pdf', $p['employee_code'], $p['tax_year_start'], ($p['tax_year_start'] + 1) % 100), 'mime' => 'application/pdf']]);
            } catch (\Throwable $ex) {
                $error = mb_substr($ex->getMessage(), 0, 255);
            }
        }
        DB::update('p60_documents', ['emailed_to' => $to ?: null, 'emailed_at' => $error ? null : now_utc(), 'email_error' => $error], 'id = ?', [$id]);
        AuditLogger::log($error ? 'p60.email_failed' : 'p60.emailed', 'p60', $id,
            ($error ? 'P60 email not sent: ' . $error : "P60 emailed to $to") . " ($year, {$p['employee_code']})", ['employee_id' => (int) $p['employee_id']]);
        return ['sent' => $error === null, 'to' => $to ?: null, 'error' => $error];
    }

    private static function save(array $e, int $start, string $key, string $name, int $size, ?array $existing, int $userId): int
    {
        return DB::transaction(function () use ($e, $start, $key, $name, $size, $existing, $userId) {
            if ($existing) {
                DB::update('p60_documents', ['status' => 'replaced', 'is_current' => null, 'replaced_at' => now_utc()], 'id = ?', [(int) $existing['id']]);
            }
            $id = DB::insert('p60_documents', [
                'employee_id' => (int) $e['id'], 'employee_code' => $e['employee_code'], 'employee_name' => trim($e['first_name'] . ' ' . $e['last_name']),
                'tax_year_start' => $start, 'original_filename' => $name, 'storage_key' => $key, 'sha256' => FileStore::hash($key),
                'size_bytes' => $size, 'status' => 'current', 'is_current' => 1, 'uploaded_by' => $userId,
            ]);
            if ($existing) {
                DB::update('p60_documents', ['replaced_by_id' => $id], 'id = ?', [(int) $existing['id']]);
                AuditLogger::log('p60.replaced', 'p60', $id, sprintf('Replaced P60 %s for %s (previous #%d archived)', self::label($start), $e['employee_code'], $existing['id']),
                    ['employee_id' => (int) $e['id'], 'previous_id' => (int) $existing['id']]);
            } else {
                AuditLogger::log('p60.uploaded', 'p60', $id, sprintf('Uploaded P60 %s for %s', self::label($start), $e['employee_code']), ['employee_id' => (int) $e['id']]);
            }
            return $id;
        });
    }

    /**
     * Bulk upload for one tax year: each file name must start with the Employee ID (e.g. A001.pdf, A001_P60.pdf).
     * @return array<int, array{file:string, ok:bool, message:string}>
     */
    public static function bulk(array $files, int $start, bool $replace, int $userId): array
    {
        self::checkYear($start);
        if (!$files) {
            throw new ValidationException('Choose one or more PDF files.');
        }
        if (count($files) > 200) {
            throw new ValidationException('Upload at most 200 files at a time.');
        }
        [$prefix] = EmployeeService::codeFormat();
        $out = [];
        foreach ($files as $f) {
            $name = basename((string) $f['name']);
            try {
                if (!preg_match('/^\s*(' . preg_quote($prefix, '/') . '\d{1,6}|[A-Za-z]{1,4}\d{1,6})(?!\d)/i', pathinfo($name, PATHINFO_FILENAME), $m)) {
                    throw new ValidationException('The file name must start with the Employee ID, e.g. A001.pdf');
                }
                $id = self::upload($m[1], $start, $f, $replace, $userId);
                $row = DB::one('SELECT emailed_at, email_error FROM p60_documents WHERE id = ?', [$id]);
                $out[] = ['file' => $name, 'ok' => true, 'emailed' => $row['emailed_at'] !== null, 'email_error' => $row['email_error'],
                    'message' => strtoupper($m[1]) . ': ' . (DB::value('SELECT replaced_by_id FROM p60_documents WHERE replaced_by_id = ?', [$id]) ? 'replaced' : 'uploaded')];
            } catch (ValidationException $e) {
                $out[] = ['file' => $name, 'ok' => false, 'message' => $e->getMessage()];
            }
        }
        return $out;
    }

    /** Removes a P60 from use (employees can no longer see it). The file and record are kept for the audit trail. */
    public static function delete(int $id, string $reason, int $userId): void
    {
        $p = DB::one("SELECT * FROM p60_documents WHERE id = ? AND status = 'current'", [$id]);
        if (!$p) {
            throw new ValidationException('Only a current P60 can be deleted.');
        }
        if (trim($reason) === '') {
            throw new ValidationException('Give a reason for deleting this P60.', ['reason' => 'A reason is required.']);
        }
        DB::update('p60_documents', ['status' => 'deleted', 'is_current' => null, 'deleted_at' => now_utc(), 'deleted_by' => $userId,
            'deleted_reason' => mb_substr(trim($reason), 0, 255)], 'id = ?', [$id]);
        AuditLogger::log('p60.deleted', 'p60', $id, sprintf('Deleted P60 %s for %s: %s', self::label((int) $p['tax_year_start']), $p['employee_code'], trim($reason)),
            ['employee_id' => (int) $p['employee_id']]);
    }
}
