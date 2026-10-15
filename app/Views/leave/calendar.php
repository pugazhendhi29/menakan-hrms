<?php
$days = [];
for ($d = $first; $d <= $last; $d = date('Y-m-d', strtotime("$d +1 day"))) {
    $days[] = $d;
}
?>
<div class="d-flex align-items-center gap-2">
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/leave/calendar?date=' . date('Y-m-d', strtotime("$first -1 month")))) ?>">‹</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/leave/calendar?date=' . date('Y-m-d', strtotime("$first +1 month")))) ?>">›</a>
  <h2 class="h4 mb-0"><?= e(date('F Y', strtotime($first))) ?></h2>
  <span class="ms-auto small text-muted-2"><span class="badge text-bg-success">approved</span> <span class="badge text-bg-info">pending</span></span>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-bordered mb-0" style="font-size:.75rem">
  <thead><tr><th>Employee</th><?php foreach ($days as $d): $dow = (int) date('N', strtotime($d)); ?><th class="text-center<?= $dow >= 6 ? ' text-muted-2' : '' ?>" title="<?= e($holidays[$d] ?? '') ?>"><?= e(date('j', strtotime($d))) ?><br><?= e(substr(date('D', strtotime($d)), 0, 2)) ?><?= isset($holidays[$d]) ? '<br>BH' : '' ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="text-nowrap"><a href="<?= e(url('/leave/' . $r['id'])) ?>"><?= e(employee_name($r)) ?></a><br><small class="text-muted-2"><?= e($r['type_name']) ?></small></td>
    <?php foreach ($days as $d): $on = $d >= $r['start_date'] && $d <= $r['end_date']; ?>
      <td class="<?= $on ? ($r['status'] === 'approved' ? 'bg-success-subtle' : 'bg-info-subtle') : '' ?>"></td>
    <?php endforeach; ?></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="<?= count($days) + 1 ?>" class="empty">Nobody is off this month.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
