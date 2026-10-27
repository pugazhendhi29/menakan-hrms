<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;

/**
 * Upload -> validate -> preview -> confirm. Validation writes every row to
 * attendance_import_rows with its raw values and messages; nothing touches
 * attendance_records until the admin confirms.
 */
final class AttendanceImportService
{
    public const HEADERS = [
        'employee id' => 'employee_code', 'employee code' => 'employee_code', 'employee name' => 'employee_name', 'name' => 'employee_name',
        'work date' => 'work_date', 'date' => 'work_date', 'check in' => 'check_in', 'check-in' => 'check_in', 'check out' => 'check_out',
        'check-out' => 'check_out', 'break minutes' => 'break_minutes', 'break' => 'break_minutes', 'notes' => 'notes',
    ];
    public const REQUIRED = ['employee_code', 'work_date', 'check_in', 'check_out'];

    /** Validates one parsed row. Pure apart from the lookups passed in, so it is unit-testable. */
    public static function validateRow(array $v, callable $findEmployee, string $today): array
    {
        $msgs = [];
        $code = strtoupper(trim((string) ($v['employee_code'] ?? '')));
        $emp = null;
        if ($code === '') {
            $msgs[] = 'Employee ID missing';
        } else {
            $emp = $findEmployee($code);
            if (!$emp) {
                $msgs[] = 'Employee ID not found';
            } elseif ($emp['status'] === 'left') {
                $msgs[] = 'Employee has left';
            }
        }
        $date = ExcelIO::parseDate($v['work_date'] ?? null);
        if ($date === null) {
            $msgs[] = ($v['work_date'] ?? '') === '' || ($v['work_date'] ?? null) === null ? 'Work Date missing' : 'Invalid date';
        } elseif ($date > $today) {
            $msgs[] = 'Work Date is in the future';
        }
        $in = ExcelIO::parseTime($v['check_in'] ?? null);
        if ($in === null) {
            $msgs[] = ($v['check_in'] ?? null) === null || $v['check_in'] === '' ? 'Check In missing' : 'Invalid Check In time';
        }
        $out = ExcelIO::parseTime($v['check_out'] ?? null);
        if ($out === null) {
            $msgs[] = ($v['check_out'] ?? null) === null || $v['check_out'] === '' ? 'Check Out missing' : 'Invalid Check Out time';
        }
        $breakRaw = $v['break_minutes'] ?? null;
        $break = 0;
        if ($breakRaw !== null && $breakRaw !== '') {
            if (!is_numeric($breakRaw) || (int) $breakRaw != $breakRaw || (int) $breakRaw < 0) {
                $msgs[] = 'Break Minutes must be a whole number of 0 or more';
            } else {
                $break = (int) $breakRaw;
            }
        }
        $result = ['employee' => $emp, 'employee_code' => $code, 'work_date' => $date, 'check_in_at' => null, 'check_out_at' => null,
            'break_minutes' => $break, 'net_minutes' => null, 'overnight' => false];
        if ($date !== null && $in !== null && $out !== null) {
            [$inUtc, $outUtc, $overnight] = HoursCalculator::utcSpan($date, $in, $out);
            $result['check_in_at'] = $inUtc;
            $result['check_out_at'] = $outUtc;
            $result['overnight'] = $overnight;
            try {
                $result['net_minutes'] = HoursCalculator::netMinutes($inUtc, $outUtc, $break);
                if ($result['net_minutes'] > 16 * 60) {
                    $msgs[] = 'Shift longer than 16 hours';
                }
            } catch (ValidationException $e) {
                $msgs[] = rtrim($e->getMessage(), '.');
            }
        }
        $result['messages'] = $msgs;
        return $result;
    }

