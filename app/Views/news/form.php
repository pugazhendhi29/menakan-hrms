<?php
use App\Services\NewsService;

$audience = (string) old('audience', $post['audience']);
$oldIds = old('audience_ids', null);
$chosen = fn (string $kind) => array_map('intval', is_array($oldIds) ? (array) ($oldIds[$kind] ?? []) : ($post['audience'] === $kind ? NewsService::ids($post['audience_ids']) : []));
$action = $id ? url('/news/' . $id) : url('/news');
$scheduledLocal = !empty($post['scheduled_at']) ? from_utc($post['scheduled_at'])->format('Y-m-d\TH:i') : '';
?>
<?php if (!$id && $templates): ?>
<form class="toolbar" method="get" action="<?= e(url('/news/create')) ?>">
  <div><label class="form-label" for="template">Start from a template</label>
    <select class="form-select form-select-sm" id="template" name="template" data-autosubmit><option value="">Blank announcement</option>
      <?php foreach ($templates as $t): ?><option value="<?= e($t['id']) ?>"<?= selected($t['id'], $post['template_id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
    </select></div>
</form>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="card" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="template_id" value="<?= e((string) $post['template_id']) ?>">
  <div class="card-body row g-3">
    <div class="col-12"><div class="alert alert-light border small mb-0"><i class="bi bi-envelope-at"></i> From: <b><?= e(NewsService::SENDER_NAME) ?> &lt;<?= e(NewsService::SENDER_EMAIL) ?>&gt;</b> (fixed). Each employee gets their own email; no one sees the other recipients.
      <?php if ($testMode): ?><br><b>Email is in test mode:</b> messages are written to <code>storage/logs/mail.log</code> and not delivered.<?php endif; ?></div></div>

    <div class="col-md-6"><label class="form-label req" for="title">Title (shown in the HRMS)</label>
      <input class="form-control<?= is_invalid('title') ?>" id="title" name="title" maxlength="200" required value="<?= e(old('title', $post['title'])) ?>"><?= field_error('title') ?></div>
    <div class="col-md-6"><label class="form-label req" for="subject">Email subject</label>
      <input class="form-control<?= is_invalid('subject') ?>" id="subject" name="subject" maxlength="200" required value="<?= e(old('subject', $post['subject'])) ?>"><?= field_error('subject') ?></div>
    <div class="col-12"><label class="form-label req" for="body">Message</label>
      <textarea class="form-control<?= is_invalid('body') ?>" id="body" name="body" rows="12" required><?= e(old('body', $post['body'])) ?></textarea><?= field_error('body') ?>
      <div class="form-text">Plain text. Personalise with <?= e(implode(' ', NewsService::PLACEHOLDERS)) ?>. The HR signature is added automatically at the end.</div></div>

    <fieldset class="col-12"><legend class="form-label req">Send to</legend>
      <?php foreach (NewsService::AUDIENCES as $k => $v): ?>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="audience" id="aud_<?= $k ?>" value="<?= $k ?>"<?= $audience === $k ? ' checked' : '' ?> data-audience><label class="form-check-label" for="aud_<?= $k ?>"><?= e($v) ?></label></div>
      <?php endforeach; ?>
      <?= field_error('audience') ?>
      <div class="mt-2" data-audience-panel="employees"<?= $audience === 'employees' ? '' : ' hidden' ?>>
        <label class="form-label" for="ids_employees">Employees</label>
        <select class="form-select<?= is_invalid('audience_ids') ?>" id="ids_employees" name="audience_ids[employees][]" multiple size="8">
          <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"<?= in_array((int) $emp['id'], $chosen('employees'), true) ? ' selected' : '' ?>><?= e(employee_name($emp) . ' (' . $emp['employee_code'] . ')') ?></option><?php endforeach; ?>
        </select><div class="form-text">Hold Ctrl (or Cmd) to choose several.</div></div>
      <div class="mt-2" data-audience-panel="departments"<?= $audience === 'departments' ? '' : ' hidden' ?>>
        <label class="form-label" for="ids_departments">Departments</label>
        <select class="form-select<?= is_invalid('audience_ids') ?>" id="ids_departments" name="audience_ids[departments][]" multiple size="6">
          <?php foreach ($departmentList as $d): ?><option value="<?= e($d['id']) ?>"<?= in_array((int) $d['id'], $chosen('departments'), true) ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mt-2" data-audience-panel="teams"<?= $audience === 'teams' ? '' : ' hidden' ?>>
        <label class="form-label" for="ids_teams">Teams</label>
        <select class="form-select<?= is_invalid('audience_ids') ?>" id="ids_teams" name="audience_ids[teams][]" multiple size="6">
          <?php foreach ($teams as $t): ?><option value="<?= e($t['id']) ?>"<?= in_array((int) $t['id'], $chosen('teams'), true) ? ' selected' : '' ?>><?= e(employee_name($t) . "'s team (" . ((int) $t['members'] + 1) . ' people)') ?></option><?php endforeach; ?>
        </select><div class="form-text">A team is a reporting manager and the employees who report to them (from the organisation chart).<?= $teams ? '' : ' No teams yet: set reporting managers on employee records.' ?></div></div>
      <?= field_error('audience_ids') ?>
      <div class="form-text">Only active employees (not archived or left) receive it. Employees without an email address in their Basic information are skipped and listed in the delivery log.</div>
    </fieldset>

    <div class="col-md-4"><label class="form-label" for="scheduled_at">Schedule for (UK time)</label>
      <input class="form-control<?= is_invalid('scheduled_at') ?>" type="datetime-local" id="scheduled_at" name="scheduled_at" value="<?= e(old('scheduled_at', $scheduledLocal)) ?>"><?= field_error('scheduled_at') ?>
      <div class="form-text">Only needed for "Schedule".</div></div>
  </div>
  <div class="card-footer d-flex flex-wrap gap-2">
    <button class="btn btn-outline-primary" name="intent" value="draft"><i class="bi bi-save"></i> Save draft</button>
    <button class="btn btn-outline-primary" name="intent" value="schedule"><i class="bi bi-clock"></i> Schedule</button>
    <button class="btn btn-primary" name="intent" value="send" data-confirm-click="Send this announcement now to everyone in the chosen audience?"><i class="bi bi-send"></i> Send now</button>
    <a class="btn btn-link" href="<?= e(url($id ? '/news/' . $id : '/news')) ?>">Cancel</a>
  </div>
</form>
