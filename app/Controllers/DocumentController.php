<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\DocumentService;
use App\Services\FileStore;

final class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $where = ['d.is_current = 1', 'e.deleted_at IS NULL'];
        $params = [];
        if ($emp = $request->int('employee_id')) {
            $where[] = 'd.employee_id = ?';
            $params[] = $emp;
        }
        if ($cat = $request->int('category_id')) {
            $where[] = 'd.category_id = ?';
            $params[] = $cat;
        }
        if ($q = $request->str('q')) {
            $where[] = "(e.employee_code LIKE ? OR CONCAT(e.first_name,' ',e.last_name) LIKE ? OR d.title LIKE ?)";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $rows = DB::all('SELECT d.*, dc.name AS category_name, e.employee_code, e.first_name, e.last_name, e.photo_key FROM employee_documents d
                           JOIN document_categories dc ON dc.id = d.category_id JOIN employees e ON e.id = d.employee_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY d.expiry_date IS NULL, d.expiry_date, e.last_name', $params);
        foreach ($rows as &$r) {
            $r['derived'] = DocumentService::status($r);
        }
        unset($r);
        $counts = array_count_values(array_column($rows, 'derived')) + ['pending' => 0, 'verified' => 0, 'expiring' => 0, 'expired' => 0, 'rejected' => 0];
        $counts['all'] = count($rows);
        $status = $request->str('status');
        if ($status === 'expiring') {
            $rows = array_values(array_filter($rows, fn ($r) => in_array($r['derived'], ['expiring', 'expired'], true)));
        } elseif ($status !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['derived'] === $status));
        }
        $p = paginate(count($rows), $request->int('page', 1), 50);
        return view('documents/index', [
            'title' => 'Documents', 'crumb' => 'People', 'rows' => array_slice($rows, $p['offset'], $p['per_page']), 'p' => $p, 'request' => $request, 'counts' => $counts,
            'categories' => DB::all('SELECT id, name FROM document_categories ORDER BY is_active DESC, sort_order, name'), 'employees' => $this->employeeOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        return view('documents/form', ['title' => 'Upload document', 'crumb' => 'People · Documents', 'doc' => null,
            'employees' => $this->employeeOptions(), 'categories' => DB::all('SELECT * FROM document_categories WHERE is_active = 1 ORDER BY sort_order, name'),
            'employeeId' => $request->int('employee_id')]);
    }

    public function store(Request $request): Response
    {
        $employeeId = (int) $request->int('employee_id', 0);
        return $this->attempt($request, '/documents/upload?employee_id=' . $employeeId, function () use ($request, $employeeId) {
            if (!DB::value('SELECT id FROM employees WHERE id = ? AND deleted_at IS NULL', [$employeeId])) {
                throw new ValidationException('Please choose an employee.', ['employee_id' => 'Please choose an employee.']);
            }
            DocumentService::upload($employeeId, $request->only(['category_id', 'title', 'document_number', 'issue_date', 'expiry_date', 'notes']), $request->file('file'), $this->userId());
            flash('success', 'Document uploaded. It is pending verification.');
            return redirect('/employees/' . $employeeId . '?tab=documents');
        });
    }

    // ------------------------------------------------------------------ Bulk upload: upload many files, then map each one

    private const STAGING = 'doc_staging';

    /** Staged files of this session: token => [key, ext, mime, size, name, input?, error?]. */
    private function staged(): array
    {
        return array_filter((array) Session::get(self::STAGING, []), fn ($s) => FileStore::exists($s['key'] ?? ''));
    }

    public function bulk(Request $request): Response
    {
        $employees = DB::all("SELECT id, employee_code, first_name, last_name FROM employees WHERE deleted_at IS NULL AND status <> 'left' ORDER BY first_name, last_name");
        $categories = DB::all('SELECT * FROM document_categories WHERE is_active = 1 ORDER BY sort_order, name');
        $rows = [];
        foreach ($this->staged() as $token => $s) {
            [$emp, $cat] = DocumentService::guess($s['name'], $employees, $categories);
            $rows[$token] = $s + ['input' => ($s['input'] ?? []) + ['employee_id' => $emp, 'category_id' => $cat,
                'title' => ucwords(trim((string) preg_replace('/[_\-.]+/', ' ', pathinfo($s['name'], PATHINFO_FILENAME))))]];
        }
        return view('documents/bulk', ['title' => 'Bulk upload documents', 'crumb' => 'People · Documents', 'rows' => $rows,
            'employees' => $employees, 'categories' => $categories, 'maxFiles' => (int) ini_get('max_file_uploads') ?: 20]);
    }

    public function bulkUpload(Request $request): Response
    {
        $files = $request->fileList('files');
        if (!$files) {
            flash('danger', 'Choose one or more files to upload.');
            return redirect('/documents/bulk');
        }
        $r = DocumentService::stage($files);
        $staged = (array) Session::get(self::STAGING, []);
        foreach ($r['staged'] as $s) {
            $staged['f' . bin2hex(random_bytes(8))] = $s;
        }
        Session::set(self::STAGING, $staged);
        if ($r['staged']) {
            flash('success', sprintf('%d file(s) uploaded. Choose the employee and category for each one, then save.', count($r['staged'])));
        }
        foreach ($r['errors'] as $err) {
            flash('danger', 'Not uploaded: ' . $err);
        }
        return redirect('/documents/bulk');
    }

    /** Saves the mapped files as documents; rows with a problem stay on the page with the reason. */
    public function bulkSave(Request $request): Response
    {
        $staged = $this->staged();
        $saved = $skipped = 0;
        $who = [];
        foreach ($request->arr('rows') as $token => $in) {
            if (!isset($staged[$token]) || !is_array($in)) {
                continue;
            }
            $in = array_map(fn ($v) => is_string($v) ? trim($v) : '', $in + ['employee_id' => '', 'category_id' => '', 'title' => '', 'document_number' => '', 'issue_date' => '', 'expiry_date' => '', 'skip' => '']);
            if ($in['skip'] === '1') {
                $skipped++;
                continue;
            }
            try {
                DocumentService::fromStaged((int) $in['employee_id'], $in, $staged[$token], $this->userId());
                unset($staged[$token]);
                $saved++;
                $who[(int) $in['employee_id']] = true;
            } catch (ValidationException $e) {
                $staged[$token]['input'] = $in;
                $staged[$token]['error'] = $e->errors ? implode(' ', $e->errors) : $e->getMessage();
            }
        }
        Session::set(self::STAGING, $staged);
        if ($saved) {
            flash('success', sprintf('%d document(s) saved for %d employee(s). They are pending verification.', $saved, count($who)));
        }
        if ($staged) {
            flash('warning', sprintf('%d file(s) still to map%s.', count($staged), $skipped ? ' (skipped ones are kept until you remove them)' : ''));
            return redirect('/documents/bulk');
        }
        return redirect('/documents');
    }

    /** Removes staged files: one (token) or all. */
    public function bulkDiscard(Request $request): Response
    {
        $staged = $this->staged();
        $token = $request->str('token');
        $drop = $token !== '' ? array_intersect_key($staged, [$token => true]) : $staged;
        DocumentService::clearStaging(0, array_column($drop, 'key'));
        Session::set(self::STAGING, array_diff_key($staged, $drop));
        flash('info', sprintf('%d file(s) removed.', count($drop)));
        return redirect(count($drop) === count($staged) ? '/documents' : '/documents/bulk');
    }

    /** Preview of a staged file (only the person who uploaded it can see it). */
    public function bulkFile(Request $request, string $token): Response
    {
        $s = $this->staged()[$token] ?? throw new HttpException(404);
        return Response::file(FileStore::path($s['key']), $s['name'], $s['mime'], true);
    }

    private function doc(int $id): array
    {
        return $this->findOr404('SELECT d.*, dc.name AS category_name, e.employee_code, e.first_name, e.last_name FROM employee_documents d
                                   JOIN document_categories dc ON dc.id = d.category_id JOIN employees e ON e.id = d.employee_id WHERE d.id = ? AND d.is_current = 1', [$id]);
    }

    public function replaceForm(Request $request, int $id): Response
    {
        $doc = $this->doc($id);
        return view('documents/form', ['title' => 'Replace document', 'crumb' => 'People · Documents', 'doc' => $doc,
            'employees' => $this->employeeOptions(true), 'categories' => DB::all('SELECT * FROM document_categories WHERE is_active = 1 ORDER BY sort_order, name'),
            'employeeId' => (int) $doc['employee_id']]);
    }

    public function replace(Request $request, int $id): Response
    {
        $doc = $this->doc($id);
        return $this->attempt($request, "/documents/$id/replace", function () use ($request, $doc, $id) {
            $input = $request->only(['title', 'document_number', 'issue_date', 'expiry_date', 'notes']) + ['category_id' => $doc['category_id']];
            DocumentService::upload((int) $doc['employee_id'], $input, $request->file('file'), $this->userId(), $id);
            flash('success', 'Document replaced. The previous version has been archived.');
            return redirect('/employees/' . $doc['employee_id'] . '?tab=documents');
        });
    }

    public function verify(Request $request, int $id): Response
    {
        $doc = $this->doc($id);
        return $this->attempt($request, '/employees/' . $doc['employee_id'] . '?tab=documents', function () use ($request, $doc, $id) {
            DocumentService::verify($id, $request->str('decision'), $this->userId(), $request->str('note') ?: null);
            flash('success', 'Document marked as ' . $request->str('decision') . '.');
            return redirect($request->str('back') === 'list' ? '/documents' : '/employees/' . $doc['employee_id'] . '?tab=documents');
        });
    }

    public function archive(Request $request, int $id): Response
    {
        $doc = $this->doc($id);
        DocumentService::archive($id, $this->userId());
        flash('success', 'Document archived.');
        return redirect('/employees/' . $doc['employee_id'] . '?tab=documents');
    }

    /** Requirement matrix: employees x required categories with derived status. */
    public function matrix(Request $request): Response
    {
        $dept = $request->int('department_id');
        $employees = DB::all("SELECT e.*, d.name AS department FROM employees e LEFT JOIN departments d ON d.id = e.department_id
                               WHERE e.deleted_at IS NULL AND e.status <> 'left'" . ($dept ? ' AND e.department_id = ' . (int) $dept : '') . ' ORDER BY d.name, e.last_name');
        $categories = DB::all('SELECT DISTINCT dc.* FROM document_categories dc JOIN document_requirements r ON r.category_id = dc.id WHERE dc.is_active = 1 ORDER BY dc.sort_order, dc.name');
        $rows = [];
        foreach ($employees as $e) {
            $c = DocumentService::compliance($e);
            $byCat = [];
            foreach ($c['rows'] as $row) {
                $byCat[$row['category']['id']] = $row['status'];
            }
            if ($request->str('only') === 'missing' && !$c['missing'] && !$c['expired']) {
                continue;
            }
            $rows[] = ['e' => $e, 'cells' => $byCat, 'c' => $c];
        }
        return view('documents/matrix', ['title' => 'Document requirement matrix', 'crumb' => 'People · Documents', 'rows' => $rows,
            'categories' => $categories, 'departments' => $this->departments(), 'request' => $request]);
    }
}
