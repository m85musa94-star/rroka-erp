<?php

namespace App\Reports;

class QuotationsReport extends Report
{
    public function key(): string
    {
        return 'quotations';
    }

    public function title(): string
    {
        return __('تحليل عروض الأسعار');
    }

    public function description(): string
    {
        return __('قيمة العروض وعددها ومعدل التحويل، حسب الحالة والشهر والعميل.');
    }

    public function dateColumn(): string
    {
        return 'r.issue_date';
    }

    public function dateLabel(): string
    {
        return __('تاريخ إصدار العرض');
    }

    public function permissions(): array
    {
        return ['quotations.view'];
    }

    protected function base(): string
    {
        return 'SELECT q.id, q.status, q.issue_date, t.net_before_vat, c.business_name AS client_name,
                       c.client_type, c.city
                  FROM quotations q
                  JOIN v_quotation_totals t ON t.quotation_id = q.id
                  JOIN clients c ON c.id = q.client_id';
    }

    public function dimensions(): array
    {
        return [
            'status' => ['label' => __('الحالة'), 'expr' => 'r.status', 'labeler' => fn ($k) => __("rroka.status.$k")],
            'month' => ['label' => __('الشهر'), 'expr' => "to_char(r.issue_date, 'YYYY-MM')"],
            'client' => ['label' => __('العميل'), 'expr' => 'r.client_name'],
            'client_type' => ['label' => __('نوع العميل'), 'expr' => 'r.client_type', 'labeler' => fn ($k) => __("rroka.client_type.$k")],
            'city' => ['label' => __('المدينة'), 'expr' => 'r.city'],
        ];
    }

    public function measures(): array
    {
        return [
            'value' => ['label' => __('القيمة قبل الضريبة'), 'agg' => 'sum(r.net_before_vat)', 'format' => 'money', 'additive' => true],
            'count' => ['label' => __('عدد العروض'), 'agg' => 'count(*)', 'format' => 'int', 'additive' => true],
            'approved_value' => ['label' => __('قيمة المعتمد'), 'agg' => "coalesce(sum(r.net_before_vat) FILTER (WHERE r.status = 'APPROVED'), 0)", 'format' => 'money', 'additive' => true],
            'conversion' => [
                'label' => __('معدل التحويل (معتمد ÷ محسوم)'),
                'agg' => "round(100.0 * count(*) FILTER (WHERE r.status = 'APPROVED') / nullif(count(*) FILTER (WHERE r.status IN ('APPROVED', 'REJECTED', 'EXPIRED')), 0), 1)",
                'format' => 'pct', 'additive' => false,
            ],
            'avg_value' => ['label' => __('متوسط قيمة العرض'), 'agg' => 'round(avg(r.net_before_vat), 2)', 'format' => 'money', 'additive' => false],
        ];
    }

    public function defaultRow(): string
    {
        return 'status';
    }

    public function filters(): array
    {
        return [
            'no_cancelled' => ['label' => __('استبعاد الملغاة'), 'group' => 'cancel', 'sql' => "r.status <> 'CANCELLED'"],
            'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'sql' => "r.issue_date >= date_trunc('month', current_date)"],
            'this_year' => ['label' => __('هذه السنة'), 'group' => 'date', 'sql' => "r.issue_date >= date_trunc('year', current_date)"],
            'last_year' => ['label' => __('السنة الماضية'), 'group' => 'date', 'sql' => "r.issue_date >= date_trunc('year', current_date) - interval '1 year' AND r.issue_date < date_trunc('year', current_date)"],
        ];
    }

    public function note(array $activeFilters, ?string $from = null, ?string $to = null): ?string
    {
        return __('معدل التحويل = العروض المعتمدة ÷ العروض التي حُسمت (معتمدة أو مرفوضة أو منتهية)؛ العروض المفتوحة لا تدخل فيه.');
    }
}
