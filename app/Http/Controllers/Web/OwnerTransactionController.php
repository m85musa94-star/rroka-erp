<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OwnerTransaction;
use App\Models\PaymentAccount;
use App\Services\StudioStorage;
use App\Support\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Owner funding and withdrawals: the only way (with expenses paid by the owner and the
 * opening entry) the owner current account moves. Approval posts the entry.
 */
class OwnerTransactionController extends Controller
{
    /** The owner's statement (approved documents, running balance) and the documents waiting. */
    public function index(): View
    {
        $lines = DB::table('v_owner_statement')->orderBy('line_date')->orderBy('doc_no')->get();
        $run = 0.0;
        foreach ($lines as $l) {
            $run += (float) $l->credit - (float) $l->debit;
            $l->balance = $run;
        }

        return view('accounting.owner.index', [
            'lines' => $lines,
            'totals' => [
                'funding' => (float) $lines->where('kind', 'FUNDING')->sum('credit'),
                'paid' => (float) $lines->where('kind', 'PAID_EXPENSE')->sum('credit'),
                'withdrawn' => (float) $lines->where('kind', 'WITHDRAWAL')->sum('debit'),
                'balance' => $run,
            ],
            'pending' => OwnerTransaction::with('paymentAccount:id,name')->where('status', 'DRAFT')->orderBy('txn_date')->get(),
            'cancelled' => OwnerTransaction::where('status', 'CANCELLED')->count(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('accounting.owner.form', ['x' => new OwnerTransaction(['txn_date' => today(),
            'kind' => $request->query('kind') === 'WITHDRAWAL' ? 'WITHDRAWAL' : 'FUNDING']), ...$this->choices()]);
    }

    public function store(Request $request, StudioStorage $storage): RedirectResponse
    {
        $x = new OwnerTransaction;
        $x->created_by = $request->user()->id;
        $this->save($request, $x, $storage);

        return redirect()->route('accounting.owner.show', $x)->with('ok', __('حُفظت حركة المالك كمسودة.'));
    }

    public function show(OwnerTransaction $owner): View
    {
        return view('accounting.owner.show', [
            'x' => $owner->load('paymentAccount', 'attachment'),
            'names' => DB::table('users')->whereIn('id', array_filter([$owner->created_by, $owner->approved_by]))->pluck('name', 'id'),
            'activity' => ActivityLog::for(['owner_transactions' => [$owner->id]]),
        ]);
    }

    public function edit(OwnerTransaction $owner): View|RedirectResponse
    {
        if ($owner->status !== 'DRAFT') {
            return redirect()->route('accounting.owner.show', $owner)->withErrors(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }

        return view('accounting.owner.form', ['x' => $owner, ...$this->choices()]);
    }

    public function update(Request $request, OwnerTransaction $owner, StudioStorage $storage): RedirectResponse
    {
        $this->save($request, $owner, $storage);

        return redirect()->route('accounting.owner.show', $owner)->with('ok', __('حُدّثت حركة المالك.'));
    }

    public function approve(Request $request, OwnerTransaction $owner): RedirectResponse
    {
        $this->draftOrFail($owner);
        $owner->forceFill(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()])->save();

        return back()->with('ok', __('اعتُمدت حركة المالك.'));
    }

    public function cancel(OwnerTransaction $owner): RedirectResponse
    {
        $this->draftOrFail($owner);
        $owner->forceFill(['status' => 'CANCELLED'])->save();

        return back()->with('ok', __('أُلغيت المسودة.'));
    }

    private function save(Request $request, OwnerTransaction $x, StudioStorage $storage): void
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(OwnerTransaction::KINDS)],
            'txn_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_account_id' => ['required', 'integer', Rule::exists('payment_accounts', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'document' => ['nullable', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);
        unset($data['document']);

        DB::transaction(function () use ($x, $data, $request, $storage) {
            $x->fill($data)->save();
            if ($request->hasFile('document')) {
                [$asset] = $storage->storeDocument($request->file('document'), __('حركة المالك').' '.$x->txn_no, $request->user()->id);
                $x->forceFill(['attachment_id' => $asset->id])->save();
            }
        });
    }

    private function choices(): array
    {
        return [
            'accounts' => PaymentAccount::where('is_active', true)->orderBy('kind')->orderBy('name')->get(['id', 'name', 'kind']),
            'canAttach' => StudioStorage::isReady(),
        ];
    }

    private function draftOrFail(OwnerTransaction $doc): void
    {
        if ($doc->status !== 'DRAFT') {
            throw ValidationException::withMessages(['rule' => __('rroka.errors.RROKA_SALES_LOCKED')]);
        }
    }
}
