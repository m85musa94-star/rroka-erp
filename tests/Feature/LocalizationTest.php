<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\DesignVersion;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeCostCard;
use App\Models\EnergyRate;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JobPosition;
use App\Models\LeaveType;
use App\Models\Machine;
use App\Models\MachineCostCard;
use App\Models\OverheadPool;
use App\Models\PaymentAccount;
use App\Models\Permission;
use App\Models\ProductionOrder;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\RawMaterial;
use App\Models\Role;
use App\Models\StudioAsset;
use App\Models\Supplier;
use App\Models\TreasuryTransfer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class LocalizationTest extends ApiTestCase
{
    private function englishAdmin(): User
    {
        $role = Role::create(['code' => 'test_en', 'name_ar' => 'Test role']);
        $role->permissions()->sync(Permission::pluck('id'));
        $user = User::factory()->create(['name' => 'Test Admin']);
        $user->forceFill(['locale' => 'en'])->save();
        $user->roles()->attach($role);

        return $user;
    }

    /** Arabic text left on an English page (user data here is all Latin, so any hit is untranslated UI). */
    private function arabicIn(string $html): array
    {
        // The language switch names the other language in its own script, by design.
        $html = preg_replace('/<button[^>]*lang-toggle[^>]*>.*?<\/button>/su', '', $html);
        preg_match_all('/[^<>"]*\p{Arabic}[^<>"]*/u', $html, $m);

        return array_values(array_unique(array_map('trim', $m[0])));
    }

    private function assertNoArabic(string $html, string $page): void
    {
        $this->assertSame([], $this->arabicIn($html), "Untranslated text on $page");
    }

    public function test_every_page_is_fully_english_for_an_english_user(): void
    {
        $admin = $this->englishAdmin();
        $client = Client::create(['business_name' => 'Acme Co', 'client_type' => 'COMPANY', 'city' => 'Riyadh']);
        $q = $this->actingAs($admin)->postJson('/api/quotations', [
            'client_id' => $client->id, 'discount_amount' => 0,
            'lines' => [['description' => 'Wardrobe', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 1000]],
        ])->assertCreated()->json();
        $this->actingAs($admin)->post("/quotations/{$q['id']}/send");
        $this->actingAs($admin)->post("/quotations/{$q['id']}/approve");
        $this->actingAs($admin)->post('/projects', [
            'quotation_id' => $q['id'], 'title' => 'Villa kitchen', 'start_date' => now()->toDateString(),
        ]);
        $projectId = Project::value('id');
        $roleId = Role::value('id');
        Storage::fake('studio');
        $this->actingAs($admin)->post('/studio', ['category' => 'FINISHED_WORK', 'title' => 'Oak kitchen', 'project_id' => $projectId,
            'files' => [UploadedFile::fake()->image('k.jpg')]]);
        $assetId = StudioAsset::value('id');
        // HR records in English.
        $this->actingAs($admin)->post('/departments', ['name' => 'Workshop']);
        $this->actingAs($admin)->post('/jobs', ['name' => 'Carpenter']);
        $this->actingAs($admin)->post('/employees', ['name' => 'Sam Carpenter', 'department_id' => Department::value('id'), 'job_id' => JobPosition::value('id'), 'hire_date' => '2026-01-01']);
        $employeeId = Employee::where('name', 'Sam Carpenter')->value('id');
        $this->actingAs($admin)->post("/employees/{$employeeId}/documents", ['doc_type' => 'IQAMA', 'doc_number' => '21', 'expiry_date' => now()->addDays(10)->toDateString()]);
        $this->actingAs($admin)->post('/contracts', ['employee_id' => $employeeId, 'contract_type' => 'INDEFINITE', 'start_date' => '2026-01-01',
            'basic_salary' => 4000, 'housing_allowance' => 1000, 'transport_allowance' => 400, 'other_allowance' => 0]);
        $contractId = EmployeeContract::value('id');
        $this->actingAs($admin)->post("/attendance/toggle/{$employeeId}");
        $this->actingAs($admin)->post('/leave-types', ['name' => 'Annual', 'is_paid' => 1, 'requires_allocation' => 1]);
        $this->actingAs($admin)->post('/leave-allocations', ['employee_id' => $employeeId, 'leave_type_id' => LeaveType::value('id'), 'days' => 21,
            'valid_from' => now()->startOfYear()->toDateString(), 'valid_to' => now()->endOfYear()->toDateString(), 'reason' => 'Yearly']);
        $this->actingAs($admin)->post('/leaves', ['employee_id' => $employeeId, 'leave_type_id' => LeaveType::value('id'),
            'date_from' => now()->addMonth()->toDateString(), 'date_to' => now()->addMonth()->addDay()->toDateString(), 'days' => 2]);
        // Purchasing & expenses in English.
        $this->actingAs($admin)->post('/suppliers', ['name' => 'Timber Co', 'vat_number' => '300000000000003']);
        $supplierId = Supplier::value('id');
        $this->actingAs($admin)->post('/expense-categories', ['name' => 'Transport']);
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'Workshop cash box']);
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CUSTODY', 'name' => 'Supervisor custody', 'employee_id' => $employeeId, 'custody_limit' => 2000]);
        $cashId = PaymentAccount::where('kind', 'CASH')->value('id');
        $custodyId = PaymentAccount::where('kind', 'CUSTODY')->value('id');
        $this->actingAs($admin)->post('/treasury/transfers', ['transfer_date' => now()->toDateString(), 'from_account_id' => $cashId, 'to_account_id' => $custodyId, 'amount' => 500]);
        $transferId = TreasuryTransfer::value('id');
        $this->actingAs($admin)->post("/treasury/transfers/{$transferId}/approve");
        $this->actingAs($admin)->post('/expenses', ['expense_date' => now()->toDateString(), 'category_id' => ExpenseCategory::value('id'), 'payee' => 'Truck',
            'description' => 'Delivery', 'amount' => 100, 'vat_amount' => 15, 'payment_account_id' => $custodyId, 'project_id' => $projectId]);
        $expenseId = Expense::value('id');
        // Manufacturing records in English.
        $this->actingAs($admin)->post('/inventory', ['code' => 'MDF18', 'name' => 'MDF board', 'uom' => 'sheet', 'is_active' => 1]);
        $materialId = RawMaterial::value('id');
        $this->actingAs($admin)->post("/inventory/{$materialId}/move", ['movement_type' => 'ADJUST_IN', 'quantity' => 20, 'unit_cost' => 90, 'reason' => 'Opening balance']);
        $this->actingAs($admin)->post('/purchases', ['supplier_id' => $supplierId, 'supplier_invoice_no' => 'T-1', 'invoice_date' => now()->toDateString(),
            'discount_amount' => 0, 'vat_amount' => 0, 'lines' => [['material_id' => $materialId, 'quantity' => 2, 'unit_price' => 50]]]);
        $purchaseId = PurchaseInvoice::value('id');
        $this->actingAs($admin)->post('/designs', ['project_id' => $projectId, 'title' => 'Main wall']);
        $versionId = DesignVersion::value('id');
        $this->actingAs($admin)->post("/design-versions/{$versionId}/bom", ['material_id' => $materialId, 'quantity' => 4]);
        $this->actingAs($admin)->post("/design-versions/{$versionId}/submit");
        $this->actingAs($admin)->post("/design-versions/{$versionId}/approve", ['client_approved_at' => now()->format('Y-m-d H:i')]);
        $this->actingAs($admin)->post("/design-versions/{$versionId}/release");
        $this->actingAs($admin)->post('/production', ['design_version_id' => $versionId]);
        $orderId = ProductionOrder::value('id');
        $this->actingAs($admin)->post("/production/{$orderId}/stage/IN_PROGRESS");
        // Costing engine records in English.
        $this->actingAs($admin)->post('/costing/centers', ['code' => 'CARP', 'name' => 'Carpentry', 'driver' => 'LABOR_HOURS']);
        $centerId = CostCenter::value('id');
        $this->actingAs($admin)->post('/costing/energy', ['effective_from' => '2026-01-01', 'rate_per_kwh' => 0.18, 'source' => 'Bill', 'estimated' => 1]);
        $this->actingAs($admin)->post('/costing/energy/'.EnergyRate::value('id').'/approve');
        $this->actingAs($admin)->post('/costing/employee-cards', ['employee_id' => $employeeId, 'effective_from' => '2026-01-01', 'basic_salary' => 3000, 'housing' => 500,
            'transportation' => 200, 'insurance' => 100, 'government_fees' => 100, 'allowances' => 100, 'other_costs' => 0, 'theoretical_hours' => 208, 'break_hours' => 20,
            'cleaning_hours' => 4, 'maintenance_hours' => 4, 'setup_hours' => 4, 'meeting_hours' => 2, 'downtime_hours' => 2, 'waiting_hours' => 2, 'other_nonproductive_hours' => 0, 'source' => 'Contract']);
        $empCardId = EmployeeCostCard::value('id');
        $this->actingAs($admin)->post("/costing/employee-cards/{$empCardId}/shares", ['cost_center_id' => $centerId, 'share_pct' => 60]);
        $this->actingAs($admin)->post('/costing/machines', ['code' => 'R1', 'name' => 'Router', 'cost_center_id' => $centerId]);
        $this->actingAs($admin)->post('/costing/machine-cards', ['machine_id' => Machine::where('code', 'R1')->value('id'), 'effective_from' => '2026-02-01', 'acquisition_cost' => 1000,
            'residual_value' => 0, 'useful_life_years' => 5, 'theoretical_annual_hours' => 2000, 'practical_annual_hours' => 1600, 'power_kw' => 5, 'load_factor' => 0.5,
            'annual_maintenance' => 100, 'annual_spare_parts' => 100, 'annual_other' => 0, 'source' => 'Invoice']);
        $machineCardId = MachineCostCard::value('id');
        $this->actingAs($admin)->post('/costing/pools', ['kind' => 'MANUFACTURING', 'effective_from' => '2026-01-01', 'period_to' => '2026-12-31', 'driver' => 'LABOR_HOURS',
            'practical_capacity' => 8000, 'source' => 'Budget']);
        $poolId = OverheadPool::value('id');
        $this->actingAs($admin)->post("/costing/pools/{$poolId}/lines", ['category' => 'GENERAL_ELECTRICITY', 'description' => 'Bill', 'amount' => 9000]);
        $this->actingAs($admin)->post('/costing/prices', ['material_id' => $materialId, 'effective_from' => '2026-01-01', 'unit_price' => 90, 'price_basis' => 'MANUAL', 'source' => 'Quote']);
        $this->actingAs($admin)->post('/costing/waste', ['category' => 'Boards', 'effective_from' => '2026-01-01', 'waste_pct' => 10, 'source' => 'Records']);
        $draft = $this->actingAs($admin)->postJson('/api/quotations', [
            'client_id' => $client->id, 'discount_amount' => 0,
            'lines' => [['description' => 'Kitchen', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 5000, 'studio_asset_id' => $assetId]],
        ])->assertCreated()->json();

        $pages = [
            '/', '/clients', '/clients?v=kanban', '/clients?g=city', '/clients/create', "/clients/{$client->id}", "/clients/{$client->id}/edit",
            '/quotations', '/quotations?v=kanban', '/quotations/create', "/quotations/{$q['id']}",
            '/projects', '/projects?v=kanban', "/projects/{$projectId}",
            '/settings/rates', '/users', '/users/create', "/users/{$admin->id}/edit",
            '/roles', '/roles/create', "/roles/{$roleId}/edit",
            '/reports', '/settings/daftra', '/settings/demo',
            '/costing', '/costing/centers', '/costing/energy', '/costing/employee-cards', '/costing/employee-cards?g=employee', "/costing/employee-cards/create?employee_id={$employeeId}&from_contract=1",
            "/costing/employee-cards/{$empCardId}", "/costing/employee-cards/{$empCardId}/edit", '/costing/machine-cards', '/costing/machine-cards/create',
            "/costing/machine-cards/{$machineCardId}", "/costing/machine-cards/{$machineCardId}/edit", '/costing/pools', '/costing/pools/create', '/costing/pools/create?kind=SELLING_ADMIN',
            "/costing/pools/{$poolId}", "/costing/pools/{$poolId}/edit", '/costing/prices', '/costing/waste',
            '/employees', '/employees?v=list&g=department', '/employees/create', "/employees/{$employeeId}", "/employees/{$employeeId}/edit", '/departments',
            '/contracts', '/contracts/create', "/contracts/{$contractId}", "/contracts/{$contractId}/edit",
            '/attendance', '/attendance/records', '/attendance/records?g=employee', '/leaves', '/leaves/create', '/leaves/settings',
            '/inventory', '/inventory?g=category', '/inventory/create', "/inventory/{$materialId}", "/inventory/{$materialId}/edit",
            '/suppliers', '/suppliers/create', "/suppliers/{$supplierId}", "/suppliers/{$supplierId}/edit",
            '/purchases', '/purchases/create', '/purchases?g=supplier', "/purchases/{$purchaseId}", "/purchases/{$purchaseId}/edit",
            '/expenses', '/expenses/create', '/expenses?g=category', "/expenses/{$expenseId}", "/expenses/{$expenseId}/edit", '/expense-categories',
            '/treasury/accounts', '/treasury/accounts?v=list', '/treasury/accounts?g=kind', '/treasury/accounts/create', "/treasury/accounts/{$custodyId}", "/treasury/accounts/{$cashId}", "/treasury/accounts/{$custodyId}/edit",
            '/treasury/transfers', '/treasury/transfers/create', "/treasury/transfers/{$transferId}",
            '/designs', '/designs/create', "/design-versions/{$versionId}",
            '/production', '/production?v=kanban', '/production/create', "/production/{$orderId}",
            '/studio', '/studio?v=list', '/studio?g=category', '/studio/create', "/studio/{$assetId}", "/studio/{$assetId}/edit", "/quotations/{$draft['id']}/edit", "/quotations/{$draft['id']}",
        ];
        foreach (['quotations', 'projects', 'profitability', 'purchases', 'expenses', 'consumption'] as $r) {
            $pages[] = "/reports/$r";
            $pages[] = "/reports/$r?view=graph";
        }

        $left = [];
        foreach ($pages as $page) {
            $res = $this->actingAs($admin)->get($page)->assertOk();
            $this->assertStringContainsString('lang="en" dir="ltr"', $res->getContent(), $page);
            foreach ($this->arabicIn($res->getContent()) as $text) {
                $left[$text][] = $page;
            }
        }
        $this->assertSame([], $left, 'Untranslated text on English pages');
    }

    public function test_toggle_switches_and_persists_locale(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->get('/')->assertSee('dir="rtl"', false);

        $this->actingAs($user)->post('/locale/en')->assertRedirect();
        $this->assertSame('en', $user->fresh()->locale);
        $this->actingAs($user->fresh())->get('/')->assertSee('dir="ltr"', false)->assertSee('Customers');

        $this->actingAs($user->fresh())->post('/locale/ar');
        $this->assertSame('ar', $user->fresh()->locale);
        $this->post('/locale/fr')->assertNotFound();
    }

    public function test_login_page_follows_session_locale(): void
    {
        $this->post('/locale/en');
        $res = $this->get('/login')->assertOk()->assertSee('dir="ltr"', false)->assertSee('Sign in');
        $this->assertNoArabic($res->getContent(), '/login');

        // The choice made before signing in is kept after signing in.
        $user = User::factory()->create(['email' => 'en@rroka.test']);
        $this->assertSame('ar', $user->fresh()->locale);
        $this->post('/login', ['email' => 'en@rroka.test', 'password' => 'password'])->assertRedirect();
        $this->assertSame('en', $user->fresh()->locale);
        $this->get('/')->assertSee('dir="ltr"', false);
    }

    public function test_validation_and_rule_errors_are_english(): void
    {
        $admin = $this->englishAdmin();
        $this->actingAs($admin)->from('/clients/create')->post('/clients', [])
            ->assertSessionHasErrors(['business_name' => 'The customer name field is required.']);

        $this->actingAs($admin)->followingRedirects()->post('/clients', ['business_name' => 'Beta', 'client_type' => 'COMPANY'])
            ->assertOk()->assertSee('Customer saved.');

        $res = $this->actingAs($admin)->get('/clients/999999')->assertNotFound();
        $this->assertNoArabic($res->getContent(), '404');
    }
}
