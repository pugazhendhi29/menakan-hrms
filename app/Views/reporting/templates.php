<?php
use App\Services\ReportCatalog;

$total = 0;
foreach ($grid as $periods) {
    $total += count($periods);
}
?>
<p class="text-muted-2"><?= (int) count($grid) ?> approved job titles × Daily, Weekly and Monthly = <b><?= (int) $total ?> role-specific templates</b>. Every report also has the standard fields (tasks completed and outstanding, problems, actions, support required, comments, attachments). Calculated figures are filled from approved daily and weekly reports, so nobody types them twice.</p>
<div class="card">
  <div class="table-responsive">
    <table class="table align-middle tpl-grid">
      <thead><tr><th>Job title</th><th>People</th><?php foreach (ReportCatalog::PERIODS as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach (ReportCatalog::ROLES as $role => [$title, $area]): ?>
        <tr>
          <td><b><?= e($title) ?></b><small class="d-block text-muted-2"><?= e($area) ?></small></td>
          <td><?= (int) (reset($grid[$role]) ?: ['people' => 0])['people'] ?></td>
          <?php foreach (array_keys(ReportCatalog::PERIODS) as $period): $t = $grid[$role][$period] ?? null; ?>
            <td><?php if ($t): ?><a class="tpl-cell" href="<?= e(url('/reporting/templates/' . $t['id'])) ?>"><b><?= (int) $t['role_fields'] ?> questions</b>
              <small><?= (int) $t['calculated'] ?> calculated · <?= (int) $t['kpis'] ?> KPIs</small></a><?php else: ?><span class="text-danger">Missing</span><?php endif; ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
