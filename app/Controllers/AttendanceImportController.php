<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AttendanceImportService;
use App\Services\ExcelIO;
use App\Services\FileStore;

final class AttendanceImportController extends Controller
{
    public function index(Request $request): Response
    {
        $batches = DB::all('SELECT b.*, u.username FROM attendance_import_batches b LEFT JOIN users u ON u.id = b.uploaded_by ORDER BY b.id DESC LIMIT 50');
        return view('imports/index', ['title' => 'Attendance Import', 'crumb' => 'Time', 'batches' => $batches, 'kind' => 'attendance',
            'base' => '/attendance-import', 'help' => 'Columns: Employee ID, Employee Name (optional), Work Date (DD/MM/YYYY), Check In, Check Out (HH:MM, UK time), Break Minutes, Notes.']);
    }

    public function template(Request $request): Response
    {
        $path = BASE_PATH . '/templates/Attendance_Import_Template.xlsx';
        if (!is_file($path)) {
            file_put_contents($path, ExcelIO::template('attendance'));
        }
        return Response::file($path, 'Attendance_Import_Template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function upload(Request $request): Response
    {
        return $this->attempt($request, '/attendance-import', function () use ($request) {
            $file = $request->file('file');
            [$ext] = FileStore::validateUpload($file, ['xlsx'], Config::get('app.upload_limits.imports'));
            $key = FileStore::store($file['tmp_name'], 'imports', $ext);
            $batchId = AttendanceImportService::validateFile(FileStore::path($key), basename((string) $file['name']), $key, $this->userId());
            return redirect('/attendance-import/' . $batchId);
        });
    }

    public function show(Request $request, int $id): Response
    {
        $batch = $this->findOr404('SELECT b.*, u.username FROM attendance_import_batches b LEFT JOIN users u ON u.id = b.uploaded_by WHERE b.id = ?', [$id]);
        $filter = $request->str('result');
        $sql = 'SELECT * FROM attendance_import_rows WHERE batch_id = ?';
        $params = [$id];
        if ($filter !== '') {
            $sql .= ' AND result = ?';
            $params[] = $filter;
        }
        $rows = DB::all($sql . ' ORDER BY row_no LIMIT 2000', $params);
        return view('imports/attendance_preview', ['title' => $batch['status'] === 'validated' ? 'Preview before import' : 'Import batch #' . $id,
            'crumb' => 'Attendance Import · batch #' . sprintf('%04d', $id) . ' · ' . $batch['original_filename'], 'batch' => $batch, 'rows' => $rows, 'filter' => $filter]);
    }

    public function confirm(Request $request, int $id): Response
    {
        return $this->attempt($request, '/attendance-import/' . $id, function () use ($request, $id) {
            $r = AttendanceImportService::confirm($id, $request->str('duplicate_mode', 'skip'), $this->userId());
            flash('success', sprintf('Import complete: %d added, %d updated, %d skipped.', $r['imported'], $r['updated'], $r['skipped']));
            return redirect('/attendance-import/' . $id);
        });
    }

    public function cancel(Request $request, int $id): Response
    {
        return $this->attempt($request, '/attendance-import/' . $id, function () use ($id) {
            AttendanceImportService::cancel($id);
            flash('success', 'Import cancelled. No records were changed.');
            return redirect('/attendance-import');
        });
    }

    public function errors(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM attendance_import_batches WHERE id = ?', [$id]);
        return Response::download(AttendanceImportService::errorReport($id), "attendance-import-$id-errors.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
