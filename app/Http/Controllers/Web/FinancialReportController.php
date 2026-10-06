<?php

namespace App\Http\Controllers\Web;

use App\Accounting\FinancialReports;
use App\Accounting\ReportOptions;
use App\Http\Controllers\Controller;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Odoo-style financial reports: options bar (period, comparison, zero lines, unfold), lines
 * with sections and totals, a balance check, and exports — print on the company letterhead
 * and a real Excel file.
 */
class FinancialReportController extends Controller
{
    public function index(): View
    {
        return view('accounting.reports.index', ['reports' => FinancialReports::all()]);
    }

    public function show(Request $request, string $key): View|Response
    {
        $def = FinancialReports::all()[$key] ?? abort(404);
        $o = new ReportOptions($request, $key === 'balance-sheet' ? 'this_year' : 'this_year');
        $r = FinancialReports::build($key, $o);
        $data = ['key' => $key, 'def' => $def, 'o' => $o, 'r' => $r, 'company' => __('إر روكا للأثاث')];

        return match (true) {
            $request->query('export') === 'xlsx' => $this->xlsx($key, $def, $o, $r),
            $request->boolean('print') => view('accounting.reports.print', $data),
            default => view('accounting.reports.show', $data),
        };
    }

    private function xlsx(string $key, array $def, ReportOptions $o, array $r): Response
    {
        $cols = $r['columns'];
        $rows = [
            [['v' => $def['title'], 's' => 'title']],
            [['v' => __('إر روكا للأثاث').' — '.($r['asOf'] ? __('في :d', ['d' => min($o->to, today()->toDateString())]) : __('من :from إلى :to', ['from' => $o->from, 'to' => $o->to])), 's' => 'muted']],
            [],
            array_merge([['v' => __('الرمز'), 's' => 'head'], ['v' => __('البيان'), 's' => 'head']], array_map(fn ($c) => ['v' => $c['label'], 's' => 'head'], $cols)),
        ];
        foreach ($r['lines'] as $l) {
            if ($l['kind'] === 'heading' && ! $l['values']) {
                $rows[] = [['v' => ''], ['v' => $l['label'], 's' => 'bold']];

                continue;
            }
            $strong = in_array($l['kind'], ['section', 'total', 'grand', 'heading'], true);
            $line = [['v' => $l['code'] ?? ''], ['v' => str_repeat('    ', (int) $l['level']).$l['label'], 's' => $strong ? 'bold' : 'text']];
            foreach ($cols as $c) {
                $v = $l['values'][$c['key']] ?? null;
                $line[] = match ($c['type']) {
                    'money' => ['v' => $v === null ? null : round((float) $v, 2), 's' => $strong ? 'money_bold' : 'money'],
                    'pct' => ['v' => $v === null ? null : round((float) $v, 1), 's' => 'pct'],
                    default => ['v' => $v],
                };
            }
            $rows[] = $line;
        }
        foreach ($r['warnings'] as $w) {
            $rows[] = [];
            $rows[] = [['v' => ''], ['v' => $w, 's' => 'muted']];
        }
        $widths = array_merge([12, 46], array_map(fn ($c) => $c['type'] === 'text' ? 16 : 18, $cols));
        $bytes = Xlsx::build($def['title'], $rows, $widths, app()->getLocale() === 'ar');

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$key.'-'.$o->to.'.xlsx"',
        ]);
    }
}