    public static function validateFile(string $path, string $originalName, string $storageKey, int $userId): int
    {
        $rows = ExcelIO::readRows($path, self::HEADERS, self::REQUIRED);
        $cache = [];
        $find = function (string $code) use (&$cache) {
            return $cache[$code] ??= DB::one('SELECT id, employee_code, first_name, last_name, status FROM employees WHERE employee_code = ? AND deleted_at IS NULL', [$code]);
        };
        $today = uk_today();

        return DB::transaction(function () use ($rows, $find, $today, $originalName, $storageKey, $userId) {
            $batchId = DB::insert('attendance_import_batches', ['original_filename' => mb_substr($originalName, 0, 255), 'storage_key' => $storageKey, 'uploaded_by' => $userId]);
            $seen = [];
            $counts = ['valid' => 0, 'duplicate' => 0, 'error' => 0];
            foreach ($rows as $row) {
                $r = self::validateRow($row['values'], $find, $today);
                $result = 'ready';
                $existingId = null;
                $info = null;
                if (!$r['messages'] && $r['employee']) {
                    $key = $r['employee']['id'] . '|' . $r['work_date'] . '|' . $r['check_in_at'];
                    if (isset($seen[$key])) {
                        $r['messages'][] = 'Duplicate of row ' . $seen[$key] . ' in this file';
                    } else {
                        $seen[$key] = $row['row_no'];
                        // A record already in the system for this employee and day is updated rather than reported as a clash.
                        $existing = self::existingRecord((int) $r['employee']['id'], $r['work_date'], $r['check_in_at'], $r['check_out_at']);
                        if ($existing) {
                            $existingId = (int) $existing['id'];
                            $changes = self::recordChanges($existing, $r + ['notes' => $row['values']['notes'] ?? null]);
                            $locked = AttendanceService::isLocked($existing);
                            $result = $existing['is_approved'] || $locked ? 'duplicate_approved' : 'duplicate';
                            $info = $locked ? 'Recorded from the rota: kept as it is (it can only be changed on a leave day)'
                                : ($existing['is_approved'] ? 'Approved record: kept as it is' . ($changes ? ' (the file has ' . implode(', ', $changes) . ')' : '')
                                : ($changes ? 'Updates the existing record: ' . implode(', ', $changes) : 'Already in the system: no changes'));
                        }
                        if ((!$existing || $result === 'duplicate') && DB::value('SELECT id FROM attendance_records WHERE employee_id = ? AND id <> ? AND check_in_at < ? AND COALESCE(check_out_at, DATE_ADD(check_in_at, INTERVAL 16 HOUR)) > ?',
                            [(int) $r['employee']['id'], (int) $existingId, $r['check_out_at'], $r['check_in_at']])) {
                            $r['messages'][] = 'Overlaps another attendance record';
                        }
                    }
                }
                if ($r['messages']) {
                    $result = 'error';
                    $existingId = null;
                }
                $counts[$result === 'ready' ? 'valid' : ($result === 'error' ? 'error' : 'duplicate')]++;
                DB::insert('attendance_import_rows', [
                    'batch_id' => $batchId, 'row_no' => $row['row_no'], 'employee_code' => mb_substr($r['employee_code'], 0, 20),
                    'employee_name' => $r['employee'] ? $r['employee']['first_name'] . ' ' . $r['employee']['last_name'] : mb_substr((string) ($row['values']['employee_name'] ?? ''), 0, 120),
                    'employee_id' => $r['employee']['id'] ?? null, 'work_date' => $r['work_date'], 'check_in_at' => $r['check_in_at'],
                    'check_out_at' => $r['check_out_at'], 'break_minutes' => $r['break_minutes'], 'net_minutes' => $r['net_minutes'],
                    'notes' => mb_substr((string) ($row['values']['notes'] ?? ''), 0, 255) ?: null,
                    'raw_json' => json_encode($row['values'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    'result' => $result, 'messages' => $r['messages'] ? implode(' · ', $r['messages']) : ($info ?? ($r['overnight'] ? 'overnight' : null)),
                    'existing_record_id' => $existingId,
                ]);
            }
            DB::update('attendance_import_batches', ['total_rows' => count($rows), 'valid_rows' => $counts['valid'], 'duplicate_rows' => $counts['duplicate'],
                'error_rows' => $counts['error']], 'id = ?', [$batchId]);
            AuditLogger::log('attendance_import.validated', 'attendance_import_batch', $batchId, sprintf('Validated %s: %d rows, %d valid, %d duplicates, %d errors', $originalName, count($rows), $counts['valid'], $counts['duplicate'], $counts['error']));
            return $batchId;
        });
    }

    /**
     * Imports ready rows. Records already in the system are updated where the file differs (mode "update", the
     * default; "skip" leaves them alone). Every changed field is kept in the record's corrections. Approved
     * records are never overwritten.
     */
    public static function confirm(int $batchId, string $mode, int $userId): array
    {
        if (!in_array($mode, ['skip', 'update'], true)) {
            $mode = 'update';
        }
        return DB::transaction(function () use ($batchId, $mode, $userId) {
            $batch = DB::one('SELECT * FROM attendance_import_batches WHERE id = ? FOR UPDATE', [$batchId]);
            if (!$batch || $batch['status'] !== 'validated') {
                throw new ValidationException('This import has already been completed or cancelled.');
            }
            $imported = $updated = $skipped = 0;
            foreach (DB::all("SELECT * FROM attendance_import_rows WHERE batch_id = ? AND result IN ('ready','duplicate','duplicate_approved') ORDER BY row_no", [$batchId]) as $row) {
                $shift = AttendanceService::matchShift((int) $row['employee_id'], $row['check_in_at']);
                $status = AttendanceService::statusFor($shift, $row['check_in_at']);
                if ($row['result'] === 'ready') {
                    try {
                        DB::insert('attendance_records', [
                            'employee_id' => (int) $row['employee_id'], 'work_date' => $row['work_date'], 'check_in_at' => $row['check_in_at'],
                            'check_out_at' => $row['check_out_at'], 'break_minutes' => (int) $row['break_minutes'], 'net_minutes' => (int) $row['net_minutes'],
                            'status' => $status, 'source' => 'import', 'shift_id' => $shift['id'] ?? null, 'import_batch_id' => $batchId,
                            'notes' => $row['notes'], 'created_by' => $userId,
                        ]);
                        DB::update('attendance_import_rows', ['result' => 'imported'], 'id = ?', [(int) $row['id']]);
                        $imported++;
                    } catch (\PDOException $e) {
                        if ($e->getCode() !== '23000') {
                            throw $e;
                        }
                        DB::update('attendance_import_rows', ['result' => 'skipped', 'messages' => 'Conflicts with a record created since validation'], 'id = ?', [(int) $row['id']]);
                        $skipped++;
                    }
                    continue;
                }
                $existing = DB::one('SELECT * FROM attendance_records WHERE id = ? FOR UPDATE', [(int) $row['existing_record_id']]);
                $changes = $existing ? self::recordChanges($existing, $row) : [];
                $clash = $existing && DB::value('SELECT id FROM attendance_records WHERE employee_id = ? AND id <> ? AND check_in_at < ? AND COALESCE(check_out_at, DATE_ADD(check_in_at, INTERVAL 16 HOUR)) > ?',
                    [(int) $row['employee_id'], (int) $existing['id'], $row['check_out_at'], $row['check_in_at']]);
                $locked = $existing && AttendanceService::isLocked($existing);
                if ($row['result'] === 'duplicate' && $mode === 'update' && $existing && !$existing['is_approved'] && !$locked && $changes && !$clash) {
                    $new = ($existing['source'] === 'rota' ? ['source' => 'import'] : []) + ['check_in_at' => $row['check_in_at'], 'check_out_at' => $row['check_out_at'], 'break_minutes' => (int) $row['break_minutes'],
                        'net_minutes' => (int) $row['net_minutes'], 'status' => $status, 'shift_id' => $shift['id'] ?? $existing['shift_id'], 'notes' => $row['notes'] ?? $existing['notes']];
                    foreach (['check_in_at', 'check_out_at', 'break_minutes', 'notes'] as $f) {
                        if ((string) $existing[$f] !== (string) $new[$f]) {
                            DB::insert('attendance_corrections', ['attendance_id' => (int) $existing['id'], 'field' => $f, 'old_value' => $existing[$f],
                                'new_value' => $new[$f], 'reason' => 'Updated by attendance import #' . $batchId, 'changed_by' => $userId, 'changed_at' => now_utc()]);
                        }
                    }
                    DB::update('attendance_records', $new, 'id = ?', [(int) $existing['id']]);
                    DB::update('attendance_import_rows', ['result' => 'updated', 'messages' => 'Updated: ' . implode(', ', $changes)], 'id = ?', [(int) $row['id']]);
                    $updated++;
                } else {
                    $note = match (true) {
                        !$existing => 'The record was removed since validation',
                        $locked => 'Recorded from the rota: kept as it is (it can only be changed on a leave day)',
                        $row['result'] === 'duplicate_approved' || (bool) $existing['is_approved'] => 'Approved record kept as it is: change it on the Attendance page',
                        !$changes => 'Already in the system: no changes',
                        $clash => 'Overlaps another attendance record',
                        default => 'Left as it is',
                    };
                    DB::update('attendance_import_rows', ['result' => 'skipped', 'messages' => $note], 'id = ?', [(int) $row['id']]);
                    $skipped++;
                }
            }
            DB::update('attendance_import_batches', ['status' => 'imported', 'duplicate_mode' => $mode, 'imported_rows' => $imported, 'updated_rows' => $updated, 'confirmed_at' => now_utc()], 'id = ?', [$batchId]);
            AuditLogger::log('attendance_import.confirmed', 'attendance_import_batch', $batchId, sprintf('Imported %d attendance rows, updated %d, skipped %d', $imported, $updated, $skipped));
            return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
        });
    }

    /**
     * The employee's attendance record on this work date that an imported row stands for: the only one that day,
     * or the one it overlaps or starts at the same time as. Null when there is none (or no single match).
     */
    private static function existingRecord(int $employeeId, string $workDate, string $inUtc, string $outUtc): ?array
    {
        $sameDay = DB::all('SELECT * FROM attendance_records WHERE employee_id = ? AND work_date = ? ORDER BY check_in_at', [$employeeId, $workDate]);
        if (count($sameDay) === 1) {
            return $sameDay[0];
        }
        $matches = array_values(array_filter($sameDay, fn ($a) => $a['check_in_at'] === $inUtc
            || ($a['check_in_at'] < $outUtc && ($a['check_out_at'] ?? gmdate('Y-m-d H:i:s', strtotime($a['check_in_at'] . ' +16 hours'))) > $inUtc)));
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** What an imported row would change on an existing record, in words. Empty notes keep the current notes. */
    private static function recordChanges(array $old, array $new): array
    {
        $out = [];
        foreach (['check_in_at' => 'check in', 'check_out_at' => 'check out'] as $f => $label) {
            if ((string) $old[$f] !== (string) $new[$f]) {
                $out[] = $label . ' ' . ($old[$f] ? uk_time($old[$f]) : '—') . ' → ' . ($new[$f] ? uk_time($new[$f]) : '—');
            }
        }
        if ((int) $old['break_minutes'] !== (int) $new['break_minutes']) {
            $out[] = 'break ' . (int) $old['break_minutes'] . ' → ' . (int) $new['break_minutes'] . ' min';
        }
        $notes = trim((string) ($new['notes'] ?? ''));
        if ($notes !== '' && $notes !== (string) $old['notes']) {
            $out[] = 'notes';
        }
        return $out;
    }

    public static function cancel(int $batchId): void
    {
        $n = DB::update('attendance_import_batches', ['status' => 'cancelled'], "id = ? AND status = 'validated'", [$batchId]);
        if (!$n) {
            throw new ValidationException('Only imports awaiting confirmation can be cancelled.');
        }
        AuditLogger::log('attendance_import.cancelled', 'attendance_import_batch', $batchId, 'Cancelled attendance import');
    }

    public static function errorReport(int $batchId): string
    {
        $rows = DB::all("SELECT * FROM attendance_import_rows WHERE batch_id = ? AND result IN ('error','duplicate','duplicate_approved','skipped') ORDER BY row_no", [$batchId]);
        $out = [];
        foreach ($rows as $r) {
            $raw = json_decode($r['raw_json'], true) ?: [];
            $out[] = [(int) $r['row_no'], (string) ($raw['employee_code'] ?? ''), (string) ($raw['employee_name'] ?? $r['employee_name'] ?? ''), self::rawText($raw['work_date'] ?? ''),
                self::rawText($raw['check_in'] ?? ''), self::rawText($raw['check_out'] ?? ''), (string) ($raw['break_minutes'] ?? ''), $r['result'], (string) $r['messages']];
        }
        return ExcelIO::build('Errors', ['Row', 'Employee ID', 'Employee Name', 'Work Date', 'Check In', 'Check Out', 'Break Minutes', 'Result', 'Problem'], $out);
    }

    private static function rawText(mixed $v): string
    {
        if (is_float($v) || is_int($v)) {
            return $v >= 1 ? (ExcelIO::parseDate($v) ? uk_date(ExcelIO::parseDate($v)) : (string) $v) : (string) ExcelIO::parseTime($v);
        }
        return (string) $v;
    }
}
