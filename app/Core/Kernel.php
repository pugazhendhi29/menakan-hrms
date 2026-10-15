<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Handles one request: session, CSRF check on every POST, routing, and error pages.
 * Returns a Response without sending it, so feature tests can call handle() directly.
 */
final class Kernel
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
        $routes = require BASE_PATH . '/routes/web.php';
        $routes($this->router);
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        Auth::reset();
        $GLOBALS['__request_path'] = $request->path;
        try {
            Session::start($request->isHttps());
            if ($request->isPost()) {
                $token = (string) ($request->post['_csrf'] ?? $request->server['HTTP_X_CSRF_TOKEN'] ?? '');
                if ($token === '' || !hash_equals(Session::csrfToken(), $token)) {
                    throw new HttpException(419);
                }
            }
            return $this->router->dispatch($request);
        } catch (HttpException $e) {
            return $this->errorPage($e->status, $e->getMessage());
        } catch (\Throwable $e) {
            error_log(sprintf("[%s] %s in %s:%d\n%s", date('c'), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
            $msg = Config::get('app.debug') ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'An unexpected error occurred. It has been logged for the administrator.';
            return $this->errorPage(500, $msg);
        }
    }

    private function errorPage(int $status, string $message): Response
    {
        $titles = [403 => 'Access denied', 404 => 'Not found', 405 => 'Not allowed', 419 => 'Page expired', 500 => 'Server error'];
        try {
            $body = View::render('errors/error', ['status' => $status, 'title' => $titles[$status] ?? 'Error', 'message' => $message], 'layouts/auth');
        } catch (\Throwable) {
            $body = '<h1>' . $status . '</h1><p>' . htmlspecialchars($message) . '</p>';
        }
        return Response::html($body, $status);
    }
}
