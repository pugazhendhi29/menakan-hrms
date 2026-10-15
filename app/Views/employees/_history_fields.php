<?php
use App\Services\EmployeeService;

/**
 * Repeatable Work Experience and Education rows, shared by the HR employee form and the
 * employee self-service page. Expects $experienceRows and $educationRows (DB rows); after a
 * failed submit the posted rows (old input) are shown instead so nothing typed is lost.
 */
$oldExp = old('experience', null);
$oldEdu = old('education', null);
$exp = is_array($oldExp) ? array_values($oldExp) : array_map(function ($r) {
    [$code, $num] = EmployeeService::splitPhone($r['reference_phone']);
    return $r + ['reference_phone_code' => $code, 'reference_phone' => $num];
}, $experienceRows);
$edu = is_array($oldEdu) ? array_values($oldEdu) : $educationRows;
if (!$exp) {
    $exp = [[]];
}
if (!$edu) {
    $edu = [[]];
}
$today = uk_today();

$expRow = function ($i, array $r) use ($today): string {
    $n = "experience[$i]";
    $k = "experience.$i.";
    $val = fn ($f) => e($r[$f] ?? '');
    $current = !empty($r['is_current']) && $r['is_current'] !== '0';
    ob_start(); ?>
  <div class="history-row border rounded p-3 mb-3" data-history-row>
    <div class="d-flex justify-content-between align-items-center mb-2">
      <b class="small text-muted-2 text-uppercase">Work experience <span data-row-number><?= is_int($i) ? $i + 1 : '' ?></span></b>
      <button type="button" class="btn btn-sm btn-outline-danger" data-remove-row aria-label="Remove this work experience"><i class="bi bi-trash"></i> Remove</button>
    </div>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label req">Company name</label><input class="form-control<?= is_invalid($k . 'company_name') ?>" name="<?= $n ?>[company_name]" value="<?= $val('company_name') ?>" maxlength="120"><?= field_error($k . 'company_name') ?></div>
      <div class="col-md-6"><label class="form-label req">Job title</label><input class="form-control<?= is_invalid($k . 'job_title') ?>" name="<?= $n ?>[job_title]" value="<?= $val('job_title') ?>" maxlength="100"><?= field_error($k . 'job_title') ?></div>
      <div class="col-md-4"><label class="form-label req">From date</label><input class="form-control<?= is_invalid($k . 'from_date') ?>" type="date" name="<?= $n ?>[from_date]" value="<?= $val('from_date') ?>" max="<?= e($today) ?>"><?= field_error($k . 'from_date') ?></div>
      <div class="col-md-4"><label class="form-label">To date</label><input class="form-control<?= is_invalid($k . 'to_date') ?>" type="date" name="<?= $n ?>[to_date]" value="<?= $current ? '' : $val('to_date') ?>" max="<?= e($today) ?>" data-to-date<?= $current ? ' disabled' : '' ?>><?= field_error($k . 'to_date') ?></div>
      <div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="<?= $n ?>[is_current]" value="1" id="cur-<?= $i ?>" data-current<?= $current ? ' checked' : '' ?>><label class="form-check-label" for="cur-<?= $i ?>">Currently works here</label></div></div>
      <div class="col-md-6"><label class="form-label">Location</label><input class="form-control<?= is_invalid($k . 'location') ?>" name="<?= $n ?>[location]" value="<?= $val('location') ?>" maxlength="120" placeholder="Town / country"><?= field_error($k . 'location') ?></div>
      <div class="col-md-6"><label class="form-label">Professional reference</label><input class="form-control<?= is_invalid($k . 'reference_name') ?>" name="<?= $n ?>[reference_name]" value="<?= $val('reference_name') ?>" maxlength="120" placeholder="Name and role"><?= field_error($k . 'reference_name') ?></div>
      <div class="col-md-6"><label class="form-label">Reference email</label><input class="form-control<?= is_invalid($k . 'reference_email') ?>" type="email" name="<?= $n ?>[reference_email]" value="<?= $val('reference_email') ?>" maxlength="190"><?= field_error($k . 'reference_email') ?></div>
      <div class="col-md-6"><label class="form-label">Reference mobile number</label>
        <div class="input-group">
          <select class="form-select flex-grow-0 w-auto" name="<?= $n ?>[reference_phone_code]" aria-label="Country code">
            <?php foreach (EmployeeService::DIAL_CODES as $code => $label): ?><option value="<?= e($code) ?>"<?= selected($code, $r['reference_phone_code'] ?? '+44') ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
          <input class="form-control<?= is_invalid($k . 'reference_phone') ?>" type="tel" name="<?= $n ?>[reference_phone]" value="<?= $val('reference_phone') ?>" maxlength="20" placeholder="7700 900123" inputmode="tel">
        </div><?= field_error($k . 'reference_phone') ?></div>
    </div>
  </div>
<?php return (string) ob_get_clean();
};

