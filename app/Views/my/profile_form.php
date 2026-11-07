<form method="post" action="<?= e(url('/my/profile')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="alert alert-light border small">You can update your personal, identity and contact details, work experience and education here. Your name, work, reporting manager and pay details are kept by HR. If any of those are wrong, please tell HR.</div>
  <div class="row g-3">
    <div class="col-xl-8 d-flex flex-column gap-3">
      <?= App\Core\View::render('employees/_self_fields', ['e' => $me], null) ?>
    </div>
  </div>
  <div class="d-flex flex-column gap-3 mt-3">
    <?= App\Core\View::render('employees/_history_fields', ['experienceRows' => $experienceRows, 'educationRows' => $educationRows], null) ?>
  </div>
  <div class="d-flex gap-2 mt-2">
    <button class="btn btn-primary" type="submit">Save changes</button>
    <a class="btn btn-outline-secondary" href="<?= e(url('/my/profile')) ?>">Cancel</a>
  </div>
</form>
