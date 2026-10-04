<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Tests of other modules approve quotations without costing them, as quotations
     * created before the costing gate do. Costing tests turn the gate on.
     * (ALTER is transactional: undone with the test's transaction.)
     */
    protected bool $costingGate = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->costingGate) {
            DB::statement('ALTER TABLE quotations ALTER COLUMN requires_costing SET DEFAULT false');
        }
    }

    protected function userWith(array $permissions): User
    {
        $role = Role::create(['code' => 'test_'.uniqid(), 'name_ar' => 'اختبار']);
        $role->permissions()->sync(Permission::whereIn('code', $permissions)->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    protected function admin(): User
    {
        return $this->userWith(Permission::pluck('code')->all());
    }

    protected function draftQuotation(User $user, array $overrides = []): array
    {
        $client = $this->actingAs($user)->postJson('/api/clients', ['business_name' => 'عميل اختبار'])->assertCreated()->json();

        return $this->actingAs($user)->postJson('/api/quotations', $overrides + [
            'client_id' => $client['id'],
            'discount_amount' => 200,
            'lines' => [
                ['description' => 'خزانة ملابس', 'quantity' => 2, 'unit_price' => 1500],
                ['description' => 'طاولة', 'quantity' => 1, 'unit_price' => 700],
            ],
        ])->assertCreated()->json();
    }
}
