<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\ExcelIO;
use App\Services\FileStore;
use App\Services\ShiftImportService;

final class ShiftImportController extends Controller
{
    public function index(Request $request): Response
    {
        $batches = DB::all('SELECT b.*, u.username FROM shift_import_batches b LEFT JOIN users u ON u.id = b.uploaded_by ORDER BY b.id DESC LIMIT 50');
        return view('imports/index', ['title' => 'Shift Import', 'crumb' => 'Time', 'batches' => $batches, 'kind' => 'shift', 'base' => '/shift-import',
            'help' => 'Columns: Employee ID, Date (DD/MM/YYYY), Start, End (HH:MM, UK time), Break Minutes, Shift Type, Location, Status (Draft/Published), Notes.']);
    }

    public function template(Request $request): Response
    {
        $path = BASE_PATH . '/templates/Shift_Import_Template.xlsx';
        if (!is_file($path)) {
            file_put_contents($path, ExcelIO::template('shifts'));
        }
        return Response::file($path, 'Shift_Import_Template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function upload(Request $request): Response
    {
        return $this->attempt($request, '/shift-import', function () use ($request) {
            $file = $request->file('file');
            [$ext] = FileStore::validateUpload($file, ['xlsx'], Config::get('app.upload_limits.imports'));
            $key = FileStore::store($file['tmp_name'], 'imports', $ext);
            $id = ShiftImportService::validateFile(FileStore::path($key), basename((string) $file['name']), $key, $this->userId());
            return redirect('/shift-import/' . $id);
        });
    }

    public function show(Request $request, int $id): Response
    {
        $batch = $this->findOr404('SELECT b.*, u.username FROM shift_import_batches b LEFT JOIN users u ON u.id = b.uploaded_by WHERE b.id = ?', [$id]);
        $filter = $request->str('result');
        $params = [$id];
        $sql = 'SELECT r.*, e.first_name, e.last_name, st.code AS type_code, l.name AS location_name FROM shift_import_rows r
                LEFT JOIN employees e ON e.id = r.employee_id LEFT JOIN shift_types st ON st.id = r.shift_type_id LEFT JOIN locations l ON l.id = r.location_id
                WHERE r.batch_id = ?';
        if ($filter !== '') {
            $sql .= ' AND r.result = ?';
            $params[] = $filter;
        }
        $rows = DB::all($sql . ' ORDER BY r.row_no LIMIT 2000', $params);
        $warnings = (int) DB::value("SELECT COUNT(*) FROM shift_import_rows WHERE batch_id = ? AND result = 'warning'", [$id]);
        return view('imports/shift_preview', ['title' => $batch['status'] === 'validated' ? 'Preview before import' : 'Shift import #' . $id,
            'crumb' => 'Shift Import · batch #' . sprintf('%04d', $id) . ' · ' . $batch['original_filename'], 'batch' => $batch, 'rows' => $rows, 'warnings' => $warnings]);
    }

    public function confirm(Request $request, int $id): Response
    {
        return $this->attempt($request, '/shift-import/' . $id, function () use ($request, $id) {
            $r = ShiftImportService::confirm($id, $request->bool('approve_over_limit'), $request->str('reason'), $this->userId());
            flash('success', sprintf('Import complete: %d shifts added, %d updated, %d skipped.', $r['imported'], $r['updated'], $r['skipped']));
            return redirect('/shift-import/' . $id);
        });
    }

    public function cancel(Request $request, int $id): Response
    {
        return $this->attempt($request, '/shift-import/' . $id, function () use ($id) {
            ShiftImportService::cancel($id);
            flash('success', 'Import cancelled. No shifts were changed.');
            return redirect('/shift-import');
        });
    }

    public function errors(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM shift_import_batches WHERE id = ?', [$id]);
        return Response::download(ShiftImportService::errorReport($id), "shift-import-$id-errors.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
