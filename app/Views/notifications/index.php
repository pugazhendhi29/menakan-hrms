<div class="card">
  <div class="card-header">Your notifications
    <form method="post" action="<?= e(url('/notifications/read-all')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" type="submit">Mark all as read</button></form>
  </div>
  <div class="list-group list-group-flush">
    <?php foreach ($rows as $n): ?>
      <a class="list-group-item list-group-item-action d-flex justify-content-between gap-3<?= $n['read_at'] ? '' : ' fw-semibold' ?>" href="<?= e(url('/notifications/' . $n['id'] . '/open')) ?>">
        <span><?= $n['read_at'] ? '' : '<span class="badge text-bg-warning me-2">New</span>' ?><?= e($n['title']) ?><?php if ($n['body']): ?><br><small class="text-muted-2 fw-normal"><?= e($n['body']) ?></small><?php endif; ?></span>
        <small class="text-muted-2 text-nowrap"><?= e(uk_datetime($n['created_at'])) ?></small>
      </a>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="empty">You have no notifications.</div><?php endif; ?>
  </div>
</div>
