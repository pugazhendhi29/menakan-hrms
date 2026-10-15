<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditLogger;
use App\Services\FileStore;

/**
 * The only way private files leave the server. Admins may open any file; employees only
 * their own current documents and their own published payslips. Anything else is a 404,
 * so the response does not reveal whether another person's file exists.
 */
final class FileController extends Controller
{
    public function document(Request $request, int $id): Response
    {
        if (Auth::isAdmin()) {
            $doc = DB::one('SELECT d.*, dc.is_sensitive FROM employee_documents d JOIN document_categories dc ON dc.id = d.category_id WHERE d.id = ?', [$id]);
        } else {
            $doc = DB::one(
                'SELECT d.*, dc.is_sensitive FROM employee_documents d JOIN document_categories dc ON dc.id = d.category_id
                  WHERE d.id = ? AND d.employee_id = ? AND d.is_current = 1 AND dc.visible_to_employee = 1',
                [$id, Auth::employeeId() ?? 0]
            );
        }
        if (!$doc || !FileStore::exists($doc['storage_key'])) {
            throw new HttpException(404);
        }
        AuditLogger::log('document.downloaded', 'employee_document', $id, 'Opened document "' . $doc['title'] . '"' . ($doc['is_sensitive'] ? ' (sensitive)' : ''), ['employee_id' => (int) $doc['employee_id']]);
        $inline = !$request->bool('download');
        return Response::file(FileStore::path($doc['storage_key']), $doc['original_filename'], $doc['mime_type'], $inline);
    }

    public function payslip(Request $request, int $id): Response
    {
        if (Auth::isAdmin()) {
            $p = DB::one('SELECT p.*, e.employee_code FROM payslips p JOIN employees e ON e.id = p.employee_id WHERE p.id = ?', [$id]);
        } else {
            $p = DB::one(
                "SELECT p.*, e.employee_code FROM payslips p JOIN employees e ON e.id = p.employee_id
                  WHERE p.id = ? AND p.employee_id = ? AND p.status = 'published' AND p.is_current = 1",
                [$id, Auth::employeeId() ?? 0]
            );
        }
        if (!$p || !FileStore::exists($p['storage_key'])) {
            throw new HttpException(404);
        }
        AuditLogger::log('payslip.downloaded', 'payslip', $id, sprintf('Opened payslip %s %s-%02d', $p['employee_code'], $p['period_year'], $p['period_month']), ['employee_id' => (int) $p['employee_id']]);
        $name = sprintf('Payslip_%s_%04d-%02d.pdf', $p['employee_code'], $p['period_year'], $p['period_month']);
        return Response::file(FileStore::path($p['storage_key']), $name, 'application/pdf', !$request->bool('download'));
    }

    /**
     * Employee photo. Admins see any; other signed-in users see photos of current colleagues
     * (as shown on the organisation chart), never those of archived employees or leavers.
     */
    public function photo(Request $request, int $id): Response
    {
        $sql = Auth::isAdmin() || Auth::employeeId() === $id
            ? 'SELECT photo_key FROM employees WHERE id = ?'
            : "SELECT photo_key FROM employees WHERE id = ? AND deleted_at IS NULL AND status <> 'left'";
        $key = DB::value($sql, [$id]);
        if (!$key || !FileStore::exists((string) $key)) {
            throw new HttpException(404);
        }
        $mime = str_ends_with((string) $key, '.png') ? 'image/png' : 'image/jpeg';
        return Response::file(FileStore::path((string) $key), 'photo-' . $id . (str_ends_with((string) $key, '.png') ? '.png' : '.jpg'), $mime, true);
    }

    /**
     * P60 PDF. Admins may open any (including replaced/deleted, for the record); employees only
     * their own current P60. Anything else is a 404. Every view or download is audited.
     */
    public function p60(Request $request, int $id): Response
    {
        $p = Auth::isAdmin()
            ? DB::one('SELECT * FROM p60_documents WHERE id = ?', [$id])
            : DB::one("SELECT * FROM p60_documents WHERE id = ? AND employee_id = ? AND status = 'current'", [$id, Auth::employeeId() ?? 0]);
        if (!$p || !FileStore::exists($p['storage_key'])) {
            throw new HttpException(404);
        }
        $download = $request->bool('download');
        AuditLogger::log($download ? 'p60.downloaded' : 'p60.viewed', 'p60', $id,
            sprintf('%s P60 %s for %s', $download ? 'Downloaded' : 'Viewed', \App\Services\P60Service::label((int) $p['tax_year_start']), $p['employee_code']),
            ['employee_id' => (int) $p['employee_id']]);
        $name = sprintf('P60_%s_%d-%02d.pdf', $p['employee_code'], $p['tax_year_start'], ($p['tax_year_start'] + 1) % 100);
        return Response::file(FileStore::path($p['storage_key']), $name, 'application/pdf', !$download);
    }
}