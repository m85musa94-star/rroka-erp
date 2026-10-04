<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Models\TreasuryTransfer;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Money moving between accounts: issuing custody to an employee, the employee
 * returning what is left, depositing cash at the bank. Approval is final; the
 * custody limit and balance are enforced by the database.
 */
class TreasuryTransferController extends Controller
{
    public function index(Request $request): View
    {
        $st = fn (string $s) => fn ($q) => $q->where('treasury_transfers.status', $s);
        $custody = fn (string $side) => fn ($q) => $q->whereHas($side, fn ($a) => $a->where('kind', 'CUSTODY'));
        $lv = new ListView($request,
            filters: [
                'draft' => ['label' => __('مسودة'), 'group' => 'st', 'apply' => $st('DRAFT')],
                'approved' => ['label' => __('معتمدة'), 'group' => 'st', 'apply' => $st('APPROVED')],
                'issue' => ['label' => __('صرف عهدة'), 'group' => 'p', 'apply' => $custody('to')],
                'return' => ['label' => __('إرجاع عهدة'), 'group' => 'p', 'apply' => $custody('from')],
                'self' => ['label' => __('اعتماد ذاتي (للمراجعة)'), 'group' => 'x', 'apply' => fn ($q) => $q->where('status', 'APPROVED')->whereColumn('approved_by', 'created_by')],
                'not_synced' => ['label' => __('لم تُرسل لدفترة'), 'group' => 'sync', 'apply' => fn ($q) => $q->where('status', 'APPROVED')->whereNull('daftra_transfer_id')],
                'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'apply' => fn ($q) => $q->where('transfer_date', '>=', now()->startOfMonth())],
            ],
            groups: [
                'from' => ['label' => __('من'), 'key' => fn ($t) => $t->from_account_id, 'title' => fn ($t) => $t->from->name],
                'to' => ['label' => __('إلى'), 'key' => fn ($t) => $t->to_account_id, 'title' => fn ($t) => $t->to->name],
                'month' => ['label' => __('الشهر'), 'key' => fn ($t) => $t->transfer_date->format('Y-m'), 'title' => fn ($t) => $t->transfer_date->format('Y-m')],
            ],
            keep: ['account_id'],
        );
        $query = $lv->applyFilters(TreasuryTransfer::with('from:id,name,kind', 'to:id,name,kind')->orderByDesc('transfer_date')->orderByDesc('id'))
            ->when($request->integer('account_id'), fn ($q, $id) => $q->where(fn ($w) => $w->where('from_account_id', $id)->orWhere('to_account_id', $id)));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('transfer_no', 'ilike', "%{$s}%")->orWhere('reference', 'ilike', "%{$s}%")->orWhere('notes', 'ilike', "%{$s}%"));
        }

        return view('treasury.transfers.index', [
            'lv' => $lv,
            'transfers' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(2000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        return view('treasury.transfers.form', [
            't' => new TreasuryTransfer(['transfer_date' => today(), 'from_account_id' => $request->integer('from') ?: null, 'to_account_id' => $request->integer('to') ?: null]),
            'accounts' => $this->accounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $t = new TreasuryTransfer($this->validated($request));
        $t->created_by = $request->user()->id;
        $t->save();

        return redirect()->route('treasury.transfers.show', $t)->with('ok', __('حُفظ التحويل كمسودة.'));
    }

    public function show(TreasuryTransfer $transfer): View
    {
        $transfer->load('from.employee', 'to.employee', 'from.summary', 'to.summary');

        return view('treasury.transfers.show', [
            't' => $transfer,
            'names' => DB::table('users')->whereIn('id', array_filter([$transfer->created_by, $transfer->approved_by]))->pluck('name', 'id'),
            'activity' => ActivityLog::for(['treasury_transfers' => [$transfer->id]]),
        ]);
    }

    public function edit(TreasuryTransfer $transfer): View|RedirectResponse
    {
        if ($transfer->status !== 'DRAFT') {
            return redirect()->route('treasury.transfers.show', $transfer)->withErrors(['rule' => __('rroka.errors.RROKA_TRANSFER_LOCKED')]);
        }

        return view('treasury.transfers.form', ['t' => $transfer, 'accounts' => $this->accounts()]);
    }

    public function update(Request $request, TreasuryTransfer $transfer): RedirectResponse
    {
        $transfer->update($this->validated($request));

        return redirect()->route('treasury.transfers.show', $transfer)->with('ok', __('حُدّث التحويل.'));
    }

    /** Self-approval is allowed (small team) and flagged for review. */
    public function approve(Request $request, TreasuryTransfer $transfer): RedirectResponse
    {
        $this->draftOrFail($transfer);
        $transfer->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();

        return back()->with('ok', __('اعتُمد التحويل.'));
    }

    public function cancel(TreasuryTransfer $transfer): RedirectResponse
    {
        $this->draftOrFail($transfer);
        $transfer->forceFill(['status' => 'CANCELLED'])->save();

        return back()->with('ok', __('أُلغيت المسودة.'));
    }

    private function validated(Request $request): array
    {
        $active = Rule::exists('payment_accounts', 'id')->where('is_active', true);

        return $request->validate([
            'transfer_date' => ['required', 'date', 'before_or_equal:today'],
            'from_account_id' => ['required', 'integer', $active],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', $active],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function accounts()
    {
        return PaymentAccount::with('summary')->where('is_active', true)->orderBy('kind')->orderBy('name')->get();
    }

    private function draftOrFail(TreasuryTransfer $t): void
    {
        if ($t->status !== 'DRAFT') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_TRANSFER_LOCKED')]);
        }
    }
}
