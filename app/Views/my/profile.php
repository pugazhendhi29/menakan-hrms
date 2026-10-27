<?php
use App\Services\EmployeeService;

$dd = fn ($v) => ($v === null || $v === '') ? '<span class="text-muted-2">—</span>' : e($v);
$present = implode("\n", array_filter([$me['address_line1'], $me['address_line2'], $me['city'], $me['postcode']]));
$age = EmployeeService::age($me['date_of_birth']);
?>
<div class="row g-3">
  <div class="col-lg-3">
    <div class="card h-100"><div class="card-body d-flex flex-column align-items-center gap-2 text-center">
      <?php if ($me['photo_key']): ?>
        <img class="photo-lg" src="<?= e(employee_photo_url($me, 132)) ?>" alt="My photo">
      <?php else: ?>
        <div class="photo-lg"><?= e(mb_substr($me['first_name'], 0, 1) . mb_substr($me['last_name'], 0, 1)) ?></div>
      <?php endif; ?>
      <div class="fw-semibold"><?= e(employee_name($me)) ?></div>
      <div class="idc text-muted-2"><?= e($me['employee_code']) ?></div>
      <small class="text-muted-2">Photos are added by HR.</small>
    </div></div>
  </div>
  <div class="col-lg-9">
    <div class="row g-3">
      <div class="col-xl-6"><div class="card h-100"><div class="card-header">Basic information</div><div class="card-body"><dl class="dl-grid">
        <dt>Employee ID</dt><dd class="idc"><?= e($me['employee_code']) ?></dd>
        <dt>First name</dt><dd><?= e($me['first_name']) ?></dd>
        <dt>Last name</dt><dd><?= e($me['last_name']) ?></dd>
        <dt>Preferred name</dt><dd><?= $dd($me['preferred_name']) ?></dd>
        <dt>Email address</dt><dd><?= $dd($me['email']) ?></dd>
      </dl></div></div></div>
      <div class="col-xl-6"><div class="card h-100"><div class="card-header">Work information</div><div class="card-body"><dl class="dl-grid">
        <dt>Department</dt><dd><?= $dd($me['department']) ?></dd>
        <dt>Location</dt><dd><?= $dd($me['location_name']) ?></dd>
        <dt>Designation</dt><dd><?= $dd($me['job_title']) ?></dd>
        <dt>Employment type</dt><dd><?= e(EmployeeService::TYPES[$me['employment_type']] ?? '') ?><?= $me['contract_hours'] !== null ? ', ' . e($me['contract_hours']) . ' hours a week' : '' ?></dd>
        <dt>Employee status</dt><dd><?= status_badge($me['status']) ?></dd>
        <dt>Date of joining</dt><dd><?= e(uk_date($me['start_date'])) ?></dd>
        <dt>Current experience</dt><dd><?= e(EmployeeService::formatMonths($experience['current'])) ?></dd>
        <dt>Total experience</dt><dd><?= e(EmployeeService::formatMonths($experience['total'])) ?></dd>
        <dt>Reporting manager</dt><dd><?= $dd(trim(($me['m_first_name'] ?? '') . ' ' . ($me['m_last_name'] ?? ''))) ?></dd>
      </dl></div></div></div>
      <div class="col-xl-6"><div class="card h-100"><div class="card-header">Personal details</div><div class="card-body"><dl class="dl-grid">
        <dt>Date of birth</dt><dd><?= $me['date_of_birth'] ? e(uk_date($me['date_of_birth'])) : $dd(null) ?></dd>
        <dt>Age</dt><dd><?= $age !== null ? e($age) . ' years' : $dd(null) ?></dd>
        <dt>Marital status</dt><dd><?= $dd($me['marital_status']) ?></dd>
        <dt>Nationality</dt><dd><?= $dd($me['nationality']) ?></dd>
        <dt>Visa type</dt><dd><?= $dd($me['visa_type']) ?></dd>
        <dt>Visa end date</dt><dd><?= $me['visa_end_date'] ? e(uk_date($me['visa_end_date'])) : $dd(null) ?></dd>
        <dt>National Insurance no.</dt><dd class="idc"><?= e(EmployeeService::mask($me['ni_number'])) ?></dd>
        <dt>Passport number</dt><dd class="idc"><?= e(EmployeeService::mask($me['passport_number'])) ?></dd>
      </dl></div></div></div>
      <div class="col-xl-6"><div class="card h-100"><div class="card-header">Contact details</div><div class="card-body"><dl class="dl-grid">
        <dt>Present address</dt><dd><?= $present !== '' ? nl2br(e($present)) : $dd(null) ?></dd>
        <dt>Permanent address</dt><dd><?= $me['permanent_same_as_present'] ? 'Same as present address' : ($me['permanent_address'] ? nl2br(e($me['permanent_address'])) : $dd(null)) ?></dd>
        <dt>Personal mobile</dt><dd><?= $dd($me['personal_mobile']) ?></dd>
        <dt>Personal email</dt><dd><?= $dd($me['personal_email']) ?></dd>
        <dt>Emergency contact</dt><dd><?= $dd(trim(($me['emergency_contact_name'] ?? '') . ' ' . ($me['emergency_contact_phone'] ?? ''))) ?></dd>
      </dl></div></div></div>
    </div>
  </div>
</div>
<?= App\Core\View::render('employees/_history_tables', ['experienceRows' => $experienceRows, 'educationRows' => $educationRows], null) ?>
<div class="alert alert-light border small mb-0">Your profile is read only. If anything here is wrong or missing, please tell HR.</div>
