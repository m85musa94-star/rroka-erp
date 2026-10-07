<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentAccount;
use App\Models\Supplier;
use App\Support\Release;

/** Delete on every screen: drafts and unused master data only; approved documents stay. */
class RecordDeleteTest extends ApiTestCase
{
    public function test_drafts_and_unused_records_are_deleted_used_and_approved_ones_stay(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash'])->assertSessionHasNoErrors();
        $cash = PaymentAccount::sole();
        $cat = ExpenseCategory::create(['name' => 'TEST supplies', 'is_overhead' => false]);
        $spare = ExpenseCategory::create(['name' => 'TEST spare', 'is_overhead' => false]);
        $new = fn () => tap($this->actingAs($admin)->post('/expenses', ['expense_date' => now()->toDateString(), 'category_id' => $cat->id, 'payee' => 'TEST shop',
            'description' => 'TEST screws', 'amount' => 10, 'vat_amount' => 0, 'payment_account_id' => $cash->id]))->assertSessionHasNoErrors() ? Expense::latest('id')->first() : null;

        // A draft is deleted from its page.
        $draft = $new();
        $this->actingAs($admin)->get("/expenses/{$draft->id}")->assertOk()->assertSee(route('records.destroy', ['expenses', $draft->id]), false);
        $this->actingAs($admin)->delete("/records/expenses/{$draft->id}")->assertRedirect(route('expenses.index'));
        $this->assertNull(Expense::find($draft->id));

        // An approved one shows no delete button, and is refused even if asked directly.
        $done = $new();
        $this->actingAs($admin)->post("/expenses/{$done->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($admin)->get("/expenses/{$done->id}")->assertOk()->assertDontSee(route('records.destroy', ['expenses', $done->id]), false);
        $this->actingAs($admin)->delete("/records/expenses/{$done->id}")->assertSessionHasErrors('rule');
        $this->assertNotNull(Expense::find($done->id));

        // Master data: an unused category goes; a used one is kept with a clear message.
        $this->actingAs($admin)->delete("/records/expense-categories/{$spare->id}")->assertSessionHasNoErrors();
        $this->assertNull(ExpenseCategory::find($spare->id));
        $this->actingAs($admin)->delete("/records/expense-categories/{$cat->id}")->assertSessionHasErrors('rule');
        $this->assertNotNull(ExpenseCategory::find($cat->id));

        $this->actingAs($admin)->post('/suppliers', ['name' => 'TEST timber'])->assertSessionHasNoErrors();
        $s = Supplier::sole();
        $this->actingAs($admin)->get("/suppliers/{$s->id}")->assertOk()->assertSee('حذف السجل نهائيًا؟');
        $this->actingAs($admin)->delete("/records/suppliers/{$s->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Supplier::count());

        // The release number is on every page.
        $this->actingAs($admin)->get('/')->assertSee(Release::VERSION);
        $this->get('/version')->assertOk()->assertSee(Release::VERSION);
    }

    public function test_delete_needs_the_module_permission(): void
    {
        $client = Client::create(['business_name' => 'TEST client']);
        $this->actingAs($this->userWith(['clients.view']))->get("/clients/{$client->id}")->assertOk()->assertDontSee('حذف السجل نهائيًا؟');
        $this->actingAs($this->userWith(['clients.view']))->delete("/records/clients/{$client->id}")->assertForbidden();
        $this->actingAs($this->userWith(['clients.view', 'clients.manage']))->delete("/records/clients/{$client->id}")->assertSessionHasNoErrors();
        $this->assertNull(Client::find($client->id));
    }
}
