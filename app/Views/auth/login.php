<form method="post" action="<?= e(url('/login')) ?>" novalidate class="d-flex flex-column gap-3">
  <?= csrf_field() ?>
  <h1 class="h2 mb-0">Sign in</h1>
  <div>
    <label class="form-label" for="username">Username or email</label>
    <input class="form-control form-control-lg" id="username" name="username" autocomplete="username" required autofocus value="<?= e(old('username')) ?>">
  </div>
  <div>
    <label class="form-label" for="password">Password</label>
    <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required>
  </div>
  <button class="btn btn-primary btn-lg" type="submit">Sign in</button>
  <small class="text-muted-2">Forgot your password? Ask HR to send a reset link. After 5 failed attempts, sign-in is paused for 15 minutes.</small>
</form>
