<?php

namespace Tests\Feature;

use App\Reports\ReportRegistry;

/** Every report, by every dimension and measure, renders (and exports) over the labelled demo data. */
class ModuleReportsTest extends ApiTestCase
{
    public function test_every_report_every_dimension_and_measure_renders(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/settings/demo')->assertSessionHasNoErrors();
        $index = $this->actingAs($admin)->get('/reports')->assertOk();
        foreach (ReportRegistry::sections() as $label) {
            $index->assertSee($label);
        }
        foreach (ReportRegistry::all() as $key => $r) {
            $index->assertSee(route('reports.show', $key), false);
            foreach (array_keys($r->dimensions()) as $dim) {
                foreach (array_keys($r->measures()) as $m) {
                    $this->actingAs($admin)->get("/reports/$key?rows=$dim&m=$m")->assertOk();
                }
            }
            $dims = array_keys($r->dimensions());
            if (count($dims) > 1) {
                $this->actingAs($admin)->get("/reports/$key?rows={$dims[0]}&cols={$dims[1]}&v=graph")->assertOk();
            }
            $this->actingAs($admin)->get("/reports/$key?export=xlsx")->assertOk();
            $this->actingAs($admin)->get("/reports/$key?print=1")->assertOk();
        }
        // Demo numbers flow through: hours logged on manufacturing orders, inspections, stock value.
        $this->actingAs($admin)->get('/reports/labor')->assertSee('الساعات');
        $this->actingAs($admin)->get('/reports/quality?m=pass_rate')->assertSee('%');
    }

    public function test_reports_follow_their_module_permissions(): void
    {
        $hr = $this->userWith(['hr.view']);
        $this->actingAs($hr)->get('/reports')->assertOk()->assertSee('تحليل الموظفين')->assertDontSee('تحليل القيود');
        $this->actingAs($hr)->get('/reports/employees')->assertOk();
        $this->actingAs($hr)->get('/reports/ledger')->assertForbidden();
        $this->actingAs($hr)->get('/')->assertSee(route('reports.index'), false);
    }
}
