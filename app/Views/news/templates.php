<div class="toolbar">
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/news')) ?>"><i class="bi bi-arrow-left"></i> News</a>
  <a class="btn btn-sm btn-hivis ms-auto" href="<?= e(url('/news/templates/create')) ?>"><i class="bi bi-plus-lg"></i> New template</a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover">
  <thead><tr><th>Name</th><th>Subject</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $t): ?>
    <tr class="<?= $t['is_active'] ? '' : 'text-muted-2' ?>">
      <td class="fw-semibold"><?= e($t['name']) ?></td><td><?= e($t['subject']) ?></td>
      <td><?= $t['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
      <td class="text-end text-nowrap">
        <?php if ($t['is_active']): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/news/create?template=' . $t['id'])) ?>">Use</a><?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/news/templates/' . $t['id'] . '/edit')) ?>">Edit</a>
        <form class="d-inline" method="post" action="<?= e(url('/news/templates/' . $t['id'] . '/delete')) ?>" data-confirm="Delete this template? Announcements already written from it are not affected."><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Delete</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="4" class="empty">No templates yet.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
