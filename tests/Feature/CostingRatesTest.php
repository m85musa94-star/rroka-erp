<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeCostCard;
use App\Models\EnergyRate;
use App\Models\Machine;
use App\Models\MachineCostCard;
use App\Models\OverheadPool;
use App\Models\RawMaterial;
use Illuminate\Support\Facades\DB;

class CostingRatesTest extends ApiTestCase
{
    private function card(array $over = []): array
    {
        return $over + ['effective_from' => '2026-01-01', 'basic_salary' => 2800, 'housing' => 700, 'transportation' => 250, 'insurance' => 100,
            'government_fees' => 100, 'allowances' => 50, 'other_costs' => 0, 'theoretical_hours' => 208, 'break_hours' => 22, 'cleaning_hours' => 6,
            'maintenance_hours' => 4, 'setup_hours' => 6, 'meeting_hours' => 2, 'downtime_hours' => 4, 'waiting_hours' => 4, 'other_nonproductive_hours' => 0,
            'source' => 'TEST contract + GOSI statement'];
    }

    public function test_employee_card_gives_the_productive_hour_rate_and_feeds_job_costing(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/costing/centers/from-spec')->assertSessionHasNoErrors();
        $this->assertSame(7, CostCenter::count());
        $this->actingAs($admin)->post('/costing/centers/from-spec')->assertSessionHasErrors('rule');
        $worker = Employee::create(['name' => 'TEST carpenter', 'hire_date' => '2025-01-01', 'is_direct_labor' => true]);

        // Salary items can be filled from the running contract (real data, not assumed).
        DB::table('employee_contracts')->insert(['employee_id' => $worker->id, 'contract_type' => 'INDEFINITE', 'start_date' => '2025-01-01',
            'basic_salary' => 2800, 'housing_allowance' => 700, 'transport_allowance' => 250, 'other_allowance' => 50, 'weekly_hours' => 48, 'status' => 'RUNNING']);
        $this->actingAs($admin)->get("/costing/employee-cards/create?employee_id={$worker->id}&from_contract=1")->assertOk()->assertSee('value="2800.00"', false);

        $this->actingAs($admin)->post('/costing/employee-cards', $this->card(['employee_id' => $worker->id, 'theoretical_hours' => 40]))
            ->assertSessionHasErrors('theoretical_hours');
        $this->actingAs($admin)->post('/costing/employee-cards', $this->card(['employee_id' => $worker->id]))->assertRedirect();
        $card = EmployeeCostCard::sole();
        $this->assertEquals(4000, (float) $card->monthly_cost);
        $this->assertEquals(160, (float) $card->practical_hours);
        $this->assertEquals(25, (float) $card->hourly_rate);

        $this->actingAs($admin)->post("/costing/employee-cards/{$card->id}/approve")->assertSessionHasErrors('rule'); // no shares yet
        $carp = CostCenter::where('code', 'CARP')->sole();
        $assy = CostCenter::where('code', 'ASSY')->sole();
        $this->actingAs($admin)->post("/costing/employee-cards/{$card->id}/shares", ['cost_center_id' => $carp->id, 'share_pct' => 70]);
        $this->actingAs($admin)->post("/costing/employee-cards/{$card->id}/shares", ['cost_center_id' => $assy->id, 'share_pct' => 30]);
        $this->actingAs($admin)->get("/costing/employee-cards/{$card->id}")->assertOk()->assertSee('25.00')->assertSee('4,000.00 ÷ 160.00');
        $this->actingAs($admin)->post("/costing/employee-cards/{$card->id}/approve")->assertSessionHasNoErrors();

        $this->assertEquals(25, (float) DB::table('worker_rates')->where('cost_card_id', $card->id)->value('hourly_cost'));
        $this->actingAs($admin)->get("/costing/employee-cards/{$card->id}/edit")->assertNotFound();
        $this->actingAs($admin)->put("/costing/employee-cards/{$card->id}", $this->card())->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post("/costing/employee-cards/{$card->id}/approve")->assertSessionHasErrors('rule');

        // A new version must start later: costs of work already done never change silently.
        $this->actingAs($admin)->post('/costing/employee-cards', $this->card(['employee_id' => $worker->id, 'effective_from' => '2025-12-01', 'basic_salary' => 3500]));
        $v2 = EmployeeCostCard::where('version', 2)->sole();
        $this->actingAs($admin)->post("/costing/employee-cards/{$v2->id}/shares", ['cost_center_id' => $carp->id, 'share_pct' => 100]);
        $this->actingAs($admin)->post("/costing/employee-cards/{$v2->id}/approve")->assertSessionHasErrors('rule');
        $this->actingAs($admin)->get('/costing')->assertOk()->assertDontSee('TEST carpenter');
    }

