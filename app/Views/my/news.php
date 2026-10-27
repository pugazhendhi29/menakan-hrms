<?php use App\Services\NewsThreadService; ?>
<div class="d-flex flex-wrap align-items-center gap-2" style="max-width:900px">
  <h2 class="h5 mb-0">Threads</h2>
  <a class="btn btn-sm btn-hivis ms-auto" href="<?= e(url('/my/news/threads/create')) ?>"><i class="bi bi-pencil-square"></i> New thread</a>
</div>
<div class="inbox" style="max-width:900px">
  <?php foreach ($threads as $t):
      $who = $t['last_sender'] ? NewsThreadService::person($t['last_sender'])['name'] : '';
      $av = NewsThreadService::avatar($who); ?>
    <a class="inbox-row" href="<?= e(url('/my/news/threads/' . $t['id'])) ?>">
      <?php if ($t['last_sender_type'] === 'department'): ?><span class="mail-avatar" style="background:#0f1f33" aria-hidden="true"><i class="bi bi-building"></i></span>
      <?php else: ?><span class="mail-avatar" style="background:<?= e($av['color']) ?>" aria-hidden="true"><?= e($av['initials']) ?></span><?php endif; ?>
      <span class="inbox-main">
        <span class="inbox-top"><span class="inbox-who"><?= e($who) ?></span><span class="inbox-title"><?= e($t['title']) ?></span></span>
        <span class="inbox-snippet d-block"><?= e(preg_replace('/\s+/', ' ', (string) $t['last_body'])) ?></span>
      </span>
      <span class="inbox-side"><span><?= $t['last_message_at'] ? e(from_utc($t['last_message_at'])->format('j M Y, H:i')) : '' ?></span>
        <?php if ((int) $t['message_count'] > 1): ?><span class="mail-chip"><?= (int) $t['message_count'] ?></span><?php endif; ?></span>
    </a>
  <?php endforeach; ?>
  <?php if (!$threads): ?><div class="inbox-empty"><i class="bi bi-chat-left-text fs-3 d-block mb-2"></i>No threads yet. <a href="<?= e(url('/my/news/threads/create')) ?>">Start one</a> with a colleague or a department.</div><?php endif; ?>
</div>

<h2 class="h5 mb-0 mt-2">Announcements</h2>
<p class="text-muted-2 small mb-0">From Menakan Transport HR. Each one was also emailed to you from hr@menakantransport.com.</p>
<?php if (!$rows): ?>
  <div class="inbox" style="max-width:900px"><div class="inbox-empty"><i class="bi bi-megaphone fs-3 d-block mb-2"></i>There are no announcements for you yet.</div></div>
<?php else: ?>
<div class="card" style="max-width:900px"><div class="list-group list-group-flush">
  <?php foreach ($rows as $r): ?>
    <a class="list-group-item list-group-item-action d-flex justify-content-between gap-3" href="<?= e(url('/my/news/' . $r['id'])) ?>">
      <span class="fw-semibold"><?= e($r['title']) ?></span><span class="small text-muted-2 text-nowrap"><?= e(uk_datetime($r['received_at'])) ?></span></a>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>
