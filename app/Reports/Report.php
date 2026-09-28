<?php

namespace App\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Odoo-style pivot report over a SQL base query.
 *
 * Totals come from GROUPING SETS so non-additive measures (percentages) are
 * computed correctly at every level instead of being summed.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** Any of these permissions opens the report. */
    abstract public function permissions(): array;

    /** SQL producing one row per fact; referenced as alias r. */
    abstract protected function base(): string;

    /** @return array<string, array{label:string, expr:string, labeler?:callable}> */
    abstract public function dimensions(): array;

    /** @return array<string, array{label:string, agg:string, format:string, additive:bool}> */
    abstract public function measures(): array;

    /** @return array<string, array{label:string, group:string, sql:string}> */
    public function filters(): array
    {
        return [];
    }

    public function defaultRow(): string
    {
        return array_key_first($this->dimensions());
    }

    public function defaultMeasure(): string
    {
        return array_key_first($this->measures());
    }

    /** Shown under the report: data-quality caveats (e.g. excluded rows). */
    public function note(array $activeFilters): ?string
    {
        return null;
    }

    /**
     * @return array{rows: list<array{key:string,label:string}>, cols: list<array{key:string,label:string}>,
     *               cells: array<string, array<string, float|null>>, rowTotals: array<string, float|null>,
     *               colTotals: array<string, float|null>, grand: float|null}
     */
    public function pivot(string $row, ?string $col, string $measure, array $activeFilters): array
    {
        $dims = $this->dimensions();
        $m = $this->measures()[$measure];
        $rowExpr = $dims[$row]['expr'];
        $colExpr = $col ? $dims[$col]['expr'] : "''";

        $where = $this->whereSql($activeFilters);
        $sql = "SELECT ({$rowExpr})::text AS rk, ({$colExpr})::text AS ck,
                       grouping(({$rowExpr})::text) AS gr, grouping(({$colExpr})::text) AS gc,
                       {$m['agg']} AS v
                  FROM ({$this->base()}) r
                 {$where}
              GROUP BY GROUPING SETS ((({$rowExpr})::text, ({$colExpr})::text), (({$rowExpr})::text), (({$colExpr})::text), ())";
        $data = collect(DB::select($sql));

        $label = fn (string $dim, ?string $k) => $this->label($dim, $k);
        $rows = $data->where('gr', 0)->where('gc', 1)
            ->map(fn ($r) => ['key' => (string) $r->rk, 'label' => $label($row, $r->rk), 'total' => $r->v]);
        $rows = $this->sortRows($rows, $row)->values()->all();
        $cols = $col
            ? $this->sortRows($data->where('gr', 1)->where('gc', 0)
                ->map(fn ($r) => ['key' => (string) $r->ck, 'label' => $label($col, $r->ck), 'total' => $r->v]), $col)
                ->map(fn ($c) => ['key' => $c['key'], 'label' => $c['label']])->values()->all()
            : [];

        $cells = [];
        foreach ($data->where('gr', 0)->where('gc', 0) as $r) {
            $cells[(string) $r->rk][(string) $r->ck] = $r->v === null ? null : (float) $r->v;
        }

        return [
            'rows' => $rows,
            'cols' => $cols,
            'cells' => $cells,
            'rowTotals' => collect($rows)->mapWithKeys(fn ($r) => [$r['key'] => $r['total'] === null ? null : (float) $r['total']])->all(),
            'colTotals' => $data->where('gr', 1)->where('gc', 0)->mapWithKeys(fn ($r) => [(string) $r->ck => $r->v === null ? null : (float) $r->v])->all(),
            'grand' => ($g = $data->first(fn ($r) => $r->gr == 1 && $r->gc == 1)) && $g->v !== null ? (float) $g->v : null,
        ];
    }

    /** Months and statuses keep their natural order; everything else by value, largest first. */
    protected function sortRows(Collection $rows, string $dim): Collection
    {
        if ($dim === 'month') {
            return $rows->sortBy('key');
        }
        if ($dim === 'status') {
            $order = array_flip(['DRAFT', 'SENT', 'APPROVED', 'REJECTED', 'EXPIRED', 'CANCELLED', 'ACTIVE', 'IN_PRODUCTION', 'INSTALLATION', 'COMPLETED', 'ON_HOLD']);

            return $rows->sortBy(fn ($r) => $order[$r['key']] ?? 99);
        }

        return $rows->sortByDesc(fn ($r) => (float) $r['total']);
    }

    public function label(string $dim, ?string $key): string
    {
        if ($key === null || $key === '') {
            return 'غير محدد';
        }
        $labeler = $this->dimensions()[$dim]['labeler'] ?? null;

        return $labeler ? $labeler($key) : $key;
    }

    private function whereSql(array $active): string
    {
        $byGroup = [];
        foreach ($active as $key) {
            $f = $this->filters()[$key] ?? null;
            if ($f) {
                $byGroup[$f['group']][] = '('.$f['sql'].')';
            }
        }
        if (! $byGroup) {
            return '';
        }

        return 'WHERE '.implode(' AND ', array_map(fn ($ors) => '('.implode(' OR ', $ors).')', $byGroup));
    }

    public static function format(?float $v, string $format): string
    {
        if ($v === null) {
            return '—';
        }

        return match ($format) {
            'int' => number_format($v, 0),
            'pct' => number_format($v, 1).'%',
            default => number_format($v, 2),
        };
    }
}
