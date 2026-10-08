<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::with('roles')->orderBy('name')->get()]);
    }

    /** One appearance for every user at once (each can still change their own afterwards). */
    public function themeAll(Request $request): RedirectResponse
    {
        $data = $request->validate(['theme' => ['required', Rule::in(Theme::MODES)]]);
        User::query()->update(['theme' => $data['theme']]);

        return back()->with('ok', __('طُبِّق المظهر على كل المستخدمين؛ يستطيع كل مستخدم تغييره لنفسه.'));
    }

    public function create(): View
    {
        return view('users.form', ['user' => new User, 'roles' => Role::orderBy('name_ar')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
            'theme' => ['nullable', Rule::in(Theme::MODES)],
            'roles' => ['array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ]);
        $user = User::create($data);
        $user->forceFill(['theme' => $data['theme'] ?? 'system'])->save();
        $user->roles()->sync($data['roles'] ?? []);

        return redirect()->route('users.index')->with('ok', __('تم إنشاء المستخدم.'));
    }

    public function edit(User $user): View
    {
        return view('users.form', ['user' => $user->load('roles'), 'roles' => Role::orderBy('name_ar')->get()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:10', 'confirmed'],
            'is_active' => ['boolean'],
            'theme' => ['nullable', Rule::in(Theme::MODES)],
            'roles' => ['array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ]);

        // Guard against locking everyone out.
        if ($user->is($request->user()) && ! $request->boolean('is_active')) {
            return back()->withErrors(['is_active' => __('لا يمكنك إيقاف حسابك أنت.')]);
        }

        $user->fill(collect($data)->only('name', 'email')->all());
        $user->is_active = $request->boolean('is_active');
        $user->theme = $data['theme'] ?? $user->theme;
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        $user->roles()->sync($data['roles'] ?? []);

        return redirect()->route('users.index')->with('ok', __('تم تحديث المستخدم.'));
    }
}
