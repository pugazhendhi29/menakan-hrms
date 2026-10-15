<form class="filters toolbar" method="get" action="<?= e(url('/users')) ?>">
  <div class="flex-grow-1" style="min-width:200px"><label class="form-label" for="q">Search</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($request->str('q')) ?>" placeholder="Username, email, name, employee ID"></div>
  <div><label class="form-label" for="role">Role</label><select class="form-select form-select-sm" id="role" name="role" data-autosubmit><option value="">All</option><option value="admin"<?= selected('admin', $request->str('role')) ?>>Admin</option><option value="employee"<?= selected('employee', $request->str('role')) ?>>Employee</option></select></div>
  <div><label class="form-label" for="active">Status</label><select class="form-select form-select-sm" id="active" name="active" data-autosubmit><option value="">All</option><option value="1"<?= selected('1', $request->str('active')) ?>>Active</option><option value="0"<?= selected('0', $request->str('active')) ?>>Inactive</option></select></div>
  <button class="btn btn-sm btn-primary">Filter</button>
  <div class="ms-auto"><a class="btn btn-sm btn-hivis" href="<?= e(url('/users/create')) ?>">+ New user</a></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover">
  <thead><tr><th>Username</th><th>Linked employee</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th class="num">Live sessions</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $u): ?>
    <tr>
      <td class="fw-semibold"><?= e($u['username']) ?></td>
      <td><?= $u['employee_code'] ? '<span class="idc">' . e($u['employee_code']) . '</span> ' . e(employee_name($u)) : '<span class="text-muted-2">—</span>' ?></td>
      <td><?= e($u['email'] ?: '—') ?></td>
      <td><span class="badge text-bg-<?= $u['role'] === 'admin' ? 'dark' : 'light border' ?>"><?= e(ucfirst($u['role'])) ?></span></td>
      <td><?= $u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?><?= $u['password_hash'] ? '' : ' <span class="badge text-bg-warning">Not activated</span>' ?></td>
      <td class="text-nowrap"><?= e(uk_datetime($u['last_login_at'])) ?></td><td class="num"><?= e($u['live_sessions']) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/users/' . $u['id'] . '/edit')) ?>">Manage</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
