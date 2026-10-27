<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\AuditLogger;
use App\Services\EmployeeService;
use App\Services\Settings;

/**
 * System settings plus simple management screens for lookup tables.
 * Table and column names come only from the LOOKUPS map below, never from the request.
 */
final class SettingController extends Controller
{
    private const LOOKUPS = [
        'departments' => ['Departments', ['name' => ['Name', 'text', true], 'code' => ['Code', 'text', true]], 'is_active', 'name'],
        'employee_statuses' => ['Employee statuses', ['name' => ['Name', 'text', true], 'code' => ['Code', 'text', false], 'colour' => ['Colour', 'colour', false],
            'counts_as_working' => ['Counts as working', 'bool', false], 'sort_order' => ['Order', 'int', true]], 'is_active', 'sort_order'],
        'job_titles' => ['Job titles', ['title' => ['Title', 'text', true], 'department_id' => ['Department', 'ref:departments:name', false], 'is_driver' => ['Driving role', 'bool', false]], 'is_active', 'title'],
        'locations' => ['Locations', ['name' => ['Name', 'text', true], 'address' => ['Address', 'text', false]], 'is_active', 'name'],
        'shift_types' => ['Shift types', ['name' => ['Name', 'text', true], 'code' => ['Code', 'text', true], 'default_start' => ['Start', 'time', true],
            'default_end' => ['End', 'time', true], 'default_break_minutes' => ['Break (min)', 'int', true], 'is_night' => ['Night', 'bool', false], 'colour' => ['Colour', 'colour', false]], 'is_active', 'default_start'],
        'leave_types' => ['Leave types', ['name' => ['Name', 'text', true], 'code' => ['Code', 'text', true], 'is_paid' => ['Paid', 'bool', false],
            'deducts_balance' => ['Uses balance', 'bool', false], 'employee_can_request' => ['Employees can request', 'bool', false], 'colour' => ['Colour', 'colour', false],
            'sort_order' => ['Order in lists', 'int', true]], 'is_active', 'sort_order'],
        'document_categories' => ['Document categories', ['name' => ['Name', 'text', true], 'code' => ['Code', 'text', true], 'has_expiry' => ['Has expiry', 'bool', false],
            'is_sensitive' => ['Sensitive', 'bool', false], 'visible_to_employee' => ['Employee can view', 'bool', false], 'description' => ['Description', 'text', false], 'sort_order' => ['Order in lists', 'int', true]], 'is_active', 'sort_order'],
        'document_requirements' => ['Document requirements', ['category_id' => ['Document', 'ref:document_categories:name', true], 'department_id' => ['Department (blank = all)', 'ref:departments:name', false],
            'job_title_id' => ['Job title (blank = any)', 'ref:job_titles:title', false]], 'is_mandatory', 'category_id'],
        'public_holidays' => ['Bank holidays', ['holiday_date' => ['Date', 'date', true], 'name' => ['Name', 'text', true], 'region' => ['Region', 'text', true]], null, 'holiday_date'],
        'work_patterns' => ['Work patterns', ['name' => ['Name', 'text', true], 'mon' => ['Mon', 'bool', false], 'tue' => ['Tue', 'bool', false], 'wed' => ['Wed', 'bool', false],
            'thu' => ['Thu', 'bool', false], 'fri' => ['Fri', 'bool', false], 'sat' => ['Sat', 'bool', false], 'sun' => ['Sun', 'bool', false], 'hours_per_day' => ['Hours/day', 'decimal', true]], 'is_active', 'name'],
    ];

    public function index(Request $request): Response
    {
        return view('settings/index', ['title' => 'Settings', 'crumb' => 'Admin', 'settings' => DB::all('SELECT * FROM system_settings ORDER BY label'),
            'lookups' => array_map(fn ($l) => $l[0], self::LOOKUPS)]);
    }

    public function update(Request $request): Response
    {
        $errors = [];
        $changes = [];
        foreach (DB::all('SELECT * FROM system_settings') as $s) {
            if (!array_key_exists($s['key'], $request->post['settings'] ?? [])) {
                if ($s['type'] === 'bool') {
                    $value = '0';
                } else {
                    continue;
                }
            } else {
                $value = trim(str_replace("\r\n", "\n", (string) $request->post['settings'][$s['key']]));
            }
            $ok = match ($s['type']) {
                'int' => preg_match('/^\d{1,5}$/', $value) === 1,
                'decimal' => is_numeric($value) && (float) $value >= 0 && (float) $value <= 1000,
                'bool' => in_array($value, ['0', '1'], true),
                default => $value !== '' && mb_strlen($value) <= 255,
            };
            if (str_ends_with($s['key'], '_date') && $ok) {
                $ok = Validator::isDate($value);
            }
            if (!$ok) {
                $errors[$s['key']] = $s['label'] . ' has an invalid value.';
                continue;
            }
            if ($value !== $s['value']) {
                Settings::set($s['key'], $value, $this->userId());
                $changes[$s['key']] = [$s['value'], $value];
            }
        }
        if ($errors) {
            return back_with_errors('/settings', $errors, [], implode(' ', $errors));
        }
        if ($changes) {
            AuditLogger::log('settings.updated', 'system_settings', null, 'Updated ' . count($changes) . ' setting(s)', $changes);
        }
        flash('success', $changes ? 'Settings saved.' : 'No settings were changed.');
        return redirect('/settings');
    }

    private function def(string $table): array
    {
        return self::LOOKUPS[$table] ?? throw new HttpException(404);
    }

