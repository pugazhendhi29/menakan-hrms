<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @var (callable():void)|null streams the body instead of $body (used for files) */
    private $stream = null;

    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        $location = preg_match('#^https?://#', $to) ? $to : url($to);
        return new self('', $status, ['Location' => $location]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json']);
    }

    /** Streams a private file with no-store caching. $inline shows it in the browser (PDF/images). */
    public static function file(string $path, string $downloadName, string $mime, bool $inline = false): self
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName) ?: 'file';
        $r = new self('', 200, [
            'Content-Type'        => $mime,
            'Content-Length'      => (string) filesize($path),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"',
            'Cache-Control'       => 'no-store, private',
            'Pragma'              => 'no-cache',
        ]);
        $r->stream = static function () use ($path): void {
            readfile($path);
        };
        $r->body = '[file:' . basename($path) . ']';
        return $r;
    }

    public static function download(string $content, string $downloadName, string $mime): self
    {
        return new self($content, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName) . '"',
            'Cache-Control'       => 'no-store, private',
        ]);
    }

    public function isFile(): bool
    {
        return $this->stream !== null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function send(): void
    {
        // Explicit status line: some SAPIs turn unknown codes such as 419 into 500.
        $reasons = [200 => 'OK', 302 => 'Found', 304 => 'Not Modified', 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 419 => 'Page Expired', 500 => 'Internal Server Error'];
        if (!headers_sent()) {
            header(sprintf('%s %d %s', $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1', $this->status, $reasons[$this->status] ?? 'Status'), true, $this->status);
        }
        $security = [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'Permissions-Policy'      => 'geolocation=(), camera=(), microphone=()',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
                . "font-src 'self' https://fonts.gstatic.com; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'",
        ];
        foreach ($security + $this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->stream !== null) {
            if (ob_get_level()) {
                ob_end_clean();
            }
            ($this->stream)();
            return;
        }
        echo $this->body;
    }
}
