<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\Authenticate;
use App\Middleware\RedirectIfAuthenticated;
use App\Middleware\RequireEmployee;
use App\Middleware\RequireRole;

final class Router
{
    private array $routes = [];
    private array $groupMiddleware = [];

    private const MIDDLEWARE = [
        'auth'     => Authenticate::class,
        'guest'    => RedirectIfAuthenticated::class,
        'role'     => RequireRole::class,
        'employee' => RequireEmployee::class,
    ];

    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /** Applies middleware to every route registered inside $fn. */
    public function group(array $middleware, callable $fn): void
    {
        $previous = $this->groupMiddleware;
        $this->groupMiddleware = array_merge($previous, $middleware);
        $fn($this);
        $this->groupMiddleware = $previous;
    }

    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace_callback('/\{(\w+)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}/', function ($m) {
            $re = $m[2] ?? ($m[1] === 'id' || str_ends_with($m[1], 'Id') ? '\d+' : '[^/]+');
            return '(?P<' . $m[1] . '>' . $re . ')';
        }, $pattern) . '$#';
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $pattern,
            'regex'      => $regex,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    public function routes(): array
    {
        return $this->routes;
    }

    public function dispatch(Request $request): Response
    {
        $methodMismatch = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $methodMismatch = true;
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $this->runPipeline($request, $route);
        }
        throw new HttpException($methodMismatch ? 405 : 404);
    }

    private function runPipeline(Request $request, array $route): Response
    {
        $core = function (Request $req) use ($route): Response {
            [$class, $method] = $route['handler'];
            $args = array_map(fn ($v) => ctype_digit($v) ? (int) $v : $v, array_values($req->params));
            $result = (new $class())->$method($req, ...$args);
            return $result instanceof Response ? $result : Response::html((string) $result);
        };

        $pipeline = array_reduce(
            array_reverse($route['middleware']),
            function (callable $next, string $spec) {
                [$name, $arg] = array_pad(explode(':', $spec, 2), 2, null);
                $class = self::MIDDLEWARE[$name] ?? throw new \RuntimeException("Unknown middleware $name");
                return fn (Request $req) => (new $class())->handle($req, $next, ...($arg !== null ? explode(',', $arg) : []));
            },
            $core
        );
        return $pipeline($request);
    }
}
