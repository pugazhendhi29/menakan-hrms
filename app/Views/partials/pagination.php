<?php /** @var array $p pagination meta; @var App\Core\Request $request */ ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2 border-top no-print">
  <small class="text-muted-2">Showing <?= e($p['from']) ?>–<?= e($p['to']) ?> of <?= e($p['total']) ?></small>
  <?php if ($p['pages'] > 1): ?>
  <nav aria-label="Pages"><ul class="pagination pagination-sm mb-0">
    <li class="page-item<?= $p['page'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($request->fullUrlWithQuery(['page' => $p['page'] - 1])) ?>" aria-label="Previous">‹</a></li>
    <?php
    $start = max(1, $p['page'] - 3);
    $end = min($p['pages'], $p['page'] + 3);
    for ($i = $start; $i <= $end; $i++): ?>
      <li class="page-item<?= $i === $p['page'] ? ' active' : '' ?>"><a class="page-link" href="<?= e($request->fullUrlWithQuery(['page' => $i])) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
    <li class="page-item<?= $p['page'] >= $p['pages'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($request->fullUrlWithQuery(['page' => $p['page'] + 1])) ?>" aria-label="Next">›</a></li>
  </ul></nav>
  <?php endif; ?>
</div>
