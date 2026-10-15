<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Template names always come from code, never from request input. */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderFile($layout, $data + ['content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile('partials/' . $template, $data);
    }

    private static function renderFile(string $template, array $data): string
    {
        $file = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
