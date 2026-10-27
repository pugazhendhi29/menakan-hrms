<?= App\Core\View::partial('news_thread_message', ['message' => $message, 'action' => url('/news/threads/' . $thread['id'] . '/messages/' . $message['id']), 'submit' => 'Save message',
  'cancel' => url('/news/threads/' . $thread['id']), 'employees' => $employees, 'departmentList' => $departmentList]) ?>
