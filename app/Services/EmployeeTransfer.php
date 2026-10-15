<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;

/**
 * Employee export (every field) and import keyed on Employee ID.
 *
 * Export: sheet 1 "Employees" holds one row per employee with every field; sheets 2 and 3 list work
 * experience and education (for reference). Import reads sheet 1 in the same layout:
 *   - Employee ID of an existing employee  -> that employee is updated (only the cells that are filled in;
 *     type CLEAR to empty a field)
 *   - Employee ID left empty                -> a new employee is created with the next Employee ID
 *   - a new Employee ID in the A001 format  -> a new employee is created with that Employee ID
 * Every row goes through the same validation as the employee form, and nothing is saved until confirmed.
 */
final class EmployeeTransfer
{
    public const CLEAR = 'CLEAR';

    /**
     * key => [header, type, options]. Types: text, date, number, bool, enum (options = key => label or list), manager.
     * The order here is the column order of the export and the template.
     */
    public static function columns(): array
    {
        $list = fn (array $values) => array_combine($values, $values);
        return [
            'employee_code' => ['Employee ID', 'key'],
            'title' => ['Title', 'text'],
            'first_name' => ['First Name', 'text'],
            'last_name' => ['Last Name', 'text'],
            'preferred_name' => ['Preferred Name', 'text'],
            'gender' => ['Gender', 'text'],
            'date_of_birth' => ['Date of Birth', 'date'],
            'marital_status' => ['Marital Status', 'enum', $list(EmployeeService::MARITAL)],
            'nationality' => ['Nationality', 'text'],
            'email' => ['Email', 'text'],
            'phone' => ['Phone', 'text'],
            'personal_email' => ['Personal Email', 'text'],
            'personal_mobile' => ['Personal Mobile', 'text'],
            'address_line1' => ['Address Line 1', 'text'],
            'address_line2' => ['Address Line 2', 'text'],
            'city' => ['City', 'text'],
            'postcode' => ['Postcode', 'text'],
            'permanent_same_as_present' => ['Permanent Address Same as Present', 'bool'],
            'permanent_address' => ['Permanent Address', 'text'],
            'emergency_contact_name' => ['Emergency Contact Name', 'text'],
            'emergency_contact_phone' => ['Emergency Contact Phone', 'text'],
            'emergency_contact_relationship' => ['Emergency Contact Relationship', 'text'],
            'department' => ['Department', 'text'],
            'designation' => ['Designation', 'text'],
            'location' => ['Location', 'text'],
            'reporting_manager' => ['Reporting Manager', 'manager'],
            'employment_type' => ['Employment Type', 'enum', EmployeeService::TYPES],
            'status' => ['Status', 'enum', EmployeeService::STATUSES],
            'start_date' => ['Date of Joining', 'date'],
            'probation_end_date' => ['Probation End Date', 'date'],
            'end_date' => ['Date of Exit', 'date'],
            'leaving_reason' => ['Leaving Reason', 'text'],
            'source_of_hire' => ['Source of Hire', 'enum', $list(EmployeeService::SOURCES)],
            'contract_hours' => ['Contract Hours', 'number'],
            'weekly_hours_limit' => ['Weekly Hours Limit', 'number'],
            'salary' => ['Salary', 'number'],
            'pay_frequency' => ['Pay Frequency', 'enum', EmployeeService::PAY_FREQ],
            'payroll_reference' => ['Payroll Reference', 'text'],
            'ni_number' => ['NI Number', 'text'],
            'passport_number' => ['Passport Number', 'text'],
            'visa_type' => ['Visa Type', 'text'],
            'visa_start_date' => ['Visa Start Date', 'date'],
            'visa_end_date' => ['Visa End Date', 'date'],
            'rtw_check_status' => ['Right to Work Check', 'enum', $list(EmployeeService::RTW_STATUSES)],
            'rtw_check_date' => ['Last Checked Date', 'date'],
            'rtw_followup_date' => ['Follow-up Date', 'date'],
            'notes' => ['Notes', 'text'],
        ];
    }

