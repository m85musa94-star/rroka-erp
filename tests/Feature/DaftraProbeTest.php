<?php

namespace Tests\Feature;

use App\Services\Daftra\DaftraProbe;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DaftraProbeTest extends ApiTestCase
{
    private function configure(): void
    {
        config(['daftra.subdomain' => 'rroka-test', 'daftra.api_key' => 'test-key', 'daftra.token' => 'test-token']);
    }

    public function test_shape_lists_field_names_and_types_without_values(): void
    {
        $shape = DaftraProbe::shape([
            'data' => [['Invoice' => ['id' => 55, 'no' => 'INV-1', 'summary_total' => '1150.00', 'client_business_name' => 'Secret Co']]],
            'pagination' => ['page' => 1, 'total' => 9],
            'by_id' => ['101' => ['x' => 1], '102' => ['x' => 2]],
        ]);

        $this->assertContains('data[].Invoice.summary_total: string', $shape);
        $this->assertContains('data[].Invoice.id: int', $shape);
        $this->assertContains('pagination.total: int', $shape);
        $this->assertContains('by_id.#.x: int', $shape);
        $text = implode("\n", $shape);
        $this->assertStringNotContainsString('Secret Co', $text);
        $this->assertStringNotContainsString('1150', $text);
        $this->assertStringNotContainsString('101', $text);
    }

    public function test_probe_page_reports_each_endpoint_read_only(): void
    {
        $this->configure();
        Http::fake([
            'rroka-test.daftra.com/api2/invoices.json*' => Http::response(['data' => [['Invoice' => ['id' => 1, 'summary_paid' => '10']]]], 200),
            'rroka-test.daftra.com/api2/expenses.json*' => Http::response(['message' => 'forbidden'], 403),
            '*' => Http::response(['message' => 'not found'], 404),
        ]);
        $admin = $this->admin();
        $before = DB::table('audit_log')->count();

        $this->actingAs($admin)->get('/settings/daftra')->assertOk()->assertSee('rroka-test.daftra.com');
        $this->actingAs($admin)->post('/settings/daftra/probe')->assertOk()
            ->assertSee('data[].Invoice.summary_paid: string')
            ->assertSee('GET /invoices.json')
            ->assertSee('مرفوض — صلاحية المفتاح');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'limit=1') && $r->hasHeader('apikey', 'test-key'));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
        $this->assertSame($before, DB::table('audit_log')->count());
    }

    public function test_probe_needs_credentials_and_permission(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/settings/daftra')->assertOk()->assertSee('غير مُدخلة')->assertDontSee('تشغيل الفحص');
        $this->actingAs($admin)->post('/settings/daftra/probe')->assertStatus(422);

        $this->configure();
        $viewer = $this->userWith(['clients.view']);
        $this->actingAs($viewer)->get('/settings/daftra')->assertForbidden();
        $this->actingAs($viewer)->post('/settings/daftra/probe')->assertForbidden();
    }

    public function test_command_prints_structure(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $this->artisan('rroka:daftra-probe')->expectsOutputToContain('## invoices GET /invoices.json → 200')->assertSuccessful();

        config(['daftra.token' => null]);
        $this->artisan('rroka:daftra-probe')->assertFailed();
    }
}
