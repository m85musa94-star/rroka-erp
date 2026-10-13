<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\SalesInvoice;
use App\Services\StudioStorage;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Customer invoices and credit notes: the tax invoice is issued in Daftra (the ZATCA
 * gate) and recorded here with Daftra's number exactly as issued. Approval posts it
 * (receivable / project revenue / output VAT); the client's balance follows.
 */
class SalesInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $st = fn (string $s) => fn ($q) => $q->where('sales_invoices.status', $s);
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('مسودة'), 'group' => 'st', 'apply' => $st('DRAFT')],
                'approved' => ['label' => __('معتمدة'), 'group' => 'st', 'apply' => $st('APPROVED')],
                'invoices' => ['label' => __('فواتير'), 'group' => 'k', 'apply' => fn ($q) => $q->where('kind', 'INVOICE')],
                'credit_notes' => ['label' => __('إشعارات دائنة'), 'group' => 'k', 'apply' => fn ($q) => $q->where('kind', 'CREDIT_NOTE')],
                'no_project' => ['label' => __('بلا مشروع'), 'group' => 'p', 'apply' => fn ($q) => $q->whereNull('project_id')],
                'self' => ['label' => __('اعتماد ذاتي (للمراجعة)'), 'group' => 'x', 'apply' => fn ($q) => $q->where('sales_invoices.status', 'APPROVED')->whereColumn('approved_by', 'sales_invoices.created_by')],
                'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'apply' => fn ($q) => $q->where('invoice_date', '>=', now()->startOfMonth())],
            ],
            groups: [
                'client' => ['label' => __('العميل'), 'key' => fn ($i) => $i->client_id, 'title' => fn ($i) => $i->client->business_name],
                'project' => ['label' => __('المشروع'), 'key' => fn ($i) => $i->project_id ?? 0, 'title' => fn ($i) => $i->project?->project_no ?? __('بلا مشروع')],
                'month' => ['label' => __('الشهر'), 'key' => fn ($i) => $i->invoice_date->format('Y-m'), 'title' => fn ($i) => $i->invoice_date->format('Y-m')],
            ],
            keep: ['client_id', 'project_id'],
        );
        $query = $lv->applyFilters(SalesInvoice::with('client:id,business_name', 'project:id,project_no')->orderByDesc('invoice_date')->orderByDesc('id'))
            ->when($request->integer('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->integer('project_id'), fn ($q, $id) => $q->where('project_id', $id));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('invoice_no', 'ilike', "%{$s}%")->orWhere('daftra_invoice_no', 'ilike', "%{$s}%")
                ->orWhere('description', 'ilike', "%{$s}%")->orWhereHas('client', fn ($c) => $c->where('business_name', 'ilike', "%{$s}%")));
        }

        return view('sales.invoices.index', [
            'lv' => $lv,
            'invoices' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(2000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        $original = $request->integer('original') ? SalesInvoice::where('kind', 'INVOICE')->where('status', 'APPROVED')->findOrFail($request->integer('original')) : null;

        return view('sales.invoices.form', ['x' => new SalesInvoice([
            'kind' => $original ? 'CREDIT_NOTE' : 'INVOICE',
            'original_invoice_id' => $original?->id,
            'invoice_date' => today(),
            'client_id' => $original?->client_id ?? ($request->integer('client_id') ?: null),
            'project_id' => $original?->project_id ?? ($request->integer('project_id') ?: null),
        ]), 'original' => $original, ...$this->choices()]);
    }

    public function store(Request $request, StudioStorage $storage): RedirectResponse
    {
        $x = new SalesInvoice;
        $x->created_by = $request->user()->id;
        $this->save($request, $x, $storage);

        return redirect()->route('sales.invoices.show', $x)->with('ok', $x->isCreditNote() ? __('حُفظ الإشعار الدائن كمسودة.') : __('حُفظت الفاتورة كمسودة.'));
    }

    public function show(SalesInvoice $invoice): View
    {
        $invoice->load('client', 'project', 'original', 'attachment', 'creditNotes', 'receipts.paymentAccount');

        return view('sales.invoices.show', [
            'x' => $invoice,
            'open' => $invoice->isCreditNote() || $invoice->status !== 'APPROVED' ? null : $invoice->openAmount(),
            'names' => DB::table('users')->whereIn('id', array_filter([$invoice->created_by, $invoice->approved_by]))->pluck('name', 'id'),
            'activity' => ActivityLog::for(['sales_invoices' => [$invoice->id]]),
        ]);
    }

    public function edit(SalesInvoice $invoice): View|RedirectResponse
    {
        if ($invoice->status !== 'DRAFT') {
            return redirect()->route('sales.invoices.show', $invoice)->withErrors(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }

        return view('sales.invoices.form', ['x' => $invoice, 'original' => $invoice->original, ...$this->choices()]);
    }

    public function update(Request $request, SalesInvoice $invoice, StudioStorage $storage): RedirectResponse
    {
        $this->save($request, $invoice, $storage);

        return redirect()->route('sales.invoices.show', $invoice)->with('ok', __('حُدّث المستند.'));
    }

    public function approve(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $this->draftOrFail($invoice);
        $invoice->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();

        return back()->with('ok', __('اعتُمد المستند.'));
    }

    public function cancel(SalesInvoice $invoice): RedirectResponse
    {
        $this->draftOrFail($invoice);
        $invoice->forceFill(['status' => 'CANCELLED'])->save();

        return back()->with('ok', __('أُلغيت المسودة.'));
    }

    /** Each client's balance from approved invoices, credit notes and receipts. */
    public function balances(Request $request): View
    {
        $rows = DB::table('v_client_balances as b')->join('clients as c', 'c.id', '=', 'b.client_id')
            ->select('b.*', 'c.business_name', 'c.client_no')
            ->when($request->boolean('open'), fn ($q) => $q->where('b.balance', '<>', 0))
            ->orderByDesc('b.balance')->get();

        return view('sales.balances', ['rows' => $rows, 'onlyOpen' => $request->boolean('open')]);
    }

    private function save(Request $request, SalesInvoice $x, StudioStorage $storage): SalesInvoice
    {
        $credit = $request->input('kind') === 'CREDIT_NOTE';
        $data = $request->validate([
            'kind' => ['required', Rule::in(SalesInvoice::KINDS)],
            'original_invoice_id' => [Rule::requiredIf($credit), 'nullable', 'integer', Rule::exists('sales_invoices', 'id')->where('kind', 'INVOICE')],
            'daftra_invoice_no' => ['required', 'string', 'max:60'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'client_id' => [Rule::requiredIf(! $credit), 'nullable', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'vat_amount' => ['required', 'numeric', 'min:0', 'lte:amount'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);
        if ($credit) {
            // A credit note belongs to its invoice's client and project.
            $orig = SalesInvoice::findOrFail($data['original_invoice_id']);
            $data['client_id'] = $orig->client_id;
            $data['project_id'] = $orig->project_id;
        } else {
            $data['original_invoice_id'] = null;
        }
        $data['daftra_invoice_no'] = trim($data['daftra_invoice_no']);
        unset($data['document']);

        try {
            DB::transaction(function () use ($x, $data, $request, $storage) {
                $x->fill($data)->save();
                if ($request->hasFile('document')) {
                    [$asset, $existed] = $storage->storeDocument($request->file('document'), __('فاتورة عميل').' '.$x->invoice_no, $request->user()->id);
                    $x->forceFill(['attachment_id' => $asset->id])->save();
                    if ($existed) {
                        session()->flash('warn', __('صورة المستند نفسها مرفقة سابقًا بـ :title — تأكد أنها ليست فاتورة مكررة.', ['title' => $asset->title]));
                    }
                }
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'ux_sales_invoices_daftra_no')) {
                throw ValidationException::withMessages(['daftra_invoice_no' => __('هذا الرقم من دفترة مسجّل في مستند آخر؛ كل فاتورة تُسجَّل مرة واحدة.')]);
            }
            throw $e;
        }

        return $x;
    }

    private function choices(): array
    {
        return [
            'clients' => Client::orderBy('business_name')->get(['id', 'business_name', 'client_no']),
            'projects' => Project::whereNot('status', 'CANCELLED')->orderByDesc('id')->get(['id', 'project_no', 'title', 'client_id']),
            'canAttach' => StudioStorage::isReady(),
        ];
    }

    private function draftOrFail(SalesInvoice $doc): void
    {
        if ($doc->status !== 'DRAFT') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }
    }
}
