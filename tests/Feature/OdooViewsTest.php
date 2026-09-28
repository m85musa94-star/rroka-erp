<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Quotation;

class OdooViewsTest extends ApiTestCase
{
    private function seedQuotes($admin): void
    {
        $a = Client::create(['business_name' => 'مؤسسة ألف', 'client_type' => 'COMPANY', 'city' => 'الرياض']);
        $b = Client::create(['business_name' => 'باسل', 'client_type' => 'INDIVIDUAL', 'city' => 'جدة']);
        foreach ([[$a, 'SENT'], [$a, 'DRAFT'], [$b, 'SENT']] as [$c, $st]) {
            $q = Quotation::create(['client_id' => $c->id, 'issue_date' => now()->toDateString(), 'discount_amount' => 0]);
            $q->lines()->create(['line_no' => 1, 'description' => 'بند', 'quantity' => 1, 'unit' => 'قطعة', 'unit_price' => 1000]);
            if ($st === 'SENT') {
                $q->forceFill(['status' => 'SENT'])->save();
            }
        }
    }

    public function test_filter_search_and_group_on_quotations(): void
    {
        $admin = $this->admin();
        $this->seedQuotes($admin);

        $this->actingAs($admin)->get('/quotations?f[]=sent')->assertOk()
            ->assertSee('بانتظار رد العميل', false)
            ->assertSee('مؤسسة ألف')->assertSee('باسل')
            ->assertSeeText('1–2 / 2');

        $this->actingAs($admin)->get('/quotations?f[]=sent&q=باسل')->assertOk()
            ->assertSeeText('1–1 / 1');

        $this->actingAs($admin)->get('/quotations?g=client')->assertOk()
            ->assertSee('class="grp"', false)
            ->assertSee('2,000.00');
    }

    public function test_kanban_columns_by_stage(): void
    {
        $admin = $this->admin();
        $this->seedQuotes($admin);

        $this->actingAs($admin)->get('/quotations?v=kanban')->assertOk()
            ->assertSee('class="kanban"', false)
            ->assertSee('b-DRAFT', false)->assertSee('b-SENT', false)->assertSee('b-APPROVED', false);
        $this->actingAs($admin)->get('/projects?v=kanban')->assertOk()->assertSee('class="kanban"', false);
        $this->actingAs($admin)->get('/clients?v=kanban')->assertOk()->assertSee('kb-cards', false);
    }

    public function test_client_filters_and_top_menu(): void
    {
        $admin = $this->admin();
        $this->seedQuotes($admin);

        $this->actingAs($admin)->get('/clients?f[]=company')->assertOk()
            ->assertSee('مؤسسة ألف')->assertDontSee('>باسل<', false)
            ->assertSee('class="tb-menu"', false)
            ->assertSee('المنشآت');
    }

    public function test_record_page_has_breadcrumbs_statusbar_and_activity(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->post("/quotations/{$q['id']}/send");

        $this->actingAs($admin)->get("/quotations/{$q['id']}")->assertOk()
            ->assertSee('class="crumbs"', false)
            ->assertSee('class="statusbar"', false)
            ->assertSee('سجل النشاط', false)
            ->assertSee('أنشأ عرض السعر', false)
            ->assertSee('أضاف بندًا', false)
            ->assertSee($admin->name);
    }

    public function test_activity_log_hidden_without_permission(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $viewer = $this->userWith(['quotations.view']);

        $this->actingAs($viewer)->get("/quotations/{$q['id']}")->assertOk()->assertDontSee('سجل النشاط', false);
    }

    public function test_unknown_filter_and_view_values_are_ignored(): void
    {
        $this->actingAs($this->admin())->get('/quotations?f[]=bogus&g=nope&v=pivot')->assertOk();
    }

    public function test_project_moves_through_stages_from_its_page(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->post("/quotations/{$q['id']}/send");
        $this->actingAs($admin)->post("/quotations/{$q['id']}/approve");
        $this->actingAs($admin)->post('/projects', ['quotation_id' => $q['id'], 'title' => 'x', 'start_date' => '2026-09-01']);
        $id = Project::first()->id;

        $this->actingAs($admin)->get("/projects/{$id}")->assertSee('بدء الإنتاج');
        $this->actingAs($admin)->from("/projects/{$id}")->post("/projects/{$id}/stage/COMPLETED")
            ->assertSessionHasErrors(['rule' => 'هذا الانتقال بين مراحل المشروع غير مسموح.']);
        foreach (['IN_PRODUCTION', 'INSTALLATION', 'COMPLETED'] as $st) {
            $this->actingAs($admin)->post("/projects/{$id}/stage/{$st}")->assertSessionHas('ok');
        }
        $this->assertNotNull(Project::find($id)->completed_at);

        $viewer = $this->userWith(['projects.view']);
        $this->actingAs($viewer)->post("/projects/{$id}/stage/ACTIVE")->assertForbidden();
    }
}
