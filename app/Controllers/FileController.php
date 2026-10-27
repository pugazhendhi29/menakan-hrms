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
        $path = FileStore::path((string) $key);
        $mime = str_ends_with((string) $key, '.png') ? 'image/png' : 'image/jpeg';
        $name = 'photo-' . $id . (str_ends_with((string) $key, '.png') ? '.png' : '.jpg');
        // Small copies for avatars (?s=64|128|256): a few KB instead of the full upload.
        $size = $request->int('s');
        if (in_array($size, self::PHOTO_SIZES, true) && ($thumb = self::photoThumb($path, (string) $key, $size))) {
            [$path, $mime, $name] = [$thumb, 'image/webp', 'photo-' . $id . '-' . $size . '.webp'];
        }
        // The URL carries a version of the photo (?v=), so the browser may keep it: a new photo gets a new URL.
        $etag = '"' . substr(sha1($key . '|' . $size), 0, 20) . '"';
        $cache = ['Cache-Control' => 'private, max-age=31536000, immutable', 'Expires' => gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT', 'Pragma' => '', 'ETag' => $etag];
        if (trim((string) ($request->server['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            return new Response('', 304, $cache);
        }
        $r = Response::file($path, $name, $mime, true);
        $r->headers = $cache + $r->headers;
        return $r;
    }

    private const PHOTO_SIZES = [64, 128, 256];

    /** A square WebP copy of the photo, made once and kept in storage/photos/thumbs. Null if it cannot be made. */
    private static function photoThumb(string $path, string $key, int $size): ?string
    {
        $dir = \App\Core\Config::get('app.storage_path') . '/photos/thumbs';
        $thumb = $dir . '/' . sha1($key) . '-' . $size . '.webp';
        if (is_file($thumb)) {
            return $thumb;
        }
        if (!function_exists('imagewebp') || !is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }
        $src = @imagecreatefromstring((string) file_get_contents($path));
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);   // centre crop to a square, as the avatar circles show it
        $out = imagecreatetruecolor($size, $size);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);
        $ok = imagewebp($out, $thumb . '.tmp', 82) && rename($thumb . '.tmp', $thumb);
        imagedestroy($src);
        imagedestroy($out);
        return $ok ? $thumb : null;
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