<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Core\Request;
use App\Core\Session;
use App\Services\FileStore;

/** Documents › Bulk upload: many files at once, then each one is mapped to an employee and category. */
final class DocumentBulkTest extends FeatureTestCase
{
    private function file(string $name, string $content = "%PDF-1.4\n% test\n%%EOF\n"): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmp, $content);
        return ['name' => $name, 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }

    private function upload(array $files): void
    {
        Session::start();
        $multi = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($files as $f) {
            foreach ($multi as $k => $_) {
                $multi[$k][] = $f[$k];
            }
        }
        $this->kernel->handle(new Request('POST', '/documents/bulk', [], ['_csrf' => Session::csrfToken()], ['files' => $multi], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    public function testUploadManyFilesThenMapEachToAnEmployeeAndCategory(): void
    {
        $code = (string) DB::value('SELECT employee_code FROM employees WHERE id = ?', [self::$f['empA']]);
        $this->actingAs(self::$f['admin']);
        $this->assertStringContainsString('/documents/bulk', $this->get('/documents')->body);
        $this->upload([$this->file("{$code}_right-to-work.pdf"), $this->file('scan 1.pdf'), $this->file('notes.pdf', 'not a pdf')]);

        // Two files are staged (the fake PDF is refused); nothing is on anyone's record yet.
        $staged = Session::get('doc_staging');
        $this->assertCount(2, $staged);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM employee_documents'));

        // The first file is matched from its name: employee ID and category.
        $page = $this->get('/documents/bulk')->body;
        [$first, $second] = array_keys($staged);
        $this->assertMatchesRegularExpression('/name="rows\[' . $first . '\]\[employee_id\]".*?value="' . self::$f['empA'] . '" selected/s', $page);
        $this->assertMatchesRegularExpression('/name="rows\[' . $first . '\]\[category_id\]".*?value="' . self::$f['cat'] . '" selected/s', $page);
        $this->assertSame(200, $this->get('/documents/bulk/file/' . $first)->status);

        // Saving: the mapped file becomes a document; the one without an employee stays with the reason.
        $this->post('/documents/bulk/save', ['rows' => [
            $first => ['employee_id' => (string) self::$f['empA'], 'category_id' => (string) self::$f['cat'], 'title' => 'Right to work check', 'document_number' => '', 'issue_date' => '', 'expiry_date' => ''],
            $second => ['employee_id' => '', 'category_id' => (string) self::$f['cat'], 'title' => 'Scan', 'document_number' => '', 'issue_date' => '', 'expiry_date' => ''],
        ]]);
        $doc = DB::one('SELECT * FROM employee_documents WHERE employee_id = ?', [self::$f['empA']]);
        $this->assertSame(['Right to work check', "{$code}_right-to-work.pdf", 'pending'], [$doc['title'], $doc['original_filename'], $doc['verification']]);
        $this->assertTrue(FileStore::exists($doc['storage_key']));
        $left = Session::get('doc_staging');
        $this->assertSame([$second], array_keys($left));
        $this->assertStringContainsString('choose an employee', $left[$second]['error']);

        // Removing the rest deletes the staged file.
        $key = $left[$second]['key'];
        $this->post('/documents/bulk/discard', []);
        $this->assertSame([], Session::get('doc_staging'));
        $this->assertFalse(FileStore::exists($key));
    }
}
