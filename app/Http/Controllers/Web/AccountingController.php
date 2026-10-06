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

/** The monthly periods (closing rules are enforced by the database). The reports live in FinancialReportController. */
class AccountingController extends Controller
{
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
