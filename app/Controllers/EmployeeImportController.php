<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\EmployeeService;
use App\Services\EmployeeTransfer;
use App\Services\FileStore;

/** Employee import, keyed on Employee ID: upload, check, preview the changes, then confirm. */
final class EmployeeImportController extends Controller
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function index(Request $request): Response
    {
        $batches = DB::all('SELECT b.*, u.username, b.create_rows + b.update_rows AS valid_rows FROM employee_import_batches b
                             LEFT JOIN users u ON u.id = b.uploaded_by ORDER BY b.id DESC LIMIT 50');
        return view('imports/index', ['title' => 'Employee Import', 'crumb' => 'People · Employees', 'batches' => $batches, 'kind' => 'employee', 'base' => '/employees/import',
            'help' => 'Use the Employees sheet of an export, or the template. Employee ID is the key: an existing Employee ID updates that employee (only filled-in cells; type CLEAR to empty a field), '
                . 'an empty Employee ID adds a new employee with the next ID, and a new ID such as ' . EmployeeService::exampleCode(25) . ' adds a new employee with that ID.']);
    }

    public function template(Request $request): Response
    {
        return Response::download(EmployeeTransfer::template(), 'Employee_Import_Template.xlsx', self::XLSX);
    }

    public function upload(Request $request): Response
    {
        return $this->attempt($request, '/employees/import', function () use ($request) {
            $file = $request->file('file');
            [$ext] = FileStore::validateUpload($file, ['xlsx'], Config::get('app.upload_limits.imports'));
            $key = FileStore::store($file['tmp_name'], 'imports', $ext);
            $id = EmployeeTransfer::validateFile(FileStore::path($key), basename((string) $file['name']), $key, $this->userId());
            return redirect('/employees/import/' . $id);
        });
    }

    public function show(Request $request, int $id): Response
    {
        $batch = $this->findOr404('SELECT b.*, u.username FROM employee_import_batches b LEFT JOIN users u ON u.id = b.uploaded_by WHERE b.id = ?', [$id]);
        $filter = $request->str('result');
        $params = [$id];
        $sql = 'SELECT * FROM employee_import_rows WHERE batch_id = ?';
        if (in_array($filter, ['create', 'update'], true)) {
            $sql .= " AND action = ? AND result IN ('ready','imported')";
            $params[] = $filter;
        } elseif (in_array($filter, ['ready', 'unchanged', 'error', 'imported'], true)) {
            $sql .= ' AND result = ?';
            $params[] = $filter;
        }
        $rows = DB::all($sql . ' ORDER BY row_no LIMIT 2000', $params);
        return view('imports/employee_preview', ['title' => $batch['status'] === 'validated' ? 'Preview before import' : 'Employee import #' . $id,
            'crumb' => 'Employee Import · batch #' . sprintf('%04d', $id) . ' · ' . $batch['original_filename'], 'batch' => $batch, 'rows' => $rows, 'filter' => $filter]);
    }

    public function confirm(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM employee_import_batches WHERE id = ?', [$id]);
        return $this->attempt($request, '/employees/import/' . $id, function () use ($id) {
            $r = EmployeeTransfer::confirm($id, $this->userId());
            $n = fn (int $x) => $x . ($x === 1 ? ' employee' : ' employees');
            flash($r['failed'] ? 'warning' : 'success', sprintf('Import complete: %s added, %d updated%s.', $n($r['created']), $r['updated'],
                $r['failed'] ? ', ' . $r['failed'] . ' could not be saved (see the rows marked Error)' : ''));
            return redirect('/employees/import/' . $id);
        });
    }

    public function cancel(Request $request, int $id): Response
    {
        return $this->attempt($request, '/employees/import/' . $id, function () use ($id) {
            EmployeeTransfer::cancel($id);
            flash('success', 'Import cancelled. No employees were changed.');
            return redirect('/employees/import');
        });
    }

    public function errors(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM employee_import_batches WHERE id = ?', [$id]);
        return Response::download(EmployeeTransfer::errorReport($id), "employee-import-$id-errors.xlsx", self::XLSX);
    }
}
