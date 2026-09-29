<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\JobPosition;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Employees (Odoo Employees style). hr.view sees the directory; personal data,
 * documents and employment changes need hr.manage.
 */
class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $soon = today()->addDays(EmployeeDocument::WARN_DAYS);
        $lv = new ListView($request,
            filters: [
                'active' => ['label' => __('على رأس العمل'), 'group' => 'st', 'apply' => fn ($q) => $q->where('is_active', true)],
                'ended' => ['label' => __('انتهت خدمتهم'), 'group' => 'st', 'apply' => fn ($q) => $q->where('is_active', false)],
                'direct' => ['label' => __('عمالة مباشرة (إنتاج)'), 'group' => 'kind', 'apply' => fn ($q) => $q->where('is_direct_labor', true)],
                'indirect' => ['label' => __('إداريون ومساندون'), 'group' => 'kind', 'apply' => fn ($q) => $q->where('is_direct_labor', false)],
                'docs' => ['label' => __('وثائق منتهية أو قريبة الانتهاء'), 'group' => 'docs', 'apply' => fn ($q) => $q->whereHas('documents', fn ($d) => $d->whereNotNull('expiry_date')->where('expiry_date', '<=', $soon))],
            ],
            groups: [
                'department' => ['label' => __('القسم'), 'key' => fn ($e) => $e->department_id ?? 0, 'title' => fn ($e) => $e->department?->name ?? __('بلا قسم')],
                'job' => ['label' => __('المسمى الوظيفي'), 'key' => fn ($e) => $e->job_id ?? 0, 'title' => fn ($e) => $e->job?->name ?? __('بلا مسمى')],
                'manager' => ['label' => __('المدير المباشر'), 'key' => fn ($e) => $e->manager_id ?? 0, 'title' => fn ($e) => $e->manager?->name ?? __('بلا مدير')],
            ],
            views: ['kanban', 'list'],
            keep: ['department_id'],
        );
        if (! $request->has('f') && ! $request->has('q') && ! $request->has('g')) {
            $lv->active = ['active'];
        }
        $query = $lv->applyFilters(Employee::with('department:id,name', 'job:id,name', 'manager:id,name')->orderBy('name'))
            ->when($request->integer('department_id'), fn ($q, $id) => $q->where('department_id', $id));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$s}%")->orWhere('employee_no', 'ilike', "%{$s}%")
                ->orWhere('trade', 'ilike', "%{$s}%")->orWhere('work_phone', 'ilike', "%{$s}%")
                ->orWhereHas('job', fn ($j) => $j->where('name', 'ilike', "%{$s}%")));
        }

        return view('hr.employees.index', [
            'lv' => $lv,
            'employees' => $lv->group ? null : $query->paginate(48)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(2000)->get()) : null,
            'alerts' => $request->user()->hasPermission('hr.manage') ? $this->alerts() : null,
        ]);
    }

    public function create(): View
    {
        return view('hr.employees.form', ['e' => new Employee(['is_direct_labor' => true, 'employment_type' => 'FULL_TIME']), ...$this->choices()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $e = Employee::create($this->validated($request));

        return redirect()->route('employees.show', $e)->with('ok', __('تمت إضافة الموظف.'));
    }

    public function show(Request $request, Employee $employee): View
    {
        $full = $request->user()->hasPermission('hr.manage');

        $employee->load('department', 'job', 'manager', 'user', 'subordinates', 'contracts', 'leaveRequests.type', 'attendances');
        if ($full) {
            $employee->load('documents');
        }

        return view('hr.employees.show', [
            'e' => $employee,
            'full' => $full,
            'balances' => LeaveType::where('requires_allocation', true)->where('is_active', true)->orderBy('name')->get()
                ->map(fn ($t) => (object) ['type' => $t, 'balance' => (float) DB::scalar('select fn_leave_balance(?, ?, ?)', [$employee->id, $t->id, today()->toDateString()])]),
            'monthHours' => (float) $employee->attendances()->reorder()->where('check_in', '>=', now()->startOfMonth())->sum('worked_hours'),
            'activity' => $full ? ActivityLog::for([
                'workers' => [$employee->id],
                'employee_documents' => DB::table('audit_log')->where('table_name', 'employee_documents')
                    ->whereRaw("(coalesce(new_data, old_data)->>'employee_id')::bigint = ?", [$employee->id])->pluck('row_id')->all(),
            ]) : collect(),
        ]);
    }

    public function edit(Employee $employee): View
    {
        return view('hr.employees.form', ['e' => $employee, ...$this->choices($employee)]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $employee->update($this->validated($request, $employee));

        return redirect()->route('employees.show', $employee)->with('ok', __('تم تحديث بيانات الموظف.'));
    }

    /** End of service (date + reason are required by the database) or re-hire. */
    public function employment(Request $request, Employee $employee): RedirectResponse
    {
        if ($employee->is_active) {
            $data = $request->validate([
                'termination_date' => ['required', 'date'],
                'termination_reason' => ['required', 'string', 'max:500'],
            ]);
            $employee->forceFill($data + ['is_active' => false])->save();

            return back()->with('ok', __('سُجّل إنهاء خدمة الموظف.'));
        }
        $data = $request->validate(['hire_date' => ['required', 'date']]);
        $employee->forceFill(['is_active' => true, 'hire_date' => $data['hire_date'], 'termination_date' => null, 'termination_reason' => null])->save();

        return back()->with('ok', __('أُعيد الموظف إلى العمل.'));
    }

    public function documentStore(Request $request, Employee $employee): RedirectResponse
    {
        $employee->documents()->create($this->documentData($request));

        return back()->with('ok', __('تم حفظ الوثيقة.'));
    }

    public function documentUpdate(Request $request, EmployeeDocument $document): RedirectResponse
    {
        $document->update($this->documentData($request));

        return back()->with('ok', __('تم تحديث الوثيقة.'));
    }

    public function documentDestroy(EmployeeDocument $document): RedirectResponse
    {
        $document->delete();

        return back()->with('ok', __('تم حذف الوثيقة.'));
    }

    /** Expired or soon-expiring documents of active employees, soonest first. */
    public function alerts(): Collection
    {
        return EmployeeDocument::with('employee:id,name,employee_no')
            ->whereHas('employee', fn ($q) => $q->where('is_active', true))
            ->whereNotNull('expiry_date')->where('expiry_date', '<=', today()->addDays(EmployeeDocument::WARN_DAYS))
            ->orderBy('expiry_date')->limit(50)->get();
    }

    private function documentData(Request $request): array
    {
        return $request->validate([
            'doc_type' => ['required', Rule::in(EmployeeDocument::TYPES)],
            'doc_number' => ['nullable', 'string', 'max:60'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:500', 'required_if:doc_type,OTHER'],
        ]);
    }

    private function validated(Request $request, ?Employee $e = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'trade' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'job_id' => ['nullable', 'integer', 'exists:job_positions,id'],
            'manager_id' => ['nullable', 'integer', 'exists:workers,id', Rule::notIn(array_filter([$e?->id]))],
            'work_phone' => ['nullable', 'string', 'max:30'],
            'work_email' => ['nullable', 'email', 'max:200'],
            'employment_type' => ['nullable', Rule::in(['FULL_TIME', 'PART_TIME', 'CONTRACTOR'])],
            'hire_date' => ['nullable', 'date'],
            'is_direct_labor' => ['boolean'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:60'],
            'id_type' => ['nullable', Rule::in(['NATIONAL_ID', 'IQAMA', 'PASSPORT']), 'required_with:id_number'],
            'id_number' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['M', 'F'])],
            'iban' => ['nullable', 'string', 'max:40'],
            'emergency_contact' => ['nullable', 'string', 'max:200'],
            'emergency_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('workers', 'user_id')->ignore($e?->id)],
        ]);
        $data['is_direct_labor'] = $request->boolean('is_direct_labor');
        if (! empty($data['iban'])) {
            $data['iban'] = strtoupper(preg_replace('/\s+/', '', $data['iban']));
        }

        return $data;
    }

    private function choices(?Employee $e = null): array
    {
        return [
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'jobs' => JobPosition::where('is_active', true)->orderBy('name')->get(['id', 'name', 'department_id']),
            'managers' => Employee::where('is_active', true)->when($e, fn ($q) => $q->where('id', '<>', $e->id))->orderBy('name')->get(['id', 'name']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
        ];
    }
}
