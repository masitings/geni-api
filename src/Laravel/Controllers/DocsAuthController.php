<?php

declare(strict_types=1);

namespace Geni\Laravel\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Handles custom form-based authentication for Geni API documentation.
 *
 * Completely isolated from application user models and guards.
 */
final class DocsAuthController extends Controller
{
    /**
     * Show the custom docs login form.
     */
    public function loginForm(Request $request): Response|RedirectResponse
    {
        $uiPath = config('geni.docs_ui_path', 'docs/api');

        if ($request->session()->get('geni_docs_authenticated') === true) {
            return redirect(url($uiPath));
        }

        $title = (config('geni.title') ?? config('app.name', 'Laravel API')).' - Docs Login';
        $actionUrl = url($uiPath.'/login');

        $html = view('geni::docs-login', [
            'title' => $title,
            'actionUrl' => $actionUrl,
        ])->render();

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    /**
     * Authenticate docs credentials securely.
     */
    public function login(Request $request): RedirectResponse
    {
        $uiPath = config('geni.docs_ui_path', 'docs/api');

        $throttleKey = 'geni_docs_login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['credentials' => 'Too many login attempts. Please try again in a minute.']);
        }

        $expectedUser = (string) config('geni.docs_auth.username');
        $expectedPass = (string) config('geni.docs_auth.password');

        $user = (string) $request->input('username', '');
        $pass = (string) $request->input('password', '');

        if ($user !== '' && $pass !== '' && hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass)) {
            RateLimiter::clear($throttleKey);
            $request->session()->put('geni_docs_authenticated', true);
            $request->session()->regenerate();

            return redirect()->intended(url($uiPath));
        }

        RateLimiter::hit($throttleKey, 60);

        return back()
            ->withInput($request->only('username'))
            ->withErrors(['credentials' => 'Invalid docs username or password.']);
    }

    /**
     * Log out of documentation session.
     */
    public function logout(Request $request): RedirectResponse
    {
        $uiPath = config('geni.docs_ui_path', 'docs/api');

        $request->session()->forget('geni_docs_authenticated');

        return redirect(url($uiPath.'/login'));
    }
}
