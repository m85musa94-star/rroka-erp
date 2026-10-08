<?php

namespace Tests\Feature;

use App\Accounting\FinancialReports;
use App\Accounting\ReportOptions;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingTest extends ApiTestCase
{
    private function entry($user, string $date, array $lines, string $description = 'TEST entry', string $type = 'MANUAL'): JournalEntry
    {
        $this->actingAs($user)->post('/accounting/journal', ['entry_date' => $date, 'description' => $description, 'source_type' => $type, 'lines' => $lines])
            ->assertSessionHasNoErrors();

        return JournalEntry::latest('id')->first();
    }

    private function acc(string $code): int
    {
        return Account::where('code', $code)->value('id');
    }

    public function test_chart_template_entry_posting_reversal_and_trial_balance(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/accounting/accounts')->assertOk()->assertSee('إنشاء الدليل المقترح');
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $this->assertSame('INPUT_VAT', Account::where('code', '1140')->value('system_role'));
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasErrors('rule');   // only into an empty chart

        // A real bank account is added under «Banks»; a postable parent is refused.
        $banks = $this->acc('1102');
        $this->actingAs($admin)->post('/accounting/accounts', ['code' => '110201', 'name' => 'TEST bank', 'detail_type' => 'BANK_CASH', 'parent_id' => $banks])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/accounts', ['code' => '110301', 'name' => 'TEST', 'detail_type' => 'CURRENT_ASSETS', 'parent_id' => $this->acc('1103')])->assertSessionHasErrors('parent_id');
        $this->actingAs($admin)->post('/accounting/accounts', ['code' => '1A', 'name' => 'TEST', 'detail_type' => 'BANK_CASH'])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/accounting/accounts', ['code' => '1199', 'name' => 'TEST'])->assertSessionHasErrors('detail_type');   // the type is required
        // Odoo-style: the type decides the class; receivables always allow reconciliation.
        $this->assertSame(['ASSET', true], [Account::where('code', '1110')->value('account_type'), Account::where('code', '1110')->value('reconcile')]);
        $this->actingAs($admin)->post('/accounting/accounts', ['code' => '1170', 'name' => 'TEST staff receivable', 'detail_type' => 'RECEIVABLE', 'parent_id' => $this->acc('11')])->assertSessionHasNoErrors();
        $this->assertTrue(Account::where('code', '1170')->value('reconcile'));
        $this->actingAs($admin)->post('/accounting/accounts', ['code' => '1180', 'name' => 'TEST', 'detail_type' => 'PAYABLE', 'parent_id' => $this->acc('11')])->assertSessionHasErrors('rule');   // a liability under an asset group
        $this->actingAs($admin)->get('/accounting/accounts?g=type')->assertOk()->assertSee('البنك والنقدية')->assertSee('مدينون (عملاء)');
        $this->actingAs($admin)->get('/accounting/accounts?f[]=liabilities')->assertOk()->assertSee('2101')->assertDontSee('TEST staff receivable');
        $this->actingAs($admin)->get('/accounting/account-groups')->assertOk()->assertSee('البنوك');
        $this->actingAs($admin)->post('/accounting/account-groups', ['code' => '1105', 'name' => 'TEST petty cash group', 'account_type' => 'ASSET', 'parent_id' => $this->acc('11')])->assertSessionHasNoErrors();
        $this->assertFalse(Account::where('code', '1105')->value('is_postable'));

        // Owner funds the bank, then rent is paid (1,000 + 150 VAT).
        $bank = $this->acc('110201');
        $deposit = $this->entry($admin, '2026-01-05', [['account_id' => $bank, 'debit' => 50000], ['account_id' => $this->acc('3201'), 'credit' => 50000]], 'TEST owner deposit');
        $rent = $this->entry($admin, '2026-01-10', [
            ['account_id' => $this->acc('5202'), 'debit' => 1000], ['account_id' => $this->acc('1140'), 'debit' => 150], ['account_id' => $bank, 'credit' => 1100],
        ], 'TEST rent');
        $this->actingAs($admin)->post("/accounting/journal/{$rent->id}/post")->assertSessionHasErrors('rule');   // 1,150 ≠ 1,100
        $this->assertSame('DRAFT', $rent->fresh()->status);
        $this->actingAs($admin)->put("/accounting/journal/{$rent->id}", ['entry_date' => '2026-01-10', 'description' => 'TEST rent', 'source_type' => 'MANUAL', 'lines' => [
            ['account_id' => $this->acc('5202'), 'debit' => 1000], ['account_id' => $this->acc('1140'), 'debit' => 150], ['account_id' => $bank, 'credit' => 1150],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/accounting/journal/{$deposit->id}/post")->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/accounting/journal/{$rent->id}/post")->assertSessionHasNoErrors();
        $this->assertSame(['JV-2026-00001', 'JV-2026-00002'], [$deposit->fresh()->entry_no, $rent->fresh()->entry_no]);

        // Posted is final: no edit, no delete; the database refuses even if the app did not.
        $this->actingAs($admin)->put("/accounting/journal/{$rent->id}", ['entry_date' => '2026-01-10', 'description' => 'x', 'source_type' => 'MANUAL', 'lines' => [
            ['account_id' => $bank, 'debit' => 1], ['account_id' => $bank, 'credit' => 1]]])->assertSessionHasErrors('rule');
        $this->actingAs($admin)->delete("/accounting/journal/{$rent->id}")->assertSessionHasErrors('rule');
        $this->assertSame('TEST rent', $rent->fresh()->description);

        // Ledger with running balance; trial balance balances.
        $this->actingAs($admin)->get("/accounting/accounts/{$bank}?from=2026-01-01&to=2026-01-31")->assertOk()->assertSee('48,850.00')->assertSee('JV-2026-00002');
        $this->actingAs($admin)->get("/accounting/accounts/{$banks}")->assertOk()->assertSee('48,850.00');   // the group shows what is under it
        $this->actingAs($admin)->get('/accounting/reports/trial-balance?from=2026-01-01&to=2026-01-31')->assertOk()
            ->assertSee('الميزان متوازن')->assertSee('51,150.00');
        $this->actingAs($admin)->get('/accounting/accounts')->assertOk()->assertSee('48,850.00');

        // Correction = reversal mirror, then the right entry.
        $this->actingAs($admin)->post("/accounting/journal/{$rent->id}/reverse", ['entry_date' => '2026-01-09', 'reason' => 'TEST'])->assertSessionHasErrors('entry_date');
        $this->actingAs($admin)->post("/accounting/journal/{$rent->id}/reverse", ['entry_date' => '2026-01-15', 'reason' => 'TEST wrong month'])->assertSessionHasNoErrors();
        $rev = JournalEntry::where('reverses_id', $rent->id)->sole();
        $this->assertSame('REVERSAL', $rev->source_type);
        $this->assertEquals(1150, (float) $rev->lines()->where('account_id', $bank)->value('debit'));
        $this->actingAs($admin)->post("/accounting/journal/{$rev->id}/post")->assertSessionHasNoErrors();
        $this->assertEquals(0, (float) DB::table('v_ledger_lines')->where('account_id', $this->acc('5202'))->sum('net'));
        $this->actingAs($admin)->get("/accounting/journal/{$rent->id}")->assertOk()->assertSee($rev->fresh()->entry_no);

        // Opening entry must be dated at the books start.
        $this->actingAs($admin)->post('/accounting/journal', ['entry_date' => '2026-02-01', 'description' => 'TEST', 'source_type' => 'OPENING',
            'lines' => [['account_id' => $bank, 'debit' => 1], ['account_id' => $this->acc('3101'), 'credit' => 1]]])->assertSessionHasErrors('entry_date');
        // A line is debit or credit.
        $this->actingAs($admin)->post('/accounting/journal', ['entry_date' => '2026-02-01', 'description' => 'TEST', 'source_type' => 'MANUAL',
            'lines' => [['account_id' => $bank, 'debit' => 5, 'credit' => 5], ['account_id' => $this->acc('3101'), 'credit' => 5]]])->assertSessionHasErrors('lines.0.debit');
        // Group accounts take no lines.
        $this->actingAs($admin)->post('/accounting/journal', ['entry_date' => '2026-02-01', 'description' => 'TEST', 'source_type' => 'MANUAL',
            'lines' => [['account_id' => $banks, 'debit' => 5], ['account_id' => $this->acc('3101'), 'credit' => 5]]])->assertSessionHasErrors('lines.0.account_id');

        // The account with entries keeps its type.
        $this->actingAs($admin)->put("/accounting/accounts/{$bank}", ['code' => '110201', 'name' => 'TEST bank', 'detail_type' => 'EXPENSES', 'parent_id' => null])
            ->assertSessionHasErrors('rule');
        $this->actingAs($admin)->get("/accounting/accounts/{$bank}/edit")->assertOk()->assertSee('50,000.00');   // smart button: balance (rent reversed)
        $this->actingAs($admin)->put("/accounting/accounts/{$bank}", ['code' => '110201', 'name' => 'TEST bank (main)', 'detail_type' => 'BANK_CASH', 'parent_id' => $banks, 'archived' => 1])
            ->assertSessionHasNoErrors();
        $this->assertFalse(Account::find($bank)->is_active);
        $this->actingAs($admin)->get('/accounting/accounts')->assertDontSee('TEST bank (main)');
        $this->actingAs($admin)->get('/accounting/accounts?f[]=archived')->assertSee('TEST bank (main)');
    }

    public function test_periods_close_in_order_and_block_posting(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template');
        $cash = Account::where('code', '1101')->value('id');
        $owner = Account::where('code', '3201')->value('id');
        $jan = $this->entry($admin, '2026-01-20', [['account_id' => $cash, 'debit' => 100], ['account_id' => $owner, 'credit' => 100]]);
        $this->actingAs($admin)->post('/accounting/periods/2026-01/close')->assertSessionHasErrors('rule');   // a draft in January
        $this->actingAs($admin)->post("/accounting/journal/{$jan->id}/post")->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/periods/2026-02/close')->assertSessionHasErrors('rule');   // January still open
        $this->actingAs($admin)->post('/accounting/periods/2026-01/close')->assertSessionHasNoErrors();
        $this->assertSame('CLOSED', FiscalPeriod::where('period_start', '2026-01-01')->value('status'));

        $late = $this->entry($admin, '2026-01-25', [['account_id' => $cash, 'debit' => 10], ['account_id' => $owner, 'credit' => 10]]);
        $this->actingAs($admin)->post("/accounting/journal/{$late->id}/post")->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post('/accounting/periods/2025-12/close')->assertSessionHasErrors('rule');   // before the books start
        $this->actingAs($admin)->post('/accounting/periods/2026-01/reopen', ['reopen_reason' => ''])->assertSessionHasErrors('reopen_reason');
        $this->actingAs($admin)->post('/accounting/periods/2026-01/reopen', ['reopen_reason' => 'TEST late invoice'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/accounting/journal/{$late->id}/post")->assertSessionHasNoErrors();
        $this->actingAs($admin)->get('/accounting/periods')->assertOk()->assertSee('2026-01')->assertSee('TEST late invoice');
    }

    public function test_financial_reports_odoo_style(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template');
        $this->actingAs($admin)->post('/accounting/accounts', ['code' => '110201', 'name' => 'TEST bank', 'detail_type' => 'BANK_CASH', 'parent_id' => $this->acc('1102')]);
        $bank = $this->acc('110201');
        $post = function (string $date, array $lines) use ($admin) {
            $e = $this->entry($admin, $date, $lines);
            $this->actingAs($admin)->post("/accounting/journal/{$e->id}/post")->assertSessionHasNoErrors();
        };
        // Owner funds 50,000; rent 1,000 + 150 VAT; a sale of 5,000 on credit; its cost 2,000 paid by bank.
        $post('2026-01-05', [['account_id' => $bank, 'debit' => 50000], ['account_id' => $this->acc('3201'), 'credit' => 50000]]);
        $post('2026-01-10', [['account_id' => $this->acc('5202'), 'debit' => 1000], ['account_id' => $this->acc('1140'), 'debit' => 150], ['account_id' => $bank, 'credit' => 1150]]);
        $post('2026-02-01', [['account_id' => $this->acc('1110'), 'debit' => 5000], ['account_id' => $this->acc('4101'), 'credit' => 5000]]);
        $post('2026-02-02', [['account_id' => $this->acc('5101'), 'debit' => 2000], ['account_id' => $bank, 'credit' => 2000]]);

        // P&L: income 5,000 − cost of revenue 2,000 = gross 3,000; − rent 1,000 = net 2,000.
        $r = FinancialReports::build('profit-loss', new ReportOptions(Request::create('/', 'GET', ['date' => 'custom', 'from' => '2026-01-01', 'to' => '2026-12-31'])));
        $line = fn ($id) => collect($r['lines'])->firstWhere('id', $id)['values']['c0'];
        $this->assertEquals([5000, 2000, 3000, 1000, 2000], [$line('income'), $line('cor'), $line('gross'), $line('expenses'), $line('net')]);
        $this->actingAs($admin)->get('/accounting/reports/profit-loss?date=custom&from=2026-01-01&to=2026-12-31')->assertOk()
            ->assertSee('مجمل الربح')->assertSee('3,000.00')->assertSee('صافي الربح');

        // Comparison: February against January (previous period of the same length).
        $r = FinancialReports::build('profit-loss', new ReportOptions(Request::create('/', 'GET', ['date' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-28', 'cmp' => 'previous'])));
        $net = collect($r['lines'])->firstWhere('id', 'net')['values'];
        $this->assertEquals([3000, -1000], [$net['c0'], $net['c1']]);
        $this->assertSame('2026-01-01', $r['columns'][1]['from']);

        // Balance sheet balances: assets 46,850 bank + 5,000 receivable + 150 VAT = 52,000 = owner 50,000 + earnings 2,000.
        $r = FinancialReports::build('balance-sheet', new ReportOptions(Request::create('/', 'GET', ['date' => 'custom', 'from' => '2026-01-01', 'to' => '2026-03-31'])));
        $line = fn ($id) => collect($r['lines'])->firstWhere('id', $id)['values']['c0'];
        $this->assertEquals([52000, 0, 52000, 2000], [$line('total_assets'), $line('total_liab'), $line('total_le'), $line('cye')]);
        $this->assertTrue($r['checks'][0]['ok']);
        $this->actingAs($admin)->get('/accounting/reports/balance-sheet?date=custom&to=2026-03-31')->assertOk()->assertSee('متوازنة')->assertSee('52,000.00');

        // General ledger: folded by default, unfolded on request with the opening balance and the entries.
        $this->actingAs($admin)->get('/accounting/reports/general-ledger?date=custom&from=2026-02-01&to=2026-02-28')->assertOk()->assertDontSee('JV-2026-00004');
        $this->actingAs($admin)->get("/accounting/reports/general-ledger?date=custom&from=2026-02-01&to=2026-02-28&acc[]={$bank}")->assertOk()
            ->assertSee('رصيد أول المدة')->assertSee('48,850.00')->assertSee('JV-2026-00004')->assertSee('46,850.00');

        // Exports: a real spreadsheet, and the print view on the letterhead.
        $x = $this->actingAs($admin)->get('/accounting/reports/balance-sheet?export=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $x->getContent());
        $this->actingAs($admin)->get('/accounting/reports/profit-loss?print=1')->assertOk()->assertSee('img/letterhead-a4.png')->assertSee('قائمة الدخل');
        $this->actingAs($admin)->get('/accounting/trial-balance?from=2026-01-01&to=2026-01-31')->assertRedirect();
    }

    public function test_permissions(): void
    {
        $viewer = $this->userWith(['accounting.view']);
        $this->actingAs($viewer)->get('/accounting/journal')->assertOk();
        $this->actingAs($viewer)->get('/accounting/reports/trial-balance')->assertOk();
        $this->actingAs($viewer)->get('/accounting/journal/create')->assertForbidden();
        $this->actingAs($viewer)->post('/accounting/accounts/template')->assertForbidden();

        $clerk = $this->userWith(['accounting.manage']);
        $this->actingAs($clerk)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $e = $this->entry($clerk, '2026-03-01', [['account_id' => Account::where('code', '1101')->value('id'), 'debit' => 5], ['account_id' => Account::where('code', '3201')->value('id'), 'credit' => 5]]);
        $this->actingAs($clerk)->post("/accounting/journal/{$e->id}/post")->assertForbidden();   // posting is a separate permission
        $this->actingAs($clerk)->post('/accounting/periods/2026-03/close')->assertForbidden();

        $this->actingAs($this->userWith(['expenses.view']))->get('/accounting/journal')->assertForbidden();
    }

    public function test_drill_down_from_a_report_keeps_the_way_back_with_its_dates(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $e = $this->entry($admin, '2026-02-05', [['account_id' => $this->acc('5202'), 'debit' => 500], ['account_id' => $this->acc('3201'), 'credit' => 500]], 'TEST rent');
        $this->actingAs($admin)->post("/accounting/journal/{$e->id}/post")->assertSessionHasNoErrors();

        $report = '/accounting/reports/profit-loss?date=custom&from=2026-02-01&to=2026-02-28';
        $page = $this->actingAs($admin)->get($report)->assertOk()->getContent();
        preg_match('#href="([^"]*accounting/accounts/'.$this->acc('5202').'[^"]*)"#', $page, $m);
        $ledger = html_entity_decode($m[1]);
        $this->assertStringContainsString('back=', $ledger);
        $res = $this->actingAs($admin)->get($ledger)->assertOk()->assertSee('رجوع إلى التقرير')->assertSee(e($report), false);
        // …and one level deeper, the entry still leads back to the same report.
        preg_match('#href="([^"]*accounting/journal/'.$e->id.'\?[^"]*)"#', $res->getContent(), $m2);
        $this->actingAs($admin)->get(html_entity_decode($m2[1]))->assertOk()->assertSee(e($report), false);
        // Only a local path is accepted.
        $this->actingAs($admin)->get('/accounting/journal/'.$e->id.'?back='.urlencode('https://evil.example'))->assertOk()->assertDontSee('رجوع إلى التقرير');
    }
}
