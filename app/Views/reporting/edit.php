<?php
use App\Services\ReportCatalog;
use App\Services\ReportPeriod;

$values = old('fields', $working['fields']);
$values = is_array($values) ? $values : [];
$critical = old('critical', $working['critical']);
$amending = (int) $r['current_version'] > 0;
$sections = ['role' => [], 'standard' => []];
foreach ($tpl['fields'] as $f) {
    $sections[$f['section']][] = $f;
}
$input = function (array $f) use ($values): string {
    $k = $f['field_key'];
    $id = 'f_' . $k;
    $name = 'fields[' . $k . ']';
    $v = (string) ($values[$k] ?? '');
    $label = '<label class="form-label' . ($f['is_required'] ? ' req' : '') . '" for="' . e($id) . '">' . e($f['label']) . ($f['is_kpi'] ? '<span class="tag-kpi">KPI</span>' : '') . '</label>';
    if ($f['field_type'] === 'text') {
        return '<div class="wide">' . $label . '<textarea class="form-control' . is_invalid('fields.' . $k) . '" id="' . e($id) . '" name="' . e($name) . '" rows="3" maxlength="5000">' . e($v) . '</textarea>' . field_error('fields.' . $k) . '</div>';
    }
    $attrs = match ($f['field_type']) {
        'int' => 'inputmode="numeric" pattern="[0-9,]*"',
        'pct' => 'inputmode="decimal"',
        default => 'inputmode="decimal"',
    };
    $prefix = $f['field_type'] === 'money' ? '<span class="input-group-text">£</span>' : '';
    $suffix = $f['field_type'] === 'pct' ? '<span class="input-group-text">%</span>' : '';
    $field = '<input class="form-control' . is_invalid('fields.' . $k) . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e($v) . '" ' . $attrs . ' autocomplete="off">';
    return '<div>' . $label . ($prefix || $suffix ? '<div class="input-group">' . $prefix . $field . $suffix . '</div>' : $field) . field_error('fields.' . $k) . '</div>';
};
$calcFields = array_filter($sections['role'], fn ($f) => $f['field_type'] === 'calc');
?>
<?php if ($lastReturn): ?>
  <div class="alert alert-warning"><b><i class="bi bi-arrow-return-left"></i> Returned for correction</b> on <?= e(uk_datetime($lastReturn['created_at'])) ?>:
    <div class="mt-1" style="white-space:pre-line"><?= e($lastReturn['comment']) ?></div></div>
<?php elseif ($r['status'] === 'overdue'): ?>
  <div class="alert alert-danger"><i class="bi bi-alarm"></i> This report is <b>overdue</b> (deadline <?= e(uk_datetime($r['due_at'])) ?>). You can still complete and submit it; it is marked as late unless you enter an earlier submission date and time below.</div>
<?php endif; ?>
<?php if ($underneath && !$amending): $src = $underneath['source']; ?>
  <div class="alert <?= $underneath['missing'] ? 'alert-warning' : 'alert-info' ?> small"><i class="bi bi-layers"></i>
    Built from your <?= $src ?> reports: <b><?= (int) $underneath['submitted'] ?> of <?= (int) $underneath['expected'] ?></b> submitted for this <?= $r['period'] === 'weekly' ? 'week' : 'month' ?>.
    Totals and rates are calculated from the approved ones, and empty text sections are pre-filled from them; edit anything before you submit.
    <?php if ($underneath['missing']): ?><br>Still to send: <?= e(implode(', ', array_map(fn ($d) => ReportPeriod::shortLabel($src, $d), array_slice($underneath['missing'], 0, 10)))) ?><?= count($underneath['missing']) > 10 ? '…' : '' ?>.
      <a href="<?= e(url('/reporting/my?period=' . $src)) ?>">Open my <?= $src ?> reports</a><?php endif; ?></div>
<?php endif; ?>

