<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Self-service pages need an account linked to a (non-deleted) employee record. */
final class RequireEmployee
{
    public function handle(Request $request, callable $next): Response
    {
        if (Auth::employeeId() === null) {
            throw new HttpException(403, 'Your account is not linked to an employee record.');
        }
        return $next($request);
    }
}
