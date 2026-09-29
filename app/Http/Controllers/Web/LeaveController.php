<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Time off (Odoo Time Off style). An employee with a linked user requests their
 * own leave; approvers (hr.leave_approve) decide. Self-approval, overlaps and
 * balance limits are refused by the database.
 */
class LeaveController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $approver = $user->hasPermission('hr.leave_approve');
        $me = Employee::where('user_id', $user->id)->first();
        abort_unless($approver || $me || $user->hasPermission('hr.manage'), 403, __('ليست لديك صلاحية لهذه الصفحة أو العملية.'));

        $st = fn (string $s) => fn ($q) => $q->where('status', $s);
        $lv = new ListView($request,
            filters: [
                'to_approve' => ['label' => __('بانتظار الاعتماد'), 'group' => 'st', 'apply' => $st('SUBMITTED')],
                'approved' => ['label' => __('معتمدة'), 'group' => 'st', 'apply' => $st('APPROVED')],
                'refused' => ['label' => __('مرفوضة'), 'group' => 'st', 'apply' => $st('REFUSED')],
                'current' => ['label' => __('جارية الآن أو قادمة'), 'group' => 'when', 'apply' => fn ($q) => $q->whereDate('date_to', '>=', today())],
                'mine' => ['label' => __('طلباتي'), 'group' => 'who', 'apply' => fn ($q) => $q->where('employee_id', $me?->id ?? 0)],
            ],
            groups: [
                'employee' => ['label' => __('الموظف'), 'key' => fn ($r) => $r->employee_id, 'title' => fn ($r) => $r->employee->name],
                'type' => ['label' => __('نوع الإجازة'), 'key' => fn ($r) => $r->leave_type_id, 'title' => fn ($r) => $r->type->name],
                'status' => ['label' => __('الحالة'), 'key' => fn ($r) => $r->status, 'title' => fn ($r) => __("rroka.status.$r->status")],
            ],
        );
        $query = $lv->applyFilters(LeaveRequest::with('employee:id,name', 'type:id,name')->orderByDesc('date_from'))
            // Without approval rights you only see your own requests.
            ->when(! $approver && ! $user->hasPermission('hr.manage'), fn ($q) => $q->where('employee_id', $me?->id ?? 0));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->whereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$s}%"));
        }

        return view('hr.leaves.index', [
            'lv' => $lv, 'approver' => $approver, 'me' => $me,
            'requests' => $lv->group ? null : $query->paginate(30)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
            'balances' => $me ? $this->balances($me) : collect(),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $me = Employee::where('user_id', $user->id)->first();
        $forOthers = $user->hasPermission('hr.leave_approve') || $user->hasPermission('hr.manage');
        abort_unless($me || $forOthers, 403, __('ليست لديك صلاحية لهذه الصفحة أو العملية.'));

        return view('hr.leaves.form', [
            'employees' => $forOthers ? Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect([$me]),
            'types' => LeaveType::where('is_active', true)->orderBy('name')->get(),
            'selected' => $request->integer('employee_id') ?: $me?->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('is_active', true)],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'days' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $own = Employee::where('id', $data['employee_id'])->value('user_id') === $user->id;
        abort_unless($own || $user->hasPermission('hr.leave_approve') || $user->hasPermission('hr.manage'), 403, __('ليست لديك صلاحية لهذه العملية.'));

        $r = new LeaveRequest($data);
        $r->created_by = $user->id;
        $r->save();

        return redirect()->route('leaves.index')->with('ok', __('أُرسل طلب الإجازة للاعتماد.'));
    }

    public function decide(Request $request, LeaveRequest $leave, string $action): RedirectResponse
    {
        $user = $request->user();
        if ($action === 'cancel') {
            // The employee may withdraw a pending request; approved ones are cancelled by an approver.
            $own = $leave->employee->user_id === $user->id && $leave->status === 'SUBMITTED';
            abort_unless($own || $user->hasPermission('hr.leave_approve'), 403, __('ليست لديك صلاحية لهذه العملية.'));
            $leave->forceFill(['status' => 'CANCELLED'])->save();

            return back()->with('ok', __('أُلغي طلب الإجازة.'));
        }
        abort_unless($user->hasPermission('hr.leave_approve'), 403, __('ليست لديك صلاحية لهذه العملية.'));
        if ($action === 'approve') {
            $leave->forceFill(['status' => 'APPROVED', 'approved_by' => $user->id, 'approved_at' => now()])->save();

            return back()->with('ok', __('اعتُمد طلب الإجازة.'));
        }
        abort_unless($action === 'refuse', 404);
        $leave->forceFill(['status' => 'REFUSED', 'approved_by' => $user->id, 'approved_at' => now(),
            'refusal_reason' => $request->validate(['refusal_reason' => ['required', 'string', 'max:500']])['refusal_reason']])->save();

        return back()->with('ok', __('رُفض طلب الإجازة.'));
    }

    /** Leave types and allocations (approvers). */
    public function settings(): View
    {
        return view('hr.leaves.settings', [
            'types' => LeaveType::orderBy('name')->get(),
            'allocations' => LeaveAllocation::with('employee:id,name', 'type:id,name', 'approver:id,name')->orderByDesc('id')->limit(200)->get(),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function typeStore(Request $request): RedirectResponse
    {
        LeaveType::create($request->validate(['name' => ['required', 'string', 'max:120', 'unique:leave_types,name']])
            + ['is_paid' => $request->boolean('is_paid'), 'requires_allocation' => $request->boolean('requires_allocation')]);

        return back()->with('ok', __('تمت إضافة نوع الإجازة.'));
    }

    public function typeUpdate(Request $request, LeaveType $type): RedirectResponse
    {
        $type->update(['is_active' => $request->boolean('is_active')]);

        return back()->with('ok', __('تم تحديث نوع الإجازة.'));
    }

    public function allocationStore(Request $request): RedirectResponse
    {
        $a = new LeaveAllocation($request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('is_active', true)],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'days' => ['required', 'numeric', 'not_in:0', 'min:-365', 'max:365'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after_or_equal:valid_from'],
            'reason' => ['required', 'string', 'max:500'],
        ]));
        $a->approved_by = $request->user()->id;
        $a->save();

        return back()->with('ok', __('تم منح الرصيد.'));
    }

    /** Remaining balance today per allocation-based type. */
    private function balances(Employee $e): Collection
    {
        return LeaveType::where('requires_allocation', true)->where('is_active', true)->orderBy('name')->get()
            ->map(fn ($t) => (object) ['type' => $t, 'balance' => (float) DB::scalar('select fn_leave_balance(?, ?, ?)', [$e->id, $t->id, today()->toDateString()])]);
    }
}
