<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\BusinessRuleException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\AuthService;

final class AuthController extends Controller
{
    public function home(Request $request): Response
    {
        return Response::redirect(Auth::check() ? '/dashboard' : '/login');
    }

    public function showLogin(Request $request): Response
    {
        return Response::html(View::render('auth/login', ['title' => 'Masuk'], 'layouts/guest'));
    }

    public function login(Request $request): Response
    {
        $email = $request->string('email');
        try {
            $user = (new AuthService())->attempt($email, (string) ($request->post['password'] ?? ''), $request->ip());
        } catch (BusinessRuleException | ValidationException $e) {
            Session::flash('error', $e instanceof ValidationException ? implode(' ', $e->errors) : $e->getMessage());
            Session::flash('old', ['email' => $email]);

            return Response::redirect('/login');
        }

        Auth::login($user);
        $intended = (string) Session::pull('intended', '/dashboard');
        // Hanya path internal agar tidak terjadi open redirect.
        if (!str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            $intended = '/dashboard';
        }

        return Response::redirect($intended);
    }

    public function logout(Request $request): Response
    {
        (new AuthService())->logout($this->user());
        Auth::logout();
        Session::flash('success', 'Anda telah keluar.');

        return Response::redirect('/login');
    }
}
