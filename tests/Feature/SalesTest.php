<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\CustomerReceipt;
use App\Models\JournalEntry;
use App\Models\PaymentAccount;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

/** Past projects (quotation exempt from the cost sheet) and customer invoices, credit notes and receipts posted to the books. */
class SalesTest extends ApiTestCase
{
    // Past projects are created through the real path: the cost-sheet gate is on.
    protected bool $costingGate = true;

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

    private function pastProject($user, Client $client, array $extra = []): Project
    {
        $this->actingAs($user)->post('/projects/past', $extra + ['client_id' => $client->id, 'title' => 'TEST past kitchen', 'contract_value' => 10000,
            'start_date' => '2026-02-01', 'stage' => 'COMPLETED', 'completed_on' => '2026-03-10', 'reason' => 'TEST before the system'])->assertSessionHasNoErrors();

        return Project::latest('id')->first();
    }

    public function test_past_project_is_an_exempt_approved_quotation_at_its_stage(): void
    {
        $admin = $this->admin();
        $client = Client::create(['business_name' => 'TEST client']);
        $p = $this->pastProject($admin, $client);
        $q = $p->quotation;

        $this->assertTrue($q->is_legacy);
        $this->assertFalse($q->requires_costing);
        $this->assertSame('APPROVED', $q->status);
        $this->assertSame('TEST before the system', $q->costing_exempt_reason);
        $this->assertSame('COMPLETED', $p->status);
        $this->assertSame('2026-03-10', $p->completed_at->toDateString());
        $this->assertEquals(10000, $p->contract_value);
        $this->actingAs($admin)->get("/quotations/{$q->id}")->assertOk()->assertSee('مشروع سابق')->assertSee('TEST before the system')->assertDontSee('إنشاء في دفترة');
        $this->actingAs($admin)->post("/quotations/{$q->id}/sync")->assertSessionHasErrors('rule');
        $this->assertNull($q->fresh()->daftra_estimate_id);

        // At a stage still open; a reason is required; only an approver may enter one.
        $open = $this->pastProject($admin, $client, ['title' => 'TEST past wardrobe', 'stage' => 'IN_PRODUCTION', 'completed_on' => null]);
        $this->assertSame('IN_PRODUCTION', $open->status);
        $this->actingAs($admin)->post('/projects/past', ['client_id' => $client->id, 'title' => 'x', 'contract_value' => 1, 'start_date' => '2026-02-01',
            'stage' => 'ACTIVE', 'reason' => ''])->assertSessionHasErrors('reason');
        $manager = $this->userWith(['projects.view', 'projects.manage', 'quotations.view']);
        $this->actingAs($manager)->get('/projects/past/create')->assertForbidden();
        $this->actingAs($manager)->post('/projects/past', ['client_id' => $client->id, 'title' => 'x', 'contract_value' => 1, 'start_date' => '2026-02-01',
            'stage' => 'ACTIVE', 'reason' => 'x'])->assertForbidden();

        // A normal new quotation still needs its cost sheet.
        $this->actingAs($admin)->post('/quotations', ['client_id' => $client->id, 'issue_date' => now()->toDateString(), 'discount_amount' => 0,
            'lines' => [['description' => 'TEST', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 100]]])->assertSessionHasNoErrors();
        $this->assertTrue(Quotation::latest('id')->first()->requires_costing);
    }

