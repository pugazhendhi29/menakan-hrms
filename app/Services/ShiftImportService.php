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
                if (!$msgs) {
                    $empId = (int) $emp['id'];
                    $same = DB::value("SELECT id FROM shifts WHERE employee_id = ? AND start_at = ? AND end_at = ? AND status <> 'cancelled'", [$empId, $startUtc, $endUtc]);
                    if ($same) {
                        $result = 'duplicate';
                        $msgs[] = 'Same shift already exists';
                    } elseif (DB::value("SELECT id FROM shifts WHERE employee_id = ? AND status <> 'cancelled' AND start_at < ? AND end_at > ?", [$empId, $endUtc, $startUtc])) {
                        $msgs[] = 'Overlaps an existing shift';
                    } else {
                        foreach ($inFile[$empId] ?? [] as [$s, $e, $rn]) {
                            if ($s < $endUtc && $e > $startUtc) {
                                $msgs[] = 'Overlaps row ' . $rn . ' in this file';
                                break;
                            }
                        }
                    }
                    if (!$msgs) {
                        $inFile[$empId][] = [$startUtc, $endUtc, $row['row_no']];
                        $week = WeekBoundary::weekStartForUtc($startUtc);
                        $k = $empId . '|' . $week;
                        $weekAdd[$k] = ($weekAdd[$k] ?? 0) + $net;
                        $total = HoursCalculator::scheduledMinutes($empId, $week) + $weekAdd[$k];
                        $limit = HoursCalculator::limitMinutes($emp);
                        if ($total > $limit) {
                            $warn[] = sprintf('Week of %s would total %s h (limit %s h): needs approval', uk_date($week), hours($total, 1), hours($limit, 1));
                            $result = 'warning';
                        }
                    }
                }
                if ($msgs && $result !== 'duplicate') {
                    $result = 'error';
                }
                $counts[in_array($result, ['ready', 'warning'], true) ? 'valid' : ($result === 'duplicate' ? 'duplicate' : 'error')]++;
                DB::insert('shift_import_rows', [
                    'batch_id' => $batchId, 'row_no' => $row['row_no'], 'employee_code' => mb_substr($code, 0, 20), 'employee_id' => $emp['id'] ?? null,
                    'start_at' => $startUtc, 'end_at' => $endUtc, 'break_minutes' => $break, 'net_minutes' => $net, 'shift_type_id' => $typeId,
                    'location_id' => $locId, 'status' => in_array($status, ['draft', 'published'], true) ? $status : null,
                    'notes' => mb_substr((string) ($v['notes'] ?? ''), 0, 255) ?: null,
                    'raw_json' => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    'result' => $result, 'messages' => implode(' · ', array_merge($msgs, $warn)) ?: null,
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
            $imported = $skipped = 0;
            $touched = [];
            foreach (DB::all("SELECT * FROM shift_import_rows WHERE batch_id = ? AND result IN ('ready','warning','duplicate') ORDER BY row_no", [$batchId]) as $r) {
                if ($r['result'] === 'duplicate' || ($r['result'] === 'warning' && !$approveOverLimit)) {
                    DB::update('shift_import_rows', ['result' => 'skipped'], 'id = ?', [(int) $r['id']]);
                    $skipped++;
                    continue;
                }
                if (DB::value("SELECT id FROM shifts WHERE employee_id = ? AND status <> 'cancelled' AND start_at < ? AND end_at > ?", [(int) $r['employee_id'], $r['end_at'], $r['start_at']])) {
                    DB::update('shift_import_rows', ['result' => 'skipped', 'messages' => 'Overlaps a shift created since validation'], 'id = ?', [(int) $r['id']]);
                    $skipped++;
                    continue;
                }
                $week = WeekBoundary::weekStartForUtc($r['start_at']);
                $shiftId = DB::insert('shifts', [
                    'employee_id' => (int) $r['employee_id'], 'shift_type_id' => $r['shift_type_id'], 'location_id' => $r['location_id'],
                    'start_at' => $r['start_at'], 'end_at' => $r['end_at'], 'break_minutes' => (int) $r['break_minutes'], 'net_minutes' => (int) $r['net_minutes'],
                    'week_start' => $week, 'status' => $r['status'] ?: 'draft', 'notes' => $r['notes'], 'import_batch_id' => $batchId, 'created_by' => $userId,
                ]);
                DB::update('shift_import_rows', ['result' => 'imported', 'shift_id' => $shiftId], 'id = ?', [(int) $r['id']]);
                $touched[(int) $r['employee_id'] . '|' . $week] = [(int) $r['employee_id'], $week];
                $imported++;
            }
            foreach ($touched as [$empId, $week]) {
                $emp = DB::one('SELECT * FROM employees WHERE id = ?', [$empId]);
                $total = HoursCalculator::scheduledMinutes($empId, $week);
                $limit = HoursCalculator::limitMinutes($emp);
                if ($total > $limit && $approveOverLimit) {
                    ShiftService::approveException($empId, $week, $limit, $total, 'Shift import #' . $batchId . ': ' . $reason, $userId);
                }
            }
            DB::update('shift_import_batches', ['status' => 'imported', 'imported_rows' => $imported, 'confirmed_at' => now_utc()], 'id = ?', [$batchId]);
            AuditLogger::log('shift_import.confirmed', 'shift_import_batch', $batchId, sprintf('Imported %d shifts, skipped %d', $imported, $skipped));
            return ['imported' => $imported, 'skipped' => $skipped];
        });
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
