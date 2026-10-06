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

/** Trial balance and the monthly periods (closing rules are enforced by the database). */
class AccountingController extends Controller
{
    /** Opening, movement and closing per postable account; the totals must balance. */
    public function trialBalance(Request $request): View
    {
        $start = $this->booksStart();
        $from = $request->date('from')?->toDateString() ?? $start ?? today()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? today()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $rows = DB::select(
            'SELECT a.id, a.code, a.name, a.name_en, a.account_type,
                    COALESCE(sum(l.net) FILTER (WHERE l.entry_date < ?), 0) AS opening,
                    COALESCE(sum(l.debit) FILTER (WHERE l.entry_date BETWEEN ? AND ?), 0) AS debit,
                    COALESCE(sum(l.credit) FILTER (WHERE l.entry_date BETWEEN ? AND ?), 0) AS credit
               FROM accounts a
               JOIN v_ledger_lines l ON l.account_id = a.id AND l.entry_date <= ?
           GROUP BY a.id ORDER BY a.code', [$from, $from, $to, $from, $to, $to]);
        $t = ['od' => 0.0, 'oc' => 0.0, 'd' => 0.0, 'c' => 0.0, 'cd' => 0.0, 'cc' => 0.0];
        foreach ($rows as $r) {
            $r->closing = (float) $r->opening + (float) $r->debit - (float) $r->credit;
            $r->label = app()->getLocale() === 'en' && $r->name_en ? $r->name_en : $r->name;
            $t['od'] += max((float) $r->opening, 0);
            $t['oc'] += max(-(float) $r->opening, 0);
            $t['d'] += (float) $r->debit;
            $t['c'] += (float) $r->credit;
            $t['cd'] += max($r->closing, 0);
            $t['cc'] += max(-$r->closing, 0);
        }

        return view('accounting.trial-balance', [
            'rows' => $rows, 't' => $t, 'from' => $from, 'to' => $to,
            'balanced' => abs($t['d'] - $t['c']) < 0.005 && abs($t['cd'] - $t['cc']) < 0.005,
            'drafts' => DB::table('journal_entries')->where('status', 'DRAFT')->whereBetween('entry_date', [$from, $to])->count(),
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
