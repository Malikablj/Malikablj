<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;
use Throwable;

/** Render template PHP (app/views) dengan layout. */
final class View
{
    /** @var array<string,mixed> data yang tersedia untuk semua view */
    public static array $shared = [];

    /** @param array<string,mixed> $data */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::capture($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string,mixed> $data */
    public static function partial(string $view, array $data = []): string
    {
        return self::capture($view, $data);
    }

    /** @param array<string,mixed> $data */
    private static function capture(string $view, array $data): string
    {
        $file = self::path($view);
        $vars = array_merge(self::$shared, $data);
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function path(string $view): string
    {
        if (!preg_match('#^[a-z0-9_\-/]+$#i', $view) || str_contains($view, '..')) {
            throw new RuntimeException('Invalid view name: ' . $view);
        }
        $file = APP_ROOT . '/app/views/' . $view . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $view);
        }
        return $file;
    }
}
