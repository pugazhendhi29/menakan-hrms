<?php

/**
 * Fills the extended profile fields for the fictional demo employees: location, source of hire,
 * personal/visa/right-to-work details, identity numbers, personal contact details, work experience,
 * education and generated placeholder photos. Only empty fields are filled, so it is safe to re-run.
 *
 *   Test fixture, called at the end of tests/fixtures/demo-seed.php
 */

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    require dirname(__DIR__, 2) . '/config/bootstrap.php';
}

use App\Core\DB;
use App\Services\FileStore;

/** Draws a simple initials avatar (fictional placeholder photo) and stores it privately. */
function demo_photo(string $initials, int $seed): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $palette = [[15, 31, 51], [27, 49, 80], [31, 95, 168], [31, 138, 76], [122, 79, 181], [168, 100, 0], [192, 57, 43], [91, 107, 127]];
    [$r, $g, $b] = $palette[$seed % count($palette)];
    $img = imagecreatetruecolor(240, 240);
    imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
    imagefilledrectangle($img, 0, 222, 240, 240, imagecolorallocate($img, 245, 179, 1));
    $white = imagecolorallocate($img, 255, 255, 255);
    // Built-in font 5 scaled up for a bold, readable placeholder.
    $small = imagecreatetruecolor(24, 16);
    imagefill($small, 0, 0, imagecolorallocate($small, $r, $g, $b));
    imagestring($small, 5, 3, 0, $initials, imagecolorallocate($small, 255, 255, 255));
    imagecopyresized($img, $small, 30, 60, 0, 0, 180, 120, 24, 16);
    unset($white);
    $tmp = tempnam(sys_get_temp_dir(), 'ph');
    imagepng($img, $tmp);
    return FileStore::storeExisting($tmp, 'photos', 'png');
}

