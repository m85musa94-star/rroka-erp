<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ClientController;
use App\Http\Controllers\Web\CostRateController;
use App\Http\Controllers\Web\DaftraSettingsController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\ProjectController;
use App\Http\Controllers\Web\QuotationController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\StudioController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

// Language switch works for guests (login page) and signed-in users alike.
Route::post('/locale/{locale}', LocaleController::class)->whereIn('locale', ['ar', 'en'])->name('locale')->middleware('throttle:30,1');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth', 'active', 'audit.user'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::middleware('permission:clients.view')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create')->middleware('permission:clients.manage');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    });
    Route::middleware('permission:clients.manage')->group(function () {
        Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
        Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    });
    Route::post('/clients/{client}/sync', [ClientController::class, 'sync'])->name('clients.sync')->middleware(['permission:daftra.sync', 'audit.user:manual']);

    Route::middleware('permission:quotations.view')->group(function () {
        Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('/quotations/create', [QuotationController::class, 'create'])->name('quotations.create')->middleware('permission:quotations.manage');
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
        // Permission per action is checked inside (manage vs approve).
        Route::post('/quotations/{quotation}/{action}', [QuotationController::class, 'transition'])
            ->whereIn('action', ['send', 'revise', 'approve', 'reject', 'cancel'])->name('quotations.transition');
    });
    Route::middleware('permission:quotations.manage')->group(function () {
        Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
        Route::get('/quotations/{quotation}/edit', [QuotationController::class, 'edit'])->name('quotations.edit');
        Route::put('/quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
    });
    Route::post('/quotations/{quotation}/sync', [QuotationController::class, 'sync'])->name('quotations.sync')->middleware(['permission:daftra.sync', 'audit.user:manual']);

    Route::middleware('permission:projects.view')->group(function () {
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create')->middleware('permission:projects.manage');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    });
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store')->middleware('permission:projects.manage');
    Route::post('/projects/{project}/stage/{to}', [ProjectController::class, 'stage'])->name('projects.stage')->middleware('permission:projects.manage');

    // Per-report permissions are checked in the controller.
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{key}', [ReportController::class, 'show'])->whereIn('key', ['quotations', 'projects', 'profitability'])->name('reports.show');

    Route::middleware('permission:settings.cost_rates')->prefix('settings/rates')->name('rates.')->group(function () {
        Route::get('/', [CostRateController::class, 'index'])->name('index');
        Route::post('/workers', [CostRateController::class, 'storeWorker'])->name('workers.store');
        Route::post('/workers/{worker}/rates', [CostRateController::class, 'storeWorkerRate'])->name('workers.rate');
        Route::post('/machines', [CostRateController::class, 'storeMachine'])->name('machines.store');
        Route::post('/machines/{machine}/rates', [CostRateController::class, 'storeMachineRate'])->name('machines.rate');
        Route::post('/overhead', [CostRateController::class, 'storeOverhead'])->name('overhead.store');
    });

    // Studio: images are streamed only through these routes. A quotation may show one,
    // so anyone who can see quotations can load an image file (not browse the studio).
    Route::middleware('permission:studio.view|quotations.view')->group(function () {
        Route::get('/studio/{asset}/file/{variant}', [StudioController::class, 'file'])->name('studio.file')->whereNumber('asset')->whereIn('variant', ['thumb', 'full']);
    });
    Route::middleware('permission:studio.view')->group(function () {
        Route::get('/studio', [StudioController::class, 'index'])->name('studio.index');
        Route::get('/studio/picker', [StudioController::class, 'picker'])->name('studio.picker');
        Route::get('/studio/create', [StudioController::class, 'create'])->name('studio.create')->middleware('permission:studio.manage');
        Route::get('/studio/{asset}', [StudioController::class, 'show'])->name('studio.show')->whereNumber('asset');
    });
    Route::middleware('permission:studio.manage')->group(function () {
        // Files are written to storage inside the request; the upload also needs time for large photos.
        Route::post('/studio', [StudioController::class, 'store'])->name('studio.store')->middleware('throttle:30,1');
        Route::get('/studio/{asset}/edit', [StudioController::class, 'edit'])->name('studio.edit')->whereNumber('asset');
        Route::put('/studio/{asset}', [StudioController::class, 'update'])->name('studio.update')->whereNumber('asset');
        Route::delete('/studio/{asset}', [StudioController::class, 'destroy'])->name('studio.destroy')->whereNumber('asset');
    });

    Route::middleware('permission:daftra.sync')->prefix('settings/daftra')->name('daftra.')->group(function () {
        Route::get('/', [DaftraSettingsController::class, 'index'])->name('index');
        // Calls Daftra over HTTP and writes nothing, so it runs outside the request transaction.
        Route::post('/probe', [DaftraSettingsController::class, 'probe'])->name('probe')->middleware(['audit.user:manual', 'throttle:6,1']);
    });

    Route::middleware('permission:users.manage')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::resource('roles', RoleController::class)->except(['show']);
    });
});
