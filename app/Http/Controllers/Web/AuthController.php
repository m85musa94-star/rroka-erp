<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = $data['email'].'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('محاولات كثيرة. حاول بعد :s ثانية.', ['s' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Auth::attempt($data + ['is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => __('البريد الإلكتروني أو كلمة المرور غير صحيحة.')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        // A language picked on the sign-in page becomes the account's language.
        $locale = $request->session()->get('locale');
        if (in_array($locale, SetLocale::SUPPORTED, true) && $request->user()->locale !== $locale) {
            $request->user()->forceFill(['locale' => $locale])->saveQuietly();
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
