<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DaftraSyncTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'daftra.subdomain' => 'rroka-test',
            'daftra.api_key' => 'test-key',
            'daftra.token' => 'test-token',
        ]);
    }

    public function test_client_sync_sends_verified_shape_and_stores_daftra_ids(): void
    {
        Http::fake(['rroka-test.daftra.com/api2/clients' => Http::response(['id' => 77, 'client_number' => '000077'], 202)]);
        $admin = $this->admin();
        $client = $this->actingAs($admin)->postJson('/api/clients', ['business_name' => 'مؤسسة الاختبار', 'phone' => '0500000000'])->json();

        $this->actingAs($admin)->postJson("/api/clients/{$client['id']}/sync-to-daftra")
            ->assertOk()
            ->assertJsonPath('daftra_client_id', 77)
            ->assertJsonPath('daftra_client_number', '000077');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://rroka-test.daftra.com/api2/clients'
            && $r->method() === 'POST'
            && $r->hasHeader('apikey', 'test-key')
            && $r->hasHeader('Authorization', 'Bearer test-token')
            && $r->data() === ['Client' => ['business_name' => 'مؤسسة الاختبار']]);

        $log = DB::table('daftra_sync_log')->where('entity_id', $client['id'])->first();
        $this->assertSame('SUCCESS', $log->status);
        $this->assertSame(77, (int) $log->daftra_id);
    }

    public function test_failed_sync_is_logged_and_reported(): void
    {
        Http::fake(['*' => Http::response(['message' => 'invalid'], 400)]);
        $admin = $this->admin();
        $client = $this->actingAs($admin)->postJson('/api/clients', ['business_name' => 'x'])->json();

        $this->actingAs($admin)->postJson("/api/clients/{$client['id']}/sync-to-daftra")
            ->assertStatus(502)->assertJsonPath('error', 'DAFTRA_SYNC_FAILED');

        $log = DB::table('daftra_sync_log')->where('entity_id', $client['id'])->first();
        $this->assertSame('FAILED', $log->status);
        $this->assertSame(400, $log->http_status);
        $this->assertNull(DB::table('clients')->where('id', $client['id'])->value('daftra_client_id'));
    }

    public function test_client_cannot_be_synced_twice(): void
    {
        Http::fake(['*' => Http::response(['id' => 5], 202)]);
        $admin = $this->admin();
        $client = $this->actingAs($admin)->postJson('/api/clients', ['business_name' => 'x'])->json();

        $this->actingAs($admin)->postJson("/api/clients/{$client['id']}/sync-to-daftra")->assertOk();
        $this->actingAs($admin)->postJson("/api/clients/{$client['id']}/sync-to-daftra")
            ->assertStatus(409)->assertJsonPath('error', 'DAFTRA_ALREADY_SYNCED');
        Http::assertSentCount(1);
    }

    public function test_missing_credentials_fail_without_calling_out(): void
    {
        Http::fake();
        config(['daftra.token' => null]);
        $admin = $this->admin();
        $client = $this->actingAs($admin)->postJson('/api/clients', ['business_name' => 'x'])->json();

        $this->actingAs($admin)->postJson("/api/clients/{$client['id']}/sync-to-daftra")->assertStatus(502);
        Http::assertNothingSent();
    }

    public function test_estimate_sync_blocked_until_mapping_verified(): void
    {
        Http::fake();
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/send");

        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/sync-to-daftra")
            ->assertStatus(409)->assertJsonPath('error', 'DAFTRA_ESTIMATE_MAPPING_NOT_VERIFIED');
        Http::assertNothingSent();
    }

    public function test_estimate_sync_requires_synced_client(): void
    {
        Http::fake();
        config(['daftra.estimate_mapping_verified' => true]);
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/send");

        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/sync-to-daftra")
            ->assertStatus(409)->assertJsonPath('error', 'DAFTRA_CLIENT_NOT_SYNCED');
    }

    public function test_approved_quotation_can_still_be_linked_to_daftra(): void
    {
        Http::fake([
            '*/clients' => Http::response(['id' => 9], 202),
            '*/estimates' => Http::response(['id' => 3001], 202),
        ]);
        config(['daftra.estimate_mapping_verified' => true]);
        $admin = $this->admin();
        $q = $this->draftQuotation($admin);
        $this->actingAs($admin)->postJson("/api/clients/{$q['client_id']}/sync-to-daftra")->assertOk();
        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/send");
        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/approve");

        $this->actingAs($admin)->postJson("/api/quotations/{$q['id']}/sync-to-daftra")
            ->assertOk()->assertJsonPath('daftra_estimate_id', 3001);
    }
}
