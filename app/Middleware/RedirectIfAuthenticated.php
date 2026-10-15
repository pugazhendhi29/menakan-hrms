<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

final class RedirectIfAuthenticated
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::check()) {
            return Response::redirect('/');
        }
        return $next($request);
    }
}
