<?php use App\Services\P60Service; ?>
<p class="text-muted-2 mb-0">Your P60 shows your total pay and tax for a UK tax year (6 April to 5 April). HR uploads it after the tax year ends.</p>
<?php if (!$rows): ?>
  <div class="card"><div class="empty"><i class="bi bi-file-earmark-text fs-3 d-block mb-2"></i>No P60 is available for you yet. If you were expecting one, please contact HR.</div></div>
<?php else: ?>
<div class="card" style="max-width:820px"><table class="table">
  <thead><tr><th>Tax Year</th><th>Period</th><th class="text-end">Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="fw-semibold"><?= e(P60Service::label((int) $r['tax_year_start'])) ?></td><td><?= e(P60Service::range((int) $r['tax_year_start'])) ?></td>
      <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/p60/' . $r['id'])) ?>" target="_blank" rel="noopener">View</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/p60/' . $r['id'] . '?download=1')) ?>"><i class="bi bi-download"></i> Download PDF</a></td></tr>
  <?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
