<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $mode = $request->validate(['mode' => ['required', Rule::in(Theme::MODES)]])['mode'];
        $request->session()->put('theme', $mode);
        if ($user = $request->user()) {
            $user->forceFill(['theme' => $mode])->saveQuietly();
        }

        return back();
    }
}
