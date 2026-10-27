<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Settings;

const UK_TZ = 'Europe/London';

/** HTML-escape for output. Use on every value printed into a view. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    return Config::get('app.base_path', '') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . ltrim($path, '/')) . $v;
}

function view(string $template, array $data = [], ?string $layout = 'layouts/app', int $status = 200): Response
{
    $GLOBALS['__old_input'] = Session::pull('_old', []);
    $GLOBALS['__form_errors'] = Session::pull('_errors', []);
    return Response::html(View::render($template, $data, $layout), $status);
}

function redirect(string $path): Response
{
    return Response::redirect($path);
}

/** Redirects back to a form with errors and the submitted input. */
function back_with_errors(string $path, array $errors, array $input = [], ?string $message = null): Response
{
    unset($input['_csrf'], $input['password'], $input['password_confirmation'], $input['current_password']);
    Session::set('_errors', $errors);
    Session::set('_old', $input);
    Session::flash('danger', $message ?? 'Please correct the highlighted fields.');
    return Response::redirect($path);
}

function csrf_token(): string
{
    return Session::csrfToken();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function flash(string $type, string $message): void
{
    Session::flash($type, $message);
}

function old(string $key, mixed $default = ''): mixed
{
    $old = $GLOBALS['__old_input'] ?? [];
    return array_key_exists($key, $old) ? $old[$key] : $default;
}

function field_error(string $key): string
{
    $errors = $GLOBALS['__form_errors'] ?? [];
    return isset($errors[$key]) ? '<div class="invalid-feedback d-block">' . e($errors[$key]) . '</div>' : '';
}

function is_invalid(string $key): string
{
    return isset(($GLOBALS['__form_errors'] ?? [])[$key]) ? ' is-invalid' : '';
}

function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

/* ---------- Time: stored in UTC, shown and entered in Europe/London ---------- */

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function uk_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(UK_TZ);
}

function utc_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('UTC');
}

/** Current date/time in UK local time. */
function uk_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', uk_tz());
}

function uk_today(): string
{
    return uk_now()->format('Y-m-d');
}

/** Converts a UK-local "Y-m-d H:i[:s]" to a UTC "Y-m-d H:i:s" string for storage. */
function to_utc(string $local): string
{
    return (new DateTimeImmutable($local, uk_tz()))->setTimezone(utc_tz())->format('Y-m-d H:i:s');
}

/** Converts a stored UTC datetime to a UK-local DateTimeImmutable. */
function from_utc(?string $utc): ?DateTimeImmutable
{
    if ($utc === null || $utc === '') {
        return null;
    }
    return (new DateTimeImmutable($utc, utc_tz()))->setTimezone(uk_tz());
}

/** d/m/Y for a Y-m-d date. */
function uk_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
    return $d ? $d->format('d/m/Y') : '—';
}

/** d/m/Y H:i (UK local) for a UTC datetime. */
function uk_datetime(?string $utc): string
{
    $d = from_utc($utc);
    return $d ? $d->format('d/m/Y H:i') : '—';
}

/** H:i (UK local) for a UTC datetime. */
function uk_time(?string $utc): string
{
    $d = from_utc($utc);
    return $d ? $d->format('H:i') : '—';
}

/** Whole minutes shown as decimal hours, e.g. 450 -> "7.50". */
function hours(?int $minutes, int $decimals = 2): string
{
    return $minutes === null ? '—' : number_format($minutes / 60, $decimals);
}

function hours_short(?int $minutes): string
{
    if ($minutes === null) {
        return '—';
    }
    $h = $minutes / 60;
    return (fmod($h, 1.0) == 0.0 ? number_format($h, 1) : rtrim(rtrim(number_format($h, 2), '0'), '.')) . 'h';
}

function days_fmt(float|string|null $days): string
{
    if ($days === null) {
        return '—';
    }
    $d = (float) $days;
    return (fmod($d, 1.0) == 0.0 ? number_format($d, 0) : rtrim(rtrim(number_format($d, 2), '0'), '.'));
}

/** '1 day', '2.5 days' */
function days_label(float|string|null $days): string
{
    return days_fmt($days) . ((float) $days == 1.0 ? ' day' : ' days');
}

