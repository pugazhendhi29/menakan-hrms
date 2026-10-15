<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Core\ValidationException;
use App\Services\P60Service;

final class P60Test extends FeatureTestCase
{
    private function pdf(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'p60');
        file_put_contents($tmp, "%PDF-1.4\n% P60 test\n%%EOF\n");
        return ['name' => 'P60.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }

    private function code(string $key): string
    {
        return (string) DB::value('SELECT employee_code FROM employees WHERE id = ?', [self::$f[$key]]);
    }

    public function testUploadStoresYearNameIdAndPrivateFile(): void
    {
        $id = P60Service::upload(strtolower($this->code('empA')), 2025, $this->pdf(), false, self::$f['admin']);
        $p = DB::one('SELECT * FROM p60_documents WHERE id = ?', [$id]);
        $this->assertSame(2025, (int) $p['tax_year_start']);
        $this->assertSame('Bob Driver', $p['employee_name']);
        $this->assertSame($this->code('empA'), $p['employee_code']);
        $this->assertSame('current', $p['status']);
        $this->assertMatchesRegularExpression('#^p60/\d{4}/\d{2}/[a-f0-9]{32}\.pdf$#', $p['storage_key']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'p60.uploaded' AND entity_id = ?", [$id]));
    }

    public function testDuplicateIsRefusedUnlessReplaced(): void
    {
        $first = P60Service::upload($this->code('empA'), 2024, $this->pdf(), false, self::$f['admin']);
        try {
            P60Service::upload($this->code('empA'), 2024, $this->pdf(), false, self::$f['admin']);
            $this->fail('Duplicate P60 should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already has a P60 for 2024/25', $e->getMessage());
        }
        $second = P60Service::upload($this->code('empA'), 2024, $this->pdf(), true, self::$f['admin']);
        $old = DB::one('SELECT * FROM p60_documents WHERE id = ?', [$first]);
        $this->assertSame('replaced', $old['status']);
        $this->assertNull($old['is_current']);
        $this->assertSame($second, (int) $old['replaced_by_id']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'p60.replaced' AND entity_id = ?", [$second]));
        // The database itself also refuses two current P60s for the same employee and year.
        $this->expectException(\PDOException::class);
        DB::insert('p60_documents', ['employee_id' => self::$f['empA'], 'employee_code' => 'X', 'employee_name' => 'X', 'tax_year_start' => 2024,
            'original_filename' => 'x.pdf', 'storage_key' => 'p60/2026/10/' . str_repeat('b', 32) . '.pdf', 'sha256' => str_repeat('0', 64), 'size_bytes' => 1]);
    }

    public function testUnknownEmployeeAndUnfinishedTaxYearAreRefused(): void
    {
        try {
            P60Service::upload('Z999', 2024, $this->pdf(), false, self::$f['admin']);
            $this->fail('Unknown Employee ID');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('employee_code', $e->errors);
        }
        $this->expectException(ValidationException::class);
        P60Service::upload($this->code('empA'), P60Service::taxYearStartFor(uk_today()), $this->pdf(), false, self::$f['admin']);   // year not ended
    }

    public function testEmployeesSeeOnlyTheirOwnCurrentP60(): void
    {
        $mine = P60Service::upload($this->code('empA'), 2023, $this->pdf(), false, self::$f['admin']);
        $other = P60Service::upload($this->code('empB'), 2023, $this->pdf(), false, self::$f['admin']);
        $this->actingAs(self::$f['userA']);
        $this->assertSame(200, $this->get("/files/p60/$mine")->status);
        $this->assertSame(404, $this->get("/files/p60/$other")->status);
        $this->assertSame(403, $this->get('/p60')->status);
        $page = $this->get('/my/p60');
        $this->assertStringContainsString('2023/24', $page->body);
        $this->assertStringNotContainsString('Upload Date', $page->body);
        $this->assertStringNotContainsString("/files/p60/$other", $page->body);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'p60.viewed' AND entity_id = ?", [$mine]));
        // After deletion the employee loses access; the admin can still open it for the record.
        P60Service::delete($mine, 'Wrong document', self::$f['admin']);
        $this->assertSame(404, $this->get("/files/p60/$mine")->status);
        $this->assertStringContainsString('No P60 is available', $this->get('/my/p60')->body);
        $this->actingAs(self::$f['admin']);
        $this->assertSame(200, $this->get("/files/p60/$mine", ['download' => '1'])->status);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'p60.deleted' AND entity_id = ?", [$mine]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'p60.downloaded' AND entity_id = ?", [$mine]));
    }

    public function testDeleteNeedsAReason(): void
    {
        $id = P60Service::upload($this->code('empB'), 2022, $this->pdf(), false, self::$f['admin']);
        $this->expectException(ValidationException::class);
        P60Service::delete($id, '  ', self::$f['admin']);
    }

    public function testP60IsEmailedToTheEmployeeOnUpload(): void
    {
        DB::update('employees', ['email' => 'bob.driver@example.com'], 'id = ?', [self::$f['empA']]);
        $log = \App\Core\Config::get('app.storage_path') . '/logs/mail.log';
        $before = is_file($log) ? filesize($log) : 0;
        $id = P60Service::upload($this->code('empA'), 2020, $this->pdf(), false, self::$f['admin']);
        $row = DB::one('SELECT * FROM p60_documents WHERE id = ?', [$id]);
        $this->assertSame('bob.driver@example.com', $row['emailed_to']);
        $this->assertNotNull($row['emailed_at']);
        $this->assertNull($row['email_error']);
        $entry = substr((string) file_get_contents($log), $before);
        $this->assertStringContainsString('From: hr@menakantransport.com', $entry);
        $this->assertStringContainsString('To: bob.driver@example.com', $entry);
        $this->assertStringContainsString('Subject: Your P60 for the tax year 2020/21 - Menakan Transport', $entry);
        $this->assertMatchesRegularExpression('/Attachments: P60_A\d{3}_2020-21\.pdf/', $entry);
        $this->assertStringContainsString("Kind regards,\n\nHuman Resource\nMenakan Transport Ltd\nUnit 11, 58 Peregrine Road,\nHainault, Essex, UK\nIG6 3SZ\nMenakan Transport Ltd | Human Resources Department", $entry);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'p60.emailed' AND entity_id = ?", [$id]));
        // A replacement is emailed too.
        $new = P60Service::replace($id, $this->pdf(), self::$f['admin']);
        $this->assertNotNull(DB::value('SELECT emailed_at FROM p60_documents WHERE id = ?', [$new]));
        // No email address: saved, recorded as not sent, nothing thrown.
        DB::update('employees', ['email' => null], 'id = ?', [self::$f['empB']]);
        $id2 = P60Service::upload($this->code('empB'), 2020, $this->pdf(), false, self::$f['admin']);
        $r2 = DB::one('SELECT * FROM p60_documents WHERE id = ?', [$id2]);
        $this->assertSame('current', $r2['status']);
        $this->assertNull($r2['emailed_at']);
        $this->assertStringContainsString('No valid email address', $r2['email_error']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'p60.email_failed' AND entity_id = ?", [$id2]));
    }

    public function testAdminListSearchAndEmptyMessage(): void
    {
        P60Service::upload($this->code('empB'), 2021, $this->pdf(), false, self::$f['admin']);
        $this->actingAs(self::$f['admin']);
        $list = $this->get('/p60', ['q' => 'cara']);
        $this->assertStringNotContainsString('Upload Date', $list->body);
        foreach (['Employee Name', 'Employee ID', 'Tax Year', 'Actions', 'Cara Courier', '2021/22', 'Replace', 'Delete'] as $s) {
            $this->assertStringContainsString($s, $list->body, $s);
        }
        $this->assertStringContainsString('No P60 documents found', $this->get('/p60', ['q' => 'nobody-matches'])->body);
    }
}
