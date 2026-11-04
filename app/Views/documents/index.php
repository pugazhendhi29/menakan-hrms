<?php
/** Documents register. Expects $rows, $p, $request, $categories, $counts (per derived status, plus 'all'). */
$status = $request->str('status');
$today = uk_today();
$filterUrl = function (string $s) use ($request): string {
    $q = array_filter(['q' => $request->str('q'), 'category_id' => $request->str('category_id'), 'status' => $s], fn ($v) => $v !== '');
    return url('/documents') . ($q ? '?' . http_build_query($q) : '');
};
$tile = function (string $s, string $icon, string $label, int $value, string $note, string $tone) use ($filterUrl, $status): string {
    $tone = $value > 0 || $tone === 'ok' ? $tone : 'calm';
    return '<a class="stat stat-' . e($tone) . ($status === $s ? ' is-on' : '') . '" href="' . e($filterUrl($status === $s ? '' : $s)) . '"' . ($status === $s ? ' aria-current="true"' : '') . '>'
        . '<span class="stat-icon"><i class="bi bi-' . e($icon) . '"></i></span><span class="stat-body"><small>' . e($label) . '</small><b>' . e($value) . '</b><span class="stat-note">' . e($note) . '</span></span></a>';
};
// "in 12 days" / "3 days ago" next to the expiry date.
$relative = function (?string $date) use ($today): string {
    if (!$date) {
        return '';
    }
    $days = (int) round((strtotime($date) - strtotime($today)) / 86400);
    return match (true) {
        $days === 0 => 'today',
        $days > 0 => 'in ' . $days . ' day' . ($days === 1 ? '' : 's'),
        default => abs($days) . ' day' . ($days === -1 ? '' : 's') . ' ago',
    };
};
$labels = ['expiring' => 'Expiring or expired', 'pending' => 'Pending verification', 'verified' => 'Verified', 'expired' => 'Expired', 'rejected' => 'Rejected'];
?>
<div class="page-actions">
  <p class="text-muted-2 mb-0">Current documents on file for active employees. Click a tile to filter.</p>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/documents/matrix')) ?>"><i class="bi bi-grid-3x3-gap"></i> Requirement matrix</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/documents/bulk')) ?>"><i class="bi bi-files"></i> Bulk upload</a>
    <a class="btn btn-sm btn-hivis" href="<?= e(url('/documents/upload')) ?>"><i class="bi bi-upload"></i> Upload document</a>
  </div>
</div>

<div class="stats mb-3">
  <?= $tile('', 'files', 'All documents', $counts['all'], 'current versions', 'ok') ?>
  <?= $tile('pending', 'hourglass-split', 'Pending', $counts['pending'], 'to verify', 'warn') ?>
  <?= $tile('expiring', 'calendar-x', 'Expiring', $counts['expiring'] + $counts['expired'], '≤ ' . setting('document_expiry_warning_days', 30) . ' days or expired', 'warn') ?>
  <?= $tile('expired', 'exclamation-octagon', 'Expired', $counts['expired'], 'need replacing', 'alert') ?>
  <?= $tile('rejected', 'x-octagon', 'Rejected', $counts['rejected'], 'need a new upload', 'alert') ?>
</div>

<div class="card">
  <form class="dash-head flex-wrap toolbar" method="get" action="<?= e(url('/documents')) ?>">
    <div class="search-box flex-grow-1"><i class="bi bi-search" aria-hidden="true"></i><label class="visually-hidden" for="q">Search</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($request->str('q')) ?>" placeholder="Search employee, ID or document title"></div>
    <div><label class="visually-hidden" for="category_id">Category</label>
      <select class="form-select form-select-sm" id="category_id" name="category_id" data-autosubmit><option value="">All categories</option>
        <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"<?= selected($c['id'], $request->str('category_id')) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="visually-hidden" for="status">Status</label>
      <select class="form-select form-select-sm" id="status" name="status" data-autosubmit><option value="">All statuses</option>
        <?php foreach ($labels as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($k, $status) ?>><?= e($v) ?></option><?php endforeach; ?>
      </select></div>
    <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filter</button>
    <?php if ($request->str('q') !== '' || $request->str('category_id') !== '' || $status !== ''): ?><a class="btn btn-sm btn-link" href="<?= e(url('/documents')) ?>">Clear</a><?php endif; ?>
    <span class="dash-count ms-auto"><?= e($p['total'] ?? count($rows)) ?> shown</span>
  </form>

  <?php if ($rows): ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Employee</th><th>Document</th><th>Expiry</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $d):
        $person = ['id' => $d['employee_id'], 'photo_key' => $d['photo_key'], 'first_name' => $d['first_name'], 'last_name' => $d['last_name']];
        $rel = $relative($d['expiry_date']);
        $relCls = ['expired' => 'text-danger', 'expiring' => 'text-warning-emphasis'][$d['derived']] ?? 'text-muted-2'; ?>
      <tr>
        <td><div class="dash-who"><?= employee_avatar($person, 34) ?><div class="dash-main">
          <a href="<?= e(url('/employees/' . $d['employee_id'] . '?tab=documents')) ?>"><b><?= e(employee_name($d)) ?></b></a><small class="idc-plain"><?= e($d['employee_code']) ?></small></div></div></td>
        <td><div class="dash-main"><b><?= e($d['title']) ?></b><small><?= e($d['category_name']) ?><?= $d['document_number'] ? ' · No. ' . e($d['document_number']) : '' ?></small></div></td>
        <td class="text-nowrap"><?php if ($d['expiry_date']): ?><?= e(uk_date($d['expiry_date'])) ?><br><small class="<?= e($relCls) ?>"><?= e($rel) ?></small><?php else: ?><span class="text-muted-2">No expiry</span><?php endif; ?></td>
        <td><?= status_badge($d['derived']) ?></td>
        <td class="text-end text-nowrap">
          <?php if ($d['verification'] === 'pending'): ?>
            <form class="d-inline" method="post" action="<?= e(url('/documents/' . $d['id'] . '/verify')) ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="verified"><input type="hidden" name="back" value="list"><button class="btn btn-sm btn-outline-success" title="Verify"><i class="bi bi-check-lg"></i> Verify</button></form>
            <form class="d-inline" method="post" action="<?= e(url('/documents/' . $d['id'] . '/verify')) ?>" data-confirm="Reject this document?"><?= csrf_field() ?><input type="hidden" name="decision" value="rejected"><input type="hidden" name="back" value="list"><button class="btn btn-sm btn-outline-danger" title="Reject" aria-label="Reject"><i class="bi bi-x-lg"></i></button></form>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/document/' . $d['id'])) ?>" target="_blank" rel="noopener" title="View file" aria-label="View file"><i class="bi bi-eye"></i></a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/documents/' . $d['id'] . '/replace')) ?>" title="Replace with a new version" aria-label="Replace"><i class="bi bi-arrow-repeat"></i></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= App\Core\View::partial('pagination', ['p' => $p, 'request' => $request]) ?>
  <?php else: ?>
    <div class="dash-empty"><i class="bi bi-folder2-open"></i><span><?= $status !== '' || $request->str('q') !== '' || $request->str('category_id') !== '' ? 'No documents match these filters.' : 'No documents uploaded yet.' ?></span></div>
  <?php endif; ?>
</div>
