<?php
use App\Services\EmployeeService;

/** Personal & profile tab. Expects $e, $base, $experience, $experienceRows, $educationRows. */
$dd = fn ($v) => ($v === null || $v === '') ? '<span class="text-muted-2">—</span>' : e($v);
$age = EmployeeService::age($e['date_of_birth']);
$visaDays = $e['visa_end_date'] ? (int) floor((strtotime($e['visa_end_date']) - strtotime(uk_today())) / 86400) : null;
$present = implode("\n", array_filter([$e['address_line1'], $e['address_line2'], $e['city'], $e['postcode']]));
$editable = !$e['deleted_at'];
?>
<div class="row g-3">
  <div class="col-xl-3 col-lg-4">
    <div class="card h-100"><div class="card-body d-flex flex-column align-items-center gap-2 text-center">
      <?php if ($e['photo_key']): ?>
        <img class="photo-lg" src="<?= e(employee_photo_url($e, 132)) ?>" alt="Photo of <?= e(employee_name($e)) ?>">
      <?php else: ?>
        <div class="photo-lg" aria-label="No photo"><?= e(mb_substr($e['first_name'], 0, 1) . mb_substr($e['last_name'], 0, 1)) ?></div>
      <?php endif; ?>
      <div class="fw-semibold"><?= e(employee_name($e)) ?></div>
      <div class="idc text-muted-2"><?= e($e['employee_code']) ?></div>
      <?php if ($editable): ?>
      <form method="post" action="<?= e($base . '/photo') ?>" enctype="multipart/form-data" class="w-100 d-flex flex-column gap-2 mt-2">
        <?= csrf_field() ?>
        <input class="form-control form-control-sm" type="file" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required aria-label="Choose photo">
        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-camera"></i> <?= $e['photo_key'] ? 'Replace photo' : 'Upload photo' ?></button>
        <small class="text-muted-2">JPG or PNG, up to 2 MB</small>
      </form>
      <?php if ($e['photo_key']): ?>
        <form method="post" action="<?= e($base . '/photo/delete') ?>" data-confirm="Remove this employee's photo?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger">Remove photo</button></form>
      <?php endif; ?>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-xl-9 col-lg-8">
    <div class="row g-3">
      <div class="col-xl-6">
        <div class="card h-100"><div class="card-header">Basic information</div><div class="card-body"><dl class="dl-grid">
          <dt>Employee ID</dt><dd class="idc"><?= e($e['employee_code']) ?></dd>
          <dt>First name</dt><dd><?= $dd(trim(($e['title'] ? $e['title'] . ' ' : '') . $e['first_name'])) ?></dd>
          <dt>Last name</dt><dd><?= $dd($e['last_name']) ?></dd>
          <dt>Preferred name</dt><dd><?= $dd($e['preferred_name']) ?></dd>
          <dt>Email address</dt><dd><?= $dd($e['email']) ?></dd>
        </dl></div></div>
      </div>
      <div class="col-xl-6">
        <div class="card h-100"><div class="card-header">Hierarchy information</div><div class="card-body"><dl class="dl-grid">
          <dt>Reporting manager</dt><dd><?= $e['line_manager_id'] ? '<a href="' . e(url('/employees/' . $e['line_manager_id'] . '?tab=personal')) . '">' . e($e['m_code'] . ' ' . $e['m_first_name'] . ' ' . $e['m_last_name']) . '</a>' : $dd($e['reporting_manager_name']) ?></dd>
        </dl></div>
        <div class="card-header border-top">Separation information</div><div class="card-body"><dl class="dl-grid">
          <dt>Date of exit</dt><dd><?= $e['end_date'] ? e(uk_date($e['end_date'])) : $dd(null) ?><?= $e['leaving_reason'] ? ' · ' . e($e['leaving_reason']) : '' ?></dd>
        </dl></div></div>
      </div>
      <div class="col-12">
        <div class="card"><div class="card-header">Work information</div><div class="card-body row">
          <dl class="dl-grid col-xl-6 mb-0">
            <dt>Department</dt><dd><?= $dd($e['department']) ?></dd>
            <dt>Location</dt><dd><?= $dd($e['location_name']) ?></dd>
            <dt>Designation</dt><dd><?= $dd($e['job_title']) ?></dd>
            <dt>Employment type</dt><dd><?= e(EmployeeService::TYPES[$e['employment_type']] ?? $e['employment_type']) ?></dd>
            <dt>Employee status</dt><dd><?= employee_status_badge($e['status']) ?></dd>
          </dl>
          <dl class="dl-grid col-xl-6 mb-0">
            <dt>Source of hire</dt><dd><?= $dd($e['source_of_hire']) ?></dd>
            <dt>Date of joining</dt><dd><?= e(uk_date($e['start_date'])) ?></dd>
            <dt>Current experience</dt><dd><?= e(EmployeeService::formatMonths($experience['current'])) ?></dd>
            <dt>Total experience</dt><dd><?= e(EmployeeService::formatMonths($experience['total'])) ?>
              <?php if ($experience['previous']): ?><br><small class="text-muted-2">incl. <?= e(EmployeeService::formatMonths($experience['previous'])) ?> from work experience</small><?php endif; ?></dd>
          </dl>
        </div></div>
      </div>
      <div class="col-xl-6">
        <div class="card h-100"><div class="card-header">Personal details</div><div class="card-body"><dl class="dl-grid">
          <dt>Date of birth</dt><dd><?= $e['date_of_birth'] ? e(uk_date($e['date_of_birth'])) : $dd(null) ?></dd>
          <dt>Age</dt><dd><?= $age !== null ? e($age) . ' years' : $dd(null) ?></dd>
          <dt>Gender</dt><dd><?= $dd($e['gender']) ?></dd>
          <dt>Marital status</dt><dd><?= $dd($e['marital_status']) ?></dd>
          <dt>Nationality</dt><dd><?= $dd($e['nationality']) ?></dd>
          <dt>Visa type</dt><dd><?= $dd($e['visa_type']) ?></dd>
          <dt>Visa start date</dt><dd><?= $e['visa_start_date'] ? e(uk_date($e['visa_start_date'])) : $dd(null) ?></dd>
          <dt>Visa end date</dt><dd><?= $e['visa_end_date'] ? e(uk_date($e['visa_end_date'])) : $dd(null) ?>
            <?php if ($visaDays !== null && $visaDays < 0): ?> <span class="badge text-bg-danger">Expired</span>
            <?php elseif ($visaDays !== null && $visaDays <= 90): ?> <span class="badge text-bg-warning"><?= e($visaDays) ?> days left</span><?php endif; ?></dd>
          <dt>Right to work audit check</dt><dd><?= $dd($e['rtw_check_status']) ?></dd>
          <dt>Last checked date</dt><dd><?= $e['rtw_check_date'] ? e(uk_date($e['rtw_check_date'])) : $dd(null) ?></dd>
          <dt>Follow-up date</dt><dd><?= $e['rtw_followup_date'] ? e(uk_date($e['rtw_followup_date'])) : $dd(null) ?>
            <?php $fu = $e['rtw_followup_date'] ? (int) floor((strtotime($e['rtw_followup_date']) - strtotime(uk_today())) / 86400) : null; ?>
            <?php if ($fu !== null && $fu < 0): ?> <span class="badge text-bg-danger">Overdue</span><?php elseif ($fu !== null && $fu <= 30): ?> <span class="badge text-bg-warning">Due in <?= e($fu) ?> days</span><?php endif; ?></dd>
        </dl></div></div>
      </div>
      <div class="col-xl-6 d-flex flex-column gap-3">
        <div class="card"><div class="card-header">Identity information <span class="badge text-bg-danger">Admin only</span></div><div class="card-body"><dl class="dl-grid">
          <dt>National Insurance no.</dt><dd class="idc"><?= $dd($e['ni_number']) ?></dd>
          <dt>Passport number</dt><dd class="idc"><?= $dd($e['passport_number']) ?></dd>
        </dl></div></div>
        <div class="card flex-grow-1"><div class="card-header">Contact details</div><div class="card-body"><dl class="dl-grid">
          <dt>Present address</dt><dd><?= $present !== '' ? nl2br(e($present)) : $dd(null) ?></dd>
          <dt>Permanent address</dt><dd><?= $e['permanent_same_as_present'] ? '<span class="text-muted-2">Same as present address</span>' : ($e['permanent_address'] ? nl2br(e($e['permanent_address'])) : $dd(null)) ?></dd>
          <dt>Personal mobile</dt><dd><?= $dd($e['personal_mobile']) ?></dd>
          <dt>Personal email</dt><dd><?= $dd($e['personal_email']) ?></dd>
          <dt>Work phone</dt><dd><?= $dd($e['phone']) ?></dd>
          <dt>Emergency contact</dt><dd><?= $dd(trim(($e['emergency_contact_name'] ?? '') . ' ' . ($e['emergency_contact_relationship'] ? '(' . $e['emergency_contact_relationship'] . ')' : '') . ' ' . ($e['emergency_contact_phone'] ?? ''))) ?></dd>
        </dl></div></div>
      </div>
    </div>
  </div>
</div>

<?= App\Core\View::render('employees/_history_tables', ['experienceRows' => $experienceRows, 'educationRows' => $educationRows, 'editUrl' => $editable ? $base . '/edit' : null], null) ?>
