<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\ActivityLog;
use App\Support\ChartTemplate;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chart of accounts and the ledger of one account. Balances come from posted lines only
 * (v_ledger_lines); a group account shows the total of everything under it.
 */
class AccountController extends Controller
{
    /** Odoo «Chart of Accounts»: postable accounts with their debit, credit and balance. */
    public function index(Request $request): View
    {
        $cls = fn (string $t) => fn ($q) => $q->where('accounts.account_type', $t);
        $lv = new ListView($request,
            filters: [
                'assets' => ['label' => __('الأصول'), 'group' => 'cls', 'apply' => $cls('ASSET')],
                'liabilities' => ['label' => __('الخصوم'), 'group' => 'cls', 'apply' => $cls('LIABILITY')],
                'equity' => ['label' => __('حقوق الملكية'), 'group' => 'cls', 'apply' => $cls('EQUITY')],
                'income' => ['label' => __('الإيرادات'), 'group' => 'cls', 'apply' => $cls('REVENUE')],
                'expenses' => ['label' => __('المصروفات'), 'group' => 'cls', 'apply' => $cls('EXPENSE')],
                'reconcile' => ['label' => __('تسمح بالتسوية'), 'group' => 'x', 'apply' => fn ($q) => $q->where('reconcile', true)],
                'with_balance' => ['label' => __('عليها حركة'), 'group' => 'y', 'apply' => fn ($q) => $q->whereNotNull('t.account_id')],
                'untyped' => ['label' => __('بلا نوع (للمراجعة)'), 'group' => 'z', 'apply' => fn ($q) => $q->whereNull('detail_type')],
                'archived' => ['label' => __('المؤرشفة'), 'group' => 'arc', 'apply' => fn ($q) => $q->where('accounts.is_active', false)],
            ],
            groups: [
                'type' => ['label' => __('النوع'), 'key' => fn ($a) => $a->detail_type ?? '', 'title' => fn ($a) => $a->detail_type ? __("rroka.detail_type.$a->detail_type") : __('بلا نوع')],
                'class' => ['label' => __('التصنيف الرئيسي'), 'key' => fn ($a) => array_search($a->account_type, Account::TYPES, true), 'title' => fn ($a) => __("rroka.account_type.$a->account_type")],
                'group' => ['label' => __('مجموعة الحسابات'), 'key' => fn ($a) => $a->parent_id ?? 0, 'title' => fn ($a) => $a->parent ? $a->parent->code.' '.$a->parent->label() : __('بلا مجموعة')],
            ],
        );
        $sums = DB::table('v_ledger_lines')->groupBy('account_id')->selectRaw('account_id, sum(debit) AS debit, sum(credit) AS credit');
        $query = $lv->applyFilters(Account::with('parent')->where('accounts.is_postable', true)
            ->leftJoinSub($sums, 't', 't.account_id', '=', 'accounts.id')
            ->select('accounts.*', 't.debit', 't.credit')
            ->orderBy('accounts.code'));
        if (! in_array('archived', $lv->active, true)) {
            $query->where('accounts.is_active', true);
        }
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('accounts.code', 'like', "{$s}%")->orWhere('accounts.name', 'ilike', "%{$s}%")->orWhere('accounts.name_en', 'ilike', "%{$s}%"));
        }

        return view('accounting.accounts.index', [
            'lv' => $lv,
            'accounts' => $lv->group ? null : $query->paginate(80)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->get()) : null,
            'empty' => ! Account::exists(),
            'untyped' => Account::where('is_postable', true)->whereNull('detail_type')->count(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('accounting.accounts.form', ['a' => new Account(['detail_type' => $request->query('type'), 'is_active' => true]), ...$this->choices(), 'activity' => collect()]);
    }

    public function edit(Account $account): View|RedirectResponse
    {
        if (! $account->is_postable) {
            return redirect()->route('accounting.accounts.groups', ['edit' => $account->id]);
        }
        $t = DB::table('v_ledger_lines')->where('account_id', $account->id)->selectRaw('count(*) AS n, sum(debit) AS debit, sum(credit) AS credit')->first();

        return view('accounting.accounts.form', ['a' => $account, ...$this->choices($account), 'totals' => $t,
            'activity' => ActivityLog::for(['accounts' => [$account->id]])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = Account::create($this->validated($request) + ['is_postable' => true]);

        return redirect()->route('accounting.accounts.edit', $account)->with('ok', __('أُضيف الحساب :code.', ['code' => $account->code]));
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $account->update($this->validated($request, $account) + ['is_active' => ! $request->boolean('archived')]);

        return redirect()->route('accounting.accounts.edit', $account)->with('ok', __('حُدّث الحساب :code.', ['code' => $account->code]));
    }

    /** Odoo «Account Groups»: the group accounts as a tree, with the totals under each. */
    public function groups(Request $request): View
    {
        $accounts = Account::orderBy('code')->get();
        $sums = DB::table('v_ledger_lines')->groupBy('account_id')->selectRaw('account_id, sum(debit) AS debit, sum(credit) AS credit')->get()->keyBy('account_id');
        $rows = array_values(array_filter($this->tree($accounts, $sums), fn ($r) => ! $r['a']->is_postable));

        return view('accounting.accounts.groups', [
            'rows' => $rows,
            'groupList' => $accounts->where('is_postable', false),
            'edit' => $request->integer('edit') ? $accounts->where('is_postable', false)->firstWhere('id', $request->integer('edit')) : null,
            'counts' => $accounts->where('is_postable', true)->countBy('parent_id'),
        ]);
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        Account::create($this->validatedGroup($request) + ['is_postable' => false]);

        return redirect()->route('accounting.accounts.groups')->with('ok', __('أُضيفت المجموعة.'));
    }

    public function updateGroup(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->is_postable, 404);
        $account->update($this->validatedGroup($request, $account));

        return redirect()->route('accounting.accounts.groups')->with('ok', __('حُدّثت المجموعة.'));
    }

    /** Creates the proposed chart, only into an empty chart; the owner then reviews and edits it. */
    public function fromTemplate(): RedirectResponse
    {
        if (Account::exists()) {
            throw ValidationException::withMessages(['rule' => __('يوجد دليل حسابات بالفعل؛ الدليل المقترح يُنشأ في دليل فارغ فقط.')]);
        }
        $ids = [];
        foreach (ChartTemplate::rows() as [$code, $name, $nameEn, $type, $parent, $postable, $role, $detail]) {
            $ids[$code] = Account::create(['code' => $code, 'name' => $name, 'name_en' => $nameEn, 'account_type' => $type, 'detail_type' => $detail,
                'parent_id' => $parent ? $ids[$parent] : null, 'is_postable' => $postable, 'system_role' => $role])->id;
        }

        return redirect()->route('accounting.accounts.index')->with('ok', __('أُنشئ الدليل المقترح. راجعه وعدّله، وأضف حساباتكم البنكية تحت «البنوك».'));
    }

    /** Ledger: opening balance, the posted lines of the range with a running balance, closing balance. */
    public function show(Request $request, Account $account): View|StreamedResponse
    {
        [$from, $to] = $this->range($request);
        $ids = $this->descendants($account);
        $sign = $account->isDebitNature() ? 1 : -1;
        $opening = $sign * (float) DB::table('v_ledger_lines')->whereIn('account_id', $ids)->where('entry_date', '<', $from)->sum('net');
        $lines = DB::table('v_ledger_lines as l')
            ->leftJoin('projects as p', 'p.id', '=', 'l.project_id')
            ->whereIn('l.account_id', $ids)->whereBetween('l.entry_date', [$from, $to])
            ->orderBy('l.entry_date')->orderBy('l.entry_no')->orderBy('l.line_id')
            ->get(['l.*', 'p.project_no']);
        $running = $opening;
        foreach ($lines as $l) {
            $running += $sign * (float) $l->net;
            $l->balance = $running;
        }

        if ($request->query('export') === 'csv') {
            return $this->csv($account, $from, $to, $opening, $lines);
        }

        return view('accounting.accounts.show', [
            'a' => $account->load('parent'), 'from' => $from, 'to' => $to, 'opening' => $opening, 'lines' => $lines,
            'closing' => $running, 'isGroup' => ! $account->is_postable,
            'activity' => ActivityLog::for(['accounts' => [$account->id]]),
        ]);
    }

    /** A postable account: its class follows its detailed type (the database derives it too). */
    private function validated(Request $request, ?Account $a = null): array
    {
        $request->merge(['code' => trim((string) $request->input('code')), 'system_role' => $request->input('system_role') ?: null]);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[0-9]{1,12}$/', Rule::unique('accounts', 'code')->ignore($a?->id)],
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'detail_type' => ['required', Rule::in(array_keys(Account::DETAIL_TYPES))],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_postable', 'false')],
            'system_role' => ['nullable', Rule::in(Account::ROLES), Rule::unique('accounts', 'system_role')->ignore($a?->id)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['code.regex' => __('رمز الحساب أرقام فقط (حتى 12 رقمًا).')]);

        return $data + ['account_type' => Account::DETAIL_TYPES[$data['detail_type']], 'reconcile' => $request->boolean('reconcile')];
    }

    private function validatedGroup(Request $request, ?Account $a = null): array
    {
        $request->merge(['code' => trim((string) $request->input('code'))]);

        return $request->validate([
            'code' => ['required', 'regex:/^[0-9]{1,12}$/', Rule::unique('accounts', 'code')->ignore($a?->id)],
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'account_type' => ['required', Rule::in(Account::TYPES)],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_postable', 'false'), Rule::notIn(array_filter([$a?->id]))],
        ], ['code.regex' => __('رمز الحساب أرقام فقط (حتى 12 رقمًا).')]);
    }

    private function choices(?Account $a = null): array
    {
        return [
            'groupList' => Account::where('is_postable', false)->where('is_active', true)->orderBy('code')->get(),
            'usedRoles' => Account::whereNotNull('system_role')->when($a, fn ($q) => $q->whereKeyNot($a->id))->pluck('system_role')->all(),
        ];
    }

    /** Depth-first rows with the balance of each account (groups roll up their children). */
    private function tree(Collection $accounts, Collection $sums): array
    {
        $byParent = $accounts->groupBy(fn ($a) => $a->parent_id ?? 0);
        $rows = [];
        $walk = function ($parentId, $depth) use (&$walk, &$rows, $byParent, $sums) {
            $dr = $cr = 0.0;
            foreach ($byParent[$parentId] ?? [] as $a) {
                $at = count($rows);
                $rows[] = null;
                [$cdr, $ccr] = $walk($a->id, $depth + 1);
                $adr = $cdr + (float) ($sums[$a->id]->debit ?? 0);
                $acr = $ccr + (float) ($sums[$a->id]->credit ?? 0);
                $rows[$at] = ['a' => $a, 'depth' => $depth, 'debit' => $adr, 'credit' => $acr,
                    'balance' => $a->isDebitNature() ? $adr - $acr : $acr - $adr, 'used' => isset($sums[$a->id])];
                $dr += $adr;
                $cr += $acr;
            }

            return [$dr, $cr];
        };
        $walk(0, 0);

        return $rows;
    }

    private function descendants(Account $account): array
    {
        return array_map(fn ($r) => (int) $r->id, DB::select(
            'WITH RECURSIVE t AS (SELECT id FROM accounts WHERE id = ? UNION ALL SELECT a.id FROM accounts a JOIN t ON a.parent_id = t.id) SELECT id FROM t',
            [$account->id]));
    }

    private function range(Request $request): array
    {
        $start = DB::table('accounting_settings')->value('books_start') ?? today()->startOfYear()->toDateString();
        $from = $request->date('from')?->toDateString() ?? $start;
        $to = $request->date('to')?->toDateString() ?? today()->toDateString();

        return $from > $to ? [$to, $from] : [$from, $to];
    }

    private function csv(Account $account, string $from, string $to, float $opening, Collection $lines): StreamedResponse
    {
        return response()->streamDownload(function () use ($opening, $lines) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('التاريخ'), __('رقم القيد'), __('الحساب'), __('البيان'), __('المشروع'), __('مدين'), __('دائن'), __('الرصيد')]);
            fputcsv($out, ['', '', '', __('رصيد أول المدة'), '', '', '', number_format($opening, 2, '.', '')]);
            foreach ($lines as $l) {
                fputcsv($out, [$l->entry_date, $l->entry_no, $l->account_code, $l->description ?: $l->entry_description, $l->project_no,
                    number_format((float) $l->debit, 2, '.', ''), number_format((float) $l->credit, 2, '.', ''), number_format($l->balance, 2, '.', '')]);
            }
            fclose($out);
        }, "ledger-{$account->code}-{$from}-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