    public function lookup(Request $request, string $table): Response
    {
        [$label, $fields, $toggle, $order] = $this->def($table);
        $rows = DB::all("SELECT * FROM `$table` ORDER BY `$order`" . ($table === 'public_holidays' ? ' DESC' : ''));
        $refs = [];
        foreach ($fields as $col => [, $type]) {
            if (str_starts_with($type, 'ref:')) {
                [, $refTable, $refCol] = explode(':', $type);
                $refs[$col] = array_column(DB::all("SELECT id, `$refCol` AS label FROM `$refTable` ORDER BY `$refCol`"), 'label', 'id');
            }
        }
        $edit = $request->int('edit') ? DB::one("SELECT * FROM `$table` WHERE id = ?", [$request->int('edit')]) : null;
        return view('settings/lookup', ['title' => $label, 'crumb' => 'Admin · Settings', 'table' => $table, 'fields' => $fields, 'toggle' => $toggle,
            'rows' => $rows, 'refs' => $refs, 'edit' => $edit]);
    }

    public function saveLookup(Request $request, string $table): Response
    {
        [$label, $fields] = $this->def($table);
        $id = $request->int('id');
        return $this->attempt($request, "/settings/$table" . ($id ? "?edit=$id" : ''), function () use ($request, $table, $fields, $id, $label) {
            $data = [];
            $errors = [];
            foreach ($fields as $col => [$name, $type, $required]) {
                $v = trim((string) ($request->post[$col] ?? ''));
                if ($type === 'bool') {
                    $data[$col] = $request->bool($col) ? 1 : 0;
                    continue;
                }
                if ($v === '') {
                    if ($required) {
                        $errors[$col] = "$name is required.";
                    }
                    $data[$col] = null;
                    continue;
                }
                $ok = match (true) {
                    $type === 'int' => preg_match('/^\d{1,4}$/', $v) === 1,
                    $type === 'decimal' => is_numeric($v) && (float) $v > 0 && (float) $v <= 24,
                    $type === 'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1,
                    $type === 'date' => Validator::isDate($v),
                    $type === 'colour' => preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1,
                    str_starts_with($type, 'ref:') => ctype_digit($v),
                    default => mb_strlen($v) <= 255,
                };
                if (!$ok) {
                    $errors[$col] = "$name is invalid.";
                }
                $data[$col] = $v;
            }
            if ($table === 'employee_statuses') {
                $old = $id ? DB::one('SELECT * FROM employee_statuses WHERE id = ?', [$id]) : null;
                $data = $this->employeeStatus($data, $old, $errors);
            }
            if ($errors) {
                throw new ValidationException('Please correct the highlighted fields.', $errors);
            }
            try {
                if ($table === 'employee_statuses' && isset($old) && $old && $old['code'] !== $data['code']) {
                    // A renamed code moves the employees who have it.
                    DB::query('UPDATE employees SET status = ? WHERE status = ?', [$data['code'], $old['code']]);
                }
                if ($id) {
                    DB::update($table, $data, 'id = ?', [$id]);
                } else {
                    $id = DB::insert($table, $data);
                }
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ValidationException('An entry with the same name or code already exists.');
                }
                throw $e;
            }
            AuditLogger::log('settings.lookup_saved', $table, $id, "Saved $label entry #$id", $data);
            EmployeeService::forgetStatuses();
            flash('success', 'Saved.');
            return redirect("/settings/$table");
        });
    }

    public function toggleLookup(Request $request, string $table, int $id): Response
    {
        [$label, , $toggle] = $this->def($table);
        $this->findOr404("SELECT id FROM `$table` WHERE id = ?", [$id]);
        if ($table === 'employee_statuses' && DB::value('SELECT is_system FROM employee_statuses WHERE id = ?', [$id])) {
            flash('warning', 'Active, Probation and Left are built in and cannot be switched off.');
            return redirect("/settings/$table");
        }
        if ($toggle === null) {
            DB::query("DELETE FROM `$table` WHERE id = ?", [$id]);
            AuditLogger::log('settings.lookup_deleted', $table, $id, "Deleted $label entry #$id");
            flash('success', 'Deleted.');
        } else {
            DB::query("UPDATE `$table` SET `$toggle` = 1 - `$toggle` WHERE id = ?", [$id]);
            AuditLogger::log('settings.lookup_toggled', $table, $id, "Toggled $toggle on $label entry #$id");
            flash('success', 'Updated.');
        }
        return redirect("/settings/$table");
    }
    /**
     * Employee status rules: the code is lowercase letters, numbers and underscores (made from the name when left
     * blank); built-in statuses (Active, Probation, Left) keep their code.
     */
    private function employeeStatus(array $data, ?array $old, array &$errors): array
    {
        if ($old && $old['is_system']) {
            $data['code'] = $old['code'];
            $data['counts_as_working'] = (int) $old['counts_as_working'];   // Left never counts, Active and Probation always do
        } else {
            $code = strtolower(trim((string) ($data['code'] ?? '')));
            if ($code === '') {
                $code = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $data['name'])), '_');
            }
            if (!preg_match('/^[a-z][a-z0-9_]{1,29}$/', $code)) {
                $errors['code'] = 'Code: 2-30 lowercase letters, numbers or underscores, starting with a letter.';
            }
            $data['code'] = $code;
        }
        $data['colour'] = $data['colour'] ?: '#64748b';
        return $data;
    }
}
