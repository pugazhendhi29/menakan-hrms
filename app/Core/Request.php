<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public array $params = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = Config::get('app.base_path', '');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $path = '/' . trim(rawurldecode($uri), '/');
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $_POST, $_FILES, $_SERVER);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $this->input($key);
        return ($v === null || $v === '' || !is_numeric($v)) ? $default : (int) $v;
    }

    public function bool(string $key): bool
    {
        return in_array($this->input($key), ['1', 'on', 'yes', 'true', 1, true], true);
    }

    public function arr(string $key): array
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->input($k);
        }
        return $out;
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    /** Normalises a multi-file input (name="files[]") into a list of single-file arrays. */
    public function fileList(string $key): array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !is_array($f['name'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
        return $out;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isHttps(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on' || ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    public function fullUrlWithQuery(array $overrides): string
    {
        $q = array_merge($this->query, $overrides);
        $q = array_filter($q, fn ($v) => $v !== null && $v !== '');
        return url($this->path) . ($q ? '?' . http_build_query($q) : '');
    }
}
