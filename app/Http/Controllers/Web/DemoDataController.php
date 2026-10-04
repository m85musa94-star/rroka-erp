<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\DemoData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Settings page to load or remove the labelled demo records. */
class DemoDataController extends Controller
{
    public const LABELS = [
        'clients' => 'العملاء', 'quotations' => 'عروض الأسعار', 'quotation_lines' => 'بنود عروض الأسعار', 'projects' => 'المشاريع',
        'designs' => 'التصاميم', 'design_versions' => 'نسخ التصاميم', 'design_bom_lines' => 'بنود قوائم المواد',
        'production_orders' => 'أوامر التصنيع', 'labor_logs' => 'ساعات العمال', 'machine_logs' => 'ساعات الآلات', 'quality_inspections' => 'فحوصات الجودة',
        'raw_materials' => 'الخامات', 'stock_movements' => 'حركات المخزون', 'suppliers' => 'الموردون', 'purchase_invoices' => 'فواتير المشتريات',
        'purchase_invoice_lines' => 'بنود فواتير المشتريات', 'expense_categories' => 'تصنيفات المصروفات', 'expenses' => 'المصروفات',
        'payment_accounts' => 'الصناديق والبنوك والعهد', 'treasury_transfers' => 'التحويلات وصرف العهد',
        'departments' => 'الأقسام', 'job_positions' => 'المسميات الوظيفية', 'workers' => 'الموظفون', 'employee_documents' => 'وثائق الموظفين',
        'employee_contracts' => 'العقود', 'worker_rates' => 'أجور ساعات العمال', 'machines' => 'الآلات', 'machine_rates' => 'تكلفة ساعات الآلات',
        'leave_types' => 'أنواع الإجازات', 'leave_allocations' => 'أرصدة الإجازات', 'leave_requests' => 'طلبات الإجازات', 'attendances' => 'سجلات الحضور',
        'studio_assets' => 'صور الاستوديو',
    ];

    public function index(): View
    {
        return view('settings.demo', ['summary' => DemoData::summary()]);
    }

    public function store(Request $request, DemoData $demo): RedirectResponse
    {
        try {
            $n = $demo->seed($request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['rule' => $e->getMessage()]);
        }

        return back()->with('ok', __('حُمّلت البيانات التجريبية (:n سجلًا).', ['n' => $n]));
    }

    public function destroy(Request $request, DemoData $demo): RedirectResponse
    {
        try {
            $n = $demo->purge($request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['rule' => $e->getMessage()]);
        }

        return back()->with('ok', __('حُذفت البيانات التجريبية (:n سجلًا).', ['n' => $n]));
    }
}
