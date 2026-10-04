<?php

use App\Http\Controllers\Web\AttendanceController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ClientController;
use App\Http\Controllers\Web\ContractController;
use App\Http\Controllers\Web\CostEstimateController;
use App\Http\Controllers\Web\CostingController;
use App\Http\Controllers\Web\CostRateController;
use App\Http\Controllers\Web\DaftraSettingsController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DemoDataController;
use App\Http\Controllers\Web\DepartmentController;
use App\Http\Controllers\Web\DesignController;
use App\Http\Controllers\Web\EmployeeController;
use App\Http\Controllers\Web\EmployeeCostCardController;
use App\Http\Controllers\Web\ExpenseController;
use App\Http\Controllers\Web\LeaveController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\MachineCostCardController;
use App\Http\Controllers\Web\MaterialController;
use App\Http\Controllers\Web\MaterialCostController;
use App\Http\Controllers\Web\OverheadPoolController;
use App\Http\Controllers\Web\PaymentAccountController;
use App\Http\Controllers\Web\ProductionController;
use App\Http\Controllers\Web\ProjectController;
use App\Http\Controllers\Web\PurchaseController;
use App\Http\Controllers\Web\QuotationController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\StudioController;
use App\Http\Controllers\Web\SupplierController;
use App\Http\Controllers\Web\ThemeController;
use App\Http\Controllers\Web\TreasuryTransferController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

