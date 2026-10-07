<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\PaymentAccount;
use App\Models\PurchaseInvoice;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;

/** Accounting plan step أ: approving a document writes its posted entry; backlog, exclusions, reconciliation. */
class PostingTest extends ApiTestCase
{
    private function acc(string $code): int
    {
        return Account::where('code', $code)->value('id');
    }

    private function expense($admin, PaymentAccount $from, ExpenseCategory $cat, array $extra = []): Expense
    {
        $this->actingAs($admin)->post('/expenses', $extra + ['expense_date' => now()->toDateString(), 'category_id' => $cat->id, 'payee' => 'TEST shop',
            'description' => 'TEST screws', 'amount' => 300, 'vat_amount' => 45, 'payment_account_id' => $from->id])->assertSessionHasNoErrors();

        return Expense::latest('id')->first();
    }

    private function lines(string $type, int $id): array
    {
        return DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.source_type', $type)->where('j.source_id', $id)->orderBy('a.code')
            ->get(['a.code', 'l.debit', 'l.credit', 'l.partner_type', 'l.partner_id'])
            ->map(fn ($l) => [$l->code, (float) $l->debit, (float) $l->credit, $l->partner_type])->all();
    }

    public function test_links_backlog_auto_posting_and_reconciliation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/accounts', ['code' => '110201', 'name' => 'TEST bank account', 'detail_type' => 'BANK_CASH', 'parent_id' => $this->acc('1102')])->assertSessionHasNoErrors();
        $worker = Worker::create(['name' => 'TEST supervisor']);
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash box'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CUSTODY', 'name' => 'TEST custody', 'employee_id' => $worker->id])->assertSessionHasNoErrors();
        $cash = PaymentAccount::where('kind', 'CASH')->sole();
        $custody = PaymentAccount::where('kind', 'CUSTODY')->sole();
        $cat = ExpenseCategory::create(['name' => 'TEST supplies', 'is_overhead' => true]);

        // Nothing is mapped: the settings page lists the gaps and auto-posting stays off.
        $this->actingAs($admin)->get('/accounting/posting')->assertOk()->assertSee('الربط غير مكتمل')->assertSee('TEST cash box')->assertSee('TEST supplies');
        $this->actingAs($admin)->post('/accounting/posting/auto', ['auto_posting' => 1])->assertSessionHasErrors('rule');
        $this->assertFalse((bool) DB::table('accounting_settings')->value('auto_posting'));

        // Approved while off: no entry, waits in the backlog.
        $x1 = $this->expense($admin, $cash, $cat);
        $this->actingAs($admin)->post("/expenses/{$x1->id}/approve")->assertSessionHasNoErrors();
        $this->assertNull(JournalEntry::forDocument('EXPENSE', $x1->id));
        $this->actingAs($admin)->get('/accounting/posting/backlog')->assertOk()->assertSee($x1->expense_no)->assertSee('345.00');
        $this->actingAs($admin)->get("/expenses/{$x1->id}")->assertOk()->assertSee('لم يُرحَّل');

        // A mapping must fit: a payment account cannot map to an expense account.
        $this->actingAs($admin)->put('/accounting/posting/links', ['payment' => [$cash->id => $this->acc('5205')]])->assertSessionHasErrors('rule');
        $this->actingAs($admin)->put('/accounting/posting/links', [
            'payment' => [$cash->id => $this->acc('1101'), $custody->id => $this->acc('1103')],
            'category' => [$cat->id => $this->acc('5205')],
            'roles' => ['INVENTORY' => $this->acc('1120'), 'WIP' => $this->acc('1130'), 'INPUT_VAT' => $this->acc('1140'), 'PAYABLE' => $this->acc('2101'), 'INVENTORY_ADJUSTMENT' => $this->acc('5207')],
        ])->assertSessionHasNoErrors();
        $this->assertSame([], DB::select('SELECT * FROM fn_posting_gaps()'));

        // Post the backlog document.
        $this->actingAs($admin)->post("/accounting/posting/EXPENSE/{$x1->id}")->assertSessionHasNoErrors();
        $this->assertSame([['1101', 0.0, 345.0, null], ['1140', 45.0, 0.0, null], ['5205', 300.0, 0.0, null]], $this->lines('EXPENSE', $x1->id));
        $je = JournalEntry::forDocument('EXPENSE', $x1->id);
        $this->actingAs($admin)->post("/accounting/posting/EXPENSE/{$x1->id}")->assertSessionHasErrors('rule');   // once only
        $this->actingAs($admin)->get("/accounting/journal/{$je->id}")->assertOk()->assertSee(route('expenses.show', $x1), false)->assertSee('قيد آلي')->assertDontSee('إعداد القيد العكسي');
        $this->actingAs($admin)->post("/accounting/journal/{$je->id}/reverse", ['entry_date' => now()->toDateString(), 'reason' => 'TEST'])->assertSessionHasErrors('rule');

        // Switch on: every approval posts at once.
        $this->actingAs($admin)->post('/accounting/posting/auto', ['auto_posting' => 1])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/transfers', ['transfer_date' => now()->toDateString(), 'from_account_id' => $cash->id, 'to_account_id' => $custody->id, 'amount' => 500])->assertSessionHasNoErrors();
        $t = DB::table('treasury_transfers')->latest('id')->first();
        $this->actingAs($admin)->post("/treasury/transfers/{$t->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame([['1101', 0.0, 500.0, null], ['1103', 500.0, 0.0, 'EMPLOYEE']], $this->lines('TRANSFER', $t->id));
        $x2 = $this->expense($admin, $custody, $cat, ['amount' => 100, 'vat_amount' => 15]);
        $this->actingAs($admin)->post("/expenses/{$x2->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame([['1103', 0.0, 115.0, 'EMPLOYEE'], ['1140', 15.0, 0.0, null], ['5205', 100.0, 0.0, null]], $this->lines('EXPENSE', $x2->id));
        $this->actingAs($admin)->get("/expenses/{$x2->id}")->assertOk()->assertSee(JournalEntry::forDocument('EXPENSE', $x2->id)->entry_no);

        // A new, unmapped category refuses the approval; the expense stays a draft.
        $loose = ExpenseCategory::create(['name' => 'TEST unmapped', 'is_overhead' => false]);
        $x3 = $this->expense($admin, $cash, $loose);
        $this->actingAs($admin)->post("/expenses/{$x3->id}/approve")->assertSessionHasErrors('rule');
        $this->assertSame('DRAFT', $x3->fresh()->status);

        // Purchase invoice: inventory + input VAT against the supplier.
        $this->actingAs($admin)->post('/suppliers', ['name' => 'TEST timber', 'vat_number' => '300000000000003'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/inventory', ['code' => 'PLY', 'name' => 'TEST plywood', 'uom' => 'sheet', 'is_active' => 1]);
        $ply = RawMaterial::where('code', 'PLY')->sole();
        $this->actingAs($admin)->post('/purchases', ['supplier_id' => Supplier::sole()->id, 'supplier_invoice_no' => 'INV-9', 'invoice_date' => now()->toDateString(),
            'discount_amount' => 0, 'vat_amount' => 150, 'lines' => [['material_id' => $ply->id, 'quantity' => 10, 'unit_price' => 100]]])->assertRedirect();
        $p = PurchaseInvoice::sole();
        $this->actingAs($admin)->post("/purchases/{$p->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame([['1120', 1000.0, 0.0, null], ['1140', 150.0, 0.0, null], ['2101', 0.0, 1150.0, 'SUPPLIER']], $this->lines('PURCHASE', $p->id));

        // A count shortage goes to the stock-adjustment account.
        $this->actingAs($admin)->post("/inventory/{$ply->id}/move", ['movement_type' => 'ADJUST_OUT', 'quantity' => 1, 'reason' => 'TEST damaged'])->assertSessionHasNoErrors();
        $m = DB::table('stock_movements')->where('movement_type', 'ADJUST_OUT')->sole();
        $this->assertSame([['1120', 0.0, 100.0, null], ['5207', 100.0, 0.0, null]], $this->lines('STOCK', $m->id));
        $this->actingAs($admin)->get("/inventory/{$ply->id}")->assertOk()->assertSee(JournalEntry::forDocument('STOCK', $m->id)->entry_no);

        // Every subledger agrees with its ledger account.
        $this->actingAs($admin)->get('/accounting/reconciliation')->assertOk()->assertSee('لا مستندات تنتظر الترحيل')
            ->assertSee('900.00')      // inventory 9 × 100 in both
            ->assertSee('385.00')      // custody 500 − 115 in both
            ->assertDontSee('b-ON_HOLD">'.__('فرق'), false);
        $this->actingAs($admin)->get('/accounting/reports/trial-balance')->assertOk()->assertSee('الميزان متوازن');

        // Switched off: an approval waits; it can be excluded with a reason and put back.
        $this->actingAs($admin)->post('/accounting/posting/auto', ['auto_posting' => 0])->assertSessionHasNoErrors();
        $x4 = $this->expense($admin, $cash, $cat, ['amount' => 50, 'vat_amount' => 0]);
        $this->actingAs($admin)->post("/expenses/{$x4->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/accounting/posting/EXPENSE/{$x4->id}/exclude", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/accounting/posting/EXPENSE/{$x4->id}/exclude", ['reason' => 'TEST in the opening entry'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get('/accounting/posting/backlog')->assertOk()->assertDontSee($x4->expense_no)->assertSee('TEST in the opening entry');
        $this->actingAs($admin)->post("/accounting/posting/EXPENSE/{$x4->id}")->assertSessionHasErrors('rule');
        $this->actingAs($admin)->delete('/accounting/posting/exclusions/'.DB::table('posting_exclusions')->value('id'))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/posting/post-all')->assertSessionHasNoErrors();
        $this->assertNotNull(JournalEntry::forDocument('EXPENSE', $x4->id));
        $this->actingAs($admin)->get('/accounting/reconciliation')->assertOk()->assertSee('لا مستندات تنتظر الترحيل');
    }

    public function test_demo_data_never_reaches_the_books_and_stays_removable(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/settings/demo')->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('v_posting_backlog')->count(), 'demo documents are not waiting to be posted');
        $this->assertSame([], array_filter(DB::select('SELECT * FROM fn_posting_gaps()'), fn ($g) => $g->gap_type !== 'ROLE'));
        $this->actingAs($admin)->post('/accounting/posting/auto', ['auto_posting' => 1])->assertSessionHasNoErrors();
        $x = DB::table('demo_records')->where('table_name', 'expenses')->value('row_id');
        $this->actingAs($admin)->post("/accounting/posting/EXPENSE/{$x}")->assertSessionHasErrors('rule');
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->actingAs($admin)->delete('/settings/demo')->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('demo_records')->count());
    }

    public function test_permissions(): void
    {
        $viewer = $this->userWith(['accounting.view']);
        $this->actingAs($viewer)->get('/accounting/posting')->assertOk()->assertDontSee('حفظ الربط')->assertDontSee('تفعيل الترحيل الآلي');
        $this->actingAs($viewer)->get('/accounting/posting/backlog')->assertOk();
        $this->actingAs($viewer)->get('/accounting/reconciliation')->assertOk();
        $this->actingAs($viewer)->put('/accounting/posting/links', [])->assertForbidden();
        $this->actingAs($viewer)->post('/accounting/posting/auto', ['auto_posting' => 1])->assertForbidden();
        $this->actingAs($viewer)->post('/accounting/posting/post-all')->assertForbidden();
        $this->actingAs($viewer)->post('/accounting/posting/EXPENSE/1/exclude', ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->userWith(['accounting.manage']))->post('/accounting/posting/auto', ['auto_posting' => 1])->assertForbidden();
    }
}
