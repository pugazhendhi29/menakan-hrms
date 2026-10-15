<a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= e(url('/my/news')) ?>"><i class="bi bi-arrow-left"></i> All news</a>
<div class="card" style="max-width:820px">
  <div class="card-header"><div class="fw-semibold"><?= e($row['title']) ?></div>
    <div class="small text-muted-2">From Menakan Transport HR Department · <?= e(uk_datetime($row['sent_at'])) ?></div></div>
  <div class="card-body">
    <div class="small text-muted-2 mb-2">Subject: <?= e($subject) ?></div>
    <pre class="news-body"><?= e(App\Services\Mailer::withSignature($body)) ?></pre>
  </div>
</div>
