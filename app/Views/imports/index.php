<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Upload <?= e($kind) ?> file <a href="<?= e(url($base . '/template')) ?>"><i class="bi bi-download"></i> Download template</a></div>
      <div class="card-body">
        <div class="steps mb-3"><span class="cur">1 · Upload</span><span>2 · Validate</span><span>3 · Preview</span><span>4 · Confirm</span></div>
        <form method="post" action="<?= e(url($base)) ?>" enctype="multipart/form-data" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <div><label class="form-label req" for="file">Excel workbook (.xlsx)</label><input class="form-control" type="file" id="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div>
          <p class="form-text mb-0"><?= e($help) ?> Maximum 5 MB and 5,000 rows. Nothing is saved until you confirm the preview.</p>
          <div><button class="btn btn-primary" type="submit">Upload and validate</button></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Import history</div>
      <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Batch</th><th>File</th><th>Uploaded</th><th class="num">Rows</th><th class="num">Valid</th><th class="num">Errors</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
          <tr><td><a href="<?= e(url($base . '/' . $b['id'])) ?>">#<?= e(sprintf('%04d', $b['id'])) ?></a></td><td><?= e($b['original_filename']) ?></td>
            <td class="text-nowrap"><?= e(uk_datetime($b['created_at'])) ?> · <?= e($b['username']) ?></td><td class="num"><?= e($b['total_rows']) ?></td>
            <td class="num"><?= e($b['valid_rows']) ?></td><td class="num"><?= e($b['error_rows']) ?></td><td><?= status_badge($b['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$batches): ?><tr><td colspan="7" class="empty">No imports yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
