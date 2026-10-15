<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * My Email: opens the company webmail. The webmail server sends X-Frame-Options: SAMEORIGIN, so it
 * cannot be shown inside the HRMS; the page opens it in a new tab instead. Signing in happens on the
 * webmail's own login page: no email address or password passes through, or is stored by, the HRMS.
 */
final class WebmailController extends Controller
{
    public function index(Request $request): Response
    {
        $url = (string) Config::get('app.webmail_url', '');
        // Only an https:// address is linked, so a mistyped setting can never become a script link.
        $url = preg_match('#^https://[^\s"<>]+$#i', $url) ? $url : '';
        return view('webmail/index', ['title' => 'My Email', 'crumb' => 'Communication', 'url' => $url,
            'host' => $url ? (string) parse_url($url, PHP_URL_HOST) : '']);
    }
}
