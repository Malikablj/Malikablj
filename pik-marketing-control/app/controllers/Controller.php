<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Session;
use App\Helpers\View;

abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected function view(string $view, array $data = [], int $status = 200, ?string $layout = 'layouts/app'): void
    {
        http_response_code($status);
        echo View::render($view, $data, $layout);
    }

    /**
     * Tampilkan ulang form dengan pesan error validasi dan input sebelumnya.
     * @param array<string,mixed> $data
     * @param array<string,string> $errors
     * @param array<string,mixed> $old
     */
    protected function invalid(string $view, array $data, array $errors, array $old, ?string $layout = 'layouts/app'): void
    {
        View::$shared['__old'] = $old;
        $data['errors'] = $errors;
        if ($layout === 'layouts/app') {
            Session::flash('danger', 'Periksa kembali isian yang ditandai.');
        }
        $this->view($view, $data, 422, $layout);
    }

    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function authorize(string $permission): void
    {
        if (!Auth::can($permission)) {
            throw new HttpException(403);
        }
    }

    protected function success(string $message, string $to): never
    {
        Session::flash('success', $message);
        redirect($to);
    }

    protected function failure(string $message, string $to): never
    {
        Session::flash('danger', $message);
        redirect($to);
    }

    /**
     * @template T
     * @param T|null $record
     * @return T
     */
    protected function found(mixed $record): mixed
    {
        if ($record === null || $record === false) {
            throw new HttpException(404);
        }
        return $record;
    }

    protected function page(): int
    {
        return max(1, Request::queryInt('page', 1));
    }

    /**
     * Path "kembali" yang aman dari parameter ?return= (hanya path internal).
     */
    protected function returnTo(string $fallback): string
    {
        $return = Request::input('return');
        if (is_string($return) && preg_match('#^/[A-Za-z0-9_\-/]*(\?[A-Za-z0-9_\-=&%.\[\]]*)?$#', $return) && !str_starts_with($return, '//')) {
            return $return;
        }
        return $fallback;
    }
}
