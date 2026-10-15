<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\ValidationException;

/**
 * Private file storage outside the web root. Files get random names; nothing from the
 * user goes into a path. Uploads are checked by extension, finfo MIME type and magic bytes.
 */
final class FileStore
{
    public const KINDS = [
        'pdf'  => ['mimes' => ['application/pdf'], 'magic' => ['%PDF-']],
        'jpg'  => ['mimes' => ['image/jpeg'], 'magic' => ["\xFF\xD8\xFF"]],
        'jpeg' => ['mimes' => ['image/jpeg'], 'magic' => ["\xFF\xD8\xFF"]],
        'png'  => ['mimes' => ['image/png'], 'magic' => ["\x89PNG\r\n\x1A\n"]],
        'xlsx' => ['mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], 'magic' => ["PK\x03\x04"]],
    ];

    /** Set false only by tests/CLI, where files are not real HTTP uploads. */
    public static bool $requireHttpUpload = PHP_SAPI !== 'cli';

    public static function root(): string
    {
        return Config::get('app.storage_path');
    }

    /**
     * Validates an uploaded file array and returns [ext, mime, size].
     * @param string[] $allowedExt
     */
    public static function validateUpload(?array $file, array $allowedExt, int $maxBytes): array
    {
        if ($file === null) {
            throw new ValidationException('Please choose a file to upload.');
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new ValidationException(match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
                UPLOAD_ERR_PARTIAL => 'The file was only partly uploaded. Please try again.',
                default => 'The upload failed. Please try again.',
            });
        }
        $tmp = (string) $file['tmp_name'];
        if (self::$requireHttpUpload && !is_uploaded_file($tmp)) {
            throw new ValidationException('Invalid upload.');
        }
        $size = (int) filesize($tmp);
        if ($size <= 0) {
            throw new ValidationException('The file is empty.');
        }
        if ($size > $maxBytes) {
            throw new ValidationException(sprintf('The file is too large. The limit is %d MB.', $maxBytes / 1048576));
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true) || !isset(self::KINDS[$ext])) {
            throw new ValidationException('This file type is not allowed. Allowed: ' . strtoupper(implode(', ', $allowedExt)) . '.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        if (!in_array($mime, self::KINDS[$ext]['mimes'], true)) {
            throw new ValidationException('The file content does not match its extension.');
        }
        $head = (string) file_get_contents($tmp, false, null, 0, 16);
        $magicOk = false;
        foreach (self::KINDS[$ext]['magic'] as $magic) {
            if (str_starts_with($head, $magic)) {
                $magicOk = true;
            }
        }
        if (!$magicOk) {
            throw new ValidationException('The file content does not match its extension.');
        }
        if ($ext === 'xlsx') {
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }
        return [$ext, $mime, $size];
    }

    /** Moves a validated upload into storage and returns its storage key, e.g. documents/2026/10/<hex>.pdf */
    public static function store(string $tmpPath, string $area, string $ext): string
    {
        $key = sprintf('%s/%s/%s.%s', $area, gmdate('Y/m'), bin2hex(random_bytes(16)), $ext);
        $dest = self::path($key);
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0750, true);
        }
        $ok = self::$requireHttpUpload ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
        if (!$ok) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }
        return $key;
    }

    /** Stores a file already on disk (e.g. a temp file from a bulk upload). */
    public static function storeExisting(string $sourcePath, string $area, string $ext): string
    {
        $key = sprintf('%s/%s/%s.%s', $area, gmdate('Y/m'), bin2hex(random_bytes(16)), $ext);
        $dest = self::path($key);
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0750, true);
        }
        if (!rename($sourcePath, $dest) && !copy($sourcePath, $dest)) {
            throw new \RuntimeException('Could not store the file.');
        }
        return $key;
    }

    public static function path(string $key): string
    {
        if (!preg_match('#^[a-z0-9_]+/\d{4}/\d{2}/[a-f0-9]{32}\.[a-z]{3,4}$#', $key)) {
            throw new \RuntimeException('Invalid storage key.');
        }
        return self::root() . '/' . $key;
    }

    public static function exists(string $key): bool
    {
        try {
            return is_file(self::path($key));
        } catch (\RuntimeException) {
            return false;
        }
    }

    public static function hash(string $key): string
    {
        return hash_file('sha256', self::path($key));
    }
}
