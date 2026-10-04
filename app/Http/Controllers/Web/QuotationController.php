<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DaftraSyncLog;
use App\Models\Quotation;
use App\Models\VatRate;
use App\Services\Daftra\DaftraSyncService;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuotationController extends Controller
{
    public function index(Request $request): View
    {
        $status = fn (string $st) => fn ($q) => $q->where('quotations.status', $st);
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('مسودة'), 'group' => 'status', 'apply' => $status('DRAFT')],
                'sent' => ['label' => __('بانتظار رد العميل'), 'group' => 'status', 'apply' => $status('SENT')],
                'approved' => ['label' => __('معتمد'), 'group' => 'status', 'apply' => $status('APPROVED')],
                'rejected' => ['label' => __('مرفوض'), 'group' => 'status', 'apply' => $status('REJECTED')],
                'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'apply' => fn ($q) => $q->where('issue_date', '>=', now()->startOfMonth())],
                'no_project' => ['label' => __('معتمد بلا مشروع'), 'group' => 'project', 'apply' => fn ($q) => $q->where('quotations.status', 'APPROVED')->whereDoesntHave('project')],
            ],
            groups: [
                'status' => ['label' => __('الحالة'), 'key' => fn ($r) => $r->status, 'title' => fn ($r) => __("rroka.status.$r->status")],
                'client' => ['label' => __('العميل'), 'key' => fn ($r) => $r->client_id, 'title' => fn ($r) => $r->client->business_name],
                'month' => ['label' => __('الشهر'), 'key' => fn ($r) => $r->issue_date->format('Y-m'), 'title' => fn ($r) => $r->issue_date->format('Y-m')],
            ],
            views: ['list', 'kanban'],
        );

        $query = $lv->applyFilters(
            Quotation::query()->with('client:id,business_name')
                ->join('v_quotation_totals as t', 't.quotation_id', '=', 'quotations.id')
                ->select('quotations.*', 't.net_before_vat')
                ->orderByDesc('quotations.id')
        );
        if ($lv->q !== '') {
            $search = $lv->q;
            $query->where(fn ($q) => $q->where('quotation_no', 'ilike', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c->where('business_name', 'ilike', "%{$search}%")));
        }

        if ($lv->view === 'kanban') {
            $all = $query->limit(500)->get();
            $columns = collect(['DRAFT', 'SENT', 'APPROVED', 'REJECTED', 'EXPIRED', 'CANCELLED'])
                ->mapWithKeys(fn ($st) => [$st => $all->where('status', $st)->values()])
                ->filter(fn ($items, $st) => in_array($st, ['DRAFT', 'SENT', 'APPROVED'], true) || $items->isNotEmpty());

            return view('quotations.index', ['lv' => $lv, 'columns' => $columns, 'quotations' => null, 'groups' => null]);
        }

        return view('quotations.index', [
            'lv' => $lv,
            'columns' => null,
            'quotations' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        return view('quotations.form', [
            'quotation' => new Quotation(['client_id' => $request->query('client_id'), 'discount_amount' => 0]),
            'lines' => [],
            'clients' => Client::orderBy('business_name')->get(['id', 'business_name', 'client_no']),
            'vatRates' => VatRate::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $quotation = DB::transaction(function () use ($data, $request) {
            $quotation = new Quotation(collect($data)->except('lines')->all());
            $quotation->created_by = $request->user()->id;
            $quotation->save();
            $this->writeLines($quotation, $data['lines']);

            return $quotation;
        });

        return redirect()->route('quotations.show', $quotation)->with('ok', __('تم حفظ عرض السعر كمسودة.'));
    }

    public function show(Quotation $quotation): View
    {
        return view('quotations.show', [
            'q' => $quotation->load('lines.studioAsset', 'client', 'project'),
            'totals' => $quotation->totals(),
            'estimates' => DB::table('cost_estimates as e')->leftJoin('v_estimate_costs as c', 'c.estimate_id', '=', 'e.id')
                ->leftJoin('cost_estimate_snapshots as s', 's.estimate_id', '=', 'e.id')->where('e.quotation_id', $quotation->id)
                ->get(['e.line_no', 'c.missing', 'c.warnings', 'c.manufacturing_cost', 'c.fully_loaded_cost', 'c.gross_margin_pct', 's.id as snapshot_id',
                    's.manufacturing_cost as frozen_cost', 's.gross_margin_pct as frozen_margin'])->keyBy('line_no'),
            'syncLog' => DaftraSyncLog::where(['entity_type' => 'QUOTATION', 'entity_id' => $quotation->id])->orderByDesc('id')->get(),
            'approver' => $quotation->approved_by ? DB::table('users')->where('id', $quotation->approved_by)->value('name') : null,
            'activity' => ActivityLog::for([
                'quotations' => [$quotation->id],
                'quotation_lines' => DB::table('audit_log')->where('table_name', 'quotation_lines')
                    ->whereRaw("(coalesce(new_data, old_data)->>'quotation_id')::bigint = ?", [$quotation->id])
                    ->pluck('row_id')->all(),
            ]),
        ]);
    }

    /** The quotation on the official letterhead (A4). Costs never appear here. */
    public function print(Quotation $quotation): View
    {
        return view('quotations.print', ['q' => $quotation->load('lines.studioAsset', 'client'), 'totals' => $quotation->totals()]);
    }

    public function edit(Quotation $quotation): View|RedirectResponse
    {
        if ($quotation->status !== 'DRAFT') {
            return redirect()->route('quotations.show', $quotation)->withErrors(['rule' => __('rroka.errors.RROKA_QUOTATION_LOCKED')]);
        }

        return view('quotations.form', [
            'quotation' => $quotation,
            'lines' => $quotation->lines->map->only('description', 'quantity', 'unit', 'unit_price', 'studio_asset_id')->all(),
            'clients' => Client::orderBy('business_name')->get(['id', 'business_name', 'client_no']),
            'vatRates' => VatRate::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($quotation, $data) {
            // Lines first: a customer's photo on an old line must not block changing the customer.
            $quotation->lines()->delete();
            $quotation->update(collect($data)->except('lines')->all());
            $this->writeLines($quotation, $data['lines']);
            $quotation->dropOrphanEstimates();
        });

        return redirect()->route('quotations.show', $quotation)->with('ok', __('تم تحديث عرض السعر.'));
    }

    public function transition(Request $request, Quotation $quotation, string $action): RedirectResponse
    {
        $map = [
            'send' => ['SENT', 'quotations.manage', __('تم تسجيل إرسال العرض للعميل.')],
            'revise' => ['DRAFT', 'quotations.manage', __('أُعيد العرض إلى مسودة للتعديل.')],
            'approve' => ['APPROVED', 'quotations.approve', __('تم اعتماد العرض.')],
            'reject' => ['REJECTED', 'quotations.approve', __('تم تسجيل رفض العميل.')],
            'cancel' => ['CANCELLED', 'quotations.manage', __('تم إلغاء العرض.')],
        ];
        abort_unless(isset($map[$action]), 404);
        [$status, $permission, $message] = $map[$action];
        abort_unless($request->user()->hasPermission($permission), 403, __('ليست لديك صلاحية لهذه العملية.'));

        $changes = ['status' => $status];
        if ($status === 'APPROVED') {
            $changes += ['approved_at' => now(), 'approved_by' => $request->user()->id];
        }
        $quotation->forceFill($changes)->save();

        return back()->with('ok', $message);
    }

    public function sync(Request $request, Quotation $quotation, DaftraSyncService $sync): RedirectResponse
    {
        $sync->syncQuotation($quotation->load('lines', 'client'), $request->user());

        return back()->with('ok', __('تم إنشاء عرض السعر في دفترة.'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'vat_rate_id' => ['nullable', 'integer', Rule::exists('vat_rates', 'id')->where('is_active', true)],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['required', 'string', 'max:30'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.studio_asset_id' => ['nullable', 'integer', 'exists:studio_assets,id'],
        ]);
        $data['lines'] = array_values($data['lines']);

        return $data;
    }

    private function writeLines(Quotation $quotation, array $lines): void
    {
        foreach ($lines as $i => $line) {
            $quotation->lines()->create($line + ['line_no' => $i + 1]);
        }
    }
}
