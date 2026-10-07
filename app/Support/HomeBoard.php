<?php

namespace App\Support;

use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The home page below the app grid (Odoo idea: "what is waiting for me", not general totals):
 *  - actions: only what needs this user's action now, each a link to exactly those records;
 *    an item with nothing waiting is not shown;
 *  - setup: the getting-started checklist, computed from the data, until every step is done.
 * Each item is shown only to users with the permission to act on it.
 */
class HomeBoard
{
    /** @return list<array{label:string, count:int, amount:?float, url:string, tone:string}> */
    public static function actions(User $u): array
    {
        $can = fn (string ...$p) => AppMenu::can($u, $p);
        $items = [];
        $add = function (bool $allowed, string $label, callable $count, string $url, string $tone = 'info', ?callable $amount = null) use (&$items) {
            if (! $allowed) {
                return;
            }
            $n = (int) $count();
            if ($n > 0) {
                $items[] = ['label' => $label, 'count' => $n, 'amount' => $amount ? (float) $amount() : null, 'url' => $url, 'tone' => $tone];
            }
        };

        $add($can('expenses.approve', 'expenses.manage'), __('مصروفات بانتظار الاعتماد'),
            fn () => DB::table('expenses')->where('status', 'DRAFT')->count(), route('expenses.index', ['f' => ['draft']]), 'warn',
            fn () => DB::table('expenses')->where('status', 'DRAFT')->sum(DB::raw('amount + vat_amount')));
        $add($can('purchases.approve', 'purchases.manage'), __('فواتير مشتريات بانتظار الاعتماد'),
            fn () => DB::table('purchase_invoices')->where('status', 'DRAFT')->count(), route('purchases.index', ['f' => ['draft']]), 'warn',
            fn () => DB::table('v_purchase_totals as t')->join('purchase_invoices as p', 'p.id', '=', 't.purchase_invoice_id')->where('p.status', 'DRAFT')->sum('t.total'));
        $add($can('treasury.approve', 'treasury.manage'), __('تحويلات وعهد بانتظار الاعتماد'),
            fn () => DB::table('treasury_transfers')->where('status', 'DRAFT')->count(), route('treasury.transfers.index', ['f' => ['draft']]), 'warn',
            fn () => DB::table('treasury_transfers')->where('status', 'DRAFT')->sum('amount'));
        $add($can('accounting.view', 'accounting.manage', 'accounting.post', 'accounting.close'), __('مستندات معتمدة لم تُرحَّل للدفاتر'),
            fn () => DB::table('v_posting_backlog')->count(), route('accounting.posting.backlog'), 'bad',
            fn () => DB::table('v_posting_backlog')->sum('amount'));
        $add($can('accounting.post'), __('قيود يدوية في المسودة'),
            fn () => DB::table('journal_entries')->where('status', 'DRAFT')->count(), route('accounting.journal.index', ['f' => ['draft']]));
        $add($can('quotations.view'), __('عروض بانتظار رد العميل'),
            fn () => DB::table('quotations')->where('status', 'SENT')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', today()))->count(),
            route('quotations.index', ['f' => ['sent']]));
        $add($can('quotations.view'), __('عروض انتهت صلاحيتها دون رد'),
            fn () => DB::table('quotations')->where('status', 'SENT')->where('valid_until', '<', today())->count(), route('quotations.index', ['f' => ['sent']]), 'bad');
        $add($can('quotations.manage'), __('عروض في المسودة'),
            fn () => DB::table('quotations')->where('status', 'DRAFT')->count(), route('quotations.index', ['f' => ['draft']]));
        $add($can('production.manage'), __('أوامر تصنيع متأخرة عن موعدها'),
            fn () => DB::table('production_orders')->whereIn('status', ['PLANNED', 'IN_PROGRESS'])->whereDate('planned_end', '<', today())->count(),
            route('production.index', ['f' => ['late']]), 'bad');
        $add($can('treasury.view', 'treasury.manage'), __('عهد مفتوحة لدى الموظفين'),
            fn () => DB::table('v_payment_account_summary')->whereNotNull('custody_balance')->where('custody_balance', '<>', 0)->count(),
            route('treasury.accounts.index', ['f' => ['custody']]), 'info',
            fn () => DB::table('v_payment_account_summary')->whereNotNull('custody_balance')->sum('custody_balance'));
        $add($can('inventory.view', 'inventory.move'), __('خامات نفد المتاح منها'),
            fn () => DB::table('stock_balances as b')->join('raw_materials as m', 'm.id', '=', 'b.material_id')->where('m.is_active', true)
                ->whereRaw('b.qty_on_hand - b.qty_reserved <= 0')->count(), route('materials.index'), 'warn');
        $add($can('hr.manage'), __('وثائق موظفين منتهية أو قريبة الانتهاء'),
            fn () => DB::table('employee_documents')->whereNotNull('expiry_date')->where('expiry_date', '<=', today()->addDays(EmployeeDocument::WARN_DAYS))->count(),
            route('employees.index', ['f' => ['docs']]), 'warn');
        $add($can('hr.leave_approve'), __('طلبات إجازة بانتظار الاعتماد'),
            fn () => DB::table('leave_requests')->where('status', 'SUBMITTED')->count(), route('leaves.index', ['f' => ['to_approve']]), 'warn');

        return $items;
    }