<div class="card rp-section">
  <div class="card-header"><span><i class="bi bi-person-vcard"></i> Report details</span><small>Filled in automatically</small></div>
  <div class="card-body">
    <dl class="rp-dl">
      <div><dt>Report ID</dt><dd><span class="idc"><?= e($r['report_no']) ?></span></dd></div>
      <div><dt>Employee</dt><dd><?= e(employee_name($r)) ?></dd></div>
      <div><dt>Employee ID</dt><dd><?= e($r['employee_code']) ?></dd></div>
      <div><dt>Job title</dt><dd><?= e($r['job_title']) ?></dd></div>
      <div><dt>Department</dt><dd><?= e($r['department'] ?? '—') ?></dd></div>
      <div><dt>Line manager</dt><dd><?= $manager ? e(employee_name($manager)) : ($isTop ? 'None: Director' : '<span class="text-danger">Not assigned</span>') ?></dd></div>
      <div><dt>Reporting period</dt><dd><?= e(ReportPeriod::label($r['period'], $r['period_start'])) ?><?= $r['period'] !== 'daily' ? '<br><small class="text-muted-2">' . e(uk_date($r['period_start'])) . ' – ' . e(uk_date($r['period_end'])) . '</small>' : '' ?></dd></div>
      <div><dt>Deadline</dt><dd><?= e(uk_datetime($r['due_at'])) ?></dd></div>
      <div><dt>Status</dt><dd><?= report_status_badge($r['status']) ?><?= $amending ? ' <small class="text-muted-2">version ' . (int) $r['current_version'] . ' submitted</small>' : '' ?></dd></div>
    </dl>
  </div>
</div>

