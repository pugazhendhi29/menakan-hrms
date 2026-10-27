<?php
use App\Services\NewsService;

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

    <?= App\Core\View::partial('news_audience', ['post' => $post, 'legend' => 'Send to', 'employees' => $employees, 'departmentList' => $departmentList, 'teams' => $teams,
      'note' => 'Only active employees (not archived or left) receive it. Employees without an email address in their Basic information are skipped and listed in the delivery log.']) ?>

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
