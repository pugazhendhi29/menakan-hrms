<?php

declare(strict_types=1);

namespace Tests\E2E;

/** Minimal browser-like HTTP client for end-to-end tests: cookies, CSRF tokens, uploads. */
final class HttpClient
{
    private string $jar;
    public int $status = 0;
    public string $body = '';
    public array $headers = [];
    public ?string $location = null;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'hrms-jar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function get(string $path): self
    {
        return $this->request('GET', $path);
    }

    /** POSTs a form. Adds the CSRF token from the last page unless $csrf === false. */
    public function post(string $path, array $fields = [], bool|string $csrf = true): self
    {
        if ($csrf === true) {
            $fields['_csrf'] ??= $this->csrf();
        } elseif (is_string($csrf)) {
            $fields['_csrf'] = $csrf;
        }
        return $this->request('POST', $path, $fields);
    }

    public function csrf(): string
    {
        if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->body, $m)) {
            return $m[1];
        }
        // No form on the current page: fetch one that always has a token.
        $saved = [$this->status, $this->body, $this->headers, $this->location];
        $this->get('/account/password');
        if (!preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->body, $m)) {
            $this->get('/login');
            preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->body, $m);
        }
        [$this->status, $this->body, $this->headers, $this->location] = $saved;
        return $m[1] ?? '';
    }

    public function login(string $username, string $password): self
    {
        $this->get('/login');
        return $this->post('/login', ['username' => $username, 'password' => $password]);
    }

    public function follow(): self
    {
        $guard = 0;
        while ($this->location && $guard++ < 5) {
            $loc = $this->location;
            $basePath = (string) parse_url($this->base, PHP_URL_PATH);
            $path = (string) parse_url($loc, PHP_URL_PATH);
            if ($basePath !== '' && str_starts_with($path, $basePath)) {
                $path = substr($path, strlen($basePath));
            }
            $path .= parse_url($loc, PHP_URL_QUERY) ? '?' . parse_url($loc, PHP_URL_QUERY) : '';
            $this->get($path);
        }
        return $this;
    }

    private function request(string $method, string $path, array $fields = []): self
    {
        $ch = curl_init($this->base . $path);
        $hasFile = (bool) array_filter($fields, fn ($v) => $v instanceof \CURLFile || (is_array($v) && array_filter($v, fn ($x) => $x instanceof \CURLFile)));
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'HRMS-E2E-Test',
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($hasFile) {
                $flat = [];
                foreach ($fields as $k => $v) {
                    if (is_array($v)) {
                        foreach (array_values($v) as $i => $x) {
                            $flat[$k . '[' . $i . ']'] = $x;
                        }
                    } else {
                        $flat[$k] = $v;
                    }
                }
                $opts[CURLOPT_POSTFIELDS] = $flat;
            } else {
                $opts[CURLOPT_POSTFIELDS] = http_build_query($fields);
            }
        }
        curl_setopt_array($ch, $opts);
        $raw = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $this->headers = [];
        foreach (explode("\r\n", substr($raw, 0, $hsize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $this->headers[strtolower(trim($k))] = trim($v);
            }
        }
        $this->body = substr($raw, $hsize);
        $this->location = $this->headers['location'] ?? null;
        return $this;
    }

    public function flashes(): string
    {
        preg_match_all('/<div class="alert alert-(\w+)[^"]*"[^>]*>\s*(.*?)\s*(?:<button|<\/div>)/s', $this->body, $m, PREG_SET_ORDER);
        return implode(' | ', array_map(fn ($x) => $x[1] . ': ' . trim(strip_tags(html_entity_decode($x[2]))), array_filter($m, fn ($x) => $x[1] !== 'light')));
    }
}
