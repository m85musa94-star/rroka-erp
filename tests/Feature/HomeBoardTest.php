<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\PaymentAccount;

/** Home: getting-started checklist and "waiting for your action" cards; accounting dashboard. */
class HomeBoardTest extends ApiTestCase
{
    public function test_empty_system_shows_the_checklist_and_nothing_waiting(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/')->assertOk()
            ->assertSee('البدء بالمنظومة')->assertSee('0 من 10 خطوات')
            ->assertSee('لا شيء ينتظر إجراءً منك الآن')
            ->assertDontSee('نظرة عامة')->assertDontSee('نسبة المصروفات غير المباشرة لم تُدخل بعد');
    }

    public function test_waiting_items_appear_as_links_and_steps_tick(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/accounting/accounts/template')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/treasury/accounts', ['kind' => 'CASH', 'name' => 'TEST cash'])->assertSessionHasNoErrors();
        $cat = ExpenseCategory::create(['name' => 'TEST supplies', 'is_overhead' => false]);
        $this->actingAs($admin)->post('/expenses', ['expense_date' => now()->toDateString(), 'category_id' => $cat->id, 'payee' => 'TEST shop',
            'description' => 'TEST screws', 'amount' => 100, 'vat_amount' => 15, 'payment_account_id' => PaymentAccount::sole()->id])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/')->assertOk()
            ->assertSee('3 من 10 خطوات')
            ->assertSee('مصروفات بانتظار الاعتماد')->assertSee('115.00')
            ->assertSee(route('expenses.index', ['f' => ['draft']]), false)
            ->assertDontSee('لا شيء ينتظر إجراءً منك الآن');

        // A user without the permission sees neither the card nor the step.
        $this->actingAs($this->userWith(['clients.view']))->get('/')->assertOk()
            ->assertDontSee('مصروفات بانتظار الاعتماد')->assertDontSee('دليل الحسابات');

        // Accounting dashboard: a card per payment account, not yet linked.
        $this->actingAs($admin)->get('/accounting')->assertOk()->assertSee('TEST cash')->assertSee('غير مربوط بحساب')
            ->assertSee('1 مستند بانتظار الاعتماد')->assertSee('المستحق للموردين');
    }
}