    /** Lower-case header text (and a few aliases) => field key, for ExcelIO::readRows(). */
    public static function headerMap(): array
    {
        $map = [];
        foreach (self::columns() as $key => $c) {
            $map[strtolower($c[0])] = $key;
        }
        return $map + ['employee code' => 'employee_code', 'job title' => 'designation', 'start date' => 'start_date', 'end date' => 'end_date',
            'line manager' => 'reporting_manager', 'national insurance number' => 'ni_number', 'right to work check status' => 'rtw_check_status'];
    }

    /* ------------------------------------------------------------------ export */

    /**
     * The employees in the list as shown (same search and filters), with every field.
     * $from is the list's "FROM employees e ... WHERE ..." SQL. @return array{0:array,1:int[]} [rows, employee ids]
     */
    public static function exportRows(string $from, array $params, string $sort): array
    {
        $from = preg_replace('/\sWHERE\s/', ' LEFT JOIN locations l ON l.id = e.location_id WHERE ', $from, 1);
        $rows = DB::all("SELECT e.*, d.name AS department, j.title AS designation, l.name AS location, m.employee_code AS m_code, m.first_name AS m_first, m.last_name AS m_last
                          $from ORDER BY $sort", $params);
        return [array_map([self::class, 'exportRow'], $rows), array_map(fn ($r) => (int) $r['id'], $rows)];
    }

    /** One employee row (joined with department, designation, location and manager) as export values. */
    public static function exportRow(array $r): array
    {
        $row = [];
        foreach (self::columns() as $key => [$header, $type]) {
            $opts = self::columns()[$key][2] ?? [];
            $v = $r[$key] ?? null;
            $row[] = match ($type) {
                'date' => $v ? uk_date($v) : '',
                'number' => $v === null || $v === '' ? '' : (float) $v,
                'bool' => $v ? 'Yes' : 'No',
                'enum' => $v === null || $v === '' ? '' : ($opts[$v] ?? $v),
                'manager' => $r['line_manager_id'] ? trim($r['m_code'] . ' · ' . $r['m_first'] . ' ' . $r['m_last']) : (string) ($r['reporting_manager_name'] ?? ''),
                default => (string) ($v ?? ''),
            };
        }
        return $row;
    }

    public static function headers(): array
    {
        return array_map(fn ($c) => $c[0], array_values(self::columns()));
    }

    /** The workbook: Employees (importable), Work Experience and Education (reference only). */
    public static function workbook(array $employeeRows, array $employeeIds): string
    {
        $exp = $edu = [];
        if ($employeeIds) {
            $in = DB::placeholders($employeeIds);
            foreach (DB::all("SELECT w.*, e.employee_code FROM employee_work_experience w JOIN employees e ON e.id = w.employee_id WHERE w.employee_id IN ($in) ORDER BY e.employee_code, w.from_date", $employeeIds) as $w) {
                $exp[] = [$w['employee_code'], $w['company_name'], $w['job_title'], uk_date($w['from_date']), $w['to_date'] ? uk_date($w['to_date']) : '', $w['is_current'] ? 'Yes' : 'No',
                    (string) $w['location'], $w['is_relevant'] ? 'Yes' : 'No', (string) $w['reference_name'], (string) $w['reference_email'], (string) $w['reference_phone'], (string) $w['description']];
            }
            foreach (DB::all("SELECT x.*, e.employee_code FROM employee_education x JOIN employees e ON e.id = x.employee_id WHERE x.employee_id IN ($in) ORDER BY e.employee_code, x.completion_date", $employeeIds) as $x) {
                $edu[] = [$x['employee_code'], $x['institute_name'], (string) ($x['education_level'] ?: $x['qualification']), (string) $x['specialization'], $x['completion_date'] ? uk_date($x['completion_date']) : ''];
            }
        }
        return ExcelIO::buildBook([
            ['Employees', self::headers(), $employeeRows],
            ['Work Experience', ['Employee ID', 'Company', 'Job Title', 'From', 'To', 'Current', 'Location', 'Relevant', 'Reference Name', 'Reference Email', 'Reference Phone', 'Description'], $exp],
            ['Education', ['Employee ID', 'Institute', 'Education Level', 'Specialisation', 'Completion Date'], $edu],
            ['Instructions', ['Menakan Transport Ltd - Employee export / import'], array_map(fn ($l) => [$l], self::instructions())],
        ]);
    }

    public static function instructions(): array
    {
        return [
            'Only the Employees sheet is imported. Work Experience and Education are included for reference; edit those on the employee record.',
            'Employee ID is the key. An Employee ID that already exists updates that employee. Leave it empty to add a new employee with the next Employee ID,',
            '   or enter a new Employee ID in the same format (e.g. ' . EmployeeService::exampleCode(25) . ') to add a new employee with that ID. IDs of archived employees cannot be reused.',
            'Updating: only filled-in cells change; empty cells keep the current value. To empty a field, type CLEAR in the cell. Columns you remove are left unchanged.',
            'New employees need First Name, Last Name, Department, Designation and Date of Joining. Employment Type defaults to Full time and Status to Active.',
            'Dates: DD/MM/YYYY. Yes/No columns: Yes or No. Employment Type, Status, Pay Frequency, Marital Status, Source of Hire and Right to Work Check use the',
            '   same choices as the employee form (as exported). Reporting Manager: the manager\'s Employee ID (e.g. A002), or a name for someone outside the system.',
            'Directors (designation containing "Director") must have an empty Reporting Manager.',
            'Department, Designation and Location that do not exist yet are created, as on the employee form.',
            'The file is checked first and you see a preview of every new employee and every change. Nothing is saved until you confirm. Maximum 5,000 rows and 5 MB.',
            'This file contains personal and pay data. Store it securely and delete it when you no longer need it.',
        ];
    }

    /* ------------------------------------------------------------------ import: validate */

    public static function validateFile(string $path, string $originalName, string $storageKey, int $userId): int
    {
        $rows = ExcelIO::readRows($path, self::headerMap(), ['employee_code']);
        [$prefix, $pad] = EmployeeService::codeFormat();
        $codePattern = '/^' . preg_quote($prefix, '/') . '\d{' . max(1, $pad) . ',6}$/';

        return DB::transaction(function () use ($rows, $originalName, $storageKey, $userId, $codePattern, $prefix, $pad) {
            $batchId = DB::insert('employee_import_batches', ['original_filename' => mb_substr($originalName, 0, 255), 'storage_key' => $storageKey, 'uploaded_by' => $userId]);
            $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0];
            $seenCodes = $seenEmails = [];
            foreach ($rows as $row) {
                $v = array_map([self::class, 'clean'], $row['values']);
                $code = strtoupper(trim((string) ($v['employee_code'] ?? '')));
                $msgs = [];
                $existing = null;
                $action = 'create';
                if ($code !== '') {
                    $existing = DB::one('SELECT * FROM employees WHERE employee_code = ?', [$code]);
                    if (isset($seenCodes[$code])) {
                        $msgs[] = 'Employee ID ' . $code . ' is also on row ' . $seenCodes[$code];
                    } elseif ($existing && $existing['deleted_at'] !== null) {
                        $msgs[] = 'Employee ID ' . $code . ' belongs to an archived employee and cannot be reused';
                    } elseif (!$existing && !preg_match($codePattern, $code)) {
                        $msgs[] = 'Employee ID ' . $code . ' is not in the Employee ID format (e.g. ' . EmployeeService::formatCode($prefix, 25, $pad) . ')';
                    }
                    $seenCodes[$code] ??= $row['row_no'];
                    $action = $existing ? 'update' : 'create';
                }

                [$input, $convErrors] = self::toInput($v, $existing);
                $msgs = array_merge($msgs, $convErrors);
                $changes = [];
                if (!$msgs) {
                    try {
                        $data = EmployeeService::validate($input, $existing ? (int) $existing['id'] : null);
                        $email = strtolower((string) ($data['email'] ?? ''));
                        if ($email !== '' && isset($seenEmails[$email])) {
                            $msgs[] = 'Email is also used on row ' . $seenEmails[$email];
                        } elseif ($email !== '') {
                            $seenEmails[$email] = $row['row_no'];
                        }
                        if ($existing) {
                            $changes = self::changes(self::currentInput($existing), $input);
                        }
                    } catch (ValidationException $e) {
                        $labels = self::columns();
                        foreach ($e->errors ?: ['' => $e->getMessage()] as $field => $m) {
                            $msgs[] = (isset($labels[$field]) ? $labels[$field][0] . ': ' : '') . rtrim((string) $m, '.');
                        }
                    }
                }
                $result = $msgs ? 'error' : ($existing && !$changes ? 'unchanged' : 'ready');
                $counts[$result === 'error' ? 'error' : ($result === 'unchanged' ? 'unchanged' : $action)]++;
                DB::insert('employee_import_rows', [
                    'batch_id' => $batchId, 'row_no' => $row['row_no'], 'employee_code' => mb_substr($code, 0, 20), 'employee_id' => $existing['id'] ?? null,
                    'action' => $action, 'result' => $result,
                    'employee_name' => mb_substr(trim(($input['first_name'] ?? '') . ' ' . ($input['last_name'] ?? '')), 0, 130),
                    'changes' => $changes ? implode(' · ', $changes) : null,
                    'messages' => $msgs ? mb_substr(implode(' · ', $msgs), 0, 2000) : null,
                    'input_json' => $msgs ? null : json_encode($input, JSON_UNESCAPED_UNICODE),
                    'raw_json' => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                ]);
            }
            DB::update('employee_import_batches', ['total_rows' => count($rows), 'create_rows' => $counts['create'], 'update_rows' => $counts['update'],
                'unchanged_rows' => $counts['unchanged'], 'error_rows' => $counts['error']], 'id = ?', [$batchId]);
            AuditLogger::log('employee_import.validated', 'employee_import_batch', $batchId, sprintf('Validated %s: %d rows, %d new, %d updates, %d unchanged, %d errors',
                $originalName, count($rows), $counts['create'], $counts['update'], $counts['unchanged'], $counts['error']));
            return $batchId;
        });
    }

