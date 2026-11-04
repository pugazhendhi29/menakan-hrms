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
use App\Services\ShiftService;

final class ShiftImportController extends Controller
{
    public function index(Request $request): Response
    {
        $batches = DB::all('SELECT b.*, u.username FROM shift_import_batches b LEFT JOIN users u ON u.id = b.uploaded_by WHERE b.status = ? ORDER BY b.id DESC', ['validated']);
        return view('imports/index', ['title' => 'Shift Import', 'crumb' => 'Time', 'batches' => $batches, 'kind' => 'shift', 'base' => '/shift-import',
            'icon' => 'calendar-week',
            'columns' => ['Employee ID' => '', 'Date' => 'DD/MM/YYYY', 'Start' => 'HH:MM', 'End' => 'HH:MM', 'Break Minutes' => '', 'Shift Type' => '', 'Location' => '', 'Status' => 'Draft/Published', 'Notes' => 'optional'],
            'help' => 'Times are UK time.',
            'pattern' => ['employees' => $this->employeeOptions(), 'types' => DB::all('SELECT * FROM shift_types WHERE is_active = 1 ORDER BY default_start'),
                'locations' => DB::all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name')]]);
    }

    /** Deletes the chosen employees' shifts in a date range (all, drafts only or published only). */
    public function deleteRange(Request $request): Response
    {
        return $this->attempt($request, '/shift-import#delete-shifts', function () use ($request) {
            $n = ShiftService::deleteRange(['employee_ids' => $request->arr('employee_ids'), 'from' => $request->str('from'), 'to' => $request->str('to'),
                'status' => $request->str('status', 'all')], $this->userId());
            flash($n ? 'success' : 'info', $n ? sprintf('%d shift(s) deleted. Their days are now days off on the attendance record.', $n) : 'No shifts matched, so nothing was deleted.');
            return redirect('/shift-import#delete-shifts');
        });
    }

    /** Creates shifts from a pattern: employees, a date range, weekdays and times (bank holidays are left free). */
    public function pattern(Request $request): Response
    {
        return $this->attempt($request, '/shift-import#pattern', function () use ($request) {
            $in = $request->only(['from', 'to', 'start_time', 'end_time', 'break_minutes', 'shift_type_id', 'location_id', 'status', 'notes']);
            $in['employee_ids'] = $request->arr('employee_ids');
            $in['weekdays'] = $request->arr('weekdays');
            $in['override_reason'] = $request->bool('approve_over_limit') ? $request->str('override_reason') : '';
            $r = ShiftService::createPattern($in, $this->userId());
            $skipped = implode(', ', array_map(fn ($k, $n) => "$n $k", array_keys($r['skipped']), $r['skipped']));
            flash($r['created'] ? 'success' : 'warning', sprintf('%d shift(s) created.', $r['created']) . ($skipped ? " Skipped: $skipped." : ''));
            foreach (array_slice($r['failed'], 0, 5) as $f) {
                flash('danger', 'Not created: ' . $f);
            }
            if (count($r['failed']) > 5) {
                flash('danger', sprintf('…and %d more not created.', count($r['failed']) - 5));
            }
            foreach (array_slice($r['warnings'], 0, 5) as $w) {
                flash('warning', $w);
            }
            if (!$r['created']) {
                return redirect('/shift-import#pattern');
            }
            $first = count($in['employee_ids']) === 1 ? '&employee_id=' . (int) $in['employee_ids'][0] : '';
            return redirect('/shifts?view=month&date=' . $in['from'] . $first);
        });
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
