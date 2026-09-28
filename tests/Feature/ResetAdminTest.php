<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

class ResetAdminTest extends ApiTestCase
{
    private function run_(string $email): string
    {
        Artisan::call('rroka:reset-admin', ['email' => $email]);
        preg_match('/Temporary password: (\S+)/', Artisan::output(), $m);

        return $m[1];
    }

    public function test_creates_admin_and_the_printed_password_logs_in(): void
    {
        $password = $this->run_('Owner@Example.test');

        $this->assertSame(16, strlen($password));
        $this->post('/login', ['email' => 'owner@example.test', 'password' => $password])->assertRedirect('/');
        $this->assertTrue(User::first()->hasPermission('users.manage'));
    }

    public function test_resets_existing_locked_account_without_duplicating(): void
    {
        $first = $this->run_('owner@example.test');
        User::query()->update(['is_active' => false]);

        $second = $this->run_('OWNER@example.test');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, User::count());
        $this->post('/login', ['email' => 'owner@example.test', 'password' => $first])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'owner@example.test', 'password' => $second])->assertRedirect('/');
    }

    public function test_rejects_invalid_email(): void
    {
        $this->artisan('rroka:reset-admin', ['email' => 'not-an-email'])->assertFailed();
        $this->assertSame(0, User::count());
    }
}
