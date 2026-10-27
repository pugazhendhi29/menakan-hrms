<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $rows = DB::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100', [$this->userId()]);
        // News threads show the thread's own date (its latest message), not the day it was shared.
        foreach ($rows as &$n) {
            if (preg_match('#^/my/news/threads/(\d+)$#', (string) $n['link'], $m)) {
                $n['shown_at'] = DB::value('SELECT MAX(occurred_at) FROM news_thread_messages WHERE thread_id = ?', [(int) $m[1]]);
            }
        }
        unset($n);
        // Newest first, by the date shown.
        usort($rows, fn ($a, $b) => [$b['shown_at'] ?? $b['created_at'], (int) $b['id']] <=> [$a['shown_at'] ?? $a['created_at'], (int) $a['id']]);
        return view('notifications/index', ['title' => 'Notifications', 'rows' => $rows]);
    }

    public function open(Request $request, int $id): Response
    {
        $n = DB::one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $this->userId()]) ?? throw new HttpException(404);
        DB::update('notifications', ['read_at' => now_utc()], 'id = ? AND read_at IS NULL', [$id]);
        $link = (string) $n['link'];
        return redirect(str_starts_with($link, '/') && !str_starts_with($link, '//') ? $link : '/notifications');
    }

    public function readAll(Request $request): Response
    {
        DB::update('notifications', ['read_at' => now_utc()], 'user_id = ? AND read_at IS NULL', [$this->userId()]);
        flash('success', 'All notifications marked as read.');
        return redirect('/notifications');
    }
}
