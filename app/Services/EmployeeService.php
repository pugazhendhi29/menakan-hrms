<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;
use App\Core\Validator;

final class EmployeeService
{
    public const FIELDS = [
        'title', 'first_name', 'last_name', 'preferred_name', 'email', 'phone', 'date_of_birth', 'gender',
        'address_line1', 'address_line2', 'city', 'postcode', 'emergency_contact_name', 'emergency_contact_phone',
        'emergency_contact_relationship', 'department', 'designation', 'location', 'reporting_manager',
        'employment_type', 'contract_hours', 'weekly_hours_limit', 'start_date', 'probation_end_date', 'end_date',
        'leaving_reason', 'status', 'salary', 'pay_frequency', 'payroll_reference', 'notes',
        'source_of_hire', 'marital_status', 'nationality', 'visa_type', 'visa_start_date', 'visa_end_date',
        'rtw_check_status', 'rtw_check_date', 'rtw_followup_date', 'ni_number', 'passport_number', 'permanent_same_as_present', 'permanent_address',
        'personal_mobile', 'personal_email',
    ];

    public const MARITAL = ['Single', 'Married', 'Civil partnership', 'Cohabiting', 'Separated', 'Divorced', 'Widowed', 'Prefer not to say'];
    public const SOURCES = ['Direct application', 'Employee referral', 'Job board', 'Recruitment agency', 'Company website', 'Social media', 'Jobcentre Plus', 'Rehire', 'Other'];
    public const RTW_STATUSES = ['Not yet checked', 'Checked - unlimited right', 'Checked - time-limited', 'Follow-up check due', 'Failed / no right to work'];
    public const VISA_TYPES = ['Not required (British/Irish citizen)', 'EU Settlement Scheme - settled', 'EU Settlement Scheme - pre-settled', 'Skilled Worker',
        'Health and Care Worker', 'Graduate', 'Student', 'Family', 'Indefinite leave to remain', 'Other'];
    public const PHOTO_MAX_BYTES = 2 * 1024 * 1024;

    /** UK National Insurance number: two prefix letters (excluding invalid ones), six digits, suffix A-D. */
    public static function isValidNino(string $nino): bool
    {
        $n = strtoupper(preg_replace('/\s+/', '', $nino));
        return (bool) preg_match('/^(?!BG|GB|KN|NK|NT|TN|ZZ)[A-CEGHJ-PR-TW-Z][A-CEGHJ-NPR-TW-Z]\d{6}[A-D]$/', $n);
    }

