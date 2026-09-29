<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Project profitability. Zero Assumption Policy: profit and margin include only
 * projects whose cost is complete; the note states how many were excluded.
 */
class ProfitabilityReport extends Report
{
    public function key(): string
    {
        return 'profitability';
    }

    public function title(): string
    {
        return __('ربحية المشاريع');
    }

    public function description(): string
    {
        return __('التكلفة الفعلية ومجمل الربح والهامش — للمشاريع مكتملة التكلفة فقط.');
    }

    public function dateColumn(): string
    {
        return 'r.start_date';
    }

    public function dateLabel(): string
    {
        return __('تاريخ بدء المشروع');
    }

    public function permissions(): array
    {
        return ['costing.view'];
    }

    protected function base(): string
    {
        return "SELECT v.project_id, v.status, v.contract_value, v.total_cost, v.gross_profit,
                       v.material_cost, v.labor_cost, v.machine_cost, v.overhead_cost,
                       (v.total_cost IS NOT NULL) AS complete,
                       p.project_no || ' — ' || p.title AS project_name, p.start_date,
                       c.business_name AS client_name
                  FROM v_project_actual_cost v
                  JOIN projects p ON p.id = v.project_id
                  JOIN clients c ON c.id = p.client_id";
    }

    public function dimensions(): array
    {
        return [
            'project' => ['label' => __('المشروع'), 'expr' => 'r.project_name'],
            'client' => ['label' => __('العميل'), 'expr' => 'r.client_name'],
            'status' => ['label' => __('المرحلة'), 'expr' => 'r.status', 'labeler' => fn ($k) => __("rroka.status.$k")],
            'month' => ['label' => __('شهر البدء'), 'expr' => "to_char(r.start_date, 'YYYY-MM')"],
        ];
    }

    public function measures(): array
    {
        $done = 'FILTER (WHERE r.complete)';

        return [
            'profit' => ['label' => __('مجمل الربح'), 'agg' => "sum(r.gross_profit) {$done}", 'format' => 'money', 'additive' => true],
            'margin' => ['label' => __('هامش مجمل الربح'), 'agg' => "round(100.0 * sum(r.gross_profit) {$done} / nullif(sum(r.contract_value) {$done}, 0), 1)", 'format' => 'pct', 'additive' => false],
            'cost' => ['label' => __('إجمالي التكلفة الفعلية'), 'agg' => "sum(r.total_cost) {$done}", 'format' => 'money', 'additive' => true],
            'revenue' => ['label' => __('قيمة العقود (مكتملة التكلفة)'), 'agg' => "sum(r.contract_value) {$done}", 'format' => 'money', 'additive' => true],
            'materials' => ['label' => __('تكلفة الخامات'), 'agg' => "sum(r.material_cost) {$done}", 'format' => 'money', 'additive' => true],
            'labor' => ['label' => __('تكلفة العمالة'), 'agg' => "sum(r.labor_cost) {$done}", 'format' => 'money', 'additive' => true],
            'incomplete' => ['label' => __('مشاريع تكلفتها غير مكتملة'), 'agg' => 'count(*) FILTER (WHERE NOT r.complete)', 'format' => 'int', 'additive' => true],
        ];
    }

    public function defaultRow(): string
    {
        return 'project';
    }

    public function filters(): array
    {
        return [
            'completed' => ['label' => __('المشاريع المكتملة'), 'group' => 'status', 'sql' => "r.status = 'COMPLETED'"],
            'open' => ['label' => __('الجارية'), 'group' => 'status', 'sql' => "r.status IN ('ACTIVE', 'IN_PRODUCTION', 'INSTALLATION')"],
        ];
    }

    public function note(array $activeFilters, ?string $from = null, ?string $to = null): ?string
    {
        [$where, $bindings] = $this->where($activeFilters, $from, $to);
        $row = DB::selectOne("SELECT count(*) AS total, count(*) FILTER (WHERE r.complete) AS complete FROM ({$this->base()}) r {$where}", $bindings);
        if ((int) $row->total === 0) {
            return null;
        }
        $excluded = $row->total - $row->complete;

        return $excluded > 0
            ? __('الربح والهامش محسوبان على :complete من :total مشروعًا فقط؛ :excluded مشروعًا مستبعد لأن تكلفته غير مكتملة (معدلات ناقصة). لا يُقدَّر أي رقم.', ['complete' => $row->complete, 'total' => $row->total, 'excluded' => $excluded])
            : __('كل المشاريع مكتملة التكلفة.');
    }
}
