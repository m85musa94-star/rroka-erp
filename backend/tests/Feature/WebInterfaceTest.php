<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WebInterfaceTest extends ApiTestCase
{
    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('تسجيل الدخول', false);
    }

    public function test_login_and_inactive_user_refused(): void
    {
        $user = $this->admin();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
        $this->post('/logout');

        $user->forceFill(['is_active' => false])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivated_user_is_signed_out_mid_session(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->get('/')->assertOk();
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

        $this->actingAs($user->fresh())->get('/')->assertRedirect('/login');
    }

    public function test_pages_render_for_admin(): void
    {
        $admin = $this->admin();
        foreach (['/', '/clients', '/clients/create', '/quotations', '/quotations/create', '/projects',
            '/projects/create', '/settings/rates', '/users', '/users/create', '/roles', '/roles/create'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_restricted_user_sees_only_what_is_granted(): void
    {
        $viewer = $this->userWith(['clients.view']);

        $this->actingAs($viewer)->get('/')->assertOk()
            ->assertDontSee('قيمة عقود المشاريع الجارية')
            ->assertDontSee('التكلفة غير مكتملة')
            ->assertDontSee('معدلات التكلفة');
        $this->actingAs($viewer)->get('/clients')->assertOk();
        $this->actingAs($viewer)->get('/settings/rates')->assertForbidden();
        $this->actingAs($viewer)->get('/users')->assertForbidden();
        $this->actingAs($viewer)->post('/clients', ['business_name' => 'x', 'client_type' => 'INDIVIDUAL'])->assertForbidden();
    }

    public function test_clerk_cannot_approve_quotation(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        Quotation::find($q['id'])->forceFill(['status' => 'SENT'])->save();

        $clerk = $this->userWith(['quotations.view', 'quotations.manage']);
        $this->actingAs($clerk)->post("/quotations/{$q['id']}/approve")->assertForbidden();
        $this->assertSame('SENT', Quotation::find($q['id'])->status);
    }

    public function test_quotation_to_project_through_the_screens(): void
    {
        $admin = $this->admin();
        $client = Client::create(['business_name' => 'عميل', 'client_type' => 'INDIVIDUAL']);

        $this->actingAs($admin)->post('/quotations', [
            'client_id' => $client->id, 'issue_date' => '2026-09-27', 'discount_amount' => 100,
            'lines' => [['description' => 'مكتب', 'quantity' => 2, 'unit' => 'قطعة', 'unit_price' => 800]],
        ])->assertRedirect();
        $q = Quotation::latest('id')->first();

        $this->actingAs($admin)->post("/quotations/{$q->id}/send")->assertSessionHas('ok');
        $this->actingAs($admin)->post("/quotations/{$q->id}/approve")->assertSessionHas('ok');
        $this->actingAs($admin)->post('/projects', ['quotation_id' => $q->id, 'title' => 'مكاتب', 'start_date' => '2026-09-27'])
            ->assertRedirect();

        $project = DB::table('projects')->where('quotation_id', $q->id)->first();
        $this->assertEquals(1500, $project->contract_value);
        $this->actingAs($admin)->get("/projects/{$project->id}")->assertOk()
            ->assertSee('نسبة المصروفات غير المباشرة غير مُدخلة', false)
            ->assertSee('غير مكتمل', false);
    }

    public function test_database_rule_refusal_is_shown_in_arabic(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);

        $this->actingAs($admin)->from('/projects/create')
            ->post('/projects', ['quotation_id' => $q['id'], 'title' => 'x', 'start_date' => '2026-09-27'])
            ->assertRedirect('/projects/create')
            ->assertSessionHasErrors(['rule' => 'لا يُنشأ مشروع إلا على عرض سعر معتمد.']);
    }

    public function test_cost_rates_entry_is_append_only_and_requires_basis(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/settings/rates/workers', ['name' => 'عامل'])->assertSessionHas('ok');
        $worker = DB::table('workers')->first();

        $this->actingAs($admin)->post("/settings/rates/workers/{$worker->id}/rates", ['hourly_cost' => 30, 'effective_from' => '2026-09-01'])
            ->assertSessionHasErrors('basis_note');
        $this->actingAs($admin)->post("/settings/rates/workers/{$worker->id}/rates", ['hourly_cost' => 0, 'effective_from' => '2026-09-01', 'basis_note' => 'x'])
            ->assertSessionHasErrors('hourly_cost');
        $this->actingAs($admin)->post("/settings/rates/workers/{$worker->id}/rates", ['hourly_cost' => 30, 'effective_from' => '2026-09-01', 'basis_note' => 'راتب ÷ ساعات'])
            ->assertSessionHas('ok');
        $this->actingAs($admin)->post("/settings/rates/workers/{$worker->id}/rates", ['hourly_cost' => 35, 'effective_from' => '2026-10-01', 'basis_note' => 'زيادة'])
            ->assertSessionHas('ok');

        $this->assertSame(2, DB::table('worker_rates')->where('worker_id', $worker->id)->count());
    }

    public function test_user_cannot_deactivate_self(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put("/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'is_active' => 0])
            ->assertSessionHasErrors('is_active');
        $this->assertTrue(User::find($admin->id)->is_active);
    }
}
