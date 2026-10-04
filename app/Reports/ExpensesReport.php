<?php

namespace App\Reports;

/** Approved expenses: where the money went, and how much of it is workshop overhead. */
class ExpensesReport extends Report
{
    public function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return __('تحليل المصروفات');
    }

    public function description(): string
    {
        return __('المصروفات المعتمدة حسب التصنيف والمشروع والشهر؛ والمصروف غير المباشر الفعلي للمقارنة بنسبة التحميل.');
    }

    public function dateColumn(): string
    {
        return 'r.expense_date';
    }

    public function dateLabel(): string
    {
        return __('تاريخ المصروف');
    }

    public function permissions(): array
    {
        return ['expenses.view', 'expenses.manage'];
    }

    protected function base(): string
    {
        return "SELECT e.id, e.expense_date, e.amount, e.vat_amount, e.payment_method, c.name AS category,
                       c.is_overhead, p.project_no, (e.project_id IS NOT NULL) AS on_project, a.name AS account
                  FROM expenses e
                  JOIN expense_categories c ON c.id = e.category_id
                  LEFT JOIN projects p ON p.id = e.project_id
                  LEFT JOIN payment_accounts a ON a.id = e.payment_account_id
                 WHERE e.status = 'APPROVED'";
    }

    public function dimensions(): array
    {
        return [
            'category' => ['label' => __('التصنيف'), 'expr' => 'r.category'],
            'month' => ['label' => __('الشهر'), 'expr' => "to_char(r.expense_date, 'YYYY-MM')"],
            'project' => ['label' => __('المشروع'), 'expr' => 'r.project_no'],
            'kind' => ['label' => __('النوع'), 'expr' => "CASE WHEN r.on_project THEN 'PROJECT' WHEN r.is_overhead THEN 'OVERHEAD' ELSE 'OTHER' END",
                'labeler' => fn ($k) => __("rroka.expense_kind.$k")],
            'account' => ['label' => __('دُفع من'), 'expr' => "COALESCE(r.account, '—')"],
            'method' => ['label' => __('طريقة الدفع'), 'expr' => 'r.payment_method', 'labeler' => fn ($k) => __("rroka.payment_method.$k")],
        ];
    }

    public function measures(): array
    {
        return [
            'amount' => ['label' => __('المبلغ قبل الضريبة'), 'agg' => 'sum(r.amount)', 'format' => 'money', 'additive' => true],
            'vat' => ['label' => __('الضريبة كما في المستندات'), 'agg' => 'sum(r.vat_amount)', 'format' => 'money', 'additive' => true],
            'count' => ['label' => __('العدد'), 'agg' => 'count(*)', 'format' => 'int', 'additive' => true],
        ];
    }

    public function filters(): array
    {
        return [
            'overhead' => ['label' => __('غير مباشرة للورشة'), 'group' => 'kind', 'sql' => 'NOT r.on_project AND r.is_overhead'],
            'on_project' => ['label' => __('على المشاريع'), 'group' => 'kind', 'sql' => 'r.on_project'],
        ];
    }
}
