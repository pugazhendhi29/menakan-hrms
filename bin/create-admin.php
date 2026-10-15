<?php

/**
 * Creates the first administrator account. The password is typed at the prompt
 * (or piped on stdin) and is never stored in code.
 *
 *   php bin/create-admin.php
 *   php bin/create-admin.php --username=admin --email=hr@example.com   (password still prompted)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Core\Auth;
use App\Core\DB;
use App\Services\AuditLogger;

$opts = getopt('', ['username::', 'email::', 'employee-code::']);

function ask(string $q, bool $secret = false): string
{
    fwrite(STDOUT, $q);
    if ($secret && DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) {
        system('stty -echo');
        $v = fgets(STDIN);
        system('stty echo');
        fwrite(STDOUT, PHP_EOL);
    } else {
        $v = fgets(STDIN);
    }
    // Strip a UTF-8 BOM that some shells (e.g. PowerShell) add when piping input.
    return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $v));
}

$username = $opts['username'] ?? ask('Admin username: ');
$email = $opts['email'] ?? ask('Email (optional): ');
$code = $opts['employee-code'] ?? '';
if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
    fwrite(STDERR, "Username must be 3-60 letters, numbers, dots, dashes or underscores.\n");
    exit(1);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}
if (DB::value('SELECT id FROM users WHERE username = ?', [$username])) {
    fwrite(STDERR, "A user called '$username' already exists.\n");
    exit(1);
}
$employeeId = null;
if ($code !== '') {
    $employeeId = DB::value('SELECT id FROM employees WHERE employee_code = ? AND deleted_at IS NULL', [$code]);
    if (!$employeeId) {
        fwrite(STDERR, "Employee $code not found.\n");
        exit(1);
    }
}
$password = ask('Password (min 10 chars, letters and numbers): ', true);
if ($problem = Auth::passwordProblem($password)) {
    fwrite(STDERR, $problem . "\n");
    exit(1);
}
$confirm = ask('Confirm password: ', true);
if ($confirm !== $password) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$id = DB::insert('users', [
    'username' => $username, 'email' => $email ?: null, 'password_hash' => Auth::hashPassword($password),
    'role' => 'admin', 'is_active' => 1, 'employee_id' => $employeeId ? (int) $employeeId : null,
]);
AuditLogger::log('user.created', 'user', $id, "Admin account '$username' created from the command line", null, null);
fwrite(STDOUT, "Admin '$username' created (user #$id). Sign in at " . App\Core\Config::get('app.url') . "/login\n");
