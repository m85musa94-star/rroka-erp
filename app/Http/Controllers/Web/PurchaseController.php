<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Services\StudioStorage;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Supplier invoices: the single entry point for materials coming in. Approval
 * posts the stock receipts at net cost (database trigger); the document then
 * goes to Daftra once that link is verified — it is never typed into Daftra.
 */
class PurchaseController extends Controller
{
    public function index(Request $request): View
    {
        $st = fn (string $s) => fn ($q) => $q->where('purchase_invoices.status', $s);
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('مسودة'), 'group' => 'st', 'apply' => $st('DRAFT')],
                'approved' => ['label' => __('معتمدة'), 'group' => 'st', 'apply' => $st('APPROVED')],
                'self' => ['label' => __('اعتماد ذاتي (للمراجعة)'), 'group' => 'x', 'apply' => fn ($q) => $q->where('purchase_invoices.status', 'APPROVED')->whereColumn('approved_by', 'purchase_invoices.created_by')],
                'no_doc' => ['label' => __('بلا صورة مستند'), 'group' => 'd', 'apply' => fn ($q) => $q->whereNull('attachment_id')],
                'not_synced' => ['label' => __('لم تُرسل لدفترة'), 'group' => 'sync', 'apply' => fn ($q) => $q->where('purchase_invoices.status', 'APPROVED')->whereNull('daftra_purchase_id')],
                'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'apply' => fn ($q) => $q->where('invoice_date', '>=', now()->startOfMonth())],
            ],
            groups: [
                'supplier' => ['label' => __('المورد'), 'key' => fn ($p) => $p->supplier_id, 'title' => fn ($p) => $p->supplier->name],
                'month' => ['label' => __('الشهر'), 'key' => fn ($p) => $p->invoice_date->format('Y-m'), 'title' => fn ($p) => $p->invoice_date->format('Y-m')],
                'status' => ['label' => __('الحالة'), 'key' => fn ($p) => $p->status, 'title' => fn ($p) => __("rroka.status.$p->status")],
            ],
            keep: ['supplier_id'],
        );
        $query = $lv->applyFilters(PurchaseInvoice::with('supplier:id,name')
            ->join('v_purchase_totals as t', 't.purchase_invoice_id', '=', 'purchase_invoices.id')
            ->select('purchase_invoices.*', 't.net_before_vat', 't.total')
            ->orderByDesc('invoice_date')->orderByDesc('purchase_invoices.id'))
            ->when($request->integer('supplier_id'), fn ($q, $id) => $q->where('supplier_id', $id));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('purchase_no', 'ilike', "%{$s}%")->orWhere('supplier_invoice_no', 'ilike', "%{$s}%")
                ->orWhereHas('supplier', fn ($x) => $x->where('name', 'ilike', "%{$s}%")));
        }

        return view('purchasing.invoices.index', [
            'lv' => $lv,
            'invoices' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        return view('purchasing.invoices.form', [
            'p' => new PurchaseInvoice(['supplier_id' => $request->integer('supplier_id') ?: null, 'invoice_date' => today(), 'discount_amount' => 0]),
            'lines' => [],
            ...$this->choices(),
        ]);
    }

    public function store(Request $request, StudioStorage $storage): RedirectResponse
    {
        $data = $this->validated($request);
        $p = DB::transaction(function () use ($data, $request, $storage) {
            $p = new PurchaseInvoice(collect($data)->except('lines', 'document')->all());
            $p->created_by = $request->user()->id;
            $p->save();
            $this->writeLines($p, $data['lines']);
            $this->attach($p, $request, $storage);

            return $p;
        });

        return redirect()->route('purchases.show', $p)->with('ok', __('حُفظت فاتورة المورد كمسودة.'));
    }

    public function show(PurchaseInvoice $purchase): View
    {
        return view('purchasing.invoices.show', [
            'p' => $purchase->load('supplier', 'lines.material', 'attachment'),
            'totals' => $purchase->totals(),
            'names' => DB::table('users')->whereIn('id', array_filter([$purchase->created_by, $purchase->approved_by]))->pluck('name', 'id'),
            'receipts' => DB::table('stock_movements')->whereIn('purchase_invoice_line_id', $purchase->lines->pluck('id'))->pluck('unit_cost', 'purchase_invoice_line_id'),
            'activity' => ActivityLog::for([
                'purchase_invoices' => [$purchase->id],
                'purchase_invoice_lines' => $purchase->lines->pluck('id')->all(),
            ]),
        ]);
    }

    public function edit(PurchaseInvoice $purchase): View|RedirectResponse
    {
        if ($purchase->status !== 'DRAFT') {
            return redirect()->route('purchases.show', $purchase)->withErrors(['rule' => __('rroka.errors.RROKA_PURCHASE_LOCKED')]);
        }

        return view('purchasing.invoices.form', [
            'p' => $purchase,
            'lines' => $purchase->lines->map->only('material_id', 'quantity', 'unit_price')->all(),
            ...$this->choices(),
        ]);
    }

    public function update(Request $request, PurchaseInvoice $purchase, StudioStorage $storage): RedirectResponse
    {
        $data = $this->validated($request, $purchase);
        DB::transaction(function () use ($purchase, $data, $request, $storage) {
            $purchase->lines()->delete();
            $purchase->update(collect($data)->except('lines', 'document')->all());
            $this->writeLines($purchase, $data['lines']);
            $this->attach($purchase, $request, $storage);
        });

        return redirect()->route('purchases.show', $purchase)->with('ok', __('حُدّثت فاتورة المورد.'));
    }

    /** Approval moves the stock (trigger). Self-approval is allowed and flagged for review. */
    public function approve(Request $request, PurchaseInvoice $purchase): RedirectResponse
    {
        $this->draftOrFail($purchase);
        $purchase->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();

        return back()->with('ok', __('اعتُمدت الفاتورة ودخلت الخامات المخزون.'));
    }

    public function cancel(PurchaseInvoice $purchase): RedirectResponse
    {
        $this->draftOrFail($purchase);
        $purchase->forceFill(['status' => 'CANCELLED'])->save();

        return back()->with('ok', __('أُلغيت المسودة.'));
    }

    private function attach(PurchaseInvoice $p, Request $request, StudioStorage $storage): void
    {
        if ($request->hasFile('document')) {
            [$asset, $existed] = $storage->storeDocument($request->file('document'), __('فاتورة مورد').' '.$p->purchase_no, $request->user()->id);
            $p->forceFill(['attachment_id' => $asset->id])->save();
            if ($existed) {
                session()->flash('warn', __('صورة المستند نفسها مرفقة سابقًا بـ :title — تأكد أنها ليست فاتورة مكررة.', ['title' => $asset->title]));
            }
        }
    }

    private function validated(Request $request, ?PurchaseInvoice $p = null): array
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'supplier_invoice_no' => ['required', 'string', 'max:60'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'vat_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.material_id' => ['required', 'integer', 'exists:raw_materials,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
        $data['lines'] = array_values($data['lines']);

        return $data;
    }

    private function writeLines(PurchaseInvoice $p, array $lines): void
    {
        foreach ($lines as $i => $line) {
            $p->lines()->create($line + ['line_no' => $i + 1]);
        }
    }

    private function choices(): array
    {
        return [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'supplier_no']),
            'materials' => RawMaterial::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'uom']),
            'canAttach' => StudioStorage::isReady(),
        ];
    }

    /** Approve/cancel act on a draft only (the database refuses changes after that). */
    private function draftOrFail(PurchaseInvoice $doc): void
    {
        if ($doc->status !== 'DRAFT') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_PURCHASE_LOCKED')]);
        }
    }
}
