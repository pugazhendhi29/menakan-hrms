<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use App\Core\Session;
use App\Core\ValidationException;

final class PayslipService
{
    public static function hasCurrent(int $employeeId, int $year, int $month): bool
    {
        return (bool) DB::value('SELECT id FROM payslips WHERE employee_id = ? AND period_year = ? AND period_month = ? AND is_current = 1', [$employeeId, $year, $month]);
    }

    private static function checkPeriod(int $year, int $month): void
    {
        if ($year < 2000 || $year > (int) date('Y') + 1 || $month < 1 || $month > 12) {
            throw new ValidationException('Choose a valid pay period.');
        }
    }

    /** Saves a stored file as the current draft payslip for a period; the previous version (if any) is archived. */
    public static function saveStored(int $employeeId, int $year, int $month, string $key, string $originalName, int $size, int $userId): int
    {
        self::checkPeriod($year, $month);
        $emp = DB::one('SELECT id, employee_code FROM employees WHERE id = ? AND deleted_at IS NULL', [$employeeId]);
        if (!$emp) {
            throw new ValidationException('Choose an employee.');
        }
        return DB::transaction(function () use ($employeeId, $year, $month, $key, $originalName, $size, $userId, $emp) {
            $old = DB::one('SELECT id FROM payslips WHERE employee_id = ? AND period_year = ? AND period_month = ? AND is_current = 1 FOR UPDATE', [$employeeId, $year, $month]);
            if ($old) {
                DB::update('payslips', ['is_current' => null, 'status' => 'archived', 'archived_at' => now_utc()], 'id = ?', [(int) $old['id']]);
            }
            $id = DB::insert('payslips', [
                'employee_id' => $employeeId, 'period_year' => $year, 'period_month' => $month,
                'original_filename' => mb_substr($originalName, 0, 255), 'storage_key' => $key, 'sha256' => FileStore::hash($key),
                'size_bytes' => $size, 'status' => 'draft', 'is_current' => 1, 'uploaded_by' => $userId,
            ]);
            AuditLogger::log($old ? 'payslip.replaced' : 'payslip.uploaded', 'payslip', $id,
                sprintf('%s payslip for %s, %s %d%s', $old ? 'Replaced' : 'Uploaded', $emp['employee_code'], month_name($month), $year, $old ? ' (previous #' . $old['id'] . ' archived)' : ''));
            return $id;
        });
    }

    public static function uploadSingle(int $employeeId, int $year, int $month, ?array $file, int $userId): int
    {
        self::checkPeriod($year, $month);
        [$ext, , $size] = FileStore::validateUpload($file, ['pdf'], Config::get('app.upload_limits.payslips'));
        $key = FileStore::store($file['tmp_name'], 'payslips', $ext);
        $id = self::saveStored($employeeId, $year, $month, $key, (string) $file['name'], $size, $userId);
        self::emailToEmployee($id);
        return $id;
    }

