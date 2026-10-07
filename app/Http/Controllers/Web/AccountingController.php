<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The accounting dashboard and the monthly periods (closing rules are enforced by the database). The reports live in FinancialReportController. */
class AccountingController extends Controller
{
    /**
     * Odoo-style accounting dashboard: a card per cash box, bank and custody with its ledger
     * balance and what is waiting on it, then purchases, expenses and the books.
     */
    public function dashboard(): View
    {
        $balance = fn (?int $acc, ?array $partner = null) => $acc === null ? null
            : (float) DB::table('v_ledger_lines')->where('account_id', $acc)
                ->when($partner, fn ($q) => $q->where('partner_type', $partner[0])->where('partner_id', $partner[1]))->sum('net');
        $monthStart = today()->startOfMonth()->toDateString();
        $pay = DB::table('payment_accounts as p')->leftJoin('accounts as a', 'a.id', '=', 'p.account_id')->where('p.is_active', true)
            ->orderByRaw("CASE p.kind WHEN 'CASH' THEN 1 WHEN 'BANK' THEN 2 ELSE 3 END")->orderBy('p.name')
            ->get(['p.*', 'a.code as acc_code'])
            ->map(function ($p) use ($balance) {
                $p->balance = $balance($p->account_id, $p->kind === 'CUSTODY' ? ['EMPLOYEE', $p->employee_id] : null);
                $p->drafts = DB::table('expenses')->where('payment_account_id', $p->id)->where('status', 'DRAFT')->count()
                    + DB::table('treasury_transfers')->where('status', 'DRAFT')->where(fn ($q) => $q->where('from_account_id', $p->id)->orWhere('to_account_id', $p->id))->count();

                return $p;
            });
        $payable = DB::table('accounts')->where('system_role', 'PAYABLE')->value('id');

        return view('accounting.dashboard', [
            'pay' => $pay,
            'purchases' => [
                'drafts' => DB::table('purchase_invoices')->where('status', 'DRAFT')->count(),
                'month' => (float) DB::table('v_purchase_totals as t')->join('purchase_invoices as p', 'p.id', '=', 't.purchase_invoice_id')
                    ->where('p.status', 'APPROVED')->where('p.invoice_date', '>=', $monthStart)->sum('t.total'),
                'payable' => $payable ? -$balance($payable) : null,
            ],
            'expenses' => [
                'drafts' => DB::table('expenses')->where('status', 'DRAFT')->count(),
                'month' => (float) DB::table('expenses')->where('status', 'APPROVED')->where('expense_date', '>=', $monthStart)->sum(DB::raw('amount + vat_amount')),
            ],
            'books' => [
                'backlog' => DB::table('v_posting_backlog')->count(),
                'drafts' => DB::table('journal_entries')->where('status', 'DRAFT')->count(),
                'posted_month' => DB::table('journal_entries')->where('status', 'POSTED')->where('entry_date', '>=', $monthStart)->count(),
                'auto' => (bool) DB::table('accounting_settings')->value('auto_posting'),
                'period' => DB::table('fiscal_periods')->where('period_start', $monthStart)->value('status') ?? 'OPEN',
            ],
        ]);
    }

    /** Every month from the books start to now, with its status and entries. */
    public function periods(): View
    {
        $start = $this->booksStart();
        $rows = [];
        if ($start) {
            $stored = FiscalPeriod::all()->keyBy(fn ($p) => $p->period_start->format('Y-m'));
            $counts = DB::table('journal_entries')->selectRaw("to_char(entry_date, 'YYYY-MM') AS m, status, count(*) AS n")
                ->groupByRaw('1, 2')->get()->groupBy('m');
            $last = max(today()->format('Y-m'), $stored->keys()->max() ?? '', $counts->keys()->max() ?? '');
            for ($m = CarbonImmutable::parse($start)->startOfMonth(); $m->format('Y-m') <= $last; $m = $m->addMonth()) {
                $k = $m->format('Y-m');
                $c = ($counts[$k] ?? collect())->pluck('n', 'status');
                $rows[] = ['month' => $k, 'start' => $m->toDateString(), 'p' => $stored[$k] ?? null,
                    'posted' => (int) ($c['POSTED'] ?? 0), 'drafts' => (int) ($c['DRAFT'] ?? 0)];
            }
        }

        return view('accounting.periods', [
            'rows' => array_reverse($rows), 'start' => $start,
            'names' => DB::table('users')->pluck('name', 'id'),
        ]);
    }

    public function closePeriod(Request $request, string $month): RedirectResponse
    {
        $p = $this->period($month);
        $p->forceFill(['status' => 'CLOSED', 'closed_by' => $request->user()->id, 'closed_at' => now()])->save();

        return back()->with('ok', __('أُقفل شهر :m؛ لا يُرحَّل فيه شيء بعد الآن.', ['m' => $month]));
    }

    public function reopenPeriod(Request $request, string $month): RedirectResponse
    {
        $data = $request->validate(['reopen_reason' => ['required', 'string', 'max:500']]);
        $p = $this->period($month);
        if ($p->status !== 'CLOSED') {
            throw ValidationException::withMessages(['rule' => __('الشهر مفتوح بالفعل.')]);
        }
        $p->forceFill(['status' => 'OPEN', 'reopen_reason' => $data['reopen_reason']])->save();

        return back()->with('ok', __('أُعيد فتح شهر :m. السبب محفوظ في سجل النشاط.', ['m' => $month]));
    }

    private function period(string $month): FiscalPeriod
    {
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);
        $start = "$month-01";
        $books = $this->booksStart();
        if (! $books || $start < substr($books, 0, 7).'-01') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_BOOKS_NOT_STARTED')]);
        }

        return FiscalPeriod::firstOrCreate(['period_start' => $start]);
    }

    private function booksStart(): ?string
    {
        return DB::table('accounting_settings')->value('books_start');
    }
}