    /**
     * Getting started, in the order the work depends on. Steps the user cannot act on are left out.
     *
     * @return list<array{label:string, hint:string, done:bool, url:string}>
     */
    public static function setup(User $u): array
    {
        $can = fn (string ...$p) => AppMenu::can($u, $p);
        $steps = [
            [$can('accounting.manage'), __('دليل الحسابات'), __('أنشئ الدليل المقترح وراجعه، وأضف حساب كل بنك.'),
                fn () => DB::table('accounts')->exists(), route('accounting.accounts.index')],
            [$can('treasury.manage'), __('الصناديق والبنوك والعهد'), __('الصندوق وكل حساب بنكي وعهدة كل موظف.'),
                fn () => DB::table('payment_accounts')->where('is_active', true)->exists(), route('treasury.accounts.index')],
            [$can('expenses.manage'), __('تصنيفات المصروفات'), __('بنود المصروفات (نقل، إيجار، كهرباء…).'),
                fn () => DB::table('expense_categories')->where('is_active', true)->exists(), route('expense-categories.index')],
            [$can('accounting.manage', 'accounting.close'), __('الربط المحاسبي والترحيل الآلي'), __('حساب لكل خزينة وبند، ثم تفعيل الترحيل الآلي.'),
                fn () => (bool) DB::table('accounting_settings')->value('auto_posting') && DB::select('SELECT 1 FROM fn_posting_gaps() LIMIT 1') === [],
                route('accounting.posting.settings')],
            [$can('purchases.manage'), __('الموردون'), __('موردو الخامات والخدمات.'),
                fn () => DB::table('suppliers')->exists(), route('suppliers.index')],
            [$can('inventory.move'), __('الخامات'), __('الخامات والمستلزمات بوحداتها.'),
                fn () => DB::table('raw_materials')->exists(), route('materials.index')],
            [$can('hr.manage'), __('الموظفون'), __('ملفات الموظفين وأقسامهم.'),
                fn () => DB::table('workers')->exists(), route('employees.index')],
            [$can('clients.manage'), __('العملاء'), __('العملاء الحاليون والمحتملون.'),
                fn () => DB::table('clients')->exists(), route('clients.index')],
            [$can('settings.cost_rates'), __('نسبة ضريبة القيمة المضافة وسياسة التسعير'), __('النسبة المعتمدة وهامش الربح المستهدف.'),
                fn () => DB::table('vat_rates')->where('is_active', true)->exists() && DB::table('pricing_policies')->where('status', 'APPROVED')->exists(),
                route('costing.pricing')],
            [$can('settings.cost_rates'), __('معدلات التكلفة'), __('بطاقات تكلفة الموظفين والآلات من البيانات الفعلية (لا تقديرات).'),
                fn () => DB::table('employee_cost_cards')->where('status', 'APPROVED')->exists(), route('costing.index')],
        ];
        $out = [];
        foreach ($steps as [$allowed, $label, $hint, $done, $url]) {
            if ($allowed) {
                $out[] = ['label' => $label, 'hint' => $hint, 'done' => (bool) $done(), 'url' => $url];
            }
        }

        return $out;
    }
}
