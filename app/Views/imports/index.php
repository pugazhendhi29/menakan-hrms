<?php
/** Shared upload page for the attendance, shift and employee imports. Expects $kind, $base, $icon, $help, $batches and optionally $columns (name => format hint). */
$columns ??= [];
$awaiting = array_values(array_filter($batches, fn ($b) => $b['status'] === 'validated'));
$steps = [
    ['upload', 'Upload', 'Choose the filled-in workbook.'],
    ['shield-check', 'Validate', 'Every row is checked against the HRMS.'],
    ['eye', 'Preview', 'Review valid rows, duplicates and errors.'],
    ['check2-circle', 'Confirm', 'Only then is anything saved.'],
];
?>
<?php if ($awaiting): ?>
<div class="alert alert-warning d-flex flex-wrap gap-2 align-items-center">
  <i class="bi bi-hourglass-split"></i>
  <span class="flex-grow-1"><?= count($awaiting) === 1 ? 'An upload is' : e(count($awaiting)) . ' uploads are' ?> validated but not confirmed yet. Nothing from <?= count($awaiting) === 1 ? 'it' : 'them' ?> is saved until you confirm.</span>
  <?php foreach (array_slice($awaiting, 0, 3) as $b): ?>
    <a class="btn btn-sm btn-hivis" href="<?= e(url($base . '/' . $b['id'])) ?>">Review <?= e($b['original_filename']) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="dash-head">
        <span class="dash-ico"><i class="bi bi-<?= e($icon ?? 'upload') ?>"></i></span><h2>Upload <?= e($kind) ?> file</h2>
        <a class="ms-auto" href="<?= e(url($base . '/template')) ?>"><i class="bi bi-download"></i> Template</a>
      </div>
      <div class="card-body d-flex flex-column gap-3">
        <form method="post" action="<?= e(url($base)) ?>" enctype="multipart/form-data" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <label class="dropzone" for="file" data-dropzone>
            <i class="bi bi-file-earmark-spreadsheet"></i>
            <span class="dropzone-title" data-dropzone-name>Drop your Excel workbook here</span>
            <span class="dropzone-hint">or <u>choose a file</u> · .xlsx, up to 5 MB and 5,000 rows</span>
            <input class="visually-hidden" type="file" id="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
          </label>
          <button class="btn btn-primary" type="submit"><i class="bi bi-shield-check"></i> Upload and validate</button>
          <p class="form-text mb-0"><i class="bi bi-info-circle"></i> Nothing is saved until you confirm the preview.</p>
        </form>

        <div>
          <h3 class="dash-label mb-2">File layout</h3>
          <?php if ($columns): ?>
            <div class="col-chips">
              <?php foreach ($columns as $name => $hint): ?><span class="col-chip"><?= e($name) ?><?php if ($hint !== ''): ?> <small><?= e($hint) ?></small><?php endif; ?></span><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <p class="form-text mb-0 mt-2"><?= e($help) ?></p>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card h-100">
      <div class="dash-head"><span class="dash-ico"><i class="bi bi-signpost-split"></i></span><h2>How it works</h2></div>
      <div class="card-body">
        <ol class="flow">
          <?php foreach ($steps as $i => [$si, $st, $sd]): ?>
            <li class="<?= $i === 0 ? 'cur' : '' ?>"><span class="flow-ico"><i class="bi bi-<?= e($si) ?>"></i></span><div><b><?= e($i + 1) ?> · <?= e($st) ?></b><small><?= e($sd) ?></small></div></li>
          <?php endforeach; ?>
        </ol>
      </div>
    </div>
  </div>
</div>
