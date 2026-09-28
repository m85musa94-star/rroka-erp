<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Owner recovery from the hosting panel's command runner, no env vars needed:
 *   php artisan rroka:reset-admin owner@example.com
 * Creates the account if missing, reactivates it, grants system_admin and prints
 * a new random temporary password (to be changed after logging in).
 */
class ResetAdmin extends Command
{
    protected $signature = 'rroka:reset-admin {email} {--name=مدير النظام}';

    protected $description = 'Create or recover the system admin and print a temporary password';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email'), " \t\n\r\"'"));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email: {$email}");

            return self::FAILURE;
        }

        $password = $this->temporaryPassword();

        $created = DB::transaction(function () use ($email, $password) {
            $role = Role::firstOrCreate(
                ['code' => 'system_admin'],
                ['name_ar' => 'مدير النظام', 'description' => 'حساب تقني بكل الصلاحيات'],
            );
            $role->permissions()->sync(Permission::pluck('id'));

            $user = User::whereRaw('lower(email) = ?', [$email])->first();
            $created = ! $user;
            $user ??= new User(['name' => $this->option('name')]);
            $user->email = $email;
            $user->password = $password;
            $user->is_active = true;
            $user->save();
            $user->roles()->syncWithoutDetaching([$role->id]);

            return $created;
        });

        $this->newLine();
        $this->info($created ? 'Admin account CREATED.' : 'Admin account RESET.');
        $this->line("Email:              {$email}");
        $this->line("Temporary password: {$password}");
        $this->warn('Log in now, then change this password from the Users page.');

        return self::SUCCESS;
    }

    /** 16 characters without look-alikes (0/O, 1/l/I) so it can be copied by eye. */
    private function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
