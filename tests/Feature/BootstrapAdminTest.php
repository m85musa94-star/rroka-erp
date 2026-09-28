<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class BootstrapAdminTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        foreach (['INITIAL_ADMIN_EMAIL', 'INITIAL_ADMIN_PASSWORD', 'INITIAL_ADMIN_NAME', 'INITIAL_ADMIN_RESET'] as $k) {
            putenv($k);
        }
        parent::tearDown();
    }

    private function env(array $vars): void
    {
        foreach ($vars as $k => $v) {
            putenv("$k=$v");
        }
    }

    public function test_creates_admin_once_from_environment(): void
    {
        $this->env(['INITIAL_ADMIN_EMAIL' => 'owner@example.test', 'INITIAL_ADMIN_PASSWORD' => 'a-long-enough-password']);

        $this->artisan('rroka:bootstrap-admin')->assertSuccessful();
        $admin = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($admin->hasPermission('users.manage'));

        $this->env(['INITIAL_ADMIN_EMAIL' => 'second@example.test']);
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
        $this->env(['INITIAL_ADMIN_EMAIL' => 'owner@example.test', 'INITIAL_ADMIN_PASSWORD' => 'short']);

        $this->artisan('rroka:bootstrap-admin')->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_capitals_spaces_and_quotes_in_panel_values_still_allow_login(): void
    {
        $this->env(['INITIAL_ADMIN_EMAIL' => ' M85Musa@Example.test ', 'INITIAL_ADMIN_PASSWORD' => '"Workshop-Pass-2026"']);
        $this->artisan('rroka:bootstrap-admin')->assertSuccessful();

        $this->post('/login', ['email' => 'M85musa@example.TEST', 'password' => 'Workshop-Pass-2026'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_reset_recovers_a_locked_out_admin(): void
    {
        $this->env(['INITIAL_ADMIN_EMAIL' => 'owner@example.test', 'INITIAL_ADMIN_PASSWORD' => 'first-password-123']);
        $this->artisan('rroka:bootstrap-admin')->assertSuccessful();
        User::where('email', 'owner@example.test')->update(['is_active' => false]);

        // Without the reset flag a changed password is ignored.
        $this->env(['INITIAL_ADMIN_PASSWORD' => 'second-password-456']);
        $this->artisan('rroka:bootstrap-admin')->assertSuccessful();
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'second-password-456'])->assertSessionHasErrors('email');

        $this->env(['INITIAL_ADMIN_RESET' => 'true']);
        $this->artisan('rroka:bootstrap-admin')->assertSuccessful();
        $this->assertSame(1, User::count());
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'second-password-456'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_existing_mixed_case_email_is_normalised_by_migration(): void
    {
        $id = DB::table('users')->insertGetId(['name' => 'x', 'email' => 'Mixed@Example.test', 'password' => bcrypt('password-123456')]);
        (require database_path('migrations/2026_09_28_000000_normalize_user_emails.php'))->up();

        $this->assertSame('mixed@example.test', DB::table('users')->where('id', $id)->value('email'));
    }
}
