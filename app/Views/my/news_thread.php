<?php
use App\Services\NewsThreadService;

$hrDated = (bool) array_filter($messages, fn ($m) => (int) $m['custom_time'] === 1);
?>
<a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= e(url('/my/news')) ?>"><i class="bi bi-arrow-left"></i> All news</a>
<div class="mail-thread">
  <div class="mail-subject">
    <h2><?= e($thread['title']) ?></h2>
    <span class="mail-chip"><i class="bi bi-chat-left-text"></i><?= e(NewsThreadService::CHANNELS[$thread['channel']] ?? $thread['channel']) ?></span>
  </div>
  <?php if ($thread['summary']): ?><div class="mail-note"><i class="bi bi-text-left"></i><pre class="news-body"><?= e($thread['summary']) ?></pre></div><?php endif; ?>
  <?php if ($hrDated): ?><div class="mail-note"><i class="bi bi-info-circle"></i><span>This is a record of an earlier conversation. The date and time shown on each message is when it was originally sent, as recorded by HR.</span></div><?php endif; ?>

  <?php foreach ($messages as $m): ?>
    <?= App\Core\View::partial('news_thread_mail', ['m' => $m, 'thread' => $thread]) ?>
  <?php endforeach; ?>

  <form method="post" action="<?= e(url('/my/news/threads/' . $thread['id'] . '/reply')) ?>" class="mail-reply" id="reply" novalidate>
    <?= csrf_field() ?>
    <label class="form-label fw-semibold" for="reply_body"><i class="bi bi-reply"></i> Reply to everyone in this thread</label>
    <textarea class="form-control<?= is_invalid('body') ?>" id="reply_body" name="body" rows="4" required placeholder="Write your reply…"><?= e(old('body', '')) ?></textarea><?= field_error('body') ?>
    <div class="d-flex align-items-center gap-2 mt-2">
      <span class="small text-muted-2">Sent now, from you. Everyone in the thread is notified.</span>
      <button class="btn btn-primary ms-auto"><i class="bi bi-send"></i> Send</button>
    </div>
  </form>
</div>
