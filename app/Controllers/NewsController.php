<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\Mailer;
use App\Services\NewsService;

/** News: admins write, schedule and send announcements (/news); employees read the ones sent to them (/my/news). */
final class NewsController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->str('status');
        $where = $status !== '' && in_array($status, ['draft', 'scheduled', 'sent', 'partially_sent', 'failed'], true) ? 'WHERE status = ?' : '';
        $p = paginate((int) DB::value("SELECT COUNT(*) FROM news_posts $where", $where ? [$status] : []), $request->int('page', 1), 25);
        $rows = DB::all("SELECT n.*, u.username AS author FROM news_posts n LEFT JOIN users u ON u.id = n.created_by $where
                          ORDER BY COALESCE(n.sent_at, n.scheduled_at, n.created_at) DESC, n.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $where ? [$status] : []);
        return view('news/index', ['title' => 'News', 'crumb' => 'Communication', 'rows' => $rows, 'p' => $p, 'request' => $request, 'status' => $status,
            'check' => NewsService::lastSenderCheck(), 'testMode' => Mailer::isLogOnly()]);
    }

    public function create(Request $request): Response
    {
        $post = ['title' => '', 'subject' => '', 'body' => '', 'audience' => 'all', 'audience_ids' => null, 'template_id' => null];
        if ($tid = $request->int('template')) {
            if ($t = DB::one('SELECT * FROM news_templates WHERE id = ? AND is_active = 1', [$tid])) {
                $post = ['title' => $t['subject'], 'subject' => $t['subject'], 'body' => $t['body'], 'template_id' => $t['id']] + $post;
            }
        }
        return $this->form($post, null);
    }

    public function store(Request $request): Response
    {
        return $this->attempt($request, '/news/create', function () use ($request) {
            $id = NewsService::save(null, $request->post, $this->userId());
            return $this->afterSave($request, $id);
        });
    }

    public function show(Request $request, int $id): Response
    {
        $post = $this->findOr404('SELECT n.*, u.username AS author FROM news_posts n LEFT JOIN users u ON u.id = n.created_by WHERE n.id = ?', [$id]);
        $deliveries = DB::all('SELECT d.*, e.employee_code, e.first_name, e.last_name FROM news_deliveries d JOIN employees e ON e.id = d.employee_id
                                WHERE d.news_id = ? ORDER BY FIELD(d.status, "failed", "skipped", "pending", "sent", "logged"), e.employee_code', [$id]);
        $preview = $deliveries ? [] : NewsService::recipients($post);
        return view('news/show', ['title' => $post['title'], 'crumb' => 'Communication · News', 'post' => $post, 'deliveries' => $deliveries,
            'preview' => $preview, 'testMode' => Mailer::isLogOnly()]);
    }

    public function edit(Request $request, int $id): Response
    {
        $post = $this->findOr404("SELECT * FROM news_posts WHERE id = ?", [$id]);
        if (!in_array($post['status'], ['draft', 'scheduled'], true)) {
            flash('warning', 'Sent announcements cannot be edited; they are kept exactly as they were sent.');
            return redirect("/news/$id");
        }
        return $this->form($post, $id);
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM news_posts WHERE id = ?', [$id]);
        return $this->attempt($request, "/news/$id/edit", function () use ($request, $id) {
            NewsService::save($id, $request->post, $this->userId());
            return $this->afterSave($request, $id);
        });
    }

    public function send(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM news_posts WHERE id = ?', [$id]);
        return $this->attempt($request, "/news/$id", function () use ($id) {
            $this->flashResult(NewsService::send($id, $this->userId()));
            return redirect("/news/$id");
        });
    }

    public function unschedule(Request $request, int $id): Response
    {
        return $this->attempt($request, "/news/$id", function () use ($id) {
            NewsService::unschedule($id, $this->userId());
            flash('success', 'Schedule cancelled. The announcement is a draft again.');
            return redirect("/news/$id");
        });
    }

    public function delete(Request $request, int $id): Response
    {
        return $this->attempt($request, "/news/$id", function () use ($id) {
            NewsService::delete($id, $this->userId());
            flash('success', 'Draft deleted.');
            return redirect('/news');
        });
    }

    public function senderCheck(Request $request): Response
    {
        $r = NewsService::checkSender($this->userId());
        flash($r['ok'] ? ($r['test_mode'] ? 'warning' : 'success') : 'danger', ($r['ok'] ? 'Sender check: ' : 'Sender check failed: ') . $r['message']);
        return redirect('/news');
    }

    /* ---------------------------------------------------------------- templates */

    public function templates(Request $request): Response
    {
        return view('news/templates', ['title' => 'News templates', 'crumb' => 'Communication · News',
            'rows' => DB::all('SELECT * FROM news_templates ORDER BY is_active DESC, name')]);
    }

    public function templateForm(Request $request, ?int $id = null): Response
    {
        $t = $id ? $this->findOr404('SELECT * FROM news_templates WHERE id = ?', [$id]) : ['id' => null, 'name' => '', 'subject' => '', 'body' => '', 'is_active' => 1];
        return view('news/template_form', ['title' => $id ? 'Edit News template' : 'New News template', 'crumb' => 'Communication · News', 't' => $t]);
    }

    public function templateEdit(Request $request, int $id): Response
    {
        return $this->templateForm($request, $id);
    }

    public function templateStore(Request $request): Response
    {
        return $this->attempt($request, '/news/templates/create', function () use ($request) {
            NewsService::saveTemplate(null, $request->post, $this->userId());
            flash('success', 'Template saved.');
            return redirect('/news/templates');
        });
    }

    public function templateUpdate(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM news_templates WHERE id = ?', [$id]);
        return $this->attempt($request, "/news/templates/$id/edit", function () use ($request, $id) {
            NewsService::saveTemplate($id, $request->post, $this->userId());
            flash('success', 'Template saved.');
            return redirect('/news/templates');
        });
    }

    public function templateDelete(Request $request, int $id): Response
    {
        return $this->attempt($request, '/news/templates', function () use ($id) {
            NewsService::deleteTemplate($id, $this->userId());
            flash('success', 'Template deleted. Announcements already written from it are not affected.');
            return redirect('/news/templates');
        });
    }

    /* ---------------------------------------------------------------- employee portal */

    public function my(Request $request): Response
    {
        $rows = DB::all("SELECT n.id, n.title, n.sent_at, d.sent_at AS received_at FROM news_deliveries d JOIN news_posts n ON n.id = d.news_id
                          WHERE d.employee_id = ? AND d.status IN ('sent','logged') ORDER BY d.sent_at DESC", [(int) Auth::employeeId()]);
        return view('my/news', ['title' => 'News', 'rows' => $rows]);
    }

    /** One announcement, only if it was delivered to this employee. Shown personalised, as in their email. */
    public function myShow(Request $request, int $id): Response
    {
        $row = DB::one("SELECT n.title, n.subject, n.body, d.sent_at, e.first_name, e.last_name, e.employee_code FROM news_deliveries d
                          JOIN news_posts n ON n.id = d.news_id JOIN employees e ON e.id = d.employee_id
                         WHERE d.news_id = ? AND d.employee_id = ? AND d.status IN ('sent','logged')", [$id, (int) Auth::employeeId()]);
        if (!$row) {
            throw new HttpException(404);
        }
        $vars = ['{first_name}' => $row['first_name'], '{last_name}' => $row['last_name'], '{full_name}' => trim($row['first_name'] . ' ' . $row['last_name']), '{employee_id}' => $row['employee_code']];
        return view('my/news_show', ['title' => 'News', 'row' => $row, 'subject' => strtr($row['subject'], $vars), 'body' => strtr($row['body'], $vars)]);
    }

    /* ---------------------------------------------------------------- helpers */

    private function form(array $post, ?int $id): Response
    {
        return view('news/form', ['title' => $id ? 'Edit announcement' : 'New announcement', 'crumb' => 'Communication · News', 'post' => $post, 'id' => $id,
            'templates' => DB::all('SELECT id, name FROM news_templates WHERE is_active = 1 ORDER BY name'),
            'employees' => $this->employeeOptions(), 'departmentList' => DB::all('SELECT id, name FROM departments ORDER BY name'), 'teams' => NewsService::teams(),
            'testMode' => Mailer::isLogOnly()]);
    }

    /** Save draft / schedule / send now, depending on which button was pressed. */
    private function afterSave(Request $request, int $id): Response
    {
        $intent = $request->str('intent', 'draft');
        try {
            if ($intent === 'schedule') {
                NewsService::schedule($id, $request->str('scheduled_at'), $this->userId());
                flash('success', 'Announcement scheduled for ' . uk_datetime((string) DB::value('SELECT scheduled_at FROM news_posts WHERE id = ?', [$id])) . '.');
                return redirect("/news/$id");
            }
            if ($intent === 'send') {
                $this->flashResult(NewsService::send($id, $this->userId()));
                return redirect("/news/$id");
            }
        } catch (ValidationException $e) {
            // The draft itself was saved; show why it was not scheduled or sent.
            return back_with_errors("/news/$id/edit", $e->errors, $request->post, $e->getMessage() . ' The draft has been saved.');
        }
        flash('success', 'Draft saved.');
        return redirect("/news/$id");
    }

    private function flashResult(array $r): void
    {
        if ($r['status'] === 'sent') {
            flash('success', sprintf('Announcement sent to %d employee%s from %s.%s', $r['sent'], $r['sent'] === 1 ? '' : 's', NewsService::SENDER_EMAIL,
                $r['test_mode'] ? ' Test mode: written to the mail log, not delivered.' : ''));
        } elseif ($r['status'] === 'partially_sent') {
            flash('warning', sprintf('Sent to %d, but %d could not be sent%s. See the delivery log below; you can retry the failed ones.', $r['sent'], $r['failed'], $r['error'] ? ' (' . $r['error'] . ')' : ''));
        } else {
            flash('danger', 'The announcement was not sent. ' . ($r['error'] ?? 'No recipient could be emailed.') . ' Nothing has been marked as sent; the failure has been logged.');
        }
        if ($r['skipped']) {
            flash('warning', $r['skipped'] . ' employee' . ($r['skipped'] === 1 ? ' has' : 's have') . ' no valid email address and were skipped.');
        }
    }
}