    /**
     * Emails the payslip PDF to the address in the employee's Basic information (employees.email).
     * Never blocks the upload: the outcome is stored on the payslip (emailed_to / emailed_at / email_error).
     * @return array{sent:bool, to:?string, error:?string}
     */
    public static function emailToEmployee(int $payslipId): array
    {
        $p = DB::one('SELECT p.*, e.employee_code, e.first_name, e.last_name, e.email FROM payslips p JOIN employees e ON e.id = p.employee_id WHERE p.id = ?', [$payslipId]);
        if (!$p) {
            return ['sent' => false, 'to' => null, 'error' => 'Payslip not found'];
        }
        $to = trim((string) $p['email']);
        $period = month_name((int) $p['period_month']) . ' ' . $p['period_year'];
        $error = null;
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $error = 'No valid email address in the employee Basic information';
        } else {
            try {
                Mailer::send($to, "Your payslip for $period - Menakan Transport",
                    "Dear {$p['first_name']},\n\nPlease find attached your payslip for $period.\n\nThis email and its attachment are confidential. If you were not expecting it, please delete it and tell HR.",
                    [['path' => FileStore::path($p['storage_key']), 'name' => sprintf('Payslip_%s_%04d-%02d.pdf', $p['employee_code'], $p['period_year'], $p['period_month']), 'mime' => 'application/pdf']]);
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 255);
            }
        }
        DB::update('payslips', ['emailed_to' => $to ?: null, 'emailed_at' => $error ? null : now_utc(), 'email_error' => $error], 'id = ?', [$payslipId]);
        AuditLogger::log($error ? 'payslip.email_failed' : 'payslip.emailed', 'payslip', $payslipId,
            ($error ? 'Payslip email not sent: ' . $error : "Payslip emailed to $to") . " ($period, {$p['employee_code']})", ['employee_id' => (int) $p['employee_id']]);
        return ['sent' => $error === null, 'to' => $to ?: null, 'error' => $error];
    }

    /**
     * Bulk step 1: validates each PDF, moves it to private temp storage and suggests a match.
     * The batch is held in the session until the admin confirms.
     */
    public static function prepareBulk(array $files, int $userId): array
    {
        if (!$files) {
            throw new ValidationException('Choose one or more PDF files.');
        }
        if (count($files) > 200) {
            throw new ValidationException('Upload at most 200 files at a time.');
        }
        $employees = DB::all('SELECT id, employee_code, first_name, last_name FROM employees WHERE deleted_at IS NULL');
        [$prefix, $pad] = EmployeeService::codeFormat();
        $items = [];
        $seenPeriods = [];
        foreach ($files as $i => $f) {
            $item = ['index' => $i, 'name' => mb_substr(basename((string) $f['name']), 0, 255), 'error' => null, 'tmp_key' => null];
            try {
                [$ext, , $size] = FileStore::validateUpload($f, ['pdf'], Config::get('app.upload_limits.payslips'));
                $item['tmp_key'] = FileStore::store($f['tmp_name'], 'tmp', $ext);
                $item['size'] = $size;
                $m = PayslipMatcher::match($item['name'], $employees, fn ($e, $y, $mo) => self::hasCurrent($e, $y, $mo), $prefix, $pad);
                $k = $m['employee_id'] . '|' . $m['year'] . '|' . $m['month'];
                if ($m['employee_id'] && isset($seenPeriods[$k])) {
                    $m['status'] = PayslipMatcher::DUPLICATE;
                }
                $seenPeriods[$k] = true;
                $item += $m;
            } catch (ValidationException $e) {
                $item['error'] = $e->getMessage();
                $item['status'] = 'invalid_file';
            }
            $items[] = $item;
        }
        $token = bin2hex(random_bytes(8));
        Session::set('payslip_bulk_' . $token, ['items' => $items, 'user_id' => $userId, 'created' => time()]);
        return ['token' => $token, 'items' => $items];
    }

    /** Bulk step 2: saves the rows the admin ticked, using the employee/period they confirmed. */
    public static function confirmBulk(string $token, array $choices, int $userId): array
    {
        $batch = Session::get('payslip_bulk_' . preg_replace('/[^a-f0-9]/', '', $token));
        if (!$batch || $batch['user_id'] !== $userId) {
            throw new ValidationException('This upload has expired. Please upload the files again.');
        }
        $saved = $skipped = $emailed = 0;
        $errors = [];
        foreach ($batch['items'] as $item) {
            $c = $choices[$item['index']] ?? null;
            if (!$item['tmp_key'] || !$c || empty($c['confirm'])) {
                $skipped++;
                continue;
            }
            $emp = (int) ($c['employee_id'] ?? 0);
            $year = (int) ($c['year'] ?? 0);
            $month = (int) ($c['month'] ?? 0);
            try {
                $key = FileStore::storeExisting(FileStore::path($item['tmp_key']), 'payslips', 'pdf');
                $newId = self::saveStored($emp, $year, $month, $key, $item['name'], (int) $item['size'], $userId);
                $saved++;
                $mail = self::emailToEmployee($newId);
                if ($mail['sent']) {
                    $emailed++;
                } else {
                    $errors[] = $item['name'] . ': saved, but not emailed (' . $mail['error'] . ')';
                }
            } catch (ValidationException $e) {
                $errors[] = $item['name'] . ': ' . $e->getMessage();
            }
        }
        Session::forget('payslip_bulk_' . $token);
        return ['saved' => $saved, 'skipped' => $skipped, 'emailed' => $emailed, 'errors' => $errors];
    }

    public static function publish(array $ids, int $userId): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $rows = DB::all("SELECT id, employee_id, period_year, period_month FROM payslips WHERE status = 'draft' AND is_current = 1 AND id IN (" . DB::placeholders($ids) . ')', $ids);
        foreach ($rows as $r) {
            DB::update('payslips', ['status' => 'published', 'published_by' => $userId, 'published_at' => now_utc()], 'id = ?', [(int) $r['id']]);
            Notifier::toEmployee((int) $r['employee_id'], 'payslip', 'Payslip available: ' . month_name((int) $r['period_month']) . ' ' . $r['period_year'], null, '/my/payslips');
        }
        if ($rows) {
            AuditLogger::log('payslip.published', 'payslip', null, 'Published ' . count($rows) . ' payslips', ['ids' => array_column($rows, 'id')]);
        }
        return count($rows);
    }

    public static function archive(int $id): void
    {
        $p = DB::one('SELECT * FROM payslips WHERE id = ? AND is_current = 1', [$id]);
        if (!$p) {
            throw new ValidationException('Payslip not found.');
        }
        DB::update('payslips', ['status' => 'archived', 'is_current' => null, 'archived_at' => now_utc()], 'id = ?', [$id]);
        AuditLogger::log('payslip.archived', 'payslip', $id, 'Archived payslip #' . $id);
    }
}
