<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\PaymentAccount;
use App\Models\PostingBacklog;
use App\Support\ChartTemplate;
use App\Support\ListView;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The link between the operations and the books (accounting plan step أ): which ledger account
 * each payment account, expense category and system role posts to; the auto-posting switch;
 * approved documents still missing from the books (post or exclude with a reason); and the
 * reconciliation of every subledger kept here against its ledger account. The entries
 * themselves are written by the database (fn_post_document) when a document is approved.
 */
class PostingController extends Controller
{
    /** Roles every automatic entry may need. */
    public const REQUIRED_ROLES = ['INVENTORY', 'WIP', 'INPUT_VAT', 'PAYABLE', 'INVENTORY_ADJUSTMENT'];

    public function settings(): View
    {
        $accounts = Account::where('is_postable', true)->where('is_active', true)->orderBy('code')->get();

        return view('accounting.posting.settings', [
            'settings' => DB::table('accounting_settings')->first(),
            'gaps' => DB::select('SELECT * FROM fn_posting_gaps()'),
            'roles' => collect(self::REQUIRED_ROLES)->mapWithKeys(fn ($r) => [$r => $accounts->firstWhere('system_role', $r)]),
            'paymentAccounts' => PaymentAccount::orderByDesc('is_active')->orderBy('kind')->orderBy('name')->get(),
            'categories' => ExpenseCategory::orderByDesc('is_active')->orderBy('name')->get(),
            'accounts' => $accounts,
            'backlog' => PostingBacklog::count(),
        ]);
    }

    public function saveLinks(Request $request): RedirectResponse
    {
        $postable = Rule::exists('accounts', 'id')->where('is_postable', 'true')->where('is_active', 'true');
        $data = $request->validate([
            'roles' => ['array'], 'roles.*' => ['nullable', 'integer', $postable],
            'payment' => ['array'], 'payment.*' => ['nullable', 'integer', $postable],
            'category' => ['array'], 'category.*' => ['nullable', 'integer', $postable],
        ]);
        DB::transaction(function () use ($data) {
            foreach ($data['roles'] ?? [] as $role => $id) {
                if (! in_array($role, self::REQUIRED_ROLES, true)) {
                    continue;
                }
                $current = Account::where('system_role', $role)->first();
                if ($current?->id === ($id ? (int) $id : null)) {
                    continue;
                }
                $current?->forceFill(['system_role' => null])->save();
                if ($id) {
                    Account::findOrFail($id)->forceFill(['system_role' => $role])->save();
                }
            }
            foreach ($data['payment'] ?? [] as $pid => $id) {
                PaymentAccount::whereKey($pid)->first()?->forceFill(['account_id' => $id ?: null])->save();
            }
            foreach ($data['category'] ?? [] as $cid => $id) {
                ExpenseCategory::whereKey($cid)->first()?->forceFill(['account_id' => $id ?: null])->save();
            }
        });

        return back()->with('ok', __('حُفظ الربط المحاسبي.'));
    }

    /**
     * A required role nobody holds (typically «فروقات جرد المخزون» in a chart created before it
     * was added) gets its account from the proposed chart, under the same group, if the code is free.
     */
    public function createRoleAccounts(): RedirectResponse
    {
        $made = [];
        foreach (ChartTemplate::rows() as [$code, $name, $nameEn, $type, $parent, $postable, $role, $detail]) {
            if (! in_array($role, self::REQUIRED_ROLES, true) || Account::where('system_role', $role)->exists()) {
                continue;
            }
            $group = $parent ? Account::where('code', $parent)->where('is_postable', false)->first() : null;
            if (Account::where('code', $code)->exists() || ($parent && ! $group)) {
                throw ValidationException::withMessages(['rule' => __('تعذّر إنشاء حساب «:n» تلقائيًا (الرمز :c مستخدم أو مجموعته غير موجودة)؛ أنشئه من دليل الحسابات ثم اختره هنا.', ['n' => $name, 'c' => $code])]);
            }
            Account::create(['code' => $code, 'name' => $name, 'name_en' => $nameEn, 'account_type' => $type, 'detail_type' => $detail,
                'parent_id' => $group?->id, 'is_postable' => $postable, 'system_role' => $role]);
            $made[] = "$code $name";
        }

        return back()->with('ok', $made ? __('أُنشئ: :list', ['list' => implode('، ', $made)]) : __('كل الأدوار لها حسابات.'));
    }

    public function toggle(Request $request): RedirectResponse
    {
        $on = $request->boolean('auto_posting');
        DB::table('accounting_settings')->where('id', 1)->update(['auto_posting' => $on]);

        return back()->with('ok', $on ? __('فُعِّل الترحيل الآلي: كل مستند يُعتمد من الآن يُنشئ قيده تلقائيًا.') : __('أُوقف الترحيل الآلي؛ المستندات المعتمدة بعد الآن تنتظر في «مستندات لم تُرحَّل».'));
    }

