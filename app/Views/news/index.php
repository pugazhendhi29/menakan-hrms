<?php use App\Services\NewsService; ?>
<div class="card">
  <div class="card-body d-flex flex-wrap gap-3 align-items-center">
    <div class="flex-grow-1">
      <div class="fw-semibold"><i class="bi bi-envelope-at"></i> Sender: <?= e(NewsService::SENDER_NAME) ?> &lt;<?= e(NewsService::SENDER_EMAIL) ?>&gt;</div>
      <div class="small text-muted-2">Fixed for every News email; it cannot be changed here. Delivery uses the SMTP server set in the server's .env file. Each employee receives their own copy, so recipients never see each other's addresses.</div>
      <div class="small mt-1">
        <?php if ($testMode): ?><span class="badge text-bg-warning">Test mode</span> Email is not configured for delivery: messages are written to the mail log only.
        <?php elseif (!$check): ?><span class="badge text-bg-secondary">Not checked</span> The sender has not been checked yet. It is also checked automatically before every send.
        <?php elseif ($check['ok']): ?><span class="badge text-bg-success">Sender authorised</span> <?= e($check['message']) ?> <span class="text-muted-2">(checked <?= e(uk_datetime($check['checked_at'])) ?>)</span>
        <?php else: ?><span class="badge text-bg-danger">Sender check failed</span> <?= e($check['message']) ?> <span class="text-muted-2">(<?= e(uk_datetime($check['checked_at'])) ?>)</span><?php endif; ?>
      </div>
    </div>
    <form method="post" action="<?= e(url('/news/sender-check')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-shield-check"></i> Check sender now</button></form>
  </div>
</div>

<form class="filters toolbar" method="get" action="<?= e(url('/news')) ?>">
  <div><label class="form-label" for="status">Status</label>
    <select class="form-select form-select-sm" id="status" name="status" data-autosubmit><option value="">All</option>
      <?php foreach (['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sent' => 'Sent', 'partially_sent' => 'Partially sent', 'failed' => 'Failed'] as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, $status) ?>><?= $v ?></option><?php endforeach; ?>
    </select></div>
  <div class="ms-auto d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/news/templates')) ?>"><i class="bi bi-file-earmark-text"></i> Templates</a>
    <a class="btn btn-sm btn-hivis" href="<?= e(url('/news/create')) ?>"><i class="bi bi-plus-lg"></i> New announcement</a>
  </div>
</form>

<div class="card"><div class="table-responsive"><table class="table table-hover">
  <thead><tr><th>Title</th><th>Audience</th><th>Status</th><th>When</th><th class="text-end">Delivered</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a class="fw-semibold" href="<?= e(url('/news/' . $r['id'])) ?>"><?= e($r['title']) ?></a><div class="small text-muted-2"><?= e($r['subject']) ?></div></td>
      <td class="small"><?= e(NewsService::audienceLabel($r)) ?></td>
      <td><?= status_badge($r['status']) ?><?= $r['test_mode'] ? ' <span class="badge text-bg-light" title="Written to the mail log, not delivered">test</span>' : '' ?></td>
      <td class="small text-nowrap"><?php if ($r['status'] === 'scheduled'): ?>Scheduled <?= e(uk_datetime($r['scheduled_at'])) ?>
        <?php elseif ($r['sent_at']): ?>Sent <?= e(uk_datetime($r['sent_at'])) ?><?php else: ?>Created <?= e(uk_datetime($r['created_at'])) ?><?php endif; ?></td>
      <td class="text-end small"><?= $r['recipients_total'] ? e($r['sent_count'] . ' / ' . $r['recipients_total']) . ($r['failed_count'] ? ' <span class="text-danger">(' . (int) $r['failed_count'] . ' failed)</span>' : '') : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="empty">No announcements yet. <a href="<?= e(url('/news/create')) ?>">Write the first one</a>.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?= App\Core\View::partial('pagination', ['p' => $p, 'request' => $request]) ?>
</div>
