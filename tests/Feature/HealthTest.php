<?php

namespace Tests\Feature;

use App\Models\User;

class HealthTest extends ApiTestCase
{
    public function test_reports_missing_admin_then_all_ok(): void
    {
        $this->get('/api/health')->assertStatus(503)->assertSee('[فاشل] حساب المدير', false);

        User::factory()->create();
        $this->get('/api/health')->assertOk()
            ->assertSee('كل البنود سليمة', false)
            ->assertDontSee('password');
    }
}