$eduRow = function ($i, array $r): string {
    $n = "education[$i]";
    $k = "education.$i.";
    ob_start(); ?>
  <div class="history-row border rounded p-3 mb-3" data-history-row>
    <div class="d-flex justify-content-between align-items-center mb-2">
      <b class="small text-muted-2 text-uppercase">Education <span data-row-number><?= is_int($i) ? $i + 1 : '' ?></span></b>
      <button type="button" class="btn btn-sm btn-outline-danger" data-remove-row aria-label="Remove this education record"><i class="bi bi-trash"></i> Remove</button>
    </div>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label req">Institute name</label><input class="form-control<?= is_invalid($k . 'institute_name') ?>" name="<?= $n ?>[institute_name]" value="<?= e($r['institute_name'] ?? '') ?>" maxlength="150"><?= field_error($k . 'institute_name') ?></div>
      <div class="col-md-6"><label class="form-label req">Education level</label>
        <select class="form-select<?= is_invalid($k . 'education_level') ?>" name="<?= $n ?>[education_level]"><option value="">Choose…</option>
          <?php foreach (EmployeeService::EDUCATION_LEVELS as $lvl): ?><option<?= selected($lvl, $r['education_level'] ?? '') ?>><?= e($lvl) ?></option><?php endforeach; ?>
        </select><?= field_error($k . 'education_level') ?></div>
      <div class="col-md-6"><label class="form-label">Specialization</label><input class="form-control<?= is_invalid($k . 'specialization') ?>" name="<?= $n ?>[specialization]" value="<?= e($r['specialization'] ?? '') ?>" maxlength="120"><?= field_error($k . 'specialization') ?></div>
      <div class="col-md-6"><label class="form-label">Date of completion</label><input class="form-control<?= is_invalid($k . 'completion_date') ?>" type="date" name="<?= $n ?>[completion_date]" value="<?= e($r['completion_date'] ?? '') ?>"><?= field_error($k . 'completion_date') ?></div>
    </div>
  </div>
<?php return (string) ob_get_clean();
};
?>
<input type="hidden" name="history_submitted" value="1">
<div class="card" id="experience">
  <div class="card-header">Work experience <small class="fw-normal text-muted-2">previous and other employers</small></div>
  <div class="card-body">
    <div data-history-list="experience" data-next-index="<?= count($exp) ?>">
      <?php foreach ($exp as $i => $r) echo $expRow($i, (array) $r); ?>
    </div>
    <template id="tpl-experience"><?= $expRow('__INDEX__', []) ?></template>
    <button type="button" class="btn btn-sm btn-outline-primary" data-add-row="experience"><i class="bi bi-plus-lg"></i> Add Another Work Experience</button>
    <div class="form-text">Leave a row completely blank to skip it. Tick "Currently works here" when there is no end date.</div>
  </div>
</div>
<div class="card" id="education">
  <div class="card-header">Education details</div>
  <div class="card-body">
    <div data-history-list="education" data-next-index="<?= count($edu) ?>">
      <?php foreach ($edu as $i => $r) echo $eduRow($i, (array) $r); ?>
    </div>
    <template id="tpl-education"><?= $eduRow('__INDEX__', []) ?></template>
    <button type="button" class="btn btn-sm btn-outline-primary" data-add-row="education"><i class="bi bi-plus-lg"></i> Add Another Education</button>
  </div>
</div>
