<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditLogger;
use App\Services\LoginThrottle;
use App\Services\PasswordTokens;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return view('auth/login', ['title' => 'Sign in'], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $username = mb_substr($request->str('username'), 0, 190);
        $password = (string) ($request->post['password'] ?? '');
        $ip = $request->ip();
        $generic = 'The username or password is incorrect.';

        if ($username === '' || $password === '') {
            return back_with_errors('/login', [], ['username' => $username], 'Enter your username and password.');
        }
        if (LoginThrottle::isLocked($username, $ip)) {
            AuditLogger::log('auth.locked', 'user', null, 'Sign-in blocked by lockout for "' . $username . '"', null, null);
            return back_with_errors('/login', [], ['username' => $username], 'Too many failed attempts. Sign-in is paused for 15 minutes.');
        }

        $user = DB::one('SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1', [$username, $username]);
        // Always run a hash check so response time does not reveal whether the account exists.
        $hash = $user['password_hash'] ?? Auth::hashPassword(bin2hex(random_bytes(8)));
        $valid = password_verify($password, $hash);

        if (!$user || !$valid || !$user['password_hash']) {
            LoginThrottle::record($username, $ip, false);
            $left = LoginThrottle::MAX_FAILURES - LoginThrottle::recentFailures($username, $ip);
            AuditLogger::log('auth.failed', 'user', $user ? (int) $user['id'] : null, 'Failed sign-in for "' . $username . '"', null, null);
            return back_with_errors('/login', [], ['username' => $username], $left > 0 ? $generic : 'Too many failed attempts. Sign-in is paused for 15 minutes.');
        }
        if (!$user['is_active']) {
            LoginThrottle::record($username, $ip, false);
            return back_with_errors('/login', [], ['username' => $username], 'This account is deactivated. Please contact HR.');
        }
        if ($user['employee_id'] && DB::value('SELECT deleted_at FROM employees WHERE id = ?', [(int) $user['employee_id']])) {
            return back_with_errors('/login', [], ['username' => $username], 'This account is deactivated. Please contact HR.');
        }

        LoginThrottle::record($username, $ip, true);
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            DB::update('users', ['password_hash' => Auth::hashPassword($password)], 'id = ?', [(int) $user['id']]);
        }
        $intended = Session::pull('intended');
        Auth::login($user, $request);
        AuditLogger::log('auth.login', 'user', (int) $user['id'], 'Signed in', null, (int) $user['id']);

        $home = $user['role'] === 'admin' ? '/dashboard' : '/my';
        return redirect(is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : $home);
    }

    public function logout(Request $request): Response
    {
        $id = Auth::id();
        if ($id) {
            AuditLogger::log('auth.logout', 'user', $id, 'Signed out');
        }
        Auth::logout();
        Session::flash('success', 'You have signed out.');
        return redirect('/login');
    }

    public function showReset(Request $request, string $token): Response
    {
        $row = PasswordTokens::find($token);
        if (!$row) {
            Session::flash('danger', 'This link is invalid or has expired. Ask HR to send a new one.');
            return redirect('/login');
        }
        return view('auth/reset', ['title' => $row['purpose'] === 'activation' ? 'Activate account' : 'Reset password', 'token' => $token, 'row' => $row], 'layouts/auth');
    }

    public function reset(Request $request, string $token): Response
    {
        $row = PasswordTokens::find($token);
        if (!$row || !$row['is_active']) {
            Session::flash('danger', 'This link is invalid or has expired. Ask HR to send a new one.');
            return redirect('/login');
        }
        $password = (string) ($request->post['password'] ?? '');
        $confirm = (string) ($request->post['password_confirmation'] ?? '');
        $problem = Auth::passwordProblem($password) ?? ($password !== $confirm ? 'The passwords do not match.' : null);
        if ($problem) {
            return back_with_errors('/password/reset/' . $token, ['password' => $problem], [], $problem);
        }
        DB::transaction(function () use ($row, $password) {
            DB::update('users', ['password_hash' => Auth::hashPassword($password), 'must_change_password' => 0], 'id = ?', [(int) $row['user_id']]);
            PasswordTokens::consume((int) $row['id']);
            DB::update('user_sessions', ['revoked_at' => now_utc()], 'user_id = ? AND revoked_at IS NULL', [(int) $row['user_id']]);
            AuditLogger::log('auth.password_set', 'user', (int) $row['user_id'], 'Password set via ' . $row['purpose'] . ' link', null, (int) $row['user_id']);
        });
        Session::flash('success', 'Your password has been set. You can now sign in.');
        return redirect('/login');
    }
}