    public function test_invoices_credit_notes_and_receipts_post_and_balance(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash box'])->assertSessionHasNoErrors();
        $cash = PaymentAccount::sole();
        $this->actingAs($admin)->put('/accounting/posting/links', ['payment' => [$cash->id => $this->acc('1101')]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounting/posting/auto', ['auto_posting' => 1])->assertSessionHasNoErrors();
        $this->assertTrue((bool) DB::table('accounting_settings')->value('auto_posting'));

        $client = Client::create(['business_name' => 'TEST client']);
        $other = Client::create(['business_name' => 'TEST other client']);
        $p = $this->pastProject($admin, $client);

        // The project must belong to the invoice's client.
        $this->actingAs($admin)->post('/sales/invoices', ['kind' => 'INVOICE', 'daftra_invoice_no' => 'INV-9', 'invoice_date' => '2026-03-10',
            'client_id' => $other->id, 'project_id' => $p->id, 'description' => 'TEST', 'amount' => 10, 'vat_amount' => 0])->assertSessionHasErrors('rule');

        $this->actingAs($admin)->post('/sales/invoices', ['kind' => 'INVOICE', 'daftra_invoice_no' => 'INV-0001', 'invoice_date' => '2026-03-10',
            'client_id' => $client->id, 'project_id' => $p->id, 'description' => 'TEST kitchen', 'amount' => 10000, 'vat_amount' => 1500])->assertSessionHasNoErrors();
        $inv = SalesInvoice::sole();
        $this->assertSame('DRAFT', $inv->status);
        $this->assertNull(JournalEntry::forDocument('SALE', $inv->id));
        // Daftra's number is entered once.
        $this->actingAs($admin)->post('/sales/invoices', ['kind' => 'INVOICE', 'daftra_invoice_no' => ' INV-0001 ', 'invoice_date' => '2026-03-11',
            'client_id' => $client->id, 'description' => 'TEST', 'amount' => 10, 'vat_amount' => 0])->assertSessionHasErrors('daftra_invoice_no');

        $this->actingAs($admin)->post("/sales/invoices/{$inv->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame([['1110', 11500.0, 0.0, 'CLIENT', $p->id], ['2110', 0.0, 1500.0, null, null], ['4101', 0.0, 10000.0, null, $p->id]], $this->lines('SALE', $inv->id));
        $this->actingAs($admin)->put("/sales/invoices/{$inv->id}", ['kind' => 'INVOICE', 'daftra_invoice_no' => 'INV-0001', 'invoice_date' => '2026-03-10',
            'client_id' => $client->id, 'description' => 'x', 'amount' => 1, 'vat_amount' => 0])->assertSessionHasErrors('rule');

        // Credit note: on the invoice's client and project, the mirror entry.
        $this->actingAs($admin)->get("/sales/invoices/create?original={$inv->id}")->assertOk()->assertSee('INV-0001');
        $this->actingAs($admin)->post('/sales/invoices', ['kind' => 'CREDIT_NOTE', 'original_invoice_id' => $inv->id, 'daftra_invoice_no' => 'CN-0001',
            'invoice_date' => '2026-03-15', 'description' => 'TEST discount', 'amount' => 1000, 'vat_amount' => 150])->assertSessionHasNoErrors();
        $cn = SalesInvoice::where('kind', 'CREDIT_NOTE')->sole();
        $this->assertSame([$client->id, $p->id], [$cn->client_id, $cn->project_id]);
        $this->actingAs($admin)->post("/sales/invoices/{$cn->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame([['1110', 0.0, 1150.0, 'CLIENT', $p->id], ['2110', 150.0, 0.0, null, null], ['4101', 1000.0, 0.0, null, $p->id]], $this->lines('SALE', $cn->id));

        // Receipt into the cash box, on the invoice.
        $this->actingAs($admin)->get("/sales/receipts/create?invoice={$inv->id}")->assertOk()->assertSee('10350');
        $this->actingAs($admin)->post('/sales/receipts', ['receipt_date' => '2026-03-20', 'client_id' => $client->id, 'sales_invoice_id' => $inv->id,
            'payment_account_id' => $cash->id, 'amount' => 6000, 'reference' => 'TEST-TRX'])->assertSessionHasNoErrors();
        $rc = CustomerReceipt::sole();
        $this->assertSame($p->id, $rc->project_id);
        $this->actingAs($admin)->post("/sales/receipts/{$rc->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame([['1101', 6000.0, 0.0, null, null], ['1110', 0.0, 6000.0, 'CLIENT', $p->id]], $this->lines('RECEIPT', $rc->id));

        // Balances: 11,500 − 1,150 − 6,000 = 4,350 — the same in the books.
        $this->assertEquals(4350, DB::table('v_client_balances')->where('client_id', $client->id)->value('balance'));
        $this->assertEquals(4350, DB::table('v_ledger_lines')->where('account_id', $this->acc('1110'))->where('partner_id', $client->id)->sum('net'));
        $this->actingAs($admin)->get("/sales/invoices/{$inv->id}")->assertOk()->assertSee('4,350.00')->assertSee($rc->receipt_no)->assertSee($cn->invoice_no);
        $this->actingAs($admin)->get('/sales/balances')->assertOk()->assertSee('TEST client')->assertSee('4,350.00');
        $this->actingAs($admin)->get("/clients/{$client->id}")->assertOk()->assertSee('4,350.00');
        $this->actingAs($admin)->get("/projects/{$p->id}")->assertOk()->assertSee('9,000.00')->assertSee('6,000.00');
        $this->assertSame(0, DB::table('v_posting_backlog')->whereIn('source_type', ['SALE', 'RECEIPT'])->count());

        // Approved documents are not deleted; a draft is.
        $this->actingAs($admin)->get("/sales/invoices/{$inv->id}")->assertDontSee(route('records.destroy', ['sales-invoices', $inv->id]), false);
        $this->actingAs($admin)->post('/sales/receipts', ['receipt_date' => '2026-03-21', 'client_id' => $client->id,
            'payment_account_id' => $cash->id, 'amount' => 50])->assertSessionHasNoErrors();
        $draft = CustomerReceipt::where('status', 'DRAFT')->sole();
        $this->actingAs($admin)->delete(route('records.destroy', ['receipts', $draft->id]))->assertSessionHasNoErrors();
        $this->assertNull(CustomerReceipt::find($draft->id));
    }

    public function test_permissions(): void
    {
        $client = Client::create(['business_name' => 'TEST client']);
        $viewer = $this->userWith(['sales.view']);
        $this->actingAs($viewer)->get('/sales/invoices')->assertOk()->assertDontSee(route('sales.invoices.create'), false);
        $this->actingAs($viewer)->get('/sales/invoices/create')->assertForbidden();
        $this->actingAs($viewer)->post('/sales/invoices', ['kind' => 'INVOICE', 'daftra_invoice_no' => 'X', 'invoice_date' => '2026-03-10',
            'client_id' => $client->id, 'description' => 'x', 'amount' => 1, 'vat_amount' => 0])->assertForbidden();
        $clerk = $this->userWith(['sales.view', 'sales.manage']);
        $this->actingAs($clerk)->post('/sales/invoices', ['kind' => 'INVOICE', 'daftra_invoice_no' => 'X', 'invoice_date' => '2026-03-10',
            'client_id' => $client->id, 'description' => 'x', 'amount' => 1, 'vat_amount' => 0])->assertSessionHasNoErrors();
        $this->actingAs($clerk)->post('/sales/invoices/'.SalesInvoice::value('id').'/approve')->assertForbidden();
        $this->actingAs($this->userWith(['studio.view']))->get('/sales/invoices')->assertForbidden();
    }
}
