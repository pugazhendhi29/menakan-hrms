<?php if ($url === ''): ?>
  <div class="card"><div class="empty"><i class="bi bi-envelope-x fs-3 d-block mb-2"></i>Webmail is not set up yet. Please contact HR.</div></div>
<?php else: ?>
<div class="card webmail-card">
  <div class="card-body d-flex flex-column flex-md-row gap-4 align-items-md-center">
    <div class="webmail-icon" aria-hidden="true"><i class="bi bi-envelope-at"></i></div>
    <div class="flex-grow-1">
      <h2 class="h4 mb-1">Company webmail</h2>
      <p class="mb-2">Read and send your Menakan Transport email. Webmail opens in a new tab, where you sign in with your email address and email password.</p>
      <p class="small text-muted-2 mb-0"><i class="bi bi-shield-lock"></i> Your email password is entered only on the webmail login page (<?= e($host) ?>). The HR portal never sees or stores it. Sign out of webmail when you finish, especially on a shared computer.</p>
    </div>
    <div class="d-grid gap-2 webmail-actions">
      <a class="btn btn-primary btn-lg" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Open webmail</a>
      <a class="btn btn-link btn-sm" href="<?= e($url) ?>" rel="noopener noreferrer">Open in this tab instead</a>
    </div>
  </div>
  <div class="card-footer small text-muted-2">
    Why a new tab? The webmail server does not allow its login page to be shown inside other websites (a security setting), so it cannot be embedded here.
    Webmail address: <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($url) ?></a>
  </div>
</div>
<?php endif; ?>
