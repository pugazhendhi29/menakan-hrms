<?php
/**
 * Admin view of an employee's ID card: the card plus create / edit / regenerate / revoke and the card history.
 * Expects $e (employee row with job_title and department), $card (current id_cards row or null), $cardHistory.
 */
use App\Services\IdCardService;

$canManage = !$e['deleted_at'] && $e['status'] !== 'left';
$defaultExpiry = IdCardService::defaultExpiry();
$statusBadge = fn (string $s) => '<span class="badge text-bg-' . ['active' => 'success', 'suspended' => 'warning', 'replaced' => 'secondary', 'revoked' => 'danger'][$s] . '">' . e(ucfirst($s)) . '</span>';
?>
<?php if (!$card): ?>
  <div class="card" style="max-width:560px">
    <div class="card-header">ID card</div>
    <div class="card-body">
      <p class="mb-3">No ID card has been issued to <?= e(employee_name($e)) ?>. The employee will see their card in the portal once you create one.</p>
      <?php if ($canManage): ?>
      <form method="post" action="<?= e(url('/employees/' . $e['id'] . '/id-card')) ?>" class="row g-3" novalidate>
        <?= csrf_field() ?>
        <div class="col-sm-6"><label class="form-label req" for="c_issue">Issue date</label>
          <input class="form-control<?= is_invalid('issue_date') ?>" type="date" id="c_issue" name="issue_date" value="<?= e(old('issue_date', uk_today())) ?>" required><?= field_error('issue_date') ?></div>
        <div class="col-sm-6"><label class="form-label req" for="c_expiry">Expiry date</label>
          <input class="form-control<?= is_invalid('expiry_date') ?>" type="date" id="c_expiry" name="expiry_date" value="<?= e(old('expiry_date', $defaultExpiry)) ?>" required><?= field_error('expiry_date') ?></div>
        <div class="col-12"><label class="form-label" for="c_notes">Notes (admin only)</label><input class="form-control" id="c_notes" name="notes" maxlength="255" value="<?= e(old('notes')) ?>"></div>
        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-person-badge"></i> Create ID card</button></div>
      </form>
      <?php else: ?>
      <div class="alert alert-secondary mb-0">ID cards can only be created for current employees.</div>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
