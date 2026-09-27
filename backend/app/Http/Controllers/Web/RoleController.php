<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Role names are the owner's decision; the system only supplies the permission catalogue. */
class RoleController extends Controller
{
    public function index(): View
    {
        return view('roles.index', ['roles' => Role::withCount('permissions')->orderBy('name_ar')->get()]);
    }

    public function create(): View
    {
        return view('roles.form', ['role' => new Role, 'permissions' => Permission::orderBy('id')->get(), 'selected' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $role = Role::create($data);
        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('roles.index')->with('ok', 'تم إنشاء الدور.');
    }

    public function edit(Role $role): View
    {
        return view('roles.form', [
            'role' => $role,
            'permissions' => Permission::orderBy('id')->get(),
            'selected' => $role->permissions()->pluck('permissions.id')->all(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);

        if ($role->code === 'system_admin' && $data['code'] !== 'system_admin') {
            return back()->withErrors(['code' => 'لا يمكن تغيير رمز دور مدير النظام.']);
        }
        $role->update($data);
        $role->permissions()->sync($role->code === 'system_admin' ? Permission::pluck('id') : ($data['permissions'] ?? []));

        return redirect()->route('roles.index')->with('ok', 'تم تحديث الدور.');
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'code' => ['required', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'code')->ignore($role?->id)],
            'name_ar' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);
    }
}
