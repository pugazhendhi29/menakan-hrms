<h1 class="h3 mb-2"><?= e($status) ?> · <?= e($title) ?></h1>
<p class="text-muted"><?= e($message) ?></p>
<p class="d-flex gap-2">
  <a class="btn btn-primary" href="<?= e(url('/')) ?>">Go to your dashboard</a>
  <a class="btn btn-outline-secondary" href="#" data-back>Go back</a>
</p>