<div class="d-flex flex-wrap gap-4 align-items-start">
  <?= App\Core\View::render('employees/_idcard_card', ['e' => $e, 'card' => $card], null) ?>

  <div class="d-flex flex-column gap-3 no-print" style="max-width:460px;flex:1 1 360px">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">ID card <?= $statusBadge($card['status']) ?></div>
      <div class="card-body d-flex flex-column gap-2">
        <dl class="dl-grid mb-0">
          <dt>Card number</dt><dd class="idc"><?= e($card['card_number']) ?></dd>
          <dt>Full name</dt><dd><?= e(trim($e['first_name'] . ' ' . $e['last_name'])) ?></dd>
          <dt>Employee ID</dt><dd class="idc"><?= e($e['employee_code']) ?></dd>
          <dt>Designation</dt><dd><?= e($e['job_title'] ?: '—') ?></dd>
          <dt>Department</dt><dd><?= e($e['department'] ?: '—') ?></dd>
          <dt>Issue date</dt><dd><?= e(uk_date($card['issue_date'])) ?></dd>
          <dt>Expiry date</dt><dd><?= e(uk_date($card['expiry_date'])) ?> <?= $card['expiry_date'] < uk_today() ? '<span class="badge text-bg-danger">Expired</span>' : '' ?></dd>
          <?php if ($card['notes']): ?><dt>Notes</dt><dd><?= e($card['notes']) ?></dd><?php endif; ?>
        </dl>
        <?php if (!$e['photo_key']): ?><div class="alert alert-warning small mb-0">No photo yet. <a href="<?= e(url('/employees/' . $e['id'] . '?tab=personal')) ?>">Upload one on the Personal &amp; profile tab</a> to show it on the card.</div><?php endif; ?>
        <small class="text-muted-2">Name, photo, Employee ID, designation and department come from the employee record, so the card updates when you edit the employee. The employee sees this card read-only in the portal<?= $card['status'] === 'suspended' ? ' (marked SUSPENDED)' : '' ?>.</small>
        <button type="button" class="btn btn-outline-primary" data-print><i class="bi bi-printer"></i> Print / save as PDF</button>
      </div>
    </div>

    <?php if ($canManage): ?>
    <div class="card">
      <div class="card-header">Edit ID card</div>
      <div class="card-body">
        <form method="post" action="<?= e(url('/id-cards/' . $card['id'])) ?>" class="row g-3" novalidate>
          <?= csrf_field() ?>
          <div class="col-sm-6"><label class="form-label req" for="u_issue">Issue date</label>
            <input class="form-control<?= is_invalid('issue_date') ?>" type="date" id="u_issue" name="issue_date" value="<?= e(old('issue_date', $card['issue_date'])) ?>" required><?= field_error('issue_date') ?></div>
          <div class="col-sm-6"><label class="form-label req" for="u_expiry">Expiry date</label>
            <input class="form-control<?= is_invalid('expiry_date') ?>" type="date" id="u_expiry" name="expiry_date" value="<?= e(old('expiry_date', $card['expiry_date'])) ?>" required><?= field_error('expiry_date') ?></div>
          <div class="col-sm-6"><label class="form-label" for="u_status">Status</label>
            <select class="form-select" id="u_status" name="status"><?php foreach (IdCardService::STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, old('status', $card['status'])) ?>><?= $v ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="form-label" for="u_notes">Notes (admin only)</label><input class="form-control" id="u_notes" name="notes" maxlength="255" value="<?= e(old('notes', (string) $card['notes'])) ?>"></div>
          <div class="col-12 d-flex flex-wrap gap-2">
            <button class="btn btn-primary">Save changes</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#idcard-regen" aria-expanded="false"><i class="bi bi-arrow-repeat"></i> Regenerate</button>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#idcard-revoke" aria-expanded="false">Revoke</button>
          </div>
        </form>

        <div class="collapse mt-3" id="idcard-regen">
          <form method="post" action="<?= e(url('/id-cards/' . $card['id'] . '/regenerate')) ?>" class="row g-2 border-top pt-3" data-confirm="Issue a new ID card? The current card number will no longer be valid." novalidate>
            <?= csrf_field() ?>
            <p class="small text-muted-2 mb-1">Issues a new card number (<?= e(IdCardService::number($e['employee_code'], (int) App\Core\DB::value('SELECT MAX(version) FROM id_cards WHERE employee_id = ?', [$e['id']]) + 1)) ?>) with the latest employee details. The current card is archived.</p>
            <div class="col-sm-6"><label class="form-label req" for="r_issue">Issue date</label><input class="form-control" type="date" id="r_issue" name="issue_date" value="<?= e(uk_today()) ?>" required></div>
            <div class="col-sm-6"><label class="form-label req" for="r_expiry">Expiry date</label><input class="form-control" type="date" id="r_expiry" name="expiry_date" value="<?= e(max($defaultExpiry, $card['expiry_date'])) ?>" required></div>
            <div class="col-12"><label class="form-label req" for="r_reason">Reason</label><input class="form-control<?= is_invalid('reason') ?>" id="r_reason" name="reason" maxlength="255" required placeholder="e.g. Lost card, new photo, promotion"><?= field_error('reason') ?></div>
            <div class="col-12"><button class="btn btn-primary">Regenerate ID card</button></div>
          </form>
        </div>

        <div class="collapse mt-3" id="idcard-revoke">
          <form method="post" action="<?= e(url('/id-cards/' . $card['id'] . '/revoke')) ?>" class="d-flex flex-wrap gap-2 align-items-end border-top pt-3" data-confirm="Revoke this ID card? The employee will no longer see an ID card.">
            <?= csrf_field() ?>
            <div class="flex-grow-1"><label class="form-label req" for="v_reason">Reason for revoking</label><input class="form-control" id="v_reason" name="reason" maxlength="255" required placeholder="e.g. Employee on long-term leave"></div>
            <button class="btn btn-danger">Revoke card</button>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($cardHistory): ?>
<div class="card mt-3 no-print">
  <div class="card-header">Previous ID cards</div>
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Card number</th><th>Issued</th><th>Expiry</th><th>Status</th><th>Ended</th><th>Reason</th></tr></thead>
    <tbody>
    <?php foreach ($cardHistory as $h): ?>
      <tr><td class="idc"><?= e($h['card_number']) ?></td><td><?= e(uk_date($h['issue_date'])) ?></td><td><?= e(uk_date($h['expiry_date'])) ?></td>
        <td><?= $statusBadge($h['status']) ?></td><td class="text-nowrap"><?= e($h['ended_at'] ? uk_datetime($h['ended_at']) : '—') ?></td><td><?= e((string) $h['ended_reason']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
