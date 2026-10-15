<form method="post" action="<?= e(url('/password/reset/' . $token)) ?>" novalidate class="d-flex flex-column gap-3">
  <?= csrf_field() ?>
  <h1 class="h2 mb-0"><?= e($title) ?></h1>
  <p class="mb-0 text-muted-2">Account: <b><?= e($row['username']) ?></b>. Choose a password of at least 10 characters with letters and numbers.</p>
  <div>
    <label class="form-label" for="password">New password</label>
    <input class="form-control<?= is_invalid('password') ?>" id="password" name="password" type="password" autocomplete="new-password" required>
    <?= field_error('password') ?>
  </div>
  <div>
    <label class="form-label" for="password_confirmation">Confirm new password</label>
    <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
  </div>
  <button class="btn btn-primary btn-lg" type="submit">Save password</button>
</form>
