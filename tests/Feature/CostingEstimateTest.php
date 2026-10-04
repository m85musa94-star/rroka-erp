<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\CostEstimate;
use App\Models\Employee;
use App\Models\EmployeeCostCard;
use App\Models\MaterialStandardPrice;
use App\Models\OverheadPool;
use App\Models\PricingPolicy;
use App\Models\Quotation;
use App\Models\RawMaterial;
use App\Models\VatRate;
use App\Models\WasteDefault;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CostingEstimateTest extends ApiTestCase
{
    protected bool $costingGate = true;

    private function approve(Model $record, int $by): void
    {
        $record->forceFill(['status' => 'APPROVED', 'approved_by' => $by, 'approved_at' => now()])->save();
    }

    /** Approved rates for 2026: carpentry 25/h + overhead 5/h, selling & admin 20%, MDF 45 + 12% waste, pricing 30% margin. */
    private function rates($admin): array
    {
        $u = $admin->id;
        $carp = CostCenter::create(['code' => 'CARP', 'name' => 'TEST carpentry', 'driver' => 'LABOR_HOURS']);
        $worker = Employee::create(['name' => 'TEST carpenter', 'hire_date' => '2025-01-01', 'is_direct_labor' => true]);
        $card = new EmployeeCostCard(['employee_id' => $worker->id, 'effective_from' => '2026-01-01', 'basic_salary' => 4000, 'housing' => 0, 'transportation' => 0,
            'insurance' => 0, 'government_fees' => 0, 'allowances' => 0, 'other_costs' => 0, 'theoretical_hours' => 160, 'break_hours' => 0, 'cleaning_hours' => 0,
            'maintenance_hours' => 0, 'setup_hours' => 0, 'meeting_hours' => 0, 'downtime_hours' => 0, 'waiting_hours' => 0, 'other_nonproductive_hours' => 0, 'source' => 'TEST']);
        $card->created_by = $u;
        $card->save();
        $card->shares()->create(['cost_center_id' => $carp->id, 'share_pct' => 100]);
        $this->approve($card, $u);
        foreach ([['kind' => 'MANUFACTURING', 'cost_center_id' => $carp->id, 'driver' => 'LABOR_HOURS', 'practical_capacity' => 2000, 'line' => ['MAINTENANCE', 10000]],
            ['kind' => 'SELLING_ADMIN', 'driver' => 'PCT_OF_MANUFACTURING_COST', 'budgeted_manufacturing_cost' => 400000, 'line' => ['ADMINISTRATIVE', 80000]]] as $p) {
            $line = $p['line'];
            unset($p['line']);
            $pool = new OverheadPool($p + ['effective_from' => '2026-01-01', 'period_to' => '2026-12-31', 'source' => 'TEST']);
            $pool->created_by = $u;
            $pool->save();
            $pool->lines()->create(['category' => $line[0], 'description' => 'TEST', 'amount' => $line[1]]);
            $this->approve($pool, $u);
        }
        $mdf = RawMaterial::create(['code' => 'MDF', 'name' => 'TEST MDF', 'category' => 'Boards', 'uom' => 'sheet']);
        foreach ([new MaterialStandardPrice(['material_id' => $mdf->id, 'effective_from' => '2026-01-01', 'unit_price' => 45, 'price_basis' => 'MANUAL', 'source' => 'TEST']),
            new WasteDefault(['category' => 'Boards', 'effective_from' => '2026-01-01', 'waste_pct' => 12, 'source' => 'TEST']),
            new PricingPolicy(['effective_from' => '2026-01-01', 'pricing_method' => 'MARGIN', 'target_pct' => 30, 'min_margin_pct' => 10, 'source' => 'TEST'])] as $r) {
            $r->created_by = $u;
            $r->save();
            $this->approve($r, $u);
        }

        return [$carp, $worker, $mdf, VatRate::create(['name' => 'TEST VAT', 'rate_pct' => 15])];
    }

    private function quotation($admin, array $over = []): Quotation
    {
        $client = $this->actingAs($admin)->postJson('/api/clients', ['business_name' => 'TEST client'])->json();
        $this->actingAs($admin)->post('/quotations', $over + ['client_id' => $client['id'], 'issue_date' => '2026-06-01', 'discount_amount' => 0,
            'lines' => [['description' => 'TEST wardrobe', 'quantity' => 2, 'unit' => 'pc', 'unit_price' => 3000]]])->assertSessionHasNoErrors();

        return Quotation::latest('id')->first();
    }

    public function test_cost_sheet_prices_the_line_and_approval_freezes_it(): void
    {
        $admin = $this->admin();
        [$carp, $worker, $mdf, $vat] = $this->rates($admin);
        $q = $this->quotation($admin, ['vat_rate_id' => $vat->id]);
        $this->assertTrue($q->requires_costing);
        $this->actingAs($admin)->get("/quotations/{$q->id}")->assertOk()->assertSee('بلا تقدير')->assertSee('900.00')->assertSee('6,900.00');

        $this->actingAs($admin)->post("/quotations/{$q->id}/send");
        $this->actingAs($admin)->post("/quotations/{$q->id}/approve")->assertSessionHasErrors('rule');
        $this->assertSame('SENT', $q->fresh()->status);

        $sheet = "/quotations/{$q->id}/lines/1/costing";
        $this->actingAs($admin)->get($sheet)->assertOk()->assertSee('value="30.00"', false); // policy suggested
        $this->actingAs($admin)->post("{$sheet}/materials", ['material_id' => $mdf->id, 'quantity' => 10])->assertSessionHasErrors('rule'); // header first
        $this->actingAs($admin)->post($sheet, ['pricing_method' => 'MARGIN', 'target_pct' => 30, 'min_margin_pct' => 10, 'product_category' => 'Wardrobes'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("{$sheet}/materials", ['material_id' => $mdf->id, 'quantity' => 10])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("{$sheet}/operations", ['operation' => 'TEST build', 'cost_center_id' => $carp->id, 'labor_hours' => 4, 'setup_hours' => 2])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("{$sheet}/direct", ['cost_type' => 'INSTALLATION', 'description' => 'TEST crew', 'amount' => 150, 'basis' => 'ONE_TIME'])->assertSessionHasNoErrors();

        // MDF 10 × 1.12 × 2 × 45 = 1,008; labour (4 × 2 + 2 setup) × 25 = 250; overhead 10 h × 5 = 50; installation 150
        // → manufacturing 1,458; fully loaded × 1.20 = 1,749.60; price at 30% margin = 1,749.60 ÷ 0.70 = 2,499.43.
        $costs = CostEstimate::sole()->costs();
        $this->assertEquals(1458, (float) $costs->manufacturing_cost);
        $this->assertEquals(1749.6, (float) $costs->fully_loaded_cost);
        $this->assertEquals(2499.43, (float) $costs->recommended_price);
        $this->assertSame([], $costs->missing);
        $this->actingAs($admin)->get($sheet)->assertOk()->assertSee('1,458.00')->assertSee('2,499.43')->assertSee('11.2000');

        $this->actingAs($admin)->post("/quotations/{$q->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $q->fresh()->status);
        $this->assertEquals(1458, (float) DB::table('cost_estimate_snapshots')->where('quotation_id', $q->id)->value('manufacturing_cost'));
        $this->actingAs($admin)->get($sheet)->assertOk()->assertSee('مجمّدة');
        $this->actingAs($admin)->post("{$sheet}/materials", ['material_id' => $mdf->id, 'quantity' => 1])->assertSessionHasErrors('rule');

        // A later price never re-prices the approved quotation.
        $newer = new MaterialStandardPrice(['material_id' => $mdf->id, 'effective_from' => '2026-07-01', 'unit_price' => 60, 'price_basis' => 'MANUAL', 'source' => 'TEST']);
        $newer->created_by = $admin->id;
        $newer->save();
        $this->approve($newer, $admin->id);
        $this->assertEquals(1458, (float) DB::table('cost_estimate_snapshots')->where('quotation_id', $q->id)->value('manufacturing_cost'));
        $this->actingAs($admin)->get("/quotations/{$q->id}")->assertSee('1,458.00');

        // The printed quotation is on the official letterhead and never carries a cost figure.
        $this->actingAs($admin)->get("/quotations/{$q->id}/print")->assertOk()
            ->assertSee('img/letterhead-a4.png')->assertSee($q->quotation_no)->assertSee('6,900.00')
            ->assertDontSee('1,458.00')->assertDontSee('2,499.43')->assertDontSee('1,749.60');
    }

    public function test_markup_discount_and_warnings(): void
    {
        $admin = $this->admin();
        [$carp, , $mdf] = $this->rates($admin);
        $q = $this->quotation($admin, ['discount_amount' => 4000]);
        $sheet = "/quotations/{$q->id}/lines/1/costing";
        $this->actingAs($admin)->post($sheet, ['pricing_method' => 'MARGIN', 'target_pct' => 100])->assertSessionHasErrors('target_pct');
        $this->actingAs($admin)->post($sheet, ['pricing_method' => 'MARKUP', 'target_pct' => 25, 'min_margin_pct' => 40]);
        $this->actingAs($admin)->post("{$sheet}/materials", ['material_id' => $mdf->id, 'quantity' => 10, 'waste_pct' => 0, 'unit_price' => 50, 'price_source' => 'TEST quote']);
        $this->actingAs($admin)->post("{$sheet}/operations", ['operation' => 'TEST', 'cost_center_id' => $carp->id, 'labor_hours' => 0, 'setup_hours' => 0])->assertSessionHasErrors('labor_hours');

        // 10 × 2 × 50 = 1,000 (no labour) → fully loaded 1,200 → markup 25% = 1,500; the 4,000 discount leaves 2,000.
        $c = CostEstimate::sole()->costs();
        $this->assertEquals(1500, (float) $c->recommended_price);
        $this->assertEquals(2000, (float) $c->net_price, 'the discount lowers the price, not the cost');
        $this->assertEquals(1000, (float) $c->manufacturing_cost);
        $this->assertSame([], $c->warnings); // margin after full cost = 40%: on the minimum, not below
        $this->actingAs($admin)->put("/quotations/{$q->id}", ['client_id' => $q->client_id, 'issue_date' => '2026-06-01', 'discount_amount' => 4500,
            'lines' => [['description' => 'TEST wardrobe', 'quantity' => 2, 'unit' => 'pc', 'unit_price' => 3000]]])->assertSessionHasNoErrors();
        $this->assertSame(['BELOW_MIN_MARGIN'], CostEstimate::sole()->costs()->warnings);
        $this->actingAs($admin)->get($sheet)->assertSee('الهامش بعد التكاليف الكاملة أقل من الحد الأدنى');

        // Removing the line removes its estimate; the gate then needs the new line costed.
        $this->actingAs($admin)->put("/quotations/{$q->id}", ['client_id' => $q->client_id, 'issue_date' => '2026-06-01', 'discount_amount' => 0, 'lines' => [
            ['description' => 'TEST other', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 100], ['description' => 'TEST two', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 100]]]);
        $this->assertSame(1, CostEstimate::count(), 'line 1 still exists');
        $this->actingAs($admin)->post("/quotations/{$q->id}/lines/2/costing", ['pricing_method' => 'MARGIN', 'target_pct' => 30])->assertSessionHasNoErrors();
        $this->assertSame(2, CostEstimate::count());
        $this->actingAs($admin)->put("/quotations/{$q->id}", ['client_id' => $q->client_id, 'issue_date' => '2026-06-01', 'discount_amount' => 0, 'lines' => [
            ['description' => 'TEST two', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 100]]]);
        $this->assertSame([1], CostEstimate::pluck('line_no')->all(), 'the estimate of the removed line 2 is gone');
        $this->actingAs($admin)->post("/quotations/{$q->id}/lines/1/costing/direct", ['cost_type' => 'OTHER', 'description' => 'x', 'amount' => 1, 'basis' => 'ONE_TIME']);
        $this->actingAs($admin)->put("/quotations/{$q->id}", ['client_id' => $q->client_id, 'issue_date' => '2026-06-01', 'discount_amount' => 0, 'lines' => [
            ['description' => 'TEST two', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 100], ['description' => 'TEST three', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 100]]]);
        $this->actingAs($admin)->post("/quotations/{$q->id}/send");
        $this->actingAs($admin)->post("/quotations/{$q->id}/approve")->assertSessionHasErrors('rule'); // line 2 has no estimate
    }

    public function test_vat_settings_pricing_policy_and_permissions(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/costing/vat-rates', ['name' => 'TEST 15', 'rate_pct' => 15])->assertSessionHasNoErrors();
        $vat = VatRate::sole();
        $this->actingAs($admin)->post("/costing/vat-rates/{$vat->id}/toggle");
        $this->assertFalse($vat->fresh()->is_active);
        $this->actingAs($admin)->post('/costing/pricing', ['effective_from' => '2026-01-01', 'pricing_method' => 'MARGIN', 'target_pct' => 120, 'source' => 'x'])->assertSessionHasErrors('target_pct');
        $this->actingAs($admin)->post('/costing/pricing', ['effective_from' => '2026-01-01', 'pricing_method' => 'MARKUP', 'target_pct' => 120, 'source' => 'TEST board decision'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/costing/pricing/'.PricingPolicy::sole()->id.'/approve')->assertSessionHasNoErrors();
        $this->actingAs($admin)->get('/costing/pricing')->assertOk()->assertSee('120.00%')->assertSee('TEST 15');

        $q = $this->quotation($admin);
        $this->actingAs($admin)->put("/quotations/{$q->id}", ['client_id' => $q->client_id, 'issue_date' => '2026-06-01', 'discount_amount' => 0, 'vat_rate_id' => $vat->id,
            'lines' => [['description' => 'x', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 1]]])->assertSessionHasErrors('vat_rate_id'); // inactive rate
        $this->actingAs($admin)->get("/quotations/{$q->id}")->assertSee('بلا ضريبة قيمة مضافة');

        $sheet = "/quotations/{$q->id}/lines/1/costing";
        $this->actingAs($this->userWith(['quotations.view']))->get($sheet)->assertForbidden();
        $viewer = $this->userWith(['costing.view']);
        $this->actingAs($viewer)->get($sheet)->assertOk();
        $this->actingAs($viewer)->post($sheet, ['pricing_method' => 'MARGIN', 'target_pct' => 30])->assertForbidden();
    }
}
