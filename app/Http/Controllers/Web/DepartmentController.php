<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobPosition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Departments and job positions: small lists edited in place. */
class DepartmentController extends Controller
{
    public function index(): View
    {
        return view('hr.departments', [
            'departments' => Department::with('parent:id,name', 'manager:id,name')->withCount('employees')->orderBy('name')->get(),
            'jobs' => JobPosition::with('department:id,name')->withCount('employees')->orderBy('name')->get(),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Department::create($this->validated($request));

        return back()->with('ok', __('تمت إضافة القسم.'));
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $department->update($this->validated($request, $department));

        return back()->with('ok', __('تم تحديث القسم.'));
    }

    public function jobStore(Request $request): RedirectResponse
    {
        JobPosition::create($this->jobValidated($request));

        return back()->with('ok', __('تمت إضافة المسمى الوظيفي.'));
    }

    public function jobUpdate(Request $request, JobPosition $job): RedirectResponse
    {
        $job->update($this->jobValidated($request, $job));

        return back()->with('ok', __('تم تحديث المسمى الوظيفي.'));
    }

    private function validated(Request $request, ?Department $d = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')->ignore($d?->id)],
            'parent_id' => ['nullable', 'integer', 'exists:departments,id', Rule::notIn(array_filter([$d?->id]))],
            'manager_id' => ['nullable', 'integer', 'exists:workers,id'],
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }

    private function jobValidated(Request $request, ?JobPosition $j = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('job_positions', 'name')->ignore($j?->id)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }
}
