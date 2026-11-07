<?php
use App\Services\EmployeeService;

/**
 * Personal details, Identity information and Contact details cards. Shared by the HR employee
 * form and the employee's own "Edit my profile" page, which may change only these fields
 * (EmployeeService::SELF_FIELDS). Expects $e (employee row, or [] for a new employee).
 */
$v = fn (string $k, $default = '') => e(old($k, $e[$k] ?? $default));
$sel = fn (string $k, $value, $default = '') => selected($value, old($k, $e[$k] ?? $default));
$samePermanent = old('permanent_same_as_present', $e ? (string) $e['permanent_same_as_present'] : '1');
?>
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
        <div class="card-header">Identity information</div>
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
