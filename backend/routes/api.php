<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\QuotationController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'audit.user'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/clients', [ClientController::class, 'index'])->middleware('permission:clients.view');
    Route::get('/clients/{client}', [ClientController::class, 'show'])->middleware('permission:clients.view');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('permission:clients.manage');
    Route::patch('/clients/{client}', [ClientController::class, 'update'])->middleware('permission:clients.manage');
    Route::post('/clients/{client}/sync-to-daftra', [ClientController::class, 'syncToDaftra'])->middleware(['permission:daftra.sync', 'audit.user:manual']);

    Route::get('/quotations', [QuotationController::class, 'index'])->middleware('permission:quotations.view');
    Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->middleware('permission:quotations.view');
    Route::post('/quotations', [QuotationController::class, 'store'])->middleware('permission:quotations.manage');
    Route::put('/quotations/{quotation}', [QuotationController::class, 'update'])->middleware('permission:quotations.manage');
    Route::post('/quotations/{quotation}/send', [QuotationController::class, 'send'])->middleware('permission:quotations.manage');
    Route::post('/quotations/{quotation}/approve', [QuotationController::class, 'approve'])->middleware('permission:quotations.approve');
    Route::post('/quotations/{quotation}/reject', [QuotationController::class, 'reject'])->middleware('permission:quotations.approve');
    Route::post('/quotations/{quotation}/sync-to-daftra', [QuotationController::class, 'syncToDaftra'])->middleware(['permission:daftra.sync', 'audit.user:manual']);

    Route::get('/projects', [ProjectController::class, 'index'])->middleware('permission:projects.view');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->middleware('permission:projects.view');
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('permission:projects.manage');
    Route::get('/projects/{project}/costing', [ProjectController::class, 'costing'])->middleware('permission:costing.view');
});
