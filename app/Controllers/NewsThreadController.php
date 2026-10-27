<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditLogger;
use App\Services\NewsService;
use App\Services\NewsThreadService;

/** News threads: admins record past conversations and publish them (/news/threads); employees read them (/my/news/threads). */
final class NewsThreadController extends Controller
{
    private const THREAD_SQL = 'SELECT t.*, c.username AS author, p.username AS publisher, w.username AS withdrawer FROM news_threads t
                                LEFT JOIN users c ON c.id = t.created_by LEFT JOIN users p ON p.id = t.published_by LEFT JOIN users w ON w.id = t.withdrawn_by WHERE t.id = ?';

    public function index(Request $request): Response
    {
        $status = $request->str('status');
        $where = in_array($status, ['draft', 'published', 'withdrawn'], true) ? 'WHERE t.status = ?' : '';
        $params = $where ? [$status] : [];
        $p = paginate((int) DB::value("SELECT COUNT(*) FROM news_threads t $where", $params), $request->int('page', 1), 25);
        // Each thread with its latest message (sender and text), for an inbox-style list.
        $rows = DB::all("SELECT t.*, COUNT(m.id) AS message_count, MAX(m.occurred_at) AS last_at,
                                (SELECT l.sender_label FROM news_thread_messages l WHERE l.thread_id = t.id ORDER BY l.occurred_at DESC, l.id DESC LIMIT 1) AS last_sender,
                                (SELECT l.sender_type FROM news_thread_messages l WHERE l.thread_id = t.id ORDER BY l.occurred_at DESC, l.id DESC LIMIT 1) AS last_sender_type,
                                (SELECT LEFT(l.body, 200) FROM news_thread_messages l WHERE l.thread_id = t.id ORDER BY l.occurred_at DESC, l.id DESC LIMIT 1) AS last_body
                           FROM news_threads t LEFT JOIN news_thread_messages m ON m.thread_id = t.id $where
                          GROUP BY t.id ORDER BY COALESCE(MAX(m.occurred_at), t.created_at) DESC, t.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
        return view('news/threads/index', ['title' => 'News threads', 'crumb' => 'Communication · News', 'rows' => $rows, 'p' => $p, 'request' => $request, 'status' => $status]);
    }

    /** Write one email: from, to, date and time, subject, message. */
    public function create(Request $request): Response
    {
        return view('news/threads/compose', ['title' => 'New email', 'crumb' => 'Communication · News threads',
            'employees' => DB::all('SELECT e.id, e.employee_code, e.first_name, e.last_name, e.status, j.title AS job_title FROM employees e
                                     LEFT JOIN job_titles j ON j.id = e.job_title_id WHERE e.deleted_at IS NULL ORDER BY e.first_name, e.last_name'),
            'departmentList' => DB::all('SELECT id, name FROM departments ORDER BY name')]);
    }

    /** Publishes the email straight away. */
    public function store(Request $request): Response
    {
        return $this->attempt($request, '/news/threads/create', function () use ($request) {
            $id = NewsThreadService::compose($request->post, $this->userId());
            flash('success', 'Email published. Its receivers can read it in My News and have been notified.');
            return redirect("/news/threads/$id");
        });
    }

    public function show(Request $request, int $id): Response
    {
        $thread = $this->findOr404(self::THREAD_SQL, [$id]);
        return view('news/threads/show', ['title' => $thread['title'], 'crumb' => 'Communication · News threads', 'thread' => $thread,
            'messages' => NewsThreadService::messages($id), 'readers' => $thread['status'] === 'withdrawn' ? [] : NewsThreadService::readers($thread)]
            + $this->pickers());
    }

    public function edit(Request $request, int $id): Response
    {
        $thread = $this->findOr404('SELECT * FROM news_threads WHERE id = ?', [$id]);
        if ($thread['status'] !== 'draft') {
            flash('warning', 'Published threads cannot be edited; they are kept exactly as they were published.');
            return redirect("/news/threads/$id");
        }
        return $this->form($thread, $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM news_threads WHERE id = ?', [$id]);
        return $this->attempt($request, "/news/threads/$id/edit", function () use ($request, $id) {
            NewsThreadService::save($id, $request->post, $this->userId());
            flash('success', 'Thread saved.');
            return redirect("/news/threads/$id");
        });
    }

    public function publish(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM news_threads WHERE id = ?', [$id]);
        return $this->attempt($request, "/news/threads/$id", function () use ($id) {
            NewsThreadService::publish($id, $this->userId());
            flash('success', 'Thread published. Employees in the audience can now read it under News in their portal.');
            return redirect("/news/threads/$id");
        });
    }

    public function withdraw(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM news_threads WHERE id = ?', [$id]);
        return $this->attempt($request, "/news/threads/$id", function () use ($request, $id) {
            NewsThreadService::withdraw($id, $request->str('reason'), $this->userId());
            flash('success', 'Thread withdrawn. Employees no longer see it; it is kept here for the record.');
            return redirect("/news/threads/$id");
        });
    }

    public function delete(Request $request, int $id): Response
    {
        return $this->attempt($request, "/news/threads/$id", function () use ($id) {
            NewsThreadService::delete($id, $this->userId());
            flash('success', 'Thread deleted. It no longer appears in anyone\'s News.');
            return redirect('/news/threads');
        });
    }

    /* ---------------------------------------------------------------- messages */

    public function messageStore(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM news_threads WHERE id = ?', [$id]);
        return $this->attempt($request, "/news/threads/$id#add-message", function () use ($request, $id) {
            NewsThreadService::saveMessage($id, null, $request->post, $this->userId());
            flash('success', DB::value('SELECT status FROM news_threads WHERE id = ?', [$id]) === 'published' ? 'Reply added. The people in the thread have been notified.' : 'Message added.');
            return redirect("/news/threads/$id#add-message");
        });
    }

    public function messageEdit(Request $request, int $id, int $messageId): Response
    {
        $thread = $this->findOr404('SELECT * FROM news_threads WHERE id = ?', [$id]);
        $message = $this->findOr404('SELECT * FROM news_thread_messages WHERE id = ? AND thread_id = ?', [$messageId, $id]);
        if ($thread['status'] !== 'draft') {
            flash('warning', 'Messages in a published thread cannot be changed.');
            return redirect("/news/threads/$id");
        }
        return view('news/threads/message_edit', ['title' => 'Edit message', 'crumb' => 'Communication · News threads · ' . $thread['title'],
            'thread' => $thread, 'message' => $message] + $this->pickers());
    }

    public function messageUpdate(Request $request, int $id, int $messageId): Response
    {
        $this->findOr404('SELECT id FROM news_thread_messages WHERE id = ? AND thread_id = ?', [$messageId, $id]);
        return $this->attempt($request, "/news/threads/$id/messages/$messageId/edit", function () use ($request, $id, $messageId) {
            NewsThreadService::saveMessage($id, $messageId, $request->post, $this->userId());
            flash('success', 'Message saved.');
            return redirect("/news/threads/$id");
        });
    }

    public function messageDelete(Request $request, int $id, int $messageId): Response
    {
        return $this->attempt($request, "/news/threads/$id", function () use ($id, $messageId) {
            NewsThreadService::deleteMessage($id, $messageId, $this->userId());
            flash('success', 'Message removed.');
            return redirect("/news/threads/$id");
        });
    }

    /* ---------------------------------------------------------------- employee portal */

    public function myCreate(Request $request): Response
    {
        return view('my/news_thread_form', ['title' => 'New thread', 'employees' => $this->employeeOptions(), 'departmentList' => $this->departments(),
            'me' => (int) Auth::employeeId()]);
    }

    public function myStore(Request $request): Response
    {
        return $this->attempt($request, '/my/news/threads/create', function () use ($request) {
            $id = NewsThreadService::startFromPortal((int) Auth::employeeId(), $request->post, $this->userId());
            flash('success', 'Thread sent. The people you sent it to can read it under News.');
            return redirect("/my/news/threads/$id");
        });
    }

    public function myReply(Request $request, int $id): Response
    {
        NewsThreadService::findForEmployee($id, (int) Auth::employeeId()) ?? throw new HttpException(404);
        return $this->attempt($request, "/my/news/threads/$id#reply", function () use ($request, $id) {
            NewsThreadService::replyFromPortal($id, (int) Auth::employeeId(), $request->post, $this->userId());
            flash('success', 'Reply sent.');
            return redirect("/my/news/threads/$id#reply");
        });
    }

    public function myShow(Request $request, int $id): Response
    {
        $thread = NewsThreadService::findForEmployee($id, (int) Auth::employeeId()) ?? throw new HttpException(404);
        AuditLogger::log('news_thread.viewed', 'news_thread', $id, 'Employee viewed news thread "' . $thread['title'] . '"');
        return view('my/news_thread', ['title' => 'News', 'thread' => $thread, 'messages' => NewsThreadService::messages($id)]);
    }

    /* ---------------------------------------------------------------- helpers */

    private function form(array $thread, ?int $id): Response
    {
        return view('news/threads/form', ['title' => $id ? 'Edit thread' : 'New thread', 'crumb' => 'Communication · News threads', 'thread' => $thread, 'id' => $id,
            'employees' => $this->employeeOptions(), 'departmentList' => DB::all('SELECT id, name FROM departments ORDER BY name'), 'teams' => NewsService::teams()]);
    }

    /** Lists for the sender and receiver pickers. Former employees are included: a past message may involve them. */
    private function pickers(): array
    {
        return ['employees' => DB::all('SELECT id, employee_code, first_name, last_name, status FROM employees WHERE deleted_at IS NULL ORDER BY first_name, last_name'),
            'departmentList' => DB::all('SELECT id, name FROM departments ORDER BY name')];
    }
}
