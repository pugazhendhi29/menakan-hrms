<?php use App\Services\NewsThreadService; ?>
<form class="filters toolbar" method="get" action="<?= e(url('/news/threads')) ?>">
  <div><label class="form-label" for="status">Status</label>
    <select class="form-select form-select-sm" id="status" name="status" data-autosubmit><option value="">All</option>
      <?php foreach (['draft' => 'Draft', 'published' => 'Published', 'withdrawn' => 'Withdrawn'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $status) ?>><?= $v ?></option><?php endforeach; ?>
    </select></div>
  <div class="ms-auto d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/news')) ?>"><i class="bi bi-megaphone"></i> Announcements</a>
    <a class="btn btn-sm btn-hivis" href="<?= e(url('/news/threads/create')) ?>"><i class="bi bi-pencil-square"></i> New thread</a>
  </div>
</form>

<div class="inbox" style="max-width:1100px">
  <?php foreach ($rows as $r):
      $who = $r['last_sender'] ? NewsThreadService::person($r['last_sender'])['name'] : '—';
      $av = NewsThreadService::avatar($who); ?>
    <div class="inbox-row has-actions">
      <a class="inbox-link" href="<?= e(url('/news/threads/' . $r['id'])) ?>" aria-label="<?= e($r['title']) ?>"></a>
      <?php if ($r['last_sender_type'] === 'department'): ?><span class="mail-avatar" style="background:#0f1f33" aria-hidden="true"><i class="bi bi-building"></i></span>
      <?php else: ?><span class="mail-avatar" style="background:<?= e($av['color']) ?>" aria-hidden="true"><?= e($r['last_sender'] ? $av['initials'] : '?') ?></span><?php endif; ?>
      <span class="inbox-main">
        <span class="inbox-top"><span class="inbox-who"><?= e($who) ?></span><span class="inbox-title"><?= e($r['title']) ?></span></span>
        <span class="inbox-snippet d-block"><?= $r['last_body'] !== null ? e(preg_replace('/\s+/', ' ', $r['last_body'])) : 'No messages yet' ?></span>
        <span class="small text-muted-2 d-block"><?= e(NewsThreadService::CHANNELS[$r['channel']] ?? $r['channel']) ?> · <?= (int) $r['message_count'] ?> message<?= (int) $r['message_count'] === 1 ? '' : 's' ?> · <?= e(NewsThreadService::readersLabel($r)) ?></span>
      </span>
      <span class="inbox-side"><span><?= $r['last_at'] ? e(from_utc($r['last_at'])->format('j M Y, H:i')) : '' ?></span><?= status_badge($r['status']) ?></span>
      <form class="inbox-action" method="post" action="<?= e(url('/news/threads/' . $r['id'] . '/delete')) ?>" data-confirm="Delete &quot;<?= e($r['title']) ?>&quot; and all its messages? It will disappear from everyone's News. This cannot be undone."><?= csrf_field() ?>
        <button class="btn btn-sm btn-outline-danger" title="Delete thread" aria-label="Delete <?= e($r['title']) ?>"><i class="bi bi-trash"></i></button></form>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="inbox-empty"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No threads yet. <a href="<?= e(url('/news/threads/create')) ?>">Start the first one</a>.</div><?php endif; ?>
  <?= App\Core\View::partial('pagination', ['p' => $p, 'request' => $request]) ?>
</div>
