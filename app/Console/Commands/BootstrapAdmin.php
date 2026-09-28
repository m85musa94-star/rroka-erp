<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Deploy-time, non-interactive: creates the first system admin from
 * INITIAL_ADMIN_EMAIL / INITIAL_ADMIN_PASSWORD / INITIAL_ADMIN_NAME, only while
 * the users table is empty. Safe to run on every deploy.
 */
class BootstrapAdmin extends Command
{
    protected $signature = 'rroka:bootstrap-admin';

    protected $description = 'Create the first system admin from environment variables (first deploy only)';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->info('Users already exist; nothing to do.');

            return self::SUCCESS;
        }

        $email = getenv('INITIAL_ADMIN_EMAIL') ?: '';
        $password = getenv('INITIAL_ADMIN_PASSWORD') ?: '';
        if ($email === '' || $password === '') {
            $this->warn('No users yet, and INITIAL_ADMIN_EMAIL / INITIAL_ADMIN_PASSWORD are not set.');

            return self::SUCCESS;
        }

        return $this->call('rroka:create-admin', [
            'email' => $email,
            'name' => getenv('INITIAL_ADMIN_NAME') ?: 'مدير النظام',
            '--if-none' => true,
        ]);
    }
}
