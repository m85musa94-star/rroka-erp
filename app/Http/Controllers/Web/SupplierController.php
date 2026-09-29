<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $lv = new ListView($request,
            filters: [
                'active' => ['label' => __('النشطون'), 'group' => 'a', 'apply' => fn ($q) => $q->where('is_active', true)],
                'inactive' => ['label' => __('الموقوفون'), 'group' => 'a', 'apply' => fn ($q) => $q->where('is_active', false)],
                'vat' => ['label' => __('مسجلون في ضريبة القيمة المضافة'), 'group' => 'v', 'apply' => fn ($q) => $q->whereNotNull('vat_number')],
            ],
            groups: ['city' => ['label' => __('المدينة'), 'key' => fn ($s) => $s->city ?? '', 'title' => fn ($s) => $s->city ?: __('بلا مدينة')]],
        );
        $query = $lv->applyFilters(Supplier::query()->orderBy('name'));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$s}%")->orWhere('supplier_no', 'ilike', "%{$s}%")
                ->orWhere('vat_number', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%"));
        }

        return view('purchasing.suppliers.index', [
            'lv' => $lv,
            'suppliers' => $lv->group ? null : $query->paginate(50)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(2000)->get()) : null,
        ]);
    }

    public function create(): View
    {
        return view('purchasing.suppliers.form', ['s' => new Supplier(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $s = new Supplier($this->validated($request));
        $s->created_by = $request->user()->id;
        $s->save();

        return redirect()->route('suppliers.show', $s)->with('ok', __('تمت إضافة المورد.'));
    }

    public function show(Supplier $supplier): View
    {
        return view('purchasing.suppliers.show', [
            's' => $supplier,
            'invoices' => $supplier->purchaseInvoices()->join('v_purchase_totals as t', 't.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->select('purchase_invoices.*', 't.net_before_vat', 't.total')->limit(50)->get(),
            'activity' => ActivityLog::for(['suppliers' => [$supplier->id]]),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('purchasing.suppliers.form', ['s' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return redirect()->route('suppliers.show', $supplier)->with('ok', __('تم تحديث المورد.'));
    }

    private function validated(Request $request, ?Supplier $s = null): array
    {
        $request->merge(['iban' => $request->filled('iban') ? strtoupper(preg_replace('/\s+/', '', $request->input('iban'))) : null]);

        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'vat_number' => ['nullable', 'regex:/^[0-9]{15}$/', Rule::unique('suppliers', 'vat_number')->ignore($s?->id)],
            'commercial_reg_no' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'iban' => ['nullable', 'regex:/^SA[0-9]{2}[0-9A-Z]{20}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }
}