    /** Whole months between two dates (inclusive of the start month part-way). */
    public static function monthsBetween(string $from, string $to): int
    {
        if ($to < $from) {
            return 0;
        }
        $d = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to));
        return $d->y * 12 + $d->m;
    }

    public static function formatMonths(int $months): string
    {
        $y = intdiv($months, 12);
        $m = $months % 12;
        if ($y === 0 && $m === 0) {
            return 'Less than 1 month';
        }
        return trim(($y ? $y . ($y === 1 ? ' year ' : ' years ') : '') . ($m ? $m . ($m === 1 ? ' month' : ' months') : ''));
    }

    public static function age(?string $dob, ?string $on = null): ?int
    {
        return $dob ? (new \DateTimeImmutable($dob))->diff(new \DateTimeImmutable($on ?? uk_today()))->y : null;
    }

    /**
     * Current experience = time at Menakan (joining date to exit date or today).
     * Total experience = current + all previous roles in the work history.
     * @return array{current:int, previous:int, relevant:int, total:int} months
     */
    public static function experience(array $employee, ?string $today = null): array
    {
        $today ??= uk_today();
        $end = $employee['end_date'] && $employee['end_date'] < $today ? $employee['end_date'] : $today;
        $current = self::monthsBetween($employee['start_date'], $end);
        $previous = $relevant = 0;
        foreach (DB::all('SELECT from_date, to_date, is_relevant FROM employee_work_experience WHERE employee_id = ?', [(int) $employee['id']]) as $w) {
            $m = self::monthsBetween($w['from_date'], $w['to_date'] ?? $today);
            $previous += $m;
            $relevant += $w['is_relevant'] ? $m : 0;
        }
        return ['current' => $current, 'previous' => $previous, 'relevant' => $relevant, 'total' => $current + $previous];
    }

    public const STATUSES = ['active' => 'Active', 'probation' => 'Probation', 'on_leave' => 'On leave', 'career_break' => 'Career Break', 'suspended' => 'Suspended', 'left' => 'Left'];
    public const TYPES = ['full_time' => 'Full time', 'part_time' => 'Part time', 'casual' => 'Casual / zero hours', 'fixed_term' => 'Fixed term', 'agency' => 'Agency'];
    public const PAY_FREQ = ['weekly' => 'Weekly', 'fortnightly' => 'Fortnightly', 'four_weekly' => 'Four-weekly', 'monthly' => 'Monthly'];

    /** Issues the next employee code (A001, A002 ...) from a row-locked sequence; codes are never reused. */
    public static function nextCode(): string
    {
        return DB::transaction(function () {
            $seq = DB::one('SELECT prefix, next_value, pad_length FROM employee_code_seq WHERE id = 1 FOR UPDATE');
            $code = self::formatCode($seq['prefix'], (int) $seq['next_value'], (int) $seq['pad_length']);
            DB::query('UPDATE employee_code_seq SET next_value = next_value + 1 WHERE id = 1');
            return $code;
        });
    }

    /**
     * Uses a specific unused Employee ID (e.g. A025) and moves the sequence past it, so the next
     * automatic ID never collides with it. IDs, including those of archived employees, are never reused.
     */
    public static function claimCode(string $code): string
    {
        $code = strtoupper(trim($code));
        [$prefix, $pad] = self::codeFormat();
        if (!preg_match('/^' . preg_quote($prefix, '/') . '(\d{' . max(1, $pad) . ',6})$/', $code, $m)) {
            throw new ValidationException('Employee ID ' . $code . ' is not in the Employee ID format (e.g. ' . self::exampleCode(25) . ').');
        }
        return DB::transaction(function () use ($code, $m) {
            DB::one('SELECT next_value FROM employee_code_seq WHERE id = 1 FOR UPDATE');
            if (DB::value('SELECT id FROM employees WHERE employee_code = ?', [$code])) {
                throw new ValidationException('Employee ID ' . $code . ' is already in use.');
            }
            DB::query('UPDATE employee_code_seq SET next_value = GREATEST(next_value, ?) WHERE id = 1', [(int) $m[1] + 1]);
            return $code;
        });
    }

    public static function previewNextCode(): string
    {
        $seq = DB::one('SELECT prefix, next_value, pad_length FROM employee_code_seq WHERE id = 1');
        return self::formatCode($seq['prefix'], (int) $seq['next_value'], (int) $seq['pad_length']);
    }

    /** A + zero-padded number, e.g. A001; numbers beyond the width simply grow (A1000). */
    public static function formatCode(string $prefix, int $number, int $pad): string
    {
        return $prefix . str_pad((string) $number, max(1, $pad), '0', STR_PAD_LEFT);
    }

    /** @return array{0:string,1:int} [prefix, digits] of the employee ID format */
    public static function codeFormat(): array
    {
        $seq = DB::one('SELECT prefix, pad_length FROM employee_code_seq WHERE id = 1');
        return [(string) ($seq['prefix'] ?? 'A'), (int) ($seq['pad_length'] ?? 3)];
    }

    /** Example ID in the current format, for help texts (e.g. A012). */
    public static function exampleCode(int $n = 12): string
    {
        [$prefix, $pad] = self::codeFormat();
        return self::formatCode($prefix, $n, $pad);
    }

    public static function validate(array $input, ?int $id = null): array
    {
        $v = Validator::make($input, [
            'title'          => 'nullable|maxlen:10',
            'first_name'     => 'required|maxlen:60',
            'last_name'      => 'required|maxlen:60',
            'preferred_name' => 'nullable|maxlen:60',
            'email'          => 'nullable|email|maxlen:190',
            'phone'          => 'nullable|phone',
            'date_of_birth'  => 'nullable|date',
            'gender'         => 'nullable|maxlen:30',
            'address_line1'  => 'nullable|maxlen:120',
            'address_line2'  => 'nullable|maxlen:120',
            'city'           => 'nullable|maxlen:80',
            'postcode'       => 'nullable|postcode',
            'emergency_contact_name'  => 'nullable|maxlen:120',
            'emergency_contact_phone' => 'nullable|phone',
            'emergency_contact_relationship' => 'nullable|maxlen:40',
            'department'     => 'required|maxlen:80',
            'designation'    => 'required|maxlen:100',
            'location'       => 'nullable|maxlen:80',
            'reporting_manager' => 'nullable|maxlen:120',
            'employment_type' => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'contract_hours' => 'nullable|numeric|min:0|max:60',
            'weekly_hours_limit' => 'nullable|numeric|min:1|max:72',
            'start_date'     => 'required|date',
            'probation_end_date' => 'nullable|date|after_or_equal:start_date',
            'end_date'       => 'nullable|date|after_or_equal:start_date',
            'leaving_reason' => 'nullable|maxlen:120',
            'status'         => 'required|in:' . implode(',', array_keys(self::STATUSES)),
            'salary'         => 'nullable|numeric|min:0|max:1000000',
            'pay_frequency'  => 'nullable|in:' . implode(',', array_keys(self::PAY_FREQ)),
            'payroll_reference' => 'nullable|maxlen:40',
            'notes'          => 'nullable|maxlen:5000',
            'source_of_hire' => 'nullable|in:' . implode(',', self::SOURCES),
            'marital_status' => 'nullable|in:' . implode(',', self::MARITAL),
            'nationality'    => 'nullable|maxlen:60',
            'visa_type'      => 'nullable|maxlen:60',
            'visa_start_date' => 'nullable|date',
            'visa_end_date'  => 'nullable|date|after_or_equal:visa_start_date',
            'rtw_check_status' => 'nullable|in:' . implode(',', self::RTW_STATUSES),
            'rtw_check_date' => 'nullable|date',
            'rtw_followup_date' => 'nullable|date|after_or_equal:rtw_check_date',
            'ni_number'      => 'nullable|maxlen:13',
            'passport_number' => 'nullable|regex:/^[A-Za-z0-9]{6,20}$/',
            'permanent_address' => 'nullable|maxlen:400',
            'personal_mobile' => 'nullable|phone',
            'personal_email' => 'nullable|email|maxlen:190',
        ], ['location' => 'Location', 'rtw_followup_date' => 'Follow-up date', 'source_of_hire' => 'Source of hire', 'marital_status' => 'Marital status', 'visa_start_date' => 'Visa start date',
            'visa_end_date' => 'Visa end date', 'rtw_check_status' => 'Right to work check', 'rtw_check_date' => 'Last checked date', 'ni_number' => 'National Insurance number',
            'passport_number' => 'Passport number', 'permanent_address' => 'Permanent address', 'personal_mobile' => 'Personal mobile number', 'personal_email' => 'Personal email address',
            'start_date' => 'Date of joining', 'end_date' => 'Date of exit', 'designation' => 'Designation', 'reporting_manager' => 'Reporting manager',
            'date_of_birth' => 'Date of birth', 'department' => 'Department', 'probation_end_date' => 'Probation end date', 'contract_hours' => 'Contract hours',
            'weekly_hours_limit' => 'Weekly hours limit', 'employment_type' => 'Employment type']);

        $errors = $v->errors();
        $data = $v->validated();
        if (!isset($errors['date_of_birth']) && $data['date_of_birth'] && $data['date_of_birth'] > date('Y-m-d', strtotime('-16 years'))) {
            $errors['date_of_birth'] = 'Employee must be at least 16 years old.';
        }
        if (!isset($errors['email']) && $data['email'] && DB::value('SELECT id FROM employees WHERE email = ? AND id <> ?', [$data['email'], $id ?? 0])) {
            $errors['email'] = 'Another employee already uses this email address.';
        }
        // Reporting manager: an employee ID or exact full name links to that employee; anything else is kept as text.
        [$data['line_manager_id'], $data['reporting_manager_name']] = self::resolveManager((string) $data['reporting_manager']);
        unset($data['reporting_manager']);
        if ($id !== null && $data['line_manager_id'] === $id) {
            $errors['reporting_manager'] = 'An employee cannot be their own reporting manager.';
        } elseif (OrgChart::isDirector($data['designation'] ?? null) && ($data['line_manager_id'] !== null || ($data['reporting_manager_name'] ?? '') !== '')) {
            // Directors sit at the top of the structure, directly under the company: they report to no one, not even another Director.
            $errors['reporting_manager'] = 'Directors do not have a reporting manager. Leave this field empty.';
        }
        if ($data['ni_number']) {
            $data['ni_number'] = strtoupper(preg_replace('/\s+/', '', $data['ni_number']));
            if (!self::isValidNino($data['ni_number'])) {
                $errors['ni_number'] = 'Enter a valid National Insurance number, e.g. AB123456C.';
            } elseif (DB::value('SELECT id FROM employees WHERE ni_number = ? AND id <> ?', [$data['ni_number'], $id ?? 0])) {
                $errors['ni_number'] = 'Another employee already has this National Insurance number.';
            }
        }
        if ($data['passport_number']) {
            $data['passport_number'] = strtoupper($data['passport_number']);
        }
        if (!isset($errors['rtw_check_date']) && $data['rtw_check_date'] && $data['rtw_check_date'] > uk_today()) {
            $errors['rtw_check_date'] = 'The last checked date cannot be in the future.';
        }
        $data['permanent_same_as_present'] = in_array($input['permanent_same_as_present'] ?? null, ['1', 'on', 1, true], true) ? 1 : 0;
        if ($data['permanent_same_as_present']) {
            $data['permanent_address'] = null;
        }
        if ($data['status'] === 'left' && !$data['end_date']) {
            $errors['end_date'] = 'A date of exit is required when the status is Left.';
        }
        if ($errors) {
            throw new ValidationException('Please correct the highlighted fields.', $errors);
        }
        if ($data['postcode']) {
            $data['postcode'] = strtoupper(preg_replace('/\s+/', ' ', $data['postcode']));
        }
        return array_map(fn ($v) => $v === '' ? null : $v, $data);
    }

    /**
     * Matches the reporting manager text to an employee: by ID at the start (e.g. "A012" or "A012 · Jane Smith")
     * or by an exact, unique full name. Otherwise the text is stored as the manager's name.
     * @return array{0:?int,1:?string} [line_manager_id, reporting_manager_name]
     */
    public static function resolveManager(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [null, null];
        }
        if (preg_match('/^([A-Za-z]{1,4}\d{1,6})\b/', $text, $m)) {
            $id = DB::value('SELECT id FROM employees WHERE employee_code = ? AND deleted_at IS NULL', [strtoupper($m[1])]);
            if ($id) {
                return [(int) $id, null];
            }
        }
        $ids = DB::column("SELECT id FROM employees WHERE deleted_at IS NULL AND CONCAT(first_name, ' ', last_name) = ?", [$text]);
        if (count($ids) === 1) {
            return [(int) $ids[0], null];
        }
        return [null, mb_substr($text, 0, 120)];
    }

    /**
     * Turns the typed department, designation and location into their records,
     * creating any that do not exist yet (matching is case-insensitive).
     */
    private static function resolveLookups(array &$data, int $userId): void
    {
        $dept = trim((string) $data['department']);
        $deptId = DB::value('SELECT id FROM departments WHERE LOWER(name) = LOWER(?)', [$dept]);
        if (!$deptId) {
            $base = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($dept)) ?: 'DEPT', 0, 9);
            $code = $base;
            for ($i = 2; DB::value('SELECT id FROM departments WHERE code = ?', [$code]); $i++) {
                $code = $base . $i;
            }
            $deptId = DB::insert('departments', ['name' => $dept, 'code' => $code]);
            AuditLogger::log('settings.lookup_saved', 'departments', $deptId, 'Department "' . $dept . '" created from the employee form', null, $userId);
        }
        $title = trim((string) $data['designation']);
        $jobId = DB::value('SELECT id FROM job_titles WHERE LOWER(title) = LOWER(?)', [$title]);
        if (!$jobId) {
            $jobId = DB::insert('job_titles', ['title' => $title, 'department_id' => (int) $deptId]);
            AuditLogger::log('settings.lookup_saved', 'job_titles', $jobId, 'Designation "' . $title . '" created from the employee form', null, $userId);
        }
        $locId = null;
        $loc = trim((string) ($data['location'] ?? ''));
        if ($loc !== '') {
            $locId = DB::value('SELECT id FROM locations WHERE LOWER(name) = LOWER(?)', [$loc]);
            if (!$locId) {
                $locId = DB::insert('locations', ['name' => $loc]);
                AuditLogger::log('settings.lookup_saved', 'locations', $locId, 'Location "' . $loc . '" created from the employee form', null, $userId);
            }
        }
        unset($data['department'], $data['designation'], $data['location']);
        $data['department_id'] = (int) $deptId;
        $data['job_title_id'] = (int) $jobId;
        $data['location_id'] = $locId ? (int) $locId : null;
    }

    /** Runs employee and history validation together so every error shows at once. */
    private static function validateWithHistory(array $input, ?int $id): array
    {
        $history = !empty($input['history_submitted']) ? self::validateHistory((array) ($input['experience'] ?? []), (array) ($input['education'] ?? [])) : null;
        try {
            $data = self::validate($input, $id);
        } catch (ValidationException $e) {
            throw new ValidationException($e->getMessage(), $e->errors + ($history['errors'] ?? []));
        }
        if ($history && $history['errors']) {
            throw new ValidationException('Please correct the highlighted work experience or education details.', $history['errors']);
        }
        return [$data, $history];
    }

    /** $code: a specific new Employee ID (from an import); null = the next one from the sequence. */
    public static function create(array $input, int $userId, ?string $code = null): int
    {
        [$data, $history] = self::validateWithHistory($input, null);
        return DB::transaction(function () use ($data, $userId, $history, $code) {
            self::resolveLookups($data, $userId);
            $data['employee_code'] = $code === null ? self::nextCode() : self::claimCode($code);
            $data['created_by'] = $userId;
            $id = DB::insert('employees', $data);
            AuditLogger::log('employee.created', 'employee', $id, 'Created employee ' . $data['employee_code'] . ' ' . $data['first_name'] . ' ' . $data['last_name'], self::auditSafe($data));
            if ($history) {
                self::syncHistory($id, $history, $userId);
            }
            return $id;
        });
    }

    public static function update(int $id, array $input, int $userId): void
    {
        $before = DB::one('SELECT * FROM employees WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$before) {
            throw new ValidationException('Employee not found.');
        }
        [$data, $history] = self::validateWithHistory($input, $id);
        DB::transaction(function () use ($id, $data, $before, $userId, $history) {
            if ($history) {
                self::syncHistory($id, $history, $userId);
            }
            self::resolveLookups($data, $userId);
            DB::update('employees', $data, 'id = ?', [$id]);
            if ($data['status'] === 'left' && $before['status'] !== 'left') {
                DB::update('users', ['is_active' => 0], 'employee_id = ?', [$id]);
                DB::query('UPDATE user_sessions s JOIN users u ON u.id = s.user_id SET s.revoked_at = ? WHERE u.employee_id = ? AND s.revoked_at IS NULL', [now_utc(), $id]);
            }
            AuditLogger::log('employee.updated', 'employee', $id, 'Updated employee ' . $before['employee_code'], self::auditSafe(AuditLogger::diff($before, $data)));
        });
    }

    /** Soft delete: the record is kept for audit/retention; linked accounts are deactivated. */
    public static function softDelete(int $id): void
    {
        $e = DB::one('SELECT * FROM employees WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$e) {
            throw new ValidationException('Employee not found.');
        }
        DB::transaction(function () use ($id, $e) {
            DB::update('employees', ['deleted_at' => now_utc()], 'id = ?', [$id]);
            DB::update('users', ['is_active' => 0], 'employee_id = ?', [$id]);
            DB::query('UPDATE user_sessions s JOIN users u ON u.id = s.user_id SET s.revoked_at = ? WHERE u.employee_id = ? AND s.revoked_at IS NULL', [now_utc(), $id]);
            AuditLogger::log('employee.deleted', 'employee', $id, 'Archived (soft-deleted) employee ' . $e['employee_code']);
        });
    }

    public static function restore(int $id): void
    {
        DB::update('employees', ['deleted_at' => null], 'id = ?', [$id]);
        AuditLogger::log('employee.restored', 'employee', $id, 'Restored archived employee #' . $id);
    }

    /** Masks a sensitive identifier for display, e.g. QQ****56C. */
    public static function mask(?string $value): string
    {
        if (!$value) {
            return '—';
        }
        return strlen($value) <= 5 ? str_repeat('*', strlen($value)) : substr($value, 0, 2) . str_repeat('*', strlen($value) - 5) . substr($value, -3);
    }

    // ------------------------------------------------------------ Work experience, education, photo

    public const EDUCATION_LEVELS = ['GCSE', 'A-Level', 'Diploma', "Bachelor's Degree", "Master's Degree", 'PhD', 'Other'];

    /** Common dialling codes for reference phone numbers (UK first). */
    public const DIAL_CODES = [
        '+44' => 'UK +44', '+353' => 'Ireland +353', '+48' => 'Poland +48', '+40' => 'Romania +40', '+370' => 'Lithuania +370',
        '+351' => 'Portugal +351', '+49' => 'Germany +49', '+33' => 'France +33', '+34' => 'Spain +34', '+39' => 'Italy +39',
        '+31' => 'Netherlands +31', '+91' => 'India +91', '+92' => 'Pakistan +92', '+880' => 'Bangladesh +880', '+94' => 'Sri Lanka +94',
        '+234' => 'Nigeria +234', '+233' => 'Ghana +233', '+27' => 'South Africa +27', '+971' => 'UAE +971', '+63' => 'Philippines +63',
        '+1' => 'USA / Canada +1', '+61' => 'Australia +61',
    ];

    /**
     * Normalises a phone number to international format "+CC digits".
     * Accepts a number typed with its own +code or 00 prefix; otherwise uses $code and drops a
     * leading trunk 0 (07700 900123 with +44 becomes +44 7700900123).
     * @return string|null|false formatted number, null when empty, false when invalid
     */
    public static function normalisePhone(string $code, string $number): string|null|false
    {
        $n = preg_replace('/[\s().-]+/', '', trim($number));
        if ($n === '') {
            return null;
        }
        if (str_starts_with($n, '00')) {
            $n = '+' . substr($n, 2);
        }
        if (str_starts_with($n, '+')) {
            $codes = array_keys(self::DIAL_CODES);
            usort($codes, fn ($x, $y) => strlen($y) <=> strlen($x));
            $code = '';
            foreach ($codes as $cc) {
                if (str_starts_with($n, $cc)) {
                    $code = $cc;
                    break;
                }
            }
            if ($code === '') {
                return preg_match('/^\+\d{7,15}$/', $n) ? $n : false;
            }
            $n = substr($n, strlen($code));
        } elseif (!isset(self::DIAL_CODES[$code])) {
            return false;
        }
        $n = ltrim($n, '0');
        if (!preg_match('/^\d{6,14}$/', $n) || strlen($code) - 1 + strlen($n) > 15) {
            return false;
        }
        return $code . ' ' . $n;
    }

    /** Splits a stored "+44 7700900123" back into [code, number] for the form. */
    public static function splitPhone(?string $phone): array
    {
        if ($phone && preg_match('/^(\+\d{1,4}) (\d+)$/', $phone, $m) && isset(self::DIAL_CODES[$m[1]])) {
            return [$m[1], $m[2]];
        }
        return ['+44', (string) $phone];
    }

    private static function filled(array $row, array $keys): bool
    {
        foreach ($keys as $k) {
            if (trim((string) ($row[$k] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Validates the repeatable work experience and education rows from a form.
     * Completely blank rows are ignored. Errors are keyed like "experience.2.to_date".
     * @return array{experience: array[], education: array[], errors: array<string,string>}
     */
    public static function validateHistory(array $experience, array $education): array
    {
        $today = uk_today();
        $errors = [];
        $exp = [];
        foreach (array_values(array_filter($experience, 'is_array')) as $i => $r) {
            if (!self::filled($r, ['company_name', 'job_title', 'from_date', 'to_date', 'location', 'reference_name', 'reference_email', 'reference_phone'])) {
                continue;
            }
            $p = "experience.$i.";
            $current = in_array($r['is_current'] ?? null, ['1', 'on', 1, true], true);
            $v = Validator::make($r, [
                'company_name' => 'required|maxlen:120', 'job_title' => 'required|maxlen:100', 'from_date' => 'required|date',
                'to_date' => ($current ? 'nullable' : 'required') . '|date|after_or_equal:from_date', 'location' => 'nullable|maxlen:120',
                'reference_name' => 'nullable|maxlen:120', 'reference_email' => 'nullable|email|maxlen:190',
            ], ['company_name' => 'Company name', 'job_title' => 'Job title', 'from_date' => 'From date', 'to_date' => 'To date',
                'reference_name' => 'Professional reference', 'reference_email' => 'Reference email']);
            foreach ($v->errors() as $k => $msg) {
                $errors[$p . $k] = $msg;
            }
            $d = $v->validated();
            if (!isset($errors[$p . 'from_date']) && $d['from_date'] && $d['from_date'] > $today) {
                $errors[$p . 'from_date'] = 'From date cannot be in the future.';
            }
            if (!$current && !isset($errors[$p . 'to_date']) && $d['to_date'] && $d['to_date'] > $today) {
                $errors[$p . 'to_date'] = 'To date cannot be in the future. Tick "Currently works here" instead.';
            }
            $phone = self::normalisePhone((string) ($r['reference_phone_code'] ?? '+44'), (string) ($r['reference_phone'] ?? ''));
            if ($phone === false) {
                $errors[$p . 'reference_phone'] = 'Enter a valid mobile number, e.g. 07700 900123 with +44.';
            }
            $exp[] = ['company_name' => $d['company_name'], 'job_title' => $d['job_title'], 'from_date' => $d['from_date'],
                'to_date' => $current ? null : ($d['to_date'] ?: null), 'is_current' => $current ? 1 : 0, 'location' => $d['location'] ?: null,
                'reference_name' => $d['reference_name'] ?: null, 'reference_email' => $d['reference_email'] ?: null, 'reference_phone' => $phone ?: null];
        }
        $edu = [];
        foreach (array_values(array_filter($education, 'is_array')) as $i => $r) {
            if (!self::filled($r, ['institute_name', 'specialization', 'completion_date', 'education_level'])) {
                continue;
            }
            $v = Validator::make($r, [
                'institute_name' => 'required|maxlen:150', 'education_level' => 'required|in:' . implode(',', self::EDUCATION_LEVELS),
                'specialization' => 'nullable|maxlen:120', 'completion_date' => 'nullable|date',
            ], ['institute_name' => 'Institute name', 'education_level' => 'Education level', 'completion_date' => 'Date of completion']);
            foreach ($v->errors() as $k => $msg) {
                $errors["education.$i.$k"] = $msg;
            }
            $d = $v->validated();
            $edu[] = ['institute_name' => $d['institute_name'], 'education_level' => $d['education_level'], 'specialization' => $d['specialization'] ?: null,
                'completion_date' => $d['completion_date'] ?: null];
        }
        return ['experience' => $exp, 'education' => $edu, 'errors' => $errors];
    }

    /** Replaces an employee's work experience and education with the validated rows. */
    public static function syncHistory(int $employeeId, array $history, int $userId): void
    {
        DB::transaction(function () use ($employeeId, $history, $userId) {
            DB::query('DELETE FROM employee_work_experience WHERE employee_id = ?', [$employeeId]);
            DB::query('DELETE FROM employee_education WHERE employee_id = ?', [$employeeId]);
            foreach ($history['experience'] as $r) {
                DB::insert('employee_work_experience', $r + ['employee_id' => $employeeId, 'created_by' => $userId]);
            }
            foreach ($history['education'] as $r) {
                DB::insert('employee_education', $r + ['employee_id' => $employeeId, 'created_by' => $userId]);
            }
            AuditLogger::log('employee.history_saved', 'employee', $employeeId, sprintf('Saved %d work experience and %d education records', count($history['experience']), count($history['education'])),
                ['employee_id' => $employeeId]);
        });
    }

    /** Validates and saves history from a form (used by the employee self-service page). */
    public static function saveHistoryFromInput(int $employeeId, array $input, int $userId): void
    {
        $h = self::validateHistory((array) ($input['experience'] ?? []), (array) ($input['education'] ?? []));
        if ($h['errors']) {
            throw new ValidationException('Please correct the highlighted work experience or education details.', $h['errors']);
        }
        self::syncHistory($employeeId, $h, $userId);
    }
    /** Replaces the employee photo (JPG/PNG up to 2 MB, must decode as an image). */
    /** @return string the normalised extension (jpg or png) */
    public static function validatePhoto(?array $file): string
    {
        [$ext] = FileStore::validateUpload($file, ['jpg', 'jpeg', 'png'], self::PHOTO_MAX_BYTES);
        $info = @getimagesize($file['tmp_name']);
        if (!$info || $info[0] < 50 || $info[1] < 50 || $info[0] > 6000 || $info[1] > 6000) {
            throw new ValidationException('The photo must be a real JPG or PNG image between 50 and 6000 pixels wide and high.', ['photo' => 'Invalid image.']);
        }
        return $ext;
    }

    public static function setPhoto(int $employeeId, ?array $file): void
    {
        $ext = self::validatePhoto($file);
        $key = FileStore::store($file['tmp_name'], 'photos', $ext === 'jpeg' ? 'jpg' : $ext);
        DB::update('employees', ['photo_key' => $key], 'id = ?', [$employeeId]);
        AuditLogger::log('employee.photo_updated', 'employee', $employeeId, 'Updated employee photo', ['employee_id' => $employeeId]);
    }

    public static function removePhoto(int $employeeId): void
    {
        DB::update('employees', ['photo_key' => null], 'id = ?', [$employeeId]);
        AuditLogger::log('employee.photo_removed', 'employee', $employeeId, 'Removed employee photo', ['employee_id' => $employeeId]);
    }

    /** Salary is sensitive: audit entries record that it changed, not the values. */
    private static function auditSafe(array $data): array
    {
        foreach (['salary', 'ni_number', 'passport_number'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = '[changed]';
            }
        }
        unset($data['photo_key']);
        return $data;
    }

    public static function lookups(): array
    {
        return [
            'departments' => DB::all('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name'),
            'jobTitles'   => DB::all('SELECT id, title, department_id FROM job_titles WHERE is_active = 1 ORDER BY title'),
            'managers'    => DB::all("SELECT id, employee_code, first_name, last_name FROM employees WHERE deleted_at IS NULL AND status <> 'left' ORDER BY first_name, last_name"),
            'shiftTypes'  => DB::all('SELECT id, name, default_start, default_end FROM shift_types WHERE is_active = 1 ORDER BY default_start'),
            'patterns'    => DB::all('SELECT id, name FROM work_patterns WHERE is_active = 1 ORDER BY name'),
            'locations'   => DB::all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name'),
        ];
    }
}
