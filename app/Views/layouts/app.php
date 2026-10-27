<?php

use App\Core\Auth;
use App\Core\Session;
use App\Services\Notifier;

$user = Auth::user();
$path = $GLOBALS['__request_path'] ?? '/';
$isAdmin = Auth::isAdmin();
$unread = $user && $isAdmin ? Notifier::unreadCount((int) $user['id']) : 0;
$flashes = Session::takeFlashes();
$nav = function (string $href, string $label, string $icon, bool $exact = false) use ($path): string {
    $on = $exact ? $path === $href : ($path === $href || str_starts_with($path, rtrim($href, '/') . '/'));
    return '<a class="side-link' . ($on ? ' on' : '') . '" href="' . e(url($href)) . '"' . ($on ? ' aria-current="page"' : '') . '><i class="bi bi-' . $icon . '" aria-hidden="true"></i><span>' . e($label) . '</span></a>';
};
$ukNow = uk_now();
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'HR Portal') . ' · Menakan Transport') ?></title>
<link rel="preload" href="<?= e(asset('fonts/inter-latin-6ab57b19.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="icon" href="<?= e(url('/favicon.ico')) ?>" sizes="any">
<link rel="icon" href="<?= e(asset('images/favicon-32.png')) ?>" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="<?= e(asset('images/apple-touch-icon.png')) ?>">
</head>
<body>
<a class="visually-hidden-focusable skip" href="#main">Skip to content</a>
<div class="shell">
  <aside class="side" id="sidebar" aria-label="Main navigation">
    <div class="brand">
      <span class="mk"><img src="<?= e(asset('images/logo-mark.png')) ?>" alt="" width="36" height="36"></span>
      <div><b>Menakan Transport</b><span><?= $isAdmin ? 'HR Portal' : 'My Portal' ?></span></div>
    </div>
    <nav>
    <?php if ($isAdmin): ?>
      <?= $nav('/dashboard', 'Dashboard', 'speedometer2') ?>
      <div class="grp">People</div>
      <?= $nav('/employees', 'Employees', 'people') ?>
      <?= $nav('/hierarchy', 'Organisation Chart', 'diagram-3') ?>
      <?= $nav('/documents', 'Documents', 'folder2-open') ?>
      <div class="grp">Time</div>
      <?= $nav('/attendance', 'Attendance', 'clock-history') ?>
      <?= $nav('/attendance-import', 'Attendance Import', 'file-earmark-arrow-up') ?>
      <?= $nav('/shifts', 'Shifts', 'calendar3-week') ?>
      <?= $nav('/shift-import', 'Shift Import', 'file-earmark-spreadsheet') ?>
      <?= $nav('/leave', 'Leave Management', 'sun') ?>
      <div class="grp">Pay</div>
      <?= $nav('/payslips', 'Payroll & Payslips', 'cash-stack') ?>
      <?= $nav('/p60', 'P60 Documents', 'file-earmark-text') ?>
      <div class="grp">Communication</div>
      <?= $nav('/my-email', 'My Email', 'envelope-at') ?>
      <?= $nav('/news', 'News', 'megaphone') ?>
      <div class="grp">Admin</div>
      <?= $nav('/users', 'Users', 'person-gear') ?>
      <?= $nav('/reports', 'Reports', 'bar-chart-line') ?>
      <?= $nav('/settings', 'Settings', 'gear') ?>
      <?php if (Auth::employeeId()): ?>
        <div class="grp">Me</div>
        <?= $nav('/my', 'My Portal', 'person-badge', true) ?>
      <?php endif; ?>
    <?php else: ?>
      <?= $nav('/my', 'My Dashboard', 'speedometer2', true) ?>
      <?= $nav('/my/profile', 'My Profile', 'person-vcard') ?>
      <?= $nav('/hierarchy', 'Organisation Chart', 'diagram-3') ?>
      <?= $nav('/my/documents', 'My Documents', 'folder2-open') ?>
      <?= $nav('/my/attendance', 'My Attendance', 'clock-history') ?>
      <?= $nav('/my/shifts', 'My Shifts', 'calendar3-week') ?>
      <?= $nav('/my/leave', 'My Leave', 'sun') ?>
      <?= $nav('/my/payslips', 'My Payslips', 'cash-stack') ?>
      <?= $nav('/my/p60', 'My P60', 'file-earmark-text') ?>
      <?= $nav('/my-email', 'My Email', 'envelope-at') ?>
      <?= $nav('/my/id-card', 'My ID Card', 'person-badge') ?>
      <?= $nav('/my/news', 'News', 'megaphone') ?>
      <?= $nav('/account/password', 'My Account', 'key') ?>
    <?php endif; ?>
    </nav>
  </aside>
  <div class="side-backdrop" data-toggle-sidebar aria-hidden="true"></div>
  <div class="main">
    <header class="top">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button" data-toggle-sidebar aria-controls="sidebar" aria-label="Menu"><i class="bi bi-list"></i></button>
      <div class="crumb">
        <span><?= e($crumb ?? ($ukNow->format('l d/m/Y') . ' · UK time ' . $ukNow->format('H:i'))) ?></span>
        <h1><?= e($title ?? 'HR Portal') ?></h1>
      </div>
      <div class="sp"></div>
      <?php if (!empty($actions)) echo $actions; ?>
      <?php if ($isAdmin): /* notifications are for admins only */ ?>
      <a class="bell" href="<?= e(url('/notifications')) ?>" aria-label="Notifications<?= $unread ? " ($unread unread)" : '' ?>">
        <i class="bi bi-bell"></i><?php if ($unread): ?><em><?= $unread > 99 ? '99+' : $unread ?></em><?php endif; ?>
      </a>
      <?php endif; ?>
      <div class="dropdown">
        <button class="who btn btn-link text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
          <?= employee_avatar(['id' => $user['employee_id'], 'photo_key' => $user['photo_key'] ?? null, 'first_name' => $user['first_name'] ?? $user['username'], 'last_name' => $user['last_name'] ?? ''], 32) ?>
          <span class="d-none d-md-inline"><?= e(Auth::displayName()) ?> · <?= $isAdmin ? 'Admin' : 'Employee' ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php if (Auth::employeeId() && $isAdmin): ?><li><a class="dropdown-item" href="<?= e(url('/my')) ?>">My portal</a></li><?php endif; ?>
          <li><a class="dropdown-item" href="<?= e(url('/account/password')) ?>">Change password</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="dropdown-item" type="submit">Sign out</button></form></li>
        </ul>
      </div>
    </header>
    <main class="content" id="main">
      <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
          <?= e($f['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>" defer></script>
<?php if (!empty($charts)): ?><script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>" defer></script><?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
