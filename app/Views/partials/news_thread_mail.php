<?php
/** One thread message as an email: avatar, sender, "to" line (opens the full from / to / date / subject), date, text. Needs: $m, $thread; optional $actions (HTML). */
use App\Services\NewsThreadService;

$from = NewsThreadService::person($m['sender_label']);
$avatar = NewsThreadService::avatar($from['name']);
$when = from_utc($m['occurred_at']);
$subject = $m['subject'] ?: $thread['title'];
?>
<article class="mail-msg">
  <div class="mail-msg-head">
    <?php if ($m['sender_type'] === 'department'): ?>
      <div class="mail-avatar" style="background:#0f1f33" aria-hidden="true"><i class="bi bi-building"></i></div>
    <?php else: ?>
      <div class="mail-avatar" style="background:<?= e($avatar['color']) ?>" aria-hidden="true"><?= e($avatar['initials']) ?></div>
    <?php endif; ?>
    <div class="mail-meta">
      <div class="mail-from"><b><?= e($from['name']) ?></b><?php if ($from['email']): ?><span class="mail-addr">&lt;<?= e($from['email']) ?>&gt;</span><?php endif; ?></div>
      <details class="mail-to">
        <summary><span>to <?= e(NewsThreadService::names($m['recipient_label'])) ?></span><i class="bi bi-chevron-down"></i></summary>
        <table class="mail-head">
          <tr><th scope="row">from:</th><td><?= e($m['sender_label']) ?></td></tr>
          <tr><th scope="row">to:</th><td><?= e($m['recipient_label']) ?></td></tr>
          <tr><th scope="row">date:</th><td><?= e($when->format('j M Y, H:i')) ?></td></tr>
          <tr><th scope="row">subject:</th><td><?= e($subject) ?></td></tr>
        </table>
      </details>
    </div>
    <time class="mail-date" datetime="<?= e($when->format(DATE_ATOM)) ?>"><?= e($when->format('j M Y, H:i')) ?></time>
  </div>
  <?php if ($m['subject'] && $m['subject'] !== $thread['title']): ?><div class="mail-body pb-0 fw-semibold"><?= e($m['subject']) ?></div><?php endif; ?>
  <div class="mail-body"><pre class="news-body"><?= e($m['body']) ?></pre></div>
  <?php if (!empty($actions)): ?><div class="mail-actions"><?= $actions ?></div><?php endif; ?>
</article>
