<?php

namespace App\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The financial statements, Odoo style: lines (sections, account lines, totals) over value
 * columns, built from posted lines only (v_ledger_lines). Untyped accounts are shown under
 * «غير مصنّف» with a warning — never guessed into a section.
 *
 * A line: ['id','level','kind' => section|account|total|grand|detail|initial, 'label', 'code'?,
 *          'account_id'?, 'parent'?, 'values' => [column key => float|string|null]].
 */
class FinancialReports
{
    /** @return array<string, array{title:string, description:string}> */
    public static function all(): array
    {
        return [
            'profit-loss' => ['title' => __('قائمة الدخل (الأرباح والخسائر)'), 'description' => __('الإيرادات والتكاليف والمصروفات وصافي الربح للفترة، مع المقارنة.')],
            'balance-sheet' => ['title' => __('الميزانية العمومية (المركز المالي)'), 'description' => __('الأصول والخصوم وحقوق الملكية في تاريخ محدد، مع فحص التوازن.')],
            'general-ledger' => ['title' => __('دفتر الأستاذ العام'), 'description' => __('حركة كل حساب في الفترة برصيد أول المدة والرصيد المتحرك.')],
            'trial-balance' => ['title' => __('ميزان المراجعة'), 'description' => __('أرصدة أول المدة وحركة الفترة وأرصدة آخر المدة لكل حساب.')],
        ];
    }

    /** @return array{columns: list<array>, lines: list<array>, warnings: list<string>, asOf: bool, checks: list<array>} */
    public static function build(string $key, ReportOptions $o): array
    {
        return match ($key) {
            'profit-loss' => self::profitLoss($o),
            'balance-sheet' => self::balanceSheet($o),
            'general-ledger' => self::generalLedger($o),
            'trial-balance' => self::trialBalance($o),
        };
    }

    // ------------------------------------------------------------------ data

    private static function accounts(): Collection
    {
        return DB::table('accounts')->where('is_postable', true)->orderBy('code')
            ->get(['id', 'code', 'name', 'name_en', 'account_type', 'detail_type'])
            ->map(function ($a) {
                $a->label = app()->getLocale() === 'en' && $a->name_en ? $a->name_en : $a->name;

                return $a;
            });
    }

