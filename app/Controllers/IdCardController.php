<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditLogger;
use App\Services\IdCardService;

/** ID cards: admin management on the employee's ID card tab, and the employee's own read-only card (/my/id-card). */
final class IdCardController extends Controller
{
    public function store(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM employees WHERE id = ?', [$id]);
        return $this->attempt($request, "/employees/$id?tab=idcard", function () use ($request, $id) {
            $cardId = IdCardService::create($id, $request->post, $this->userId());
            flash('success', 'ID card ' . DB::value('SELECT card_number FROM id_cards WHERE id = ?', [$cardId]) . ' created. The employee can now see it in the portal.');
            return redirect("/employees/$id?tab=idcard");
        });
    }

    public function update(Request $request, int $id): Response
    {
        $card = $this->findOr404('SELECT * FROM id_cards WHERE id = ?', [$id]);
        $back = '/employees/' . $card['employee_id'] . '?tab=idcard';
        return $this->attempt($request, $back, function () use ($request, $id, $back) {
            IdCardService::update($id, $request->post, $this->userId());
            flash('success', 'ID card updated.');
            return redirect($back);
        });
    }

    public function regenerate(Request $request, int $id): Response
    {
        $card = $this->findOr404('SELECT * FROM id_cards WHERE id = ?', [$id]);
        $back = '/employees/' . $card['employee_id'] . '?tab=idcard';
        return $this->attempt($request, $back, function () use ($request, $id, $back, $card) {
            $newId = IdCardService::regenerate($id, $request->post, $this->userId());
            flash('success', sprintf('New ID card %s issued. %s has been archived.', DB::value('SELECT card_number FROM id_cards WHERE id = ?', [$newId]), $card['card_number']));
            return redirect($back);
        });
    }

    public function revoke(Request $request, int $id): Response
    {
        $card = $this->findOr404('SELECT * FROM id_cards WHERE id = ?', [$id]);
        $back = '/employees/' . $card['employee_id'] . '?tab=idcard';
        return $this->attempt($request, $back, function () use ($request, $id, $back, $card) {
            IdCardService::revoke($id, $request->str('reason'), $this->userId());
            flash('success', 'ID card ' . $card['card_number'] . ' revoked. The employee no longer sees an ID card.');
            return redirect($back);
        });
    }

    /** The signed-in employee's own current card, view only. */
    public function my(Request $request): Response
    {
        $empId = (int) Auth::employeeId();
        $e = DB::one('SELECT e.*, d.name AS department, j.title AS job_title FROM employees e LEFT JOIN departments d ON d.id = e.department_id
                       LEFT JOIN job_titles j ON j.id = e.job_title_id WHERE e.id = ?', [$empId]);
        $card = IdCardService::current($empId);
        if ($card) {
            AuditLogger::log('id_card.viewed', 'id_card', (int) $card['id'], 'Employee viewed their ID card ' . $card['card_number'], ['employee_id' => $empId]);
        }
        return view('my/id_card', ['title' => 'My ID Card', 'e' => $e, 'card' => $card]);
    }
}
