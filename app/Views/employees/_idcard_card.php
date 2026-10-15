<?php
/**
 * The ID card itself (portrait, CR80 proportions 54 x 85.6 mm). Expects $e (employee row with job_title and department)
 * and $card (id_cards row). Website and address come from Settings.
 */
$name = trim($e['first_name'] . ' ' . $e['last_name']);
$photoUrl = $e['photo_key'] ? url('/files/photo/' . $e['id']) . '?v=' . substr(md5($e['photo_key']), 0, 8) : null;
$stamp = $card['status'] === 'suspended' ? 'SUSPENDED' : ($card['expiry_date'] < uk_today() ? 'EXPIRED' : null);
?>
<div class="idcard-wrap">
  <div class="idcard" role="img" aria-label="Staff ID card for <?= e($name) ?>, <?= e($e['job_title'] ?: 'no designation') ?>, <?= e($e['department'] ?: 'no department') ?>, Employee ID <?= e($e['employee_code']) ?>, card <?= e($card['card_number']) ?>, expires <?= e(uk_date($card['expiry_date'])) ?>">
    <svg class="idcard-bg" viewBox="0 0 340 540" preserveAspectRatio="none" aria-hidden="true">
      <!-- top-left swoosh -->
      <path d="M0 0 H128 C96 22 52 50 0 120 Z" fill="#2747d6"/>
      <path d="M0 0 H82 C58 24 26 52 0 82 Z" fill="#1734b8"/>
      <path d="M0 120 C52 50 96 22 128 0 H142 C104 26 58 58 0 138 Z" fill="#8fb2ff" opacity=".55"/>
      <!-- top-right ribbon -->
      <path d="M150 0 H340 V30 C300 18 240 10 150 0 Z" fill="#2747d6"/>
      <path d="M206 0 H340 V14 C300 8 262 4 206 0 Z" fill="#1734b8"/>
      <!-- right edge wave -->
      <path d="M340 150 C318 196 318 300 340 360 Z" fill="#2747d6" opacity=".9"/>
      <path d="M340 168 C328 210 328 290 340 340 Z" fill="#8fb2ff" opacity=".6"/>
      <!-- bottom-left wave -->
      <path d="M0 440 C30 462 48 500 62 540 H0 Z" fill="#2747d6"/>
      <path d="M0 470 C20 486 32 512 40 540 H0 Z" fill="#1734b8"/>
      <path d="M0 430 C36 454 56 498 72 540 H62 C48 500 30 462 0 440 Z" fill="#8fb2ff" opacity=".55"/>
      <!-- bottom-right corner -->
      <path d="M340 488 C324 510 312 526 302 540 H340 Z" fill="#2747d6"/>
    </svg>

    <div class="idcard-logo">
      <img class="idcard-mark" src="<?= e(asset('images/logo-mark.png')) ?>" alt="" draggable="false">
      <span>MENAKAN TRANSPORT</span>
    </div>

    <div class="idcard-photo">
      <?php if ($photoUrl): ?>
        <img src="<?= e($photoUrl) ?>" alt="" draggable="false">
      <?php else: ?>
        <span class="idcard-initials"><?= e(mb_strtoupper(mb_substr($e['first_name'], 0, 1) . mb_substr($e['last_name'], 0, 1))) ?></span>
      <?php endif; ?>
    </div>

    <div class="idcard-name"><?= e(mb_strtoupper($name)) ?></div>
    <div class="idcard-role"><?= e($e['job_title'] ?: '—') ?></div>

    <dl class="idcard-fields">
      <dt>ID No</dt><dd>: <?= e($e['employee_code']) ?></dd>
      <dt>Department</dt><dd>: <?= e($e['department'] ?: '—') ?></dd>
      <dt>Expire Date</dt><dd>: <?= e(uk_date($card['expiry_date'])) ?></dd>
    </dl>

    <div class="idcard-footer">
      <div class="idcard-cardno">Card <?= e($card['card_number']) ?></div>
      <div><?= e((string) setting('company_website', '')) ?></div>
      <div><?= e((string) setting('company_address', '')) ?></div>
    </div>
    <?php if ($stamp): ?><div class="idcard-stamp"><?= $stamp ?></div><?php endif; ?>
  </div>
</div>
