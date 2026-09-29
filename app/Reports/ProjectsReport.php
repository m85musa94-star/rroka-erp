<?php

namespace App\Reports;

class ProjectsReport extends Report
{
    public function key(): string
    {
        return 'projects';
    }

    public function title(): string
    {
        return __('تحليل المشاريع');
    }

    public function description(): string
    {
        return __('عدد المشاريع وقيمة عقودها والمتأخر منها، حسب المرحلة والشهر والعميل.');
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
        return ['projects.view'];
    }

    protected function base(): string
    {
        return "SELECT p.id, p.status, p.start_date, p.target_date, p.contract_value, c.business_name AS client_name,
                       c.city,
                       (p.status NOT IN ('COMPLETED', 'CANCELLED') AND p.target_date < current_date) AS is_late
                  FROM projects p
                  JOIN clients c ON c.id = p.client_id";
    }

    public function dimensions(): array
    {
        return [
            'status' => ['label' => __('المرحلة'), 'expr' => 'r.status', 'labeler' => fn ($k) => __("rroka.status.$k")],
            'month' => ['label' => __('شهر البدء'), 'expr' => "to_char(r.start_date, 'YYYY-MM')"],
            'client' => ['label' => __('العميل'), 'expr' => 'r.client_name'],
            'city' => ['label' => __('المدينة'), 'expr' => 'r.city'],
        ];
    }

    public function measures(): array
    {
        return [
            'value' => ['label' => __('قيمة العقود قبل الضريبة'), 'agg' => 'sum(r.contract_value)', 'format' => 'money', 'additive' => true],
            'count' => ['label' => __('عدد المشاريع'), 'agg' => 'count(*)', 'format' => 'int', 'additive' => true],
            'late' => ['label' => __('المتأخرة عن التسليم'), 'agg' => 'count(*) FILTER (WHERE r.is_late)', 'format' => 'int', 'additive' => true],
        ];
    }

    public function defaultRow(): string
    {
        return 'status';
    }

    public function filters(): array
    {
        return [
            'open' => ['label' => __('الجارية'), 'group' => 'status', 'sql' => "r.status IN ('ACTIVE', 'IN_PRODUCTION', 'INSTALLATION')"],
            'completed' => ['label' => __('المكتملة'), 'group' => 'status', 'sql' => "r.status = 'COMPLETED'"],
            'this_year' => ['label' => __('بدأت هذه السنة'), 'group' => 'date', 'sql' => "r.start_date >= date_trunc('year', current_date)"],
        ];
    }
}
