<?php
use App\Services\EmployeeService;

$e = $employee ?? [];
$v = fn (string $k, $default = '') => e(old($k, $e[$k] ?? $default));
$sel = fn (string $k, $value, $default = '') => selected($value, old($k, $e[$k] ?? $default));
$action = $employee ? url('/employees/' . $employee['id']) : url('/employees');
$samePermanent = old('permanent_same_as_present', $employee ? (string) $e['permanent_same_as_present'] : '1');
?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-xl-6 d-flex flex-column gap-3">

      <div class="card">
        <div class="card-header">Basic information <?php if (!$employee): ?><span class="badge text-bg-light border">Employee ID will be <?= e($nextCode) ?></span><?php endif; ?></div>
        <div class="card-body row g-3">
          <div class="col-sm-4"><label class="form-label" for="employee_code_ro">Employee ID</label><input class="form-control idc" id="employee_code_ro" value="<?= e($employee['employee_code'] ?? $nextCode) ?>" readonly></div>
          <div class="col-sm-2"><label class="form-label" for="title">Title</label><input class="form-control<?= is_invalid('title') ?>" id="title" name="title" value="<?= $v('title') ?>" maxlength="10" placeholder="Mr/Ms"></div>
          <div class="col-sm-6"><label class="form-label" for="preferred_name">Preferred name</label><input class="form-control" id="preferred_name" name="preferred_name" value="<?= $v('preferred_name') ?>" maxlength="60"></div>
          <div class="col-sm-6"><label class="form-label req" for="first_name">First name</label><input class="form-control<?= is_invalid('first_name') ?>" id="first_name" name="first_name" value="<?= $v('first_name') ?>" required maxlength="60"><?= field_error('first_name') ?></div>
          <div class="col-sm-6"><label class="form-label req" for="last_name">Last name</label><input class="form-control<?= is_invalid('last_name') ?>" id="last_name" name="last_name" value="<?= $v('last_name') ?>" required maxlength="60"><?= field_error('last_name') ?></div>
          <div class="col-12"><label class="form-label" for="email">Email address (work)</label><input class="form-control<?= is_invalid('email') ?>" type="email" id="email" name="email" value="<?= $v('email') ?>" maxlength="190"><?= field_error('email') ?></div>
          <div class="col-12"><label class="form-label" for="photo">Employee photo</label>
            <?php if (!empty($e['photo_key'])): ?><div class="mb-2"><?= employee_avatar($e, 64) ?> <small class="text-muted-2 ms-2">Choose a new file to replace the current photo.</small></div><?php endif; ?>
            <input class="form-control<?= is_invalid('photo') ?>" type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
            <div class="form-text">JPG or PNG, up to 2 MB. Square head-and-shoulders photos look best.</div><?= field_error('photo') ?></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Work information</div>
        <div class="card-body row g-3">
          <div class="col-sm-6"><label class="form-label req" for="department">Department</label>
            <input class="form-control<?= is_invalid('department') ?>" id="department" name="department" value="<?= e(old('department', $e['department'] ?? '')) ?>" maxlength="80" required list="department-list" autocomplete="off">
            <datalist id="department-list"><?php foreach ($departments as $d): ?><option value="<?= e($d['name']) ?>"><?php endforeach; ?></datalist><?= field_error('department') ?></div>
          <div class="col-sm-6"><label class="form-label" for="location">Location</label>
            <input class="form-control<?= is_invalid('location') ?>" id="location" name="location" value="<?= e(old('location', $e['location_name'] ?? '')) ?>" maxlength="80" list="location-list" autocomplete="off">
            <datalist id="location-list"><?php foreach ($locations as $l): ?><option value="<?= e($l['name']) ?>"><?php endforeach; ?></datalist><?= field_error('location') ?></div>
          <div class="col-sm-6"><label class="form-label req" for="designation">Designation</label>
            <input class="form-control<?= is_invalid('designation') ?>" id="designation" name="designation" value="<?= e(old('designation', $e['job_title'] ?? '')) ?>" maxlength="100" required list="designation-list" autocomplete="off">
            <datalist id="designation-list"><?php foreach ($jobTitles as $j): ?><option value="<?= e($j['title']) ?>"><?php endforeach; ?></datalist><?= field_error('designation') ?></div>          <div class="col-sm-6"><label class="form-label req" for="employment_type">Employment type</label>
            <select class="form-select" id="employment_type" name="employment_type">
              <?php foreach (EmployeeService::TYPES as $k => $label): ?><option value="<?= e($k) ?>"<?= $sel('employment_type', $k, 'full_time') ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-6"><label class="form-label req" for="status">Employee status</label>
            <select class="form-select" id="status" name="status">
              <?php foreach (EmployeeService::statuses(false, (string) ($employee['status'] ?? '')) as $k => $label): ?><option value="<?= e($k) ?>"<?= $sel('status', $k, 'active') ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-6"><label class="form-label" for="source_of_hire">Source of hire</label>
            <select class="form-select<?= is_invalid('source_of_hire') ?>" id="source_of_hire" name="source_of_hire"><option value="">—</option>
              <?php foreach (EmployeeService::SOURCES as $s): ?><option<?= $sel('source_of_hire', $s) ?>><?= e($s) ?></option><?php endforeach; ?>
            </select><?= field_error('source_of_hire') ?></div>
          <div class="col-sm-6"><label class="form-label req" for="start_date">Date of joining</label><input class="form-control<?= is_invalid('start_date') ?>" type="date" id="start_date" name="start_date" value="<?= $v('start_date') ?>" required><?= field_error('start_date') ?></div>
          <div class="col-sm-6"><label class="form-label" for="probation_end_date">Probation ends</label><input class="form-control<?= is_invalid('probation_end_date') ?>" type="date" id="probation_end_date" name="probation_end_date" value="<?= $v('probation_end_date') ?>"><?= field_error('probation_end_date') ?></div>
          <div class="col-sm-4"><label class="form-label" for="contract_hours">Contract hours / week</label><input class="form-control<?= is_invalid('contract_hours') ?>" id="contract_hours" name="contract_hours" value="<?= $v('contract_hours') ?>" inputmode="decimal"><?= field_error('contract_hours') ?></div>
          <div class="col-sm-4"><label class="form-label" for="weekly_hours_limit">Weekly limit override</label><input class="form-control<?= is_invalid('weekly_hours_limit') ?>" id="weekly_hours_limit" name="weekly_hours_limit" value="<?= $v('weekly_hours_limit') ?>" inputmode="decimal" placeholder="Blank = contract / <?= e(setting('weekly_hours_limit')) ?>"><?= field_error('weekly_hours_limit') ?></div>
          <div class="col-12 form-text mt-0">Current experience (from the date of joining) and total experience (including the work experience below) are calculated automatically.</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Hierarchy information</div>
        <div class="card-body">
          <label class="form-label" for="reporting_manager">Reporting manager</label>
          <input class="form-control<?= is_invalid('reporting_manager') ?>" id="reporting_manager" name="reporting_manager" value="<?= e(old('reporting_manager', ($e['line_manager_id'] ?? null ? ($e['m_code'] . ' · ' . $e['m_first_name'] . ' ' . $e['m_last_name']) : ($e['reporting_manager_name'] ?? '')))) ?>" maxlength="120" list="manager-list" autocomplete="off" placeholder="Type a name or employee ID">
          <datalist id="manager-list"><?php foreach ($managers as $m): if ($employee && (int) $m['id'] === (int) $employee['id']) continue; ?><option value="<?= e($m['employee_code'] . ' · ' . employee_name($m)) ?>"><?php endforeach; ?></datalist>
          <?= field_error('reporting_manager') ?><div class="form-text">Pick a suggestion to link an employee, or type any name (e.g. an external manager).</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Separation information</div>
        <div class="card-body row g-3">
          <div class="col-sm-5"><label class="form-label" for="end_date">Date of exit</label><input class="form-control<?= is_invalid('end_date') ?>" type="date" id="end_date" name="end_date" value="<?= $v('end_date') ?>"><?= field_error('end_date') ?></div>
          <div class="col-sm-7"><label class="form-label" for="leaving_reason">Reason for leaving</label><input class="form-control" id="leaving_reason" name="leaving_reason" value="<?= $v('leaving_reason') ?>" maxlength="120"></div>
          <div class="col-12 form-text mt-0">Setting the status to Left needs a date of exit and deactivates the portal account.</div>
        </div>
      </div>
    </div>

    <div class="col-xl-6 d-flex flex-column gap-3">
      <div class="card">
        <div class="card-header">Personal details</div>
        <div class="card-body row g-3">
          <div class="col-sm-6"><label class="form-label" for="date_of_birth">Date of birth</label><input class="form-control<?= is_invalid('date_of_birth') ?>" type="date" id="date_of_birth" name="date_of_birth" value="<?= $v('date_of_birth') ?>"><?= field_error('date_of_birth') ?><div class="form-text">Age is calculated from this.</div></div>
          <div class="col-sm-6"><label class="form-label" for="gender">Gender <small class="text-muted-2">(optional)</small></label><input class="form-control" id="gender" name="gender" value="<?= $v('gender') ?>" maxlength="30" list="gender-list">
            <datalist id="gender-list"><option>Female</option><option>Male</option><option>Non-binary</option><option>Prefer not to say</option></datalist></div>
          <div class="col-sm-6"><label class="form-label" for="marital_status">Marital status</label>
            <select class="form-select<?= is_invalid('marital_status') ?>" id="marital_status" name="marital_status"><option value="">—</option>
              <?php foreach (EmployeeService::MARITAL as $m): ?><option<?= $sel('marital_status', $m) ?>><?= e($m) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-6"><label class="form-label" for="nationality">Nationality</label><input class="form-control" id="nationality" name="nationality" value="<?= $v('nationality') ?>" maxlength="60" list="nationality-list">
            <datalist id="nationality-list"><option>British</option><option>Irish</option><option>Polish</option><option>Romanian</option><option>Indian</option><option>Nigerian</option><option>Pakistani</option><option>Portuguese</option><option>Lithuanian</option><option>Ghanaian</option></datalist></div>
          <div class="col-12"><label class="form-label" for="visa_type">Visa type</label><input class="form-control" id="visa_type" name="visa_type" value="<?= $v('visa_type') ?>" maxlength="60" list="visa-list">
            <datalist id="visa-list"><?php foreach (EmployeeService::VISA_TYPES as $t): ?><option><?= e($t) ?></option><?php endforeach; ?></datalist></div>
          <div class="col-sm-6"><label class="form-label" for="visa_start_date">Visa start date</label><input class="form-control<?= is_invalid('visa_start_date') ?>" type="date" id="visa_start_date" name="visa_start_date" value="<?= $v('visa_start_date') ?>"><?= field_error('visa_start_date') ?></div>
          <div class="col-sm-6"><label class="form-label" for="visa_end_date">Visa end date</label><input class="form-control<?= is_invalid('visa_end_date') ?>" type="date" id="visa_end_date" name="visa_end_date" value="<?= $v('visa_end_date') ?>"><?= field_error('visa_end_date') ?></div>
          <div class="col-sm-7"><label class="form-label" for="rtw_check_status">Right to work audit check</label>
            <select class="form-select<?= is_invalid('rtw_check_status') ?>" id="rtw_check_status" name="rtw_check_status"><option value="">—</option>
              <?php foreach (EmployeeService::RTW_STATUSES as $s): ?><option<?= $sel('rtw_check_status', $s) ?>><?= e($s) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-5"></div>
          <div class="col-sm-6"><label class="form-label" for="rtw_check_date">Last Checked Date</label><input class="form-control<?= is_invalid('rtw_check_date') ?>" type="date" id="rtw_check_date" name="rtw_check_date" value="<?= $v('rtw_check_date') ?>" max="<?= e(uk_today()) ?>"><?= field_error('rtw_check_date') ?></div>
          <div class="col-sm-6"><label class="form-label" for="rtw_followup_date">Follow-up Date</label><input class="form-control<?= is_invalid('rtw_followup_date') ?>" type="date" id="rtw_followup_date" name="rtw_followup_date" value="<?= $v('rtw_followup_date') ?>"><?= field_error('rtw_followup_date') ?><div class="form-text">When the next right to work check is due.</div></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Identity information <span class="badge text-bg-danger">Admin only</span></div>
        <div class="card-body row g-3">
          <div class="col-sm-6"><label class="form-label" for="ni_number">National Insurance number</label><input class="form-control idc<?= is_invalid('ni_number') ?>" id="ni_number" name="ni_number" value="<?= $v('ni_number') ?>" maxlength="13" placeholder="AB 12 34 56 C" autocomplete="off"><?= field_error('ni_number') ?></div>
          <div class="col-sm-6"><label class="form-label" for="passport_number">Passport number</label><input class="form-control idc<?= is_invalid('passport_number') ?>" id="passport_number" name="passport_number" value="<?= $v('passport_number') ?>" maxlength="20" autocomplete="off"><?= field_error('passport_number') ?></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Contact details</div>
        <div class="card-body row g-3">
          <div class="col-12"><label class="form-label" for="address_line1">Present address</label><input class="form-control mb-2" id="address_line1" name="address_line1" value="<?= $v('address_line1') ?>" maxlength="120" placeholder="Line 1"><input class="form-control" id="address_line2" name="address_line2" value="<?= $v('address_line2') ?>" maxlength="120" placeholder="Line 2" aria-label="Address line 2"></div>
          <div class="col-sm-7"><label class="form-label" for="city">Town / city</label><input class="form-control" id="city" name="city" value="<?= $v('city') ?>" maxlength="80"></div>
          <div class="col-sm-5"><label class="form-label" for="postcode">Postcode</label><input class="form-control<?= is_invalid('postcode') ?>" id="postcode" name="postcode" value="<?= $v('postcode') ?>" maxlength="10"><?= field_error('postcode') ?></div>
          <div class="col-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="permanent_same_as_present" name="permanent_same_as_present" value="1" data-reveal-inverse="#permanent-box"<?= checked($samePermanent === '1') ?>><label class="form-check-label" for="permanent_same_as_present">Permanent address is the same as the present address</label></div>
            <div id="permanent-box" class="mt-2"><label class="form-label" for="permanent_address">Permanent address</label><textarea class="form-control<?= is_invalid('permanent_address') ?>" id="permanent_address" name="permanent_address" rows="3" maxlength="400"><?= $v('permanent_address') ?></textarea><?= field_error('permanent_address') ?></div>
          </div>
          <div class="col-sm-6"><label class="form-label" for="personal_mobile">Personal mobile number</label><input class="form-control<?= is_invalid('personal_mobile') ?>" id="personal_mobile" name="personal_mobile" value="<?= $v('personal_mobile') ?>" maxlength="30"><?= field_error('personal_mobile') ?></div>
          <div class="col-sm-6"><label class="form-label" for="personal_email">Personal email address</label><input class="form-control<?= is_invalid('personal_email') ?>" type="email" id="personal_email" name="personal_email" value="<?= $v('personal_email') ?>" maxlength="190"><?= field_error('personal_email') ?></div>
          <div class="col-sm-6"><label class="form-label" for="phone">Work phone</label><input class="form-control<?= is_invalid('phone') ?>" id="phone" name="phone" value="<?= $v('phone') ?>" maxlength="30"><?= field_error('phone') ?></div>
          <div class="col-sm-6"></div>
          <div class="col-sm-5"><label class="form-label" for="emergency_contact_name">Emergency contact</label><input class="form-control" id="emergency_contact_name" name="emergency_contact_name" value="<?= $v('emergency_contact_name') ?>" maxlength="120"></div>
          <div class="col-sm-4"><label class="form-label" for="emergency_contact_phone">Contact phone</label><input class="form-control<?= is_invalid('emergency_contact_phone') ?>" id="emergency_contact_phone" name="emergency_contact_phone" value="<?= $v('emergency_contact_phone') ?>" maxlength="30"><?= field_error('emergency_contact_phone') ?></div>
          <div class="col-sm-3"><label class="form-label" for="emergency_contact_relationship">Relationship</label><input class="form-control" id="emergency_contact_relationship" name="emergency_contact_relationship" value="<?= $v('emergency_contact_relationship') ?>" maxlength="40"></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Pay <span class="badge text-bg-danger">Admin only</span></div>
        <div class="card-body row g-3">
          <div class="col-sm-4"><label class="form-label" for="salary">Annual salary (£)</label><input class="form-control<?= is_invalid('salary') ?>" id="salary" name="salary" value="<?= $v('salary') ?>" inputmode="decimal"><?= field_error('salary') ?></div>
          <div class="col-sm-4"><label class="form-label" for="pay_frequency">Pay frequency</label>
            <select class="form-select" id="pay_frequency" name="pay_frequency"><option value="">—</option>
              <?php foreach (EmployeeService::PAY_FREQ as $k => $label): ?><option value="<?= e($k) ?>"<?= $sel('pay_frequency', $k) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-4"><label class="form-label" for="payroll_reference">Payroll reference</label><input class="form-control" id="payroll_reference" name="payroll_reference" value="<?= $v('payroll_reference') ?>" maxlength="40"></div>
          <div class="col-12"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="3" maxlength="5000"><?= $v('notes') ?></textarea></div>
        </div>
      </div>
    </div>
  </div>
  <div class="d-flex flex-column gap-3 mt-3">
    <?= App\Core\View::render('employees/_history_fields', ['experienceRows' => $experienceRows, 'educationRows' => $educationRows], null) ?>
  </div>
  <div class="d-flex gap-2 mt-2">
    <button class="btn btn-primary" type="submit"><?= $employee ? 'Save changes' : 'Create employee' ?></button>
    <a class="btn btn-outline-secondary" href="<?= e(url($employee ? '/employees/' . $employee['id'] . '?tab=personal' : '/employees')) ?>">Cancel</a>
  </div>
</form>
