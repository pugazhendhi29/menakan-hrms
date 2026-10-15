<p class="text-muted-2 mb-0">Every report can be filtered, viewed on screen, and exported to CSV or Excel.</p>
<div class="row g-3">
<?php foreach ($groups as $group => $items): ?>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><?= e($group) ?></div>
      <div class="list-group list-group-flush">
        <?php foreach ($items as $key => [$title, $desc]): ?>
          <a class="list-group-item list-group-item-action" href="<?= e(url('/reports/' . $key)) ?>"><b><?= e($title) ?></b><br><small class="text-muted-2"><?= e($desc) ?></small></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>
