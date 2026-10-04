<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentAccount;
use App\Models\TreasuryTransfer;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;

class TreasuryTest extends ApiTestCase
{
    /** @return array{0: PaymentAccount, 1: PaymentAccount, 2: PaymentAccount, 3: Worker} cash, bank, custody, custodian */
    private function accounts($admin): array
    {
        $worker = Worker::create(['name' => 'TEST site supervisor']);
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash box'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'BANK', 'name' => 'TEST bank', 'bank_name' => 'TEST', 'iban' => 'sa03 8000 0000 6080 1016 7519'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CUSTODY', 'name' => 'TEST custody', 'employee_id' => $worker->id, 'custody_limit' => 1000])->assertSessionHasNoErrors();

        return [PaymentAccount::where('kind', 'CASH')->sole(), PaymentAccount::where('kind', 'BANK')->sole(), PaymentAccount::where('kind', 'CUSTODY')->sole(), $worker];
    }

    private function expense($admin, PaymentAccount $from, array $extra = []): Expense
    {
        $cat = ExpenseCategory::firstOrCreate(['name' => 'TEST supplies'], ['is_overhead' => true]);
        $this->actingAs($admin)->post('/expenses', $extra + ['expense_date' => now()->toDateString(), 'category_id' => $cat->id, 'payee' => 'TEST shop',
            'description' => 'TEST screws', 'amount' => 300, 'vat_amount' => 45, 'payment_account_id' => $from->id])->assertSessionHasNoErrors();

        return Expense::latest('id')->first();
    }

    private function transfer($admin, PaymentAccount $from, PaymentAccount $to, float $amount): TreasuryTransfer
    {
        $this->actingAs($admin)->post('/treasury/transfers', ['transfer_date' => now()->toDateString(), 'from_account_id' => $from->id,
            'to_account_id' => $to->id, 'amount' => $amount])->assertSessionHasNoErrors();

        return TreasuryTransfer::latest('id')->first();
    }

    public function test_custody_cycle_issue_spend_return_close(): void
    {
        $admin = $this->admin();
        [$cash, $bank, $custody, $worker] = $this->accounts($admin);
        $this->assertSame('SA0380000000608010167519', $bank->iban);
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CUSTODY', 'name' => 'TEST second', 'employee_id' => $worker->id])->assertSessionHasErrors('employee_id');
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST x', 'iban' => 'SA0380000000608010167519'])->assertSessionHasErrors('iban');

        // Issue custody from the cash box; above the limit is refused by the database.
        $big = $this->transfer($admin, $cash, $custody, 1500);
        $this->actingAs($admin)->post("/treasury/transfers/{$big->id}/approve")->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post("/treasury/transfers/{$big->id}/cancel")->assertSessionHasNoErrors();
        $issue = $this->transfer($admin, $cash, $custody, 1000);
        $this->assertSame('ISSUE', $issue->purpose());
        $this->actingAs($admin)->post("/treasury/transfers/{$issue->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($admin)->put("/treasury/transfers/{$issue->id}", ['transfer_date' => now()->toDateString(), 'from_account_id' => $cash->id, 'to_account_id' => $custody->id, 'amount' => 1])->assertSessionHasErrors('rule');

        // Spending from custody: the method and the employee come from the account.
        $x = $this->expense($admin, $custody, ['payment_method' => 'CARD']);
        $this->assertSame('PETTY_CASH', $x->payment_method);
        $this->assertSame($worker->id, $x->paid_by_employee_id);
        $this->actingAs($admin)->post("/expenses/{$x->id}/approve")->assertSessionHasNoErrors();
        $this->assertEquals(655, (float) DB::scalar('select fn_custody_balance(?)', [$custody->id]));

        $this->actingAs($admin)->get('/treasury/accounts')->assertOk()->assertSee('655.00')->assertSee('TEST cash box');
        $this->actingAs($admin)->get("/treasury/accounts/{$custody->id}")->assertOk()->assertSee($x->expense_no)->assertSee($issue->transfer_no)->assertSee('655.00');
        $this->actingAs($admin)->get("/expenses/{$x->id}")->assertSee('TEST custody');
        $csv = $this->actingAs($admin)->get("/treasury/accounts/{$custody->id}?export=csv")->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString($x->expense_no, $csv);
        $this->assertStringContainsString('655.00', $csv);

        // Return: not more than held; closing only once settled.
        $tooMuch = $this->transfer($admin, $custody, $cash, 700);
        $this->actingAs($admin)->post("/treasury/transfers/{$tooMuch->id}/approve")->assertSessionHasErrors('rule');
        $this->actingAs($admin)->put("/treasury/accounts/{$custody->id}", ['name' => 'TEST custody', 'is_active' => 0])->assertSessionHasErrors('rule');
        $this->actingAs($admin)->put("/treasury/transfers/{$tooMuch->id}", ['transfer_date' => now()->toDateString(), 'from_account_id' => $custody->id, 'to_account_id' => $cash->id, 'amount' => 655])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/treasury/transfers/{$tooMuch->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($admin)->put("/treasury/accounts/{$custody->id}", ['name' => 'TEST custody', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($custody->fresh()->is_active);
        $this->actingAs($admin)->get('/treasury/transfers?f[]=return')->assertOk()->assertSee($tooMuch->transfer_no)->assertDontSee($issue->transfer_no);
    }

    public function test_expense_needs_an_account_and_a_matching_method(): void
    {
        $admin = $this->admin();
        [$cash, $bank] = $this->accounts($admin);
        $card = $this->expense($admin, $bank, ['payment_method' => 'CARD']);
        $this->assertSame('CARD', $card->payment_method);
        $this->assertNull($card->paid_by_employee_id);
        $cashX = $this->expense($admin, $cash, ['payment_method' => 'CARD']);
        $this->assertSame('CASH', $cashX->payment_method, 'a cash box always pays cash');

        // A draft from before the treasury (no account) cannot be approved until it says where it was paid from.
        DB::table('expenses')->where('id', $cashX->id)->update(['payment_account_id' => null]);
        $this->actingAs($admin)->post("/expenses/{$cashX->id}/approve")->assertSessionHasErrors('rule');
        $this->actingAs($admin)->get("/expenses/{$cashX->id}")->assertSee('غير محدد');

        $this->actingAs($admin)->post("/expenses/{$card->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($admin)->get('/expenses?g=account')->assertOk()->assertSee('TEST bank');
        $this->actingAs($admin)->get("/expenses?payment_account_id={$bank->id}")->assertSee($card->expense_no)->assertDontSee($cashX->expense_no);
        $this->actingAs($admin)->get('/reports/expenses?rows=account')->assertOk()->assertSee('TEST bank');
        $this->actingAs($admin)->get("/treasury/accounts/{$bank->id}")->assertOk()->assertSee('345.00')->assertSee('دفترة');
    }

    public function test_permissions(): void
    {
        $admin = $this->admin();
        [$cash, , $custody] = $this->accounts($admin);
        $t = $this->transfer($admin, $cash, $custody, 100);

        $viewer = $this->userWith(['treasury.view']);
        $this->actingAs($viewer)->get('/treasury/accounts')->assertOk();
        $this->actingAs($viewer)->get("/treasury/transfers/{$t->id}")->assertOk();
        $this->actingAs($viewer)->get('/treasury/accounts/create')->assertForbidden();
        $this->actingAs($viewer)->post("/treasury/transfers/{$t->id}/approve")->assertForbidden();

        $clerk = $this->userWith(['treasury.manage']);
        $this->actingAs($clerk)->post("/treasury/transfers/{$t->id}/approve")->assertForbidden();
        $this->actingAs($clerk)->get('/')->assertSee(route('treasury.accounts.index'), false);

        $other = $this->userWith(['expenses.view']);
        $this->actingAs($other)->get('/treasury/accounts')->assertForbidden();
        $this->actingAs($other)->get('/')->assertDontSee(route('treasury.accounts.index'), false);
    }
}