<form method="post" action="<?= e(url('/reporting/reports/' . $r['id'])) ?>" id="report-form" novalidate>
  <?= csrf_field() ?>

  <?php if ($calcFields): ?>
  <div class="card rp-section">
    <div class="card-header"><span><i class="bi bi-calculator"></i> Figures from your approved reports</span>
      <small><?= (int) $calc['daily']['approved'] ?> approved daily report(s)<?= $r['period'] === 'monthly' ? ', ' . (int) $calc['weekly']['approved'] . ' approved weekly report(s)' : '' ?><?= $calc['daily']['pending'] + $calc['weekly']['pending'] ? ' · ' . ($calc['daily']['pending'] + $calc['weekly']['pending']) . ' awaiting approval (not included yet)' : '' ?></small></div>
    <div class="card-body">
      <p class="small text-muted-2 mb-3">You do not need to type these again: they are worked out from what you already reported and are fixed when you submit.</p>
      <div class="rp-fields">
        <?php foreach ($calcFields as $f): ?>
          <div><span class="form-label d-block"><?= e($f['label']) ?><?= $f['is_kpi'] ? '<span class="tag-kpi">KPI</span>' : '' ?></span>
            <div class="rp-calc"><b><?= e(report_value($f['display'], $calc['values'][$f['field_key']] ?? null)) ?></b><small>Calculated</small></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card rp-section">
    <div class="card-header"><span><i class="bi bi-clipboard-data"></i> <?= e(ReportCatalog::ROLES[$r['role_code']][0]) ?>: <?= e(ReportCatalog::PERIODS[$r['period']]) ?> questions</span><small>Fields marked * are required to submit</small></div>
    <div class="card-body">
      <div class="rp-fields">
        <?php foreach ($sections['role'] as $f): if ($f['field_type'] !== 'calc') { echo $input($f); } endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card rp-section">
    <div class="card-header"><span><i class="bi bi-list-task"></i> Standard report</span><small>The same on every report</small></div>
    <div class="card-body">
      <div class="rp-fields"><?php foreach ($sections['standard'] as $f): echo $input($f); endforeach; ?></div>
      <div class="rp-critical mt-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="critical" name="critical" value="1"<?= checked($critical) ?> data-reveal="#critical-box">
          <label class="form-check-label fw-semibold" for="critical"><i class="bi bi-exclamation-octagon text-danger"></i> Raise a critical issue (your manager and the Directors are told straight away)</label>
        </div>
        <div id="critical-box" class="mt-2"<?= $critical ? '' : ' hidden' ?>>
          <label class="form-label" for="critical_note">Critical issue</label>
          <input class="form-control<?= is_invalid('critical_note') ?>" id="critical_note" name="critical_note" maxlength="500" value="<?= e(old('critical_note', $working['critical_note'])) ?>">
          <?= field_error('critical_note') ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($isManager && $team): $c = $team['counts']; ?>
  <div class="card rp-section">
    <div class="card-header"><span><i class="bi bi-diagram-3"></i> Team consolidation</span><small>Approved reports from the people who report to you are included when you submit</small></div>
    <div class="card-body pb-0">
      <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge rounded-pill text-bg-light"><?= (int) $c['team'] ?> in team</span>
        <span class="badge rounded-pill rs rs-submitted"><?= (int) $c['received'] ?> submitted</span>
        <span class="badge rounded-pill rs rs-approved"><?= (int) $c['approved'] ?> approved</span>
        <?php if ($c['awaiting']): ?><a class="badge rounded-pill rs rs-under_review text-decoration-none" href="<?= e(url('/reporting/team?period=' . $r['period'] . '&date=' . $r['period_start'])) ?>"><?= (int) $c['awaiting'] ?> awaiting your approval</a><?php endif; ?>
        <?php if ($c['returned']): ?><span class="badge rounded-pill rs rs-returned"><?= (int) $c['returned'] ?> returned</span><?php endif; ?>
        <?php if ($c['missing']): ?><span class="badge rounded-pill rs rs-overdue"><?= (int) $c['missing'] ?> not submitted</span><?php endif; ?>
        <?php if ($team['avg_rating']): ?><span class="badge rounded-pill text-bg-light">Average rating <?= e($team['avg_rating']) ?>/5</span><?php endif; ?>
      </div>
      <?php if ($c['awaiting']): ?><div class="alert alert-info py-2 small">Review the reports awaiting your approval first: only approved reports are consolidated.</div><?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Employee</th><th>Job title</th><th>Report</th><th>Status</th><th>Rating</th></tr></thead>
        <tbody>
        <?php foreach ($team['members'] as $m): ?>
          <tr>
            <td><b><?= e($m['name']) ?></b> <span class="idc"><?= e($m['code']) ?></span></td>
            <td><?= e($m['job_title'] ?? '—') ?></td>
            <td><?= $m['report'] ? '<a href="' . e(url('/reporting/reports/' . $m['report']['id'])) . '">' . e($m['report']['report_no']) . '</a>' : '—' ?><?= !empty($m['report']['has_critical']) ? ' <i class="bi bi-exclamation-octagon text-danger" title="Critical issue"></i>' : '' ?></td>
            <td><?= $m['report'] ? report_status_badge($m['report']['status']) : ($m['expected'] ? report_status_badge('missing') : '<small class="text-muted-2">Not expected</small>') ?></td>
            <td><?= !empty($m['report']['rating']) ? '<span class="stars">' . str_repeat('★', (int) $m['report']['rating']) . '</span>' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php foreach ($team['roles'] as $role): if (!array_filter($role['kpis'], fn ($k) => $k['value'] !== null)) { continue; } ?>
      <div class="card-body border-top">
        <div class="fw-semibold mb-2"><?= e($role['title']) ?> <small class="text-muted-2">· <?= (int) $role['reports'] ?> approved report(s)</small></div>
        <div class="rp-kpis" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">
          <?php foreach ($role['kpis'] as $k): if ($k['value'] === null) { continue; } ?><div class="rp-kpi"><small><?= e($k['label']) ?></small><b><?= e(report_value($k['display'], $k['value'])) ?></b></div><?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="card-body border-top">
      <label class="form-label req" for="assessment">Your management assessment</label>
      <textarea class="form-control<?= is_invalid('assessment') ?>" id="assessment" name="assessment" rows="5" maxlength="5000" placeholder="Your own view of the team's performance, the problems you found, what you have done about them and what you need from senior management."><?= e(old('assessment', $working['assessment'])) ?></textarea>
      <?= field_error('assessment') ?>
      <div class="form-text">Required: a manager's report must add the manager's own assessment, not just pass on the team's reports.</div>
      <div class="mt-3">
        <span class="form-label req d-block">Team performance rating</span>
        <?php $rating = (string) old('team_rating', (string) $working['team_rating']); ?>
        <div class="rating" role="radiogroup" aria-label="Team performance rating">
          <?php for ($i = 5; $i >= 1; $i--): ?>
            <input type="radio" id="tr<?= $i ?>" name="team_rating" value="<?= $i ?>"<?= checked($rating === (string) $i) ?>><label for="tr<?= $i ?>" title="<?= $i ?> of 5">★</label>
          <?php endfor; ?>
        </div>
        <?= field_error('team_rating') ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($canSetTime): ?>
  <div class="card rp-section">
    <div class="card-body">
      <label class="form-label" for="submitted_at">Submission date and time <small class="text-muted-2">(optional, UK time)</small></label>
      <input class="form-control<?= is_invalid('submitted_at') ?>" type="datetime-local" id="submitted_at" name="submitted_at" style="max-width:280px"
        value="<?= e(old('submitted_at', '')) ?>" min="<?= e($r['period_start']) ?>T00:00" max="<?= e(uk_now()->format('Y-m-d\TH:i')) ?>">
      <?= field_error('submitted_at') ?>
      <div class="form-text"><?= $r['due_at'] < now_utc() ? 'This report is past its deadline (' . e(uk_datetime($r['due_at'])) . '). If you sent it earlier another way, enter when; it counts as late only if that is after the deadline. ' : '' ?>Leave blank to submit now. You can choose any time from the start of the period up to now; the report shows it as its submission time.</div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($amending): ?>
  <div class="card rp-section border-warning">
    <div class="card-body">
      <label class="form-label req" for="amendment_note">What did you change, and why?</label>
      <textarea class="form-control<?= is_invalid('amendment_note') ?>" id="amendment_note" name="amendment_note" rows="2" maxlength="1000"><?= e(old('amendment_note', '')) ?></textarea>
      <?= field_error('amendment_note') ?>
      <div class="form-text">Your original submission is kept unchanged; this correction is recorded as version <?= (int) $r['current_version'] + 1 ?>.</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="rp-actions-bar">
    <span class="note"><?= $isTop ? 'As a Director, submitting completes the reporting chain.' : ($manager ? 'Submitting sends the report to ' . e(employee_name($manager)) . '. You cannot change it afterwards.' : 'You need a line manager before you can submit.') ?></span>
    <a class="btn btn-outline-secondary" href="<?= e(url('/reporting/my?period=' . $r['period'])) ?>">Back</a>
    <button class="btn btn-outline-primary" type="submit" name="intent" value="save"><i class="bi bi-save"></i> Save draft</button>
    <button class="btn btn-hivis" type="submit" name="intent" value="submit"<?= !$manager && !$isTop ? ' disabled' : '' ?> data-confirm="Submit this report? You will not be able to change it unless it is returned to you."><i class="bi bi-send"></i> Submit report</button>
  </div>
