<?php

use App\Core\Session;

$flashes = Session::takeFlashes();
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'Sign in') . ' · Menakan Transport HR') ?></title>
<link rel="preload" href="<?= e(asset('fonts/comfortaa-latin-var.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="icon" href="<?= e(url('/favicon.ico')) ?>" sizes="any">
<link rel="icon" href="<?= e(asset('images/favicon-32.png')) ?>" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="<?= e(asset('images/apple-touch-icon.png')) ?>">
</head>
<body>
<div class="login">
  <div class="l">
    <div class="brand"><span class="mk"><img src="<?= e(asset('images/logo-mark.png')) ?>" alt="" width="36" height="36"></span><div><b>Menakan</b><span>Transport Ltd</span></div></div>
    <div>
      <h2>People, shifts and pay in one place.</h2>
      <p>Drivers, depot teams and office staff sign in here to see their rotas, request leave and download payslips.</p>
      <div class="login-points"><span><i class="bi bi-calendar3-week"></i> Rotas</span><span><i class="bi bi-sun"></i> Leave</span><span><i class="bi bi-cash-stack"></i> Payslips</span><span><i class="bi bi-megaphone"></i> News</span></div>
    </div>
    <div class="road" aria-hidden="true"></div>
  </div>
  <div class="r">
    <div class="form-box">
      <img class="login-logo" src="<?= e(asset('images/logo.png')) ?>" alt="Menakan Transport" width="300" height="60">
      <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" role="alert"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
</div>
<script src="<?= e(asset('vendor/lucide/icons.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
