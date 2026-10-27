<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;

final class ShiftImportService
{
    public const HEADERS = [
        'employee id' => 'employee_code', 'employee code' => 'employee_code', 'date' => 'date', 'shift date' => 'date',
        'start' => 'start', 'start time' => 'start', 'end' => 'end', 'end time' => 'end', 'break minutes' => 'break_minutes', 'break' => 'break_minutes',
        'shift type' => 'shift_type', 'location' => 'location', 'status' => 'status', 'notes' => 'notes', 'employee name' => 'employee_name',
    ];
    public const REQUIRED = ['employee_code', 'date', 'start', 'end'];

    public static function validateFile(string $path, string $originalName, string $storageKey, int $userId): int
    {
        $rows = ExcelIO::readRows($path, self::HEADERS, self::REQUIRED);
        $types = [];
        foreach (DB::all('SELECT id, code, name FROM shift_types WHERE is_active = 1') as $t) {
            $types[strtoupper($t['code'])] = (int) $t['id'];
            $types[strtoupper($t['name'])] = (int) $t['id'];
        }
        $locations = [];
        foreach (DB::all('SELECT id, name FROM locations WHERE is_active = 1') as $l) {
            $locations[strtoupper($l['name'])] = (int) $l['id'];
        }
        $empCache = [];

        return DB::transaction(function () use ($rows, $types, $locations, &$empCache, $originalName, $storageKey, $userId) {
            $batchId = DB::insert('shift_import_batches', ['original_filename' => mb_substr($originalName, 0, 255), 'storage_key' => $storageKey, 'uploaded_by' => $userId]);
            $inFile = [];      // employee_id => list of [start,end,row]
            $weekAdd = [];     // employee_id|week => minutes added by earlier rows
            $counts = ['valid' => 0, 'duplicate' => 0, 'error' => 0];
            foreach ($rows as $row) {
                $v = $row['values'];
                $msgs = [];
                $warn = [];
                $code = strtoupper(trim((string) ($v['employee_code'] ?? '')));
                $emp = null;
                if ($code === '') {
                    $msgs[] = 'Employee ID missing';
                } else {
                    $emp = $empCache[$code] ??= DB::one('SELECT * FROM employees WHERE employee_code = ? AND deleted_at IS NULL', [$code]);
                    if (!$emp) {
                        $msgs[] = 'Employee ID not found';
                    } elseif ($emp['status'] === 'left') {
                        $msgs[] = 'Employee has left';
                    }
                }
                $date = ExcelIO::parseDate($v['date'] ?? null);
                if ($date === null) {
                    $msgs[] = ($v['date'] ?? null) === null || $v['date'] === '' ? 'Date missing' : 'Invalid date';
                }
                $start = ExcelIO::parseTime($v['start'] ?? null);
                $end = ExcelIO::parseTime($v['end'] ?? null);
                if ($start === null) {
                    $msgs[] = 'Invalid or missing Start time';
                }
                if ($end === null) {
                    $msgs[] = 'Invalid or missing End time';
                }
                $break = 0;
                $braw = $v['break_minutes'] ?? null;
                if ($braw !== null && $braw !== '') {
                    if (!is_numeric($braw) || (int) $braw != $braw || (int) $braw < 0) {
                        $msgs[] = 'Break Minutes must be a whole number of 0 or more';
                    } else {
                        $break = (int) $braw;
                    }
                }
                $typeId = null;
                if (($v['shift_type'] ?? '') !== '' && $v['shift_type'] !== null) {
                    $typeId = $types[strtoupper((string) $v['shift_type'])] ?? null;
                    if (!$typeId) {
                        $msgs[] = 'Unknown Shift Type';
                    }
                }
                $locId = null;
                if (($v['location'] ?? '') !== '' && $v['location'] !== null) {
                    $locId = $locations[strtoupper((string) $v['location'])] ?? null;
                    if (!$locId) {
                        $msgs[] = 'Unknown Location';
                    }
                }
                $status = strtolower(trim((string) ($v['status'] ?? '')));
                if ($status === '') {
                    $status = 'draft';
                } elseif (!in_array($status, ['draft', 'published'], true)) {
                    $msgs[] = 'Status must be Draft or Published';
                }
                $startUtc = $endUtc = null;
                $net = null;
                $result = 'ready';
                if (!$msgs) {
                    [$startUtc, $endUtc] = HoursCalculator::utcSpan($date, $start, $end);
                    try {
                        $net = HoursCalculator::netMinutes($startUtc, $endUtc, $break);
                        if ($net > 16 * 60) {
                            $msgs[] = 'Shift longer than 16 hours';
                        }
                    } catch (ValidationException $e) {
                        $msgs[] = rtrim($e->getMessage(), '.');
                    }
                }
                $existing = null;
                $info = [];
                if (!$msgs) {
                    $empId = (int) $emp['id'];
                    // A shift already in the system for this employee on this date is updated rather than reported as a clash.
                    $existing = self::existingShift($empId, $date, $startUtc, $endUtc);
                    if ($existing) {
                        $new = ['start_at' => $startUtc, 'end_at' => $endUtc, 'break_minutes' => $break,
                            'shift_type_id' => $typeId ?? $existing['shift_type_id'], 'location_id' => $locId ?? $existing['location_id'],
                            'status' => ($v['status'] ?? '') === '' || $v['status'] === null ? $existing['status'] : $status,
                            'notes' => mb_substr((string) ($v['notes'] ?? ''), 0, 255) ?: $existing['notes']];
                        $changes = self::shiftChanges($existing, $new);
                        if (!$changes) {
                            $result = 'duplicate';
                            $info[] = 'Already in the system: no changes';
                        } else {
                            $info[] = 'Updates the existing shift: ' . implode(', ', $changes);
                        }
                    }
                    if ($result !== 'duplicate' && DB::value("SELECT id FROM shifts WHERE employee_id = ? AND status <> 'cancelled' AND start_at < ? AND end_at > ? AND id <> ?",
                        [$empId, $endUtc, $startUtc, (int) ($existing['id'] ?? 0)])) {
                        $msgs[] = 'Overlaps another shift';
                    } elseif ($result !== 'duplicate') {
                        foreach ($inFile[$empId] ?? [] as [$s, $e, $rn]) {
                            if ($s < $endUtc && $e > $startUtc) {
                                $msgs[] = 'Overlaps row ' . $rn . ' in this file';
                                break;
                            }
                        }
                    }
                    if (!$msgs && $result !== 'duplicate') {
                        $inFile[$empId][] = [$startUtc, $endUtc, $row['row_no']];
                        $week = WeekBoundary::weekStartForUtc($startUtc);
                        $k = $empId . '|' . $week;
                        // An update replaces the existing shift's hours in its week.
                        $weekAdd[$k] = ($weekAdd[$k] ?? 0) + $net - ($existing && $existing['week_start'] === $week ? (int) $existing['net_minutes'] : 0);
                        $total = HoursCalculator::scheduledMinutes($empId, $week) + $weekAdd[$k];
                        $limit = HoursCalculator::limitMinutes($emp);
                        if ($total > $limit) {
                            $warn[] = sprintf('Week of %s would total %s h (limit %s h): needs approval', uk_date($week), hours($total, 1), hours($limit, 1));
                            $result = 'warning';
                        }
                    }
                }
                if ($msgs) {
                    $result = 'error';
                }
                if ($result !== 'error') {
                    $msgs = $info;   // not problems: what will happen to the existing shift
                }
                $counts[in_array($result, ['ready', 'warning'], true) ? 'valid' : ($result === 'duplicate' ? 'duplicate' : 'error')]++;
                DB::insert('shift_import_rows', [
                    'batch_id' => $batchId, 'row_no' => $row['row_no'], 'employee_code' => mb_substr($code, 0, 20), 'employee_id' => $emp['id'] ?? null,
                    'start_at' => $startUtc, 'end_at' => $endUtc, 'break_minutes' => $break, 'net_minutes' => $net, 'shift_type_id' => $typeId,
                    'location_id' => $locId, 'status' => in_array($status, ['draft', 'published'], true) ? $status : null,
                    'notes' => mb_substr((string) ($v['notes'] ?? ''), 0, 255) ?: null,
                    'raw_json' => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    'result' => $result, 'messages' => implode(' · ', array_merge($msgs, $warn)) ?: null,
                    'shift_id' => $result !== 'error' && $existing ? (int) $existing['id'] : null,
                ]);
            }
            DB::update('shift_import_batches', ['total_rows' => count($rows), 'valid_rows' => $counts['valid'], 'duplicate_rows' => $counts['duplicate'], 'error_rows' => $counts['error']], 'id = ?', [$batchId]);
            AuditLogger::log('shift_import.validated', 'shift_import_batch', $batchId, sprintf('Validated %s: %d rows, %d valid, %d duplicates, %d errors', $originalName, count($rows), $counts['valid'], $counts['duplicate'], $counts['error']));
            return $batchId;
        });
    }