    /**
     * Turns one sheet row into employee-form input. For an existing employee, cells left empty keep the
     * current value and CLEAR empties the field. @return array{0:array,1:string[]} [input, conversion errors]
     */
    private static function toInput(array $v, ?array $existing): array
    {
        $input = $existing ? self::currentInput($existing) : ['employment_type' => 'full_time', 'status' => 'active', 'permanent_same_as_present' => '0'];
        $errors = [];
        foreach (self::columns() as $key => $c) {
            [$header, $type] = $c;
            if ($type === 'key' || !array_key_exists($key, $v)) {
                continue;
            }
            $raw = $v[$key];
            if ($raw === null || $raw === '') {
                continue;   // empty cell: keep the current (or default) value
            }
            if (is_string($raw) && strtoupper(trim($raw)) === self::CLEAR) {
                $input[$key] = $type === 'bool' ? '0' : '';
                continue;
            }
            switch ($type) {
                case 'date':
                    $d = ExcelIO::parseDate($raw);
                    if ($d === null) {
                        $errors[] = "$header: use DD/MM/YYYY";
                    }
                    $input[$key] = $d ?? (string) $raw;
                    break;
                case 'number':
                    $n = is_string($raw) ? str_replace([',', '£'], '', trim($raw)) : $raw;
                    if (!is_numeric($n)) {
                        $errors[] = "$header: must be a number";
                    }
                    $input[$key] = is_numeric($n) ? (string) (0 + $n) : (string) $raw;
                    break;
                case 'bool':
                    $b = strtolower(trim((string) $raw));
                    if (!in_array($b, ['yes', 'no', 'y', 'n', 'true', 'false', '1', '0'], true)) {
                        $errors[] = "$header: use Yes or No";
                    }
                    $input[$key] = in_array($b, ['yes', 'y', 'true', '1'], true) ? '1' : '0';
                    break;
                case 'enum':
                    $match = null;
                    foreach ($c[2] as $k => $label) {
                        if (strcasecmp(trim((string) $raw), (string) $label) === 0 || strcasecmp(trim((string) $raw), (string) $k) === 0) {
                            $match = (string) $k;
                            break;
                        }
                    }
                    if ($match === null) {
                        $errors[] = "$header: \"$raw\" is not one of " . implode(', ', array_values($c[2]));
                    }
                    $input[$key] = $match ?? (string) $raw;
                    break;
                default:
                    $input[$key] = is_float($raw) && floor($raw) === $raw ? (string) (int) $raw : trim((string) $raw);
            }
        }
        return [$input, $errors];
    }

