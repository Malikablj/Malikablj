<?php

declare(strict_types=1);

/*
 * PIK Marketing Control — front controller.
 * Semua request diarahkan ke file ini oleh public/.htaccess.
 */

// Server bawaan PHP (php -S, untuk development): layani file statis apa adanya
if (PHP_SAPI === 'cli-server') {
    $staticFile = __DIR__ . rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    if ($staticFile !== __FILE__ && is_file($staticFile)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

App\Helpers\App::handleHttp();
