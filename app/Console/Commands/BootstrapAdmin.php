<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deploy-time, non-interactive admin management from environment variables:
 *   INITIAL_ADMIN_EMAIL / INITIAL_ADMIN_PASSWORD / INITIAL_ADMIN_NAME
 * - Creates the first system admin while the users table is empty.
 * - With INITIAL_ADMIN_RESET=true, (re)sets that account's password, reactivates
 *   it and grants system_admin — the owner's recovery path when locked out.
 * Values are trimmed and stripped of surrounding quotes, which are easy to type
 * by mistake in a hosting panel.
 */
class BootstrapAdmin extends Command
{
    protected $signature = 'rroka:bootstrap-admin';

    protected $description = 'Create or reset the system admin from environment variables';

    public function handle(): int
    {
        $email = mb_strtolower($this->clean('INITIAL_ADMIN_EMAIL'));
        $password = $this->clean('INITIAL_ADMIN_PASSWORD');
        $name = $this->clean('INITIAL_ADMIN_NAME') ?: 'مدير النظام';
        $reset = filter_var($this->clean('INITIAL_ADMIN_RESET'), FILTER_VALIDATE_BOOLEAN);

        if (! $reset && User::query()->exists()) {
            $this->info('Users already exist; nothing to do. (Set INITIAL_ADMIN_RESET=true to reset the admin password.)');

            return self::SUCCESS;
        }
        if ($email === '' || $password === '') {
            $this->warn('INITIAL_ADMIN_EMAIL / INITIAL_ADMIN_PASSWORD are not set; nothing to do.');

            return self::SUCCESS;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid INITIAL_ADMIN_EMAIL: {$email}");

            return self::FAILURE;
        }
        if (mb_strlen($password) < 12) {
            $this->error('INITIAL_ADMIN_PASSWORD must be at least 12 characters.');

            return self::FAILURE;
        }

        $existed = DB::transaction(function () use ($email, $password, $name) {
            $role = Role::firstOrCreate(
                ['code' => 'system_admin'],
                ['name_ar' => 'مدير النظام', 'description' => 'حساب تقني بكل الصلاحيات'],
            );
            $role->permissions()->sync(Permission::pluck('id'));

            $user = User::whereRaw('lower(email) = ?', [$email])->first();
            $existed = (bool) $user;
            $user ??= new User(['name' => $name]);
            $user->email = $email;
            $user->password = $password;
            $user->is_active = true;
            $user->save();
            $user->roles()->syncWithoutDetaching([$role->id]);

            return $existed;
        });

        $this->info($existed ? "Admin password reset for {$email}." : "System admin created: {$email}.");

        return self::SUCCESS;
    }

    private function clean(string $key): string
    {
        $value = trim((string) getenv($key));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = trim(substr($value, 1, -1));
        }

        return $value;
    }
}