    /** The employee's current values in employee-form input format. */
    public static function currentInput(array $e): array
    {
        $in = [];
        foreach (EmployeeService::FIELDS as $f) {
            $in[$f] = $e[$f] ?? null;
        }
        $in['department'] = (string) DB::value('SELECT name FROM departments WHERE id = ?', [(int) $e['department_id']]);
        $in['designation'] = (string) DB::value('SELECT title FROM job_titles WHERE id = ?', [(int) $e['job_title_id']]);
        $in['location'] = $e['location_id'] ? (string) DB::value('SELECT name FROM locations WHERE id = ?', [(int) $e['location_id']]) : '';
        $in['reporting_manager'] = $e['line_manager_id'] ? (string) DB::value('SELECT employee_code FROM employees WHERE id = ?', [(int) $e['line_manager_id']]) : (string) ($e['reporting_manager_name'] ?? '');
        $in['permanent_same_as_present'] = $e['permanent_same_as_present'] ? '1' : '0';
        return array_map(fn ($x) => $x === null ? '' : (string) $x, $in);
    }

    /** Human-readable list of the fields that change, e.g. "Phone: 07700 900123 → 07700 900999". */
    private static function changes(array $before, array $after): array
    {
        $out = [];
        foreach (self::columns() as $key => [$header, $type]) {
            if (!array_key_exists($key, $after) || $type === 'key') {
                continue;
            }
            $a = (string) ($before[$key] ?? '');
            $b = (string) ($after[$key] ?? '');
            if ($type === 'number' && is_numeric($a) && is_numeric($b)) {
                $same = (float) $a === (float) $b;
            } elseif ($key === 'reporting_manager') {
                $same = strcasecmp(EmployeeService::resolveManager($a)[0] . '|' . EmployeeService::resolveManager($a)[1], EmployeeService::resolveManager($b)[0] . '|' . EmployeeService::resolveManager($b)[1]) === 0;
            } else {
                $same = strcasecmp(trim($a), trim($b)) === 0 && trim($a) === trim($b);
            }
            if (!$same) {
                // Identity and pay values are summarised, not shown, in the preview.
                $out[] = in_array($key, ['ni_number', 'passport_number', 'salary'], true) ? "$header changed" : sprintf('%s: %s → %s', $header, $a === '' ? '(empty)' : $a, $b === '' ? '(empty)' : $b);
            }
        }
        return $out;
    }

