<?php

namespace App\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Odoo-style report options read from the query string: the date filter (a preset or a
 * custom range), the comparison column, and the display toggles. The fiscal year is the
 * calendar year (books start 2026-01-01); every report states this under its title.
 */
class ReportOptions
{
    public const PRESETS = ['this_month', 'this_quarter', 'this_year', 'last_month', 'last_quarter', 'last_year', 'custom'];

    public const COMPARISONS = ['none', 'previous', 'last_year'];

    public string $preset;

    public string $from;

    public string $to;

    public string $comparison;

    public bool $showZero;

    public bool $unfoldAll;

    /** @var list<int> */
    public array $unfolded;

    public ?string $booksStart;

    public function __construct(Request $request, string $defaultPreset = 'this_year')
    {
        $this->booksStart = DB::table('accounting_settings')->value('books_start');
        $today = CarbonImmutable::today();
        $preset = (string) $request->query('date', ($request->query('from') || $request->query('to')) ? 'custom' : $defaultPreset);
        $this->preset = in_array($preset, self::PRESETS, true) ? $preset : $defaultPreset;

        if ($this->preset === 'custom') {
            $from = self::validDate($request->query('from')) ?? $this->booksStart ?? $today->startOfYear()->toDateString();
            $to = self::validDate($request->query('to')) ?? $today->toDateString();
            [$this->from, $this->to] = $from <= $to ? [$from, $to] : [$to, $from];
        } else {
            [$f, $t] = self::range($this->preset, $today);
            [$this->from, $this->to] = [$f->toDateString(), $t->toDateString()];
        }
        $cmp = (string) $request->query('cmp', 'none');
        $this->comparison = in_array($cmp, self::COMPARISONS, true) ? $cmp : 'none';
        $this->showZero = $request->boolean('zero');
        $this->unfoldAll = $request->query('unfold') === 'all';
        $this->unfolded = array_values(array_map('intval', array_filter((array) $request->query('acc', []), 'is_numeric')));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function range(string $preset, CarbonImmutable $today): array
    {
        return match ($preset) {
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'this_quarter' => [$today->startOfQuarter(), $today->endOfQuarter()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'last_quarter' => [$today->subQuarterNoOverflow()->startOfQuarter(), $today->subQuarterNoOverflow()->endOfQuarter()],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()],
            default => [$today->startOfYear(), $today->endOfYear()],
        };
    }

    /**
     * Columns of a period report: the selected period, then its comparison.
     *
     * @return list<array{key:string, from:string, to:string, label:string}>
     */
    public function columns(): array
    {
        $cols = [['key' => 'c0', 'from' => $this->from, 'to' => $this->to, 'label' => self::label($this->from, $this->to)]];
        if ($this->comparison !== 'none') {
            $f = CarbonImmutable::parse($this->from);
            $t = CarbonImmutable::parse($this->to);
            if ($this->comparison === 'last_year') {
                [$cf, $ct] = [$f->subYear(), $t->subYear()];
            } elseif ($f->day === 1 && $t->isSameDay($t->endOfMonth())) {
                // A run of whole months compares with the same number of months before it.
                $months = $f->diffInMonths($t->addDay());
                [$cf, $ct] = [$f->subMonthsNoOverflow((int) $months), $f->subDay()];
            } else {
                $days = $f->diffInDays($t) + 1;
                [$cf, $ct] = [$f->subDays((int) $days), $f->subDay()];
            }
            $cols[] = ['key' => 'c1', 'from' => $cf->toDateString(), 'to' => $ct->toDateString(), 'label' => self::label($cf->toDateString(), $ct->toDateString())];
        }

        return $cols;
    }

    public static function label(string $from, string $to): string
    {
        $f = CarbonImmutable::parse($from);
        $t = CarbonImmutable::parse($to);
        if ($f->day === 1 && $f->month === 1 && $t->month === 12 && $t->day === 31 && $f->year === $t->year) {
            return (string) $f->year;
        }
        if ($f->day === 1 && $t->isSameDay($t->endOfMonth()) && $f->isSameMonth($t)) {
            return $f->format('Y-m');
        }

        return $from.' → '.$to;
    }

    /** Query parameters that reproduce these options (with overrides; null removes). */
    public function query(array $changes = []): array
    {
        $q = array_filter([
            'date' => $this->preset,
            'from' => $this->preset === 'custom' ? $this->from : null,
            'to' => $this->preset === 'custom' ? $this->to : null,
            'cmp' => $this->comparison !== 'none' ? $this->comparison : null,
            'zero' => $this->showZero ? 1 : null,
            'unfold' => $this->unfoldAll ? 'all' : null,
            'acc' => $this->unfolded ?: null,
        ]);
        foreach ($changes as $k => $v) {
            if ($v === null) {
                unset($q[$k]);
            } else {
                $q[$k] = $v;
            }
        }

        return $q;
    }

    private static function validDate(mixed $v): ?string
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        $d = \DateTime::createFromFormat('!Y-m-d', $v);

        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }
}
