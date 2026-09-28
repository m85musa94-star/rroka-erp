<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Role names are the owner's decision; the system only supplies the permission catalogue. */
class RoleController extends Controller
{
    public function index(): View
    {
        return view('roles.index', ['roles' => Role::withCount(['permissions', 'users'])->orderBy('name_ar')->get()]);
    }

    public function create(): View
    {
        return view('roles.form', ['role' => new Role, 'groups' => $this->groupedPermissions(), 'selected' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // The technical code is generated; the owner only names the role in Arabic.
        $data['code'] = 'role_'.(((int) Role::max('id')) + 1).'_'.strtolower(Str::random(4));
        $role = Role::create($data);
        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('roles.index')->with('ok', 'تم إنشاء الدور.');
    }

    public function edit(Role $role): View
    {
        return view('roles.form', [
            'role' => $role,
            'groups' => $this->groupedPermissions(),
            'selected' => $role->permissions()->pluck('permissions.id')->all(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);
        $role->update($data);
        $role->permissions()->sync($role->code === 'system_admin' ? Permission::pluck('id') : ($data['permissions'] ?? []));

        return redirect()->route('roles.index')->with('ok', 'تم تحديث الدور.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->code === 'system_admin') {
            return back()->withErrors(['role' => 'لا يمكن حذف دور مدير النظام.']);
        }
        if ($role->users()->exists()) {
            return back()->withErrors(['role' => 'لا يمكن حذف دور مسنَد إلى مستخدمين. أزله عنهم أولًا من صفحة المستخدمين.']);
        }
        $role->permissions()->detach();
        $role->delete();

        return redirect()->route('roles.index')->with('ok', 'تم حذف الدور.');
    }

    /** Permissions grouped by module prefix, with Arabic group titles. */
    private function groupedPermissions(): array
    {
        return Permission::orderBy('id')->get()
            ->groupBy(fn ($p) => Str::before($p->code, '.'))
            ->map(fn ($items, $key) => ['label' => __("rroka.permission_groups.$key"), 'items' => $items])
            ->values()->all();
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:100', Rule::unique('roles', 'name_ar')->ignore($role?->id)],
            'description' => ['nullable', 'string'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);
    }
}
