<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\DB;
use App\Core\ValidationException;
use App\Core\Validator;

final class DocumentService
{
    public const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

    /** Derived status: rejected, expired, expiring, verified or pending. */
    public static function status(array $doc, ?string $today = null): string
    {
        $today ??= uk_today();
        if ($doc['verification'] === 'rejected') {
            return 'rejected';
        }
        if (!empty($doc['expiry_date'])) {
            if ($doc['expiry_date'] < $today) {
                return 'expired';
            }
            $warn = (int) Settings::get('document_expiry_warning_days', 30);
            if ($doc['expiry_date'] <= date('Y-m-d', strtotime($today . " +$warn days"))) {
                return 'expiring';
            }
        }
        return $doc['verification'] === 'verified' ? 'verified' : 'pending';
    }

    /** Categories required for an employee (everyone, their department, or their job title). None for a "documents optional" job title. */
    public static function requiredCategories(array $employee): array
    {
        if (!empty($employee['job_title_id']) && DB::value('SELECT documents_optional FROM job_titles WHERE id = ?', [(int) $employee['job_title_id']])) {
            return [];
        }
        return DB::all(
            'SELECT DISTINCT dc.* FROM document_requirements r JOIN document_categories dc ON dc.id = r.category_id
              WHERE dc.is_active = 1 AND r.is_mandatory = 1
                AND ((r.department_id IS NULL AND r.job_title_id IS NULL)
                     OR (r.department_id = ? AND r.job_title_id IS NULL)
                     OR r.job_title_id = ?)
              ORDER BY dc.sort_order, dc.name',
            [(int) ($employee['department_id'] ?? 0), (int) ($employee['job_title_id'] ?? 0)]
        );
    }

    public static function currentDocuments(int $employeeId): array
    {
        return DB::all(
            'SELECT d.*, dc.name AS category_name, dc.has_expiry, dc.is_sensitive, dc.visible_to_employee
               FROM employee_documents d JOIN document_categories dc ON dc.id = d.category_id
              WHERE d.employee_id = ? AND d.is_current = 1 ORDER BY dc.sort_order, dc.name, d.created_at DESC',
            [$employeeId]
        );
    }

    /**
     * Requirement checklist: one row per required category with the derived status
     * (missing when no current document).
     * @return array{rows: array, required:int, satisfied:int, expiring:int, missing:int, expired:int}
     */
    public static function compliance(array $employee): array
    {
        $required = self::requiredCategories($employee);
        $docs = [];
        foreach (self::currentDocuments((int) $employee['id']) as $d) {
            $docs[$d['category_id']] ??= $d;
        }
        $rows = [];
        $summary = ['required' => count($required), 'satisfied' => 0, 'expiring' => 0, 'missing' => 0, 'expired' => 0];
        foreach ($required as $cat) {
            $doc = $docs[$cat['id']] ?? null;
            $status = $doc ? self::status($doc) : 'missing';
            if (in_array($status, ['verified', 'pending', 'expiring'], true)) {
                $summary['satisfied']++;
            }
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
            $rows[] = ['category' => $cat, 'document' => $doc, 'status' => $status];
        }
        $summary['rows'] = $rows;
        return $summary;
    }

    public static function complianceLabel(array $c): array
    {
        if ($c['required'] === 0) {
            return ['secondary', 'None required'];
        }
        $label = $c['satisfied'] . '/' . $c['required'];
        if ($c['expired'] > 0) {
            return ['danger', $label . ' · ' . $c['expired'] . ' expired'];
        }
        if ($c['missing'] > 0) {
            return ['danger', $label . ' · ' . $c['missing'] . ' missing'];
        }
        if ($c['expiring'] > 0) {
            return ['warning', $label . ' · ' . $c['expiring'] . ' expiring'];
        }
        return ['success', $label];
    }

    /**
     * Uploads a document. If $replaceId is given, the old version is archived (kept, not deleted).
     */
    public static function upload(int $employeeId, array $input, ?array $file, int $userId, ?int $replaceId = null): int
    {
        $cat = self::checkInput($input);
        [$ext, $mime, $size] = FileStore::validateUpload($file, self::ALLOWED_EXT, Config::get('app.upload_limits.documents'));
        $key = FileStore::store($file['tmp_name'], 'documents', $ext);
        return self::record($employeeId, $input, $cat, $key, $mime, $size, (string) $file['name'], $userId, $replaceId);
    }

