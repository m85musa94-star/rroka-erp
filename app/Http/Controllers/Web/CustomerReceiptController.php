<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CustomerReceipt;
use App\Models\PaymentAccount;
use App\Models\Project;
use App\Models\SalesInvoice;
use App\Services\StudioStorage;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Receipts from clients: approval posts Dr cash/bank/custody, Cr the client's receivable. */
class CustomerReceiptController extends Controller
{
    public function index(Request $request): View
    {
        $st = fn (string $s) => fn ($q) => $q->where('customer_receipts.status', $s);
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('مسودة'), 'group' => 'st', 'apply' => $st('DRAFT')],
                'approved' => ['label' => __('معتمدة'), 'group' => 'st', 'apply' => $st('APPROVED')],
                'no_invoice' => ['label' => __('بلا فاتورة (دفعة مقدمة)'), 'group' => 'i', 'apply' => fn ($q) => $q->whereNull('sales_invoice_id')],
                'self' => ['label' => __('اعتماد ذاتي (للمراجعة)'), 'group' => 'x', 'apply' => fn ($q) => $q->where('customer_receipts.status', 'APPROVED')->whereColumn('approved_by', 'customer_receipts.created_by')],
                'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'apply' => fn ($q) => $q->where('receipt_date', '>=', now()->startOfMonth())],
            ],
            groups: [
                'client' => ['label' => __('العميل'), 'key' => fn ($r) => $r->client_id, 'title' => fn ($r) => $r->client->business_name],
                'account' => ['label' => __('استُلم في'), 'key' => fn ($r) => $r->payment_account_id, 'title' => fn ($r) => $r->paymentAccount->name],
                'month' => ['label' => __('الشهر'), 'key' => fn ($r) => $r->receipt_date->format('Y-m'), 'title' => fn ($r) => $r->receipt_date->format('Y-m')],
            ],
            keep: ['client_id', 'project_id'],
        );
        $query = $lv->applyFilters(CustomerReceipt::with('client:id,business_name', 'project:id,project_no', 'invoice:id,invoice_no,daftra_invoice_no', 'paymentAccount:id,name')
            ->orderByDesc('receipt_date')->orderByDesc('id'))
            ->when($request->integer('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->integer('project_id'), fn ($q, $id) => $q->where('project_id', $id));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('receipt_no', 'ilike', "%{$s}%")->orWhere('reference', 'ilike', "%{$s}%")
                ->orWhereHas('client', fn ($c) => $c->where('business_name', 'ilike', "%{$s}%")));
        }

        return view('sales.receipts.index', [
            'lv' => $lv,
            'receipts' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(2000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        $invoice = $request->integer('invoice') ? SalesInvoice::where('kind', 'INVOICE')->findOrFail($request->integer('invoice')) : null;

        return view('sales.receipts.form', ['x' => new CustomerReceipt([
            'receipt_date' => today(),
            'client_id' => $invoice?->client_id ?? ($request->integer('client_id') ?: null),
            'project_id' => $invoice?->project_id ?? ($request->integer('project_id') ?: null),
            'sales_invoice_id' => $invoice?->id,
            'amount' => $invoice && $invoice->status === 'APPROVED' ? max(0, round($invoice->openAmount(), 2)) ?: null : null,
        ]), ...$this->choices()]);
    }

    public function store(Request $request, StudioStorage $storage): RedirectResponse
    {
        $x = new CustomerReceipt;
        $x->created_by = $request->user()->id;
        $this->save($request, $x, $storage);

        return redirect()->route('sales.receipts.show', $x)->with('ok', __('حُفظ سند القبض كمسودة.'));
    }

    public function show(CustomerReceipt $receipt): View
    {
        return view('sales.receipts.show', [
            'x' => $receipt->load('client', 'project', 'invoice', 'paymentAccount', 'attachment'),
            'names' => DB::table('users')->whereIn('id', array_filter([$receipt->created_by, $receipt->approved_by]))->pluck('name', 'id'),
            'activity' => ActivityLog::for(['customer_receipts' => [$receipt->id]]),
        ]);
    }

    public function edit(CustomerReceipt $receipt): View|RedirectResponse
    {
        if ($receipt->status !== 'DRAFT') {
            return redirect()->route('sales.receipts.show', $receipt)->withErrors(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }

        return view('sales.receipts.form', ['x' => $receipt, ...$this->choices()]);
    }

    public function update(Request $request, CustomerReceipt $receipt, StudioStorage $storage): RedirectResponse
    {
        $this->save($request, $receipt, $storage);

        return redirect()->route('sales.receipts.show', $receipt)->with('ok', __('حُدّث سند القبض.'));
    }

    public function approve(Request $request, CustomerReceipt $receipt): RedirectResponse
    {
        $this->draftOrFail($receipt);
        $receipt->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();

        return back()->with('ok', __('اعتُمد سند القبض.'));
    }

    public function cancel(CustomerReceipt $receipt): RedirectResponse
    {
        $this->draftOrFail($receipt);
        $receipt->forceFill(['status' => 'CANCELLED'])->save();

        return back()->with('ok', __('أُلغيت المسودة.'));
    }

    private function save(Request $request, CustomerReceipt $x, StudioStorage $storage): void
    {
        $data = $request->validate([
            'receipt_date' => ['required', 'date', 'before_or_equal:today'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'sales_invoice_id' => ['nullable', 'integer', Rule::exists('sales_invoices', 'id')->where('kind', 'INVOICE')],
            'payment_account_id' => ['required', 'integer', Rule::exists('payment_accounts', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'document' => ['nullable', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);
        unset($data['document']);

        DB::transaction(function () use ($x, $data, $request, $storage) {
            $x->fill($data)->save();
            if ($request->hasFile('document')) {
                [$asset, $existed] = $storage->storeDocument($request->file('document'), __('سند قبض').' '.$x->receipt_no, $request->user()->id);
                $x->forceFill(['attachment_id' => $asset->id])->save();
                if ($existed) {
                    session()->flash('warn', __('صورة المستند نفسها مرفقة سابقًا بـ :title — تأكد أنها ليست فاتورة مكررة.', ['title' => $asset->title]));
                }
            }
        });
    }

    private function choices(): array
    {
        return [
            'clients' => Client::orderBy('business_name')->get(['id', 'business_name', 'client_no']),
            'projects' => Project::whereNot('status', 'CANCELLED')->orderByDesc('id')->get(['id', 'project_no', 'title', 'client_id']),
            'invoices' => SalesInvoice::where('kind', 'INVOICE')->whereNot('status', 'CANCELLED')->orderByDesc('invoice_date')
                ->get(['id', 'invoice_no', 'daftra_invoice_no', 'client_id', 'total', 'status']),
            'accounts' => PaymentAccount::where('is_active', true)->orderBy('kind')->orderBy('name')->get(['id', 'name', 'kind']),
            'canAttach' => StudioStorage::isReady(),
        ];
    }

    private function draftOrFail(CustomerReceipt $doc): void
    {
        if ($doc->status !== 'DRAFT') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }
    }
}
