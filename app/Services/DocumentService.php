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

    /** Categories required for an employee (everyone, their department, or their job title). */
    public static function requiredCategories(array $employee): array
    {
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
        [$ext, $mime, $size] = FileStore::validateUpload($file, self::ALLOWED_EXT, Config::get('app.upload_limits.documents'));
        $key = FileStore::store($file['tmp_name'], 'documents', $ext);

        return DB::transaction(function () use ($employeeId, $input, $file, $userId, $replaceId, $key, $mime, $size, $cat) {
            $id = DB::insert('employee_documents', [
                'employee_id' => $employeeId, 'category_id' => (int) $cat['id'], 'title' => $input['title'],
                'document_number' => $input['document_number'] ?: null, 'issue_date' => $input['issue_date'] ?: null,
                'expiry_date' => $input['expiry_date'] ?: null, 'original_filename' => mb_substr(basename((string) $file['name']), 0, 255),
                'storage_key' => $key, 'mime_type' => $mime, 'size_bytes' => $size, 'sha256' => FileStore::hash($key),
                'verification' => 'pending', 'is_current' => 1, 'notes' => $input['notes'] ?: null, 'uploaded_by' => $userId,
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

    /** Current documents expiring within $days (or already expired when $includeExpired). */
    public static function expiring(int $days, bool $includeExpired = true, int $limit = 500): array
    {
        $today = uk_today();
        $until = date('Y-m-d', strtotime("$today +$days days"));
        $where = $includeExpired ? 'd.expiry_date <= ?' : 'd.expiry_date BETWEEN ? AND ?';
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