    /**
     * Saves a file from the bulk upload staging area (see stage()) as a document for the employee.
     * @param array{key:string, ext:string, mime:string, size:int, name:string} $staged
     */
    public static function fromStaged(int $employeeId, array $input, array $staged, int $userId): int
    {
        if (!DB::value('SELECT id FROM employees WHERE id = ? AND deleted_at IS NULL', [$employeeId])) {
            throw new ValidationException('Please choose an employee.', ['employee_id' => 'Please choose an employee.']);
        }
        $cat = self::checkInput($input);
        if (!FileStore::exists($staged['key'])) {
            throw new ValidationException('The uploaded file is no longer available. Please upload it again.');
        }
        $key = FileStore::storeExisting(FileStore::path($staged['key']), 'documents', $staged['ext']);
        return self::record($employeeId, $input, $cat, $key, $staged['mime'], (int) $staged['size'], $staged['name'], $userId, null);
    }

    /**
     * Bulk upload, step 1: validates each file and keeps it in the private staging area until it is mapped to an
     * employee and category. Staged files older than two days are cleared out.
     * @return array{staged: array<int, array>, errors: string[]}
     */
    public static function stage(array $files): array
    {
        self::clearStaging(2 * 86400);
        $staged = $errors = [];
        foreach ($files as $file) {
            try {
                [$ext, $mime, $size] = FileStore::validateUpload($file, self::ALLOWED_EXT, Config::get('app.upload_limits.documents'));
                $staged[] = ['key' => FileStore::store($file['tmp_name'], 'staging', $ext), 'ext' => $ext, 'mime' => $mime, 'size' => $size,
                    'name' => mb_substr(basename((string) $file['name']), 0, 255)];
            } catch (ValidationException $e) {
                $errors[] = basename((string) $file['name']) . ': ' . $e->getMessage();
            }
        }
        return ['staged' => $staged, 'errors' => $errors];
    }

