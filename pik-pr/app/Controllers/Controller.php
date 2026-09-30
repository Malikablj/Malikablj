<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    /**
     * @param array<string, mixed> $data
     */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(View::render($template, $data), $status);
    }

    protected function redirect(string $to, ?string $success = null): Response
    {
        if ($success !== null) {
            Session::flash('success', $success);
        }

        return Response::redirect($to);
    }

    /**
     * @return array<string, mixed>
     */
    protected function user(): array
    {
        $user = Auth::user();
        if ($user === null) {
            throw new HttpException(401);
        }

        return $user;
    }

    protected function authorize(bool $allowed, string $message = ''): void
    {
        if (!$allowed) {
            throw new HttpException(403, $message);
        }
    }

    /**
     * @template T
     * @param T|null $value
     * @return T
     */
    protected function findOr404(mixed $value): mixed
    {
        if ($value === null || $value === false) {
            throw new HttpException(404);
        }

        return $value;
    }

    protected function page(\App\Core\Request $request): int
    {
        return max(1, (int) ($request->query['page'] ?? 1));
    }
}
