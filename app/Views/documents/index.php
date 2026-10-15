<form class="filters toolbar" method="get" action="<?= e(url('/documents')) ?>">
  <div class="flex-grow-1" style="min-width:200px"><label class="form-label" for="q">Search</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($request->str('q')) ?>" placeholder="Employee or document title"></div>
  <div><label class="form-label" for="category_id">Category</label>
    <select class="form-select form-select-sm" id="category_id" name="category_id" data-autosubmit><option value="">All</option>
      <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"<?= selected($c['id'], $request->str('category_id')) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="status">Status</label>
    <select class="form-select form-select-sm" id="status" name="status" data-autosubmit><option value="">All</option>
      <?php foreach (['expiring' => 'Expiring or expired', 'pending' => 'Pending verification', 'verified' => 'Verified', 'expired' => 'Expired', 'rejected' => 'Rejected'] as $k => $v): ?>
        <option value="<?= e($k) ?>"<?= selected($k, $request->str('status')) ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select></div>
  <button class="btn btn-sm btn-primary">Filter</button>
  <div class="ms-auto d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/documents/matrix')) ?>">Requirement matrix</a><a class="btn btn-sm btn-hivis" href="<?= e(url('/documents/upload')) ?>"><i class="bi bi-upload"></i> Upload</a></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover">
  <thead><tr><th>Employee</th><th>Category</th><th>Title</th><th>Expiry</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $d): ?>
    <tr>
      <td><span class="idc"><?= e($d['employee_code']) ?></span> <a href="<?= e(url('/employees/' . $d['employee_id'] . '?tab=documents')) ?>"><?= e(employee_name($d)) ?></a></td>
      <td><?= e($d['category_name']) ?></td><td><?= e($d['title']) ?></td><td class="num"><?= e(uk_date($d['expiry_date'])) ?></td><td><?= status_badge($d['derived']) ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/document/' . $d['id'])) ?>" target="_blank" rel="noopener">View</a>
        <?php if ($d['verification'] === 'pending'): ?>
          <form class="d-inline" method="post" action="<?= e(url('/documents/' . $d['id'] . '/verify')) ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="verified"><input type="hidden" name="back" value="list"><button class="btn btn-sm btn-outline-success">Verify</button></form>
          <form class="d-inline" method="post" action="<?= e(url('/documents/' . $d['id'] . '/verify')) ?>" data-confirm="Reject this document?"><?= csrf_field() ?><input type="hidden" name="decision" value="rejected"><input type="hidden" name="back" value="list"><button class="btn btn-sm btn-outline-danger">Reject</button></form>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/documents/' . $d['id'] . '/replace')) ?>">Replace</a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">No documents match.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?= App\Core\View::partial('pagination', ['p' => $p, 'request' => $request]) ?>
</div>
