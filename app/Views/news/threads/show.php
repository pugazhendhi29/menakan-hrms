<?php
use App\Services\NewsThreadService;

$draft = $thread['status'] === 'draft';
$published = $thread['status'] === 'published';
// "Everyone in this thread" only makes sense once there is a first message.
$recipientTypes = $messages ? NewsThreadService::RECIPIENT_TYPES : array_diff_key(NewsThreadService::RECIPIENT_TYPES, ['thread' => 1]);
?>
<div class="d-flex flex-wrap gap-2 align-items-center">
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/news/threads')) ?>"><i class="bi bi-arrow-left"></i> All threads</a>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <?php if ($draft): ?>
      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/news/threads/' . $thread['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit details</a>
      <form method="post" action="<?= e(url('/news/threads/' . $thread['id'] . '/publish')) ?>" data-confirm="Publish this thread? The employees who sent or received its messages (and any extra readers) will see it in My News and be notified. Its messages can no longer be changed."><?= csrf_field() ?>
        <button class="btn btn-sm btn-primary"<?= $messages ? '' : ' disabled' ?>><i class="bi bi-send"></i> Publish</button></form>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/news/threads/' . $thread['id'] . '/delete')) ?>" data-confirm="Delete this thread and all its messages? It will disappear from everyone's News. This cannot be undone."><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete</button></form>
  </div>
</div>

<?php if ($thread['status'] === 'withdrawn'): ?>
  <div class="alert alert-secondary mb-0"><b>Withdrawn</b> on <?= e(uk_datetime($thread['withdrawn_at'])) ?><?= $thread['withdrawer'] ? ' by ' . e($thread['withdrawer']) : '' ?>: <?= e((string) $thread['withdrawn_reason']) ?>. Employees no longer see it.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="mail-thread">
      <div class="mail-subject">
        <h2><?= e($thread['title']) ?></h2>
        <?= status_badge($thread['status']) ?>
        <span class="mail-chip"><i class="bi bi-chat-left-text"></i><?= e(NewsThreadService::CHANNELS[$thread['channel']] ?? $thread['channel']) ?></span>
        <span class="mail-chip"><?= count($messages) ?> message<?= count($messages) === 1 ? '' : 's' ?></span>
      </div>
      <?php if ($thread['summary']): ?><div class="mail-note"><i class="bi bi-text-left"></i><pre class="news-body"><?= e($thread['summary']) ?></pre></div><?php endif; ?>

      <?php foreach ($messages as $m): ?>
        <?php ob_start(); ?>
          <?php if ($draft): ?>
            <a href="<?= e(url('/news/threads/' . $thread['id'] . '/messages/' . $m['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a>
            <form method="post" action="<?= e(url('/news/threads/' . $thread['id'] . '/messages/' . $m['id'] . '/delete')) ?>" data-confirm="Remove this message?"><?= csrf_field() ?><button class="btn btn-link btn-sm text-danger p-0"><i class="bi bi-trash"></i> Remove</button></form>
          <?php endif; ?>
        <?= App\Core\View::partial('news_thread_mail', ['m' => $m, 'thread' => $thread, 'actions' => trim((string) ob_get_clean())]) ?>
      <?php endforeach; ?>
      <?php if (!$messages): ?><div class="inbox"><div class="inbox-empty"><i class="bi bi-envelope-open fs-3 d-block mb-2"></i>No messages yet. Add the first one below.</div></div><?php endif; ?>

      <?php if ($draft || $published): ?>
        <h3 class="h6 mb-0 mt-2" id="add-message"><?= $published ? 'Reply' : 'Add a message' ?></h3>
        <?= App\Core\View::partial('news_thread_message', ['message' => $published ? ['recipient_type' => 'thread'] : [], 'action' => url('/news/threads/' . $thread['id'] . '/messages'),
          'submit' => $published ? 'Send reply' : 'Add message', 'employees' => $employees, 'departmentList' => $departmentList, 'recipientTypes' => $recipientTypes]) ?>
        <div class="form-text mt-0">Messages are shown in date and time order.<?= $published ? ' The people in the thread are notified of a reply.' : '' ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-4 d-flex flex-column gap-3">
    <div class="card">
      <div class="card-header">Readers</div>
      <div class="card-body small">
        <div class="mb-2"><?= e(NewsThreadService::readersLabel($thread)) ?></div>
        <?php if ($thread['status'] === 'withdrawn'): ?><div class="text-muted-2">Hidden from employees.</div>
        <?php else: ?>
          <div class="text-muted-2 mb-1"><?= count($readers) ?> active employee<?= count($readers) === 1 ? '' : 's' ?> <?= $draft ? 'will see it in My News once published' : 'can see it in My News' ?>:</div>
          <div style="max-height:320px;overflow:auto"><?php foreach ($readers as $r): ?><div><?= e(employee_name($r)) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($published): ?>
      <form method="post" action="<?= e(url('/news/threads/' . $thread['id'] . '/withdraw')) ?>" class="card" data-confirm="Withdraw this thread? Employees will no longer see it.">
        <?= csrf_field() ?>
        <div class="card-header">Withdraw</div>
        <div class="card-body"><label class="form-label req" for="reason">Reason</label>
          <input class="form-control<?= is_invalid('reason') ?>" id="reason" name="reason" maxlength="500" required value="<?= e(old('reason', '')) ?>"><?= field_error('reason') ?>
          <div class="form-text">Hides it from employees. The thread stays here for the record.</div></div>
        <div class="card-footer"><button class="btn btn-sm btn-outline-danger">Withdraw thread</button></div>
      </form>
    <?php endif; ?>
  </div>
</div>
