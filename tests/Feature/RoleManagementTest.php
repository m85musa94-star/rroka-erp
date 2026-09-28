<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

class RoleManagementTest extends ApiTestCase
{
    public function test_owner_creates_role_with_arabic_name_only(): void
    {
        $admin = $this->admin();
        $perms = Permission::whereIn('code', ['clients.view', 'quotations.view'])->pluck('id')->all();

        $this->actingAs($admin)->post('/roles', ['name_ar' => 'مسؤول المبيعات', 'permissions' => $perms])
            ->assertRedirect('/roles');

        $role = Role::where('name_ar', 'مسؤول المبيعات')->firstOrFail();
        $this->assertMatchesRegularExpression('/^role_\d+_[a-z0-9]{4}$/', $role->code);
        $this->assertEqualsCanonicalizing($perms, $role->permissions()->pluck('permissions.id')->all());
    }

    public function test_duplicate_role_name_is_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/roles', ['name_ar' => 'المحاسب']);
        $this->actingAs($admin)->post('/roles', ['name_ar' => 'المحاسب'])->assertSessionHasErrors('name_ar');
    }

    public function test_permissions_are_grouped_by_module(): void
    {
        $this->actingAs($this->admin())->get('/roles/create')->assertOk()
            ->assertSee('عروض الأسعار', false)
            ->assertSee('المخزون', false)
            ->assertSee('اعتماد عروض الأسعار', false)
            ->assertDontSee('name="code"', false);
    }

    public function test_new_user_gets_role_and_logs_in_with_its_permissions_only(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/roles', ['name_ar' => 'مشاهد', 'permissions' => Permission::where('code', 'clients.view')->pluck('id')->all()]);
        $role = Role::where('name_ar', 'مشاهد')->first();

        $this->actingAs($admin)->post('/users', [
            'name' => 'موظف', 'email' => 'Staff@Rroka.test',
            'password' => 'staff-password-1', 'password_confirmation' => 'staff-password-1',
            'roles' => [$role->id],
        ])->assertRedirect('/users');
        $this->post('/logout');

        $this->post('/login', ['email' => 'staff@rroka.test', 'password' => 'staff-password-1'])->assertRedirect('/');
        $staff = User::where('email', 'staff@rroka.test')->first();
        $this->assertTrue($staff->hasPermission('clients.view'));
        $this->get('/clients')->assertOk();
        $this->get('/quotations')->assertForbidden();
    }

    public function test_role_in_use_or_admin_role_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $inUse = $admin->roles()->first();
        $this->actingAs($admin)->delete("/roles/{$inUse->id}")->assertSessionHasErrors('role');

        $sys = Role::create(['code' => 'system_admin', 'name_ar' => 'مدير النظام']);
        $this->actingAs($admin)->delete("/roles/{$sys->id}")->assertSessionHasErrors('role');

        $free = Role::create(['code' => 'tmp', 'name_ar' => 'مؤقت']);
        $this->actingAs($admin)->delete("/roles/{$free->id}")->assertRedirect('/roles');
        $this->assertNull(Role::find($free->id));
    }
}
