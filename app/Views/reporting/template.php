<?php
use App\Services\ReportCatalog;

$types = ['int' => 'Whole number', 'dec' => 'Number', 'money' => 'Amount (£)', 'pct' => 'Percentage', 'text' => 'Text', 'calc' => 'Calculated'];
?>
<div class="rp-bar">
  <nav class="period-tabs" aria-label="Template period">
    <?php foreach ($siblings as $s): ?><a class="<?= (int) $s['id'] === (int) $t['id'] ? 'on' : '' ?>" href="<?= e(url('/reporting/templates/' . $s['id'])) ?>"><?= e(ReportCatalog::PERIODS[$s['period']]) ?></a><?php endforeach; ?>
  </nav>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/reporting/templates')) ?>"><i class="bi bi-grid-3x3"></i> All templates</a>
</div>
<?php foreach (['role' => 'Role-specific questions', 'standard' => 'Standard fields (every report)'] as $section => $heading): ?>
<div class="card rp-section">
  <div class="card-header"><?= e($heading) ?></div>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>#</th><th>Question / indicator</th><th>Type</th><th>Required</th><th>How it is filled</th></tr></thead>
      <tbody>
      <?php foreach ($t['fields'] as $f): if ($f['section'] !== $section) { continue; } ?>
        <tr>
          <td><?= (int) $f['sort_order'] ?></td>
          <td><?= e($f['label']) ?><?= $f['is_kpi'] ? '<span class="tag-kpi">KPI</span>' : '' ?></td>
          <td><?= e($types[$f['field_type']]) ?><?= $f['field_type'] === 'calc' ? ' <small class="text-muted-2">(' . e($types[$f['display']]) . ')</small>' : '' ?></td>
          <td><?= $f['is_required'] ? 'Yes' : '' ?></td>
          <td class="small"><?= $f['field_type'] === 'calc' ? '<code>' . e($f['formula']) . '</code>' : 'Entered by the employee' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
<p class="small text-muted-2">In formulas, <code>sum</code>, <code>avg</code> and <code>last</code> read the employee's approved daily reports in the period; <code>wsum</code>, <code>wavg</code> and <code>wlast</code> read their approved weekly reports. Managers' reports also carry the team consolidation, their own management assessment and a team rating.</p>
