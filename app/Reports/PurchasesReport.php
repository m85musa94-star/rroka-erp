<?php

namespace App\Reports;

/** Approved supplier invoices, line by line (net of the invoice discount share). */
class PurchasesReport extends Report
{
    public function key(): string
    {
        return 'purchases';
    }

    public function title(): string
    {
        return __('تحليل المشتريات');
    }

    public function description(): string
    {
        return __('المشتريات المعتمدة حسب المورد والخامة والشهر، بالصافي بعد الخصم وقبل الضريبة.');
    }

    public function dateColumn(): string
    {
        return 'r.invoice_date';
    }

    public function dateLabel(): string
    {
        return __('تاريخ الفاتورة');
    }

    public function permissions(): array
    {
        return ['purchases.view', 'purchases.manage'];
    }

    protected function base(): string
    {
        // Net per line = received quantity × received unit cost (discount already spread by the posting trigger).
        return "SELECT p.id, p.invoice_date, s.name AS supplier, m.code || ' — ' || m.name AS material, m.category,
                       sm.quantity * sm.unit_cost AS net_value, p.id AS invoice_id
                  FROM purchase_invoices p
                  JOIN suppliers s ON s.id = p.supplier_id
                  JOIN purchase_invoice_lines l ON l.purchase_invoice_id = p.id
                  JOIN raw_materials m ON m.id = l.material_id
                  JOIN stock_movements sm ON sm.purchase_invoice_line_id = l.id
                 WHERE p.status = 'APPROVED'";
    }

    public function dimensions(): array
    {
        return [
            'supplier' => ['label' => __('المورد'), 'expr' => 'r.supplier'],
            'month' => ['label' => __('الشهر'), 'expr' => "to_char(r.invoice_date, 'YYYY-MM')"],
            'material' => ['label' => __('الخامة'), 'expr' => 'r.material'],
            'category' => ['label' => __('فئة الخامة'), 'expr' => 'r.category'],
        ];
    }

    public function measures(): array
    {
        return [
            'value' => ['label' => __('الصافي قبل الضريبة'), 'agg' => 'round(sum(r.net_value), 2)', 'format' => 'money', 'additive' => true],
            'invoices' => ['label' => __('عدد الفواتير'), 'agg' => 'count(DISTINCT r.invoice_id)', 'format' => 'int', 'additive' => false],
        ];
    }
}
