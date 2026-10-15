<p class="text-muted-2 mb-0">Announcements from Menakan Transport HR. Each one was also emailed to you from hr@menakantransport.com.</p>
<?php if (!$rows): ?>
  <div class="card"><div class="empty"><i class="bi bi-megaphone fs-3 d-block mb-2"></i>There are no announcements for you yet.</div></div>
<?php else: ?>
<div class="card" style="max-width:820px"><div class="list-group list-group-flush">
  <?php foreach ($rows as $r): ?>
    <a class="list-group-item list-group-item-action d-flex justify-content-between gap-3" href="<?= e(url('/my/news/' . $r['id'])) ?>">
      <span class="fw-semibold"><?= e($r['title']) ?></span><span class="small text-muted-2 text-nowrap"><?= e(uk_datetime($r['received_at'])) ?></span></a>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>
