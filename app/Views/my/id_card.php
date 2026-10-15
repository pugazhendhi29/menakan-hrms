<?php
/** The employee's own ID card, view only: no edit, print, download or delete controls. */
?>
<?php if (!$card): ?>
  <div class="card"><div class="empty"><i class="bi bi-person-badge fs-3 d-block mb-2"></i>No ID card has been issued to you yet. If you need one, please contact HR.</div></div>
<?php else: ?>
<div class="idcard-viewonly d-flex flex-wrap gap-4 align-items-start" data-no-save>
  <?= App\Core\View::render('employees/_idcard_card', ['e' => $e, 'card' => $card], null) ?>
  <div class="card" style="max-width:420px">
    <div class="card-header">Your ID card</div>
    <div class="card-body d-flex flex-column gap-2">
      <dl class="dl-grid mb-0">
        <dt>Full name</dt><dd><?= e(trim($e['first_name'] . ' ' . $e['last_name'])) ?></dd>
        <dt>Employee ID</dt><dd class="idc"><?= e($e['employee_code']) ?></dd>
        <dt>Designation</dt><dd><?= e($e['job_title'] ?: '—') ?></dd>
        <dt>Department</dt><dd><?= e($e['department'] ?: '—') ?></dd>
        <dt>Card number</dt><dd class="idc"><?= e($card['card_number']) ?></dd>
        <dt>Expiry date</dt><dd><?= e(uk_date($card['expiry_date'])) ?></dd>
      </dl>
      <?php if ($card['status'] === 'suspended'): ?><div class="alert alert-warning small mb-0">Your ID card is currently suspended. Please contact HR.</div>
      <?php elseif ($card['expiry_date'] < uk_today()): ?><div class="alert alert-warning small mb-0">Your ID card has expired. Please contact HR for a new one.</div><?php endif; ?>
      <small class="text-muted-2"><i class="bi bi-lock"></i> View only. If any detail is wrong, please contact HR to have it corrected.</small>
    </div>
  </div>
</div>
<p class="idcard-print-blocked">Printing the ID card is not available. Please contact HR.</p>
<?php endif; ?>
