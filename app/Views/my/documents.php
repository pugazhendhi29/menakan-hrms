<?php use App\Services\DocumentService; ?>
<div class="card"><div class="card-header">Required documents</div><table class="table">
  <thead><tr><th>Document</th><th>Expiry</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($compliance['rows'] as $r): ?>
    <tr><td><?= e($r['category']['name']) ?></td><td><?= e($r['document'] ? uk_date($r['document']['expiry_date']) : '—') ?></td><td><?= status_badge($r['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$compliance['rows']): ?><tr><td colspan="3" class="empty">No documents are required for your role.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="card"><div class="card-header">My documents on file</div><div class="table-responsive"><table class="table">
  <thead><tr><th>Category</th><th>Title</th><th>Expiry</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($docs as $d): ?>
    <tr><td><?= e($d['category_name']) ?></td><td><?= e($d['title']) ?></td><td><?= e(uk_date($d['expiry_date'])) ?></td><td><?= status_badge(DocumentService::status($d)) ?></td>
      <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/document/' . $d['id'])) ?>" target="_blank" rel="noopener">View</a> <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/files/document/' . $d['id'] . '?download=1')) ?>">Download</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$docs): ?><tr><td colspan="5" class="empty">No documents on file.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="alert alert-light border small mb-0">Documents are uploaded and verified by HR. To update one, bring the original to the office.</div>