    /**
     * Imports ready rows. Rows over the weekly limit are imported only when $approveOverLimit is true
     * (an hours exception is recorded with $reason); otherwise they are skipped.
     */
    public static function confirm(int $batchId, bool $approveOverLimit, string $reason, int $userId): array
    {
        if ($approveOverLimit && trim($reason) === '') {
            throw new ValidationException('Give a reason to approve hours over the limit.');
        }
        return DB::transaction(function () use ($batchId, $approveOverLimit, $reason, $userId) {
            $batch = DB::one('SELECT * FROM shift_import_batches WHERE id = ? FOR UPDATE', [$batchId]);
            if (!$batch || $batch['status'] !== 'validated') {
                throw new ValidationException('This import has already been completed or cancelled.');
            }
            $imported = $updated = $skipped = 0;
            $touched = [];
            foreach (DB::all("SELECT * FROM shift_import_rows WHERE batch_id = ? AND result IN ('ready','warning','duplicate') ORDER BY row_no", [$batchId]) as $r) {
                if ($r['result'] === 'duplicate' || ($r['result'] === 'warning' && !$approveOverLimit)) {
                    DB::update('shift_import_rows', ['result' => 'skipped'] + ($r['result'] === 'duplicate' ? [] : ['shift_id' => null]), 'id = ?', [(int) $r['id']]);
                    $skipped++;
                    continue;
                }
                $existing = $r['shift_id'] ? DB::one("SELECT * FROM shifts WHERE id = ? AND status <> 'cancelled' FOR UPDATE", [(int) $r['shift_id']]) : null;
                if ($r['shift_id'] && !$existing) {
                    DB::update('shift_import_rows', ['result' => 'skipped', 'messages' => 'The shift to update was cancelled since validation'], 'id = ?', [(int) $r['id']]);
                    $skipped++;
                    continue;
                }
                if (DB::value("SELECT id FROM shifts WHERE employee_id = ? AND status <> 'cancelled' AND start_at < ? AND end_at > ? AND id <> ?",
                    [(int) $r['employee_id'], $r['end_at'], $r['start_at'], (int) ($existing['id'] ?? 0)])) {
                    DB::update('shift_import_rows', ['result' => 'skipped', 'messages' => 'Overlaps a shift created since validation'], 'id = ?', [(int) $r['id']]);
                    $skipped++;
                    continue;
                }
                $week = WeekBoundary::weekStartForUtc($r['start_at']);
                $data = ['shift_type_id' => $r['shift_type_id'] ?? $existing['shift_type_id'] ?? null, 'location_id' => $r['location_id'] ?? $existing['location_id'] ?? null,
                    'start_at' => $r['start_at'], 'end_at' => $r['end_at'], 'break_minutes' => (int) $r['break_minutes'], 'net_minutes' => (int) $r['net_minutes'], 'week_start' => $week];
                if ($existing) {
                    $raw = json_decode($r['raw_json'], true) ?: [];
                    $data += ['status' => ($raw['status'] ?? '') === '' || $raw['status'] === null ? $existing['status'] : ($r['status'] ?: $existing['status']),
                        'notes' => $r['notes'] ?? $existing['notes']];
                    DB::update('shifts', $data, 'id = ?', [(int) $existing['id']]);
                    DB::update('shift_import_rows', ['result' => 'imported', 'messages' => $r['messages']], 'id = ?', [(int) $r['id']]);
                    AuditLogger::log('shift.updated', 'shift', (int) $existing['id'], 'Shift updated by shift import #' . $batchId . ': ' . mb_strimwidth((string) $r['messages'], 0, 200, '…'));
                    if ($existing['week_start'] !== $week) {
                        $touched[(int) $r['employee_id'] . '|' . $existing['week_start']] = [(int) $r['employee_id'], $existing['week_start']];
                    }
                    $updated++;
                } else {
                    $shiftId = DB::insert('shifts', $data + ['employee_id' => (int) $r['employee_id'], 'status' => $r['status'] ?: 'draft', 'notes' => $r['notes'],
                        'import_batch_id' => $batchId, 'created_by' => $userId]);
                    DB::update('shift_import_rows', ['result' => 'imported', 'shift_id' => $shiftId], 'id = ?', [(int) $r['id']]);
                    $imported++;
                }
                $touched[(int) $r['employee_id'] . '|' . $week] = [(int) $r['employee_id'], $week];
            }
            foreach ($touched as [$empId, $week]) {
                $emp = DB::one('SELECT * FROM employees WHERE id = ?', [$empId]);
                $total = HoursCalculator::scheduledMinutes($empId, $week);
                $limit = HoursCalculator::limitMinutes($emp);
                if ($total > $limit && $approveOverLimit) {
                    ShiftService::approveException($empId, $week, $limit, $total, 'Shift import #' . $batchId . ': ' . $reason, $userId);
                }
            }
            DB::update('shift_import_batches', ['status' => 'imported', 'imported_rows' => $imported + $updated, 'confirmed_at' => now_utc()], 'id = ?', [$batchId]);
            AuditLogger::log('shift_import.confirmed', 'shift_import_batch', $batchId, sprintf('Imported %d shifts, updated %d, skipped %d', $imported, $updated, $skipped));
            foreach (array_unique(array_map(fn ($t) => $t[0], $touched)) as $empId) {
                AttendanceService::fillFromRota((int) $empId, $userId);   // attendance follows the rota
            }
            return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
        });
    }

