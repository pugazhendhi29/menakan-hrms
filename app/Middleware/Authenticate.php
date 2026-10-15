<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** Requires a signed-in, active user with a live (not revoked, not idle) session. */
final class Authenticate
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Session::get('user_id')) {
            if ($request->method === 'GET') {
                Session::set('intended', $request->path);
            }
            return Response::redirect('/login');
        }

        $idleLimit = Config::get('app.session_idle', 30) * 60;
        $last = (int) Session::get('last_activity', 0);
        if ($last && time() - $last > $idleLimit) {
            Auth::logout();
            Session::flash('warning', 'You were signed out after ' . Config::get('app.session_idle', 30) . ' minutes of inactivity.');
            return Response::redirect('/login');
        }

        $sessionRow = DB::one(
            'SELECT id, last_seen_at, revoked_at FROM user_sessions WHERE session_id_hash = ? AND user_id = ?',
            [hash('sha256', Session::id()), (int) Session::get('user_id')]
        );
        if (!Auth::check() || !$sessionRow || $sessionRow['revoked_at'] !== null) {
            Auth::logout();
            Session::flash('warning', 'Your session has ended. Please sign in again.');
            return Response::redirect('/login');
        }

        Session::set('last_activity', time());
        if (strtotime($sessionRow['last_seen_at'] . ' UTC') < time() - 60) {
            DB::update('user_sessions', ['last_seen_at' => now_utc()], 'id = ?', [(int) $sessionRow['id']]);
        }

        if ((int) Auth::user()['must_change_password'] === 1 && !in_array($request->path, ['/account/password', '/logout'], true)) {
            Session::flash('info', 'Please choose a new password before continuing.');
            return Response::redirect('/account/password');
        }

        return $next($request);
    }
}
