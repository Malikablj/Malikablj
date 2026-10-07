<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;

Response::redirect(Auth::check() ? 'dashboard.php' : 'login.php');