    /** Undo the export's formula-injection guard ('+44 ... -> +44 ...) and trim text. */
    private static function clean(mixed $v): mixed
    {
        if (!is_string($v)) {
            return $v;
        }
        $v = trim($v);
        return strlen($v) > 1 && $v[0] === "'" && in_array($v[1], ['=', '+', '-', '@'], true) ? substr($v, 1) : $v;
    }

    /* ------------------------------------------------------------------ import: confirm */

    /** Applies the ready rows in file order (so managers created earlier in the file can be referenced later). */
    public static function confirm(int $batchId, int $userId): array
    {
        $batch = DB::one('SELECT * FROM employee_import_batches WHERE id = ?', [$batchId]);
        if (!$batch || $batch['status'] !== 'validated') {
            throw new ValidationException('This import has already been completed or cancelled.');
        }
        DB::update('employee_import_batches', ['status' => 'importing'], 'id = ?', [$batchId]);
        $created = $updated = $failed = 0;
        foreach (DB::all("SELECT * FROM employee_import_rows WHERE batch_id = ? AND result = 'ready' ORDER BY row_no", [$batchId]) as $r) {
            $input = json_decode((string) $r['input_json'], true) ?: [];
            try {
                if ($r['action'] === 'update') {
                    EmployeeService::update((int) $r['employee_id'], $input, $userId);
                    $empId = (int) $r['employee_id'];
                    $updated++;
                } else {
                    $empId = EmployeeService::create($input, $userId, $r['employee_code'] !== '' ? $r['employee_code'] : null);
                    $created++;
                }
                DB::update('employee_import_rows', ['result' => 'imported', 'employee_id' => $empId,
                    'employee_code' => (string) DB::value('SELECT employee_code FROM employees WHERE id = ?', [$empId])], 'id = ?', [(int) $r['id']]);
            } catch (\Throwable $e) {
                $msg = $e instanceof ValidationException && $e->errors ? implode(' · ', array_map('strval', $e->errors)) : $e->getMessage();
                DB::update('employee_import_rows', ['result' => 'error', 'messages' => mb_substr('Not saved: ' . $msg, 0, 2000)], 'id = ?', [(int) $r['id']]);
                $failed++;
            }
        }
        DB::update('employee_import_batches', ['status' => 'imported', 'imported_rows' => $created + $updated, 'confirmed_at' => now_utc()], 'id = ?', [$batchId]);
        AuditLogger::log('employee_import.confirmed', 'employee_import_batch', $batchId, sprintf('Employee import: %d created, %d updated, %d failed', $created, $updated, $failed));
        return ['created' => $created, 'updated' => $updated, 'failed' => $failed];
    }