    public function test_machine_card_hour_rate_needs_an_approved_electricity_price(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/costing/centers', ['code' => 'cnc', 'name' => 'TEST CNC', 'driver' => 'MACHINE_HOURS'])->assertSessionHasNoErrors();
        $cnc = CostCenter::sole();
        $this->assertSame('CNC', $cnc->code);
        $this->actingAs($admin)->post('/costing/machines', ['code' => 'r1', 'name' => 'TEST router', 'cost_center_id' => $cnc->id])->assertSessionHasNoErrors();
        $machine = Machine::sole();
        $this->actingAs($admin)->get('/costing')->assertSee('R1');

        $card = ['machine_id' => $machine->id, 'effective_from' => '2026-01-01', 'acquisition_cost' => 120000, 'residual_value' => 20000, 'useful_life_years' => 10,
            'theoretical_annual_hours' => 2000, 'practical_annual_hours' => 1600, 'power_kw' => 10, 'load_factor' => 0.5,
            'annual_maintenance' => 3200, 'annual_spare_parts' => 1600, 'annual_other' => 0, 'source' => 'TEST invoice'];
        $this->actingAs($admin)->post('/costing/machine-cards', ['residual_value' => 130000] + $card)->assertSessionHasErrors('residual_value');
        $this->actingAs($admin)->post('/costing/machine-cards', $card)->assertRedirect();
        $mc = MachineCostCard::sole();
        $this->assertNull($mc->hourly_rate, 'no electricity price → unknown, not zero');
        $this->actingAs($admin)->get("/costing/machine-cards/{$mc->id}")->assertOk()->assertSee('6.2500');
        $this->actingAs($admin)->post("/costing/machine-cards/{$mc->id}/approve")->assertSessionHasErrors('rule');

        $this->actingAs($admin)->post('/costing/energy', ['effective_from' => '2025-12-01', 'rate_per_kwh' => 0.18, 'source' => 'TEST bill'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/costing/energy/'.EnergyRate::sole()->id.'/approve')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/costing/energy/'.EnergyRate::sole()->id.'/approve')->assertSessionHasErrors('rule');
        $this->assertSame('APPROVED', EnergyRate::sole()->status);

        $this->actingAs($admin)->put("/costing/machine-cards/{$mc->id}", $card)->assertSessionHasNoErrors(); // re-reads the electricity price
        $this->assertEquals(0.9, (float) $mc->fresh()->electricity_per_hour);
        $this->assertEquals(10.15, (float) $mc->fresh()->hourly_rate);
        $this->actingAs($admin)->post("/costing/machine-cards/{$mc->id}/approve")->assertSessionHasNoErrors();
        $this->assertEquals(10.15, (float) DB::table('machine_rates')->where('cost_card_id', $mc->id)->value('hourly_cost'));
        $this->actingAs($admin)->get('/costing/machine-cards')->assertOk()->assertSee('10.15');
    }

    public function test_overhead_pools_absorb_by_driver_and_refuse_double_counting(): void
    {
        $admin = $this->admin();
        $supervisor = Employee::create(['name' => 'TEST supervisor', 'hire_date' => '2025-01-01']);
        $direct = Employee::create(['name' => 'TEST direct', 'hire_date' => '2025-01-01', 'is_direct_labor' => true]);
        $this->actingAs($admin)->post('/costing/centers', ['code' => 'CARP', 'name' => 'TEST carpentry', 'driver' => 'LABOR_HOURS']);
        $this->actingAs($admin)->post('/costing/employee-cards', $this->card(['employee_id' => $direct->id]));
        $card = EmployeeCostCard::sole();
        $card->shares()->create(['cost_center_id' => CostCenter::sole()->id, 'share_pct' => 100]);
        $this->actingAs($admin)->post("/costing/employee-cards/{$card->id}/approve");

        $pool = ['kind' => 'MANUFACTURING', 'effective_from' => '2026-01-01', 'period_to' => '2026-12-31', 'driver' => 'LABOR_HOURS',
            'theoretical_capacity' => 10000, 'practical_capacity' => 8000, 'source' => 'TEST 2026 budget'];
        $this->actingAs($admin)->post('/costing/pools', ['practical_capacity' => 12000] + $pool)->assertSessionHasErrors('practical_capacity');
        $this->actingAs($admin)->post('/costing/pools', $pool)->assertRedirect();
        $p = OverheadPool::sole();
        $this->actingAs($admin)->post("/costing/pools/{$p->id}/lines", ['category' => 'RENT', 'description' => 'TEST rent', 'amount' => 60000])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/costing/pools/{$p->id}/lines", ['category' => 'SUPERVISION', 'description' => 'TEST', 'amount' => 18000])->assertSessionHasErrors('employee_id');
        $this->actingAs($admin)->post("/costing/pools/{$p->id}/lines", ['category' => 'INDIRECT_LABOR', 'description' => 'TEST', 'amount' => 1, 'employee_id' => $direct->id])->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post("/costing/pools/{$p->id}/lines", ['category' => 'SUPERVISION', 'description' => 'TEST supervisor', 'amount' => 18000, 'employee_id' => $supervisor->id])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/costing/pools/{$p->id}/lines", ['category' => 'SELLING', 'description' => 'x', 'amount' => 1])->assertSessionHasErrors('category');
        $this->actingAs($admin)->get("/costing/pools/{$p->id}")->assertOk()->assertSee('9.7500'); // (60,000 + 18,000) ÷ 8,000 preview
        $this->actingAs($admin)->post("/costing/pools/{$p->id}/approve")->assertSessionHasNoErrors();
        $this->assertEquals(9.75, (float) $p->fresh()->rate);
        $this->actingAs($admin)->delete("/costing/pools/{$p->id}/lines/".$p->lines()->value('id'))->assertSessionHasErrors('rule');

        $this->actingAs($admin)->post('/costing/pools', ['kind' => 'SELLING_ADMIN', 'effective_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'driver' => 'PCT_OF_MANUFACTURING_COST', 'budgeted_manufacturing_cost' => 400000, 'source' => 'TEST'])->assertRedirect();
        $sa = OverheadPool::where('kind', 'SELLING_ADMIN')->sole();
        $this->actingAs($admin)->post("/costing/pools/{$sa->id}/lines", ['category' => 'SELLING', 'description' => 'TEST marketing', 'amount' => 30000]);
        $this->actingAs($admin)->post("/costing/pools/{$sa->id}/lines", ['category' => 'ADMINISTRATIVE', 'description' => 'TEST office', 'amount' => 50000]);
        $this->actingAs($admin)->post("/costing/pools/{$sa->id}/approve")->assertSessionHasNoErrors();
        $this->assertEquals(20, (float) $sa->fresh()->rate);
        $this->actingAs($admin)->get('/costing/pools')->assertOk()->assertSee('20.00%')->assertSee('9.75');
    }

    public function test_standard_prices_and_waste_are_entered_then_approved(): void
    {
        $admin = $this->admin();
        $fab = RawMaterial::create(['code' => 'FAB', 'name' => 'TEST fabric', 'category' => 'Fabric', 'uom' => 'm']);
        $vel = RawMaterial::create(['code' => 'VEL', 'name' => 'TEST velvet', 'category' => 'Fabric', 'uom' => 'm']);
        $this->actingAs($admin)->get('/costing/prices')->assertOk()->assertSee('FAB');

        $this->actingAs($admin)->post('/costing/prices', ['material_id' => $fab->id, 'effective_from' => '2026-01-01', 'unit_price' => 45, 'price_basis' => 'SUPPLIER_QUOTE', 'source' => 'TEST quote'])->assertSessionHasNoErrors();
        $this->assertNull(DB::scalar("select fn_standard_price(?, '2026-02-01')", [$fab->id]));
        $this->actingAs($admin)->post('/costing/prices/'.DB::table('material_standard_prices')->value('id').'/approve')->assertSessionHasNoErrors();
        $this->assertEquals(45, (float) DB::scalar("select fn_standard_price(?, '2026-02-01')", [$fab->id]));

        $this->actingAs($admin)->post('/costing/waste', ['material_id' => $fab->id, 'category' => 'Fabric', 'effective_from' => '2026-01-01', 'waste_pct' => 5, 'source' => 'x'])->assertSessionHasErrors('material_id');
        $this->actingAs($admin)->post('/costing/waste', ['category' => 'Fabric', 'effective_from' => '2026-01-01', 'waste_pct' => 12, 'source' => 'TEST cutting'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/costing/waste', ['material_id' => $vel->id, 'effective_from' => '2026-01-01', 'waste_pct' => 18, 'source' => 'TEST pattern'])->assertSessionHasNoErrors();
        foreach (DB::table('waste_defaults')->pluck('id') as $id) {
            $this->actingAs($admin)->post("/costing/waste/{$id}/approve")->assertSessionHasNoErrors();
        }
        $this->assertEquals(12, (float) DB::scalar("select fn_standard_waste_pct(?, '2026-02-01')", [$fab->id]));
        $this->assertEquals(18, (float) DB::scalar("select fn_standard_waste_pct(?, '2026-02-01')", [$vel->id]));
        $this->actingAs($admin)->get('/costing/waste')->assertOk()->assertSee('18.00');
    }

    public function test_entering_and_approving_are_separate_permissions(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/costing/energy', ['effective_from' => '2026-01-01', 'rate_per_kwh' => 0.18, 'source' => 'TEST']);
        $id = EnergyRate::sole()->id;

        $clerk = $this->userWith(['settings.cost_rates']);
        $this->actingAs($clerk)->get('/costing/energy')->assertOk();
        $this->actingAs($clerk)->post("/costing/energy/{$id}/approve")->assertForbidden();

        $approver = $this->userWith(['cost_rates.approve']);
        $this->actingAs($approver)->get('/costing/energy')->assertOk();
        $this->actingAs($approver)->post('/costing/energy', ['effective_from' => '2026-02-01', 'rate_per_kwh' => 1, 'source' => 'x'])->assertForbidden();
        $this->actingAs($approver)->post("/costing/energy/{$id}/approve")->assertSessionHasNoErrors();
        $this->assertFalse(EnergyRate::sole()->selfApproved());

        $this->actingAs($this->userWith(['projects.view']))->get('/costing')->assertForbidden();
        $this->actingAs($approver)->get('/')->assertSee(route('costing.index'), false);
    }
}
