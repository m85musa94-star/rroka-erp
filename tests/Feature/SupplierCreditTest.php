<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentAccount;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;

/** Supplier invoices on credit (owed, cost on the project) and supplier payments that settle them. */
class SupplierCreditTest extends ApiTestCase
{
    private function acc(string $code): int
    {
        return Account::where('code', $code)->value('id');
    }

    private function lines(string $type, int $id): array
    {
        return DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.source_type', $type)->where('j.source_id', $id)->orderBy('a.code')
            ->get(['a.code', 'l.debit', 'l.credit', 'l.partner_type', 'l.project_id'])
            ->map(fn ($l) => [$l->code, (float) $l->debit, (float) $l->credit, $l->partner_type, $l->project_id])->all();
    }

    public function test_invoice_on_credit_then_payments(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash box'])->assertSessionHasNoErrors();
        $cash = PaymentAccount::sole();
        $cat = ExpenseCategory::create(['name' => 'TEST boards', 'is_overhead' => false]);
        $this->actingAs($admin)->put('/accounting/posting/links', ['payment' => [$cash->id => $this->acc('1101')], 'category' => [$cat->id => $this->acc('5205')]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/posting/auto', ['auto_posting' => 1])->assertSessionHasNoErrors();
        $supplier = Supplier::create(['name' => 'TEST timber']);
        $client = Client::create(['business_name' => 'TEST client']);
        $this->actingAs($admin)->post('/projects/past', ['client_id' => $client->id, 'title' => 'TEST kitchen', 'contract_value' => 9000,
            'start_date' => '2026-02-01', 'stage' => 'IN_PRODUCTION', 'reason' => 'TEST'])->assertSessionHasNoErrors();
        $project = Project::sole();
        $credit = ['expense_date' => '2026-03-01', 'category_id' => $cat->id, 'supplier_id' => $supplier->id, 'description' => 'TEST boards',
            'amount' => 2000, 'vat_amount' => 300, 'payment_account_id' => 'CREDIT', 'reference' => 'TT-501', 'project_id' => $project->id];

        // On credit needs the supplier and their invoice number.
        $this->actingAs($admin)->post('/expenses', ['supplier_id' => null, 'payee' => 'x'] + $credit)->assertSessionHasErrors('supplier_id');
        $this->actingAs($admin)->post('/expenses', ['reference' => ''] + $credit)->assertSessionHasErrors('reference');
        $this->actingAs($admin)->post('/expenses', $credit)->assertSessionHasNoErrors();
        $x = Expense::sole();
        $this->assertSame(['CREDIT', null], [$x->payment_method, $x->payment_account_id]);
        // The same supplier invoice is entered once.
        $this->actingAs($admin)->post('/expenses', ['reference' => ' TT-501 '] + $credit)->assertSessionHasErrors('reference');

        // Approval: cost on the project (work in progress), owed to the supplier.
        $this->actingAs($admin)->post("/expenses/{$x->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame([['1130', 2000.0, 0.0, null, $project->id], ['1140', 300.0, 0.0, null, null], ['2101', 0.0, 2300.0, 'SUPPLIER', null]], $this->lines('EXPENSE', $x->id));
        $this->assertEquals(2000, $project->actualCost()->direct_expense_cost);
        $this->actingAs($admin)->get("/expenses/{$x->id}")->assertOk()->assertSee('آجل على حساب المورد')->assertSee('2,300.00')
            ->assertSee(route('supplier-payments.create', ['expense' => $x->id]), false);

        // Partial payment from the cash box.
        $this->actingAs($admin)->get("/supplier-payments/create?expense={$x->id}")->assertOk()->assertSee('2300');
        $this->actingAs($admin)->post('/supplier-payments', ['payment_date' => '2026-03-10', 'supplier_id' => $supplier->id, 'invoice' => "E{$x->id}",
            'payment_account_id' => $cash->id, 'amount' => 1500, 'reference' => 'TRX-1'])->assertSessionHasNoErrors();
        $sp = SupplierPayment::sole();
        $this->assertSame($x->id, $sp->expense_id);
        $this->actingAs($admin)->post("/supplier-payments/{$sp->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame([['1101', 0.0, 1500.0, null, null], ['2101', 1500.0, 0.0, 'SUPPLIER', null]], $this->lines('SUPPLIER_PAYMENT', $sp->id));

        // Never more than the invoice; what is left shows everywhere and agrees with the books.
        $this->actingAs($admin)->post('/supplier-payments', ['payment_date' => '2026-03-11', 'supplier_id' => $supplier->id, 'invoice' => "E{$x->id}",
            'payment_account_id' => $cash->id, 'amount' => 900])->assertSessionHasNoErrors();
        $over = SupplierPayment::where('status', 'DRAFT')->sole();
        $this->actingAs($admin)->post("/supplier-payments/{$over->id}/approve")->assertSessionHasErrors('rule');
        $this->assertEquals(800, DB::table('v_supplier_balances')->where('supplier_id', $supplier->id)->value('balance'));
        $this->assertEquals(-800, DB::table('v_ledger_lines')->where('account_id', $this->acc('2101'))->where('partner_id', $supplier->id)->sum('net'));
        $this->actingAs($admin)->get("/expenses/{$x->id}")->assertOk()->assertSee('800.00')->assertSee($sp->payment_no);
        $this->actingAs($admin)->get('/supplier-payments/balances')->assertOk()->assertSee('TEST timber')->assertSee('800.00');
        $this->actingAs($admin)->get("/suppliers/{$supplier->id}")->assertOk()->assertSee('800.00');
        $this->actingAs($admin)->get('/accounting/reconciliation')->assertOk();

        // Drafts are deleted; approved payments are not.
        $this->actingAs($admin)->delete(route('records.destroy', ['supplier-payments', $over->id]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->get("/supplier-payments/{$sp->id}")->assertOk()->assertDontSee(route('records.destroy', ['supplier-payments', $sp->id]), false);
    }

    public function test_permissions(): void
    {
        $supplier = Supplier::create(['name' => 'TEST timber']);
        $viewer = $this->userWith(['purchases.view']);
        $this->actingAs($viewer)->get('/supplier-payments')->assertOk();
        $this->actingAs($viewer)->get('/supplier-payments/create')->assertForbidden();
        $clerk = $this->userWith(['purchases.view', 'purchases.manage', 'treasury.manage']);
        $this->actingAs($clerk)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash'])->assertSessionHasNoErrors();
        $this->actingAs($clerk)->post('/supplier-payments', ['payment_date' => '2026-03-10', 'supplier_id' => $supplier->id,
            'payment_account_id' => PaymentAccount::value('id'), 'amount' => 10])->assertSessionHasNoErrors();
        $this->actingAs($clerk)->post('/supplier-payments/'.SupplierPayment::value('id').'/approve')->assertForbidden();
        $this->actingAs($this->userWith(['studio.view']))->get('/supplier-payments')->assertForbidden();
    }
}
