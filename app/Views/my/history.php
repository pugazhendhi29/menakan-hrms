<form method="post" action="<?= e(url('/my/history')) ?>" class="d-flex flex-column gap-3" novalidate style="max-width:1100px">
  <?= csrf_field() ?>
  <p class="text-muted-2 mb-0">Add every previous job and qualification. HR can see these details and may contact your professional references.</p>
  <?= App\Core\View::render('employees/_history_fields', ['experienceRows' => $experienceRows, 'educationRows' => $educationRows], null) ?>
  <div class="d-flex gap-2"><button class="btn btn-primary" type="submit">Save</button><a class="btn btn-outline-secondary" href="<?= e(url('/my/profile')) ?>">Cancel</a></div>
</form>
