<?php
use App\Services\NewsService;

$editable = in_array($post['status'], ['draft', 'scheduled'], true);
$retry = in_array($post['status'], ['partially_sent', 'failed'], true);
?>
<div class="d-flex flex-wrap gap-2 align-items-center">
  <?= status_badge($post['status']) ?>
  <?php if ($post['test_mode']): ?><span class="badge text-bg-light">Test mode: mail log only</span><?php endif; ?>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <?php if ($editable): ?>
      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/news/' . $post['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a>
      <form method="post" action="<?= e(url('/news/' . $post['id'] . '/send')) ?>" data-confirm="Send this announcement now to everyone in the audience?"><?= csrf_field() ?><button class="btn btn-sm btn-primary"><i class="bi bi-send"></i> Send now</button></form>
    <?php endif; ?>
    <?php if ($post['status'] === 'scheduled'): ?>
      <form method="post" action="<?= e(url('/news/' . $post['id'] . '/unschedule')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Cancel schedule</button></form>
    <?php endif; ?>
    <?php if ($post['status'] === 'draft' && !$deliveries): ?>
      <form method="post" action="<?= e(url('/news/' . $post['id'] . '/delete')) ?>" data-confirm="Delete this draft?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Delete draft</button></form>
    <?php endif; ?>
    <?php if ($retry): ?>
      <form method="post" action="<?= e(url('/news/' . $post['id'] . '/send')) ?>" data-confirm="Try again for the recipients that were not sent?"><?= csrf_field() ?><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Retry failed</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($post['last_error']): ?><div class="alert alert-danger mb-0"><b>Last send error:</b> <?= e($post['last_error']) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Email</div>
      <div class="card-body">
        <dl class="dl-grid">
          <dt>From</dt><dd><?= e(NewsService::SENDER_NAME) ?> &lt;<?= e(NewsService::SENDER_EMAIL) ?>&gt;</dd>
          <dt>Subject</dt><dd><?= e($post['subject']) ?></dd>
          <dt>Audience</dt><dd><?= e(NewsService::audienceLabel($post)) ?></dd>
          <?php if ($post['scheduled_at']): ?><dt>Scheduled</dt><dd><?= e(uk_datetime($post['scheduled_at'])) ?></dd><?php endif; ?>
          <?php if ($post['sent_at']): ?><dt>Sent</dt><dd><?= e(uk_datetime($post['sent_at'])) ?></dd><?php endif; ?>
          <dt>Created</dt><dd><?= e(uk_datetime($post['created_at'])) ?><?= $post['author'] ? ' by ' . e($post['author']) : '' ?></dd>
        </dl>
        <pre class="news-body"><?= e(App\Services\Mailer::withSignature($post['body'])) ?></pre>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <?php if ($deliveries): ?>
        <div class="card-header">Delivery log <span class="text-muted-2 small">(<?= (int) $post['sent_count'] ?> sent, <?= (int) $post['failed_count'] ?> failed, <?= count(array_filter($deliveries, fn ($d) => $d['status'] === 'skipped')) ?> skipped)</span></div>
        <div class="table-responsive" style="max-height:520px"><table class="table table-sm mb-0">
          <thead><tr><th>Employee</th><th>Status</th><th>Time</th></tr></thead><tbody>
          <?php foreach ($deliveries as $d): ?>
            <tr><td><?= e(employee_name($d)) ?> <span class="idc text-muted-2"><?= e($d['employee_code']) ?></span><div class="small text-muted-2"><?= e((string) $d['email']) ?></div>
              <?php if ($d['error']): ?><div class="small text-danger"><?= e($d['error']) ?></div><?php endif; ?></td>
              <td><?= status_badge($d['status']) ?></td><td class="small text-nowrap"><?= e($d['sent_at'] ? uk_datetime($d['sent_at']) : ($d['attempted_at'] ? uk_datetime($d['attempted_at']) : '—')) ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div>
      <?php else: ?>
        <div class="card-header">Recipients if sent now (<?= count($preview) ?>)</div>
        <div class="table-responsive" style="max-height:520px"><table class="table table-sm mb-0"><tbody>
          <?php foreach ($preview as $r): ?><tr><td><?= e(employee_name($r)) ?> <span class="idc text-muted-2"><?= e($r['employee_code']) ?></span></td>
            <td class="small"><?= filter_var(trim((string) $r['email']), FILTER_VALIDATE_EMAIL) ? '<i class="bi bi-envelope text-success"></i>' : '<span class="text-danger">No email: will be skipped</span>' ?></td></tr><?php endforeach; ?>
          <?php if (!$preview): ?><tr><td class="empty">No active employees match this audience.</td></tr><?php endif; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
  </div>
</div>
