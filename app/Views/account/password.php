<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">Change password</div>
      <div class="card-body">
        <?php if ($user['must_change_password']): ?>
          <div class="alert alert-info">You are using a temporary password. Choose a new one to continue.</div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/account/password')) ?>" novalidate class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <div>
            <label class="form-label" for="current_password">Current password</label>
            <input class="form-control<?= is_invalid('current_password') ?>" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            <?= field_error('current_password') ?>
          </div>
          <div>
            <label class="form-label" for="password">New password</label>
            <input class="form-control<?= is_invalid('password') ?>" id="password" name="password" type="password" autocomplete="new-password" required>
            <div class="form-text">At least 10 characters, with letters and numbers.</div>
            <?= field_error('password') ?>
          </div>
          <div>
            <label class="form-label" for="password_confirmation">Confirm new password</label>
            <input class="form-control<?= is_invalid('password_confirmation') ?>" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            <?= field_error('password_confirmation') ?>
          </div>
          <div><button class="btn btn-primary" type="submit">Change password</button></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">Account</div>
      <div class="card-body">
        <dl class="dl-grid">
          <dt>Username</dt><dd><?= e($user['username']) ?></dd>
          <dt>Email</dt><dd><?= e($user['email'] ?: '—') ?></dd>
          <dt>Role</dt><dd><?= e(ucfirst($user['role'])) ?></dd>
          <dt>Last sign-in</dt><dd><?= e(uk_datetime($user['last_login_at'])) ?></dd>
        </dl>
      </div>
    </div>
  </div>
</div>
