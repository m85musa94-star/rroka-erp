<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ClientController;
use App\Http\Controllers\Web\CostRateController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ProjectController;
use App\Http\Controllers\Web\QuotationController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

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

    Route::middleware('permission:settings.cost_rates')->prefix('settings/rates')->name('rates.')->group(function () {
        Route::get('/', [CostRateController::class, 'index'])->name('index');
        Route::post('/workers', [CostRateController::class, 'storeWorker'])->name('workers.store');
        Route::post('/workers/{worker}/rates', [CostRateController::class, 'storeWorkerRate'])->name('workers.rate');
        Route::post('/machines', [CostRateController::class, 'storeMachine'])->name('machines.store');
        Route::post('/machines/{machine}/rates', [CostRateController::class, 'storeMachineRate'])->name('machines.rate');
        Route::post('/overhead', [CostRateController::class, 'storeOverhead'])->name('overhead.store');
    });

    Route::middleware('permission:users.manage')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::resource('roles', RoleController::class)->except(['show', 'destroy']);
    });
});
