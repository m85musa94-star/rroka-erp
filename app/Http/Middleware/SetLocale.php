<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/** Interface language: the user's saved choice, else the session's, else Arabic. */
class SetLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? $request->session()->get('locale');
        App::setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
