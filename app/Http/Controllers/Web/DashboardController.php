<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\HomeBoard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Home: the app grid, the getting-started checklist, and what is waiting for this user (App\Support\HomeBoard). */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('dashboard', ['actions' => HomeBoard::actions($request->user()), 'setup' => HomeBoard::setup($request->user())]);
    }
}
