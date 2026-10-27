<?php
/** New email: from / to / date and time / subject / message, published on Send. The sender's signature is added automatically. */
use App\Services\NewsThreadService;

$senderType = (string) old('sender_type', 'employee');
$oldSender = old('sender_id', []);
$senderId = fn (string $type) => (int) (is_array($oldSender) ? ($oldSender[$type] ?? 0) : 0);
$recipientType = (string) old('recipient_type', 'employee');
$oldRecipients = old('recipient_ids', []);
$recipients = fn (string $type) => array_map('intval', (array) (is_array($oldRecipients) ? ($oldRecipients[$type] ?? []) : []));
$name = fn (array $emp) => employee_name($emp) . (($emp['status'] ?? '') === 'left' ? ' · left' : '');
?>
<a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= e(url('/news/threads')) ?>"><i class="bi bi-arrow-left"></i> All threads</a>
<form method="post" action="<?= e(url('/news/threads')) ?>" class="compose" novalidate>
  <?= csrf_field() ?>
  <div class="compose-head"><i class="bi bi-envelope-paper"></i> New email</div>

  <div class="compose-row">
    <label class="compose-label" for="sender_employee">From</label>
    <div class="compose-field">
      <div class="d-flex flex-wrap gap-3 mb-1">
        <?php foreach (NewsThreadService::SENDER_TYPES as $k => $v): ?>
          <div class="form-check form-check-inline m-0"><input class="form-check-input" type="radio" name="sender_type" id="st_<?= $k ?>" value="<?= $k ?>"<?= $senderType === $k ? ' checked' : '' ?> data-switch="sender"><label class="form-check-label small" for="st_<?= $k ?>"><?= e($v) ?></label></div>
        <?php endforeach; ?>
      </div>
      <div data-switch-panel="sender:employee"<?= $senderType === 'employee' ? '' : ' hidden' ?>>
        <select class="form-select<?= is_invalid('sender_id') ?>" id="sender_employee" name="sender_id[employee]" data-signature-from><option value="" data-signature="">Choose an employee…</option>
          <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>" data-signature="<?= e(trim(employee_name($emp) . "\n" . (string) $emp['job_title'])) ?>"<?= selected($emp['id'], $senderId('employee')) ?>><?= e($name($emp)) ?></option><?php endforeach; ?>
        </select></div>
      <div data-switch-panel="sender:department"<?= $senderType === 'department' ? '' : ' hidden' ?>>
        <label class="visually-hidden" for="sender_department">Sending department</label>
        <select class="form-select<?= is_invalid('sender_id') ?>" id="sender_department" name="sender_id[department]" data-signature-from><option value="" data-signature="">Choose a department…</option>
          <?php foreach ($departmentList as $d): ?><option value="<?= e($d['id']) ?>" data-signature="<?= e($d['name']) ?>"<?= selected($d['id'], $senderId('department')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?= field_error('sender_type') ?><?= field_error('sender_id') ?>
    </div>
  </div>

  <div class="compose-row">
    <label class="compose-label" for="recipient_employee">To</label>
    <div class="compose-field">
      <div class="d-flex flex-wrap gap-3 mb-1">
        <?php foreach (['employee' => 'Employees', 'department' => 'Departments', 'all' => 'Everyone'] as $k => $v): ?>
          <div class="form-check form-check-inline m-0"><input class="form-check-input" type="radio" name="recipient_type" id="rt_<?= $k ?>" value="<?= $k ?>"<?= $recipientType === $k ? ' checked' : '' ?> data-switch="recipient"><label class="form-check-label small" for="rt_<?= $k ?>"><?= e($v) ?></label></div>
        <?php endforeach; ?>
      </div>
      <div data-switch-panel="recipient:employee"<?= $recipientType === 'employee' ? '' : ' hidden' ?>>
        <select class="form-select<?= is_invalid('recipient_ids') ?>" id="recipient_employee" name="recipient_ids[employee][]" multiple size="5">
          <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"<?= in_array((int) $emp['id'], $recipients('employee'), true) ? ' selected' : '' ?>><?= e($name($emp)) ?></option><?php endforeach; ?>
        </select></div>
      <div data-switch-panel="recipient:department"<?= $recipientType === 'department' ? '' : ' hidden' ?>>
        <label class="visually-hidden" for="recipient_department">Receiving departments</label>
        <select class="form-select<?= is_invalid('recipient_ids') ?>" id="recipient_department" name="recipient_ids[department][]" multiple size="5">
          <?php foreach ($departmentList as $d): ?><option value="<?= e($d['id']) ?>"<?= in_array((int) $d['id'], $recipients('department'), true) ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="small text-muted-2" data-switch-panel="recipient:all"<?= $recipientType === 'all' ? '' : ' hidden' ?>>All active employees.</div>
      <?= field_error('recipient_type') ?><?= field_error('recipient_ids') ?>
      <div class="small text-muted-2 mt-1">Hold Ctrl (or Cmd) to choose several.</div>
    </div>
  </div>

  <div class="compose-row">
    <label class="compose-label" for="occurred_at">Date</label>
    <div class="compose-field" style="max-width:260px">
      <input class="form-control<?= is_invalid('occurred_at') ?>" type="datetime-local" id="occurred_at" name="occurred_at" max="<?= e(uk_now()->format('Y-m-d\TH:i')) ?>" value="<?= e(old('occurred_at', uk_now()->format('Y-m-d\TH:i'))) ?>"><?= field_error('occurred_at') ?>
    </div>
  </div>

  <div class="compose-row">
    <label class="compose-label" for="subject">Subject</label>
    <div class="compose-field">
      <input class="form-control compose-subject<?= is_invalid('subject') ?>" id="subject" name="subject" maxlength="200" required value="<?= e(old('subject', '')) ?>"><?= field_error('subject') ?>
    </div>
  </div>

  <div class="compose-body">
    <label class="visually-hidden" for="body">Message</label>
    <textarea class="form-control<?= is_invalid('body') ?>" id="body" name="body" rows="12" required placeholder="Write your email…"><?= e(old('body', '')) ?></textarea><?= field_error('body') ?>
    <div class="compose-signature" aria-live="polite">
      <div class="small text-muted-2 mb-1">Signature, added automatically:</div>
      <pre class="news-body" id="signature-preview" data-empty="Choose who the email is from.">Choose who the email is from.</pre>
    </div>
  </div>

  <div class="compose-foot">
    <button class="btn btn-primary"><i class="bi bi-send"></i> Publish</button>
    <span class="small text-muted-2">Published straight away: the sender and receivers see it in My News and are notified.</span>
  </div>
</form>
