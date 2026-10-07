<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Services\DemoData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DemoDataTest extends ApiTestCase
{
    private function disabledTriggers(): int
    {
        return (int) DB::scalar("select count(*) from pg_trigger where not tgisinternal and tgenabled <> 'O'");
    }

    public function test_load_view_every_screen_and_remove(): void
    {
        Storage::fake('studio');
        $admin = $this->admin();
        $this->actingAs($admin)->get('/settings/demo')->assertOk()->assertSee('تحميل البيانات التجريبية');
        $this->actingAs($admin)->post('/settings/demo')->assertSessionHasNoErrors()->assertSessionHas('ok');

        // Two or three labelled samples per screen, through the real workflow states.
        $this->assertSame(3, Client::where('business_name', 'like', 'تجريبي — %')->count());
        $this->assertSame(['APPROVED', 'APPROVED', 'DRAFT', 'SENT'], DB::table('quotations')->orderBy('status')->pluck('status')->all());
        $this->assertSame(['COMPLETED', 'IN_PROGRESS'], ProductionOrder::orderBy('status')->pluck('status')->all());
        $this->assertSame(3, DB::table('studio_assets')->count());
        $cost = DB::table('v_project_actual_cost')->where('status', 'IN_PRODUCTION')->first();
        $this->assertEquals(22000, (float) $cost->contract_value);
        $this->assertEquals(1112.5, (float) $cost->labor_cost);
        $this->assertNull($cost->gross_profit, 'the overhead rate is global and left unset');
        $this->assertEquals(1793, (float) DB::table('v_payment_account_summary')->whereNotNull('custody_balance')->value('custody_balance'));

        foreach (['/', '/clients', '/quotations', '/projects', '/designs', '/production', '/inventory', '/suppliers', '/purchases', '/expenses',
            '/employees', '/departments', '/contracts', '/attendance', '/attendance/records', '/leaves', '/leaves/settings', '/studio', '/settings/rates',
            '/reports/profitability', '/reports/purchases', '/reports/expenses', '/treasury/accounts', '/treasury/transfers'] as $page) {
            $this->actingAs($admin)->get($page)->assertOk()->assertSee('تجريبي');
        }
        $this->actingAs($admin)->get('/production/'.ProductionOrder::where('status', 'IN_PROGRESS')->value('id'))->assertOk()->assertSee('3 مم');

        $this->actingAs($admin)->post('/settings/demo')->assertSessionHasErrors('rule');
        $this->actingAs($admin)->get('/settings/demo')->assertSee('حذف البيانات التجريبية');

        $this->actingAs($admin)->delete('/settings/demo')->assertSessionHasNoErrors();
        foreach (['clients', 'quotations', 'projects', 'stock_movements', 'stock_balances', 'workers', 'attendances', 'expenses', 'purchase_invoices', 'studio_assets', 'payment_accounts', 'treasury_transfers', 'demo_records'] as $t) {
            $this->assertSame(0, DB::table($t)->count(), $t);
        }
        $this->assertSame([], Storage::disk('studio')->allFiles());
        $this->assertSame(0, $this->disabledTriggers());
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'demo_records')->where('action', 'DELETE')->count());
    }

    public function test_removal_is_refused_when_real_records_are_linked(): void
    {
        Storage::fake('studio');
        $admin = $this->admin();
        $this->artisan('rroka:demo', ['--user' => $admin->id])->assertSuccessful();
        $client = Client::where('business_name', 'like', '%الواحة%')->sole();
        $this->actingAs($admin)->postJson('/api/quotations', ['client_id' => $client->id, 'discount_amount' => 0,
            'lines' => [['description' => 'real', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 10]]])->assertCreated();

        $n = DB::table('demo_records')->count();
        $this->actingAs($admin)->delete('/settings/demo')->assertSessionHasErrors('rule');
        $this->assertSame($n, DB::table('demo_records')->count());
        $this->assertTrue(Client::whereKey($client->id)->exists());
        $this->assertSame(0, $this->disabledTriggers());
        $this->artisan('rroka:demo', ['--purge' => true, '--user' => $admin->id])->assertFailed();

        // The page names what blocks the removal; after review the owner can remove it with the demo data.
        $this->actingAs($admin)->get('/settings/demo')->assertOk()->assertSee('سجلات غير تجريبية مرتبطة بالبيانات التجريبية')->assertSee('عروض الأسعار');
        $this->assertArrayHasKey('quotations', DemoData::blockers());
        $this->actingAs($admin)->delete('/settings/demo', ['with_linked' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('demo_records')->count());
        $this->assertFalse(Client::whereKey($client->id)->exists());
        $this->assertSame(0, $this->disabledTriggers());
    }

    public function test_a_real_record_on_a_demo_production_order_is_named_and_removable(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/settings/demo')->assertSessionHasNoErrors();
        $order = DB::table('demo_records')->where('table_name', 'production_orders')->value('row_id');
        $worker = DB::table('demo_records')->where('table_name', 'workers')->value('row_id');
        $log = DB::table('labor_logs')->insertGetId(['production_order_id' => $order, 'worker_id' => $worker, 'work_date' => now()->toDateString(), 'hours' => 1, 'created_by' => $admin->id]);
        $this->actingAs($admin)->delete('/settings/demo')->assertSessionHasErrors(['rule' => __('لا يمكن حذف البيانات التجريبية: توجد سجلات حقيقية مرتبطة بها (:table). احذف الربط أولًا.', ['table' => 'labor_logs'])]);
        $this->assertSame(['labor_logs' => [$log]], DemoData::blockers());
        $this->actingAs($admin)->delete('/settings/demo', ['with_linked' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('production_orders')->count());
        $this->assertSame(0, DB::table('labor_logs')->count());
    }

    public function test_only_user_managers_can_load_or_remove(): void
    {
        $user = $this->userWith(['clients.view']);
        $this->actingAs($user)->get('/settings/demo')->assertForbidden();
        $this->actingAs($user)->post('/settings/demo')->assertForbidden();
        $this->actingAs($user)->delete('/settings/demo')->assertForbidden();
        $this->assertSame(0, DB::table('demo_records')->count());
    }
}