    public static function cancel(int $batchId): void
    {
        if (!DB::update('employee_import_batches', ['status' => 'cancelled'], "id = ? AND status = 'validated'", [$batchId])) {
            throw new ValidationException('Only imports awaiting confirmation can be cancelled.');
        }
        AuditLogger::log('employee_import.cancelled', 'employee_import_batch', $batchId, 'Cancelled employee import');
    }

    /** Rows with problems, with the original cells, for the admin to fix and re-upload. */
    public static function errorReport(int $batchId): string
    {
        $cols = self::columns();
        $out = [];
        foreach (DB::all("SELECT * FROM employee_import_rows WHERE batch_id = ? AND result = 'error' ORDER BY row_no", [$batchId]) as $r) {
            $raw = json_decode((string) $r['raw_json'], true) ?: [];
            $line = [(int) $r['row_no'], (string) $r['messages']];
            foreach ($cols as $key => $c) {
                $x = $raw[$key] ?? '';
                $line[] = $c[1] === 'date' && (is_int($x) || is_float($x)) ? uk_date((string) ExcelIO::parseDate($x)) : (is_scalar($x) ? (string) $x : '');
            }
            $out[] = $line;
        }
        return ExcelIO::build('Errors', array_merge(['Row', 'Problem'], self::headers()), $out);
    }

    /** Blank template: the export headers, one example row and the instructions. */
    public static function template(): string
    {
        $example = [];
        foreach (self::columns() as $key => $c) {
            $example[] = match ($key) {
                'first_name' => 'Jane', 'last_name' => 'Example', 'email' => 'jane.example@menakantransport.com', 'phone' => '07700 900123',
                'department' => 'Transport & Operations Management', 'designation' => 'Van Driver', 'reporting_manager' => EmployeeService::exampleCode(2),
                'employment_type' => 'Full time', 'status' => 'Active', 'start_date' => '01/11/2026', 'contract_hours' => 37.5, 'permanent_same_as_present' => 'Yes',
                default => '',
            };
        }
        return ExcelIO::buildBook([
            ['Employees', self::headers(), [$example]],
            ['Instructions', ['Menakan Transport Ltd - Employee import template'], array_map(fn ($l) => [$l], array_merge(self::instructions(), ['Delete the example row before uploading.']))],
        ]);
    }
}