// Language switch works for guests (login page) and signed-in users alike.
Route::post('/locale/{locale}', LocaleController::class)->whereIn('locale', ['ar', 'en'])->name('locale')->middleware('throttle:30,1');
Route::post('/theme', ThemeController::class)->name('theme')->middleware('throttle:30,1');

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
    Route::get('/reports/{key}', [ReportController::class, 'show'])->whereIn('key', ['quotations', 'projects', 'profitability', 'purchases', 'expenses', 'consumption'])->name('reports.show');

    Route::middleware('permission:settings.cost_rates')->prefix('settings/rates')->name('rates.')->group(function () {
        Route::get('/', [CostRateController::class, 'index'])->name('index');
        Route::post('/workers', [CostRateController::class, 'storeWorker'])->name('workers.store');
        Route::post('/workers/{worker}/rates', [CostRateController::class, 'storeWorkerRate'])->name('workers.rate');
        Route::post('/machines', [CostRateController::class, 'storeMachine'])->name('machines.store');
        Route::post('/machines/{machine}/rates', [CostRateController::class, 'storeMachineRate'])->name('machines.rate');
        Route::post('/overhead', [CostRateController::class, 'storeOverhead'])->name('overhead.store');
    });

    // Cost sheet of a quotation line: viewed by costing or quotation staff, edited by quotation staff.
    Route::middleware('permission:costing.view|quotations.manage')->group(function () {
        Route::get('/quotations/{quotation}/lines/{line}/costing', [CostEstimateController::class, 'show'])->name('estimates.show')->whereNumber(['quotation', 'line']);
    });
    Route::middleware('permission:quotations.manage')->prefix('quotations/{quotation}/lines/{line}/costing')->name('estimates.')->whereNumber(['quotation', 'line'])->group(function () {
        Route::post('/', [CostEstimateController::class, 'save'])->name('save');
        Route::post('/materials', [CostEstimateController::class, 'materialStore'])->name('materials.store');
        Route::post('/operations', [CostEstimateController::class, 'operationStore'])->name('operations.store');
        Route::post('/direct', [CostEstimateController::class, 'directStore'])->name('direct.store');
        Route::delete('/{kind}/{id}', [CostEstimateController::class, 'destroyItem'])->name('items.destroy')->whereIn('kind', ['materials', 'operations', 'direct'])->whereNumber('id');
    });

    // Costing engine: cost centres and versioned rates (entered, then approved and frozen).
    Route::middleware('permission:settings.cost_rates|cost_rates.approve')->prefix('costing')->name('costing.')->group(function () {
        Route::get('/', [CostingController::class, 'index'])->name('index');
        Route::get('/centers', [CostingController::class, 'centers'])->name('centers');
        Route::get('/energy', [CostingController::class, 'energy'])->name('energy');
        Route::get('/employee-cards', [EmployeeCostCardController::class, 'index'])->name('employee-cards.index');
        Route::get('/employee-cards/{card}', [EmployeeCostCardController::class, 'show'])->name('employee-cards.show')->whereNumber('card');
        Route::get('/machine-cards', [MachineCostCardController::class, 'index'])->name('machine-cards.index');
        Route::get('/machine-cards/{card}', [MachineCostCardController::class, 'show'])->name('machine-cards.show')->whereNumber('card');
        Route::get('/pools', [OverheadPoolController::class, 'index'])->name('pools.index');
        Route::get('/pools/{pool}', [OverheadPoolController::class, 'show'])->name('pools.show')->whereNumber('pool');
        Route::get('/prices', [MaterialCostController::class, 'prices'])->name('prices');
        Route::get('/waste', [MaterialCostController::class, 'waste'])->name('waste');
        Route::get('/pricing', [CostingController::class, 'pricing'])->name('pricing');
    });
    Route::middleware('permission:settings.cost_rates')->prefix('costing')->name('costing.')->group(function () {
        Route::post('/centers', [CostingController::class, 'centerStore'])->name('centers.store');
        Route::post('/centers/from-spec', [CostingController::class, 'centersFromSpec'])->name('centers.spec');
        Route::put('/centers/{center}', [CostingController::class, 'centerUpdate'])->name('centers.update')->whereNumber('center');
        Route::post('/energy', [CostingController::class, 'energyStore'])->name('energy.store');
        Route::get('/employee-cards/create', [EmployeeCostCardController::class, 'create'])->name('employee-cards.create');
        Route::post('/employee-cards', [EmployeeCostCardController::class, 'store'])->name('employee-cards.store');
        Route::get('/employee-cards/{card}/edit', [EmployeeCostCardController::class, 'edit'])->name('employee-cards.edit')->whereNumber('card');
        Route::put('/employee-cards/{card}', [EmployeeCostCardController::class, 'update'])->name('employee-cards.update')->whereNumber('card');
        Route::post('/employee-cards/{card}/shares', [EmployeeCostCardController::class, 'shareStore'])->name('employee-cards.shares.store')->whereNumber('card');
        Route::delete('/employee-cards/{card}/shares/{share}', [EmployeeCostCardController::class, 'shareDestroy'])->name('employee-cards.shares.destroy')->whereNumber(['card', 'share']);
        Route::get('/machine-cards/create', [MachineCostCardController::class, 'create'])->name('machine-cards.create');
        Route::post('/machine-cards', [MachineCostCardController::class, 'store'])->name('machine-cards.store');
        Route::get('/machine-cards/{card}/edit', [MachineCostCardController::class, 'edit'])->name('machine-cards.edit')->whereNumber('card');
        Route::put('/machine-cards/{card}', [MachineCostCardController::class, 'update'])->name('machine-cards.update')->whereNumber('card');
        Route::post('/machines', [MachineCostCardController::class, 'machineStore'])->name('machines.store');
        Route::put('/machines/{machine}', [MachineCostCardController::class, 'machineUpdate'])->name('machines.update')->whereNumber('machine');
        Route::get('/pools/create', [OverheadPoolController::class, 'create'])->name('pools.create');
        Route::post('/pools', [OverheadPoolController::class, 'store'])->name('pools.store');
        Route::get('/pools/{pool}/edit', [OverheadPoolController::class, 'edit'])->name('pools.edit')->whereNumber('pool');
        Route::put('/pools/{pool}', [OverheadPoolController::class, 'update'])->name('pools.update')->whereNumber('pool');
        Route::post('/pools/{pool}/lines', [OverheadPoolController::class, 'lineStore'])->name('pools.lines.store')->whereNumber('pool');
        Route::delete('/pools/{pool}/lines/{line}', [OverheadPoolController::class, 'lineDestroy'])->name('pools.lines.destroy')->whereNumber(['pool', 'line']);
        Route::post('/prices', [MaterialCostController::class, 'priceStore'])->name('prices.store');
        Route::post('/waste', [MaterialCostController::class, 'wasteStore'])->name('waste.store');
        Route::post('/pricing', [CostingController::class, 'policyStore'])->name('pricing.store');
        Route::post('/vat-rates', [CostingController::class, 'vatStore'])->name('vat.store');
        Route::post('/vat-rates/{rate}/toggle', [CostingController::class, 'vatToggle'])->name('vat.toggle')->whereNumber('rate');
        Route::post('/{type}/{id}/cancel', [CostingController::class, 'cancel'])->name('cancel')->whereNumber('id');
    });
    Route::post('/costing/{type}/{id}/approve', [CostingController::class, 'approve'])->name('costing.approve')
        ->whereNumber('id')->middleware('permission:cost_rates.approve');

    // Manufacturing: inventory, designs and BOM, production orders. Stage order, stock
    // limits and frozen BOMs are enforced by the database; these only gate who may act.
    Route::middleware('permission:inventory.view|inventory.move')->group(function () {
        Route::get('/inventory', [MaterialController::class, 'index'])->name('materials.index');
        Route::get('/inventory/create', [MaterialController::class, 'create'])->name('materials.create')->middleware('permission:inventory.move');
        Route::get('/inventory/{material}', [MaterialController::class, 'show'])->name('materials.show')->whereNumber('material');
    });
    Route::middleware('permission:inventory.move')->group(function () {
        Route::post('/inventory', [MaterialController::class, 'store'])->name('materials.store');
        Route::get('/inventory/{material}/edit', [MaterialController::class, 'edit'])->name('materials.edit')->whereNumber('material');
        Route::put('/inventory/{material}', [MaterialController::class, 'update'])->name('materials.update')->whereNumber('material');
        Route::post('/inventory/{material}/move', [MaterialController::class, 'move'])->name('materials.move')->whereNumber('material');
    });

    Route::middleware('permission:designs.manage|designs.release|bom.manage|production.manage|projects.view')->group(function () {
        Route::get('/designs', [DesignController::class, 'index'])->name('designs.index');
        Route::get('/designs/create', [DesignController::class, 'create'])->name('designs.create')->middleware('permission:designs.manage');
        Route::get('/designs/{design}', [DesignController::class, 'show'])->name('designs.show')->whereNumber('design');
        Route::get('/design-versions/{version}', [DesignController::class, 'version'])->name('design-versions.show')->whereNumber('version');
        // Each action checks its own permission (designs.manage or designs.release).
        Route::post('/design-versions/{version}/{action}', [DesignController::class, 'transition'])->name('design-versions.transition')
            ->whereIn('action', ['submit', 'revise', 'approve', 'reject', 'release']);
    });
    Route::middleware('permission:designs.manage')->group(function () {
        Route::post('/designs', [DesignController::class, 'store'])->name('designs.store');
        Route::post('/designs/{design}/versions', [DesignController::class, 'newVersion'])->name('designs.versions.store')->whereNumber('design');
        Route::put('/design-versions/{version}', [DesignController::class, 'updateVersion'])->name('design-versions.update')->whereNumber('version');
    });
    Route::middleware('permission:bom.manage')->group(function () {
        Route::post('/design-versions/{version}/bom', [DesignController::class, 'bomStore'])->name('design-versions.bom')->whereNumber('version');
        Route::delete('/bom-lines/{line}', [DesignController::class, 'bomDestroy'])->name('bom-lines.destroy')->whereNumber('line');
    });

    Route::middleware('permission:production.manage|production.log_time|quality.inspect|projects.view')->group(function () {
        Route::get('/production', [ProductionController::class, 'index'])->name('production.index');
        Route::get('/production/create', [ProductionController::class, 'create'])->name('production.create')->middleware('permission:production.manage');
        Route::get('/production/{order}', [ProductionController::class, 'show'])->name('production.show')->whereNumber('order');
    });
    Route::post('/production', [ProductionController::class, 'store'])->name('production.store')->middleware('permission:production.manage');
    Route::post('/production/{order}/stage/{to}', [ProductionController::class, 'transition'])->name('production.transition')->whereNumber('order')->middleware('permission:production.manage');
    Route::post('/production/{order}/material', [ProductionController::class, 'material'])->name('production.material')->whereNumber('order')->middleware('permission:inventory.move');
    Route::post('/production/{order}/reserve-all', [ProductionController::class, 'reserveAll'])->name('production.reserve-all')->whereNumber('order')->middleware('permission:inventory.move');
    Route::post('/production/{order}/labor', [ProductionController::class, 'labor'])->name('production.labor')->whereNumber('order')->middleware('permission:production.log_time');
    Route::post('/production/{order}/machine', [ProductionController::class, 'machine'])->name('production.machine')->whereNumber('order')->middleware('permission:production.log_time');
    Route::post('/production/{order}/inspect', [ProductionController::class, 'inspect'])->name('production.inspect')->whereNumber('order')->middleware('permission:quality.inspect');

    // HR: the directory needs hr.view; personal data, documents and changes need hr.manage.
    Route::middleware('permission:hr.view|hr.manage')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create')->middleware('permission:hr.manage');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show')->whereNumber('employee');
        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    });
    Route::middleware('permission:hr.manage')->group(function () {
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit')->whereNumber('employee');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update')->whereNumber('employee');
        Route::post('/employees/{employee}/employment', [EmployeeController::class, 'employment'])->name('employees.employment')->whereNumber('employee');
        Route::post('/employees/{employee}/documents', [EmployeeController::class, 'documentStore'])->name('employees.documents.store')->whereNumber('employee');
        Route::put('/employee-documents/{document}', [EmployeeController::class, 'documentUpdate'])->name('employee-documents.update')->whereNumber('document');
        Route::delete('/employee-documents/{document}', [EmployeeController::class, 'documentDestroy'])->name('employee-documents.destroy')->whereNumber('document');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update')->whereNumber('department');
        Route::post('/jobs', [DepartmentController::class, 'jobStore'])->name('jobs.store');
        Route::put('/jobs/{job}', [DepartmentController::class, 'jobUpdate'])->name('jobs.update')->whereNumber('job');
    });

    Route::middleware('permission:hr.contracts')->group(function () {
        Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::get('/contracts/create', [ContractController::class, 'create'])->name('contracts.create');
        Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');
        Route::get('/contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show')->whereNumber('contract');
        Route::get('/contracts/{contract}/edit', [ContractController::class, 'edit'])->name('contracts.edit')->whereNumber('contract');
        Route::put('/contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update')->whereNumber('contract');
        Route::post('/contracts/{contract}/{action}', [ContractController::class, 'transition'])->name('contracts.transition')->whereNumber('contract')->whereIn('action', ['start', 'close', 'cancel']);
    });
    Route::middleware('permission:hr.attendance')->group(function () {
        Route::get('/attendance', [AttendanceController::class, 'board'])->name('attendance.index');
        Route::get('/attendance/records', [AttendanceController::class, 'index'])->name('attendance.records');
        Route::post('/attendance/toggle/{employee}', [AttendanceController::class, 'toggle'])->name('attendance.toggle')->whereNumber('employee');
        Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');
        Route::put('/attendance/{attendance}', [AttendanceController::class, 'update'])->name('attendance.update')->whereNumber('attendance');
    });
    // Time off: employees request their own; the controller checks who may act for whom.
    Route::get('/leaves', [LeaveController::class, 'index'])->name('leaves.index');
    Route::get('/leaves/create', [LeaveController::class, 'create'])->name('leaves.create');
    Route::post('/leaves', [LeaveController::class, 'store'])->name('leaves.store');
    Route::post('/leaves/{leave}/{action}', [LeaveController::class, 'decide'])->name('leaves.decide')->whereNumber('leave')->whereIn('action', ['approve', 'refuse', 'cancel']);
    Route::middleware('permission:hr.leave_approve')->group(function () {
        Route::get('/leaves/settings', [LeaveController::class, 'settings'])->name('leaves.settings');
        Route::post('/leave-types', [LeaveController::class, 'typeStore'])->name('leave-types.store');
        Route::put('/leave-types/{type}', [LeaveController::class, 'typeUpdate'])->name('leave-types.update')->whereNumber('type');
        Route::post('/leave-allocations', [LeaveController::class, 'allocationStore'])->name('leave-allocations.store');
    });

    // Purchasing & expenses: documents captured once here (approval moves stock / books project cost).
    Route::middleware('permission:purchases.view|purchases.manage')->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create')->middleware('permission:purchases.manage');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show')->whereNumber('supplier');
        Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create')->middleware('permission:purchases.manage');
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show')->whereNumber('purchase');
    });
    Route::middleware('permission:purchases.manage')->group(function () {
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit')->whereNumber('supplier');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update')->whereNumber('supplier');
        Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store')->middleware('throttle:30,1');
        Route::get('/purchases/{purchase}/edit', [PurchaseController::class, 'edit'])->name('purchases.edit')->whereNumber('purchase');
        Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update')->whereNumber('purchase');
        Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->name('purchases.cancel')->whereNumber('purchase');
    });
    Route::post('/purchases/{purchase}/approve', [PurchaseController::class, 'approve'])->name('purchases.approve')->whereNumber('purchase')->middleware('permission:purchases.approve');

    Route::middleware('permission:expenses.view|expenses.manage')->group(function () {
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create')->middleware('permission:expenses.manage');
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show')->whereNumber('expense');
        Route::get('/expense-categories', [ExpenseController::class, 'categories'])->name('expense-categories.index');
    });
    Route::middleware('permission:expenses.manage')->group(function () {
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store')->middleware('throttle:30,1');
        Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit')->whereNumber('expense');
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update')->whereNumber('expense');
        Route::post('/expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->name('expenses.cancel')->whereNumber('expense');
    });
    Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve')->whereNumber('expense')->middleware('permission:expenses.approve');
    Route::middleware('permission:expenses.approve')->group(function () {
        Route::post('/expense-categories', [ExpenseController::class, 'categoryStore'])->name('expense-categories.store');
        Route::put('/expense-categories/{category}', [ExpenseController::class, 'categoryUpdate'])->name('expense-categories.update')->whereNumber('category');
    });

    // Studio: images are streamed only through these routes. A quotation may show one,
    // so anyone who can see quotations can load an image file (not browse the studio).
    // Treasury: cash boxes, bank accounts, employee custody and transfers between them.
    Route::middleware('permission:treasury.view|treasury.manage')->prefix('treasury')->name('treasury.')->group(function () {
        Route::get('/accounts', [PaymentAccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/create', [PaymentAccountController::class, 'create'])->name('accounts.create')->middleware('permission:treasury.manage');
        Route::get('/accounts/{account}', [PaymentAccountController::class, 'show'])->name('accounts.show')->whereNumber('account');
        Route::get('/transfers', [TreasuryTransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/create', [TreasuryTransferController::class, 'create'])->name('transfers.create')->middleware('permission:treasury.manage');
        Route::get('/transfers/{transfer}', [TreasuryTransferController::class, 'show'])->name('transfers.show')->whereNumber('transfer');
    });
    Route::middleware('permission:treasury.manage')->prefix('treasury')->name('treasury.')->group(function () {
        Route::post('/accounts', [PaymentAccountController::class, 'store'])->name('accounts.store');
        Route::get('/accounts/{account}/edit', [PaymentAccountController::class, 'edit'])->name('accounts.edit')->whereNumber('account');
        Route::put('/accounts/{account}', [PaymentAccountController::class, 'update'])->name('accounts.update')->whereNumber('account');
        Route::post('/transfers', [TreasuryTransferController::class, 'store'])->name('transfers.store');
        Route::get('/transfers/{transfer}/edit', [TreasuryTransferController::class, 'edit'])->name('transfers.edit')->whereNumber('transfer');
        Route::put('/transfers/{transfer}', [TreasuryTransferController::class, 'update'])->name('transfers.update')->whereNumber('transfer');
        Route::post('/transfers/{transfer}/cancel', [TreasuryTransferController::class, 'cancel'])->name('transfers.cancel')->whereNumber('transfer');
    });
    Route::post('/treasury/transfers/{transfer}/approve', [TreasuryTransferController::class, 'approve'])->name('treasury.transfers.approve')
        ->whereNumber('transfer')->middleware('permission:treasury.approve');

    Route::middleware('permission:studio.view|quotations.view|purchases.view|expenses.view')->group(function () {
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
        Route::get('/settings/demo', [DemoDataController::class, 'index'])->name('demo.index');
        Route::post('/settings/demo', [DemoDataController::class, 'store'])->name('demo.store');
        Route::delete('/settings/demo', [DemoDataController::class, 'destroy'])->name('demo.destroy');
    });
});