    /** Debit/credit per account for a date range (from null = since the beginning). */
    private static function sums(?string $from, string $to): Collection
    {
        return DB::table('v_ledger_lines')
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from))
            ->where('entry_date', '<=', $to)
            ->groupBy('account_id')
            ->selectRaw('account_id, sum(debit) AS debit, sum(credit) AS credit')
            ->get()->keyBy('account_id');
    }

    private static function columnsFor(ReportOptions $o, bool $asOf): array
    {
        $cols = [];
        $today = today()->toDateString();
        foreach ($o->columns() as $c) {
            // A balance is taken "as of" a date that has happened: the end of this year means today.
            $to = $asOf ? min($c['to'], $today) : $c['to'];
            $cols[] = ['key' => $c['key'], 'label' => $asOf ? __('في :d', ['d' => $to]) : $c['label'], 'type' => 'money', 'from' => $c['from'], 'to' => $to];
        }
        if (count($cols) === 2) {
            $cols[] = ['key' => 'var', 'label' => '%', 'type' => 'pct'];
        }

        return $cols;
    }

    private static function withVariation(array $lines, array $cols): array
    {
        if (count($cols) < 3) {
            return $lines;
        }
        foreach ($lines as &$l) {
            $a = $l['values']['c0'] ?? null;
            $b = $l['values']['c1'] ?? null;
            $l['values']['var'] = $a !== null && $b !== null && abs((float) $b) > 0.004 ? ((float) $a - (float) $b) / abs((float) $b) * 100 : null;
        }

        return $lines;
    }

    // ------------------------------------------------------------------ profit & loss

    private static function profitLoss(ReportOptions $o): array
    {
        $cols = self::columnsFor($o, false);
        $money = array_values(array_filter($cols, fn ($c) => $c['type'] === 'money'));
        $accounts = self::accounts()->filter(fn ($a) => in_array($a->account_type, ['REVENUE', 'EXPENSE'], true));
        $sums = [];
        foreach ($money as $c) {
            $sums[$c['key']] = self::sums($c['from'], $c['to']);
        }
        // Income lines read credit − debit, cost and expense lines debit − credit.
        $value = fn ($a, $ck) => isset($sums[$ck][$a->id])
            ? ($a->account_type === 'REVENUE' ? 1 : -1) * ((float) $sums[$ck][$a->id]->credit - (float) $sums[$ck][$a->id]->debit)
            : 0.0;

        $sections = [
            ['income', __('الإيرادات'), fn ($a) => $a->detail_type === 'INCOME' || ($a->account_type === 'REVENUE' && $a->detail_type === null)],
            ['cor', __('تكلفة الإيرادات'), fn ($a) => $a->detail_type === 'COST_OF_REVENUE'],
            ['other_income', __('إيرادات أخرى'), fn ($a) => $a->detail_type === 'OTHER_INCOME'],
            ['expenses', __('المصروفات'), fn ($a) => $a->detail_type === 'EXPENSES' || ($a->account_type === 'EXPENSE' && $a->detail_type === null)],
            ['depreciation', __('الإهلاك'), fn ($a) => $a->detail_type === 'DEPRECIATION'],
        ];
        $totals = [];
        $blocks = [];
        foreach ($sections as [$id, $label, $match]) {
            $lines = [];
            $sum = array_fill_keys(array_column($money, 'key'), 0.0);
            foreach ($accounts->filter($match) as $a) {
                $vals = [];
                $any = false;
                foreach ($money as $c) {
                    // Already oriented: income credit − debit, costs debit − credit (both shown positive);
                    // the totals below subtract the cost sections.
                    $v = $value($a, $c['key']);
                    $vals[$c['key']] = $v;
                    $sum[$c['key']] += $v;
                    $any = $any || abs($v) > 0.004 || isset($sums[$c['key']][$a->id]);
                }
                if ($any || $o->showZero) {
                    $lines[] = ['id' => "a{$a->id}", 'level' => 1, 'kind' => 'account', 'label' => $a->label, 'code' => $a->code, 'account_id' => $a->id,
                        'parent' => $id, 'values' => $vals, 'untyped' => $a->detail_type === null];
                }
            }
            $totals[$id] = $sum;
            $blocks[$id] = array_merge([['id' => $id, 'level' => 0, 'kind' => 'section', 'label' => $label, 'values' => $sum]], $lines);
        }
        $calc = fn (callable $f) => array_combine(array_column($money, 'key'), array_map(fn ($c) => $f($c['key']), $money));
        $gross = $calc(fn ($k) => $totals['income'][$k] - $totals['cor'][$k]);
        $operating = $calc(fn ($k) => $gross[$k] + $totals['other_income'][$k] - $totals['expenses'][$k] - $totals['depreciation'][$k]);

        $lines = array_merge(
            $blocks['income'], $blocks['cor'],
            [['id' => 'gross', 'level' => 0, 'kind' => 'total', 'label' => __('مجمل الربح'), 'values' => $gross]],
            $blocks['other_income'], $blocks['expenses'], $blocks['depreciation'],
            [['id' => 'net', 'level' => 0, 'kind' => 'grand', 'label' => __('صافي الربح (الخسارة)'), 'values' => $operating]],
        );
        $warnings = [];
        if ($accounts->contains(fn ($a) => $a->detail_type === null)) {
            $warnings[] = __('توجد حسابات إيرادات أو مصروفات بلا نوع؛ أُدرجت تحت «الإيرادات» أو «المصروفات» بحسب تصنيفها الرئيسي. حدّد نوعها من دليل الحسابات.');
        }

        return ['columns' => $cols, 'lines' => self::withVariation($lines, $cols), 'warnings' => $warnings, 'asOf' => false, 'checks' => []];
    }

    // ------------------------------------------------------------------ balance sheet

    private static function balanceSheet(ReportOptions $o): array
    {
        $cols = self::columnsFor($o, true);
        $money = array_values(array_filter($cols, fn ($c) => $c['type'] === 'money'));
        $accounts = self::accounts();
        $bal = [];
        $pl = [];
        foreach ($money as $c) {
            $s = self::sums(null, $c['to']);
            $yearStart = CarbonImmutable::parse($c['to'])->startOfYear()->toDateString();
            $before = self::sums(null, CarbonImmutable::parse($yearStart)->subDay()->toDateString());
            $bal[$c['key']] = $s;
            $plOf = fn (Collection $sums) => $accounts->filter(fn ($a) => in_array($a->account_type, ['REVENUE', 'EXPENSE'], true))
                ->sum(fn ($a) => isset($sums[$a->id]) ? (float) $sums[$a->id]->credit - (float) $sums[$a->id]->debit : 0.0);
            $pl[$c['key']] = ['current' => $plOf($s) - $plOf($before), 'previous' => $plOf($before)];
        }
        $value = fn ($a, $ck) => isset($bal[$ck][$a->id])
            ? ($a->account_type === 'ASSET' ? 1 : -1) * ((float) $bal[$ck][$a->id]->debit - (float) $bal[$ck][$a->id]->credit)
            : 0.0;

        $keys = array_column($money, 'key');
        $zero = array_fill_keys($keys, 0.0);
        $lines = [];
        $group = function (string $id, string $label, int $level, callable $match, string $parent) use ($accounts, $money, $value, $o, $zero, &$lines) {
            $sum = $zero;
            $rows = [];
            foreach ($accounts->filter($match) as $a) {
                $vals = [];
                $any = false;
                foreach ($money as $c) {
                    $v = $value($a, $c['key']);
                    $vals[$c['key']] = $v;
                    $sum[$c['key']] += $v;
                    $any = $any || abs($v) > 0.004;
                }
                if ($any || $o->showZero) {
                    $rows[] = ['id' => "a{$a->id}", 'level' => $level + 1, 'kind' => 'account', 'label' => $a->label, 'code' => $a->code, 'account_id' => $a->id,
                        'parent' => $id, 'values' => $vals, 'untyped' => $a->detail_type === null];
                }
            }
            if ($rows || $o->showZero) {
                $lines[] = ['id' => $id, 'level' => $level, 'kind' => 'section', 'label' => $label, 'values' => $sum, 'parent' => $parent];
                array_push($lines, ...$rows);
            }

            return $sum;
        };
        $add = fn (array ...$sums) => array_combine($keys, array_map(fn ($k) => array_sum(array_column($sums, $k)), $keys));
        $is = fn (string ...$types) => fn ($a) => in_array($a->detail_type, $types, true);
        $untyped = fn (string $cls) => fn ($a) => $a->account_type === $cls && $a->detail_type === null;

        // Assets
        $lines[] = ['id' => 'assets', 'level' => 0, 'kind' => 'heading', 'label' => __('الأصول'), 'values' => []];
        $lines[] = ['id' => 'current_assets', 'level' => 1, 'kind' => 'heading', 'label' => __('الأصول المتداولة'), 'values' => []];
        $ca = $add(
            $group('bank_cash', __('البنك والنقدية'), 2, $is('BANK_CASH'), 'current_assets'),
            $group('receivable', __('المدينون'), 2, $is('RECEIVABLE'), 'current_assets'),
            $group('current', __('أصول متداولة أخرى'), 2, $is('CURRENT_ASSETS'), 'current_assets'),
            $group('prepayments', __('مصروفات مدفوعة مقدمًا'), 2, $is('PREPAYMENTS'), 'current_assets'),
        );
        $lines[] = ['id' => 'total_ca', 'level' => 1, 'kind' => 'total', 'label' => __('إجمالي الأصول المتداولة'), 'values' => $ca];
        $nca = $add(
            $group('fixed', __('أصول ثابتة'), 1, $is('FIXED_ASSETS'), 'assets'),
            $group('noncurrent', __('أصول غير متداولة'), 1, $is('NON_CURRENT_ASSETS'), 'assets'),
            $group('assets_untyped', __('غير مصنّف'), 1, $untyped('ASSET'), 'assets'),
        );
        $assets = $add($ca, $nca);
        $lines[] = ['id' => 'total_assets', 'level' => 0, 'kind' => 'grand', 'label' => __('إجمالي الأصول'), 'values' => $assets];

        // Liabilities
        $lines[] = ['id' => 'liabilities', 'level' => 0, 'kind' => 'heading', 'label' => __('الخصوم'), 'values' => []];
        $cl = $add(
            $group('payable', __('الدائنون'), 1, $is('PAYABLE'), 'liabilities'),
            $group('credit_card', __('بطاقات ائتمان'), 1, $is('CREDIT_CARD'), 'liabilities'),
            $group('current_liab', __('خصوم متداولة'), 1, $is('CURRENT_LIABILITIES'), 'liabilities'),
        );
        $lines[] = ['id' => 'total_cl', 'level' => 1, 'kind' => 'total', 'label' => __('إجمالي الخصوم المتداولة'), 'values' => $cl];
        $ncl = $add(
            $group('noncurrent_liab', __('خصوم غير متداولة'), 1, $is('NON_CURRENT_LIABILITIES'), 'liabilities'),
            $group('liab_untyped', __('غير مصنّف'), 1, $untyped('LIABILITY'), 'liabilities'),
        );
        $liabilities = $add($cl, $ncl);
        $lines[] = ['id' => 'total_liab', 'level' => 0, 'kind' => 'total', 'label' => __('إجمالي الخصوم'), 'values' => $liabilities];

        // Equity: posted equity accounts + the earnings not yet closed into them.
        $lines[] = ['id' => 'equity', 'level' => 0, 'kind' => 'heading', 'label' => __('حقوق الملكية'), 'values' => []];
        $eq = $add(
            $group('equity_accounts', __('رأس المال وجاري المالك والأرباح المبقاة'), 1, fn ($a) => $a->account_type === 'EQUITY', 'equity'),
        );
        $current = array_combine($keys, array_map(fn ($k) => $pl[$k]['current'], $keys));
        $previous = array_combine($keys, array_map(fn ($k) => $pl[$k]['previous'], $keys));
        $lines[] = ['id' => 'cye', 'level' => 1, 'kind' => 'section', 'label' => __('أرباح (خسائر) السنة الحالية'), 'values' => $current, 'computed' => true];
        $lines[] = ['id' => 'pye', 'level' => 1, 'kind' => 'section', 'label' => __('أرباح (خسائر) سنوات سابقة غير موزعة'), 'values' => $previous, 'computed' => true];
        $equity = $add($eq, $current, $previous);
        $lines[] = ['id' => 'total_equity', 'level' => 0, 'kind' => 'total', 'label' => __('إجمالي حقوق الملكية'), 'values' => $equity];
        $le = $add($liabilities, $equity);
        $lines[] = ['id' => 'total_le', 'level' => 0, 'kind' => 'grand', 'label' => __('إجمالي الخصوم وحقوق الملكية'), 'values' => $le];

        $checks = [];
        foreach ($money as $c) {
            $diff = round($assets[$c['key']] - $le[$c['key']], 2);
            $checks[] = ['label' => $c['label'], 'ok' => abs($diff) < 0.005, 'diff' => $diff];
        }
        $warnings = [];
        if ($accounts->contains(fn ($a) => in_array($a->account_type, ['ASSET', 'LIABILITY'], true) && $a->detail_type === null)) {
            $warnings[] = __('توجد حسابات أصول أو خصوم بلا نوع، فظهرت تحت «غير مصنّف». حدّد نوعها من دليل الحسابات لتظهر في مكانها الصحيح.');
        }

        return ['columns' => $cols, 'lines' => self::withVariation($lines, $cols), 'warnings' => $warnings, 'asOf' => true, 'checks' => $checks];
    }

    // ------------------------------------------------------------------ general ledger

    private static function generalLedger(ReportOptions $o): array
    {
        $cols = [
            ['key' => 'date', 'label' => __('التاريخ'), 'type' => 'text'],
            ['key' => 'ref', 'label' => __('القيد'), 'type' => 'text'],
            ['key' => 'project', 'label' => __('المشروع'), 'type' => 'text'],
            ['key' => 'debit', 'label' => __('مدين'), 'type' => 'money'],
            ['key' => 'credit', 'label' => __('دائن'), 'type' => 'money'],
            ['key' => 'balance', 'label' => __('الرصيد'), 'type' => 'money'],
        ];
        $accounts = self::accounts()->keyBy('id');
        $opening = self::sums(null, CarbonImmutable::parse($o->from)->subDay()->toDateString());
        $period = self::sums($o->from, $o->to);
        $lines = [];
        $td = $tc = 0.0;
        foreach ($accounts as $a) {
            $ob = isset($opening[$a->id]) ? (float) $opening[$a->id]->debit - (float) $opening[$a->id]->credit : 0.0;
            $pd = (float) ($period[$a->id]->debit ?? 0);
            $pc = (float) ($period[$a->id]->credit ?? 0);
            if (! isset($opening[$a->id]) && ! isset($period[$a->id]) && ! $o->showZero) {
                continue;
            }
            $td += $pd;
            $tc += $pc;
            $unfolded = $o->unfoldAll || in_array($a->id, $o->unfolded, true);
            $lines[] = ['id' => "a{$a->id}", 'level' => 0, 'kind' => 'account', 'label' => $a->label, 'code' => $a->code, 'account_id' => $a->id,
                'foldable' => true, 'unfolded' => $unfolded, 'values' => ['debit' => $pd, 'credit' => $pc, 'balance' => $ob + $pd - $pc]];
            if (! $unfolded) {
                continue;
            }
            $lines[] = ['id' => "i{$a->id}", 'level' => 1, 'kind' => 'initial', 'label' => __('رصيد أول المدة'), 'parent' => "a{$a->id}",
                'values' => ['date' => $o->from, 'debit' => null, 'credit' => null, 'balance' => $ob]];
            $running = $ob;
            $rows = DB::table('v_ledger_lines as l')->leftJoin('projects as p', 'p.id', '=', 'l.project_id')
                ->where('l.account_id', $a->id)->whereBetween('l.entry_date', [$o->from, $o->to])
                ->orderBy('l.entry_date')->orderBy('l.entry_no')->orderBy('l.line_id')->limit(1000)
                ->get(['l.entry_id', 'l.entry_date', 'l.entry_no', 'l.description', 'l.entry_description', 'l.debit', 'l.credit', 'p.project_no']);
            foreach ($rows as $r) {
                $running += (float) $r->debit - (float) $r->credit;
                $lines[] = ['id' => 'l'.$r->entry_no.$a->id, 'level' => 1, 'kind' => 'detail', 'label' => $r->description ?: $r->entry_description, 'parent' => "a{$a->id}",
                    'entry_id' => $r->entry_id, 'values' => ['date' => $r->entry_date, 'ref' => $r->entry_no, 'project' => $r->project_no,
                        'debit' => (float) $r->debit ?: null, 'credit' => (float) $r->credit ?: null, 'balance' => $running]];
            }
        }
        $lines[] = ['id' => 'total', 'level' => 0, 'kind' => 'grand', 'label' => __('الإجمالي'), 'values' => ['debit' => $td, 'credit' => $tc, 'balance' => $td - $tc]];

        return ['columns' => $cols, 'lines' => $lines, 'warnings' => [], 'asOf' => false, 'checks' => [['label' => __('الحركة'), 'ok' => abs($td - $tc) < 0.005, 'diff' => round($td - $tc, 2)]]];
    }

    // ------------------------------------------------------------------ trial balance

    private static function trialBalance(ReportOptions $o): array
    {
        $cols = [
            ['key' => 'od', 'label' => __('أول المدة — مدين'), 'type' => 'money', 'group' => __('رصيد أول المدة')],
            ['key' => 'oc', 'label' => __('أول المدة — دائن'), 'type' => 'money', 'group' => __('رصيد أول المدة')],
            ['key' => 'd', 'label' => __('الفترة — مدين'), 'type' => 'money', 'group' => __('حركة الفترة')],
            ['key' => 'c', 'label' => __('الفترة — دائن'), 'type' => 'money', 'group' => __('حركة الفترة')],
            ['key' => 'cd', 'label' => __('آخر المدة — مدين'), 'type' => 'money', 'group' => __('رصيد آخر المدة')],
            ['key' => 'cc', 'label' => __('آخر المدة — دائن'), 'type' => 'money', 'group' => __('رصيد آخر المدة')],
        ];
        $accounts = self::accounts();
        $opening = self::sums(null, CarbonImmutable::parse($o->from)->subDay()->toDateString());
        $period = self::sums($o->from, $o->to);
        $sum = array_fill_keys(array_column($cols, 'key'), 0.0);
        $byType = [];
        foreach ($accounts as $a) {
            if (! isset($opening[$a->id]) && ! isset($period[$a->id]) && ! $o->showZero) {
                continue;
            }
            $ob = isset($opening[$a->id]) ? (float) $opening[$a->id]->debit - (float) $opening[$a->id]->credit : 0.0;
            $d = (float) ($period[$a->id]->debit ?? 0);
            $c = (float) ($period[$a->id]->credit ?? 0);
            $cb = $ob + $d - $c;
            $v = ['od' => max($ob, 0), 'oc' => max(-$ob, 0), 'd' => $d, 'c' => $c, 'cd' => max($cb, 0), 'cc' => max(-$cb, 0)];
            foreach ($v as $k => $x) {
                $sum[$k] += $x;
            }
            $byType[$a->account_type][] = ['id' => "a{$a->id}", 'level' => 1, 'kind' => 'account', 'label' => $a->label, 'code' => $a->code, 'account_id' => $a->id,
                'parent' => $a->account_type, 'values' => $v, 'untyped' => $a->detail_type === null];
        }
        $lines = [];
        foreach (['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'] as $t) {
            if (empty($byType[$t])) {
                continue;
            }
            $sub = array_fill_keys(array_keys($sum), 0.0);
            foreach ($byType[$t] as $l) {
                foreach ($l['values'] as $k => $x) {
                    $sub[$k] += $x;
                }
            }
            $lines[] = ['id' => $t, 'level' => 0, 'kind' => 'section', 'label' => __("rroka.account_type.$t"), 'values' => $sub];
            array_push($lines, ...$byType[$t]);
        }
        $lines[] = ['id' => 'total', 'level' => 0, 'kind' => 'grand', 'label' => __('الإجمالي'), 'values' => $sum];
        $ok = abs($sum['d'] - $sum['c']) < 0.005 && abs($sum['cd'] - $sum['cc']) < 0.005 && abs($sum['od'] - $sum['oc']) < 0.005;

        return ['columns' => $cols, 'lines' => $lines, 'warnings' => [], 'asOf' => false,
            'checks' => [['label' => __('الميزان'), 'ok' => $ok, 'diff' => round($sum['cd'] - $sum['cc'], 2)]]];
    }
}