    /**
     * The employee's shift on this UK date that the imported row stands for: the only one that day, or the one
     * it overlaps or starts at the same time as. Null when there is none (or no single match).
     */
    private static function existingShift(int $employeeId, string $date, string $startUtc, string $endUtc): ?array
    {
        $dayStart = to_utc($date . ' 00:00');
        $dayEnd = to_utc((new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00');
        $sameDay = DB::all("SELECT * FROM shifts WHERE employee_id = ? AND status <> 'cancelled' AND start_at >= ? AND start_at < ? ORDER BY start_at", [$employeeId, $dayStart, $dayEnd]);
        if (count($sameDay) === 1) {
            return $sameDay[0];
        }
        $matches = array_values(array_filter($sameDay, fn ($s) => $s['start_at'] === $startUtc || ($s['start_at'] < $endUtc && $s['end_at'] > $startUtc)));
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** What an import row would change on an existing shift, in words. */
    private static function shiftChanges(array $old, array $new): array
    {
        $out = [];
        $time = fn ($utc) => from_utc($utc)->format('d/m H:i');
        if ($old['start_at'] !== $new['start_at']) {
            $out[] = 'start ' . $time($old['start_at']) . ' → ' . $time($new['start_at']);
        }
        if ($old['end_at'] !== $new['end_at']) {
            $out[] = 'end ' . $time($old['end_at']) . ' → ' . $time($new['end_at']);
        }
        if ((int) $old['break_minutes'] !== (int) $new['break_minutes']) {
            $out[] = 'break ' . (int) $old['break_minutes'] . ' → ' . (int) $new['break_minutes'] . ' min';
        }
        if ((int) $old['shift_type_id'] !== (int) $new['shift_type_id']) {
            $out[] = 'shift type';
        }
        if ((int) $old['location_id'] !== (int) $new['location_id']) {
            $out[] = 'location';
        }
        if ($old['status'] !== $new['status']) {
            $out[] = 'status ' . $old['status'] . ' → ' . $new['status'];
        }
        if ((string) $old['notes'] !== (string) $new['notes']) {
            $out[] = 'notes';
        }
        return $out;
    }

    public static function cancel(int $batchId): void
    {
        if (!DB::update('shift_import_batches', ['status' => 'cancelled'], "id = ? AND status = 'validated'", [$batchId])) {
            throw new ValidationException('Only imports awaiting confirmation can be cancelled.');
        }
        AuditLogger::log('shift_import.cancelled', 'shift_import_batch', $batchId, 'Cancelled shift import');
    }

    public static function errorReport(int $batchId): string
    {
        $out = [];
        foreach (DB::all("SELECT * FROM shift_import_rows WHERE batch_id = ? AND result IN ('error','duplicate','warning','skipped') ORDER BY row_no", [$batchId]) as $r) {
            $raw = json_decode($r['raw_json'], true) ?: [];
            $fmt = fn ($x) => is_float($x) || is_int($x) ? ($x >= 1 ? uk_date((string) ExcelIO::parseDate($x)) : (string) ExcelIO::parseTime($x)) : (string) $x;
            $out[] = [(int) $r['row_no'], (string) ($raw['employee_code'] ?? ''), $fmt($raw['date'] ?? ''), $fmt($raw['start'] ?? ''), $fmt($raw['end'] ?? ''),
                (string) ($raw['break_minutes'] ?? ''), (string) ($raw['shift_type'] ?? ''), (string) ($raw['location'] ?? ''), $r['result'], (string) $r['messages']];
        }
        return ExcelIO::build('Errors', ['Row', 'Employee ID', 'Date', 'Start', 'End', 'Break Minutes', 'Shift Type', 'Location', 'Result', 'Problem'], $out);
    }
}
