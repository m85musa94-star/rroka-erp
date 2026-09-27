<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DaftraSyncLog;
use App\Models\Quotation;
use App\Services\Daftra\DaftraSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class QuotationController extends Controller
{
    public function index(Request $request): View
    {
        $query = Quotation::with('client:id,business_name')->orderByDesc('id');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        $quotations = $query->paginate(30)->withQueryString();
        $totals = DB::table('v_quotation_totals')->whereIn('quotation_id', $quotations->pluck('id'))
            ->pluck('net_before_vat', 'quotation_id');

        return view('quotations.index', compact('quotations', 'totals'));
    }

    public function create(Request $request): View
    {
        return view('quotations.form', [
            'quotation' => new Quotation(['client_id' => $request->query('client_id'), 'discount_amount' => 0]),
            'lines' => [],
            'clients' => Client::orderBy('business_name')->get(['id', 'business_name', 'client_no']),
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

        return redirect()->route('quotations.show', $quotation)->with('ok', 'تم حفظ عرض السعر كمسودة.');
    }

    public function show(Quotation $quotation): View
    {
        return view('quotations.show', [
            'q' => $quotation->load('lines', 'client', 'project'),
            'totals' => $quotation->totals(),
            'syncLog' => DaftraSyncLog::where(['entity_type' => 'QUOTATION', 'entity_id' => $quotation->id])->orderByDesc('id')->get(),
            'approver' => $quotation->approved_by ? DB::table('users')->where('id', $quotation->approved_by)->value('name') : null,
        ]);
    }

    public function edit(Quotation $quotation): View|RedirectResponse
    {
        if ($quotation->status !== 'DRAFT') {
            return redirect()->route('quotations.show', $quotation)->withErrors(['rule' => __('rroka.errors.RROKA_QUOTATION_LOCKED')]);
        }

        return view('quotations.form', [
            'quotation' => $quotation,
            'lines' => $quotation->lines->map->only('description', 'quantity', 'unit', 'unit_price')->all(),
            'clients' => Client::orderBy('business_name')->get(['id', 'business_name', 'client_no']),
        ]);
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($quotation, $data) {
            $quotation->update(collect($data)->except('lines')->all());
            $quotation->lines()->delete();
            $this->writeLines($quotation, $data['lines']);
        });

        return redirect()->route('quotations.show', $quotation)->with('ok', 'تم تحديث عرض السعر.');
    }

    public function transition(Request $request, Quotation $quotation, string $action): RedirectResponse
    {
        $map = [
            'send' => ['SENT', 'quotations.manage', 'تم تسجيل إرسال العرض للعميل.'],
            'revise' => ['DRAFT', 'quotations.manage', 'أُعيد العرض إلى مسودة للتعديل.'],
            'approve' => ['APPROVED', 'quotations.approve', 'تم اعتماد العرض.'],
            'reject' => ['REJECTED', 'quotations.approve', 'تم تسجيل رفض العميل.'],
            'cancel' => ['CANCELLED', 'quotations.manage', 'تم إلغاء العرض.'],
        ];
        abort_unless(isset($map[$action]), 404);
        [$status, $permission, $message] = $map[$action];
        abort_unless($request->user()->hasPermission($permission), 403, 'ليست لديك صلاحية لهذه العملية.');

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

        return back()->with('ok', 'تم إنشاء عرض السعر في دفترة.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['required', 'string', 'max:30'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
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
