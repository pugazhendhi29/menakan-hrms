<?php use App\Services\NewsService; ?>
<form method="post" action="<?= e(url($t['id'] ? '/news/templates/' . $t['id'] : '/news/templates')) ?>" class="card" style="max-width:860px" novalidate>
  <?= csrf_field() ?>
  <div class="card-body row g-3">
    <div class="col-md-6"><label class="form-label req" for="name">Template name</label>
      <input class="form-control<?= is_invalid('name') ?>" id="name" name="name" maxlength="120" required value="<?= e(old('name', $t['name'])) ?>"><?= field_error('name') ?></div>
    <div class="col-md-6"><label class="form-label req" for="subject">Email subject</label>
      <input class="form-control<?= is_invalid('subject') ?>" id="subject" name="subject" maxlength="200" required value="<?= e(old('subject', $t['subject'])) ?>"><?= field_error('subject') ?></div>
    <div class="col-12"><label class="form-label req" for="body">Message</label>
      <textarea class="form-control<?= is_invalid('body') ?>" id="body" name="body" rows="10" required><?= e(old('body', $t['body'])) ?></textarea><?= field_error('body') ?>
      <div class="form-text">Placeholders: <?= e(implode(' ', NewsService::PLACEHOLDERS)) ?>. The sender (<?= e(NewsService::SENDER_EMAIL) ?>) and HR signature are added automatically.</div></div>
    <?php if ($t['id']): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"<?= checked(old('is_active', $t['is_active'])) ?>><label class="form-check-label" for="is_active">Active (offered when writing an announcement)</label></div></div><?php endif; ?>
  </div>
  <div class="card-footer d-flex gap-2"><button class="btn btn-primary">Save template</button><a class="btn btn-link" href="<?= e(url('/news/templates')) ?>">Cancel</a></div>
</form>
