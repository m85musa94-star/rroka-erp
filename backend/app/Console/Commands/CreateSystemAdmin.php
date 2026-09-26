<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bootstraps the first account. Business roles (the 11 roles of the blueprint)
 * are defined later by the owner; this only creates a technical system_admin.
 */
class CreateSystemAdmin extends Command
{
    protected $signature = 'rroka:create-admin {email} {name}';

    protected $description = 'Create a user with the system_admin role (all permissions)';

    public function handle(): int
    {
        $password = $this->secret('Password (min 12 characters)');
        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($password) {
            $role = Role::firstOrCreate(
                ['code' => 'system_admin'],
                ['name_ar' => 'مدير النظام', 'description' => 'حساب تقني بكل الصلاحيات'],
            );
            $role->permissions()->sync(Permission::pluck('id'));

            $user = User::create([
                'email' => $this->argument('email'),
                'name' => $this->argument('name'),
                'password' => $password,
            ]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        });

        $this->info('System admin created.');

        return self::SUCCESS;
    }
}
