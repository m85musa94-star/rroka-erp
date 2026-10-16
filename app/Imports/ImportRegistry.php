<?php

namespace App\Imports;

use App\Http\Controllers\Web\ProjectController;
use App\Models\Account;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\CostCenter;
use App\Models\CostEstimate;
use App\Models\CostEstimateDirectCost;
use App\Models\CostEstimateMaterial;
use App\Models\CostEstimateOperation;
use App\Models\CustomerReceipt;
use App\Models\Department;
use App\Models\Design;
use App\Models\DesignBomLine;
use App\Models\DesignVersion;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeCostCard;
use App\Models\EmployeeCostCardShare;
use App\Models\EnergyRate;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JobPosition;
use App\Models\JournalEntry;
use App\Models\LaborLog;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Machine;
use App\Models\MachineCostCard;
use App\Models\MachineLog;
use App\Models\MaterialStandardPrice;
use App\Models\OverheadPool;
use App\Models\OverheadPoolLine;
use App\Models\OwnerTransaction;
use App\Models\PaymentAccount;
use App\Models\PricingPolicy;
use App\Models\ProductionOrder;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\QualityInspection;
use App\Models\Quotation;
use App\Models\RawMaterial;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\TreasuryTransfer;
use App\Models\VatRate;
use App\Models\VatReturn;
use App\Models\WasteDefault;
use Illuminate\Support\Facades\DB;

/**
 * Every screen whose records can be entered from a filled template. A definition names
 * the screen's own save route (the import goes through it, so its checks and the
 * database's rules apply unchanged), the template columns, and where a created record
 * is shown. Documents are created as drafts, exactly as when saved from the screen.
 *
 * Keys: label, section, route, model, columns, and optionally:
 *  lines    — line columns; rows sharing a «رقم المستند في الملف» make one document
 *  prepare  — fn (array $input): array, turns helper columns ("_…") into form fields
 *  params   — fn (array $input): array, the save route's parameters (the parent record)
 *  approve  — [route, fn (int $id): array params], approve all drafts of a batch at once
 *  show     — fn (int $id): ?string, link to a created record
 *  number   — column holding the record's number
 *  screen   — the list the import belongs to (its «back» link)
 *  note     — what to know before filling the template
 *  permission — needed on top of the save route's (a self-service screen imported for others)
 */
