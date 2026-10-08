<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/** Pivot reports for every module that had none (clients, manufacturing, quality, stock, treasury, ledger, HR). */
class ModuleReports
{
    /** @return list<DefinedReport> */
    public static function all(): array
    {
        $month = fn (string $col) => ['label' => __('الشهر'), 'expr' => "to_char($col, 'YYYY-MM')"];
        $count = fn (string $label = 'العدد') => ['label' => __($label), 'agg' => 'count(*)', 'format' => 'int', 'additive' => true];
        $status = fn (string $col, string $label = 'الحالة') => ['label' => __($label), 'expr' => $col, 'labeler' => fn ($k) => __("rroka.status.$k")];
        $production = ['production.manage', 'production.log_time', 'quality.inspect', 'projects.view'];
        $tz = "AT TIME ZONE 'Asia/Riyadh'";

        return array_map(fn ($d) => new DefinedReport($d), [
            [
                'key' => 'clients', 'title' => __('تحليل العملاء'),
                'description' => __('العملاء حسب النوع والمدينة وتاريخ الإضافة، وعدد عروضهم وقيمة المعتمد منها.'),
                'permissions' => ['clients.view'],
                'base' => "SELECT c.id, (c.created_at $tz)::date AS created_on, c.client_type, COALESCE(NULLIF(btrim(c.city), ''), '—') AS city, c.business_name,
                                  (SELECT count(*) FROM quotations q WHERE q.client_id = c.id) AS q_count,
                                  (SELECT COALESCE(sum(t.net_before_vat), 0) FROM quotations q JOIN v_quotation_totals t ON t.quotation_id = q.id
                                    WHERE q.client_id = c.id AND q.status = 'APPROVED') AS approved_value
                             FROM clients c",
                'date' => ['r.created_on', __('تاريخ إضافة العميل')],
                'dimensions' => [
                    'type' => ['label' => __('النوع'), 'expr' => 'r.client_type', 'labeler' => fn ($k) => __("rroka.client_type.$k")],
                    'city' => ['label' => __('المدينة'), 'expr' => 'r.city'],
                    'month' => $month('r.created_on'),
                    'client' => ['label' => __('العميل'), 'expr' => 'r.business_name'],
                ],
                'measures' => [
                    'count' => $count('عدد العملاء'),
                    'quotations' => ['label' => __('عدد العروض'), 'agg' => 'sum(r.q_count)', 'format' => 'int', 'additive' => true],
                    'approved' => ['label' => __('قيمة العروض المعتمدة قبل الضريبة'), 'agg' => 'sum(r.approved_value)', 'format' => 'money', 'additive' => true],
                ],
            ],
            [
                'key' => 'production', 'title' => __('تحليل أوامر التصنيع'),
                'description' => __('أوامر التصنيع حسب الحالة والمشروع والشهر، والمتأخر منها عن موعده ومتوسط مدة التنفيذ.'),
                'permissions' => $production,
                'base' => "SELECT o.id, o.status, o.planned_start, p.project_no,
                                  (o.status IN ('PLANNED', 'IN_PROGRESS') AND o.planned_end < current_date) AS late,
                                  CASE WHEN o.completed_at IS NOT NULL AND o.started_at IS NOT NULL
                                       THEN extract(epoch FROM o.completed_at - o.started_at) / 86400 END AS days
                             FROM production_orders o JOIN projects p ON p.id = o.project_id",
                'date' => ['r.planned_start', __('تاريخ البدء المخطط')],
                'dimensions' => [
                    'status' => $status('r.status'),
                    'project' => ['label' => __('المشروع'), 'expr' => 'r.project_no'],
                    'month' => $month('r.planned_start'),
                    'late' => ['label' => __('الالتزام بالموعد'), 'expr' => "CASE WHEN r.late THEN 'LATE' ELSE 'ON_TIME' END",
                        'labeler' => fn ($k) => $k === 'LATE' ? __('متأخر') : __('في الموعد أو منتهٍ')],
                ],
                'measures' => [
                    'count' => $count('عدد الأوامر'),
                    'late' => ['label' => __('المتأخر'), 'agg' => 'sum(CASE WHEN r.late THEN 1 ELSE 0 END)', 'format' => 'int', 'additive' => true],
                    'days' => ['label' => __('متوسط مدة التنفيذ (أيام)'), 'agg' => 'avg(r.days)', 'format' => 'money', 'additive' => false],
                ],
            ],
            [
                'key' => 'labor', 'title' => __('ساعات العمل على الإنتاج'),
                'description' => __('ساعات العمال المسجلة على أوامر التصنيع حسب العامل والقسم والمشروع والشهر.'),
                'permissions' => $production,
                'base' => "SELECT l.id, l.work_date, l.hours, w.name AS worker, COALESCE(d.name, '—') AS department, o.order_no, p.project_no
                             FROM labor_logs l JOIN workers w ON w.id = l.worker_id LEFT JOIN departments d ON d.id = w.department_id
                             JOIN production_orders o ON o.id = l.production_order_id JOIN projects p ON p.id = o.project_id",
                'date' => ['r.work_date', __('تاريخ العمل')],
                'dimensions' => [
                    'worker' => ['label' => __('العامل'), 'expr' => 'r.worker'],
                    'project' => ['label' => __('المشروع'), 'expr' => 'r.project_no'],
                    'order' => ['label' => __('أمر التصنيع'), 'expr' => 'r.order_no'],
                    'department' => ['label' => __('القسم'), 'expr' => 'r.department'],
                    'month' => $month('r.work_date'),
                ],
                'measures' => [
                    'hours' => ['label' => __('الساعات'), 'agg' => 'sum(r.hours)', 'format' => 'money', 'additive' => true],
                    'count' => $count('عدد التسجيلات'),
                ],
            ],
            [
                'key' => 'machines', 'title' => __('ساعات تشغيل الآلات'),
                'description' => __('ساعات الآلات المسجلة على أوامر التصنيع حسب الآلة والمشروع والشهر.'),
                'permissions' => $production,
                'base' => "SELECT l.id, l.work_date, l.hours, m.code || ' ' || m.name AS machine, o.order_no, p.project_no
                             FROM machine_logs l JOIN machines m ON m.id = l.machine_id
                             JOIN production_orders o ON o.id = l.production_order_id JOIN projects p ON p.id = o.project_id",
                'date' => ['r.work_date', __('تاريخ التشغيل')],
                'dimensions' => [
                    'machine' => ['label' => __('الآلة'), 'expr' => 'r.machine'],
                    'project' => ['label' => __('المشروع'), 'expr' => 'r.project_no'],
                    'order' => ['label' => __('أمر التصنيع'), 'expr' => 'r.order_no'],
                    'month' => $month('r.work_date'),
                ],
                'measures' => [
                    'hours' => ['label' => __('الساعات'), 'agg' => 'sum(r.hours)', 'format' => 'money', 'additive' => true],
                    'count' => $count('عدد التسجيلات'),
                ],
            ],
            [
                'key' => 'quality', 'title' => __('تحليل الجودة'),
                'description' => __('فحوصات الجودة حسب المرحلة والنتيجة والمشروع والشهر، ونسبة النجاح من أول مرة.'),
                'permissions' => $production,
                'base' => "SELECT i.id, (i.inspected_at $tz)::date AS inspected_on, i.stage, i.result, COALESCE(p.project_no, '—') AS project_no
                             FROM quality_inspections i LEFT JOIN production_orders o ON o.id = i.production_order_id
                             LEFT JOIN projects p ON p.id = o.project_id",
                'date' => ['r.inspected_on', __('تاريخ الفحص')],
                'dimensions' => [
                    'result' => ['label' => __('النتيجة'), 'expr' => 'r.result', 'labeler' => fn ($k) => __("rroka.qc_result.$k")],
                    'stage' => ['label' => __('المرحلة'), 'expr' => 'r.stage', 'labeler' => fn ($k) => __("rroka.qc_stage.$k")],
                    'project' => ['label' => __('المشروع'), 'expr' => 'r.project_no'],
                    'month' => $month('r.inspected_on'),
                ],
                'measures' => [
                    'count' => $count('عدد الفحوصات'),
                    'issues' => ['label' => __('فاشل أو يحتاج إعادة عمل'), 'agg' => "sum(CASE WHEN r.result <> 'PASS' THEN 1 ELSE 0 END)", 'format' => 'int', 'additive' => true],
                    'pass_rate' => ['label' => __('نسبة النجاح'), 'agg' => "avg(CASE WHEN r.result = 'PASS' THEN 100.0 ELSE 0 END)", 'format' => 'pct', 'additive' => false],
                ],
            ],
            [
                'key' => 'stock', 'title' => __('أرصدة المخزون وقيمته'),
                'description' => __('رصيد كل خامة ومحجوزها وقيمتها بمتوسط التكلفة، حسب الفئة.'),
                'permissions' => ['inventory.view', 'inventory.move'],
                'base' => "SELECT m.id, (m.created_at $tz)::date AS created_on, m.code || ' ' || m.name AS material, COALESCE(m.category, '—') AS category,
                                  COALESCE(b.qty_on_hand, 0) AS qty, COALESCE(b.qty_on_hand - b.qty_reserved, 0) AS available,
                                  round(COALESCE(b.qty_on_hand, 0) * b.avg_unit_cost, 2) AS value, m.is_active
                             FROM raw_materials m LEFT JOIN stock_balances b ON b.material_id = m.id",
                'date' => ['r.created_on', __('تاريخ إضافة الخامة')],
                'dimensions' => [
                    'category' => ['label' => __('الفئة'), 'expr' => 'r.category'],
                    'material' => ['label' => __('الخامة'), 'expr' => 'r.material'],
                ],
                'measures' => [
                    'value' => ['label' => __('القيمة بمتوسط التكلفة'), 'agg' => 'sum(r.value)', 'format' => 'money', 'additive' => true],
                    'count' => $count('عدد الخامات'),
                    'qty' => ['label' => __('الكمية المتاحة (لكل خامة بوحدتها)'), 'agg' => 'sum(r.available)', 'format' => 'money', 'additive' => false],
                ],
                'filters' => [
                    'active' => ['label' => __('الخامات النشطة'), 'group' => 'a', 'sql' => 'r.is_active'],
                    'in_stock' => ['label' => __('لها رصيد'), 'group' => 'b', 'sql' => 'r.qty > 0'],
                ],
                'note' => function () {
                    $n = DB::table('stock_balances')->where('qty_on_hand', '>', 0)->whereNull('avg_unit_cost')->count();

                    return $n ? __(':n خامة لها رصيد بلا تكلفة، فلا تدخل القيمة (لا تُقدَّر).', ['n' => $n]) : null;
                },
            ],
            [
                'key' => 'movements', 'title' => __('حركات المخزون'),
                'description' => __('كل حركات المخزون حسب النوع والخامة والمشروع والشهر، بالقيمة.'),
                'permissions' => ['inventory.view', 'inventory.move'],
                'base' => "SELECT sm.id, (sm.moved_at $tz)::date AS moved_on, sm.movement_type, m.code || ' ' || m.name AS material,
                                  COALESCE(m.category, '—') AS category, COALESCE(p.project_no, '—') AS project_no,
                                  round(sm.quantity * sm.unit_cost, 2) AS value
                             FROM stock_movements sm JOIN raw_materials m ON m.id = sm.material_id LEFT JOIN projects p ON p.id = sm.project_id",
                'date' => ['r.moved_on', __('تاريخ الحركة')],
                'dimensions' => [
                    'type' => ['label' => __('نوع الحركة'), 'expr' => 'r.movement_type', 'labeler' => fn ($k) => __("rroka.movement_type.$k")],
                    'material' => ['label' => __('الخامة'), 'expr' => 'r.material'],
                    'category' => ['label' => __('الفئة'), 'expr' => 'r.category'],
                    'project' => ['label' => __('المشروع'), 'expr' => 'r.project_no'],
                    'month' => $month('r.moved_on'),
                ],
                'measures' => [
                    'value' => ['label' => __('القيمة'), 'agg' => 'sum(r.value)', 'format' => 'money', 'additive' => true],
                    'count' => $count('عدد الحركات'),
                ],
            ],
            [
                'key' => 'treasury', 'title' => __('حركة الخزائن والعهد'),
                'description' => __('المقبوض والمدفوع المعتمد لكل صندوق وبنك وعهدة حسب النوع والشهر.'),
                'permissions' => ['treasury.view', 'treasury.manage'],
                'base' => "SELECT l.line_date, l.source, l.amount_in, l.amount_out, a.name AS account, a.kind
                             FROM v_payment_account_lines l JOIN payment_accounts a ON a.id = l.account_id WHERE l.status = 'APPROVED'",
                'date' => ['r.line_date', __('تاريخ الحركة')],
                'dimensions' => [
                    'account' => ['label' => __('الحساب'), 'expr' => 'r.account'],
                    'kind' => ['label' => __('النوع'), 'expr' => 'r.kind', 'labeler' => fn ($k) => __("rroka.account_kind.$k")],
                    'source' => ['label' => __('الحركة'), 'expr' => 'r.source',
                        'labeler' => fn ($k) => ['EXPENSE' => __('مصروف'), 'TRANSFER_IN' => __('تحويل وارد'), 'TRANSFER_OUT' => __('تحويل صادر')][$k] ?? $k],
                    'month' => $month('r.line_date'),
                ],
                'measures' => [
                    'out' => ['label' => __('المدفوع'), 'agg' => 'sum(r.amount_out)', 'format' => 'money', 'additive' => true],
                    'in' => ['label' => __('المقبوض'), 'agg' => 'sum(r.amount_in)', 'format' => 'money', 'additive' => true],
                    'net' => ['label' => __('الصافي'), 'agg' => 'sum(r.amount_in - r.amount_out)', 'format' => 'money', 'additive' => true],
                    'count' => $count('عدد الحركات'),
                ],
            ],
            [
                'key' => 'ledger', 'title' => __('تحليل القيود'),
                'description' => __('القيود المرحَّلة حسب الحساب ونوعه ومصدر القيد والمشروع والشهر: مدين ودائن وصافٍ.'),
                'permissions' => ['accounting.view', 'accounting.manage', 'accounting.post', 'accounting.close'],
                'base' => "SELECT l.entry_id, l.entry_date, l.account_code || ' ' || ".(app()->getLocale() === 'en' ? 'COALESCE(a.name_en, a.name)' : 'a.name')." AS account,
                                  l.account_type, l.source_type, COALESCE(p.project_no, '—') AS project_no, l.debit, l.credit, l.net
                             FROM v_ledger_lines l JOIN accounts a ON a.id = l.account_id LEFT JOIN projects p ON p.id = l.project_id",
                'date' => ['r.entry_date', __('تاريخ القيد')],
                'dimensions' => [
                    'account' => ['label' => __('الحساب'), 'expr' => 'r.account'],
                    'type' => ['label' => __('التصنيف الرئيسي'), 'expr' => 'r.account_type', 'labeler' => fn ($k) => __("rroka.account_type.$k")],
                    'source' => ['label' => __('مصدر القيد'), 'expr' => 'r.source_type', 'labeler' => fn ($k) => __("rroka.journal_source.$k")],
                    'project' => ['label' => __('المشروع'), 'expr' => 'r.project_no'],
                    'month' => $month('r.entry_date'),
                ],
                'measures' => [
                    'debit' => ['label' => __('مدين'), 'agg' => 'sum(r.debit)', 'format' => 'money', 'additive' => true],
                    'credit' => ['label' => __('دائن'), 'agg' => 'sum(r.credit)', 'format' => 'money', 'additive' => true],
                    'net' => ['label' => __('الصافي (مدين − دائن)'), 'agg' => 'sum(r.net)', 'format' => 'money', 'additive' => true],
                    'entries' => ['label' => __('عدد القيود'), 'agg' => 'count(DISTINCT r.entry_id)', 'format' => 'int', 'additive' => false],
                ],
            ],
            [
                'key' => 'employees', 'title' => __('تحليل الموظفين'),
                'description' => __('عدد الموظفين حسب القسم والمسمى ونوع التوظيف والعمالة المباشرة وسنة التعيين.'),
                'permissions' => ['hr.view', 'hr.manage'],
                'base' => "SELECT w.id, w.hire_date, COALESCE(d.name, '—') AS department, COALESCE(j.name, '—') AS job, w.employment_type, w.is_direct_labor,
                                  CASE WHEN w.is_active AND w.termination_date IS NULL THEN 'ACTIVE' ELSE 'ENDED' END AS state
                             FROM workers w LEFT JOIN departments d ON d.id = w.department_id LEFT JOIN job_positions j ON j.id = w.job_id",
                'date' => ['r.hire_date', __('تاريخ التعيين')],
                'dimensions' => [
                    'department' => ['label' => __('القسم'), 'expr' => 'r.department'],
                    'job' => ['label' => __('المسمى الوظيفي'), 'expr' => 'r.job'],
                    'type' => ['label' => __('نوع التوظيف'), 'expr' => "COALESCE(r.employment_type, '—')", 'labeler' => fn ($k) => $k === '—' ? '—' : __("rroka.employment_type.$k")],
                    'direct' => ['label' => __('نوع العمالة'), 'expr' => "CASE WHEN r.is_direct_labor THEN 'D' ELSE 'I' END",
                        'labeler' => fn ($k) => $k === 'D' ? __('عمالة مباشرة (إنتاج)') : __('إداريون ومساندون')],
                    'state' => ['label' => __('الحالة'), 'expr' => 'r.state', 'labeler' => fn ($k) => $k === 'ACTIVE' ? __('على رأس العمل') : __('انتهت خدمتهم')],
                    'year' => ['label' => __('سنة التعيين'), 'expr' => "COALESCE(to_char(r.hire_date, 'YYYY'), '—')"],
                ],
                'measures' => ['count' => $count('عدد الموظفين')],
                'filters' => ['active' => ['label' => __('على رأس العمل'), 'group' => 's', 'sql' => "r.state = 'ACTIVE'"]],
            ],
            [
                'key' => 'attendance', 'title' => __('تحليل الحضور'),
                'description' => __('سجلات الحضور وساعات العمل الفعلية حسب الموظف والقسم والشهر.'),
                'permissions' => ['hr.attendance'],
                'base' => "SELECT a.id, (a.check_in $tz)::date AS day, a.worked_hours, w.name AS employee, COALESCE(d.name, '—') AS department
                             FROM attendances a JOIN workers w ON w.id = a.employee_id LEFT JOIN departments d ON d.id = w.department_id",
                'date' => ['r.day', __('تاريخ الحضور')],
                'dimensions' => [
                    'employee' => ['label' => __('الموظف'), 'expr' => 'r.employee'],
                    'department' => ['label' => __('القسم'), 'expr' => 'r.department'],
                    'month' => $month('r.day'),
                ],
                'measures' => [
                    'hours' => ['label' => __('ساعات العمل'), 'agg' => 'sum(r.worked_hours)', 'format' => 'money', 'additive' => true],
                    'days' => ['label' => __('أيام الحضور'), 'agg' => 'count(DISTINCT (r.employee, r.day))', 'format' => 'int', 'additive' => false],
                    'open' => ['label' => __('بلا تسجيل خروج'), 'agg' => 'sum(CASE WHEN r.worked_hours IS NULL THEN 1 ELSE 0 END)', 'format' => 'int', 'additive' => true],
                ],
            ],
            [
                'key' => 'leaves', 'title' => __('تحليل الإجازات'),
                'description' => __('أيام الإجازات وطلباتها حسب النوع والحالة والموظف والشهر.'),
                'permissions' => ['hr.leave_approve', 'hr.manage'],
                'base' => 'SELECT r.id, r.date_from, r.days, r.status, t.name AS leave_type, w.name AS employee
                             FROM leave_requests r JOIN leave_types t ON t.id = r.leave_type_id JOIN workers w ON w.id = r.employee_id',
                'date' => ['r.date_from', __('تاريخ بداية الإجازة')],
                'dimensions' => [
                    'type' => ['label' => __('نوع الإجازة'), 'expr' => 'r.leave_type'],
                    'status' => $status('r.status'),
                    'employee' => ['label' => __('الموظف'), 'expr' => 'r.employee'],
                    'month' => $month('r.date_from'),
                ],
                'measures' => [
                    'days' => ['label' => __('الأيام'), 'agg' => 'sum(r.days)', 'format' => 'money', 'additive' => true],
                    'count' => $count('عدد الطلبات'),
                ],
                'filters' => ['approved' => ['label' => __('المعتمدة'), 'group' => 's', 'sql' => "r.status = 'APPROVED'"]],
            ],
        ]);
    }
}
