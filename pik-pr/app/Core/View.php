<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class View
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }

        return self::renderFile($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile('partials/' . $template, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderFile(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/-]+$#', $template)) {
            throw new RuntimeException('Nama template tidak valid: ' . $template);
        }
        $file = BASE_PATH . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template tidak ditemukan: ' . $template);
        }

        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                include $__file;
            })($file, $data);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
