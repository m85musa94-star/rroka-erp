<?php

namespace App\Support;

/**
 * Proposed chart of accounts for a make-to-order furniture workshop. Created only on request
 * (an empty chart), then reviewed and edited by the owner: it is a structure, not data.
 * Bank accounts are added one per real account under 1102; nothing here assumes a bank.
 * Row: [code, Arabic name, English name, class, parent code, postable, system role, detailed type].
 */
class ChartTemplate
{
    public static function rows(): array
    {
        return [
            ['1', 'الأصول', 'Assets', 'ASSET', null, false, null, null],
            ['11', 'الأصول المتداولة', 'Current assets', 'ASSET', '1', false, null, null],
            ['1101', 'النقدية في الصندوق', 'Cash on hand', 'ASSET', '11', true, null, 'BANK_CASH'],
            ['1102', 'البنوك', 'Banks', 'ASSET', '11', false, null, null],
            ['1103', 'عهد الموظفين', 'Employee custody', 'ASSET', '11', true, null, 'CURRENT_ASSETS'],
            ['1110', 'العملاء (الذمم المدينة)', 'Accounts receivable', 'ASSET', '11', true, 'RECEIVABLE', 'RECEIVABLE'],
            ['1120', 'مخزون الخامات والمستلزمات', 'Raw materials and supplies', 'ASSET', '11', true, 'INVENTORY', 'CURRENT_ASSETS'],
            ['1130', 'أعمال تحت التنفيذ (تكلفة المشاريع الجارية)', 'Work in progress (open projects)', 'ASSET', '11', true, 'WIP', 'CURRENT_ASSETS'],
            ['1140', 'ضريبة القيمة المضافة — المدخلات', 'Input VAT', 'ASSET', '11', true, 'INPUT_VAT', 'CURRENT_ASSETS'],
            ['1150', 'مصروفات مدفوعة مقدمًا', 'Prepaid expenses', 'ASSET', '11', true, null, 'PREPAYMENTS'],
            ['1160', 'سلف الموظفين', 'Employee advances', 'ASSET', '11', true, null, 'CURRENT_ASSETS'],
            ['12', 'الأصول غير المتداولة', 'Non-current assets', 'ASSET', '1', false, null, null],
            ['1201', 'المكائن والمعدات', 'Machinery and equipment', 'ASSET', '12', true, 'FIXED_ASSETS', 'FIXED_ASSETS'],
            ['1202', 'الأثاث والتجهيزات المكتبية', 'Furniture and office equipment', 'ASSET', '12', true, null, 'FIXED_ASSETS'],
            ['1203', 'السيارات', 'Vehicles', 'ASSET', '12', true, null, 'FIXED_ASSETS'],
            ['1209', 'مجمع إهلاك الأصول الثابتة', 'Accumulated depreciation', 'ASSET', '12', true, 'ACCUMULATED_DEPRECIATION', 'FIXED_ASSETS'],
            ['2', 'الخصوم', 'Liabilities', 'LIABILITY', null, false, null, null],
            ['21', 'الخصوم المتداولة', 'Current liabilities', 'LIABILITY', '2', false, null, null],
            ['2101', 'الموردون (الذمم الدائنة)', 'Accounts payable', 'LIABILITY', '21', true, 'PAYABLE', 'PAYABLE'],
            ['2110', 'ضريبة القيمة المضافة — المخرجات', 'Output VAT', 'LIABILITY', '21', true, 'OUTPUT_VAT', 'CURRENT_LIABILITIES'],
            ['2111', 'ضريبة القيمة المضافة — صافي المستحق للهيئة', 'VAT payable to ZATCA', 'LIABILITY', '21', true, null, 'CURRENT_LIABILITIES'],
            ['2120', 'دفعات مقدمة من العملاء', 'Customer advances', 'LIABILITY', '21', true, 'CUSTOMER_ADVANCES', 'CURRENT_LIABILITIES'],
            ['2130', 'رواتب مستحقة', 'Accrued salaries', 'LIABILITY', '21', true, null, 'CURRENT_LIABILITIES'],
            ['2140', 'مصروفات مستحقة', 'Accrued expenses', 'LIABILITY', '21', true, null, 'CURRENT_LIABILITIES'],
            ['2150', 'مخصص الزكاة', 'Zakat provision', 'LIABILITY', '21', true, null, 'CURRENT_LIABILITIES'],
            ['22', 'الخصوم غير المتداولة', 'Non-current liabilities', 'LIABILITY', '2', false, null, null],
            ['2201', 'مخصص مكافأة نهاية الخدمة', 'End-of-service provision', 'LIABILITY', '22', true, null, 'NON_CURRENT_LIABILITIES'],
            ['3', 'حقوق الملكية', 'Equity', 'EQUITY', null, false, null, null],
            ['3101', 'رأس المال', 'Capital', 'EQUITY', '3', true, 'CAPITAL', 'EQUITY'],
            ['3201', 'جاري المالك', 'Owner current account', 'EQUITY', '3', true, 'OWNER_CURRENT', 'EQUITY'],
            ['3301', 'الأرباح المبقاة', 'Retained earnings', 'EQUITY', '3', true, 'RETAINED_EARNINGS', 'EQUITY'],
            ['3302', 'أرباح وخسائر السنة الحالية (غير موزعة)', 'Current year earnings (undistributed)', 'EQUITY', '3', true, null, 'CURRENT_YEAR_EARNINGS'],
            ['4', 'الإيرادات', 'Revenue', 'REVENUE', null, false, null, null],
            ['4101', 'إيرادات المشاريع (تصنيع وتوريد وتركيب)', 'Project revenue', 'REVENUE', '4', true, 'SALES', 'INCOME'],
            ['4190', 'خصومات المبيعات', 'Sales discounts', 'REVENUE', '4', true, 'SALES_DISCOUNT', 'INCOME'],
            ['4201', 'إيرادات أخرى', 'Other income', 'REVENUE', '4', true, null, 'OTHER_INCOME'],
            ['5', 'التكاليف والمصروفات', 'Costs and expenses', 'EXPENSE', null, false, null, null],
            ['51', 'تكلفة المشاريع المنفذة', 'Cost of projects delivered', 'EXPENSE', '5', false, null, null],
            ['5101', 'تكلفة المشاريع المسلّمة', 'Cost of delivered projects', 'EXPENSE', '51', true, 'COST_OF_SALES', 'COST_OF_REVENUE'],
            ['52', 'التكاليف الصناعية غير المباشرة', 'Manufacturing overhead', 'EXPENSE', '5', false, null, null],
            ['5201', 'أجور غير مباشرة', 'Indirect labour', 'EXPENSE', '52', true, null, 'EXPENSES'],
            ['5202', 'إيجار الورشة', 'Workshop rent', 'EXPENSE', '52', true, null, 'EXPENSES'],
            ['5203', 'كهرباء ومياه', 'Electricity and water', 'EXPENSE', '52', true, null, 'EXPENSES'],
            ['5204', 'صيانة المكائن والمعدات', 'Machine maintenance', 'EXPENSE', '52', true, null, 'EXPENSES'],
            ['5205', 'أدوات ومستهلكات الورشة', 'Workshop tools and consumables', 'EXPENSE', '52', true, null, 'EXPENSES'],
            ['5206', 'إهلاك المكائن والمعدات', 'Depreciation of machinery', 'EXPENSE', '52', true, 'DEPRECIATION', 'DEPRECIATION'],
            ['5207', 'فروقات جرد المخزون', 'Inventory count differences', 'EXPENSE', '52', true, 'INVENTORY_ADJUSTMENT', 'EXPENSES'],
            ['53', 'المصروفات العمومية والإدارية', 'General and administrative expenses', 'EXPENSE', '5', false, null, null],
            ['5301', 'رواتب إدارية', 'Administrative salaries', 'EXPENSE', '53', true, null, 'EXPENSES'],
            ['5302', 'رسوم حكومية وتأشيرات وإقامات', 'Government fees, visas and residency', 'EXPENSE', '53', true, null, 'EXPENSES'],
            ['5303', 'تأمينات اجتماعية وطبي', 'Social and medical insurance', 'EXPENSE', '53', true, null, 'EXPENSES'],
            ['5304', 'اتصالات وإنترنت وبرامج', 'Telecom, internet and software', 'EXPENSE', '53', true, null, 'EXPENSES'],
            ['5305', 'أتعاب مهنية', 'Professional fees', 'EXPENSE', '53', true, null, 'EXPENSES'],
            ['5306', 'رسوم بنكية', 'Bank charges', 'EXPENSE', '53', true, null, 'EXPENSES'],
            ['5307', 'ضيافة ونثريات مكتبية', 'Hospitality and office sundries', 'EXPENSE', '53', true, null, 'EXPENSES'],
            ['5308', 'مصاريف التأسيس', 'Start-up costs', 'EXPENSE', '53', true, null, 'EXPENSES'],
            ['5309', 'وقود ونقل', 'Fuel and transport', 'EXPENSE', '53', true, null, 'EXPENSES'],
            ['5390', 'مصروفات أخرى', 'Other expenses', 'EXPENSE', '53', true, null, 'EXPENSES'],
            ['54', 'مصروفات البيع والتسويق', 'Selling and marketing expenses', 'EXPENSE', '5', false, null, null],
            ['5401', 'تسويق وإعلان', 'Marketing and advertising', 'EXPENSE', '54', true, null, 'EXPENSES'],
            ['59', 'الزكاة', 'Zakat', 'EXPENSE', '5', false, null, null],
            ['5901', 'مصروف الزكاة', 'Zakat expense', 'EXPENSE', '59', true, null, 'EXPENSES'],
        ];
    }
}
