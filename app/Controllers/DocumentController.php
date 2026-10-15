<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\DocumentService;

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
        $rows = DB::all('SELECT d.*, dc.name AS category_name, e.employee_code, e.first_name, e.last_name FROM employee_documents d
                           JOIN document_categories dc ON dc.id = d.category_id JOIN employees e ON e.id = d.employee_id
                          WHERE ' . implode(' AND ', $where) . ' ORDER BY d.expiry_date IS NULL, d.expiry_date, e.last_name', $params);
        foreach ($rows as &$r) {
            $r['derived'] = DocumentService::status($r);
        }
        unset($r);
        $status = $request->str('status');
        if ($status === 'expiring') {
            $rows = array_values(array_filter($rows, fn ($r) => in_array($r['derived'], ['expiring', 'expired'], true)));
        } elseif ($status !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['derived'] === $status));
        }
        $p = paginate(count($rows), $request->int('page', 1), 50);
        return view('documents/index', [
            'title' => 'Documents', 'crumb' => 'People', 'rows' => array_slice($rows, $p['offset'], $p['per_page']), 'p' => $p, 'request' => $request,
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
