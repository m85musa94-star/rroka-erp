<?php

namespace App\Accounting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The VAT return of a period from the approved documents (v_vat_documents /
 * fn_vat_figures), the same period in the books, and the tax periods to file.
 * Box numbers of the official form are not reproduced (not verified against
 * ZATCA's current form): the categories are the ones the documents can prove.
 */
class VatReport
{
    public const BUCKETS = ['SALES_STD', 'SALES_ZERO', 'SALES_CREDIT', 'PURCH_DEDUCT', 'PURCH_VERIFY', 'PURCH_NOVAT'];

    /** @return array{buckets: array<string, object>, output: float, input: float, verify: float, net: float, flagged: Collection, books: array{output: float, input: float}} */
    public static function build(string $from, string $to): array
    {
        $rows = collect(DB::select('SELECT * FROM fn_vat_figures(?, ?)', [$from, $to]))->keyBy('bucket');
        $b = [];
        foreach (self::BUCKETS as $k) {
            $b[$k] = $rows[$k] ?? (object) ['bucket' => $k, 'amount' => 0, 'vat' => 0, 'docs' => 0, 'rate_flags' => 0];
        }
        $output = (float) $b['SALES_STD']->vat + (float) $b['SALES_ZERO']->vat + (float) $b['SALES_CREDIT']->vat;
        $input = (float) $b['PURCH_DEDUCT']->vat;

        return [
            'buckets' => $b,
            'output' => $output,
            'input' => $input,
            'verify' => (float) $b['PURCH_VERIFY']->vat,
            'net' => $output - $input,
            'flagged' => DB::table('v_vat_documents')->whereBetween('doc_date', [$from, $to])->where('rate_ok', false)->orderBy('doc_date')->get(),
            'books' => self::books($from, $to),
        ];
    }

    /** The documents of one bucket in the period. */
    public static function documents(string $from, string $to, string $bucket): Collection
    {
        return DB::table('v_vat_documents')->whereBetween('doc_date', [$from, $to])->where('bucket', $bucket)->orderBy('doc_date')->orderBy('doc_no')->get();
    }

    /** Output / input VAT posted in the period, before any return settlement. */
    public static function books(string $from, string $to): array
    {
        $sum = fn (string $role, string $expr) => (float) DB::table('v_ledger_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.system_role', $role)->whereBetween('l.entry_date', [$from, $to])
            ->whereNotIn('l.source_type', ['VAT_RETURN', 'VAT_PAYMENT'])->sum(DB::raw($expr));

        return ['output' => $sum('OUTPUT_VAT', 'l.credit - l.debit'), 'input' => $sum('INPUT_VAT', 'l.debit - l.credit')];
    }

    /**
     * Tax periods from the books start to today (monthly or quarterly), each with its return if any.
     *
     * @return list<array{from: string, to: string, label: string, return: ?object}>
     */
    public static function periods(?string $frequency): array
    {
        if (! $frequency) {
            return [];
        }
        $start = Carbon::parse(DB::table('accounting_settings')->value('books_start') ?? '2026-01-01')->startOfMonth();
        $returns = DB::table('vat_returns')->whereNot('status', 'CANCELLED')->orderByDesc('id')->get()->groupBy(fn ($r) => $r->period_start.'|'.$r->period_end);
        $step = $frequency === 'QUARTERLY' ? 3 : 1;
        $out = [];
        for ($p = $start->copy(); $p->lte(today()); $p->addMonthsNoOverflow($step)) {
            $from = $p->toDateString();
            $to = $p->copy()->addMonthsNoOverflow($step - 1)->endOfMonth()->toDateString();
            $out[] = ['from' => $from, 'to' => $to, 'label' => $step === 3 ? 'Q'.$p->quarter.' '.$p->year : $p->format('Y-m'),
                'return' => $returns[$from.'|'.$to][0] ?? null, 'ended' => $to < today()->toDateString()];
        }

        return array_reverse($out);
    }
}
