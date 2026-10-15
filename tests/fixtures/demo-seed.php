<?php

/**
 * Loads fictional demo data relative to today's date: departments, employees, accounts,
 * documents, shifts, attendance, leave and payslips. Run on a freshly loaded schema:
 *
 *   php tests/fixtures/demo-seed.php
 *
 * TEST FIXTURE ONLY: fictional demo data used by the end-to-end suite. Never run it on a live database.
 *
 * Demo employee accounts get the password from the DEMO_PASSWORD environment variable,
 * or one typed at the prompt. No password is stored in this file.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Core\Auth;
use App\Core\DB;
use App\Services\EmployeeService;
use App\Services\FileStore;
use App\Services\HoursCalculator;
use App\Services\LeaveService;
use App\Services\Settings;
use App\Services\WeekBoundary;

if (DB::value('SELECT COUNT(*) FROM employees') > 0) {
    fwrite(STDERR, "The employees table is not empty. Reload database/database.sql first (do not run this on a live database).\n");
    exit(1);
}
$demoPassword = getenv('DEMO_PASSWORD') ?: '';
if ($demoPassword === '') {
    fwrite(STDOUT, 'Password for demo employee accounts: ');
    $demoPassword = trim((string) fgets(STDIN));
}
if ($p = Auth::passwordProblem($demoPassword)) {
    fwrite(STDERR, $p . "\n");
    exit(1);
}
$demoHash = Auth::hashPassword($demoPassword);
mt_srand(20261014);
$today = uk_today();
$nowUtc = now_utc();

function ins(string $table, array $rows): array
{
    $ids = [];
    foreach ($rows as $k => $r) {
        $ids[$k] = DB::insert($table, $r);
    }
    return $ids;
}

function makePdf(string $title, array $lines): string
{
    $text = 'BT /F1 16 Tf 50 780 Td (' . addcslashes($title, '()\\') . ') Tj ET';
    $y = 750;
    foreach ($lines as $l) {
        $text .= ' BT /F1 11 Tf 50 ' . $y . ' Td (' . addcslashes($l, '()\\') . ') Tj ET';
        $y -= 18;
    }
    $objs = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($text) . " >>\nstream\n$text\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $i => $o) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    return $pdf . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

function storePdf(string $area, string $title, array $lines): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'hrms');
    file_put_contents($tmp, makePdf($title, $lines));
    $size = filesize($tmp);
    $key = FileStore::storeExisting($tmp, $area, 'pdf');
    return [$key, $size, FileStore::hash($key)];
}

function d(string $rel): string
{
    global $today;
    return date('Y-m-d', strtotime("$today $rel"));
}

// The fictional demo company uses the older MT0001 format; the E2E suite relies on these codes.
DB::update('employee_code_seq', ['prefix' => 'MT', 'pad_length' => 4], 'id = 1');

DB::pdo()->beginTransaction();

// ---------------------------------------------------------------- Reference data
$dept = ins('departments', [
    'DRV' => ['name' => 'Drivers', 'code' => 'DRV'],
    'WHS' => ['name' => 'Warehouse', 'code' => 'WHS'],
    'FOP' => ['name' => 'Freight Ops', 'code' => 'FOPS'],
    'FLT' => ['name' => 'Fleet', 'code' => 'FLT'],
    'OFF' => ['name' => 'Office & HR', 'code' => 'OFF'],
]);
$job = ins('job_titles', [
    'HGV1' => ['title' => 'HGV Class 1 Driver', 'department_id' => $dept['DRV'], 'is_driver' => 1],
    'HGV2' => ['title' => 'HGV Class 2 Driver', 'department_id' => $dept['DRV'], 'is_driver' => 1],
    'VAN'  => ['title' => 'Van Driver', 'department_id' => $dept['DRV'], 'is_driver' => 1],
    'MDC'  => ['title' => 'Multi-drop Courier', 'department_id' => $dept['DRV'], 'is_driver' => 1],
    'TM'   => ['title' => 'Transport Manager', 'department_id' => $dept['DRV']],
    'WO'   => ['title' => 'Warehouse Operative', 'department_id' => $dept['WHS']],
    'FORK' => ['title' => 'Forklift Operator', 'department_id' => $dept['WHS']],
    'FA'   => ['title' => 'Freight Administrator', 'department_id' => $dept['FOP']],
    'TP'   => ['title' => 'Transport Planner', 'department_id' => $dept['FOP']],
    'OM'   => ['title' => 'Operations Manager', 'department_id' => $dept['FOP']],
    'FM'   => ['title' => 'Fleet Manager', 'department_id' => $dept['FLT']],
    'FT'   => ['title' => 'Fleet Technician', 'department_id' => $dept['FLT']],
    'HR'   => ['title' => 'HR Officer', 'department_id' => $dept['OFF']],
    'FIN'  => ['title' => 'Finance Assistant', 'department_id' => $dept['OFF']],
]);
$pat = ins('work_patterns', [
    'MF'  => ['name' => 'Monday to Friday', 'hours_per_day' => 7.5],
    'TS'  => ['name' => 'Tuesday to Saturday', 'mon' => 0, 'sat' => 1, 'hours_per_day' => 7.5],
    'PT3' => ['name' => 'Part time Mon-Wed', 'thu' => 0, 'fri' => 0, 'hours_per_day' => 7.0],
]);
$st = ins('shift_types', [
    'EARLY' => ['name' => 'Early', 'code' => 'EARLY', 'default_start' => '06:00', 'default_end' => '14:00', 'default_break_minutes' => 30, 'colour' => '#1f5fa8'],
    'LATE'  => ['name' => 'Late', 'code' => 'LATE', 'default_start' => '14:00', 'default_end' => '22:00', 'default_break_minutes' => 30, 'colour' => '#7a4fb5'],
    'NIGHT' => ['name' => 'Night', 'code' => 'NIGHT', 'default_start' => '22:00', 'default_end' => '06:00', 'default_break_minutes' => 30, 'is_night' => 1, 'colour' => '#1b3150'],
    'DAY'   => ['name' => 'Office day', 'code' => 'DAY', 'default_start' => '09:00', 'default_end' => '17:00', 'default_break_minutes' => 30, 'colour' => '#1f8a4c'],
    'LONG'  => ['name' => 'Long day', 'code' => 'LONG', 'default_start' => '06:00', 'default_end' => '15:00', 'default_break_minutes' => 30, 'colour' => '#a86400'],
]);
$loc = ins('locations', [
    'DAV' => ['name' => 'Daventry Depot', 'address' => 'Unit 4, Royal Oak Way, Daventry NN11 8PQ'],
    'NHB' => ['name' => 'Northampton Hub', 'address' => 'Brackmills Industrial Estate, Northampton NN4 7PB'],
    'HO'  => ['name' => 'Head Office', 'address' => '2 Station Square, Milton Keynes MK9 1BB'],
]);
// The company's leave types, as in database/seed.sql: [name, paid, uses balance, employees can request, colour]
$lt = [];
$i = 0;
foreach ([
    'AL' => ['Annual Leave (Paid Holiday)', 1, 1, 1, '#1f8a4c'], 'SICK' => ['Sick Leave', 1, 0, 0, '#c0392b'], 'SSP' => ['Statutory Sick Pay (SSP)', 1, 0, 0, '#d9480f'],
    'CSP' => ['Company Sick Pay', 1, 0, 0, '#e8590c'], 'UNP' => ['Unpaid Leave', 0, 0, 1, '#5b6b7f'], 'COMP' => ['Compassionate Leave', 1, 0, 1, '#5b3fb5'],
    'BRV' => ['Bereavement Leave', 1, 0, 1, '#495057'], 'EMG' => ['Emergency Leave', 0, 0, 1, '#e67700'], 'TOD' => ['Time Off for Dependants', 0, 0, 1, '#0c8599'],
    'CARER' => ["Carer's Leave", 0, 0, 1, '#087f5b'], 'MAT' => ['Maternity Leave', 1, 0, 0, '#a86400'], 'SMP' => ['Statutory Maternity Pay', 1, 0, 0, '#c2255c'],
    'PAT' => ['Paternity Leave', 1, 0, 0, '#1f5fa8'], 'SPP' => ['Statutory Paternity Pay', 1, 0, 0, '#5c7cfa'], 'MAR' => ['Marriage / Wedding Leave', 1, 0, 1, '#ae3ec9'],
    'REL' => ['Religious Holiday Leave', 0, 0, 1, '#2b8a3e'],
] as $code => [$name, $paid, $balance, $request, $colour]) {
    $lt[$code] = DB::insert('leave_types', ['name' => $name, 'code' => $code, 'is_paid' => $paid, 'deducts_balance' => $balance,
        'employee_can_request' => $request, 'colour' => $colour, 'sort_order' => ++$i]);
}
$dc = ins('document_categories', [
    'RTW'   => ['name' => 'Right to work', 'code' => 'RTW', 'is_sensitive' => 1, 'description' => 'Passport, BRP or share code check'],
    'CON'   => ['name' => 'Contract of employment', 'code' => 'CONTRACT'],
    'DL'    => ['name' => 'Driving licence', 'code' => 'DL', 'has_expiry' => 1],
    'CPC'   => ['name' => 'Driver CPC', 'code' => 'CPC', 'has_expiry' => 1],
    'TACHO' => ['name' => 'Digital tachograph card', 'code' => 'TACHO', 'has_expiry' => 1],
    'FORK'  => ['name' => 'Forklift licence', 'code' => 'FLT', 'has_expiry' => 1],
    'P45'   => ['name' => 'P45 / starter checklist', 'code' => 'P45', 'is_sensitive' => 1],
    'TRAIN' => ['name' => 'Training certificate', 'code' => 'TRAIN', 'has_expiry' => 0],
]);
ins('document_requirements', [
    ['category_id' => $dc['RTW']],
    ['category_id' => $dc['CON']],
    ['category_id' => $dc['DL'], 'department_id' => $dept['DRV']],
    ['category_id' => $dc['CPC'], 'job_title_id' => $job['HGV1']],
    ['category_id' => $dc['CPC'], 'job_title_id' => $job['HGV2']],
    ['category_id' => $dc['TACHO'], 'job_title_id' => $job['HGV1']],
    ['category_id' => $dc['TACHO'], 'job_title_id' => $job['HGV2']],
    ['category_id' => $dc['FORK'], 'job_title_id' => $job['FORK']],
]);
$holidays = [
    ['2025-01-01', "New Year's Day"], ['2025-04-18', 'Good Friday'], ['2025-04-21', 'Easter Monday'], ['2025-05-05', 'Early May bank holiday'],
    ['2025-05-26', 'Spring bank holiday'], ['2025-08-25', 'Summer bank holiday'], ['2025-12-25', 'Christmas Day'], ['2025-12-26', 'Boxing Day'],
    ['2026-01-01', "New Year's Day"], ['2026-04-03', 'Good Friday'], ['2026-04-06', 'Easter Monday'], ['2026-05-04', 'Early May bank holiday'],
    ['2026-05-25', 'Spring bank holiday'], ['2026-08-31', 'Summer bank holiday'], ['2026-12-25', 'Christmas Day'], ['2026-12-28', 'Boxing Day (substitute day)'],
    ['2027-01-01', "New Year's Day"], ['2027-03-26', 'Good Friday'], ['2027-03-29', 'Easter Monday'], ['2027-05-03', 'Early May bank holiday'],
    ['2027-05-31', 'Spring bank holiday'], ['2027-08-30', 'Summer bank holiday'], ['2027-12-27', 'Christmas Day (substitute day)'], ['2027-12-28', 'Boxing Day (substitute day)'],
];
foreach ($holidays as [$hd, $hn]) {
    DB::insert('public_holidays', ['holiday_date' => $hd, 'name' => $hn]);
}

// ---------------------------------------------------------------- Employees (code order matters: Daniel Okafor = MT0012)
$people = [
    ['Priya', 'Anand', 'HR', 'OFF', '2019-04-01', 'active', 'full_time', 37.5, 'DAY', 'p.anand', null, 'MF', 'Female'],
    ['Mark', 'Ellis', 'TM', 'DRV', '2018-02-12', 'active', 'full_time', 40, 'DAY', 'm.ellis', null, 'MF', 'Male'],
    ['Jo', 'Barnes', 'OM', 'FOP', '2017-09-04', 'active', 'full_time', 37.5, 'DAY', 'j.barnes', null, 'MF', null],
    ['Ravi', 'Patel', 'WO', 'WHS', '2022-05-16', 'active', 'full_time', 37.5, 'EARLY', 'r.patel', 3, 'MF', 'Male'],
    ['Grace', 'Mensah', 'FIN', 'OFF', '2023-01-09', 'active', 'full_time', 37.5, 'DAY', 'g.mensah', 1, 'MF', 'Female'],
    ['Tom', 'Whitfield', 'HGV2', 'DRV', '2021-03-22', 'active', 'full_time', 37.5, 'EARLY', 't.whitfield', 2, 'MF', 'Male'],
    ['Hannah', 'Clarke', 'FA', 'FOP', '2020-07-01', 'active', 'full_time', 37.5, 'DAY', 'h.clarke', 3, 'MF', 'Female'],
    ['Gareth', 'Lloyd', 'VAN', 'DRV', '2021-06-15', 'left', 'full_time', 37.5, 'EARLY', 'g.lloyd', 2, 'MF', 'Male'],
    ['Owen', 'Price', 'FORK', 'WHS', '2022-11-07', 'active', 'full_time', 37.5, 'LATE', 'o.price', 3, 'MF', 'Male'],
    ['Chloe', 'Evans', 'WO', 'WHS', '2024-02-05', 'active', 'part_time', 22.5, 'EARLY', 'c.evans', 3, 'PT3', 'Female'],
    ['Samuel', 'Adeyemi', 'HGV1', 'DRV', '2020-10-19', 'active', 'full_time', 37.5, 'NIGHT', 's.adeyemi', 2, 'MF', 'Male'],
    ['Daniel', 'Okafor', 'HGV1', 'DRV', '2024-03-04', 'active', 'full_time', 37.5, 'EARLY', 'd.okafor', 2, 'MF', 'Male'],
    ['Emily', 'Turner', 'TP', 'FOP', '2023-08-14', 'active', 'full_time', 37.5, 'DAY', 'e.turner', 3, 'MF', 'Female'],
    ['Kwame', 'Asante', 'MDC', 'DRV', '2023-04-24', 'active', 'full_time', 37.5, 'EARLY', 'k.asante', 2, 'TS', 'Male'],
    ['Lucy', 'Morgan', 'FT', 'FLT', '2022-01-10', 'active', 'full_time', 37.5, 'EARLY', 'l.morgan', null, 'MF', 'Female'],
    ['Imran', 'Hussain', 'VAN', 'DRV', '2025-05-12', 'active', 'full_time', 37.5, 'LATE', 'i.hussain', 2, 'MF', 'Male'],
    ['Bethany', 'Shaw', 'WO', 'WHS', '2025-09-01', 'active', 'full_time', 37.5, 'LATE', 'b.shaw', 3, 'MF', 'Female'],
    ['Jack', 'Robinson', 'FORK', 'WHS', '2021-12-06', 'active', 'full_time', 37.5, 'EARLY', 'j.robinson', 3, 'MF', 'Male'],
    ['Aisha', 'Rahman', 'MDC', 'DRV', '2023-09-11', 'active', 'full_time', 37.5, 'EARLY', 'a.rahman', 2, 'MF', 'Female'],
    ['Neha', 'Sharma', 'FA', 'FOP', '2024-06-03', 'active', 'full_time', 37.5, 'DAY', 'n.sharma', 3, 'MF', 'Female'],
    ['Callum', 'Reid', 'HGV1', 'DRV', '2019-08-19', 'on_leave', 'full_time', 37.5, 'NIGHT', 'c.reid', 2, 'MF', 'Male'],
    ['Zofia', 'Kowalska', 'WO', 'WHS', '2024-10-21', 'active', 'full_time', 37.5, 'LATE', 'z.kowalska', 3, 'MF', 'Female'],
    ['Ryan', 'Patel', 'VAN', 'DRV', '2025-02-17', 'active', 'full_time', 37.5, 'EARLY', 'ry.patel', 2, 'MF', 'Male'],
    ['Sophie', 'Hartley', 'FA', 'FOP', '2025-01-02', 'active', 'full_time', 37.5, 'DAY', 's.hartley', 3, 'MF', 'Female'],
    ['Marek', 'Nowak', 'HGV1', 'DRV', '2022-03-28', 'active', 'full_time', 37.5, 'EARLY', 'm.nowak', 2, 'MF', 'Male'],
    ['Lena', 'Fischer', 'FM', 'FLT', d('-15 days'), 'probation', 'full_time', 37.5, 'DAY', 'l.fischer', 3, 'MF', 'Female'],
];
$streets = ['High Street', 'Station Road', 'Church Lane', 'Park Avenue', 'Mill Road', 'Victoria Road', 'London Road', 'The Green'];
$towns = [['Daventry', 'NN11 4AB'], ['Northampton', 'NN1 2DE'], ['Rugby', 'CV21 3FG'], ['Towcester', 'NN12 6HJ'], ['Milton Keynes', 'MK9 3KL'], ['Brackley', 'NN13 7MN']];
$emp = [];
foreach ($people as $i => [$first, $last, $jobKey, $deptKey, $start, $status, $type, $hours, $shiftKey, $username, $managerIdx, $patKey, $gender]) {
    $town = $towns[$i % count($towns)];
    $data = [
        'employee_code' => EmployeeService::nextCode(), 'first_name' => $first, 'last_name' => $last,
        'email' => strtolower(str_replace(' ', '', $first . '.' . $last)) . '@menakan.example', 'phone' => '07700 9' . sprintf('%05d', 10000 + $i * 137),
        'date_of_birth' => date('Y-m-d', strtotime('1970-01-01 +' . (mt_rand(0, 32) * 365 + mt_rand(0, 360)) . ' days')), 'gender' => $gender,
        'address_line1' => (10 + $i * 3) . ' ' . $streets[$i % count($streets)], 'city' => $town[0], 'postcode' => $town[1],
        'emergency_contact_name' => 'Next of kin ' . ($i + 1), 'emergency_contact_phone' => '07700 8' . sprintf('%05d', 20000 + $i * 211), 'emergency_contact_relationship' => 'Partner',
        'department_id' => $dept[$deptKey], 'job_title_id' => $job[$jobKey], 'work_pattern_id' => $pat[$patKey], 'employment_type' => $type,
        'contract_hours' => $hours, 'start_date' => $start, 'status' => $status,
        'probation_end_date' => $status === 'probation' ? date('Y-m-d', strtotime("$start +6 months")) : null,
        'end_date' => $status === 'left' ? '2026-07-31' : null, 'leaving_reason' => $status === 'left' ? 'Resigned' : null,
        'salary' => in_array($jobKey, ['HGV1', 'TM', 'OM', 'FM'], true) ? 38500 + $i * 250 : 26500 + $i * 180, 'pay_frequency' => 'monthly',
        'payroll_reference' => 'PR' . (5000 + $i),
    ];
    $id = DB::insert('employees', $data);
    $emp[$i + 1] = $data + ['id' => $id, 'shift' => $shiftKey, 'username' => $username, 'manager_idx' => $managerIdx, 'hours' => $hours];
}
foreach ($emp as $e) {
    if ($e['manager_idx']) {
        DB::update('employees', ['line_manager_id' => $emp[$e['manager_idx']]['id']], 'id = ?', [$e['id']]);
    }
}
// Marek has a personal 40h limit agreed (override of the 37.5h company rule).
DB::update('employees', ['weekly_hours_limit' => 40], 'id = ?', [$emp[25]['id']]);

// ---------------------------------------------------------------- Accounts (employee portal); admin is created with bin/create-admin.php
$userIds = [];
foreach ($emp as $n => $e) {
    if ($n === 1) {
        continue; // Priya's record is linked to the admin account by create-admin --employee-code=MT0001
    }
    $userIds[$n] = DB::insert('users', [
        'employee_id' => $e['id'], 'username' => $e['username'], 'email' => $e['email'], 'password_hash' => $demoHash,
        'role' => 'employee', 'is_active' => $e['status'] === 'left' ? 0 : 1,
    ]);
}

// ---------------------------------------------------------------- Documents
$docFor = function (int $n, string $catKey, ?string $expiry, string $verification = 'verified', ?string $title = null) use ($emp, $dc) {
    $e = $emp[$n];
    [$key, $size, $sha] = storePdf('documents', ($title ?? $catKey) . ' - ' . $e['first_name'] . ' ' . $e['last_name'], ['Fictional demo document for ' . $e['employee_code'], 'Menakan Transport Ltd HRMS']);
    return DB::insert('employee_documents', [
        'employee_id' => $e['id'], 'category_id' => $dc[$catKey], 'title' => $title ?? $catKey, 'document_number' => strtoupper(substr(md5($e['employee_code'] . $catKey), 0, 10)),
        'issue_date' => d('-2 years'), 'expiry_date' => $expiry, 'original_filename' => strtolower($catKey) . '_' . strtolower($e['last_name']) . '.pdf',
        'storage_key' => $key, 'mime_type' => 'application/pdf', 'size_bytes' => $size, 'sha256' => $sha, 'verification' => $verification,
        'verified_at' => $verification === 'verified' ? now_utc() : null, 'is_current' => 1,
    ]);
};
$titles = ['RTW' => 'Right to work check', 'CON' => 'Contract of employment', 'DL' => 'Driving licence', 'CPC' => 'Driver CPC qualification card', 'TACHO' => 'Digital tachograph card', 'FORK' => 'Counterbalance forklift licence'];
foreach ($emp as $n => $e) {
    if ($e['status'] === 'left') {
        continue;
    }
    $jobKey = $people[$n - 1][2];
    if (!($n === 26)) { // Lena Fischer: right to work missing
        $docFor($n, 'RTW', null, 'verified', $titles['RTW']);
    }
    $docFor($n, 'CON', null, $n === 26 ? 'pending' : 'verified', $titles['CON']);
    if ($e['department_id'] === $dept['DRV'] && $jobKey !== 'TM') {
        $docFor($n, 'DL', $n === 6 ? d('+20 days') : d('+' . (300 + $n * 40) . ' days'), 'verified', $titles['DL']);
    }
    if (in_array($jobKey, ['HGV1', 'HGV2'], true)) {
        $docFor($n, 'CPC', $n === 21 ? d('-12 days') : d('+' . (200 + $n * 30) . ' days'), 'verified', $titles['CPC']);
        if ($n !== 11) { // Samuel: tachograph card missing
            $docFor($n, 'TACHO', d('+' . (400 + $n * 20) . ' days'), 'verified', $titles['TACHO']);
        }
    }
    if ($jobKey === 'FORK') {
        $docFor($n, 'FORK', $n === 9 ? d('+45 days') : d('+500 days'), $n === 18 ? 'pending' : 'verified', $titles['FORK']);
    }
}
// Aisha Rahman (courier) holds a Driver CPC that expires in 8 days (not required for her role, still tracked).
$docFor(19, 'CPC', d('+8 days'), 'verified', $titles['CPC']);

// ---------------------------------------------------------------- Shifts: 6 weeks back to 3 weeks ahead
$thisWeek = WeekBoundary::weekStartForDate($today);
$weeks = [];
for ($w = -6; $w <= 3; $w++) {
    $weeks[] = date('Y-m-d', strtotime("$thisWeek " . ($w >= 0 ? '+' : '') . ($w * 7) . ' days'));
}
$typeTimes = ['EARLY' => ['06:00', '14:00'], 'LATE' => ['14:00', '22:00'], 'NIGHT' => ['22:00', '06:00'], 'DAY' => ['09:00', '17:00'], 'LONG' => ['06:00', '15:00']];
$locFor = fn ($e) => $e['department_id'] === $dept['OFF'] || $e['department_id'] === $dept['FOP'] ? $loc['HO'] : ($e['department_id'] === $dept['WHS'] ? $loc['NHB'] : $loc['DAV']);
$addShift = function (array $e, string $date, string $startT, string $endT, int $break, string $typeKey, string $status, ?string $notes = null) use ($st, $locFor) {
    [$s, $en] = HoursCalculator::utcSpan($date, $startT, $endT);
    return DB::insert('shifts', [
        'employee_id' => $e['id'], 'shift_type_id' => $st[$typeKey] ?? null, 'location_id' => $locFor($e), 'start_at' => $s, 'end_at' => $en,
        'break_minutes' => $break, 'net_minutes' => HoursCalculator::netMinutes($s, $en, $break), 'week_start' => WeekBoundary::weekStartForUtc($s),
        'status' => $status, 'notes' => $notes,
    ]);
};
$special = [12, 24, 25]; // hand-built current weeks
$holidaySet = array_flip(array_column($holidays, 0));
foreach ($emp as $n => $e) {
    if ($e['status'] === 'left' || $n === 1) {
        continue;
    }
    $p = $people[$n - 1][11];
    $days = $p === 'TS' ? [1, 2, 3, 4, 5] : ($p === 'PT3' ? [0, 1, 2] : [0, 1, 2, 3, 4]);
    foreach ($weeks as $wi => $ws) {
        if (in_array($n, $special, true) && $ws === $thisWeek) {
            continue;
        }
        if ($n === 21 && $ws >= $thisWeek) {
            continue; // Callum is on leave
        }
        $status = $ws <= date('Y-m-d', strtotime("$thisWeek +7 days")) ? 'published' : 'draft';
        $type = $e['shift'];
        if ($type === 'EARLY' && $wi % 3 === 2 && $e['department_id'] === $dept['WHS']) {
            $type = 'LATE';
        }
        foreach ($days as $dIdx) {
            $date = date('Y-m-d', strtotime("$ws +$dIdx days"));
            if (isset($holidaySet[$date]) || $date < $e['start_date']) {
                continue;
            }
            if ($n === 12 && $date === d('-7 days')) {
                continue; // Daniel's annual leave day
            }
            [$a, $b] = $typeTimes[$type];
            $addShift($e, $date, $a, $b, 30, $type, $status);
        }
    }
}
// Daniel Okafor, this week: 42.0h (over 37.5h, not yet approved)
$mon = $thisWeek;
$dd = fn ($i) => date('Y-m-d', strtotime("$mon +$i days"));
$addShift($emp[12], $dd(0), '06:00', '15:00', 30, 'LONG', 'published');
$addShift($emp[12], $dd(1), '06:00', '15:00', 30, 'LONG', 'published');
$addShift($emp[12], $dd(2), '22:00', '06:00', 30, 'NIGHT', 'published');
$addShift($emp[12], $dd(3), '14:00', '23:00', 30, 'LATE', 'published', 'Covering for C. Reid');
$addShift($emp[12], $dd(4), '06:00', '15:30', 30, 'LONG', 'published');
// Sophie Hartley, this week: 40.0h (over, not approved)
foreach ([0, 1, 2, 3, 4] as $i) {
    $addShift($emp[24], $dd($i), '08:30', '17:00', 30, 'DAY', 'published');
}
// Marek Nowak, this week: 41.0h against his 40h personal limit, approved exception
foreach ([0, 1, 2, 3] as $i) {
    $addShift($emp[25], $dd($i), '06:00', '16:45', 30, 'LONG', 'published');
}
$addShift($emp[25], $dd(4), '06:00', '14:00', 30, 'EARLY', 'cancelled', 'Cancelled: vehicle in for MOT');

// ---------------------------------------------------------------- Attendance for past published shifts
$cutoff = time();
$shiftRows = DB::all("SELECT s.*, e.status AS emp_status FROM shifts s JOIN employees e ON e.id = s.employee_id WHERE s.status = 'published' AND s.start_at < ? ORDER BY s.start_at", [$nowUtc]);
$absentKeys = [];
$grace = (int) Settings::get('late_grace_minutes', 5);
foreach ($shiftRows as $i => $s) {
    $r = mt_rand(1, 100);
    $isToday = WeekBoundary::ukDateOfUtc($s['start_at']) === $today;
    if ($r <= 4 && !$isToday) {
        continue; // unexplained absence
    }
    if ($isToday && (int) $s['employee_id'] === $emp[17]['id']) {
        continue; // Bethany: absent today
    }
    $inOffset = $r <= 14 ? mt_rand(8, 35) : -mt_rand(0, 10);
    $in = strtotime($s['start_at'] . ' UTC') + $inOffset * 60;
    $out = strtotime($s['end_at'] . ' UTC') + mt_rand(-5, 20) * 60;
    $open = $out > $cutoff;
    if ($in > $cutoff) {
        continue;
    }
    $incomplete = !$open && mt_rand(1, 100) <= 2;
    $inUtc = gmdate('Y-m-d H:i:s', $in);
    $outUtc = ($open || $incomplete) ? null : gmdate('Y-m-d H:i:s', $out);
    $net = $outUtc ? intdiv($out - $in, 60) - (int) $s['break_minutes'] : null;
    $ageDays = ($cutoff - $in) / 86400;
    DB::insert('attendance_records', [
        'employee_id' => $s['employee_id'], 'work_date' => WeekBoundary::ukDateOfUtc($inUtc), 'check_in_at' => $inUtc, 'check_out_at' => $outUtc,
        'break_minutes' => $outUtc ? (int) $s['break_minutes'] : 0, 'net_minutes' => $net,
        'status' => $incomplete ? 'incomplete' : ($in > strtotime($s['start_at'] . ' UTC') + $grace * 60 ? 'late' : 'present'),
        'source' => mt_rand(1, 3) === 1 ? 'import' : 'web', 'shift_id' => $s['id'],
        'is_approved' => $outUtc && $ageDays > 7 ? 1 : 0, 'approved_at' => $outUtc && $ageDays > 7 ? $nowUtc : null,
    ]);
}

// ---------------------------------------------------------------- Leave
[$ys, $ye, $year] = LeaveService::yearBounds($today);
foreach ($emp as $n => $e) {
    LeaveService::ensureEntitlement($e['id'], $year);
}
$leave = function (int $n, string $type, string $from, string $to, string $status, ?string $reason = null) use ($emp, $lt, $userIds) {
    $e = $emp[$n];
    $days = LeaveService::countDays($e, $from, $to, false, false);
    $id = DB::insert('leave_requests', [
        'employee_id' => $e['id'], 'leave_type_id' => $lt[$type], 'start_date' => $from, 'end_date' => $to, 'days' => $days, 'reason' => $reason,
        'status' => $status, 'requested_by' => $userIds[$n] ?? null, 'decided_at' => $status === 'pending' ? null : now_utc(),
        'shift_clashes' => count(LeaveService::shiftClashes($e['id'], $from, $to)),
    ]);
    if ($status === 'approved' && $type === 'AL') {
        [, , $yr] = LeaveService::yearBounds($from);
        DB::insert('leave_ledger', ['employee_id' => $e['id'], 'leave_type_id' => $lt['AL'], 'leave_year' => $yr, 'entry_type' => 'deduction',
            'days' => -$days, 'leave_request_id' => $id, 'note' => 'Annual leave ' . uk_date($from) . '–' . uk_date($to), 'created_at' => now_utc()]);
    }
    return $id;
};
// Spread some taken annual leave across the year so balances look realistic.
foreach ($emp as $n => $e) {
    if ($e['status'] === 'left' || $n === 26) {
        continue;
    }
    $start = date('Y-m-d', strtotime("$year-03-02 +" . (($n * 17) % 120) . ' days'));
    while (in_array((int) date('N', strtotime($start)), [6, 7], true)) {
        $start = date('Y-m-d', strtotime("$start +1 day"));
    }
    if ($start < $e['start_date']) {
        continue;
    }
    $end = date('Y-m-d', strtotime("$start +" . (($n % 3) + 2) . ' days'));
    $leave($n, 'AL', $start, $end, 'approved', 'Holiday');
}
$leave(12, 'AL', d('-7 days'), d('-7 days'), 'approved', 'Family event');
$leave(12, 'AL', "$year-12-21", "$year-12-24", 'pending', 'Christmas with family');
$leave(4, 'AL', date('Y-m-d', strtotime($thisWeek . ' +14 days')), date('Y-m-d', strtotime($thisWeek . ' +18 days')), 'pending', 'Trip to Leicester');
$leave(5, 'COMP', d('+1 day'), d('+1 day'), 'pending', 'Funeral');
$leave(21, 'PAT', date('Y-m-d', strtotime($thisWeek)), date('Y-m-d', strtotime($thisWeek . ' +13 days')), 'approved', 'Paternity leave');
$leave(14, 'SICK', d('-9 days'), d('-8 days'), 'approved', 'Flu');
$leave(20, 'AL', d('+30 days'), d('+31 days'), 'rejected', 'Short break');
DB::update('leave_requests', ['decision_note' => 'Peak period: two planners already off'], "status = 'rejected'");

// Hours exception for Marek (approved by HR)
DB::insert('hours_exceptions', ['employee_id' => $emp[25]['id'], 'week_start' => $thisWeek, 'limit_minutes' => 2400, 'approved_minutes' => 2460,
    'reason' => 'Agreed overtime to cover vehicle shortage', 'approved_by' => $userIds[2], 'approved_at' => $nowUtc]);

// ---------------------------------------------------------------- Payslips: last three months published, plus drafts for some
$periods = [];
for ($m = 3; $m >= 1; $m--) {
    $periods[] = [(int) date('Y', strtotime("$today first day of -$m month")), (int) date('n', strtotime("$today first day of -$m month"))];
}
foreach ($emp as $n => $e) {
    if ($e['status'] === 'left') {
        continue;
    }
    foreach ($periods as $pi => [$py, $pm]) {
        if (date('Y-m-t', strtotime("$py-$pm-01")) < $e['start_date']) {
            continue;
        }
        $draft = $pi === 2 && $n % 4 === 0;
        [$key, $size, $sha] = storePdf('payslips', 'Payslip ' . month_name($pm) . " $py", [
            $e['first_name'] . ' ' . $e['last_name'] . ' (' . $e['employee_code'] . ')', 'Payroll ref: ' . $e['payroll_reference'],
            'Gross pay: GBP ' . number_format($e['salary'] / 12, 2), 'Fictional demo payslip - Menakan Transport Ltd',
        ]);
        DB::insert('payslips', [
            'employee_id' => $e['id'], 'period_year' => $py, 'period_month' => $pm, 'original_filename' => sprintf('%s_%04d-%02d.pdf', $e['employee_code'], $py, $pm),
            'storage_key' => $key, 'sha256' => $sha, 'size_bytes' => $size, 'status' => $draft ? 'draft' : 'published', 'is_current' => 1,
            'published_at' => $draft ? null : gmdate('Y-m-d H:i:s', strtotime(date('Y-m-t', strtotime("$py-$pm-01")) . ' 09:00:00')),
        ]);
    }
}

// ---------------------------------------------------------------- Notifications
DB::insert('notifications', ['user_id' => $userIds[12], 'type' => 'shift', 'title' => 'New shifts published', 'body' => 'Your rota has been updated.', 'link' => '/my/shifts', 'created_at' => $nowUtc]);
DB::insert('notifications', ['user_id' => $userIds[12], 'type' => 'payslip', 'title' => 'Payslip available: ' . month_name($periods[2][1]) . ' ' . $periods[2][0], 'link' => '/my/payslips', 'created_at' => $nowUtc]);

DB::pdo()->commit();

require __DIR__ . '/demo-profiles.php';

$counts = [];
foreach (['employees', 'users', 'employee_documents', 'shifts', 'attendance_records', 'leave_requests', 'leave_ledger', 'payslips'] as $t) {
    $counts[] = $t . '=' . DB::value("SELECT COUNT(*) FROM $t");
}
fwrite(STDOUT, "Demo data loaded: " . implode(', ', $counts) . "\n");
fwrite(STDOUT, "Next: php bin/create-admin.php --username=admin --employee-code=MT0001
");
