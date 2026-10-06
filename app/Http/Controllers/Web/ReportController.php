<?php

namespace App\Http\Controllers\Web;

use App\Accounting\FinancialReports;
use App\Http\Controllers\Controller;
use App\Reports\Report;
use App\Reports\ReportRegistry;
use App\Support\AppMenu;
use App\Support\ListView;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $reports = ReportRegistry::forUser($request->user());
        $financial = AppMenu::can($request->user(), ['accounting.view', 'accounting.manage', 'accounting.post', 'accounting.close']) ? FinancialReports::all() : [];
        abort_if($reports === [] && $financial === [], 403, __('ليست لديك صلاحية على أي تقرير.'));

        return view('reports.index', ['reports' => $reports, 'financial' => $financial]);
    }

    public function show(Request $request, string $key): View|StreamedResponse|Response
    {
        $report = ReportRegistry::all()[$key] ?? abort(404);
        abort_unless(ReportRegistry::allowed($report, $request->user()), 403, __('ليست لديك صلاحية لهذا التقرير.'));

        $dims = $report->dimensions();
        $measures = $report->measures();
        $row = isset($dims[$request->query('rows')]) ? $request->query('rows') : $report->defaultRow();
        $col = isset($dims[$request->query('cols')]) && $request->query('cols') !== $row ? $request->query('cols') : null;
        $measure = isset($measures[$request->query('m')]) ? $request->query('m') : $report->defaultMeasure();

        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $swapped = false;
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
            $swapped = true;
        }

        $lv = new ListView($request,
            filters: array_map(fn ($f) => $f + ['apply' => fn ($q) => $q], $report->filters()),
            views: ['pivot', 'graph'],
            keep: ['rows', 'cols', 'm', 'from', 'to'],
        );

        $pivot = $report->pivot($row, $col, $measure, $lv->active, $from, $to);
        $range = $this->rangeText($from, $to);

        if ($request->query('export') === 'csv') {
            return $this->csv($report, $pivot, $row, $col, $measure, $range);
        }
        if ($request->query('export') === 'xlsx') {
            return $this->xlsx($report, $pivot, $row, $col, $measure, $range);
        }
        if ($request->boolean('print')) {
            return view('reports.print', compact('report', 'pivot', 'row', 'col', 'measure', 'dims', 'measures', 'range'));
        }

        return view('reports.show', compact('report', 'lv', 'pivot', 'row', 'col', 'measure', 'dims', 'measures', 'from', 'to', 'range', 'swapped'));
    }

    /** A valid Y-m-d date or null (anything else is ignored). */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $d = \DateTime::createFromFormat('!Y-m-d', $value);

        return $d && $d->format('Y-m-d') === $value ? $value : null;
    }

    private function rangeText(?string $from, ?string $to): ?string
    {
        return match (true) {
            $from && $to => __('من :from إلى :to', ['from' => $from, 'to' => $to]),
            (bool) $from => __('من :from', ['from' => $from]),
            (bool) $to => __('حتى :to', ['to' => $to]),
            default => null,
        };
    }

    /** A real spreadsheet: numbers stay numbers, totals bold, right to left in Arabic. */
    private function xlsx(Report $report, array $p, string $row, ?string $col, string $measure, ?string $range): Response
    {
        $fmt = $report->measures()[$measure]['format'];
        $cell = fn ($v, bool $bold = false) => ['v' => $v === null ? null : ($fmt === 'int' ? (int) $v : round((float) $v, $fmt === 'pct' ? 1 : 2)),
            's' => $fmt === 'pct' ? 'pct' : ($bold ? 'money_bold' : 'money')];
        $rows = [
            [['v' => $report->title(), 's' => 'title']],
            [['v' => __('إر روكا للأثاث').' — '.$report->measures()[$measure]['label'].' — '.$report->dateLabel().': '.($range ?? __('كل الفترات')), 's' => 'muted']],
            [],
            array_merge([['v' => $report->dimensions()[$row]['label'].($col ? ' / '.$report->dimensions()[$col]['label'] : ''), 's' => 'head']],
                array_map(fn ($c) => ['v' => $c['label'], 's' => 'head'], $p['cols']), [['v' => __('الإجمالي'), 's' => 'head']]),
        ];
        foreach ($p['rows'] as $r) {
            $line = [['v' => $r['label']]];
            foreach ($p['cols'] as $c) {
                $line[] = $cell($p['cells'][$r['key']][$c['key']] ?? null);
            }
            $line[] = $cell($p['rowTotals'][$r['key']] ?? null, true);
            $rows[] = $line;
        }
        $total = [['v' => __('الإجمالي'), 's' => 'bold']];
        foreach ($p['cols'] as $c) {
            $total[] = $cell($p['colTotals'][$c['key']] ?? null, true);
        }
        $total[] = $cell($p['grand'], true);
        $rows[] = $total;
        $bytes = Xlsx::build($report->title(), $rows, array_merge([34], array_fill(0, count($p['cols']) + 1, 16)), app()->getLocale() === 'ar');

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$report->key().'-'.now()->format('Y-m-d').'.xlsx"',
        ]);
    }

    /** UTF-8 CSV with BOM so Excel opens Arabic correctly. */
    private function csv(Report $report, array $p, string $row, ?string $col, string $measure, ?string $range): StreamedResponse
    {
        $fmt = $report->measures()[$measure]['format'];
        $num = fn ($v) => $v === null ? '' : ($fmt === 'int' ? (string) (int) $v : number_format($v, $fmt === 'pct' ? 1 : 2, '.', ''));
        $name = $report->key().'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($report, $p, $row, $measure, $num, $range) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$report->title().' — '.$report->measures()[$measure]['label']]);
            fputcsv($out, [$report->dateLabel().': '.($range ?? __('كل الفترات'))]);
            $header = [$report->dimensions()[$row]['label']];
            foreach ($p['cols'] as $c) {
                $header[] = $c['label'];
            }
            $header[] = __('الإجمالي');
            fputcsv($out, $header);
            foreach ($p['rows'] as $r) {
                $line = [$r['label']];
                foreach ($p['cols'] as $c) {
                    $line[] = $num($p['cells'][$r['key']][$c['key']] ?? null);
                }
                $line[] = $num($p['rowTotals'][$r['key']] ?? null);
                fputcsv($out, $line);
            }
            $total = [__('الإجمالي')];
            foreach ($p['cols'] as $c) {
                $total[] = $num($p['colTotals'][$c['key']] ?? null);
            }
            $total[] = $num($p['grand']);
            fputcsv($out, $total);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
