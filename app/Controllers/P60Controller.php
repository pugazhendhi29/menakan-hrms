<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\P60Service;

/** P60 documents: admin management (/p60) and the employee's own list (/my/p60). */
final class P60Controller extends Controller
{
    /** Flash the outcome of the automatic email for one P60. */
    private function flashEmail(int $id): void
    {
        $m = DB::one('SELECT emailed_to, email_error FROM p60_documents WHERE id = ?', [$id]);
        if ($m['email_error']) {
            flash('warning', 'The P60 was saved but not emailed: ' . $m['email_error'] . '.');
        } else {
            flash('success', 'P60 emailed to ' . $m['emailed_to'] . (\App\Services\Mailer::isLogOnly() ? ' (test mode: written to the mail log, not delivered).' : '.'));
        }
    }

    public function index(Request $request): Response
    {
        $where = ['1=1'];
        $params = [];
        $status = $request->str('status', 'current');
        if (in_array($status, ['current', 'replaced', 'deleted'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($year = $request->int('tax_year')) {
            $where[] = 'p.tax_year_start = ?';
            $params[] = $year;
        }
        if ($q = $request->str('q')) {
            $where[] = "(p.employee_code LIKE ? OR p.employee_name LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ?)";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $base = 'FROM p60_documents p JOIN employees e ON e.id = p.employee_id WHERE ' . implode(' AND ', $where);
        $p = paginate((int) DB::value("SELECT COUNT(*) $base", $params), $request->int('page', 1), 50);
        $rows = DB::all('SELECT p.*, e.first_name, e.last_name, u.username AS uploaded_by_name FROM p60_documents p JOIN employees e ON e.id = p.employee_id
                          LEFT JOIN users u ON u.id = p.uploaded_by WHERE ' . implode(' AND ', $where) . " ORDER BY p.tax_year_start DESC, p.employee_code
                          LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
        return view('p60/index', ['title' => 'P60 Documents', 'crumb' => 'Pay', 'rows' => $rows, 'p' => $p, 'request' => $request, 'status' => $status,
            'years' => P60Service::yearOptions()]);
    }

    public function create(Request $request): Response
    {
        return view('p60/upload', ['title' => 'Upload P60', 'crumb' => 'Pay · P60 Documents', 'years' => P60Service::yearOptions(),
            'employees' => DB::all('SELECT employee_code, first_name, last_name FROM employees WHERE deleted_at IS NULL ORDER BY employee_code'),
            'employeeCode' => $request->str('employee_code')]);
    }

    public function store(Request $request): Response
    {
        return $this->attempt($request, '/p60/upload', function () use ($request) {
            $id = P60Service::upload($request->str('employee_code'), (int) $request->int('tax_year', 0), $request->file('file'), $request->bool('replace'), $this->userId());
            $row = DB::one('SELECT * FROM p60_documents WHERE id = ?', [$id]);
            flash('success', sprintf('P60 %s saved for %s (%s).', P60Service::label((int) $row['tax_year_start']), $row['employee_name'], $row['employee_code']));
            $this->flashEmail($id);
            return redirect('/p60?q=' . urlencode($row['employee_code']));
        });
    }

    public function bulk(Request $request): Response
    {
        return $this->attempt($request, '/p60/upload', function () use ($request) {
            $results = P60Service::bulk($request->fileList('files'), (int) $request->int('tax_year', 0), $request->bool('replace'), $this->userId());
            $ok = count(array_filter($results, fn ($r) => $r['ok']));
            $mailed = count(array_filter($results, fn ($r) => !empty($r['emailed'])));
            flash($ok ? 'success' : 'warning', sprintf('%d of %d P60 files saved for %s, %d emailed to employees.', $ok, count($results), P60Service::label((int) $request->int('tax_year', 0)), $mailed)
                . (\App\Services\Mailer::isLogOnly() ? ' Email is in test mode (mail log only).' : ''));
            foreach ($results as $r) {
                if (!$r['ok']) {
                    flash('danger', $r['file'] . ': ' . $r['message']);
                } elseif (empty($r['emailed'])) {
                    flash('warning', $r['file'] . ': saved, but not emailed (' . $r['email_error'] . ').');
                }
            }
            return redirect('/p60?tax_year=' . $request->int('tax_year', 0));
        });
    }

    public function replaceForm(Request $request, int $id): Response
    {
        $row = $this->findOr404("SELECT * FROM p60_documents WHERE id = ? AND status = 'current'", [$id]);
        return view('p60/replace', ['title' => 'Replace P60', 'crumb' => 'Pay · P60 Documents', 'row' => $row]);
    }

    public function replace(Request $request, int $id): Response
    {
        $this->findOr404("SELECT id FROM p60_documents WHERE id = ? AND status = 'current'", [$id]);
        return $this->attempt($request, "/p60/$id/replace", function () use ($request, $id) {
            $newId = P60Service::replace($id, $request->file('file'), $this->userId());
            $row = DB::one('SELECT * FROM p60_documents WHERE id = ?', [$newId]);
            flash('success', sprintf('P60 %s for %s replaced. The previous file has been archived.', P60Service::label((int) $row['tax_year_start']), $row['employee_code']));
            $this->flashEmail($newId);
            return redirect('/p60?q=' . urlencode($row['employee_code']));
        });
    }

    public function delete(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM p60_documents WHERE id = ?', [$id]);
        return $this->attempt($request, '/p60', function () use ($request, $id) {
            P60Service::delete($id, $request->str('reason'), $this->userId());
            flash('success', 'P60 deleted. It is no longer visible to the employee; the record is kept for audit.');
            return redirect('/p60');
        });
    }

    /** The signed-in employee's own current P60s only. */
    public function my(Request $request): Response
    {
        $rows = DB::all("SELECT * FROM p60_documents WHERE employee_id = ? AND status = 'current' ORDER BY tax_year_start DESC", [(int) Auth::employeeId()]);
        return view('my/p60', ['title' => 'My P60s', 'rows' => $rows]);
    }
}