    public function backlog(Request $request): View
    {
        $type = fn (string $t) => fn ($q) => $q->where('source_type', $t);
        $lv = new ListView($request,
            filters: [
                'expense' => ['label' => __('المصروفات'), 'group' => 't', 'apply' => $type('EXPENSE')],
                'purchase' => ['label' => __('فواتير المشتريات'), 'group' => 't', 'apply' => $type('PURCHASE')],
                'transfer' => ['label' => __('تحويلات الخزينة'), 'group' => 't', 'apply' => $type('TRANSFER')],
                'stock' => ['label' => __('حركات المخزون'), 'group' => 't', 'apply' => $type('STOCK')],
            ],
            groups: [
                'type' => ['label' => __('نوع المستند'), 'key' => fn ($r) => $r->source_type, 'title' => fn ($r) => __("rroka.journal_source.$r->source_type")],
                'month' => ['label' => __('الشهر'), 'key' => fn ($r) => $r->doc_date->format('Y-m'), 'title' => fn ($r) => $r->doc_date->format('Y-m')],
            ],
        );
        $query = $lv->applyFilters(PostingBacklog::query()->orderBy('doc_date')->orderBy('source_type')->orderBy('source_id'));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('doc_no', 'ilike', "%{$s}%")->orWhere('description', 'ilike', "%{$s}%"));
        }

        return view('accounting.posting.backlog', [
            'lv' => $lv,
            'rows' => $lv->group ? null : $query->paginate(100)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->get()) : null,
            'total' => PostingBacklog::count(),
            'exclusions' => DB::table('posting_exclusions')->orderByDesc('created_at')->limit(50)->get(),
            'names' => DB::table('users')->pluck('name', 'id'),
            'auto' => (bool) DB::table('accounting_settings')->value('auto_posting'),
        ]);
    }

    /** Post one document from the backlog. */
    public function post(Request $request, string $type, int $id): RedirectResponse
    {
        abort_unless(in_array($type, JournalEntry::DOCUMENT_SOURCES, true), 404);
        $entryId = DB::scalar('SELECT fn_post_document(?, ?, ?)', [$type, $id, $request->user()->id]);

        return back()->with('ok', $entryId
            ? __('رُحِّل المستند بالقيد :no.', ['no' => JournalEntry::find($entryId)->entry_no])
            : __('المستند بلا قيمة؛ لا قيد له.'));
    }

    /** Post every document in the backlog (each on its own; failures are listed, the rest post). */
    public function postAll(Request $request): RedirectResponse
    {
        $posted = 0;
        $failed = [];
        foreach (PostingBacklog::orderBy('doc_date')->orderBy('source_id')->get() as $d) {
            try {
                DB::transaction(fn () => DB::scalar('SELECT fn_post_document(?, ?, ?)', [$d->source_type, $d->source_id, $request->user()->id]));
                $posted++;
            } catch (QueryException $e) {
                $code = preg_match('/RROKA_[A-Z_]+/', $e->getMessage(), $m) ? $m[0] : null;
                $failed[] = $d->doc_no.': '.($code ? __("rroka.errors.$code") : __('تعذّر الترحيل'));
            }
        }
        $back = back()->with('ok', __('رُحِّل :n مستند.', ['n' => $posted]));

        return $failed ? $back->withErrors(['rule' => __('لم يُرحَّل :n:', ['n' => count($failed)]).' '.implode(' — ', array_slice($failed, 0, 8))]) : $back;
    }

    public function exclude(Request $request, string $type, int $id): RedirectResponse
    {
        abort_unless(in_array($type, JournalEntry::DOCUMENT_SOURCES, true), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::table('posting_exclusions')->insert(['source_type' => $type, 'source_id' => $id, 'reason' => $data['reason'], 'created_by' => $request->user()->id]);

        return back()->with('ok', __('استُبعد المستند من الدفاتر والسبب محفوظ.'));
    }

    public function unexclude(int $exclusion): RedirectResponse
    {
        DB::table('posting_exclusions')->where('id', $exclusion)->delete();

        return back()->with('ok', __('أُلغي الاستبعاد؛ عاد المستند إلى قائمة ما لم يُرحَّل.'));
    }

    /**
     * Subledger (documents kept here) against ledger (posted entries). A difference is
     * explained by documents not yet posted, the opening entry, or manual entries.
     */
    public function reconciliation(): View
    {
        $role = fn (string $r) => Account::where('system_role', $r)->first();
        $gl = fn (?Account $a, ?callable $where = null) => $a
            ? (float) DB::table('v_ledger_lines')->where('account_id', $a->id)->when($where, $where)->sum('net')
            : null;
        $inv = $role('INVENTORY');
        $wip = $role('WIP');
        $vat = $role('INPUT_VAT');
        $ap = $role('PAYABLE');

        $rows = [
            [
                'label' => __('مخزون الخامات'), 'account' => $inv,
                'sub' => (float) DB::table('stock_balances')->selectRaw('COALESCE(sum(round(qty_on_hand * COALESCE(avg_unit_cost, 0), 2)), 0) AS v')->value('v'),
                'gl' => $gl($inv), 'note' => __('الكمية المتاحة × متوسط التكلفة'), 'link' => route('materials.index'),
            ],
            [
                'label' => __('أعمال تحت التنفيذ (المشاريع)'), 'account' => $wip,
                'sub' => (float) DB::selectOne("SELECT COALESCE((SELECT sum(CASE movement_type WHEN 'ISSUE' THEN 1 ELSE -1 END * round(quantity * unit_cost, 2)) FROM stock_movements WHERE movement_type IN ('ISSUE', 'RETURN')), 0)
                    + COALESCE((SELECT sum(amount) FROM expenses WHERE status = 'APPROVED' AND project_id IS NOT NULL), 0) AS v")->v,
                'gl' => $gl($wip), 'note' => __('خامات مصروفة صافية + مصروفات معتمدة على المشاريع'), 'link' => null,
            ],
            [
                'label' => __('ضريبة المدخلات'), 'account' => $vat,
                'sub' => (float) DB::selectOne("SELECT COALESCE((SELECT sum(vat_amount) FROM expenses WHERE status = 'APPROVED'), 0)
                    + COALESCE((SELECT sum(vat_amount) FROM purchase_invoices WHERE status = 'APPROVED'), 0) AS v")->v,
                'gl' => $gl($vat), 'note' => __('ضريبة المصروفات والمشتريات المعتمدة (قبل تسوية الإقرار)'), 'link' => null,
            ],
            [
                'label' => __('الموردون'), 'account' => $ap,
                'sub' => (float) DB::table('v_purchase_totals as t')->join('purchase_invoices as p', 'p.id', '=', 't.purchase_invoice_id')->where('p.status', 'APPROVED')->sum('t.total'),
                'gl' => $ap ? -$gl($ap) : null, 'note' => __('فواتير المشتريات المعتمدة؛ سداد الموردين يُسجَّل في المرحلة (ب)'), 'link' => route('purchases.index'),
            ],
        ];

        $custody = PaymentAccount::where('kind', 'CUSTODY')->orderBy('name')->get()->map(function ($p) use ($gl) {
            $acc = $p->account_id ? Account::find($p->account_id) : null;

            return [
                'label' => $p->name, 'account' => $acc,
                'sub' => (float) DB::scalar('SELECT fn_custody_balance(?)', [$p->id]),
                'gl' => $gl($acc, fn ($q) => $q->where('partner_type', 'EMPLOYEE')->where('partner_id', $p->employee_id)),
                'link' => route('treasury.accounts.show', $p),
            ];
        })->filter(fn ($r) => $r['sub'] != 0 || ($r['gl'] ?? 0) != 0)->values();

        $projects = DB::select("
            WITH sub AS (
                SELECT project_id, sum(CASE movement_type WHEN 'ISSUE' THEN 1 ELSE -1 END * round(quantity * unit_cost, 2)) AS v
                  FROM stock_movements WHERE movement_type IN ('ISSUE', 'RETURN') GROUP BY project_id
                UNION ALL
                SELECT project_id, sum(amount) FROM expenses WHERE status = 'APPROVED' AND project_id IS NOT NULL GROUP BY project_id
            ), s AS (SELECT project_id, sum(v) AS v FROM sub GROUP BY project_id),
            g AS (SELECT project_id, sum(net) AS v FROM v_ledger_lines WHERE account_id = ? AND project_id IS NOT NULL GROUP BY project_id)
            SELECT p.id, p.project_no, p.title, COALESCE(s.v, 0) AS sub, COALESCE(g.v, 0) AS gl
              FROM projects p LEFT JOIN s ON s.project_id = p.id LEFT JOIN g ON g.project_id = p.id
             WHERE COALESCE(s.v, 0) <> 0 OR COALESCE(g.v, 0) <> 0
             ORDER BY p.project_no", [$wip?->id ?? 0]);

        return view('accounting.posting.reconciliation', [
            'rows' => $rows, 'custody' => $custody, 'projects' => $projects, 'wip' => $wip,
            'backlog' => PostingBacklog::selectRaw('count(*) AS n, COALESCE(sum(amount), 0) AS v')->first(),
            'excluded' => DB::table('posting_exclusions')->count(),
            'books' => DB::table('accounting_settings')->value('books_start'),
        ]);
    }
}
