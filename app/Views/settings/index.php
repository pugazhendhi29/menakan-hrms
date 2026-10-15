<div class="row g-3">
  <div class="col-xl-7">
    <div class="card"><div class="card-header">System settings</div><div class="card-body">
      <form method="post" action="<?= e(url('/settings')) ?>" class="row g-3">
        <?= csrf_field() ?>
        <?php foreach ($settings as $s): $id = 's_' . $s['key']; ?>
          <div class="col-md-6">
            <?php if ($s['type'] === 'bool'): ?>
              <div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="<?= e($id) ?>" name="settings[<?= e($s['key']) ?>]" value="1"<?= checked($s['value'] === '1') ?>><label class="form-check-label fw-semibold" for="<?= e($id) ?>"><?= e($s['label']) ?></label></div>
            <?php else: ?>
              <label class="form-label" for="<?= e($id) ?>"><?= e($s['label']) ?></label>
              <?php if (str_ends_with($s['key'], '_signature')): ?>
                <textarea class="form-control<?= is_invalid($s['key']) ?>" id="<?= e($id) ?>" name="settings[<?= e($s['key']) ?>]" rows="6" maxlength="255"><?= e($s['value']) ?></textarea>
              <?php else: ?>
              <input class="form-control<?= is_invalid($s['key']) ?>" id="<?= e($id) ?>" name="settings[<?= e($s['key']) ?>]" value="<?= e($s['value']) ?>"<?= str_ends_with($s['key'], '_date') ? ' type="date"' : '' ?>>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($s['description']): ?><div class="form-text"><?= e($s['description']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="col-12"><button class="btn btn-primary" type="submit">Save settings</button></div>
      </form>
    </div></div>
  </div>
  <div class="col-xl-5">
    <div class="card"><div class="card-header">Reference data</div>
      <div class="list-group list-group-flush">
        <?php foreach ($lookups as $table => $label): ?><a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= e(url('/settings/' . $table)) ?>"><?= e($label) ?> <i class="bi bi-chevron-right"></i></a><?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
