<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

class QuotationProjectFlowTest extends ApiTestCase
{
    public function test_full_flow_from_quotation_to_project(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);

        $this->assertSame('DRAFT', $q['status']);
        $this->assertEquals(3500, $q['totals']['net_before_vat']); // 2x1500 + 700 - 200

        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/send")->assertOk()->assertJsonPath('status', 'SENT');
        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/approve")->assertOk()->assertJsonPath('status', 'APPROVED');

        $project = $this->actingAs($admin)->postJson('/api/projects', [
            'quotation_id' => $q['id'], 'title' => 'مطبخ اختبار',
        ])->assertCreated()->json();

        $this->assertEquals(3500, $project['contract_value']);
        $this->assertSame($q['client_id'], $project['client_id']);
    }

    public function test_project_refused_on_unapproved_quotation(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);

        $this->actingAs($admin)->postJson('/api/projects', ['quotation_id' => $q['id'], 'title' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'RROKA_PROJECT_NEEDS_APPROVED_QUOTATION');
    }

    public function test_sent_quotation_cannot_be_edited(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/send")->assertOk();

        $this->actingAs($admin)->putJson("/api/quotations/{$q['id']}", [
            'client_id' => $q['client_id'],
            'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertStatus(422)->assertJsonPath('error', 'RROKA_QUOTATION_LOCKED');
    }

    public function test_draft_quotation_can_be_edited(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);

        $this->actingAs($admin)->putJson("/api/quotations/{$q['id']}", [
            'client_id' => $q['client_id'],
            'discount_amount' => 50,
            'lines' => [['description' => 'رف', 'quantity' => 3, 'unit_price' => 100]],
        ])->assertOk()->assertJsonCount(1, 'lines')->assertJsonPath('totals.net_before_vat', '250.00');
    }

    public function test_approval_requires_permission(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/send")->assertOk();

        $clerk = $this->userWith(['quotations.view', 'quotations.manage']);
        $this->actingAs($clerk)->postJson("/api/quotations/{$q['id']}/approve")
            ->assertForbidden()
            ->assertJsonPath('permission', 'quotations.approve');
    }

    public function test_audit_log_records_acting_user(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);

        $this->assertTrue(DB::table('audit_log')
            ->where(['table_name' => 'quotations', 'row_id' => $q['id'], 'user_id' => $admin->id])
            ->exists());
    }

    public function test_costing_is_explicitly_incomplete_without_rates(): void
    {
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/send");
        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/approve");
        $project = $this->actingAs($admin)->postJson('/api/projects', ['quotation_id' => $q['id'], 'title' => 'x'])->json();

        $this->actingAs($admin)->getJson("/api/projects/{$project['id']}/costing")
            ->assertOk()
            ->assertJsonPath('is_complete', false)
            ->assertJsonPath('total_cost', null)
            ->assertJsonPath('gross_profit', null)
            ->assertJsonPath('costing_gaps', ['OVERHEAD_RATE_MISSING']);
    }

    public function test_login_issues_token_and_rejects_bad_password(): void
    {
        $user = $this->admin();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnauthorized();
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/clients')->assertOk();
    }
}
