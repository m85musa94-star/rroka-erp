<?php

namespace App\Reports;

/**
 * Materials issued to production (net of returns) at the cost that left the
 * store: the monthly figure Daftra needs for the inventory → production cost entry.
 */
class MaterialConsumptionReport extends Report
{
    public function key(): string
    {
        return 'consumption';
    }

    public function title(): string
    {
        return __('الخامات المصروفة للإنتاج');
    }

    public function description(): string
    {
        return __('قيمة الخامات المصروفة للمشاريع صافي المرتجع بمتوسط التكلفة، حسب الشهر والمشروع والخامة — أساس قيد نهاية الشهر في دفترة.');
    }

    public function dateColumn(): string
    {
        return 'r.moved_on';
    }

    public function dateLabel(): string
    {
        return __('تاريخ الحركة');
    }

    public function permissions(): array
    {
        return ['inventory.view', 'inventory.move', 'costing.view'];
    }

    protected function base(): string
    {
        return "SELECT sm.id, (sm.moved_at AT TIME ZONE 'Asia/Riyadh')::date AS moved_on, p.project_no,
                       m.code || ' — ' || m.name AS material,
                       CASE sm.movement_type WHEN 'ISSUE' THEN 1 ELSE -1 END * sm.quantity * sm.unit_cost AS value,
                       (sm.unit_cost IS NULL) AS no_cost
                  FROM stock_movements sm
                  JOIN projects p ON p.id = sm.project_id
                  JOIN raw_materials m ON m.id = sm.material_id
                 WHERE sm.movement_type IN ('ISSUE', 'RETURN')";
    }

    public function dimensions(): array
    {
        return [
            'month' => ['label' => __('الشهر'), 'expr' => "to_char(r.moved_on, 'YYYY-MM')"],
            'project' => ['label' => __('المشروع'), 'expr' => 'r.project_no'],
            'material' => ['label' => __('الخامة'), 'expr' => 'r.material'],
        ];
    }

    public function measures(): array
    {
        // Any movement without a cost makes the total unknown ("—"), never an understated number.
        return [
            'value' => ['label' => __('القيمة بمتوسط التكلفة'), 'agg' => 'CASE WHEN bool_or(r.no_cost) THEN NULL ELSE round(sum(r.value), 2) END', 'format' => 'money', 'additive' => true],
        ];
    }

    public function defaultRow(): string
    {
        return 'month';
    }
}
