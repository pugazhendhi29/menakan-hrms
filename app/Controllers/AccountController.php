<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditLogger;

final class AccountController extends Controller
{
    public function showPassword(Request $request): Response
    {
        return view('account/password', ['title' => 'My account', 'user' => $this->user()]);
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->user();
        $current = (string) ($request->post['current_password'] ?? '');
        $new = (string) ($request->post['password'] ?? '');
        $confirm = (string) ($request->post['password_confirmation'] ?? '');
        $errors = [];
        if (!password_verify($current, (string) $user['password_hash'])) {
            $errors['current_password'] = 'Your current password is incorrect.';
        }
        $problem = Auth::passwordProblem($new);
        if ($problem) {
            $errors['password'] = $problem;
        } elseif ($new !== $confirm) {
            $errors['password_confirmation'] = 'The passwords do not match.';
        } elseif ($new === $current) {
            $errors['password'] = 'Choose a password different from your current one.';
        }
        if ($errors) {
            return back_with_errors('/account/password', $errors);
        }
        DB::update('users', ['password_hash' => Auth::hashPassword($new), 'must_change_password' => 0], 'id = ?', [(int) $user['id']]);
        // End every other session for this account.
        DB::update('user_sessions', ['revoked_at' => now_utc()], 'user_id = ? AND revoked_at IS NULL AND session_id_hash <> ?', [(int) $user['id'], hash('sha256', Session::id())]);
        AuditLogger::log('auth.password_changed', 'user', (int) $user['id'], 'Changed own password');
        flash('success', 'Your password has been changed. Other devices have been signed out.');
        return redirect('/account/password');
    }
}
