<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\OwnerTransaction;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\DB;

/** Owner funding / withdrawals and expenses paid by the owner; the owner account takes no manual entry; partner rules on manual lines. */
class OwnerTest extends ApiTestCase
{
    private function acc(string $code): int
    {
        return Account::where('code', $code)->value('id');
    }

    public function test_owner_documents_statement_and_manual_entry_rules(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash box'])->assertSessionHasNoErrors();
        $cash = PaymentAccount::sole();
        $cat = ExpenseCategory::create(['name' => 'TEST machines', 'is_overhead' => false]);
        $this->actingAs($admin)->put('/accounting/posting/links', ['payment' => [$cash->id => $this->acc('1101')], 'category' => [$cat->id => $this->acc('5205')]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/posting/auto', ['auto_posting' => 1])->assertSessionHasNoErrors();
        $owner = $this->acc('3201');

        // Funding and withdrawal.
        $this->actingAs($admin)->post('/accounting/owner', ['kind' => 'FUNDING', 'txn_date' => '2026-01-05', 'payment_account_id' => $cash->id,
            'amount' => 50000, 'description' => 'TEST working capital', 'reference' => 'TRX 7781'])->assertSessionHasNoErrors();
        $fund = OwnerTransaction::sole();
        $this->actingAs($admin)->post("/accounting/owner/{$fund->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/owner', ['kind' => 'WITHDRAWAL', 'txn_date' => '2026-04-20', 'payment_account_id' => $cash->id,
            'amount' => 5000, 'description' => 'TEST withdrawal'])->assertSessionHasNoErrors();
        $draw = OwnerTransaction::where('kind', 'WITHDRAWAL')->sole();
        $this->actingAs($admin)->post("/accounting/owner/{$draw->id}/approve")->assertSessionHasNoErrors();
        $this->assertNotNull(JournalEntry::forDocument('OWNER', $fund->id));

        // An expense paid by the owner from their own money.
        $this->actingAs($admin)->post('/expenses', ['expense_date' => '2026-01-10', 'category_id' => $cat->id, 'payee' => 'TEST machine shop',
            'description' => 'TEST saw', 'amount' => 8000, 'vat_amount' => 1200, 'payment_account_id' => 'OWNER'])->assertSessionHasNoErrors();
        $x = Expense::sole();
        $this->assertSame(['OWNER', null], [$x->payment_method, $x->payment_account_id]);
        $this->actingAs($admin)->post("/expenses/{$x->id}/approve")->assertSessionHasNoErrors();
        $this->assertEquals(9200, DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->where('j.source_type', 'EXPENSE')->where('j.source_id', $x->id)->where('l.account_id', $owner)->value('l.credit'));

        // Statement = 50,000 + 9,200 − 5,000 = 54,200 = the books.
        $this->assertEquals(-54200, DB::table('v_ledger_lines')->where('account_id', $owner)->sum('net'));
        $this->actingAs($admin)->get('/accounting/owner')->assertOk()->assertSee('54,200.00')->assertSee($fund->txn_no)->assertSee($x->expense_no);
        $this->actingAs($admin)->get('/accounting/reconciliation')->assertOk();
        $this->actingAs($admin)->get("/expenses/{$x->id}")->assertOk()->assertSee('دفعها المالك من ماله');

        // The owner account takes no manual entry; the opening entry may use it.
        $lines = [['account_id' => $this->acc('1101'), 'debit' => 10], ['account_id' => $owner, 'credit' => 10]];
        $this->actingAs($admin)->post('/accounting/journal', ['entry_date' => '2026-03-01', 'description' => 'TEST', 'source_type' => 'MANUAL', 'lines' => $lines])
            ->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post('/accounting/journal', ['entry_date' => '2026-01-01', 'description' => 'TEST opening', 'source_type' => 'OPENING', 'lines' => $lines])
            ->assertSessionHasNoErrors();

        // Receivables / payables lines name their partner.
        $client = Client::create(['business_name' => 'TEST client']);
        $ar = [['account_id' => $this->acc('1110'), 'debit' => 10], ['account_id' => $this->acc('4101'), 'credit' => 10]];
        $this->actingAs($admin)->post('/accounting/journal', ['entry_date' => '2026-03-01', 'description' => 'TEST', 'source_type' => 'MANUAL', 'lines' => $ar])
            ->assertSessionHasErrors('lines.0.partner');
        $ar[0]['partner'] = 'SUPPLIER:1';
        $this->actingAs($admin)->post('/accounting/journal', ['entry_date' => '2026-03-01', 'description' => 'TEST', 'source_type' => 'MANUAL', 'lines' => $ar])
            ->assertSessionHasErrors('lines.0.partner');
        $ar[0]['partner'] = 'CLIENT:'.$client->id;
        $this->actingAs($admin)->post('/accounting/journal', ['entry_date' => '2026-03-01', 'description' => 'TEST receivable fix', 'source_type' => 'MANUAL', 'lines' => $ar])
            ->assertSessionHasNoErrors();
        $e = JournalEntry::where('description', 'TEST receivable fix')->sole();
        $this->assertSame(['CLIENT', $client->id], [$e->lines[0]->partner_type, $e->lines[0]->partner_id]);
        $this->actingAs($admin)->get("/accounting/journal/{$e->id}")->assertOk()->assertSee('TEST client');
        $this->actingAs($admin)->get("/accounting/journal/{$e->id}/edit")->assertOk()->assertSee('value="CLIENT:'.$client->id.'" selected', false);
    }

    public function test_permissions(): void
    {
        $viewer = $this->userWith(['accounting.view']);
        $this->actingAs($viewer)->get('/accounting/owner')->assertOk();
        $this->actingAs($viewer)->get('/accounting/owner/create')->assertForbidden();
        $clerk = $this->userWith(['accounting.view', 'accounting.manage', 'treasury.manage']);
        $this->actingAs($clerk)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash'])->assertSessionHasNoErrors();
        $this->actingAs($clerk)->post('/accounting/owner', ['kind' => 'FUNDING', 'txn_date' => '2026-03-01', 'payment_account_id' => PaymentAccount::value('id'),
            'amount' => 10, 'description' => 'TEST'])->assertSessionHasNoErrors();
        $this->actingAs($clerk)->post('/accounting/owner/'.OwnerTransaction::value('id').'/approve')->assertForbidden();
        $this->actingAs($this->userWith(['studio.view']))->get('/accounting/owner')->assertForbidden();
    }
}
