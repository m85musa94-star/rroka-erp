<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PaymentAccount;
use App\Support\ActivityLog;
use App\Support\ListView;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cash boxes, bank accounts and employee custody (Odoo: cash/bank journals). Every
 * expense and transfer names one of them; each maps to a Daftra treasury, where the
 * bank and cash reconciliation is done. Only custody shows a balance here.
 */
class PaymentAccountController extends Controller
{
    public function index(Request $request): View
    {
        $kind = fn (string $k) => fn ($q) => $q->where('kind', $k);
        $lv = new ListView($request,
            filters: [
                'cash' => ['label' => __('الصناديق النقدية'), 'group' => 'k', 'apply' => $kind('CASH')],
                'bank' => ['label' => __('الحسابات البنكية'), 'group' => 'k', 'apply' => $kind('BANK')],
                'custody' => ['label' => __('عهد الموظفين'), 'group' => 'k', 'apply' => $kind('CUSTODY')],
                'owed' => ['label' => __('عهد دفع أصحابها من مالهم'), 'group' => 'x', 'apply' => fn ($q) => $q->whereHas('summary', fn ($s) => $s->where('custody_balance', '<', 0))],
                'drafts' => ['label' => __('عليها مسودات'), 'group' => 'x', 'apply' => fn ($q) => $q->whereHas('summary', fn ($s) => $s->where('drafts', '>', 0))],
                'not_synced' => ['label' => __('حركات لم تُرسل لدفترة'), 'group' => 'x', 'apply' => fn ($q) => $q->whereHas('summary', fn ($s) => $s->where('not_in_daftra', '>', 0))],
                'inactive' => ['label' => __('مغلقة'), 'group' => 'a', 'apply' => fn ($q) => $q->where('is_active', false)],
            ],
            groups: ['kind' => ['label' => __('النوع'), 'key' => fn ($a) => $a->kind, 'title' => fn ($a) => __("rroka.account_kind.$a->kind")]],
            views: ['kanban', 'list'],
        );
        $query = $lv->applyFilters(PaymentAccount::with('employee:id,name', 'summary')->orderBy('kind')->orderBy('name'));
        if (! in_array('inactive', $lv->active, true)) {
            $query->where('is_active', true);
        }
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$s}%")->orWhere('bank_name', 'ilike', "%{$s}%")
                ->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$s}%")));
        }
        $accounts = $query->get();

        return view('treasury.accounts.index', [
            'lv' => $lv,
            'accounts' => $lv->group ? null : $accounts,
            'groups' => $lv->group ? $lv->grouped($accounts) : null,
        ]);
    }

    public function create(Request $request): View
    {
        $kind = in_array($request->query('kind'), PaymentAccount::KINDS, true) ? $request->query('kind') : 'CASH';

        return view('treasury.accounts.form', ['a' => new PaymentAccount(['kind' => $kind, 'is_active' => true]), 'employees' => $this->custodians()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $a = new PaymentAccount($this->validated($request));
        $a->created_by = $request->user()->id;
        $a->save();

        return redirect()->route('treasury.accounts.show', $a)->with('ok', __('تمت إضافة الحساب.'));
    }

    /** The statement: every movement in the period; a running balance for custody only. */
    public function show(Request $request, PaymentAccount $account): View|StreamedResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $request->date('from') ? CarbonImmutable::parse($request->date('from')) : CarbonImmutable::today()->subMonths(2)->startOfMonth();
        $to = $request->date('to') ? CarbonImmutable::parse($request->date('to')) : CarbonImmutable::today();

        $lines = DB::table('v_payment_account_lines as l')
            ->leftJoin('payment_accounts as c', 'c.id', '=', 'l.counterpart_account_id')
            ->where('l.account_id', $account->id)->whereBetween('l.line_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('l.line_date')->orderBy('l.created_at')
            ->get(['l.*', 'c.name as counterpart']);

        $opening = null;
        if ($account->isCustody()) {
            $opening = (float) DB::table('v_payment_account_lines')->where('account_id', $account->id)->where('status', 'APPROVED')
                ->where('line_date', '<', $from->toDateString())->selectRaw('COALESCE(sum(amount_in - amount_out), 0) v')->value('v');
            $running = $opening;
            foreach ($lines as $l) {
                if ($l->status === 'APPROVED') {
                    $running += (float) $l->amount_in - (float) $l->amount_out;
                    $l->balance = $running;
                }
            }
        }

        if ($request->query('export') === 'csv') {
            return $this->csv($account, $lines, $from, $to);
        }

        $approved = $lines->where('status', 'APPROVED');

        return view('treasury.accounts.show', [
            'a' => $account->load('employee', 'summary'),
            'lines' => $lines, 'from' => $from, 'to' => $to, 'opening' => $opening,
            'periodIn' => $approved->sum('amount_in'), 'periodOut' => $approved->sum('amount_out'),
            'activity' => ActivityLog::for(['payment_accounts' => [$account->id]]),
        ]);
    }

    public function edit(PaymentAccount $account): View
    {
        return view('treasury.accounts.form', ['a' => $account, 'employees' => $this->custodians()]);
    }

    public function update(Request $request, PaymentAccount $account): RedirectResponse
    {
        $account->update($this->validated($request, $account));

        return redirect()->route('treasury.accounts.show', $account)->with('ok', __('تم تحديث الحساب.'));
    }

    /**
     * Only an account that was never used is deleted (an entry made by mistake); one with
     * expenses or transfers is closed instead — the database foreign keys refuse it too.
     */
    public function destroy(PaymentAccount $account): RedirectResponse
    {
        $used = DB::table('expenses')->where('payment_account_id', $account->id)->exists()
            || DB::table('treasury_transfers')->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id)->exists();
        if ($used) {
            throw ValidationException::withMessages(['rule' => __('الحساب عليه حركات؛ لا يُحذف بل يُغلق (إلغاء «نشط»).')]);
        }
        $account->delete();

        return redirect()->route('treasury.accounts.index')->with('ok', __('حُذف الحساب.'));
    }

    /** Kind and custodian are set once (the database refuses a change); the rest stays editable. */
    private function validated(Request $request, ?PaymentAccount $a = null): array
    {
        $request->merge(['iban' => $request->filled('iban') ? strtoupper(preg_replace('/\s+/', '', $request->input('iban'))) : null]);
        $kind = $a?->kind ?? $request->input('kind');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('payment_accounts', 'name')->ignore($a?->id)],
            'kind' => [$a ? 'prohibited' : 'required', Rule::in(PaymentAccount::KINDS)],
            'bank_name' => ['nullable', 'string', 'max:120', Rule::prohibitedIf($kind !== 'BANK')],
            'iban' => ['nullable', 'regex:/^SA[0-9]{2}[0-9A-Z]{20}$/', Rule::prohibitedIf($kind !== 'BANK')],
            'employee_id' => $a ? ['prohibited'] : ['nullable', 'integer', Rule::requiredIf($kind === 'CUSTODY'), Rule::prohibitedIf($kind !== 'CUSTODY'),
                Rule::exists('workers', 'id')->where('is_active', true),
                Rule::unique('payment_accounts', 'employee_id')->where('kind', 'CUSTODY')->where('is_active', true)],
            'custody_limit' => ['nullable', 'numeric', 'gt:0', Rule::prohibitedIf($kind !== 'CUSTODY')],
            'daftra_treasury_ref' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['employee_id.unique' => __('لهذا الموظف عهدة مفتوحة؛ أغلقها أولًا أو استخدمها.')]);

        return $data + ['is_active' => $a ? $request->boolean('is_active') : true];
    }

    private function custodians()
    {
        return Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    private function csv(PaymentAccount $account, $lines, CarbonImmutable $from, CarbonImmutable $to): StreamedResponse
    {
        $name = 'statement-'.$account->id.'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($lines, $account) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 Arabic correctly with a BOM
            $head = [__('التاريخ'), __('المستند'), __('البيان'), __('الطرف المقابل'), __('وارد'), __('صادر'), __('الحالة'), __('المرجع'), __('في دفترة')];
            if ($account->isCustody()) {
                $head[] = __('الرصيد');
            }
            fputcsv($out, $head);
            foreach ($lines as $l) {
                $row = [$l->line_date, $l->doc_no, $l->description, $l->counterpart ?? '', $l->amount_in, $l->amount_out,
                    __("rroka.status.$l->status"), $l->reference ?? '', $l->in_daftra ? __('نعم') : __('لا')];
                if ($account->isCustody()) {
                    $row[] = isset($l->balance) ? number_format($l->balance, 2, '.', '') : '';
                }
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
