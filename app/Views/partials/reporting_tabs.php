<?php
/** Daily / Weekly / Monthly tabs. $path = page, $period = current tab, $query = other query values to keep. */
$query ??= [];
?>
<nav class="period-tabs" aria-label="Reporting period">
  <?php foreach (\App\Services\ReportCatalog::PERIODS as $key => $label): ?>
    <a class="<?= $key === $period ? 'on' : '' ?>" href="<?= e(url($path) . '?' . http_build_query(['period' => $key] + $query)) ?>"<?= $key === $period ? ' aria-current="page"' : '' ?>>
      <i class="bi bi-<?= ['daily' => 'calendar-day', 'weekly' => 'calendar-week', 'monthly' => 'calendar3'][$key] ?>" aria-hidden="true"></i><?= e($label) ?>
    </a>
  <?php endforeach; ?>
</nav>