function money(?string $amount): string
{
    return $amount === null ? '—' : '£' . number_format((float) $amount, 2);
}

function month_name(int $month): string
{
    return DateTimeImmutable::createFromFormat('!n', (string) $month)->format('F');
}

function employee_name(array $row, string $prefix = ''): string
{
    return trim(($row[$prefix . 'first_name'] ?? '') . ' ' . ($row[$prefix . 'last_name'] ?? ''));
}

/** Employee photo (served through the authorised file route) or initials when there is none. */
/**
 * URL of an employee's photo. The version (?v=) changes with the photo, so browsers can keep it. With $displayPx
 * the server sends a small square copy, sharp on high-resolution screens (twice the display size).
 */
function employee_photo_url(array $e, int $displayPx = 0): string
{
    $url = url('/files/photo/' . $e['id']) . '?v=' . substr(md5((string) $e['photo_key']), 0, 8);
    if ($displayPx > 0) {
        $need = $displayPx * 2;
        $url .= '&s=' . ($need <= 64 ? 64 : ($need <= 128 ? 128 : 256));
    }
    return $url;
}

function employee_avatar(array $e, int $size = 32): string
{
    $style = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . max(10, (int) ($size * 0.36)) . 'px';
    if (!empty($e['photo_key']) && !empty($e['id'])) {
        return '<img class="av av-photo" src="' . e(employee_photo_url($e, $size)) . '" alt="Photo of ' . e(employee_name($e)) . '" style="' . $style . '" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async">';
    }
    return '<span class="av" style="' . $style . '" aria-hidden="true">' . e(strtoupper(mb_substr((string) ($e['first_name'] ?? ''), 0, 1) . mb_substr((string) ($e['last_name'] ?? ''), 0, 1))) . '</span>';
}

/** Bootstrap badge for a status value. */
function status_badge(?string $status): string
{
    $map = [
        'active' => 'success', 'probation' => 'info', 'on_leave' => 'warning', 'career_break' => 'warning', 'suspended' => 'danger', 'left' => 'secondary',
        'verified' => 'success', 'pending' => 'info', 'rejected' => 'danger', 'missing' => 'secondary', 'expiring' => 'warning', 'expired' => 'danger',
        'present' => 'success', 'late' => 'warning', 'absent' => 'danger', 'incomplete' => 'danger',
        'draft' => 'secondary', 'published' => 'success', 'cancelled' => 'secondary', 'archived' => 'secondary',
        'approved' => 'success',
        'ready' => 'success', 'duplicate' => 'warning', 'duplicate_approved' => 'warning', 'error' => 'danger', 'imported' => 'success',
        'skipped' => 'secondary', 'updated' => 'info', 'warning' => 'warning',
        'uploaded' => 'secondary', 'validated' => 'info', 'failed' => 'danger',
        'scheduled' => 'info', 'sending' => 'info', 'unchanged' => 'secondary', 'importing' => 'info', 'sent' => 'success', 'partially_sent' => 'warning', 'logged' => 'info',
    ];
    $s = (string) $status;
    $label = match ($s) {
        'duplicate_approved' => 'Duplicate · approved record exists',
        'career_break' => 'Career Break',
        default => ucfirst(str_replace('_', ' ', $s)),
    };
    return '<span class="badge rounded-pill text-bg-' . ($map[$s] ?? 'light') . '">' . e($label) . '</span>';
}

/** An employee status badge, with the name and colour from Settings > Employee statuses. */
function employee_status_badge(?string $code): string
{
    $s = \App\Services\EmployeeService::status((string) $code);
    $colour = $s && preg_match('/^#[0-9a-fA-F]{6}$/', $s['colour']) ? $s['colour'] : '#64748b';
    return '<span class="badge rounded-pill" style="background:' . $colour . '1f;color:' . $colour . '">' . e($s['name'] ?? ucfirst(str_replace('_', ' ', (string) $code))) . '</span>';
}

/** Pagination metadata for LIMIT/OFFSET queries. */
function paginate(int $total, int $page, int $perPage = 25): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    return ['total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage,
        'from' => $total ? ($page - 1) * $perPage + 1 : 0, 'to' => min($total, $page * $perPage)];
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $v): string
{
    return $v ? ' checked' : '';
}
