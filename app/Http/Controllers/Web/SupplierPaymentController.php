<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\PaymentAccount;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\StudioStorage;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Supplier payments: settle invoices on credit (expenses with the method CREDIT, or
 * stock purchase invoices). Approval posts Dr payables (the supplier) / Cr the account paid from.
 */
class SupplierPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $st = fn (string $s) => fn ($q) => $q->where('supplier_payments.status', $s);
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('مسودة'), 'group' => 'st', 'apply' => $st('DRAFT')],
                'approved' => ['label' => __('معتمدة'), 'group' => 'st', 'apply' => $st('APPROVED')],
                'no_invoice' => ['label' => __('بلا فاتورة (دفعة مقدمة)'), 'group' => 'i', 'apply' => fn ($q) => $q->whereNull('expense_id')->whereNull('purchase_invoice_id')],
                'self' => ['label' => __('اعتماد ذاتي (للمراجعة)'), 'group' => 'x', 'apply' => fn ($q) => $q->where('supplier_payments.status', 'APPROVED')->whereColumn('approved_by', 'supplier_payments.created_by')],
                'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'apply' => fn ($q) => $q->where('payment_date', '>=', now()->startOfMonth())],
            ],
            groups: [
                'supplier' => ['label' => __('المورد'), 'key' => fn ($r) => $r->supplier_id, 'title' => fn ($r) => $r->supplier->name],
                'account' => ['label' => __('دُفع من'), 'key' => fn ($r) => $r->payment_account_id, 'title' => fn ($r) => $r->paymentAccount->name],
                'month' => ['label' => __('الشهر'), 'key' => fn ($r) => $r->payment_date->format('Y-m'), 'title' => fn ($r) => $r->payment_date->format('Y-m')],
            ],
            keep: ['supplier_id'],
        );
        $query = $lv->applyFilters(SupplierPayment::with('supplier:id,name', 'paymentAccount:id,name', 'expense:id,expense_no,reference', 'purchaseInvoice:id,purchase_no,supplier_invoice_no')
            ->orderByDesc('payment_date')->orderByDesc('id'))
            ->when($request->integer('supplier_id'), fn ($q, $id) => $q->where('supplier_id', $id));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('payment_no', 'ilike', "%{$s}%")->orWhere('reference', 'ilike', "%{$s}%")
                ->orWhereHas('supplier', fn ($c) => $c->where('name', 'ilike', "%{$s}%")));
        }

        return view('purchasing.payments.index', [
            'lv' => $lv,
            'payments' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(2000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        $x = new SupplierPayment(['payment_date' => today(), 'supplier_id' => $request->integer('supplier_id') ?: null]);
        if ($e = $request->integer('expense') ? Expense::where('payment_method', 'CREDIT')->findOrFail($request->integer('expense')) : null) {
            $x->fill(['supplier_id' => $e->supplier_id, 'expense_id' => $e->id, 'amount' => $e->status === 'APPROVED' ? max(0, round($e->openAmount(), 2)) ?: null : null]);
        } elseif ($p = $request->integer('purchase') ? PurchaseInvoice::findOrFail($request->integer('purchase')) : null) {
            $x->fill(['supplier_id' => $p->supplier_id, 'purchase_invoice_id' => $p->id, 'amount' => $p->status === 'APPROVED' ? max(0, round(self::purchaseOpen($p), 2)) ?: null : null]);
        }

        return view('purchasing.payments.form', ['x' => $x, ...$this->choices()]);
    }

    public function store(Request $request, StudioStorage $storage): RedirectResponse
    {
        $x = new SupplierPayment;
        $x->created_by = $request->user()->id;
        $this->save($request, $x, $storage);

        return redirect()->route('supplier-payments.show', $x)->with('ok', __('حُفظ سند الصرف كمسودة.'));
    }

    public function show(SupplierPayment $payment): View
    {
        return view('purchasing.payments.show', [
            'x' => $payment->load('supplier', 'paymentAccount', 'expense', 'purchaseInvoice', 'attachment'),
            'names' => DB::table('users')->whereIn('id', array_filter([$payment->created_by, $payment->approved_by]))->pluck('name', 'id'),
            'activity' => ActivityLog::for(['supplier_payments' => [$payment->id]]),
        ]);
    }

    public function edit(SupplierPayment $payment): View|RedirectResponse
    {
        if ($payment->status !== 'DRAFT') {
            return redirect()->route('supplier-payments.show', $payment)->withErrors(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }

        return view('purchasing.payments.form', ['x' => $payment, ...$this->choices()]);
    }

    public function update(Request $request, SupplierPayment $payment, StudioStorage $storage): RedirectResponse
    {
        $this->save($request, $payment, $storage);

        return redirect()->route('supplier-payments.show', $payment)->with('ok', __('حُدّث سند الصرف.'));
    }

    public function approve(Request $request, SupplierPayment $payment): RedirectResponse
    {
        $this->draftOrFail($payment);
        $payment->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();

        return back()->with('ok', __('اعتُمد سند الصرف.'));
    }

    public function cancel(SupplierPayment $payment): RedirectResponse
    {
        $this->draftOrFail($payment);
        $payment->forceFill(['status' => 'CANCELLED'])->save();

        return back()->with('ok', __('أُلغيت المسودة.'));
    }

    /** What each supplier is owed (approved invoices − payments). */
    public function balances(Request $request): View
    {
        $rows = DB::table('v_supplier_balances as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')
            ->select('b.*', 's.name')
            ->when($request->boolean('open'), fn ($q) => $q->where('b.balance', '<>', 0))
            ->orderByDesc('b.balance')->get();

        return view('purchasing.payments.balances', ['rows' => $rows, 'onlyOpen' => $request->boolean('open')]);
    }

    public static function purchaseOpen(PurchaseInvoice $p): float
    {
        return (float) DB::table('v_purchase_totals')->where('purchase_invoice_id', $p->id)->value('total')
            - (float) SupplierPayment::where('purchase_invoice_id', $p->id)->where('status', 'APPROVED')->sum('amount');
    }

    private function save(Request $request, SupplierPayment $x, StudioStorage $storage): void
    {
        $data = $request->validate([
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'invoice' => ['nullable', 'string', 'regex:/^(E|P)\d+$/'],
            'payment_account_id' => ['required', 'integer', Rule::exists('payment_accounts', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'document' => ['nullable', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);
        // One select names the invoice paid: E<id> = an expense on credit, P<id> = a purchase invoice.
        $inv = $data['invoice'] ?? '';
        $data['expense_id'] = str_starts_with($inv, 'E') ? (int) substr($inv, 1) : null;
        $data['purchase_invoice_id'] = str_starts_with($inv, 'P') ? (int) substr($inv, 1) : null;
        unset($data['invoice'], $data['document']);

        DB::transaction(function () use ($x, $data, $request, $storage) {
            $x->fill($data)->save();
            if ($request->hasFile('document')) {
                [$asset] = $storage->storeDocument($request->file('document'), __('سند صرف').' '.$x->payment_no, $request->user()->id);
                $x->forceFill(['attachment_id' => $asset->id])->save();
            }
        });
    }

    private function choices(): array
    {
        return [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'creditExpenses' => Expense::where('payment_method', 'CREDIT')->whereNot('status', 'CANCELLED')->orderByDesc('expense_date')
                ->get(['id', 'expense_no', 'reference', 'supplier_id', 'amount', 'vat_amount', 'status']),
            'purchases' => PurchaseInvoice::join('v_purchase_totals as t', 't.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->whereNot('purchase_invoices.status', 'CANCELLED')->orderByDesc('invoice_date')
                ->get(['purchase_invoices.id', 'purchase_no', 'supplier_invoice_no', 'supplier_id', 't.total', 'purchase_invoices.status']),
            'accounts' => PaymentAccount::where('is_active', true)->orderBy('kind')->orderBy('name')->get(['id', 'name', 'kind']),
            'canAttach' => StudioStorage::isReady(),
        ];
    }

    private function draftOrFail(SupplierPayment $doc): void
    {
        if ($doc->status !== 'DRAFT') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }
    }
}
