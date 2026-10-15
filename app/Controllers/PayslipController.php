<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\PayslipMatcher;
use App\Services\PayslipService;

final class PayslipController extends Controller
{
    public function index(Request $request): Response
    {
        $year = $request->int('year') ?? (int) date('Y', strtotime(uk_today() . ' first day of last month'));
        $month = $request->int('month') ?? (int) date('n', strtotime(uk_today() . ' first day of last month'));
        $status = $request->str('status');
        $where = 'p.period_year = ? AND p.period_month = ? AND p.is_current = 1';
        $params = [$year, $month];
        if (in_array($status, ['draft', 'published'], true)) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }
        $rows = DB::all("SELECT p.*, e.employee_code, e.first_name, e.last_name, d.name AS department FROM payslips p JOIN employees e ON e.id = p.employee_id
                          LEFT JOIN departments d ON d.id = e.department_id WHERE $where ORDER BY e.employee_code", $params);
        $missing = DB::all("SELECT e.id, e.employee_code, e.first_name, e.last_name FROM employees e
                             WHERE e.deleted_at IS NULL AND e.start_date <= LAST_DAY(?) AND (e.end_date IS NULL OR e.end_date >= ?)
                               AND NOT EXISTS (SELECT 1 FROM payslips p WHERE p.employee_id = e.id AND p.period_year = ? AND p.period_month = ? AND p.is_current = 1)
                             ORDER BY e.employee_code", [sprintf('%04d-%02d-01', $year, $month), sprintf('%04d-%02d-01', $year, $month), $year, $month]);
        return view('payslips/index', ['title' => 'Payroll & Payslips', 'crumb' => 'Pay', 'rows' => $rows, 'missing' => $missing, 'year' => $year, 'month' => $month, 'status' => $status]);
    }

    public function create(Request $request): Response
    {
        return view('payslips/upload', ['title' => 'Upload payslips', 'crumb' => 'Pay · Payslips', 'employees' => $this->employeeOptions(true), 'employeeId' => $request->int('employee_id'),
            'year' => (int) date('Y', strtotime(uk_today() . ' first day of last month')), 'month' => (int) date('n', strtotime(uk_today() . ' first day of last month'))]);
    }

    public function store(Request $request): Response
    {
        return $this->attempt($request, '/payslips/upload', function () use ($request) {
            $employeeId = (int) $request->int('employee_id', 0);
            if (!DB::value('SELECT id FROM employees WHERE id = ? AND deleted_at IS NULL', [$employeeId])) {
                throw new ValidationException('Choose an employee.', ['employee_id' => 'Choose an employee.']);
            }
            $replacing = PayslipService::hasCurrent($employeeId, (int) $request->int('year', 0), (int) $request->int('month', 0));
            if ($replacing && !$request->bool('confirm_replace')) {
                throw new ValidationException('A payslip already exists for this employee and period. Tick "Replace existing" to archive it and upload the new one.');
            }
            $id = PayslipService::uploadSingle($employeeId, (int) $request->int('year', 0), (int) $request->int('month', 0), $request->file('file'), $this->userId());
            if ($request->bool('publish_now')) {
                PayslipService::publish([$id], $this->userId());
            }
            flash('success', 'Payslip uploaded' . ($request->bool('publish_now') ? ' and published.' : ' as a draft. Publish it to make it visible in the employee portal.'));
            $mail = DB::one('SELECT emailed_to, email_error FROM payslips WHERE id = ?', [$id]);
            if ($mail['email_error']) {
                flash('warning', 'The payslip was not emailed: ' . $mail['email_error'] . '.');
            } else {
                flash('success', 'Payslip emailed to ' . $mail['emailed_to'] . (\App\Services\Mailer::isLogOnly() ? ' (test mode: written to the mail log, not delivered).' : '.'));
            }
            return redirect('/payslips?year=' . $request->int('year') . '&month=' . $request->int('month'));
        });
    }

    public function bulkPrepare(Request $request): Response
    {
        return $this->attempt($request, '/payslips/upload', function () use ($request) {
            $r = PayslipService::prepareBulk($request->fileList('files'), $this->userId());
            return redirect('/payslips/bulk/' . $r['token']);
        });
    }

    public function bulkPreview(Request $request, string $token): Response
    {
        $batch = Session::get('payslip_bulk_' . $token);
        if (!$batch) {
            flash('warning', 'This upload has expired. Please upload the files again.');
            return redirect('/payslips/upload');
        }
        return view('payslips/bulk', ['title' => 'Confirm payslip matches', 'crumb' => 'Pay · Payslips · Bulk upload', 'items' => $batch['items'], 'token' => $token,
            'employees' => $this->employeeOptions(true), 'labels' => PayslipMatcher::LABELS]);
    }

    public function bulkConfirm(Request $request, string $token): Response
    {
        return $this->attempt($request, '/payslips/bulk/' . $token, function () use ($request, $token) {
            $r = PayslipService::confirmBulk($token, $request->arr('rows'), $this->userId());
            flash('success', sprintf('%d payslips saved as drafts and %d emailed to employees, %d skipped.', $r['saved'], $r['emailed'], $r['skipped'])
                . (\App\Services\Mailer::isLogOnly() ? ' Email is in test mode: messages were written to the mail log, not delivered.' : ''));
            foreach ($r['errors'] as $err) {
                flash('danger', $err);
            }
            return redirect('/payslips');
        });
    }

    public function publish(Request $request): Response
    {
        $n = PayslipService::publish($request->arr('ids'), $this->userId());
        flash($n ? 'success' : 'warning', $n ? "$n payslips published. Employees have been notified." : 'No draft payslips were selected.');
        return redirect('/payslips?year=' . $request->int('year') . '&month=' . $request->int('month'));
    }

    public function archive(Request $request, int $id): Response
    {
        $p = $this->findOr404('SELECT * FROM payslips WHERE id = ?', [$id]);
        return $this->attempt($request, '/payslips', function () use ($id, $p) {
            PayslipService::archive($id);
            flash('success', 'Payslip archived. It is no longer visible to the employee.');
            return redirect('/payslips?year=' . $p['period_year'] . '&month=' . $p['period_month']);
        });
    }
}