$profiles = [
    // code => [location, source, marital, nationality, visa type, visa start, visa end (relative days or null), rtw status, NI, passport, relevant past roles]
    'MT0001' => ['HO', 'Recruitment agency', 'Married', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0002' => ['DAV', 'Employee referral', 'Married', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0003' => ['HO', 'Job board', 'Single', 'Irish', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0004' => ['NHB', 'Direct application', 'Married', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0005' => ['HO', 'Company website', 'Single', 'Ghanaian', 'Indefinite leave to remain', '2019-03-01', null, 'Checked - unlimited right'],
    'MT0006' => ['DAV', 'Job board', 'Divorced', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0007' => ['HO', 'Employee referral', 'Civil partnership', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0009' => ['NHB', 'Jobcentre Plus', 'Single', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0010' => ['NHB', 'Direct application', 'Married', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0011' => ['DAV', 'Recruitment agency', 'Married', 'Nigerian', 'Skilled Worker', '2023-10-01', 540, 'Checked - time-limited'],
    'MT0012' => ['DAV', 'Job board', 'Married', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0013' => ['HO', 'Company website', 'Single', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0014' => ['DAV', 'Employee referral', 'Married', 'Ghanaian', 'Indefinite leave to remain', '2016-05-01', null, 'Checked - unlimited right'],
    'MT0015' => ['DAV', 'Social media', 'Single', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0016' => ['DAV', 'Recruitment agency', 'Married', 'Pakistani', 'Family', '2024-01-15', 75, 'Follow-up check due'],
    'MT0017' => ['NHB', 'Job board', 'Single', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0018' => ['NHB', 'Rehire', 'Cohabiting', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0019' => ['DAV', 'Job board', 'Single', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0020' => ['HO', 'Recruitment agency', 'Married', 'Indian', 'Skilled Worker', '2024-05-20', 45, 'Checked - time-limited'],
    'MT0021' => ['DAV', 'Employee referral', 'Married', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0022' => ['NHB', 'Job board', 'Single', 'Polish', 'EU Settlement Scheme - settled', '2021-02-10', null, 'Checked - unlimited right'],
    'MT0023' => ['DAV', 'Direct application', 'Single', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0024' => ['HO', 'Company website', 'Married', 'British', 'Not required (British/Irish citizen)', null, null, 'Checked - unlimited right'],
    'MT0025' => ['DAV', 'Recruitment agency', 'Married', 'Polish', 'EU Settlement Scheme - pre-settled', '2022-03-01', 160, 'Checked - time-limited'],
    'MT0026' => ['HO', 'Recruitment agency', 'Single', 'German', 'EU Settlement Scheme - settled', '2020-11-01', null, 'Not yet checked'],
];
$history = [
    ['Eddie Stobart Logistics', 'HGV Class 1 Driver', 1095, 'Daventry', 'Transport manager (fictional)', 1],
    ['DHL Supply Chain', 'Warehouse Operative', 730, 'Northampton', 'Shift supervisor (fictional)', 1],
    ['Royal Mail', 'Delivery Driver', 548, 'Rugby', 'Delivery office manager (fictional)', 1],
    ['Tesco Stores', 'Customer Assistant', 365, 'Milton Keynes', null, 0],
    ['Wincanton', 'Transport Planner', 912, 'Coventry', 'Planning lead (fictional)', 1],
    ['Kuehne + Nagel', 'Freight Coordinator', 640, 'Leicester', 'Operations manager (fictional)', 1],
];
$schools = [
    ['Northampton College', 'Diploma', 'Logistics Operations'],
    ['University of Northampton', "Bachelor's Degree", 'Business and Supply Chain Management'],
    ['Daventry Academy', 'GCSE', 'English, Maths, Science'],
    ['Coventry University', "Master's Degree", 'Logistics and Supply Chain Management'],
    ['Moulton College', 'A-Level', 'Business Studies'],
];
$locIds = array_column(DB::all('SELECT id, name FROM locations'), 'id', 'name');
$locMap = ['HO' => $locIds['Head Office'] ?? null, 'DAV' => $locIds['Daventry Depot'] ?? null, 'NHB' => $locIds['Northampton Hub'] ?? null];
$prefixes = ['AB', 'CE', 'JT', 'KL', 'MR', 'PW', 'SE', 'WM'];
$today = date('Y-m-d');
$n = 0;

foreach (DB::all('SELECT * FROM employees ORDER BY id') as $i => $e) {
    $p = $profiles[$e['employee_code']] ?? null;
    if (!$p) {
        continue;
    }
    [$loc, $source, $marital, $nationality, $visa, $visaStart, $visaDays, $rtw] = $p;
    $fill = [
        'location_id' => $locMap[$loc], 'source_of_hire' => $source, 'marital_status' => $marital, 'nationality' => $nationality,
        'visa_type' => $visa, 'visa_start_date' => $visaStart, 'visa_end_date' => $visaDays === null ? null : date('Y-m-d', strtotime("$today +$visaDays days")),
        'rtw_check_status' => $rtw, 'rtw_check_date' => $rtw === 'Not yet checked' ? null : max($e['start_date'], date('Y-m-d', strtotime($e['start_date'] . ' -7 days'))),
        'ni_number' => $prefixes[$i % count($prefixes)] . sprintf('%06d', 104857 + $i * 7919 % 899999) . chr(65 + $i % 4),
        'passport_number' => $i % 3 === 0 ? null : sprintf('%d', 530000000 + $i * 104729),
        'personal_mobile' => '07700 9' . sprintf('%05d', 50000 + $i * 211),
        'personal_email' => strtolower($e['first_name'] . '.' . $e['last_name']) . $i . '@example.com',
        'permanent_same_as_present' => $i % 5 === 0 ? 0 : 1,
        'permanent_address' => $i % 5 === 0 ? (12 + $i) . " Family Road\nLeicester\nLE1 " . (1 + $i % 9) . 'AB' : null,
    ];
    $update = [];
    foreach ($fill as $k => $v) {
        if ($k === 'permanent_same_as_present') {
            if ($e['permanent_address'] === null) {
                $update[$k] = $v;
            }
        } elseif ($e[$k] === null && $v !== null) {
            $update[$k] = $v;
        }
    }
    if ($update) {
        DB::update('employees', $update, 'id = ?', [(int) $e['id']]);
    }
    if (!DB::value('SELECT COUNT(*) FROM employee_work_experience WHERE employee_id = ?', [$e['id']])) {
        $end = date('Y-m-d', strtotime($e['start_date'] . ' -14 days'));
        foreach ([$history[$i % count($history)], $history[($i + 3) % count($history)]] as $k => [$company, $title, $days, $town, $ref, $relevant]) {
            if ($k === 1 && $i % 2 === 0) {
                break;
            }
            $from = date('Y-m-d', strtotime("$end -$days days"));
            DB::insert('employee_work_experience', ['employee_id' => $e['id'], 'company_name' => $company, 'job_title' => $title,
                'from_date' => $from, 'to_date' => $end, 'location' => $town, 'reference_name' => $ref,
                'reference_email' => $ref ? 'reference' . $i . '@example.com' : null, 'reference_phone' => $ref ? '+44 77009' . sprintf('%05d', 70000 + $i * 37) : null,
                'is_relevant' => $relevant]);
            $end = date('Y-m-d', strtotime("$from -30 days"));
        }
    }
    if (!DB::value('SELECT COUNT(*) FROM employee_education WHERE employee_id = ?', [$e['id']])) {
        [$inst, $qual, $spec] = $schools[$i % count($schools)];
        DB::insert('employee_education', ['employee_id' => $e['id'], 'institute_name' => $inst, 'education_level' => $qual, 'specialization' => $spec,
            'completion_date' => date('Y-m-d', strtotime($e['start_date'] . ' -' . (3 + $i % 6) . ' years'))]);
    }
    if ($e['photo_key'] === null && $i % 3 !== 2) {
        $key = demo_photo(strtoupper(mb_substr($e['first_name'], 0, 1) . mb_substr($e['last_name'], 0, 1)), $i);
        if ($key) {
            DB::update('employees', ['photo_key' => $key], 'id = ?', [(int) $e['id']]);
        }
    }
    $n++;
}
fwrite(STDOUT, "Profile details filled for $n demo employees.\n");
