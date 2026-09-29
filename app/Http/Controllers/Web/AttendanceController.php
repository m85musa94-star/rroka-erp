<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\ListView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Attendance (Odoo Attendances style): today's board with check-in/out, the
 * record list, and manual corrections. Overlaps, open records, future times
 * and leave days are refused by the database.
 */
class AttendanceController extends Controller
{
    public function board(): View
    {
        $today = today();
        $employees = Employee::where('is_active', true)->with('job:id,name')->orderBy('name')->get();
        $open = Attendance::whereNull('check_out')->get()->keyBy('employee_id');
        $todayRows = Attendance::where('check_in', '>=', $today)->get()->groupBy('employee_id');
        $onLeave = LeaveRequest::where('status', 'APPROVED')->whereDate('date_from', '<=', $today)->whereDate('date_to', '>=', $today)
            ->pluck('employee_id')->flip();

        return view('hr.attendance.board', compact('employees', 'open', 'todayRows', 'onLeave'));
    }

    public function toggle(Request $request, Employee $employee): RedirectResponse
    {
        $open = Attendance::where('employee_id', $employee->id)->whereNull('check_out')->first();
        if ($open) {
            $open->update(['check_out' => now()]);

            return back()->with('ok', __('سُجّل انصراف :name.', ['name' => $employee->name]));
        }
        $a = new Attendance(['employee_id' => $employee->id, 'check_in' => now()]);
        $a->created_by = $request->user()->id;
        $a->save();

        return back()->with('ok', __('سُجّل حضور :name.', ['name' => $employee->name]));
    }

    public function index(Request $request): View
    {
        $lv = new ListView($request,
            filters: [
                'open' => ['label' => __('لم ينصرف بعد'), 'group' => 'st', 'apply' => fn ($q) => $q->whereNull('check_out')],
                'today' => ['label' => __('اليوم'), 'group' => 'date', 'apply' => fn ($q) => $q->where('check_in', '>=', today())],
                'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'apply' => fn ($q) => $q->where('check_in', '>=', now()->startOfMonth())],
                'last_month' => ['label' => __('الشهر الماضي'), 'group' => 'date', 'apply' => fn ($q) => $q->whereBetween('check_in', [now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()])],
            ],
            groups: [
                'employee' => ['label' => __('الموظف'), 'key' => fn ($a) => $a->employee_id, 'title' => fn ($a) => $a->employee->name],
                'day' => ['label' => __('اليوم'), 'key' => fn ($a) => $a->check_in->toDateString(), 'title' => fn ($a) => $a->check_in->toDateString()],
            ],
        );
        $query = $lv->applyFilters(Attendance::with('employee:id,name')->orderByDesc('check_in'));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->whereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$s}%"));
        }

        return view('hr.attendance.index', [
            'lv' => $lv,
            'rows' => $lv->group ? null : $query->paginate(50)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(3000)->get()) : null,
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Manual record or correction (forgotten check-out, etc.) — audited. */
    public function store(Request $request): RedirectResponse
    {
        $a = new Attendance($this->validated($request));
        $a->created_by = $request->user()->id;
        $a->save();

        return back()->with('ok', __('تم تسجيل الحضور.'));
    }

    public function update(Request $request, Attendance $attendance): RedirectResponse
    {
        $attendance->update(collect($this->validated($request))->except('employee_id')->all());

        return back()->with('ok', __('تم تصحيح سجل الحضور.'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('is_active', true)],
            'check_in' => ['required', 'date'],
            'check_out' => ['nullable', 'date', 'after:check_in'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $tz = config('app.timezone');
        $data['check_in'] = Carbon::parse($data['check_in'], $tz);
        $data['check_out'] = isset($data['check_out']) ? Carbon::parse($data['check_out'], $tz) : null;

        return $data;
    }
}
