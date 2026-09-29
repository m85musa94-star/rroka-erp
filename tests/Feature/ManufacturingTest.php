<?php

namespace Tests\Feature;

use App\Models\Design;
use App\Models\DesignVersion;
use App\Models\Machine;
use App\Models\ProductionOrder;
use App\Models\RawMaterial;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;

class ManufacturingTest extends ApiTestCase
{
    /** An approved quotation turned into a project (synthetic test data). */
    private function project($admin): int
    {
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->post("/quotations/{$q['id']}/send");
        $this->actingAs($admin)->post("/quotations/{$q['id']}/approve");
        $this->actingAs($admin)->post('/projects', ['quotation_id' => $q['id'], 'title' => 'TEST kitchen', 'start_date' => now()->toDateString()]);

        return (int) DB::table('projects')->value('id');
    }

    private function material($admin, string $code, float $qty, float $cost): RawMaterial
    {
        $this->actingAs($admin)->post('/inventory', ['code' => $code, 'name' => "TEST $code", 'uom' => 'pc', 'is_active' => 1])->assertRedirect();
        $m = RawMaterial::where('code', $code)->sole();
        if ($qty > 0) {
            $this->actingAs($admin)->post("/inventory/{$m->id}/move", ['movement_type' => 'ADJUST_IN', 'quantity' => $qty, 'unit_cost' => $cost, 'reference' => 'INV-1', 'reason' => 'TEST opening balance'])->assertSessionHasNoErrors();
        }

        return $m;
    }

    public function test_inventory_receipts_adjustments_and_balances(): void
    {
        $admin = $this->admin();
        $m = $this->material($admin, 'MDF18', 10, 100);
        $this->actingAs($admin)->post("/inventory/{$m->id}/move", ['movement_type' => 'RECEIPT', 'quantity' => 10, 'unit_cost' => 120])->assertSessionHasErrors('movement_type'); // receipts come from supplier invoices
        $this->actingAs($admin)->post("/inventory/{$m->id}/move", ['movement_type' => 'ADJUST_IN', 'quantity' => 10, 'unit_cost' => 120, 'reason' => 'TEST count']);
        $this->assertEquals(110, (float) DB::table('stock_balances')->where('material_id', $m->id)->value('avg_unit_cost'));

        $this->actingAs($admin)->post("/inventory/{$m->id}/move", ['movement_type' => 'ADJUST_OUT', 'quantity' => 1])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/inventory/{$m->id}/move", ['movement_type' => 'ADJUST_OUT', 'quantity' => 99, 'reason' => 'count'])->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post("/inventory/{$m->id}/move", ['movement_type' => 'ADJUST_OUT', 'quantity' => 2, 'reason' => 'damaged'])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/inventory')->assertOk()->assertSee('MDF18')->assertSee('18');
        $this->actingAs($admin)->get("/inventory/{$m->id}")->assertOk()->assertSee('damaged')->assertSee('INV-1');
        $this->actingAs($this->userWith(['inventory.view']))->post("/inventory/{$m->id}/move", ['movement_type' => 'ADJUST_IN', 'quantity' => 1, 'unit_cost' => 1, 'reason' => 'x'])->assertForbidden();
    }

