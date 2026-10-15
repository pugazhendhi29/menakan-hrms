<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Core\ValidationException;
use App\Services\IdCardService;

final class IdCardTest extends FeatureTestCase
{
    private function code(string $key): string
    {
        return (string) DB::value('SELECT employee_code FROM employees WHERE id = ?', [self::$f[$key]]);
    }

    private function issue(string $key, string $expiry = '2026-12-31'): int
    {
        return IdCardService::create(self::$f[$key], ['issue_date' => '2026-10-01', 'expiry_date' => $expiry], self::$f['admin']);
    }

    public function testAdminCreatesACardThatShowsTheRequiredFields(): void
    {
        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('No ID card has been issued', $this->get('/employees/' . self::$f['empA'], ['tab' => 'idcard'])->body);
        $this->post('/employees/' . self::$f['empA'] . '/id-card', ['issue_date' => '2026-10-01', 'expiry_date' => '2026-12-31']);
        $card = IdCardService::current(self::$f['empA']);
        $this->assertSame($this->code('empA') . '-01', $card['card_number']);
        $res = $this->get('/employees/' . self::$f['empA'], ['tab' => 'idcard']);
        foreach (['BOB DRIVER', 'Van Driver', ': ' . $this->code('empA'), 'Department', ': Drivers', ': 31/12/2026', 'MENAKAN TRANSPORT', 'www.menakantransport.com',
                     'Card ' . $card['card_number'], 'Edit ID card', 'Regenerate', 'Revoke', 'data-print'] as $s) {
            $this->assertStringContainsString($s, $res->body, $s);
        }
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'id_card.created' AND entity_id = ?", [$card['id']]));
        // Only one current card per employee.
        $this->expectException(ValidationException::class);
        $this->issue('empA');
    }

    public function testDatesAreValidated(): void
    {
        try {
            IdCardService::create(self::$f['empA'], ['issue_date' => '2026-10-01', 'expiry_date' => '2026-09-01'], self::$f['admin']);
            $this->fail('Expiry before issue should be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expiry_date', $e->errors);
        }
        $this->expectException(ValidationException::class);
        IdCardService::create(self::$f['empA'], ['issue_date' => '2026-02-30', 'expiry_date' => '2027-01-01'], self::$f['admin']);
    }

    public function testEditRegenerateAndRevoke(): void
    {
        $id = $this->issue('empA');
        IdCardService::update($id, ['issue_date' => '2026-10-01', 'expiry_date' => '2027-03-31', 'status' => 'suspended', 'notes' => 'Investigation'], self::$f['admin']);
        $card = DB::one('SELECT * FROM id_cards WHERE id = ?', [$id]);
        $this->assertSame(['2027-03-31', 'suspended', 'Investigation'], [$card['expiry_date'], $card['status'], $card['notes']]);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'id_card.updated' AND entity_id = ?", [$id]));

        try {
            IdCardService::regenerate($id, ['issue_date' => '2026-10-15', 'expiry_date' => '2027-10-14'], self::$f['admin']);
            $this->fail('Regenerate needs a reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors);
        }
        $newId = IdCardService::regenerate($id, ['issue_date' => '2026-10-15', 'expiry_date' => '2027-10-14', 'reason' => 'Lost card'], self::$f['admin']);
        $old = DB::one('SELECT * FROM id_cards WHERE id = ?', [$id]);
        $new = DB::one('SELECT * FROM id_cards WHERE id = ?', [$newId]);
        $this->assertSame(['replaced', null, 'Lost card'], [$old['status'], $old['is_current'], $old['ended_reason']]);
        $this->assertSame([$this->code('empA') . '-02', 'active', 1], [$new['card_number'], $new['status'], (int) $new['is_current']]);
        $this->assertCount(1, IdCardService::history(self::$f['empA']));

        IdCardService::revoke($newId, 'Left the depot', self::$f['admin']);
        $this->assertNull(IdCardService::current(self::$f['empA']));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'id_card.revoked' AND entity_id = ?", [$newId]));
        // After revoking, a new card can be created and continues the numbering.
        $third = $this->issue('empA');
        $this->assertSame($this->code('empA') . '-03', DB::value('SELECT card_number FROM id_cards WHERE id = ?', [$third]));
    }

    public function testEmployeeSeesOnlyTheirOwnCardReadOnly(): void
    {
        $mine = $this->issue('empA');
        $this->issue('empB');
        $this->actingAs(self::$f['userA']);
        $res = $this->get('/my/id-card');
        $this->assertSame(200, $res->status);
        foreach (['BOB DRIVER', 'Van Driver', ': ' . $this->code('empA'), ': Drivers', 'MENAKAN TRANSPORT', 'View only', 'data-no-save', 'idcard-viewonly'] as $s) {
            $this->assertStringContainsString($s, $res->body, $s);
        }
        // Nothing to change, print or download, and no other employee's details.
        foreach (['<form method="post" action="' . url('/id-cards'), 'data-print', 'Regenerate', 'Revoke', 'Edit ID card', 'download', 'CARA COURIER'] as $s) {
            $this->assertStringNotContainsString($s, $res->body, $s);
        }
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'id_card.viewed' AND entity_id = ?", [$mine]));
        // Every management action and the admin tab are refused for employees, even on their own card.
        $this->assertSame(403, $this->get('/employees/' . self::$f['empA'], ['tab' => 'idcard'])->status);
        $this->assertSame(403, $this->post("/id-cards/$mine", ['issue_date' => '2026-10-01', 'expiry_date' => '2030-01-01', 'status' => 'active'])->status);
        $this->assertSame(403, $this->post("/id-cards/$mine/regenerate", ['reason' => 'x', 'expiry_date' => '2030-01-01'])->status);
        $this->assertSame(403, $this->post("/id-cards/$mine/revoke", ['reason' => 'x'])->status);
        $this->assertSame(403, $this->post('/employees/' . self::$f['empA'] . '/id-card', ['expiry_date' => '2030-01-01'])->status);
        $this->assertSame('2026-12-31', DB::value('SELECT expiry_date FROM id_cards WHERE id = ?', [$mine]));
    }

    public function testEmployeeWithoutACardSeesAMessageAndSuspendedIsMarked(): void
    {
        $this->actingAs(self::$f['userB']);
        $this->assertStringContainsString('No ID card has been issued to you yet', $this->get('/my/id-card')->body);
        $id = $this->issue('empB');
        IdCardService::update($id, ['issue_date' => '2026-10-01', 'expiry_date' => '2026-12-31', 'status' => 'suspended'], self::$f['admin']);
        $body = $this->get('/my/id-card')->body;
        $this->assertStringContainsString('SUSPENDED', $body);
        $this->assertStringContainsString('currently suspended', $body);
    }

    public function testDefaultExpiryComesFromSettingsAndMustBeADate(): void
    {
        \App\Services\Settings::set('id_card_expiry_date', '2027-06-30', self::$f['admin']);
        $this->assertSame('2027-06-30', IdCardService::defaultExpiry());
        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('value="2027-06-30"', $this->get('/employees/' . self::$f['empB'], ['tab' => 'idcard'])->body);
        $settings = array_column(DB::all('SELECT `key`, `value` FROM system_settings'), 'value', 'key');
        $this->post('/settings', ['settings' => ['id_card_expiry_date' => 'not-a-date'] + $settings]);
        $this->assertSame('2027-06-30', DB::value("SELECT `value` FROM system_settings WHERE `key` = 'id_card_expiry_date'"));
    }
}