    /** Deletes staged bulk-upload files older than $seconds (0 = all of the given keys only). */
    public static function clearStaging(int $seconds, array $keys = []): void
    {
        foreach ($keys as $k) {
            if (FileStore::exists($k)) {
                @unlink(FileStore::path($k));
            }
        }
        if ($seconds <= 0) {
            return;
        }
        $dir = FileStore::root() . '/staging';
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && $f->getMTime() < time() - $seconds) {
                @unlink($f->getPathname());
            }
        }
    }

    /**
     * Bulk upload, step 2 helper: guesses the employee and category from a file name, e.g.
     * "A005_passport.pdf" or "John Smith - BRP.jpg". Returns [employee id|null, category id|null].
     */
    public static function guess(string $filename, array $employees, array $categories): array
    {
        $norm = fn (string $s) => ' ' . trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($s))) . ' ';
        $name = $norm(pathinfo($filename, PATHINFO_FILENAME));
        $emp = null;
        foreach ($employees as $e) {
            if (str_contains($name, $norm($e['employee_code']))) {
                $emp = (int) $e['id'];
                break;
            }
        }
        if ($emp === null) {
            foreach ($employees as $e) {
                $first = trim($norm($e['first_name']));
                $last = trim($norm($e['last_name']));
                if ($first !== '' && $last !== '' && str_contains($name, " $first ") && str_contains($name, " $last ")) {
                    $emp = (int) $e['id'];
                    break;
                }
            }
        }
        $cat = null;
        $best = 0;
        $aliases = ['e visa' => ['evisa', 'visa'], 'brp' => ['residence permit'], 'p 45' => ['p45'], 'p 60' => ['p60'], 'resume' => ['cv'], 'dbs' => ['disclosure']];
        foreach ($categories as $c) {
            $words = trim($norm(str_replace(' LETTER', '', strtoupper($c['name']))));
            foreach (array_merge([$words], $aliases[$words] ?? []) as $w) {
                if ($w !== '' && str_contains($name, " $w ") && strlen($w) > $best) {
                    $cat = (int) $c['id'];
                    $best = strlen($w);
                }
            }
        }
        return [$emp, $cat];
    }

    /** Validates the document details and returns the (active) category. */
    private static function checkInput(array $input): array
    {
        $v = Validator::make($input, [
            'category_id'     => 'required|integer',
            'title'           => 'required|maxlen:150',
            'document_number' => 'nullable|maxlen:60',
            'issue_date'      => 'nullable|date',
            'expiry_date'     => 'nullable|date|after_or_equal:issue_date',
            'notes'           => 'nullable|maxlen:255',
        ], ['category_id' => 'Category']);
        if ($v->fails()) {
            throw new ValidationException('Please correct the highlighted fields.', $v->errors());
        }
        $cat = DB::one('SELECT * FROM document_categories WHERE id = ? AND is_active = 1', [(int) $input['category_id']]);
        if (!$cat) {
            throw new ValidationException('Please choose a category.', ['category_id' => 'Please choose a category.']);
        }
        if ($cat['has_expiry'] && empty($input['expiry_date'])) {
            throw new ValidationException('This category needs an expiry date.', ['expiry_date' => 'An expiry date is required for ' . $cat['name'] . '.']);
        }
        return $cat;
    }

    /** Inserts the document record for a stored file (archiving the one it replaces, if any). */
    private static function record(int $employeeId, array $input, array $cat, string $key, string $mime, int $size, string $originalName, int $userId, ?int $replaceId): int
    {
        return DB::transaction(function () use ($employeeId, $input, $originalName, $userId, $replaceId, $key, $mime, $size, $cat) {
            $id = DB::insert('employee_documents', [
                'employee_id' => $employeeId, 'category_id' => (int) $cat['id'], 'title' => $input['title'],
                'document_number' => ($input['document_number'] ?? '') ?: null, 'issue_date' => ($input['issue_date'] ?? '') ?: null,
                'expiry_date' => ($input['expiry_date'] ?? '') ?: null, 'original_filename' => mb_substr(basename($originalName), 0, 255),
                'storage_key' => $key, 'mime_type' => $mime, 'size_bytes' => $size, 'sha256' => FileStore::hash($key),
                'verification' => 'pending', 'is_current' => 1, 'notes' => ($input['notes'] ?? '') ?: null, 'uploaded_by' => $userId,
            ]);
            if ($replaceId) {
                $old = DB::one('SELECT * FROM employee_documents WHERE id = ? AND employee_id = ? AND is_current = 1', [$replaceId, $employeeId]);
                if (!$old) {
                    throw new ValidationException('The document being replaced was not found.');
                }
                DB::update('employee_documents', ['is_current' => 0, 'archived_at' => now_utc(), 'replaced_by_id' => $id], 'id = ?', [$replaceId]);
                AuditLogger::log('document.replaced', 'employee_document', $id, sprintf('Replaced %s (document #%d archived)', $cat['name'], $replaceId), ['employee_id' => $employeeId]);
            } else {
                AuditLogger::log('document.uploaded', 'employee_document', $id, sprintf('Uploaded %s for employee #%d', $cat['name'], $employeeId), ['employee_id' => $employeeId]);
            }
            Notifier::toEmployee($employeeId, 'document', 'New document added: ' . $cat['name'], null, '/my/documents');
            return $id;
        });
    }

    public static function verify(int $docId, string $decision, int $userId, ?string $note = null): void
    {
        if (!in_array($decision, ['verified', 'rejected'], true)) {
            throw new ValidationException('Invalid decision.');
        }
        $doc = DB::one('SELECT * FROM employee_documents WHERE id = ? AND is_current = 1', [$docId]);
        if (!$doc) {
            throw new ValidationException('Document not found.');
        }
        DB::update('employee_documents', ['verification' => $decision, 'verified_by' => $userId, 'verified_at' => now_utc(),
            'notes' => $note ?: $doc['notes']], 'id = ?', [$docId]);
        AuditLogger::log('document.' . $decision, 'employee_document', $docId, ucfirst($decision) . ' document "' . $doc['title'] . '"', ['employee_id' => (int) $doc['employee_id']]);
    }

    public static function archive(int $docId, int $userId): void
    {
        $doc = DB::one('SELECT * FROM employee_documents WHERE id = ? AND is_current = 1', [$docId]);
        if (!$doc) {
            throw new ValidationException('Document not found.');
        }
        DB::update('employee_documents', ['is_current' => 0, 'archived_at' => now_utc()], 'id = ?', [$docId]);
        AuditLogger::log('document.archived', 'employee_document', $docId, 'Archived document "' . $doc['title'] . '"', ['employee_id' => (int) $doc['employee_id']]);
    }

    /**
     * Current documents expiring within $days (or already expired when $includeExpired). $skipOptional leaves out
     * employees whose job title has documents optional (e.g. Director), as on the dashboard.
     */
    public static function expiring(int $days, bool $includeExpired = true, int $limit = 500, bool $skipOptional = false): array
    {
        $today = uk_today();
        $until = date('Y-m-d', strtotime("$today +$days days"));
        $where = $includeExpired ? 'd.expiry_date <= ?' : 'd.expiry_date BETWEEN ? AND ?';
        if ($skipOptional) {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM job_titles j WHERE j.id = e.job_title_id AND j.documents_optional = 1)';
        }
        $params = $includeExpired ? [$until] : [$today, $until];
        return DB::all(
            "SELECT d.*, dc.name AS category_name, e.employee_code, e.first_name, e.last_name, e.id AS employee_id, dep.name AS department
               FROM employee_documents d JOIN document_categories dc ON dc.id = d.category_id
               JOIN employees e ON e.id = d.employee_id LEFT JOIN departments dep ON dep.id = e.department_id
              WHERE d.is_current = 1 AND d.expiry_date IS NOT NULL AND e.deleted_at IS NULL AND e.status <> 'left' AND $where
              ORDER BY d.expiry_date LIMIT $limit",
            $params
        );
    }
}
