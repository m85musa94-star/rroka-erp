<?php

namespace Tests\Feature;

use App\Models\User;

class BootstrapAdminTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        putenv('INITIAL_ADMIN_EMAIL');
        putenv('INITIAL_ADMIN_PASSWORD');
        parent::tearDown();
    }

    public function test_creates_admin_once_from_environment(): void
    {
        putenv('INITIAL_ADMIN_EMAIL=owner@example.test');
        putenv('INITIAL_ADMIN_PASSWORD=a-long-enough-password');

        $this->artisan('rroka:bootstrap-admin')->assertSuccessful();
        $admin = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($admin->hasPermission('users.manage'));
        $this->assertTrue($admin->hasPermission('costing.view'));

        putenv('INITIAL_ADMIN_EMAIL=second@example.test');
        $this->artisan('rroka:bootstrap-admin')->assertSuccessful();
        $this->assertSame(1, User::count());
    }

    public function test_does_nothing_without_environment(): void
    {
        $this->artisan('rroka:bootstrap-admin')->assertSuccessful();
        $this->assertSame(0, User::count());
    }

    public function test_rejects_short_password(): void
    {
        putenv('INITIAL_ADMIN_EMAIL=owner@example.test');
        putenv('INITIAL_ADMIN_PASSWORD=short');

        $this->artisan('rroka:bootstrap-admin')->assertFailed();
        $this->assertSame(0, User::count());
    }
}
