<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\PaymentAccount;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\VatReturn;
use Illuminate\Support\Facades\DB;

/** VAT report from the documents, the filed return (lock, settlement, payment), the unverified bucket and reopening. */
class VatReturnTest extends ApiTestCase
{
    private function acc(string $code): int
    {
        return Account::where('code', $code)->value('id');
    }

    public function test_report_return_lock_settlement_and_payment(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash box'])->assertSessionHasNoErrors();
        $cash = PaymentAccount::sole();
        $cat = ExpenseCategory::create(['name' => 'TEST supplies', 'is_overhead' => false]);
        $this->actingAs($admin)->put('/accounting/posting/links', ['payment' => [$cash->id => $this->acc('1101')], 'category' => [$cat->id => $this->acc('5205')]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/posting/auto', ['auto_posting' => 1])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put('/accounting/vat/settings', ['vat_period' => 'MONTHLY'])->assertSessionHasNoErrors();
        $client = Client::create(['business_name' => 'TEST client']);
        $registered = Supplier::create(['name' => 'TEST registered', 'vat_number' => '300000000000003']);
        $unregistered = Supplier::create(['name' => 'TEST unregistered']);

        $sale = fn (array $x) => tap($this->actingAs($admin)->post('/sales/invoices', $x + ['kind' => 'INVOICE', 'client_id' => $client->id, 'description' => 'TEST'])
            ->assertSessionHasNoErrors(), fn () => $this->actingAs($admin)->post('/sales/invoices/'.SalesInvoice::latest('id')->value('id').'/approve')->assertSessionHasNoErrors());
        $sale(['daftra_invoice_no' => 'INV-1', 'invoice_date' => '2026-05-10', 'amount' => 10000, 'vat_amount' => 1500]);
        $sale(['daftra_invoice_no' => 'INV-2', 'invoice_date' => '2026-05-12', 'amount' => 1000, 'vat_amount' => 100]);   // 10%: flagged
        $expense = function (Supplier $s, float $amount, float $vat, string $date = '2026-05-15') use ($admin, $cash, $cat) {
            $this->actingAs($admin)->post('/expenses', ['expense_date' => $date, 'category_id' => $cat->id, 'supplier_id' => $s->id, 'description' => 'TEST',
                'amount' => $amount, 'vat_amount' => $vat, 'payment_account_id' => $cash->id])->assertSessionHasNoErrors();
            $x = Expense::latest('id')->first();

            return [$x, $this->actingAs($admin)->post("/expenses/{$x->id}/approve")];
        };
        $expense($registered, 2000, 300);
        $expense($unregistered, 400, 60);

        // The report: output 1,600; deductible input 300; 60 to verify; net 1,300; books agree.
        $this->actingAs($admin)->get('/accounting/vat/report?from=2026-05-01&to=2026-05-31')->assertOk()
            ->assertSee('1,600.00')->assertSee('300.00')->assertSee('60.00')->assertSee('1,300.00')->assertSee(SalesInvoice::where('daftra_invoice_no', 'INV-2')->value('invoice_no'));
        $this->actingAs($admin)->get('/accounting/vat/report?from=2026-05-01&to=2026-05-31&print=1')->assertOk();
        $this->assertStringStartsWith('PK', $this->actingAs($admin)->get('/accounting/vat/report?from=2026-05-01&to=2026-05-31&export=xlsx')->assertOk()->getContent());
        $this->actingAs($admin)->get('/accounting/vat')->assertOk()->assertSee('2026-05')->assertSee(route('accounting.vat.create', ['from' => '2026-05-01', 'to' => '2026-05-31']));

        // Including the unverified VAT needs the reason.
        $this->actingAs($admin)->post('/accounting/vat/returns', ['period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'filed_output_vat' => 1600,
            'filed_input_vat' => 300, 'include_verify' => 1])->assertSessionHasErrors('verify_reason');
        $this->actingAs($admin)->post('/accounting/vat/returns', ['period_start' => '2026-05-01', 'period_end' => '2026-05-31'])->assertSessionHasNoErrors();
        $vr = VatReturn::sole();
        $this->actingAs($admin)->post("/accounting/vat/returns/{$vr->id}/approve")->assertSessionHasErrors('rule');   // filed figures missing
        $this->actingAs($admin)->put("/accounting/vat/returns/{$vr->id}", ['period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'filed_output_vat' => 1600, 'filed_input_vat' => 300])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/accounting/vat/returns/{$vr->id}/approve")->assertSessionHasNoErrors();
        $vr->refresh();
        $this->assertSame(['1600.00', '300.00', '60.00'], [$vr->sys_output_vat, $vr->sys_input_vat, $vr->sys_verify_vat]);
        $this->assertSame('2026-05-31', DB::table('accounting_settings')->value('vat_locked_until'));
        $this->assertNotNull(JournalEntry::forDocument('VAT_RETURN', $vr->id));
        $this->assertEquals(-1300, DB::table('v_ledger_lines')->where('account_id', $this->acc('2115'))->sum('net'));

        // A late taxed receipt in May is refused; reopening needs the reason and is logged.
        [$late, $res] = $expense($registered, 100, 15, '2026-05-20');
        $res->assertSessionHasErrors('rule');
        $this->assertSame('DRAFT', $late->fresh()->status);
        $this->actingAs($admin)->post('/accounting/vat/reopen', ['until' => '2026-04-30', 'reason' => ''])->assertSessionHasErrors('reason');

        // Payment: never more than due, then posted.
        $this->actingAs($admin)->post("/accounting/vat/returns/{$vr->id}/pay", ['paid_date' => '2026-06-20', 'paid_account_id' => $cash->id, 'paid_amount' => 1500])->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post("/accounting/vat/returns/{$vr->id}/pay", ['paid_date' => '2026-06-20', 'paid_account_id' => $cash->id, 'paid_amount' => 1300, 'paid_reference' => 'SADAD 1'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull(JournalEntry::forDocument('VAT_PAYMENT', $vr->id));
        $this->assertEquals(0, DB::table('v_ledger_lines')->where('account_id', $this->acc('2115'))->sum('net'));
        $this->actingAs($admin)->get("/accounting/vat/returns/{$vr->id}")->assertOk()->assertSee('SADAD 1')->assertSee('1,300.00');
        $this->actingAs($admin)->get('/accounting/reconciliation')->assertOk();

        $this->actingAs($admin)->post('/accounting/vat/reopen', ['until' => '2026-04-30', 'reason' => 'TEST late receipt'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/expenses/{$late->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($admin)->get('/accounting/vat')->assertOk()->assertSee('TEST late receipt');
    }

    public function test_permissions(): void
    {
        $viewer = $this->userWith(['accounting.view']);
        $this->actingAs($viewer)->get('/accounting/vat')->assertOk();
        $this->actingAs($viewer)->get('/accounting/vat/returns/create')->assertForbidden();
        $this->actingAs($viewer)->post('/accounting/vat/reopen', ['reason' => 'x'])->assertForbidden();
        $clerk = $this->userWith(['accounting.view', 'accounting.manage']);
        $this->actingAs($clerk)->post('/accounting/vat/returns', ['period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'filed_output_vat' => 0, 'filed_input_vat' => 0])
            ->assertSessionHasNoErrors();
        $this->actingAs($clerk)->post('/accounting/vat/returns/'.VatReturn::value('id').'/approve')->assertForbidden();
        $this->actingAs($clerk)->put('/accounting/vat/settings', ['vat_period' => 'MONTHLY'])->assertForbidden();
    }
}
