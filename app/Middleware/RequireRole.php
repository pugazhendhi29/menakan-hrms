<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class RequireRole
{
    public function handle(Request $request, callable $next, string ...$roles): Response
    {
        if (!in_array(Auth::user()['role'] ?? '', $roles, true)) {
            throw new HttpException(403);
        }
        return $next($request);
    }
}
