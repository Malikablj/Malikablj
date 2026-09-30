<?php

declare(strict_types=1);

// Built-in server PHP (composer serve): biarkan file statis dilayani langsung.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $file = realpath(__DIR__ . $path);
    if ($path !== '/' && $file !== false && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file)) {
        return false;
    }
}

require __DIR__ . '/../bootstrap/app.php';

$app = new App\Core\App();
$app->handle(App\Core\Request::capture())->send();
