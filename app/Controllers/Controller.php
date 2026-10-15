<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

abstract class Controller
{
    protected function user(): array
    {
        return Auth::user() ?? throw new HttpException(403);
    }

    protected function userId(): int
    {
        return (int) $this->user()['id'];
    }

    /** Runs an action; on a business-rule failure, redirects back with the errors and input. */
    protected function attempt(Request $request, string $failPath, callable $action): Response
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            return back_with_errors($failPath, $e->errors, $request->post, $e->getMessage());
        }
    }

    protected function findOr404(string $sql, array $params): array
    {
        return DB::one($sql, $params) ?? throw new HttpException(404);
    }

    protected function employeeOptions(bool $includeLeft = false): array
    {
        return DB::all('SELECT id, employee_code, first_name, last_name FROM employees WHERE deleted_at IS NULL'
            . ($includeLeft ? '' : " AND status <> 'left'") . ' ORDER BY first_name, last_name');
    }

    protected function departments(): array
    {
        return DB::all('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name');
    }

    /** Sends a tabular export as CSV or XLSX. */
    protected function exportTable(string $format, string $name, array $headers, array $rows): Response
    {
        $stamp = gmdate('Ymd-His');
        if ($format === 'csv') {
            return Response::download(\App\Services\ExcelIO::csv($headers, $rows), "$name-$stamp.csv", 'text/csv; charset=UTF-8');
        }
        return Response::download(\App\Services\ExcelIO::build($name, $headers, $rows), "$name-$stamp.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