</form>

<div class="card rp-section mt-3" id="attachments">
  <div class="card-header"><span><i class="bi bi-paperclip"></i> Supporting attachments</span><small>PDF, JPG, PNG or Excel · up to 10 MB each · <?= count($attachments) ?>/<?= App\Services\ReportService::MAX_ATTACHMENTS ?></small></div>
  <?php if ($attachments): ?>
  <ul class="dash-list">
    <?php foreach ($attachments as $a): ?>
      <li><i class="bi bi-file-earmark"></i><div class="dash-main"><a href="<?= e(url('/files/report-attachment/' . $a['id'])) ?>" target="_blank" rel="noopener"><b><?= e($a['original_filename']) ?></b></a>
        <small><?= e(number_format($a['size_bytes'] / 1024)) ?> KB · <?= $a['version_no'] ? 'submitted with version ' . (int) $a['version_no'] : 'not submitted yet' ?></small></div>
        <?php if (!$a['version_no']): ?>
          <form method="post" action="<?= e(url('/reporting/reports/' . $r['id'] . '/attachments/' . $a['id'] . '/delete')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Remove this attachment?">Remove</button></form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <div class="card-body">
    <form method="post" action="<?= e(url('/reporting/reports/' . $r['id'] . '/attachments')) ?>" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end">
      <?= csrf_field() ?>
      <div class="flex-grow-1"><label class="form-label" for="file">Add a file</label><input class="form-control" type="file" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.xlsx" required></div>
      <button class="btn btn-outline-primary" type="submit"><i class="bi bi-upload"></i> Upload</button>
    </form>
    <div class="form-text">Save your draft first: uploading reloads the page.</div>
  </div>
</div>
