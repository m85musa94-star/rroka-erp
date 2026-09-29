<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Employment contracts (hr.contracts only: salaries are confidential). */
class ContractController extends Controller
{
    public function index(Request $request): View
    {
        $st = fn (string $s) => fn ($q) => $q->where('status', $s);
        $lv = new ListView($request,
            filters: [
                'running' => ['label' => __('ساري'), 'group' => 'st', 'apply' => $st('RUNNING')],
                'draft' => ['label' => __('مسودة'), 'group' => 'st', 'apply' => $st('DRAFT')],
                'expired' => ['label' => __('منتهٍ'), 'group' => 'st', 'apply' => $st('EXPIRED')],
                'ending' => ['label' => __('ينتهي قريبًا'), 'group' => 'end', 'apply' => fn ($q) => $q->where('status', 'RUNNING')->whereNotNull('end_date')->where('end_date', '<=', today()->addDays(EmployeeContract::WARN_DAYS))],
            ],
            groups: [
                'status' => ['label' => __('الحالة'), 'key' => fn ($c) => $c->status, 'title' => fn ($c) => __("rroka.status.$c->status")],
                'type' => ['label' => __('نوع العقد'), 'key' => fn ($c) => $c->contract_type, 'title' => fn ($c) => __("rroka.contract_type.$c->contract_type")],
            ],
        );
        $query = $lv->applyFilters(EmployeeContract::with('employee:id,name,employee_no')->orderByDesc('start_date'));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('contract_no', 'ilike', "%{$s}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$s}%")));
        }

        return view('hr.contracts.index', [
            'lv' => $lv,
            'contracts' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
        ]);
    }

    public function create(Request $request): View
    {
        return view('hr.contracts.form', [
            'c' => new EmployeeContract(['employee_id' => $request->integer('employee_id') ?: null, 'contract_type' => 'FIXED_TERM']),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_no']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $c = new EmployeeContract($this->validated($request));
        $c->created_by = $request->user()->id;
        $c->save();

        return redirect()->route('contracts.show', $c)->with('ok', __('تم حفظ العقد كمسودة.'));
    }

    public function show(EmployeeContract $contract): View
    {
        return view('hr.contracts.show', [
            'c' => $contract->load('employee'),
            'activity' => ActivityLog::for(['employee_contracts' => [$contract->id]]),
        ]);
    }

    public function edit(EmployeeContract $contract): View|RedirectResponse
    {
        if ($contract->status !== 'DRAFT') {
            return redirect()->route('contracts.show', $contract)->withErrors(['rule' => __('rroka.errors.RROKA_CONTRACT_LOCKED')]);
        }

        return view('hr.contracts.form', ['c' => $contract, 'employees' => Employee::where('id', $contract->employee_id)->get(['id', 'name', 'employee_no'])]);
    }

    public function update(Request $request, EmployeeContract $contract): RedirectResponse
    {
        $contract->update(collect($this->validated($request))->except('employee_id')->all());

        return redirect()->route('contracts.show', $contract)->with('ok', __('تم تحديث العقد.'));
    }

    /** start (DRAFT → RUNNING), close (RUNNING → EXPIRED with its end date), cancel. */
    public function transition(Request $request, EmployeeContract $contract, string $action): RedirectResponse
    {
        $changes = match ($action) {
            'start' => ['status' => 'RUNNING'],
            'close' => ['status' => 'EXPIRED', 'end_date' => $request->validate(['end_date' => ['required', 'date', 'after_or_equal:'.$contract->start_date->toDateString()]])['end_date']],
            'cancel' => ['status' => 'CANCELLED'],
            default => abort(404),
        };
        $contract->forceFill($changes)->save();

        return back()->with('ok', __('العقد الآن: ').__("rroka.status.{$changes['status']}"));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('is_active', true)],
            'contract_type' => ['required', Rule::in(['FIXED_TERM', 'INDEFINITE'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'required_if:contract_type,FIXED_TERM'],
            'basic_salary' => ['required', 'numeric', 'gt:0'],
            'housing_allowance' => ['required', 'numeric', 'min:0'],
            'transport_allowance' => ['required', 'numeric', 'min:0'],
            'other_allowance' => ['required', 'numeric', 'min:0'],
            'weekly_hours' => ['nullable', 'numeric', 'gt:0', 'max:168'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
