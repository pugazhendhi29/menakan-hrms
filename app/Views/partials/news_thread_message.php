<?php
/** Sender / receivers / date and time / text of one thread message. Needs: $message, $action, $submit, $employees, $departmentList; optional $cancel, $recipientTypes. */
use App\Services\NewsService;
use App\Services\NewsThreadService;

$senderType = (string) old('sender_type', $message['sender_type'] ?? 'department');
$oldSender = old('sender_id', null);
$senderId = fn (string $type) => (int) (is_array($oldSender) ? ($oldSender[$type] ?? 0) : (($message['sender_type'] ?? '') === $type ? ($message['sender_id'] ?? 0) : 0));
$recipientType = (string) old('recipient_type', $message['recipient_type'] ?? 'department');
$oldRecipients = old('recipient_ids', null);
$recipients = fn (string $type) => array_map('intval', is_array($oldRecipients) ? (array) ($oldRecipients[$type] ?? [])
    : (($message['recipient_type'] ?? '') === $type ? NewsService::ids($message['recipient_ids'] ?? '') : []));
$whenLocal = !empty($message['occurred_at']) ? from_utc($message['occurred_at'])->format('Y-m-d\TH:i') : '';
$label = fn (array $emp) => employee_name($emp) . ' (' . $emp['employee_code'] . ')' . (($emp['status'] ?? '') === 'left' ? ' · left' : '');
?>
<form method="post" action="<?= e($action) ?>" class="card" novalidate>
  <?= csrf_field() ?>
  <div class="card-body row g-3">
    <fieldset class="col-md-6"><legend class="form-label req">From</legend>
      <?php foreach (NewsThreadService::SENDER_TYPES as $k => $v): ?>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="sender_type" id="st_<?= $k ?>" value="<?= $k ?>"<?= $senderType === $k ? ' checked' : '' ?> data-switch="sender"><label class="form-check-label" for="st_<?= $k ?>"><?= e($v) ?></label></div>
      <?php endforeach; ?>
      <?= field_error('sender_type') ?>
      <div class="mt-2" data-switch-panel="sender:department"<?= $senderType === 'department' ? '' : ' hidden' ?>>
        <label class="visually-hidden" for="sender_department">Sending department</label>
        <select class="form-select<?= is_invalid('sender_id') ?>" id="sender_department" name="sender_id[department]"><option value="">Choose a department…</option>
          <?php foreach ($departmentList as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $senderId('department')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mt-2" data-switch-panel="sender:employee"<?= $senderType === 'employee' ? '' : ' hidden' ?>>
        <label class="visually-hidden" for="sender_employee">Sending employee</label>
        <select class="form-select<?= is_invalid('sender_id') ?>" id="sender_employee" name="sender_id[employee]"><option value="">Choose an employee…</option>
          <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"<?= selected($emp['id'], $senderId('employee')) ?>><?= e($label($emp)) ?></option><?php endforeach; ?>
        </select></div>
      <?= field_error('sender_id') ?>
    </fieldset>

    <fieldset class="col-md-6"><legend class="form-label req">To</legend>
      <?php foreach ($recipientTypes ?? NewsThreadService::RECIPIENT_TYPES as $k => $v): ?>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="recipient_type" id="rt_<?= $k ?>" value="<?= $k ?>"<?= $recipientType === $k ? ' checked' : '' ?> data-switch="recipient"><label class="form-check-label" for="rt_<?= $k ?>"><?= e($v) ?></label></div>
      <?php endforeach; ?>
      <?= field_error('recipient_type') ?>
      <div class="mt-2" data-switch-panel="recipient:department"<?= $recipientType === 'department' ? '' : ' hidden' ?>>
        <label class="visually-hidden" for="recipient_department">Receiving departments</label>
        <select class="form-select<?= is_invalid('recipient_ids') ?>" id="recipient_department" name="recipient_ids[department][]" multiple size="5">
          <?php foreach ($departmentList as $d): ?><option value="<?= e($d['id']) ?>"<?= in_array((int) $d['id'], $recipients('department'), true) ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="mt-2" data-switch-panel="recipient:employee"<?= $recipientType === 'employee' ? '' : ' hidden' ?>>
        <label class="visually-hidden" for="recipient_employee">Receiving employees</label>
        <select class="form-select<?= is_invalid('recipient_ids') ?>" id="recipient_employee" name="recipient_ids[employee][]" multiple size="6">
          <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"<?= in_array((int) $emp['id'], $recipients('employee'), true) ? ' selected' : '' ?>><?= e($label($emp)) ?></option><?php endforeach; ?>
        </select></div>
      <?= field_error('recipient_ids') ?>
      <div class="form-text" data-switch-panel="recipient:all"<?= $recipientType === 'all' ? '' : ' hidden' ?>>Sent to the whole company.</div>
      <div class="form-text" data-switch-panel="recipient:thread"<?= $recipientType === 'thread' ? '' : ' hidden' ?>>A reply to everyone who sent or received a message in this thread.</div>
      <div class="form-text">Hold Ctrl (or Cmd) to choose several.</div>
    </fieldset>

    <div class="col-md-4"><label class="form-label req" for="occurred_at">Date and time sent (UK time)</label>
      <input class="form-control<?= is_invalid('occurred_at') ?>" type="datetime-local" id="occurred_at" name="occurred_at" max="<?= e(uk_now()->format('Y-m-d\TH:i')) ?>" value="<?= e(old('occurred_at', $whenLocal)) ?>"><?= field_error('occurred_at') ?>
      <div class="form-text">When the original message was sent. Today or earlier.</div></div>
    <div class="col-md-8"><label class="form-label" for="msg_subject">Subject</label>
      <input class="form-control<?= is_invalid('subject') ?>" id="msg_subject" name="subject" maxlength="200" value="<?= e(old('subject', (string) ($message['subject'] ?? ''))) ?>"><?= field_error('subject') ?>
      <div class="form-text">Optional. The thread title is shown when left empty.</div></div>
    <div class="col-12"><label class="form-label req" for="msg_body">Message</label>
      <textarea class="form-control<?= is_invalid('body') ?>" id="msg_body" name="body" rows="6" required><?= e(old('body', $message['body'] ?? '')) ?></textarea><?= field_error('body') ?>
      <div class="form-text">Plain text, as it was written.</div></div>
  </div>
  <div class="card-footer d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= e($submit) ?></button>
    <?php if (!empty($cancel)): ?><a class="btn btn-link" href="<?= e($cancel) ?>">Cancel</a><?php endif; ?>
  </div>
</form>
