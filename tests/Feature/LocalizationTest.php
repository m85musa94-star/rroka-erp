<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Department;
use App\Models\DesignVersion;
use App\Models\Employee;
use App\Models\JobPosition;
use App\Models\Permission;
use App\Models\ProductionOrder;
use App\Models\Project;
use App\Models\RawMaterial;
use App\Models\Role;
use App\Models\StudioAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class LocalizationTest extends ApiTestCase
{
    private function englishAdmin(): User
    {
        $role = Role::create(['code' => 'test_en', 'name_ar' => 'Test role']);
        $role->permissions()->sync(Permission::pluck('id'));
        $user = User::factory()->create(['name' => 'Test Admin']);
        $user->forceFill(['locale' => 'en'])->save();
        $user->roles()->attach($role);

        return $user;
    }

    /** Arabic text left on an English page (user data here is all Latin, so any hit is untranslated UI). */
    private function arabicIn(string $html): array
    {
        // The language switch names the other language in its own script, by design.
        $html = preg_replace('/<button[^>]*lang-toggle[^>]*>.*?<\/button>/su', '', $html);
        preg_match_all('/[^<>"]*\p{Arabic}[^<>"]*/u', $html, $m);

        return array_values(array_unique(array_map('trim', $m[0])));
    }

    private function assertNoArabic(string $html, string $page): void
    {
        $this->assertSame([], $this->arabicIn($html), "Untranslated text on $page");
    }

    public function test_every_page_is_fully_english_for_an_english_user(): void
    {
        $admin = $this->englishAdmin();
        $client = Client::create(['business_name' => 'Acme Co', 'client_type' => 'COMPANY', 'city' => 'Riyadh']);
        $q = $this->actingAs($admin)->postJson('/api/quotations', [
            'client_id' => $client->id, 'discount_amount' => 0,
            'lines' => [['description' => 'Wardrobe', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 1000]],
        ])->assertCreated()->json();
        $this->actingAs($admin)->post("/quotations/{$q['id']}/send");
        $this->actingAs($admin)->post("/quotations/{$q['id']}/approve");
        $this->actingAs($admin)->post('/projects', [
            'quotation_id' => $q['id'], 'title' => 'Villa kitchen', 'start_date' => now()->toDateString(),
        ]);
        $projectId = Project::value('id');
        $roleId = Role::value('id');
        Storage::fake('studio');
        $this->actingAs($admin)->post('/studio', ['category' => 'FINISHED_WORK', 'title' => 'Oak kitchen', 'project_id' => $projectId,
            'files' => [UploadedFile::fake()->image('k.jpg')]]);
        $assetId = StudioAsset::value('id');
        // HR records in English.
        $this->actingAs($admin)->post('/departments', ['name' => 'Workshop']);
        $this->actingAs($admin)->post('/jobs', ['name' => 'Carpenter']);
        $this->actingAs($admin)->post('/employees', ['name' => 'Sam Carpenter', 'department_id' => Department::value('id'), 'job_id' => JobPosition::value('id'), 'hire_date' => '2026-01-01']);
        $employeeId = Employee::where('name', 'Sam Carpenter')->value('id');
        $this->actingAs($admin)->post("/employees/{$employeeId}/documents", ['doc_type' => 'IQAMA', 'doc_number' => '21', 'expiry_date' => now()->addDays(10)->toDateString()]);
        // Manufacturing records in English.
        $this->actingAs($admin)->post('/inventory', ['code' => 'MDF18', 'name' => 'MDF board', 'uom' => 'sheet', 'is_active' => 1]);
        $materialId = RawMaterial::value('id');
        $this->actingAs($admin)->post("/inventory/{$materialId}/move", ['movement_type' => 'RECEIPT', 'quantity' => 20, 'unit_cost' => 90]);
        $this->actingAs($admin)->post('/designs', ['project_id' => $projectId, 'title' => 'Main wall']);
        $versionId = DesignVersion::value('id');
        $this->actingAs($admin)->post("/design-versions/{$versionId}/bom", ['material_id' => $materialId, 'quantity' => 4]);
        $this->actingAs($admin)->post("/design-versions/{$versionId}/submit");
        $this->actingAs($admin)->post("/design-versions/{$versionId}/approve", ['client_approved_at' => now()->format('Y-m-d H:i')]);
        $this->actingAs($admin)->post("/design-versions/{$versionId}/release");
        $this->actingAs($admin)->post('/production', ['design_version_id' => $versionId]);
        $orderId = ProductionOrder::value('id');
        $this->actingAs($admin)->post("/production/{$orderId}/stage/IN_PROGRESS");
        $draft = $this->actingAs($admin)->postJson('/api/quotations', [
            'client_id' => $client->id, 'discount_amount' => 0,
            'lines' => [['description' => 'Kitchen', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 5000, 'studio_asset_id' => $assetId]],
        ])->assertCreated()->json();

        $pages = [
            '/', '/clients', '/clients?v=kanban', '/clients?g=city', '/clients/create', "/clients/{$client->id}", "/clients/{$client->id}/edit",
            '/quotations', '/quotations?v=kanban', '/quotations/create', "/quotations/{$q['id']}",
            '/projects', '/projects?v=kanban', "/projects/{$projectId}",
            '/settings/rates', '/users', '/users/create', "/users/{$admin->id}/edit",
            '/roles', '/roles/create', "/roles/{$roleId}/edit",
            '/reports', '/settings/daftra',
            '/employees', '/employees?v=list&g=department', '/employees/create', "/employees/{$employeeId}", "/employees/{$employeeId}/edit", '/departments',
            '/inventory', '/inventory?g=category', '/inventory/create', "/inventory/{$materialId}", "/inventory/{$materialId}/edit",
            '/designs', '/designs/create', "/design-versions/{$versionId}",
            '/production', '/production?v=kanban', '/production/create', "/production/{$orderId}",
            '/studio', '/studio?v=list', '/studio?g=category', '/studio/create', "/studio/{$assetId}", "/studio/{$assetId}/edit", "/quotations/{$draft['id']}/edit", "/quotations/{$draft['id']}",
        ];
        foreach (['quotations', 'projects', 'profitability'] as $r) {
            $pages[] = "/reports/$r";
            $pages[] = "/reports/$r?view=graph";
        }

        $left = [];
        foreach ($pages as $page) {
            $res = $this->actingAs($admin)->get($page)->assertOk();
            $this->assertStringContainsString('lang="en" dir="ltr"', $res->getContent(), $page);
            foreach ($this->arabicIn($res->getContent()) as $text) {
                $left[$text][] = $page;
            }
        }
        $this->assertSame([], $left, 'Untranslated text on English pages');
    }

    public function test_toggle_switches_and_persists_locale(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->get('/')->assertSee('dir="rtl"', false);

        $this->actingAs($user)->post('/locale/en')->assertRedirect();
        $this->assertSame('en', $user->fresh()->locale);
        $this->actingAs($user->fresh())->get('/')->assertSee('dir="ltr"', false)->assertSee('Customers');

        $this->actingAs($user->fresh())->post('/locale/ar');
        $this->assertSame('ar', $user->fresh()->locale);
        $this->post('/locale/fr')->assertNotFound();
    }

    public function test_login_page_follows_session_locale(): void
    {
        $this->post('/locale/en');
        $res = $this->get('/login')->assertOk()->assertSee('dir="ltr"', false)->assertSee('Sign in');
        $this->assertNoArabic($res->getContent(), '/login');

        // The choice made before signing in is kept after signing in.
        $user = User::factory()->create(['email' => 'en@rroka.test']);
        $this->assertSame('ar', $user->fresh()->locale);
        $this->post('/login', ['email' => 'en@rroka.test', 'password' => 'password'])->assertRedirect();
        $this->assertSame('en', $user->fresh()->locale);
        $this->get('/')->assertSee('dir="ltr"', false);
    }

    public function test_validation_and_rule_errors_are_english(): void
    {
        $admin = $this->englishAdmin();
        $this->actingAs($admin)->from('/clients/create')->post('/clients', [])
            ->assertSessionHasErrors(['business_name' => 'The customer name field is required.']);

        $this->actingAs($admin)->followingRedirects()->post('/clients', ['business_name' => 'Beta', 'client_type' => 'COMPANY'])
            ->assertOk()->assertSee('Customer saved.');

        $res = $this->actingAs($admin)->get('/clients/999999')->assertNotFound();
        $this->assertNoArabic($res->getContent(), '404');
    }
}
