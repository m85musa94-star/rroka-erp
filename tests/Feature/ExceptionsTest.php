<?php

namespace Tests\Feature;

use App\Accounting\Exceptions;
use App\Models\Account;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentAccount;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/** The exceptions board lists every detective control with its records; red exceptions block closing their month. */
class ExceptionsTest extends ApiTestCase
{
    private function acc(string $code): int
    {
        return Account::where('code', $code)->value('id');
    }

    public function test_board_items_details_and_closing_block(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/accounting/exceptions')->assertOk()->assertSee('لا استثناءات');

        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash box'])->assertSessionHasNoErrors();
        $cash = PaymentAccount::sole();
        $cat = ExpenseCategory::create(['name' => 'TEST supplies', 'is_overhead' => false]);
        $this->actingAs($admin)->put('/accounting/posting/links', ['payment' => [$cash->id => $this->acc('1101')], 'category' => [$cat->id => $this->acc('5205')]])->assertSessionHasNoErrors();
        $unregistered = Supplier::create(['name' => 'TEST unregistered']);
        $client = Client::create(['business_name' => 'TEST client']);

        // Auto-posting off: an approved expense waits in the backlog (red), self-approved, no image, VAT to verify, odd rate.
        $this->actingAs($admin)->post('/expenses', ['expense_date' => '2026-02-10', 'category_id' => $cat->id, 'supplier_id' => $unregistered->id, 'description' => 'TEST',
            'amount' => 1000, 'vat_amount' => 100, 'payment_account_id' => $cash->id])->assertSessionHasNoErrors();
        $x = Expense::sole();
        $this->actingAs($admin)->post("/expenses/{$x->id}/approve")->assertSessionHasNoErrors();
        // An old customer invoice, not collected.
        $this->actingAs($admin)->post('/sales/invoices', ['kind' => 'INVOICE', 'daftra_invoice_no' => 'INV-OLD', 'invoice_date' => '2026-02-01', 'client_id' => $client->id,
            'description' => 'TEST', 'amount' => 1000, 'vat_amount' => 150])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/sales/invoices/'.SalesInvoice::value('id').'/approve')->assertSessionHasNoErrors();

        $keys = collect(Exceptions::summary())->keyBy('key');
        foreach (['backlog', 'vat_rate', 'vat_verify', 'no_image', 'self_approved', 'client_overdue'] as $k) {
            $this->assertTrue($keys->has($k), "exception $k");
        }
        $this->assertSame(2, $keys['backlog']['count']);
        $this->assertSame('RED', $keys['backlog']['severity']);
        $this->actingAs($admin)->get('/accounting/exceptions')->assertOk()->assertSee('مستندات معتمدة لم تُرحَّل للدفاتر')->assertSee('ضريبة مورد بلا رقم ضريبي');
        foreach (array_keys(Exceptions::definitions()) as $k) {
            $this->actingAs($admin)->get("/accounting/exceptions/$k")->assertOk();
        }
        $this->actingAs($admin)->get('/accounting/exceptions/backlog')->assertOk()->assertSee($x->expense_no)->assertSee(route('expenses.show', $x), false);
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('استثناءات تحتاج مراجعة');

        // February does not close while its documents are unposted; once posted it does (January first).
        DB::table('fiscal_periods')->insertOrIgnore([['period_start' => '2026-01-01'], ['period_start' => '2026-02-01']]);
        $this->actingAs($admin)->post('/accounting/periods/2026-01/close')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/periods/2026-02/close')->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post('/accounting/posting/post-all')->assertSessionHasNoErrors();
        $this->assertFalse(collect(Exceptions::summary())->pluck('key')->contains('backlog'));
        $this->actingAs($admin)->post('/accounting/periods/2026-02/close')->assertSessionHasNoErrors();
    }

    public function test_only_accounting_users(): void
    {
        $this->actingAs($this->userWith(['accounting.view']))->get('/accounting/exceptions')->assertOk();
        $this->actingAs($this->userWith(['expenses.view']))->get('/accounting/exceptions')->assertForbidden();
        $this->actingAs($this->userWith(['accounting.view']))->get('/accounting/exceptions/nope')->assertNotFound();
    }
}
