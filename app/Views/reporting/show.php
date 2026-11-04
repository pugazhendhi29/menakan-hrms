<?php
use App\Services\ReportCatalog;
use App\Services\ReportExport;
use App\Services\ReportPeriod;
use App\Services\ReportService;

$values = $version ? $version['data'] : $live['data'];
$calc = $version ? ($version['data']['calculated'] ?? []) : $live['calc']['values'];
$prevValues = $previous['data'] ?? null;
$meta = $version['data']['meta'] ?? [];
$team = $version['consolidation'] ?? null;
$answer = function (array $f) use ($values, $calc, $prevValues): string {
    $isCalc = $f['field_type'] === 'calc';
    $v = $isCalc ? ($calc[$f['field_key']] ?? null) : ($values['fields'][$f['field_key']] ?? null);
    $shown = $f['display'] === 'text' ? (($v ?? '') === '' ? '—' : e($v)) : '<span class="num">' . e(report_value($f['display'], $v)) . '</span>';
    $changed = '';
    if ($prevValues !== null) {
        $pv = $isCalc ? ($prevValues['calculated'][$f['field_key']] ?? null) : ($prevValues['fields'][$f['field_key']] ?? null);
        if ((string) $pv !== (string) $v) {
            $changed = '<span class="was">Before: ' . e($f['display'] === 'text' ? (($pv ?? '') === '' ? '(blank)' : mb_strimwidth((string) $pv, 0, 200, '…')) : report_value($f['display'], $pv)) . '</span>';
        }
    }
    return '<div' . ($changed ? ' class="changed"' : '') . '><dt>' . e($f['label']) . ($isCalc ? '<span class="tag-calc">Calculated</span>' : '') . ($f['is_kpi'] ? '<span class="tag-kpi">KPI</span>' : '') . '</dt><dd>' . $shown . $changed . '</dd></div>';
};
$timeline = [];
foreach ($versions as $v) {
    $timeline[] = ['at' => $v['submitted_at'], 'class' => 'submitted', 'title' => $v['version_no'] > 1 ? 'Amended and resubmitted (version ' . $v['version_no'] . ')' : 'Submitted (original, version 1)',
        'by' => $v['username'], 'text' => $v['amendment_note'], 'rating' => null];
}
foreach ($reviews as $rv) {
    $timeline[] = ['at' => $rv['created_at'], 'class' => $rv['action'], 'title' => ReportExport::reviewLabel($rv['action']),
        'by' => $rv['first_name'] ? employee_name($rv) : ($rv['username'] ?? 'Scheduler'), 'text' => $rv['comment'], 'rating' => $rv['rating']];
}
usort($timeline, fn ($a, $b) => strcmp($a['at'], $b['at']));
$sections = ['role' => [], 'standard' => []];
foreach ($tpl['fields'] as $f) {
    $sections[$f['section']][] = $f;
}
?>
<div class="rp-bar">
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <?= report_status_badge($r['status']) ?>
    <?php if ($r['is_late']): ?><span class="badge rounded-pill rs rs-overdue">Submitted late</span><?php endif; ?>
    <?php if ($r['has_critical'] && (int) $r['current_version'] > 0): ?><span class="badge rounded-pill rs rs-escalated"><i class="bi bi-exclamation-octagon"></i> Critical issue</span><?php endif; ?>
    <?php if ($r['status'] === 'escalated'): ?><span class="small text-muted-2">with <?= e(trim($r['x_first_name'] . ' ' . $r['x_last_name'])) ?></span><?php endif; ?>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($canEdit): ?><a class="btn btn-hivis btn-sm" href="<?= e(url('/reporting/reports/' . $r['id'] . '/edit')) ?>"><i class="bi bi-pencil-square"></i> <?= $r['status'] === 'returned' ? 'Correct and resubmit' : 'Continue report' ?></a><?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/reporting/reports/' . $r['id'] . '/pdf')) ?>"><i class="bi bi-filetype-pdf"></i> PDF</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/reporting/reports/' . $r['id'] . '/xlsx')) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card rp-section">
      <div class="card-header"><span><i class="bi bi-person-vcard"></i> Report details</span>
        <?php if (count($versions) > 1): ?>
          <form method="get" class="d-flex gap-2 align-items-center"><label class="small text-muted-2" for="version">Version</label>
            <select class="form-select form-select-sm" id="version" name="version" data-autosubmit>
              <?php foreach ($versions as $v): ?><option value="<?= (int) $v['version_no'] ?>"<?= selected($v['version_no'], $version['version_no'] ?? 0) ?>><?= (int) $v['version_no'] ?><?= $v['version_no'] == 1 ? ' (original)' : ' (amendment)' ?> · <?= e(uk_datetime($v['submitted_at'])) ?></option><?php endforeach; ?>
            </select></form>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <dl class="rp-dl">
          <div><dt>Report ID</dt><dd><span class="idc"><?= e($r['report_no']) ?></span></dd></div>
          <div><dt>Employee</dt><dd><?= e($meta['employee'] ?? employee_name($r)) ?></dd></div>
          <div><dt>Employee ID</dt><dd><?= e($meta['employee_code'] ?? $r['employee_code']) ?></dd></div>
          <div><dt>Job title</dt><dd><?= e($meta['job_title'] ?? $r['job_title']) ?></dd></div>
          <div><dt>Department</dt><dd><?= e($meta['department'] ?? $r['department'] ?? '—') ?></dd></div>
          <div><dt>Line manager</dt><dd><?= e($meta['line_manager'] ?? (trim($r['m_first_name'] . ' ' . $r['m_last_name']) ?: '—')) ?></dd></div>
          <div><dt>Reporting period</dt><dd><?= e(ReportPeriod::label($r['period'], $r['period_start'])) ?><?= $r['period'] !== 'daily' ? '<br><small class="text-muted-2">' . e(uk_date($r['period_start'])) . ' – ' . e(uk_date($r['period_end'])) . '</small>' : '' ?></dd></div>
          <div><dt>Submitted</dt><dd><?= e($version ? uk_datetime($version['submitted_at']) : 'Not yet') ?></dd></div>
          <div><dt>Deadline</dt><dd><?= e(uk_datetime($r['due_at'])) ?></dd></div>
        </dl>
        <?php if ($version && (int) $version['version_no'] > 1): ?>
          <div class="alert alert-warning mt-3 mb-0 py-2 small"><b>Amendment (version <?= (int) $version['version_no'] ?>):</b> <?= e($version['amendment_note']) ?> · changed answers are highlighted with the previous value.</div>
        <?php elseif (!$version): ?>
          <div class="alert alert-secondary mt-3 mb-0 py-2 small">This report has not been submitted yet. You are seeing the current draft.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card rp-section">
      <div class="card-header"><span><i class="bi bi-clipboard-data"></i> <?= e(ReportCatalog::ROLES[$r['role_code']][0]) ?>: <?= e(ReportCatalog::PERIODS[$r['period']]) ?> questions</span>
        <?php if ($version && $r['period'] !== 'daily' && !empty($version['data']['calculated_sources'])): $src = $version['data']['calculated_sources']; ?>
          <small>Calculated from <?= (int) $src['daily']['approved'] ?> approved daily<?= $r['period'] === 'monthly' ? ' and ' . (int) $src['weekly']['approved'] . ' weekly' : '' ?> report(s)</small>
        <?php endif; ?></div>
      <dl class="rp-answers"><?php foreach ($sections['role'] as $f): echo $answer($f); endforeach; ?></dl>
    </div>

    <div class="card rp-section">
      <div class="card-header"><span><i class="bi bi-list-task"></i> Standard report</span></div>
      <dl class="rp-answers"><?php foreach ($sections['standard'] as $f): echo $answer($f); endforeach; ?></dl>
      <?php if (!empty($values['critical'])): ?>
        <div class="card-body pt-0"><div class="rp-critical"><b class="text-danger"><i class="bi bi-exclamation-octagon"></i> Critical issue</b><div style="white-space:pre-line"><?= e($values['critical_note']) ?></div></div></div>
      <?php endif; ?>
    </div>

    <?php if (!empty($values['assessment']) || $team || $children): ?>
    <div class="card rp-section">
      <div class="card-header"><span><i class="bi bi-diagram-3"></i> Management assessment and team consolidation</span>
        <?php if (!empty($values['team_rating'])): ?><span class="stars" title="Team performance rating"><?= str_repeat('★', (int) $values['team_rating']) ?><span class="text-muted-2"><?= str_repeat('★', 5 - (int) $values['team_rating']) ?></span></span><?php endif; ?></div>
      <?php if (!empty($values['assessment'])): ?><div class="card-body" style="white-space:pre-line"><?= e($values['assessment']) ?></div><?php endif; ?>
      <?php if ($team): $c = $team['counts']; ?>
        <div class="card-body border-top pb-2 small text-muted-2">At submission: <?= (int) $c['team'] ?> in team · <?= (int) $c['expected'] ?> expected · <?= (int) $c['received'] ?> submitted · <?= (int) $c['approved'] ?> approved · <?= (int) $c['awaiting'] ?> awaiting approval · <?= (int) $c['missing'] ?> not submitted<?= $team['avg_rating'] ? ' · average rating ' . e($team['avg_rating']) . '/5' : '' ?></div>
        <?php foreach ($team['roles'] as $role): if (!array_filter($role['kpis'], fn ($k) => $k['value'] !== null)) { continue; } ?>
          <div class="card-body pt-2"><div class="fw-semibold mb-2"><?= e($role['title']) ?> <small class="text-muted-2">· <?= (int) $role['reports'] ?> approved report(s)</small></div>
            <div class="rp-kpis" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))"><?php foreach ($role['kpis'] as $k): if ($k['value'] === null) { continue; } ?><div class="rp-kpi"><small><?= e($k['label']) ?></small><b><?= e(report_value($k['display'], $k['value'])) ?></b></div><?php endforeach; ?></div></div>
        <?php endforeach; ?>
        <?php foreach ($team['critical'] as $x): ?><div class="card-body pt-0"><div class="rp-critical small"><b><?= e($x['name']) ?>:</b> <?= e($x['note']) ?></div></div><?php endforeach; ?>
      <?php endif; ?>
      <?php if ($children): ?>
        <div class="table-responsive border-top">
          <table class="table align-middle">
            <thead><tr><th>Consolidated report</th><th>Employee</th><th>Job title</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($children as $ch): ?>
              <tr><td><a href="<?= e(url('/reporting/reports/' . $ch['id'])) ?>"><?= e($ch['report_no']) ?></a></td><td><?= e(employee_name($ch)) ?></td><td><?= e($ch['job_title']) ?></td><td><?= report_status_badge($ch['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($parents): ?>
      <div class="alert alert-light border small"><i class="bi bi-arrow-up-circle"></i> Consolidated into
        <?php foreach ($parents as $p): ?><a href="<?= e(url('/reporting/reports/' . $p['id'])) ?>"><?= e($p['report_no']) ?></a> (<?= e(employee_name($p)) ?>, <?= e(ReportService::STATUSES[$p['status']]) ?>) <?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($attachments): ?>
    <div class="card rp-section">
      <div class="card-header"><span><i class="bi bi-paperclip"></i> Supporting attachments</span></div>
      <ul class="dash-list">
        <?php foreach ($attachments as $a): ?>
          <li><i class="bi bi-file-earmark"></i><div class="dash-main"><a href="<?= e(url('/files/report-attachment/' . $a['id'])) ?>" target="_blank" rel="noopener"><b><?= e($a['original_filename']) ?></b></a>
            <small><?= e(number_format($a['size_bytes'] / 1024)) ?> KB · <?= $a['version_no'] ? 'version ' . (int) $a['version_no'] : 'draft' ?></small></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-xl-4">
    <?php if ($canReview): ?>
    <div class="card rp-section rp-review" id="review">
      <div class="card-header"><span><i class="bi bi-clipboard-check"></i> Your review</span></div>
      <div class="card-body">
        <form method="post" action="<?= e(url('/reporting/reports/' . $r['id'] . '/review')) ?>">
          <?= csrf_field() ?>
          <label class="form-label req" for="comment">Management comments</label>
          <textarea class="form-control<?= is_invalid('comment') ?>" id="comment" name="comment" rows="5" maxlength="5000" required placeholder="Your assessment, what needs correcting, or why you are escalating."><?= e(old('comment')) ?></textarea>
          <?= field_error('comment') ?>
          <div class="mt-3"><span class="form-label d-block">Performance rating <small class="text-muted-2">(on approval)</small></span>
            <div class="rating" role="radiogroup" aria-label="Performance rating">
              <?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" id="rt<?= $i ?>" name="rating" value="<?= $i ?>"><label for="rt<?= $i ?>" title="<?= $i ?> of 5">★</label><?php endfor; ?>
            </div></div>
          <div class="d-grid gap-2 mt-3">
            <button class="btn btn-success" type="submit" name="decision" value="approved"><i class="bi bi-patch-check"></i> Approve</button>
            <button class="btn btn-outline-warning" type="submit" name="decision" value="returned"><i class="bi bi-arrow-return-left"></i> Return for correction</button>
            <?php if ($canEscalate): ?><button class="btn btn-outline-danger" type="submit" name="decision" value="escalated" data-confirm="Escalate this report to your line manager for a decision?"><i class="bi bi-arrow-up-circle"></i> Escalate to my manager</button><?php endif; ?>
          </div>
          <p class="small text-muted-2 mt-2 mb-0">You cannot approve your own reports. Overdue reports are never approved automatically.</p>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card rp-section">
      <div class="card-header"><span><i class="bi bi-clock-history"></i> Approval history</span></div>
      <?php if ($timeline): ?>
        <ol class="rp-timeline">
          <?php foreach ($timeline as $t): ?>
            <li class="t-<?= e($t['class']) ?>"><b><?= e($t['title']) ?></b><?= $t['rating'] ? ' <span class="stars">' . str_repeat('★', (int) $t['rating']) . '</span>' : '' ?><br>
              <small><?= e(uk_datetime($t['at'])) ?> · <?= e($t['by'] ?? '') ?></small>
              <?php if ($t['text']): ?><blockquote><?= e($t['text']) ?></blockquote><?php endif; ?></li>
          <?php endforeach; ?>
        </ol>
      <?php else: ?><div class="dash-empty"><i class="bi bi-hourglass"></i><span>Nothing yet.</span></div><?php endif; ?>
    </div>

    <div class="card rp-section" id="actions">
      <div class="card-header"><span><i class="bi bi-list-check"></i> Management actions</span><span class="dash-count"><?= count($actions) ?></span></div>
      <?php if ($actions): ?>
        <ul class="dash-list">
          <?php foreach ($actions as $a): ?>
            <li><div class="dash-main"><b><?= e($a['title']) ?></b><small><?= e(employee_name($a)) ?> · <?= e(ucfirst($a['priority'])) ?><?= $a['due_date'] ? ' · due ' . e(uk_date($a['due_date'])) : '' ?></small></div><?= status_badge($a['status'] === 'in_progress' ? 'pending' : ($a['status'] === 'done' ? 'approved' : ($a['status'] === 'cancelled' ? 'cancelled' : 'scheduled'))) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($canRaise): ?>
        <div class="card-body border-top">
          <form method="post" action="<?= e(url('/reporting/reports/' . $r['id'] . '/actions')) ?>" class="d-grid gap-2">
            <?= csrf_field() ?>
            <div><label class="form-label req" for="a_title">New action</label><input class="form-control<?= is_invalid('title') ?>" id="a_title" name="title" maxlength="200" value="<?= e(old('title')) ?>" required><?= field_error('title') ?></div>
            <div><label class="form-label" for="a_details">Details</label><textarea class="form-control" id="a_details" name="details" rows="2"><?= e(old('details')) ?></textarea></div>
            <div class="row g-2">
              <div class="col-12"><label class="form-label" for="a_to">Assigned to</label><select class="form-select<?= is_invalid('assigned_to') ?>" id="a_to" name="assigned_to"><?php foreach ($assignees as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($id, old('assigned_to', $r['employee_id'])) ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
              <div class="col-6"><label class="form-label" for="a_pri">Priority</label><select class="form-select" id="a_pri" name="priority"><?php foreach (['low', 'medium', 'high', 'critical'] as $p): ?><option value="<?= $p ?>"<?= selected($p, old('priority', 'medium')) ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select></div>
              <div class="col-6"><label class="form-label" for="a_due">Due</label><input class="form-control" type="date" id="a_due" name="due_date" value="<?= e(old('due_date')) ?>"></div>
            </div>
            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-plus-lg"></i> Add action</button>
          </form>
        </div>
      <?php elseif (!$actions): ?><div class="dash-empty"><i class="bi bi-check2-circle"></i><span>No actions.</span></div><?php endif; ?>
    </div>

    <div class="card rp-section">
      <details>
        <summary class="card-header"><span><i class="bi bi-shield-check"></i> Audit trail</span><span class="dash-count"><?= count($audit) ?></span></summary>
        <ul class="dash-list small">
          <?php foreach ($audit as $a): ?><li><div class="dash-main"><span><?= e($a['summary']) ?></span><small><?= e(uk_datetime($a['created_at'])) ?> · <?= e($a['username'] ?? 'System') ?></small></div></li><?php endforeach; ?>
        </ul>
      </details>
    </div>
  </div>
</div>
