<?php $action = $u ? url('/users/' . $u['id']) : url('/users'); ?>
<div class="row g-3">
  <div class="col-xl-6">
    <div class="card"><div class="card-header"><?= $u ? 'Account: ' . e($u['username']) : 'New account' ?></div><div class="card-body">
      <form method="post" action="<?= e($action) ?>" class="row g-3" novalidate>
        <?= csrf_field() ?>
        <div class="col-sm-6"><label class="form-label req" for="username">Username</label><input class="form-control<?= is_invalid('username') ?>" id="username" name="username" value="<?= e(old('username', $u['username'] ?? '')) ?>" required><?= field_error('username') ?></div>
        <div class="col-sm-6"><label class="form-label" for="email">Email</label><input class="form-control<?= is_invalid('email') ?>" type="email" id="email" name="email" value="<?= e(old('email', $u['email'] ?? '')) ?>"><?= field_error('email') ?></div>
        <div class="col-sm-6"><label class="form-label req" for="role">Role</label>
          <select class="form-select<?= is_invalid('role') ?>" id="role" name="role">
            <option value="employee"<?= selected('employee', old('role', $u['role'] ?? 'employee')) ?>>Employee (self-service only)</option>
            <option value="admin"<?= selected('admin', old('role', $u['role'] ?? '')) ?>>Admin (full access)</option>
          </select><?= field_error('role') ?></div>
        <div class="col-sm-6"><label class="form-label" for="employee_id">Linked employee</label>
          <select class="form-select<?= is_invalid('employee_id') ?>" id="employee_id" name="employee_id"><option value="">None (admin-only account)</option>
            <?php foreach ($employees as $e): ?><option value="<?= e($e['id']) ?>"<?= selected($e['id'], old('employee_id', $u['employee_id'] ?? '')) ?>><?= e($e['employee_code'] . ' ' . employee_name($e)) ?></option><?php endforeach; ?>
          </select><?= field_error('employee_id') ?></div>
        <?php if (!$u): ?>
        <div class="col-12"><label class="form-label" for="password">Temporary password <small class="text-muted-2">(optional)</small></label><input class="form-control<?= is_invalid('password') ?>" type="password" id="password" name="password" autocomplete="new-password"><?= field_error('password') ?>
          <div class="form-text">Leave blank to generate a one-time activation link instead (recommended). A temporary password must be changed at first sign-in.</div></div>
        <?php endif; ?>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $u ? 'Save' : 'Create user' ?></button><a class="btn btn-outline-secondary" href="<?= e(url('/users')) ?>">Back</a></div>
      </form>
    </div></div>
  </div>
  <?php if ($u): ?>
  <div class="col-xl-6">
    <div class="card mb-3"><div class="card-header">Status <?= $u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></div><div class="card-body d-flex flex-wrap gap-2">
      <form method="post" action="<?= e(url('/users/' . $u['id'] . '/toggle')) ?>" data-confirm="<?= $u['is_active'] ? 'Deactivate this account and sign it out everywhere?' : 'Activate this account?' ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-<?= $u['is_active'] ? 'danger' : 'success' ?>"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
      <form method="post" action="<?= e(url('/users/' . $u['id'] . '/reset-link')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><?= $u['password_hash'] ? 'Send password reset link' : 'Send activation link' ?></button></form>
      <form method="post" action="<?= e(url('/users/' . $u['id'] . '/revoke-sessions')) ?>" data-confirm="Sign this user out of every device?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Sign out all sessions</button></form>
    </div></div>
    <div class="card mb-3"><div class="card-header">Sessions</div><div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>Started</th><th>Last seen</th><th>IP</th><th>State</th></tr></thead><tbody>
      <?php foreach ($sessions as $s): ?><tr><td><?= e(uk_datetime($s['created_at'])) ?></td><td><?= e(uk_datetime($s['last_seen_at'])) ?></td><td><?= e($s['ip']) ?></td><td><?= $s['revoked_at'] ? '<span class="badge text-bg-secondary">Ended</span>' : '<span class="badge text-bg-success">Live</span>' ?></td></tr><?php endforeach; ?>
      <?php if (!$sessions): ?><tr><td colspan="4" class="empty">No sessions.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="card"><div class="card-header">Recent sign-in attempts</div><div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>When</th><th>IP</th><th>Result</th></tr></thead><tbody>
      <?php foreach ($logins as $l): ?><tr><td><?= e(uk_datetime($l['attempted_at'])) ?></td><td><?= e($l['ip']) ?></td><td><?= $l['success'] ? '<span class="badge text-bg-success">Success</span>' : '<span class="badge text-bg-danger">Failed</span>' ?></td></tr><?php endforeach; ?>
      <?php if (!$logins): ?><tr><td colspan="3" class="empty">No attempts recorded.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  </div>
  <?php endif; ?>
</div>
