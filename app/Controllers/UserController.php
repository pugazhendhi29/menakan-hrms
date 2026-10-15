<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\AuditLogger;
use App\Services\PasswordTokens;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $where = ['1=1'];
        $params = [];
        if (in_array($role = $request->str('role'), ['admin', 'employee'], true)) {
            $where[] = 'u.role = ?';
            $params[] = $role;
        }
        if ($request->str('active') !== '') {
            $where[] = 'u.is_active = ?';
            $params[] = $request->str('active') === '1' ? 1 : 0;
        }
        if ($q = $request->str('q')) {
            $where[] = "(u.username LIKE ? OR u.email LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.employee_code LIKE ?)";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $rows = DB::all('SELECT u.*, e.employee_code, e.first_name, e.last_name,
                                (SELECT COUNT(*) FROM user_sessions s WHERE s.user_id = u.id AND s.revoked_at IS NULL AND s.last_seen_at > ?) AS live_sessions
                           FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE ' . implode(' AND ', $where) . ' ORDER BY u.role, u.username',
            array_merge([gmdate('Y-m-d H:i:s', time() - 1800)], $params));
        return view('users/index', ['title' => 'Users', 'crumb' => 'Admin', 'rows' => $rows, 'request' => $request]);
    }

    public function create(Request $request): Response
    {
        return view('users/form', ['title' => 'New user', 'crumb' => 'Admin · Users', 'u' => null, 'employees' => $this->unlinkedEmployees()]);
    }

    private function unlinkedEmployees(?int $include = null): array
    {
        return DB::all("SELECT e.id, e.employee_code, e.first_name, e.last_name FROM employees e
                         WHERE e.deleted_at IS NULL AND e.status <> 'left'
                           AND (e.id = ? OR NOT EXISTS (SELECT 1 FROM users u WHERE u.employee_id = e.id AND u.is_active = 1))
                         ORDER BY e.first_name, e.last_name", [$include ?? 0]);
    }

    private function validate(Request $request, ?int $id): array
    {
        $username = strtolower($request->str('username'));
        $email = $request->str('email');
        $role = $request->str('role');
        $employeeId = $request->int('employee_id');
        $errors = [];
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
            $errors['username'] = 'Username must be 3–60 lowercase letters, numbers, dots, dashes or underscores.';
        } elseif (DB::value('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id ?? 0])) {
            $errors['username'] = 'That username is already taken.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($email !== '' && DB::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id ?? 0])) {
            $errors['email'] = 'Another account uses this email.';
        }
        if (!in_array($role, ['admin', 'employee'], true)) {
            $errors['role'] = 'Choose a role.';
        }
        if ($role === 'employee' && !$employeeId) {
            $errors['employee_id'] = 'Employee accounts must be linked to an employee record.';
        }
        if ($employeeId && DB::value('SELECT id FROM users WHERE employee_id = ? AND is_active = 1 AND id <> ?', [$employeeId, $id ?? 0])) {
            $errors['employee_id'] = 'This employee already has an active account.';
        }
        if ($errors) {
            throw new ValidationException('Please correct the highlighted fields.', $errors);
        }
        return ['username' => $username, 'email' => $email ?: null, 'role' => $role, 'employee_id' => $employeeId ?: null];
    }

    public function store(Request $request): Response
    {
        return $this->attempt($request, '/users/create', function () use ($request) {
            $data = $this->validate($request, null);
            $password = (string) ($request->post['password'] ?? '');
            if ($password !== '' && ($p = Auth::passwordProblem($password))) {
                throw new ValidationException($p, ['password' => $p]);
            }
            $id = DB::insert('users', $data + ['is_active' => 1, 'created_by' => $this->userId(),
                'password_hash' => $password !== '' ? Auth::hashPassword($password) : null, 'must_change_password' => $password !== '' ? 1 : 0]);
            AuditLogger::log('user.created', 'user', $id, "Created {$data['role']} account '{$data['username']}'", $data + ['employee_id' => $data['employee_id']]);
            if ($password === '') {
                $link = PasswordTokens::issue($id, 'activation', $this->userId());
                flash('success', 'User created. Activation link (also in the mail log): ' . $link);
            } else {
                flash('success', 'User created with a temporary password. They must change it at first sign-in.');
            }
            return redirect('/users');
        });
    }

    public function edit(Request $request, int $id): Response
    {
        $u = $this->findOr404('SELECT u.*, e.employee_code, e.first_name, e.last_name FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ?', [$id]);
        $sessions = DB::all('SELECT * FROM user_sessions WHERE user_id = ? ORDER BY last_seen_at DESC LIMIT 20', [$id]);
        $logins = DB::all('SELECT * FROM login_attempts WHERE username IN (?, ?) ORDER BY attempted_at DESC LIMIT 15', [$u['username'], (string) $u['email']]);
        return view('users/form', ['title' => 'Edit user', 'crumb' => 'Admin · Users', 'u' => $u, 'employees' => $this->unlinkedEmployees($u['employee_id'] ? (int) $u['employee_id'] : null),
            'sessions' => $sessions, 'logins' => $logins]);
    }

    public function update(Request $request, int $id): Response
    {
        $before = $this->findOr404('SELECT * FROM users WHERE id = ?', [$id]);
        return $this->attempt($request, "/users/$id/edit", function () use ($request, $id, $before) {
            $data = $this->validate($request, $id);
            if ($id === $this->userId() && $data['role'] !== 'admin') {
                throw new ValidationException('You cannot remove your own admin role.');
            }
            DB::update('users', $data, 'id = ?', [$id]);
            if ($before['role'] !== $data['role']) {
                $this->revoke($id, $id === $this->userId());
            }
            AuditLogger::log('user.updated', 'user', $id, "Updated account '{$data['username']}'", AuditLogger::diff($before, $data));
            flash('success', 'User updated.');
            return redirect("/users/$id/edit");
        });
    }

    public function toggle(Request $request, int $id): Response
    {
        $u = $this->findOr404('SELECT * FROM users WHERE id = ?', [$id]);
        return $this->attempt($request, "/users/$id/edit", function () use ($u, $id) {
            if ($id === $this->userId()) {
                throw new ValidationException('You cannot deactivate your own account.');
            }
            $active = $u['is_active'] ? 0 : 1;
            if ($active && $u['employee_id'] && DB::value('SELECT id FROM users WHERE employee_id = ? AND is_active = 1 AND id <> ?', [$u['employee_id'], $id])) {
                throw new ValidationException('This employee already has another active account.');
            }
            if ($active && $u['employee_id'] && DB::value("SELECT id FROM employees WHERE id = ? AND (deleted_at IS NOT NULL OR status = 'left')", [$u['employee_id']])) {
                throw new ValidationException('The linked employee has left or is archived.');
            }
            DB::update('users', ['is_active' => $active], 'id = ?', [$id]);
            if (!$active) {
                $this->revoke($id);
            }
            AuditLogger::log($active ? 'user.activated' : 'user.deactivated', 'user', $id, ($active ? 'Activated' : 'Deactivated') . " account '{$u['username']}'");
            flash('success', $active ? 'Account activated.' : 'Account deactivated and signed out everywhere.');
            return redirect("/users/$id/edit");
        });
    }

    public function resetLink(Request $request, int $id): Response
    {
        $u = $this->findOr404('SELECT * FROM users WHERE id = ?', [$id]);
        $link = PasswordTokens::issue($id, $u['password_hash'] ? 'reset' : 'activation', $this->userId());
        AuditLogger::log('user.reset_link', 'user', $id, "Issued password link for '{$u['username']}'");
        flash('success', 'Password link created (valid 48 hours, also written to the mail log): ' . $link);
        return redirect("/users/$id/edit");
    }

    public function revokeSessions(Request $request, int $id): Response
    {
        $u = $this->findOr404('SELECT * FROM users WHERE id = ?', [$id]);
        $n = $this->revoke($id, $id === $this->userId());
        AuditLogger::log('user.sessions_revoked', 'user', $id, "Revoked $n sessions for '{$u['username']}'");
        flash('success', "$n session(s) signed out.");
        return redirect("/users/$id/edit");
    }

    /** Revokes sessions; keeps the current admin's own session when $keepCurrent. */
    private function revoke(int $userId, bool $keepCurrent = false): int
    {
        $sql = 'user_id = ? AND revoked_at IS NULL';
        $params = [$userId];
        if ($keepCurrent) {
            $sql .= ' AND session_id_hash <> ?';
            $params[] = hash('sha256', \App\Core\Session::id());
        }
        return DB::update('user_sessions', ['revoked_at' => now_utc()], $sql, $params);
    }
}
