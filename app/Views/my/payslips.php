<div class="card" style="max-width:820px"><table class="table">
  <thead><tr><th>Pay period</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $p): ?>
    <tr><td><b><?= e(month_name((int) $p['period_month']) . ' ' . $p['period_year']) ?></b></td>
      <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/payslip/' . $p['id'])) ?>" target="_blank" rel="noopener">View</a> <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/payslip/' . $p['id'] . '?download=1')) ?>"><i class="bi bi-download"></i> Download PDF</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="2" class="empty">No payslips have been published for you yet.</td></tr><?php endif; ?>
</tbody></table></div>
<p class="small text-muted-2">Payslips appear here once payroll publishes them. Questions about your pay? Contact HR.</p>