    public function test_full_cycle_design_release_order_materials_time_quality(): void
    {
        $admin = $this->admin();
        $projectId = $this->project($admin);
        $mdf = $this->material($admin, 'MDF18', 20, 100);
        $hinge = $this->material($admin, 'HNG', 3, 5);

        // Design v1: BOM, client review, approval, release.
        $this->actingAs($admin)->post('/designs', ['project_id' => $projectId, 'title' => 'TEST wall'])->assertRedirect();
        $v1 = DesignVersion::sole();
        $this->actingAs($admin)->post("/design-versions/{$v1->id}/bom", ['material_id' => $mdf->id, 'quantity' => 10, 'waste_pct' => 10]);
        $this->actingAs($admin)->post("/design-versions/{$v1->id}/bom", ['material_id' => $hinge->id, 'quantity' => 8]);
        $this->actingAs($admin)->post("/design-versions/{$v1->id}/release")->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post("/design-versions/{$v1->id}/submit");
        $this->actingAs($admin)->post("/design-versions/{$v1->id}/approve", ['client_approved_at' => now()->subHour()->format('Y-m-d H:i')]);
        $this->actingAs($admin)->post("/design-versions/{$v1->id}/release")->assertSessionHasNoErrors();
        $this->assertSame('RELEASED_FOR_PRODUCTION', $v1->fresh()->status);
        $this->actingAs($admin)->post("/design-versions/{$v1->id}/bom", ['material_id' => $mdf->id, 'quantity' => 1])->assertSessionHasErrors('rule');
        $this->actingAs($admin)->get("/design-versions/{$v1->id}")->assertOk()->assertSee('11'); // 10 + 10% waste

        // Manufacturing order: check availability reserves what exists (hinges are short).
        $this->actingAs($admin)->post('/production', ['design_version_id' => $v1->id, 'planned_start' => now()->toDateString()])->assertRedirect();
        $o = ProductionOrder::sole();
        $this->assertSame($projectId, $o->project_id);
        $this->actingAs($admin)->post("/production/{$o->id}/reserve-all")->assertSessionHas('ok', fn ($m) => str_contains($m, 'HNG'));
        $this->assertEquals(11, (float) DB::scalar('select fn_project_reserved(?, ?)', [$projectId, $mdf->id]));
        $this->assertEquals(3, (float) DB::scalar('select fn_project_reserved(?, ?)', [$projectId, $hinge->id]));

        $this->actingAs($admin)->post("/production/{$o->id}/material", ['movement_type' => 'ISSUE', 'material_id' => $mdf->id, 'quantity' => 11])->assertSessionHasNoErrors();
        $this->assertEquals(0, (float) DB::scalar('select fn_project_reserved(?, ?)', [$projectId, $mdf->id]));
        $this->assertEquals(11, (float) DB::scalar('select fn_project_issued(?, ?)', [$projectId, $mdf->id]));

        // Time only after start; completion only after a passing final inspection.
        $worker = Worker::create(['name' => 'TEST carpenter']);
        $machine = Machine::create(['code' => 'CNC', 'name' => 'TEST CNC']);
        $log = ['worker_id' => $worker->id, 'work_date' => now()->toDateString(), 'hours' => 6];
        $this->actingAs($admin)->post("/production/{$o->id}/labor", $log)->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post("/production/{$o->id}/stage/COMPLETED")->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post("/production/{$o->id}/stage/IN_PROGRESS")->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/production/{$o->id}/labor", $log)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/production/{$o->id}/machine", ['machine_id' => $machine->id, 'work_date' => now()->toDateString(), 'hours' => 2])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/production/{$o->id}/stage/COMPLETED")->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post("/production/{$o->id}/inspect", ['stage' => 'FINAL', 'result' => 'FAIL'])->assertSessionHasErrors('findings');
        $this->actingAs($admin)->post("/production/{$o->id}/inspect", ['stage' => 'FINAL', 'result' => 'PASS'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get("/production/{$o->id}")->assertOk()->assertSee('TEST carpenter')->assertSee('CNC');
        $this->actingAs($admin)->post("/production/{$o->id}/stage/COMPLETED")->assertSessionHasNoErrors();
        $this->assertSame('COMPLETED', $o->fresh()->status);
        $this->actingAs($admin)->post("/production/{$o->id}/material", ['movement_type' => 'RETURN', 'material_id' => $mdf->id, 'quantity' => 1])->assertSessionHasErrors('rule');

        // Job costing picks up the issued material at average cost; labour stays NULL without a rate.
        $cost = DB::table('v_project_actual_cost')->where('project_id', $projectId)->first();
        $this->assertEquals(1100, (float) $cost->material_cost);
        $this->assertNull($cost->labor_cost);

        // A new version copies the BOM; releasing it supersedes v1.
        $this->actingAs($admin)->post("/designs/{$v1->design_id}/versions", ['change_notes' => 'wider'])->assertRedirect();
        $v2 = DesignVersion::where('version_no', 2)->sole();
        $this->assertSame(2, $v2->bomLines()->count());
        $this->actingAs($admin)->post("/design-versions/{$v2->id}/submit");
        $this->actingAs($admin)->post("/design-versions/{$v2->id}/approve", ['client_approved_at' => now()->format('Y-m-d H:i')]);
        $this->actingAs($admin)->post("/design-versions/{$v2->id}/release")->assertSessionHasNoErrors();
        $this->assertSame('SUPERSEDED', $v1->fresh()->status);

        $this->actingAs($admin)->get("/projects/{$projectId}")->assertOk()->assertSee('TEST wall')->assertSee($o->order_no);
        $this->actingAs($admin)->get('/production?v=kanban')->assertOk()->assertSee($o->order_no);
        $this->actingAs($admin)->get('/designs')->assertOk()->assertSee('TEST wall');
    }

    public function test_permissions_gate_each_action(): void
    {
        $admin = $this->admin();
        $projectId = $this->project($admin);
        $viewer = $this->userWith(['projects.view']);
        $this->actingAs($viewer)->get('/production')->assertOk();
        $this->actingAs($viewer)->get('/production/create')->assertForbidden();
        $this->actingAs($viewer)->post('/designs', ['project_id' => $projectId, 'title' => 'x'])->assertForbidden();
        $this->actingAs($admin)->post('/designs', ['project_id' => $projectId, 'title' => 'TEST']);
        $v = DesignVersion::sole();
        $designer = $this->userWith(['designs.manage']);
        $this->actingAs($designer)->post("/design-versions/{$v->id}/submit")->assertSessionHasNoErrors();
        $this->actingAs($designer)->post("/design-versions/{$v->id}/approve", ['client_approved_at' => now()->format('Y-m-d H:i')]);
        $this->actingAs($designer)->post("/design-versions/{$v->id}/release")->assertForbidden();
        $this->assertSame(1, Design::count());
    }
}
