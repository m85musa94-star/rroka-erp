<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Reports\Report;
use App\Reports\ReportRegistry;
use App\Support\ListView;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $reports = ReportRegistry::forUser($request->user());
        abort_if($reports === [], 403, __('ليست لديك صلاحية على أي تقرير.'));

        return view('reports.index', ['reports' => $reports]);
    }

    public function show(Request $request, string $key): View|StreamedResponse
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
