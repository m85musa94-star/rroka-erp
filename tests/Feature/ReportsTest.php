<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quotation;

class ReportsTest extends ApiTestCase
{
    /** 3 quotes: A approved 1000, A rejected 3000, B sent 500. */
    private function seedQuotations($admin): void
    {
        $a = Client::create(['business_name' => 'ألف', 'client_type' => 'COMPANY', 'city' => 'الرياض']);
        $b = Client::create(['business_name' => 'باء', 'client_type' => 'INDIVIDUAL', 'city' => 'جدة']);
        foreach ([[$a, 1000, 'APPROVED'], [$a, 3000, 'REJECTED'], [$b, 500, 'SENT']] as [$c, $amt, $st]) {
            $q = Quotation::create(['client_id' => $c->id, 'issue_date' => now()->toDateString(), 'discount_amount' => 0]);
            $q->lines()->create(['line_no' => 1, 'description' => 'x', 'quantity' => 1, 'unit' => 'قطعة', 'unit_price' => $amt]);
            $q->forceFill(['status' => 'SENT'])->save();
            if ($st === 'APPROVED') {
                $q->forceFill(['status' => 'APPROVED', 'approved_at' => now(), 'approved_by' => $admin->id])->save();
            } elseif ($st === 'REJECTED') {
                $q->forceFill(['status' => 'REJECTED'])->save();
            }
        }
    }

    public function test_pivot_totals_and_non_additive_conversion(): void
    {
        $admin = $this->admin();
        $this->seedQuotations($admin);

        $this->actingAs($admin)->get('/reports/quotations?rows=client&m=value')->assertOk()
            ->assertSeeInOrder(['ألف', '4,000.00', 'باء', '500.00', 'الإجمالي', '4,500.00']);

        // Conversion = approved / decided: client A 1 of 2 = 50%, B none decided, total 1 of 2 = 50% (not 50+0).
        $this->actingAs($admin)->get('/reports/quotations?rows=client&m=conversion')->assertOk()
            ->assertSeeInOrder(['ألف', '50.0%', 'باء', '—', 'الإجمالي', '50.0%']);
    }

    public function test_rows_by_cols_and_filters(): void
    {
        $admin = $this->admin();
        $this->seedQuotations($admin);

        $this->actingAs($admin)->get('/reports/quotations?rows=client&cols=status&m=count')->assertOk()
            ->assertSee('معتمد')->assertSee('مرفوض');
        $this->actingAs($admin)->get('/reports/quotations?rows=status&m=count&f[]=this_year&v=graph')->assertOk()
            ->assertSee('class="bars"', false);
        // Undecided rows have no conversion rate: shown as not computable, never as 0%.
        $this->actingAs($admin)->get('/reports/quotations?rows=status&m=conversion&v=graph')->assertOk()
            ->assertSee('غير قابل للحساب')->assertSee('0.0%');
    }

    public function test_csv_export_opens_in_excel(): void
    {
        $admin = $this->admin();
        $this->seedQuotations($admin);

        $res = $this->actingAs($admin)->get('/reports/quotations?rows=client&m=value&export=csv');
        $res->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('ألف,4000.00', $body);
        $this->assertStringContainsString('الإجمالي,4500.00', $body);
    }

    public function test_profitability_never_estimates_missing_costs(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->post("/quotations/{$q['id']}/send");
        $this->actingAs($admin)->post("/quotations/{$q['id']}/approve");
        $this->actingAs($admin)->post('/projects', ['quotation_id' => $q['id'], 'title' => 'x', 'start_date' => '2026-09-01']);

        $this->actingAs($admin)->get('/reports/profitability?m=profit')->assertOk()
            ->assertSee('على 0 من 1 مشروعًا فقط', false);
        $this->actingAs($admin)->get('/reports/profitability?m=incomplete')->assertOk()
            ->assertSeeInOrder(['الإجمالي', '1']);
    }

    public function test_report_permissions(): void
    {
        $sales = $this->userWith(['quotations.view']);
        $this->actingAs($sales)->get('/reports')->assertOk()->assertSee('تحليل عروض الأسعار')->assertDontSee('ربحية المشاريع');
        $this->actingAs($sales)->get('/reports/profitability')->assertForbidden();
        $this->actingAs($sales)->get('/reports/quotations')->assertOk();

        $nobody = $this->userWith(['clients.view']);
        $this->actingAs($nobody)->get('/reports')->assertForbidden();
        $this->actingAs($nobody)->get('/')->assertDontSee('التقارير');
    }

    public function test_invalid_parameters_fall_back_to_defaults(): void
    {
        $this->actingAs($this->admin())->get("/reports/quotations?rows=x;drop&cols=y&m=z'&f[]=bad&v=nope")->assertOk();
        $this->actingAs($this->admin())->get('/reports/unknown')->assertNotFound();
    }

    public function test_date_range_from_and_to(): void
    {
        $admin = $this->admin();
        $c = Client::create(['business_name' => 'جيم', 'client_type' => 'COMPANY']);
        foreach ([['2026-01-15', 100], ['2026-02-10', 200], ['2026-03-05', 400]] as [$date, $amt]) {
            $q = Quotation::create(['client_id' => $c->id, 'issue_date' => $date, 'discount_amount' => 0]);
            $q->lines()->create(['line_no' => 1, 'description' => 'x', 'quantity' => 1, 'unit' => 'قطعة', 'unit_price' => $amt]);
        }

        // Inclusive on both ends: Feb + Mar = 600.
        $this->actingAs($admin)->get('/reports/quotations?rows=month&m=value&from=2026-02-10&to=2026-03-05')->assertOk()
            ->assertSee('من <bdi dir="ltr">2026-02-10</bdi> إلى <bdi dir="ltr">2026-03-05</bdi>', false)
            ->assertDontSee('<td>2026-01</td>', false)
            ->assertSeeInOrder(['2026-02', '200.00', '2026-03', '400.00', 'الإجمالي', '600.00']);

        // Only "to": Jan + Feb = 300.
        $this->actingAs($admin)->get('/reports/quotations?rows=month&m=value&to=2026-02-28')->assertOk()
            ->assertSee('حتى <bdi dir="ltr">2026-02-28</bdi>', false)->assertSeeInOrder(['الإجمالي', '300.00']);

        // Reversed dates are swapped, and the owner is told.
        $this->actingAs($admin)->get('/reports/quotations?rows=month&m=value&from=2026-03-31&to=2026-02-01')->assertOk()
            ->assertSee('عُكس التاريخان')->assertSeeInOrder(['الإجمالي', '600.00']);

        // Garbage dates are ignored, not injected.
        $this->actingAs($admin)->get("/reports/quotations?m=value&from=2026-13-45&to=x'or'1")->assertOk()
            ->assertSee('كل الفترات');

        // The range is kept in the CSV and when switching to the graph.
        $csv = $this->actingAs($admin)->get('/reports/quotations?rows=month&m=value&from=2026-02-01&export=csv')->streamedContent();
        $this->assertStringContainsString('تاريخ إصدار العرض: من 2026-02-01', $csv);
        $this->assertStringContainsString('الإجمالي,600.00', $csv);
        $this->actingAs($admin)->get('/reports/quotations?rows=month&m=value&from=2026-02-01')->assertOk()
            ->assertSee('from=2026-02-01', false);
    }
}