class ImportRegistry
{
    public const SECTIONS = [
        'sales' => 'المبيعات والعملاء',
        'purchasing' => 'المشتريات والمصروفات',
        'treasury' => 'الخزينة',
        'accounting' => 'المحاسبة',
        'inventory' => 'المخزون',
        'projects' => 'المشاريع والتصنيع',
        'hr' => 'الموظفون',
        'costing' => 'محرك التكلفة',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        return self::$cache ??= self::build();
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** The import that belongs to a screen (by its save route), for the buttons on that screen. */
    public static function forRoute(string $route): ?string
    {
        foreach (self::all() as $key => $def) {
            if ($def['route'] === $route) {
                return $key;
            }
        }

        return null;
    }

    private static function opts(string $group, array $values): array
    {
        return array_combine($values, array_map(fn ($v) => "rroka.$group.$v", $values));
    }

    private static function build(): array
    {
        $show = fn (string $route) => fn (int $id) => route($route, $id);
        $notes = Col::text('notes', 'ملاحظات');

        return [
            // ------------------------------------------------------------- Sales
            'clients' => [
                'label' => 'العملاء', 'section' => 'sales', 'route' => 'clients.store', 'model' => Client::class,
                'show' => $show('clients.show'), 'number' => 'client_no', 'screen' => 'clients.index',
                'columns' => [
                    Col::text('business_name', 'اسم العميل', true),
                    Col::enum('client_type', 'نوع العميل', self::opts('client_type', ['INDIVIDUAL', 'COMPANY']), true),
                    Col::text('phone', 'الجوال'),
                    Col::text('email', 'البريد الإلكتروني'),
                    Col::text('vat_number', 'الرقم الضريبي', false, '15 رقمًا'),
                    Col::text('commercial_reg_no', 'السجل التجاري'),
                    Col::text('city', 'المدينة'),
                    Col::text('address', 'العنوان'),
                    $notes,
                ],
            ],
            'quotations' => [
                'label' => 'عروض الأسعار', 'section' => 'sales', 'route' => 'quotations.store', 'model' => Quotation::class,
                'show' => $show('quotations.show'), 'number' => 'quotation_no', 'screen' => 'quotations.index',
                'note' => 'كل عرض بنوده في أسطر متتالية بنفس «رقم المستند في الملف»؛ بيانات الرأس تُقرأ من السطر الأول.',
                'columns' => [
                    Col::lookup('client_id', 'العميل', 'client', true),
                    Col::date('issue_date', 'تاريخ الإصدار', true),
                    Col::date('valid_until', 'صالح حتى'),
                    Col::number('discount_amount', 'الخصم', true, null, '0'),
                    Col::lookup('vat_rate_id', 'نسبة الضريبة', 'vat_rate', false, 'فارغ = بلا ضريبة'),
                    $notes,
                ],
                'lines' => [
                    Col::text('description', 'وصف البند', true),
                    Col::number('quantity', 'الكمية', true, null, '1'),
                    Col::text('unit', 'الوحدة', true, null, 'قطعة'),
                    Col::number('unit_price', 'سعر الوحدة', true),
                ],
            ],
            'sales_invoices' => [
                'label' => 'فواتير العملاء والإشعارات الدائنة', 'section' => 'sales', 'route' => 'sales.invoices.store', 'model' => SalesInvoice::class,
                'show' => $show('sales.invoices.show'), 'number' => 'invoice_no', 'screen' => 'sales.invoices.index',
                'approve' => ['sales.invoices.approve', fn (int $id) => ['invoice' => SalesInvoice::findOrFail($id)]],
                'note' => 'كما صدرت من دفترة. الإشعار الدائن يذكر رقم الفاتورة الأصلية في دفترة (قد تكون في الملف نفسه في سطر سابق).',
                'columns' => [
                    Col::enum('kind', 'النوع', ['INVOICE' => 'فاتورة', 'CREDIT_NOTE' => 'إشعار دائن'], true),
                    Col::text('daftra_invoice_no', 'رقم الفاتورة في دفترة', true),
                    Col::date('invoice_date', 'تاريخ الفاتورة', true),
                    Col::lookup('client_id', 'العميل', 'client', false, 'مطلوب للفاتورة'),
                    Col::lookup('project_id', 'المشروع', 'project'),
                    Col::lookup('original_invoice_id', 'الفاتورة الأصلية (للإشعار الدائن)', 'sales_invoice'),
                    Col::text('description', 'البيان', true),
                    Col::number('amount', 'المبلغ قبل الضريبة', true),
                    Col::number('vat_amount', 'ضريبة القيمة المضافة', true, null, '225'),
                    $notes,
                ],
            ],
            'customer_receipts' => [
                'label' => 'سندات القبض', 'section' => 'sales', 'route' => 'sales.receipts.store', 'model' => CustomerReceipt::class,
                'show' => $show('sales.receipts.show'), 'number' => 'receipt_no', 'screen' => 'sales.receipts.index',
                'approve' => ['sales.receipts.approve', fn (int $id) => ['receipt' => CustomerReceipt::findOrFail($id)]],
                'columns' => [
                    Col::date('receipt_date', 'تاريخ القبض', true),
                    Col::lookup('client_id', 'العميل', 'client', true),
                    Col::lookup('project_id', 'المشروع', 'project'),
                    Col::lookup('sales_invoice_id', 'الفاتورة (رقم دفترة)', 'sales_invoice'),
                    Col::lookup('payment_account_id', 'استُلم في', 'payment_account', true),
                    Col::number('amount', 'المبلغ', true),
                    Col::text('reference', 'المرجع'),
                    Col::text('description', 'البيان'),
                ],
            ],

            // ------------------------------------------------------------- Purchasing
            'suppliers' => [
                'label' => 'الموردون', 'section' => 'purchasing', 'route' => 'suppliers.store', 'model' => Supplier::class,
                'show' => $show('suppliers.show'), 'number' => 'supplier_no', 'screen' => 'suppliers.index',
                'columns' => [
                    Col::text('name', 'اسم المورد', true),
                    Col::text('vat_number', 'الرقم الضريبي', false, '15 رقمًا'),
                    Col::text('commercial_reg_no', 'السجل التجاري'),
                    Col::text('phone', 'الجوال'),
                    Col::text('email', 'البريد الإلكتروني'),
                    Col::text('city', 'المدينة'),
                    Col::text('address', 'العنوان'),
                    Col::text('iban', 'الآيبان'),
                    $notes,
                ],
            ],
            'expense_categories' => [
                'label' => 'تصنيفات المصروفات', 'section' => 'purchasing', 'route' => 'expense-categories.store', 'model' => ExpenseCategory::class,
                'show' => fn () => route('expense-categories.index'), 'screen' => 'expense-categories.index',
                'columns' => [
                    Col::text('name', 'اسم التصنيف', true),
                    Col::bool('is_overhead', 'مصروف غير مباشر للورشة'),
                    Col::text('daftra_account_ref', 'مرجع الحساب في دفترة'),
                ],
            ],
            'expenses' => [
                'label' => 'المصروفات وفواتير الموردين الآجلة', 'section' => 'purchasing', 'route' => 'expenses.store', 'model' => Expense::class,
                'show' => $show('expenses.show'), 'number' => 'expense_no', 'screen' => 'expenses.index',
                'approve' => ['expenses.approve', fn (int $id) => ['expense' => Expense::findOrFail($id)]],
                'note' => '«دُفع من»: اسم الخزينة، أو «آجل» لفاتورة مورد لم تُسدَّد (المورد ورقم فاتورته مطلوبان)، أو «المالك» إذا دفعها المالك من ماله.',
                'columns' => [
                    Col::date('expense_date', 'التاريخ', true),
                    Col::lookup('category_id', 'التصنيف', 'expense_category', true),
                    Col::lookup('supplier_id', 'المورد', 'supplier'),
                    Col::text('payee', 'المستفيد (بلا مورد مسجل)'),
                    Col::text('description', 'البيان', true),
                    Col::number('amount', 'المبلغ قبل الضريبة', true),
                    Col::number('vat_amount', 'ضريبة القيمة المضافة', true, null, '0'),
                    ['extra' => ['CREDIT' => 'آجل', 'OWNER' => 'المالك']] + Col::lookup('payment_account_id', 'دُفع من', 'payment_account', true),
                    Col::enum('payment_method', 'طريقة الدفع من البنك', ['BANK' => 'rroka.payment_method.BANK', 'CARD' => 'rroka.payment_method.CARD']),
                    Col::lookup('project_id', 'المشروع', 'project', false, 'فارغ = مصروف عام'),
                    Col::text('reference', 'رقم فاتورة المورد / المرجع'),
                ],
            ],
            'purchases' => [
                'label' => 'فواتير مشتريات الخامات', 'section' => 'purchasing', 'route' => 'purchases.store', 'model' => PurchaseInvoice::class,
                'show' => $show('purchases.show'), 'number' => 'purchase_no', 'screen' => 'purchases.index',
                'approve' => ['purchases.approve', fn (int $id) => ['purchase' => PurchaseInvoice::findOrFail($id)]],
                'note' => 'كل فاتورة خاماتها في أسطر متتالية بنفس «رقم المستند في الملف». اعتمادها يُدخل الخامات للمخزون.',
                'columns' => [
                    Col::lookup('supplier_id', 'المورد', 'supplier', true),
                    Col::text('supplier_invoice_no', 'رقم فاتورة المورد', true),
                    Col::date('invoice_date', 'تاريخ الفاتورة', true),
                    Col::date('due_date', 'تاريخ الاستحقاق'),
                    Col::number('discount_amount', 'الخصم', true, null, '0'),
                    Col::number('vat_amount', 'ضريبة القيمة المضافة', true, null, '0'),
                    $notes,
                ],
                'lines' => [
                    Col::lookup('material_id', 'الخامة', 'material', true),
                    Col::number('quantity', 'الكمية', true, null, '10'),
                    Col::number('unit_price', 'سعر الوحدة', true, null, '25'),
                ],
            ],
            'supplier_payments' => [
                'label' => 'سندات صرف الموردين', 'section' => 'purchasing', 'route' => 'supplier-payments.store', 'model' => SupplierPayment::class,
                'show' => $show('supplier-payments.show'), 'number' => 'payment_no', 'screen' => 'supplier-payments.index',
                'approve' => ['supplier-payments.approve', fn (int $id) => ['payment' => SupplierPayment::findOrFail($id)]],
                'note' => '«رقم فاتورة المورد» يربط السداد بفاتورته الآجلة المعتمدة لهذا المورد؛ فارغ = دفعة على الحساب.',
                'prepare' => fn (array $in) => ['invoice' => self::supplierInvoice($in['supplier_id'] ?? null, $in['_invoice'] ?? null)] + $in,
                'columns' => [
                    Col::date('payment_date', 'تاريخ السداد', true),
                    Col::lookup('supplier_id', 'المورد', 'supplier', true),
                    Col::text('_invoice', 'رقم فاتورة المورد'),
                    Col::lookup('payment_account_id', 'دُفع من', 'payment_account', true),
                    Col::number('amount', 'المبلغ', true),
                    Col::text('reference', 'المرجع'),
                    Col::text('description', 'البيان'),
                ],
            ],

            // ------------------------------------------------------------- Treasury
            'payment_accounts' => [
                'label' => 'الخزائن والبنوك والعهد', 'section' => 'treasury', 'route' => 'treasury.accounts.store', 'model' => PaymentAccount::class,
                'show' => $show('treasury.accounts.show'), 'screen' => 'treasury.accounts.index',
                'columns' => [
                    Col::text('name', 'الاسم', true),
                    Col::enum('kind', 'النوع', self::opts('account_kind', PaymentAccount::KINDS), true),
                    Col::text('bank_name', 'اسم البنك', false, 'للبنك فقط'),
                    Col::text('iban', 'الآيبان', false, 'للبنك فقط'),
                    Col::lookup('employee_id', 'الموظف صاحب العهدة', 'employee', false, 'للعهدة فقط'),
                    Col::number('custody_limit', 'سقف العهدة', false, 'للعهدة فقط'),
                    Col::text('daftra_treasury_ref', 'مرجع الخزينة في دفترة'),
                    $notes,
                ],
            ],
            'treasury_transfers' => [
                'label' => 'التحويلات وصرف العهد', 'section' => 'treasury', 'route' => 'treasury.transfers.store', 'model' => TreasuryTransfer::class,
                'show' => $show('treasury.transfers.show'), 'number' => 'transfer_no', 'screen' => 'treasury.transfers.index',
                'approve' => ['treasury.transfers.approve', fn (int $id) => ['transfer' => TreasuryTransfer::findOrFail($id)]],
                'columns' => [
                    Col::date('transfer_date', 'التاريخ', true),
                    Col::lookup('from_account_id', 'من', 'payment_account', true),
                    Col::lookup('to_account_id', 'إلى', 'payment_account', true),
                    Col::number('amount', 'المبلغ', true),
                    Col::text('reference', 'المرجع'),
                    $notes,
                ],
            ],
            'owner_transactions' => [
                'label' => 'تمويل المالك وسحوباته', 'section' => 'treasury', 'route' => 'accounting.owner.store', 'model' => OwnerTransaction::class,
                'show' => $show('accounting.owner.show'), 'number' => 'txn_no', 'screen' => 'accounting.owner.index',
                'approve' => ['accounting.owner.approve', fn (int $id) => ['owner' => OwnerTransaction::findOrFail($id)]],
                'columns' => [
                    Col::enum('kind', 'النوع', self::opts('owner_kind', OwnerTransaction::KINDS), true),
                    Col::date('txn_date', 'التاريخ', true),
                    Col::lookup('payment_account_id', 'الخزينة', 'payment_account', true),
                    Col::number('amount', 'المبلغ', true),
                    Col::text('description', 'البيان', true),
                    Col::text('reference', 'المرجع'),
                ],
            ],

            // ------------------------------------------------------------- Accounting
            'accounts' => [
                'label' => 'دليل الحسابات', 'section' => 'accounting', 'route' => 'accounting.accounts.store', 'model' => Account::class,
                'show' => $show('accounting.accounts.edit'), 'number' => 'code', 'screen' => 'accounting.accounts.index',
                'columns' => [
                    Col::text('code', 'رمز الحساب', true, 'أرقام فقط', '1105'),
                    Col::text('name', 'اسم الحساب', true),
                    Col::text('name_en', 'الاسم بالإنجليزية'),
                    Col::enum('detail_type', 'النوع', self::opts('detail_type', array_keys(Account::DETAIL_TYPES)), true),
                    Col::lookup('parent_id', 'المجموعة', 'account_group'),
                    Col::enum('system_role', 'دور الحساب في الربط', self::opts('account_role', Account::ROLES)),
                    Col::bool('reconcile', 'يقبل المطابقة'),
                    $notes,
                ],
            ],
            'account_groups' => [
                'label' => 'مجموعات الحسابات', 'section' => 'accounting', 'route' => 'accounting.accounts.groups.store', 'model' => Account::class,
                'show' => fn () => route('accounting.accounts.groups'), 'number' => 'code', 'screen' => 'accounting.accounts.groups',
                'columns' => [
                    Col::text('code', 'رمز المجموعة', true, 'أرقام فقط', '11'),
                    Col::text('name', 'اسم المجموعة', true),
                    Col::text('name_en', 'الاسم بالإنجليزية'),
                    Col::lookup('parent_id', 'المجموعة الأم', 'account_group'),
                    $notes,
                ],
            ],
            'journal_entries' => [
                'label' => 'القيود اليومية والافتتاحية', 'section' => 'accounting', 'route' => 'accounting.journal.store', 'model' => JournalEntry::class,
                'show' => $show('accounting.journal.show'), 'number' => 'entry_no', 'screen' => 'accounting.journal.index',
                'approve' => ['accounting.journal.post', fn (int $id) => ['entry' => JournalEntry::findOrFail($id)]],
                'note' => 'كل قيد أسطره متتالية بنفس «رقم المستند في الملف». سطر حساب العملاء أو الموردين يذكر نوع الطرف واسمه.',
                'prepare' => fn (array $in) => ['lines' => array_map(fn ($l) => ['partner' => self::partner($l['_partner_type'] ?? null, $l['_partner'] ?? null)] + $l, $in['lines'] ?? [])] + $in,
                'columns' => [
                    Col::date('entry_date', 'تاريخ القيد', true),
                    Col::enum('source_type', 'نوع القيد', self::opts('journal_source', ['MANUAL', 'OPENING']), true),
                    Col::text('description', 'البيان', true),
                    Col::text('reference', 'المرجع'),
                ],
                'lines' => [
                    Col::lookup('account_id', 'الحساب', 'account', true),
                    Col::number('debit', 'مدين', false, null, '0'),
                    Col::number('credit', 'دائن', false, null, '0'),
                    Col::text('description', 'بيان السطر'),
                    Col::enum('_partner_type', 'نوع الطرف', ['CLIENT' => 'عميل', 'SUPPLIER' => 'مورد', 'EMPLOYEE' => 'موظف']),
                    Col::text('_partner', 'الطرف (الاسم أو الرقم)'),
                    Col::lookup('project_id', 'المشروع', 'project'),
                    Col::lookup('cost_center_id', 'مركز التكلفة', 'cost_center'),
                ],
            ],
            'vat_returns' => [
                'label' => 'الإقرارات الضريبية المقدمة', 'section' => 'accounting', 'route' => 'accounting.vat.store', 'model' => VatReturn::class,
                'show' => $show('accounting.vat.show'), 'number' => 'return_no', 'screen' => 'accounting.vat.index',
                'note' => 'تُنشأ مسودات؛ الاعتماد يقفل الفترة فيبقى من شاشة الإقرار نفسها بعد المراجعة.',
                'columns' => [
                    Col::date('period_start', 'بداية الفترة', true),
                    Col::date('period_end', 'نهاية الفترة', true),
                    Col::number('filed_output_vat', 'ضريبة المخرجات المقدمة'),
                    Col::number('filed_input_vat', 'ضريبة المدخلات المقدمة'),
                    Col::bool('include_verify', 'تضمين المشتريات التي تحتاج تحققًا'),
                    Col::text('verify_reason', 'سبب التضمين'),
                    $notes,
                ],
            ],

            // ------------------------------------------------------------- Inventory
            'materials' => [
                'label' => 'الخامات', 'section' => 'inventory', 'route' => 'materials.store', 'model' => RawMaterial::class,
                'show' => $show('materials.show'), 'number' => 'code', 'screen' => 'materials.index',
                'defaults' => ['is_active' => '1'],
                'columns' => [
                    Col::text('code', 'رمز الخامة', true, null, 'MDF-18'),
                    Col::text('name', 'اسم الخامة', true),
                    Col::text('category', 'الفئة'),
                    Col::text('uom', 'الوحدة', true, null, 'لوح'),
                    Col::bool('is_active', 'نشطة', 'فارغ = نعم'),
                ],
            ],
            'stock_adjustments' => [
                'label' => 'تسويات المخزون والأرصدة الافتتاحية', 'section' => 'inventory', 'route' => 'materials.move', 'model' => StockMovement::class,
                'show' => fn (int $id) => route('materials.show', StockMovement::find($id)?->material_id ?? 0), 'screen' => 'materials.index',
                'note' => 'الرصيد الافتتاحي = «إضافة تسوية» بالكمية وتكلفة الوحدة.',
                'params' => fn (array $in) => ['material' => RawMaterial::findOrFail($in['_material'])],
                'columns' => [
                    Col::lookup('_material', 'الخامة', 'material', true),
                    Col::enum('movement_type', 'نوع الحركة', self::opts('movement_type', ['ADJUST_IN', 'ADJUST_OUT']), true),
                    Col::number('quantity', 'الكمية', true, null, '10'),
                    Col::number('unit_cost', 'تكلفة الوحدة', false, 'مطلوبة للإضافة', '25'),
                    Col::datetime('moved_at', 'تاريخ الحركة'),
                    Col::text('reason', 'السبب', true),
                    Col::text('reference', 'المرجع'),
                ],
            ],

            // ------------------------------------------------------------- Projects & manufacturing
            'projects' => [
                'label' => 'المشاريع (من عرض معتمد)', 'section' => 'projects', 'route' => 'projects.store', 'model' => Project::class,
                'show' => $show('projects.show'), 'number' => 'project_no', 'screen' => 'projects.index',
                'columns' => [
                    Col::lookup('quotation_id', 'عرض السعر المعتمد', 'approved_quotation', true),
                    Col::text('title', 'عنوان المشروع', true),
                    Col::date('start_date', 'تاريخ البدء', true),
                    Col::date('target_date', 'التاريخ المستهدف'),
                    Col::lookup('manager_id', 'مدير المشروع', 'user'),
                ],
            ],
            'past_projects' => [
                'label' => 'المشاريع السابقة', 'section' => 'projects', 'route' => 'projects.past.store', 'model' => Project::class,
                'show' => $show('projects.show'), 'number' => 'project_no', 'screen' => 'projects.index',
                'note' => 'لكل مشروع عرض معتمد معفى من ورقة التكلفة بالسبب المكتوب، ثم المشروع في مرحلته.',
                'columns' => [
                    Col::lookup('client_id', 'العميل', 'client', true),
                    Col::text('title', 'عنوان المشروع', true),
                    Col::number('contract_value', 'قيمة العقد قبل الضريبة', true, null, '45000'),
                    Col::lookup('vat_rate_id', 'نسبة الضريبة', 'vat_rate'),
                    Col::date('start_date', 'تاريخ البدء', true),
                    Col::date('target_date', 'التاريخ المستهدف'),
                    Col::enum('stage', 'المرحلة الحالية', self::opts('status', ProjectController::PAST_STAGES), true),
                    Col::date('completed_on', 'تاريخ التسليم', false, 'مطلوب للمسلَّم'),
                    Col::lookup('manager_id', 'مدير المشروع', 'user'),
                    Col::text('reason', 'سبب الإعفاء من ورقة التكلفة', true, null, 'مشروع قبل تشغيل المنظومة'),
                ],
            ],
            'designs' => [
                'label' => 'التصاميم', 'section' => 'projects', 'route' => 'designs.store', 'model' => Design::class,
                'show' => $show('designs.show'), 'screen' => 'designs.index',
                'columns' => [
                    Col::lookup('project_id', 'المشروع', 'project', true),
                    Col::text('title', 'عنوان التصميم', true),
                    Col::text('file_url', 'رابط الملف'),
                    Col::text('change_notes', 'ملاحظات النسخة'),
                ],
            ],
            'bom_lines' => [
                'label' => 'قوائم المواد للتصاميم', 'section' => 'projects', 'route' => 'design-versions.bom', 'model' => DesignBomLine::class,
                'show' => null, 'screen' => 'designs.index',
                'note' => 'التصميم يُحدَّد بالمشروع وعنوان التصميم ورقم النسخة (فارغ = آخر نسخة). السطر نفسه للخامة نفسها يحدّث كميتها.',
                'params' => fn (array $in) => ['version' => self::designVersion($in['_project'] ?? null, $in['_design'] ?? null, $in['_version'] ?? null)],
                'columns' => [
                    Col::lookup('_project', 'المشروع', 'project', true),
                    Col::text('_design', 'عنوان التصميم', true),
                    Col::number('_version', 'رقم النسخة', false, null, '1'),
                    Col::lookup('material_id', 'الخامة', 'material', true),
                    Col::number('quantity', 'الكمية', true, null, '4'),
                    Col::number('waste_pct', 'نسبة الهالك %', false, null, '5'),
                ],
            ],
            'production_orders' => [
                'label' => 'أوامر التصنيع', 'section' => 'projects', 'route' => 'production.store', 'model' => ProductionOrder::class,
                'show' => $show('production.show'), 'number' => 'order_no', 'screen' => 'production.index',
                'prepare' => fn (array $in) => ['design_version_id' => self::designVersion($in['_project'] ?? null, $in['_design'] ?? null, $in['_version'] ?? null)->id] + $in,
                'columns' => [
                    Col::lookup('_project', 'المشروع', 'project', true),
                    Col::text('_design', 'عنوان التصميم', true),
                    Col::number('_version', 'رقم النسخة', false, 'فارغ = آخر نسخة', '1'),
                    Col::date('planned_start', 'البدء المخطط'),
                    Col::date('planned_end', 'الانتهاء المخطط'),
                    $notes,
                ],
            ],
            'production_materials' => [
                'label' => 'حجز وصرف الخامات لأوامر التصنيع', 'section' => 'projects', 'route' => 'production.material', 'model' => StockMovement::class,
                'show' => fn (int $id) => route('production.show', StockMovement::find($id)?->production_order_id ?? 0), 'screen' => 'production.index',
                'params' => fn (array $in) => ['order' => ProductionOrder::findOrFail($in['_order'])],
                'columns' => [
                    Col::lookup('_order', 'أمر التصنيع', 'production_order', true),
                    Col::enum('movement_type', 'نوع الحركة', self::opts('movement_type', ['RESERVE', 'UNRESERVE', 'ISSUE', 'RETURN']), true),
                    Col::lookup('material_id', 'الخامة', 'material', true),
                    Col::number('quantity', 'الكمية', true, null, '2'),
                ],
            ],
            'labor_logs' => [
                'label' => 'ساعات العمال على أوامر التصنيع', 'section' => 'projects', 'route' => 'production.labor', 'model' => LaborLog::class,
                'show' => fn (int $id) => route('production.show', LaborLog::find($id)?->production_order_id ?? 0), 'screen' => 'production.index',
                'params' => fn (array $in) => ['order' => ProductionOrder::findOrFail($in['_order'])],
                'columns' => [
                    Col::lookup('_order', 'أمر التصنيع', 'production_order', true),
                    Col::lookup('worker_id', 'العامل', 'employee', true),
                    Col::date('work_date', 'تاريخ العمل', true),
                    Col::number('hours', 'الساعات', true, null, '8'),
                    Col::text('activity', 'النشاط'),
                ],
            ],
            'machine_logs' => [
                'label' => 'ساعات الآلات على أوامر التصنيع', 'section' => 'projects', 'route' => 'production.machine', 'model' => MachineLog::class,
                'show' => fn (int $id) => route('production.show', MachineLog::find($id)?->production_order_id ?? 0), 'screen' => 'production.index',
                'params' => fn (array $in) => ['order' => ProductionOrder::findOrFail($in['_order'])],
                'columns' => [
                    Col::lookup('_order', 'أمر التصنيع', 'production_order', true),
                    Col::lookup('machine_id', 'الآلة', 'machine', true),
                    Col::date('work_date', 'تاريخ العمل', true),
                    Col::number('hours', 'الساعات', true, null, '3'),
                ],
            ],
            'quality_inspections' => [
                'label' => 'فحوصات الجودة', 'section' => 'projects', 'route' => 'production.inspect', 'model' => QualityInspection::class,
                'show' => fn (int $id) => route('production.show', QualityInspection::find($id)?->production_order_id ?? 0), 'screen' => 'production.index',
                'params' => fn (array $in) => ['order' => ProductionOrder::findOrFail($in['_order'])],
                'columns' => [
                    Col::lookup('_order', 'أمر التصنيع', 'production_order', true),
                    Col::enum('stage', 'مرحلة الفحص', self::opts('qc_stage', ['IN_PROCESS', 'FINAL']), true),
                    Col::enum('result', 'النتيجة', self::opts('qc_result', QualityInspection::RESULTS), true),
                    Col::text('findings', 'الملاحظات', false, 'مطلوبة لغير «ناجح»'),
                ],
            ],

            // ------------------------------------------------------------- HR
            'employees' => [
                'label' => 'الموظفون', 'section' => 'hr', 'route' => 'employees.store', 'model' => Employee::class,
                'show' => $show('employees.show'), 'number' => 'employee_no', 'screen' => 'employees.index',
                'columns' => [
                    Col::text('name', 'الاسم', true),
                    Col::text('trade', 'المهنة'),
                    Col::lookup('department_id', 'القسم', 'department'),
                    Col::lookup('job_id', 'المسمى الوظيفي', 'job'),
                    Col::lookup('manager_id', 'المدير المباشر', 'employee'),
                    Col::text('work_phone', 'هاتف العمل'),
                    Col::text('work_email', 'بريد العمل'),
                    Col::enum('employment_type', 'نوع التوظيف', self::opts('employment_type', ['FULL_TIME', 'PART_TIME', 'CONTRACTOR'])),
                    Col::date('hire_date', 'تاريخ التعيين'),
                    Col::bool('is_direct_labor', 'عمالة مباشرة'),
                    Col::text('mobile', 'الجوال'),
                    Col::text('nationality', 'الجنسية'),
                    Col::enum('id_type', 'نوع الهوية', self::opts('doc_type', ['NATIONAL_ID', 'IQAMA', 'PASSPORT'])),
                    Col::text('id_number', 'رقم الهوية'),
                    Col::date('birth_date', 'تاريخ الميلاد'),
                    Col::enum('gender', 'الجنس', ['M' => 'ذكر', 'F' => 'أنثى']),
                    Col::text('iban', 'الآيبان'),
                    Col::text('emergency_contact', 'جهة اتصال الطوارئ'),
                    Col::text('emergency_phone', 'هاتف الطوارئ'),
                    Col::text('address', 'العنوان'),
                    $notes,
                ],
            ],
            'departments' => [
                'label' => 'الأقسام', 'section' => 'hr', 'route' => 'departments.store', 'model' => Department::class,
                'show' => fn () => route('departments.index'), 'screen' => 'departments.index',
                'columns' => [
                    Col::text('name', 'اسم القسم', true),
                    Col::lookup('parent_id', 'القسم الأعلى', 'department'),
                    Col::lookup('manager_id', 'المدير', 'employee'),
                ],
            ],
            'jobs' => [
                'label' => 'المسميات الوظيفية', 'section' => 'hr', 'route' => 'jobs.store', 'model' => JobPosition::class,
                'show' => fn () => route('departments.index'), 'screen' => 'departments.index',
                'columns' => [
                    Col::text('name', 'المسمى الوظيفي', true),
                    Col::lookup('department_id', 'القسم', 'department'),
                    Col::text('description', 'الوصف'),
                ],
            ],
            'contracts' => [
                'label' => 'عقود الموظفين', 'section' => 'hr', 'route' => 'contracts.store', 'model' => EmployeeContract::class,
                'show' => $show('contracts.show'), 'number' => 'contract_no', 'screen' => 'contracts.index',
                'columns' => [
                    Col::lookup('employee_id', 'الموظف', 'employee', true),
                    Col::enum('contract_type', 'نوع العقد', self::opts('contract_type', ['FIXED_TERM', 'INDEFINITE']), true),
                    Col::date('start_date', 'تاريخ البدء', true),
                    Col::date('end_date', 'تاريخ الانتهاء', false, 'مطلوب للعقد محدد المدة'),
                    Col::number('basic_salary', 'الراتب الأساسي', true, null, '3000'),
                    Col::number('housing_allowance', 'بدل السكن', true, null, '0'),
                    Col::number('transport_allowance', 'بدل النقل', true, null, '0'),
                    Col::number('other_allowance', 'بدلات أخرى', true, null, '0'),
                    Col::number('weekly_hours', 'ساعات العمل الأسبوعية', false, null, '48'),
                    $notes,
                ],
            ],
            'attendance' => [
                'label' => 'سجلات الحضور', 'section' => 'hr', 'route' => 'attendance.store', 'model' => Attendance::class,
                'show' => fn () => route('attendance.records'), 'screen' => 'attendance.records',
                'columns' => [
                    Col::lookup('employee_id', 'الموظف', 'employee', true),
                    Col::datetime('check_in', 'وقت الحضور', true),
                    Col::datetime('check_out', 'وقت الانصراف'),
                    Col::text('notes', 'ملاحظات'),
                ],
            ],
            'leave_types' => [
                'label' => 'أنواع الإجازات', 'section' => 'hr', 'route' => 'leave-types.store', 'model' => LeaveType::class,
                'show' => fn () => route('leaves.settings'), 'screen' => 'leaves.settings',
                'columns' => [
                    Col::text('name', 'نوع الإجازة', true),
                    Col::bool('is_paid', 'مدفوعة'),
                    Col::bool('requires_allocation', 'تحتاج رصيدًا'),
                ],
            ],
            'leave_allocations' => [
                'label' => 'أرصدة الإجازات', 'section' => 'hr', 'route' => 'leave-allocations.store', 'model' => LeaveAllocation::class,
                'show' => fn () => route('leaves.settings'), 'screen' => 'leaves.settings',
                'columns' => [
                    Col::lookup('employee_id', 'الموظف', 'employee', true),
                    Col::lookup('leave_type_id', 'نوع الإجازة', 'leave_type', true),
                    Col::number('days', 'الأيام', true, 'بالسالب للخصم', '21'),
                    Col::date('valid_from', 'من', true),
                    Col::date('valid_to', 'إلى', true),
                    Col::text('reason', 'السبب', true),
                ],
            ],
            'leaves' => [
                'label' => 'طلبات الإجازة', 'section' => 'hr', 'route' => 'leaves.store', 'model' => LeaveRequest::class,
                'permission' => 'hr.leave_approve|hr.manage',
                'show' => fn () => route('leaves.index'), 'screen' => 'leaves.index',
                'columns' => [
                    Col::lookup('employee_id', 'الموظف', 'employee', true),
                    Col::lookup('leave_type_id', 'نوع الإجازة', 'leave_type', true),
                    Col::date('date_from', 'من', true),
                    Col::date('date_to', 'إلى', true),
                    Col::number('days', 'الأيام', true, null, '3'),
                    Col::text('reason', 'السبب'),
                ],
            ],

            // ------------------------------------------------------------- Costing
            'cost_centers' => [
                'label' => 'مراكز التكلفة', 'section' => 'costing', 'route' => 'costing.centers.store', 'model' => CostCenter::class,
                'show' => fn () => route('costing.centers'), 'number' => 'code', 'screen' => 'costing.centers',
                'columns' => [
                    Col::text('code', 'الرمز', true, null, 'CARP'),
                    Col::text('name', 'الاسم', true),
                    Col::enum('driver', 'أساس التحميل', self::opts('driver', CostCenter::DRIVERS), true),
                    Col::lookup('department_id', 'القسم', 'department'),
                    $notes,
                ],
            ],
            'machines' => [
                'label' => 'الآلات', 'section' => 'costing', 'route' => 'costing.machines.store', 'model' => Machine::class,
                'show' => fn () => route('costing.machine-cards.index'), 'number' => 'code', 'screen' => 'costing.machine-cards.index',
                'columns' => [
                    Col::text('code', 'الرمز', true, null, 'CNC-1'),
                    Col::text('name', 'الاسم', true),
                    Col::lookup('cost_center_id', 'مركز التكلفة', 'cost_center'),
                ],
            ],
            'employee_cost_cards' => [
                'label' => 'بطاقات تكلفة الموظفين', 'section' => 'costing', 'route' => 'costing.employee-cards.store', 'model' => EmployeeCostCard::class,
                'show' => $show('costing.employee-cards.show'), 'screen' => 'costing.employee-cards.index',
                'approve' => ['costing.approve', fn (int $id) => ['type' => 'employee-cards', 'id' => $id]],
                'note' => 'المبالغ شهرية والساعات شهرية. وزّع ساعات كل بطاقة على مراكز التكلفة (قالب «توزيع بطاقات الموظفين») قبل اعتمادها.',
                'columns' => [
                    Col::lookup('employee_id', 'الموظف', 'employee', true),
                    Col::date('effective_from', 'تاريخ السريان', true),
                    Col::number('basic_salary', 'الراتب الأساسي', true, null, '3000'),
                    Col::number('housing', 'السكن', true, null, '0'),
                    Col::number('transportation', 'النقل', true, null, '0'),
                    Col::number('insurance', 'التأمينات الاجتماعية والطبية', true, null, '0'),
                    Col::number('government_fees', 'الإقامة والرسوم الحكومية (شهريًا)', true, null, '0'),
                    Col::number('allowances', 'البدلات', true, null, '0'),
                    Col::number('other_costs', 'تكاليف أخرى', true, null, '0'),
                    Col::number('theoretical_hours', 'الساعات النظرية الشهرية', true, null, '208'),
                    Col::number('break_hours', 'الاستراحات', true, null, '0'),
                    Col::number('cleaning_hours', 'التنظيف', true, null, '0'),
                    Col::number('maintenance_hours', 'الصيانة', true, null, '0'),
                    Col::number('setup_hours', 'التجهيز', true, null, '0'),
                    Col::number('meeting_hours', 'الاجتماعات', true, null, '0'),
                    Col::number('downtime_hours', 'التوقف المعتاد', true, null, '0'),
                    Col::number('waiting_hours', 'انتظار المواد', true, null, '0'),
                    Col::number('other_nonproductive_hours', 'وقت غير منتج آخر', true, null, '0'),
                    Col::text('source', 'المصدر', true, null, 'مسير رواتب مارس'),
                    Col::bool('estimated', 'تقديري'),
                    $notes,
                ],
            ],
            'employee_card_shares' => [
                'label' => 'توزيع بطاقات الموظفين على مراكز التكلفة', 'section' => 'costing', 'route' => 'costing.employee-cards.shares.store', 'model' => EmployeeCostCardShare::class,
                'show' => null, 'screen' => 'costing.employee-cards.index',
                'note' => 'البطاقة تُحدَّد بالموظف وتاريخ سريانها (مسودة).',
                'params' => fn (array $in) => ['card' => self::costCard($in['_employee'] ?? null, $in['_effective_from'] ?? null)],
                'columns' => [
                    Col::lookup('_employee', 'الموظف', 'employee', true),
                    Col::date('_effective_from', 'تاريخ سريان البطاقة', true),
                    Col::lookup('cost_center_id', 'مركز التكلفة', 'cost_center', true),
                    Col::number('share_pct', 'النسبة %', true, null, '100'),
                ],
            ],
            'machine_cost_cards' => [
                'label' => 'بطاقات تكلفة الآلات', 'section' => 'costing', 'route' => 'costing.machine-cards.store', 'model' => MachineCostCard::class,
                'show' => $show('costing.machine-cards.show'), 'screen' => 'costing.machine-cards.index',
                'approve' => ['costing.approve', fn (int $id) => ['type' => 'machine-cards', 'id' => $id]],
                'columns' => [
                    Col::lookup('machine_id', 'الآلة', 'machine', true),
                    Col::date('effective_from', 'تاريخ السريان', true),
                    Col::number('acquisition_cost', 'تكلفة الشراء', true, null, '120000'),
                    Col::number('residual_value', 'القيمة المتبقية', true, null, '10000'),
                    Col::number('useful_life_years', 'العمر الإنتاجي بالسنوات', true, null, '10'),
                    Col::number('theoretical_annual_hours', 'الساعات النظرية السنوية', true, null, '2496'),
                    Col::number('practical_annual_hours', 'الساعات العملية السنوية', true, null, '1800'),
                    Col::number('power_kw', 'القدرة (كيلوواط)', true, null, '7.5'),
                    Col::number('load_factor', 'معامل التحميل (0 إلى 1)', true, null, '0.7'),
                    Col::number('annual_maintenance', 'الصيانة السنوية', true, null, '0'),
                    Col::number('annual_spare_parts', 'قطع الغيار السنوية', true, null, '0'),
                    Col::number('annual_other', 'تكاليف سنوية أخرى', true, null, '0'),
                    Col::text('source', 'المصدر', true),
                    Col::bool('estimated', 'تقديري'),
                    $notes,
                ],
            ],
            'overhead_pools' => [
                'label' => 'أوعية المصاريف غير المباشرة', 'section' => 'costing', 'route' => 'costing.pools.store', 'model' => OverheadPool::class,
                'show' => $show('costing.pools.show'), 'screen' => 'costing.pools.index',
                'note' => 'بنود كل وعاء في قالب «بنود أوعية المصاريف»، ثم يُعتمد الوعاء.',
                'columns' => [
                    Col::enum('kind', 'نوع الوعاء', self::opts('pool_kind', ['MANUFACTURING', 'SELLING_ADMIN']), true),
                    Col::lookup('cost_center_id', 'مركز التكلفة', 'cost_center', false, 'فارغ = كل الورشة'),
                    Col::date('effective_from', 'من', true),
                    Col::date('period_to', 'إلى', true),
                    Col::enum('driver', 'أساس التحميل', self::opts('driver', ['LABOR_HOURS', 'MACHINE_HOURS', 'PCT_OF_MANUFACTURING_COST']), true),
                    Col::number('theoretical_capacity', 'الطاقة النظرية'),
                    Col::number('practical_capacity', 'الطاقة العملية'),
                    Col::number('budgeted_manufacturing_cost', 'تكلفة التصنيع المقدرة', false, 'لوعاء البيع والإدارة'),
                    Col::text('source', 'المصدر', true),
                    Col::bool('estimated', 'تقديري'),
                    $notes,
                ],
            ],
            'overhead_pool_lines' => [
                'label' => 'بنود أوعية المصاريف', 'section' => 'costing', 'route' => 'costing.pools.lines.store', 'model' => OverheadPoolLine::class,
                'show' => null, 'screen' => 'costing.pools.index',
                'note' => 'الوعاء يُحدَّد بنوعه ومركز تكلفته (فارغ = كل الورشة) وتاريخ بدايته.',
                'params' => fn (array $in) => ['pool' => self::pool($in['_kind'] ?? null, $in['_cost_center'] ?? null, $in['_effective_from'] ?? null)],
                'columns' => [
                    Col::enum('_kind', 'نوع الوعاء', self::opts('pool_kind', ['MANUFACTURING', 'SELLING_ADMIN']), true),
                    Col::lookup('_cost_center', 'مركز تكلفة الوعاء', 'cost_center'),
                    Col::date('_effective_from', 'بداية الوعاء', true),
                    Col::enum('category', 'البند', self::opts('cost_category', [...OverheadPool::MANUFACTURING_CATEGORIES, ...OverheadPool::SELLING_ADMIN_CATEGORIES]), true),
                    Col::text('description', 'الوصف', true),
                    Col::number('amount', 'المبلغ', true),
                    Col::lookup('employee_id', 'الموظف', 'employee', false, 'للإشراف والعمالة غير المباشرة'),
                    Col::lookup('machine_id', 'الآلة', 'machine', false, 'للإهلاك فقط'),
                ],
            ],
            'material_prices' => [
                'label' => 'الأسعار المعيارية للخامات', 'section' => 'costing', 'route' => 'costing.prices.store', 'model' => MaterialStandardPrice::class,
                'show' => fn () => route('costing.prices'), 'screen' => 'costing.prices',
                'approve' => ['costing.approve', fn (int $id) => ['type' => 'prices', 'id' => $id]],
                'columns' => [
                    Col::lookup('material_id', 'الخامة', 'material', true),
                    Col::date('effective_from', 'تاريخ السريان', true),
                    Col::number('unit_price', 'سعر الوحدة', true, null, '25'),
                    Col::enum('price_basis', 'أساس السعر', self::opts('price_basis', MaterialStandardPrice::BASES), true),
                    Col::text('source', 'المصدر', true),
                    Col::bool('estimated', 'تقديري'),
                ],
            ],
            'waste_defaults' => [
                'label' => 'نسب الهالك', 'section' => 'costing', 'route' => 'costing.waste.store', 'model' => WasteDefault::class,
                'show' => fn () => route('costing.waste'), 'screen' => 'costing.waste',
                'approve' => ['costing.approve', fn (int $id) => ['type' => 'waste', 'id' => $id]],
                'columns' => [
                    Col::lookup('material_id', 'الخامة', 'material', false, 'أو الفئة'),
                    Col::text('category', 'فئة الخامات'),
                    Col::date('effective_from', 'تاريخ السريان', true),
                    Col::number('waste_pct', 'نسبة الهالك %', true, null, '5'),
                    Col::text('source', 'المصدر', true),
                    Col::bool('estimated', 'تقديري'),
                ],
            ],
            'energy_rates' => [
                'label' => 'أسعار الكهرباء', 'section' => 'costing', 'route' => 'costing.energy.store', 'model' => EnergyRate::class,
                'show' => fn () => route('costing.energy'), 'screen' => 'costing.energy',
                'approve' => ['costing.approve', fn (int $id) => ['type' => 'energy', 'id' => $id]],
                'columns' => [
                    Col::date('effective_from', 'تاريخ السريان', true),
                    Col::number('rate_per_kwh', 'سعر الكيلوواط ساعة', true, null, '0.30'),
                    Col::text('source', 'المصدر', true),
                    Col::bool('estimated', 'تقديري'),
                    $notes,
                ],
            ],
            'pricing_policies' => [
                'label' => 'سياسات التسعير', 'section' => 'costing', 'route' => 'costing.pricing.store', 'model' => PricingPolicy::class,
                'show' => fn () => route('costing.pricing'), 'screen' => 'costing.pricing',
                'approve' => ['costing.approve', fn (int $id) => ['type' => 'pricing', 'id' => $id]],
                'columns' => [
                    Col::date('effective_from', 'تاريخ السريان', true),
                    Col::enum('pricing_method', 'طريقة التسعير', self::opts('pricing_method', PricingPolicy::METHODS), true),
                    Col::number('target_pct', 'النسبة المستهدفة %', true, null, '25'),
                    Col::number('min_margin_pct', 'أدنى هامش %'),
                    Col::text('source', 'المصدر', true),
                    Col::bool('estimated', 'تقديري'),
                    $notes,
                ],
            ],
            'vat_rates' => [
                'label' => 'نسب ضريبة القيمة المضافة', 'section' => 'costing', 'route' => 'costing.vat.store', 'model' => VatRate::class,
                'show' => fn () => route('costing.pricing'), 'screen' => 'costing.pricing',
                'columns' => [
                    Col::text('name', 'الاسم', true, null, 'القياسية 15%'),
                    Col::number('rate_pct', 'النسبة %', true, null, '15'),
                ],
            ],
            'estimate_materials' => [
                'label' => 'مواد أوراق التكلفة', 'section' => 'costing', 'route' => 'estimates.materials.store', 'model' => CostEstimateMaterial::class,
                'show' => null, 'screen' => 'quotations.index',
                'note' => 'لبند عرض سعر حُفظت طريقة تسعيره في ورقة التكلفة.',
                'params' => fn (array $in) => self::estimateParams($in),
                'columns' => [
                    Col::lookup('_quotation', 'عرض السعر', 'quotation', true),
                    Col::number('_line', 'رقم البند', true, null, '1'),
                    Col::lookup('material_id', 'الخامة', 'material', true),
                    Col::number('quantity', 'الكمية', true, null, '4'),
                    Col::number('waste_pct', 'نسبة الهالك %'),
                    Col::number('unit_price', 'سعر الوحدة', false, 'فارغ = السعر المعياري'),
                    Col::text('price_source', 'مصدر السعر', false, 'مطلوب مع السعر'),
                ],
            ],
            'estimate_operations' => [
                'label' => 'عمليات أوراق التكلفة', 'section' => 'costing', 'route' => 'estimates.operations.store', 'model' => CostEstimateOperation::class,
                'show' => null, 'screen' => 'quotations.index',
                'params' => fn (array $in) => self::estimateParams($in),
                'columns' => [
                    Col::lookup('_quotation', 'عرض السعر', 'quotation', true),
                    Col::number('_line', 'رقم البند', true, null, '1'),
                    Col::text('operation', 'العملية', true),
                    Col::lookup('cost_center_id', 'مركز التكلفة', 'cost_center', true),
                    Col::lookup('employee_id', 'الموظف', 'employee'),
                    Col::number('labor_hours', 'ساعات العمل', true, null, '6'),
                    Col::number('setup_hours', 'ساعات التجهيز', true, null, '0'),
                    Col::lookup('machine_id', 'الآلة', 'machine'),
                    Col::number('machine_hours', 'ساعات الآلة'),
                    Col::number('machine_setup_hours', 'ساعات تجهيز الآلة'),
                ],
            ],
            'estimate_direct_costs' => [
                'label' => 'التكاليف المباشرة لأوراق التكلفة', 'section' => 'costing', 'route' => 'estimates.direct.store', 'model' => CostEstimateDirectCost::class,
                'show' => null, 'screen' => 'quotations.index',
                'params' => fn (array $in) => self::estimateParams($in),
                'columns' => [
                    Col::lookup('_quotation', 'عرض السعر', 'quotation', true),
                    Col::number('_line', 'رقم البند', true, null, '1'),
                    Col::enum('cost_type', 'النوع', self::opts('direct_cost_type', CostEstimate::DIRECT_TYPES), true),
                    Col::text('description', 'الوصف', true),
                    Col::number('amount', 'المبلغ', true),
                    Col::enum('basis', 'الأساس', self::opts('cost_basis', ['PER_UNIT', 'ONE_TIME']), true),
                ],
            ],
        ];
    }

    /** "E12" (an expense on credit) or "P5" (a purchase invoice) of this supplier, by its invoice number. */
    private static function supplierInvoice(?int $supplierId, ?string $no): ?string
    {
        $no = trim((string) $no);
        if ($no === '' || ! $supplierId) {
            return null;
        }
        $e = Expense::where('supplier_id', $supplierId)->where('payment_method', 'CREDIT')->whereRaw('lower(trim(reference)) = lower(?)', [$no])->pluck('id');
        $p = PurchaseInvoice::where('supplier_id', $supplierId)->whereRaw('lower(trim(supplier_invoice_no)) = lower(?)', [$no])->where('status', '<>', 'CANCELLED')->pluck('id');
        if ($e->count() + $p->count() === 1) {
            return $e->isNotEmpty() ? 'E'.$e->first() : 'P'.$p->first();
        }
        throw new ImportError($e->count() + $p->count() === 0
            ? __('لا توجد فاتورة لهذا المورد بالرقم «:v».', ['v' => $no])
            : __('أكثر من فاتورة لهذا المورد بالرقم «:v».', ['v' => $no]), '_invoice');
    }

    /** "CLIENT:id" / "SUPPLIER:id" / "EMPLOYEE:id" for a journal line's partner. */
    private static function partner(?string $type, ?string $value): ?string
    {
        if (trim((string) $value) === '') {
            return null;
        }
        $lookup = ['CLIENT' => 'client', 'SUPPLIER' => 'supplier', 'EMPLOYEE' => 'employee'][$type ?? ''] ?? null;
        if (! $lookup) {
            throw new ImportError(__('اختر نوع الطرف (عميل أو مورد أو موظف).'), '_partner_type');
        }
        try {
            return $type.':'.Lookups::resolve($lookup, $value);
        } catch (ImportError $e) {
            throw new ImportError($e->getMessage(), '_partner');
        }
    }

    private static function designVersion(?int $projectId, ?string $title, mixed $versionNo): DesignVersion
    {
        $design = Design::where('project_id', $projectId)->whereRaw('lower(trim(title)) = lower(?)', [trim((string) $title)])->get();
        if ($design->count() !== 1) {
            throw new ImportError($design->isEmpty()
                ? __('لا يوجد تصميم بهذا العنوان في المشروع: «:v».', ['v' => $title])
                : __('أكثر من تصميم بهذا العنوان في المشروع: «:v».', ['v' => $title]), '_design');
        }
        $q = DesignVersion::where('design_id', $design->first()->id);
        $version = $versionNo !== null && $versionNo !== '' ? $q->where('version_no', (int) $versionNo)->first() : $q->orderByDesc('version_no')->first();

        return $version ?? throw new ImportError(__('لا توجد نسخة بهذا الرقم للتصميم.'), '_version');
    }

    private static function costCard(?int $employeeId, ?string $from): EmployeeCostCard
    {
        $cards = EmployeeCostCard::where('employee_id', $employeeId)->whereDate('effective_from', (string) $from)->where('status', 'DRAFT')->get();

        return $cards->count() === 1 ? $cards->first()
            : throw new ImportError(__('لا توجد بطاقة مسودة واحدة لهذا الموظف بهذا التاريخ.'), '_effective_from');
    }

    private static function pool(?string $kind, ?int $centerId, ?string $from): OverheadPool
    {
        $pools = OverheadPool::where('kind', $kind)->whereDate('effective_from', (string) $from)
            ->where(fn ($q) => $centerId ? $q->where('cost_center_id', $centerId) : $q->whereNull('cost_center_id'))
            ->where('status', 'DRAFT')->get();

        return $pools->count() === 1 ? $pools->first()
            : throw new ImportError(__('لا يوجد وعاء مسودة واحد بهذا النوع والمركز والتاريخ.'), '_effective_from');
    }

    private static function estimateParams(array $in): array
    {
        $line = (int) ($in['_line'] ?? 0);
        if (! DB::table('quotation_lines')->where('quotation_id', $in['_quotation'] ?? 0)->where('line_no', $line)->exists()) {
            throw new ImportError(__('لا يوجد بند بهذا الرقم في عرض السعر.'), '_line');
        }

        return ['quotation' => Quotation::findOrFail($in['_quotation']), 'line' => $line];
    }
}
