<?php use App\Services\NewsThreadService; ?>
<form method="post" action="<?= e(url($id ? '/news/threads/' . $id : '/news/threads')) ?>" class="card" novalidate>
  <?= csrf_field() ?>
  <div class="card-body row g-3">
    <div class="col-md-8"><label class="form-label req" for="title">Title</label>
      <input class="form-control<?= is_invalid('title') ?>" id="title" name="title" maxlength="200" required value="<?= e(old('title', $thread['title'])) ?>"><?= field_error('title') ?></div>
    <div class="col-md-4"><label class="form-label req" for="channel">Where it took place</label>
      <select class="form-select<?= is_invalid('channel') ?>" id="channel" name="channel">
        <?php foreach (NewsThreadService::CHANNELS as $k => $v): ?><option value="<?= $k ?>"<?= selected($k, old('channel', $thread['channel'])) ?>><?= e($v) ?></option><?php endforeach; ?>
      </select><?= field_error('channel') ?></div>
    <div class="col-12"><label class="form-label" for="summary">Summary (optional)</label>
      <textarea class="form-control<?= is_invalid('summary') ?>" id="summary" name="summary" rows="3" maxlength="1000"><?= e(old('summary', (string) $thread['summary'])) ?></textarea><?= field_error('summary') ?>
      <div class="form-text">A line or two of context, shown above the messages.</div></div>

    <?= App\Core\View::partial('news_audience', ['post' => $thread, 'legend' => 'Who can read it once published', 'options' => NewsThreadService::AUDIENCES, 'employees' => $employees, 'departmentList' => $departmentList, 'teams' => $teams,
      'note' => 'Every employee who sent or received a message always sees the thread in My News and get a notification when it is published. Choose another option to let more people read it. Nothing is emailed.']) ?>
  </div>
  <div class="card-footer d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-save"></i> <?= $id ? 'Save' : 'Save and add messages' ?></button>
    <a class="btn btn-link" href="<?= e(url($id ? '/news/threads/' . $id : '/news/threads')) ?>">Cancel</a>
  </div>
</form>
