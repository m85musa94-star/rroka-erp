<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\JobPosition;

class HrTest extends ApiTestCase
{
    public function test_employee_file_departments_jobs_and_directory_privacy(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/departments', ['name' => 'TEST workshop'])->assertSessionHasNoErrors();
        $dept = Department::sole();
        $this->actingAs($admin)->post('/jobs', ['name' => 'TEST carpenter', 'department_id' => $dept->id])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/employees', [
            'name' => 'TEST Ahmed', 'department_id' => $dept->id, 'job_id' => JobPosition::sole()->id, 'hire_date' => '2026-01-01',
            'is_direct_labor' => 1, 'id_type' => 'IQAMA', 'id_number' => '2000000001', 'iban' => 'sa03 8000 0000 6080 1016 7519',
            'work_phone' => '0500000001',
        ])->assertRedirect();
        $e = Employee::where('name', 'TEST Ahmed')->sole();
        $this->assertSame('SA0380000000608010167519', $e->iban);
        $this->assertMatchesRegularExpression('/^E-\d{4}-\d{6}$/', $e->employee_no);

        // Saudi IBAN shape is enforced by validation + database.
        $this->actingAs($admin)->put("/employees/{$e->id}", ['name' => 'TEST Ahmed', 'iban' => 'DE89370400440532013000'])->assertSessionHasErrors('rule');

        // Directory users see work data, never personal data.
        $viewer = $this->userWith(['hr.view']);
        $this->actingAs($viewer)->get("/employees/{$e->id}")->assertOk()->assertSee('0500000001')
            ->assertDontSee('2000000001')->assertDontSee('SA0380000000608010167519');
        $this->actingAs($viewer)->get("/employees/{$e->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->post('/employees', ['name' => 'x'])->assertForbidden();
        $this->actingAs($admin)->get("/employees/{$e->id}")->assertOk()->assertSee('2000000001');

        $this->actingAs($admin)->get('/employees')->assertOk()->assertSee('TEST Ahmed');
        $this->actingAs($admin)->get('/employees?v=list&g=department')->assertOk()->assertSee('TEST workshop');
        $this->actingAs($admin)->get('/departments')->assertOk()->assertSee('TEST carpenter');
    }

    public function test_reporting_line_cannot_loop_and_end_of_service_needs_reason(): void
    {
        $admin = $this->admin();
        $a = Employee::create(['name' => 'TEST A']);
        $b = Employee::create(['name' => 'TEST B', 'manager_id' => $a->id]);
        $this->actingAs($admin)->put("/employees/{$a->id}", ['name' => 'TEST A', 'manager_id' => $b->id])->assertSessionHasErrors('rule');

        $this->actingAs($admin)->post("/employees/{$b->id}/employment", ['termination_date' => '2026-09-01'])->assertSessionHasErrors('termination_reason');
        $this->actingAs($admin)->post("/employees/{$b->id}/employment", ['termination_date' => '2026-09-01', 'termination_reason' => 'TEST resigned'])->assertSessionHasNoErrors();
        $this->assertFalse($b->fresh()->is_active);
        $this->actingAs($admin)->get('/employees')->assertDontSee('TEST B'); // default: active only
        $this->actingAs($admin)->get('/employees?f[]=ended')->assertSee('TEST B');
        $this->actingAs($admin)->post("/employees/{$b->id}/employment", ['hire_date' => '2026-10-01'])->assertSessionHasNoErrors();
        $this->assertTrue($b->fresh()->is_active);
    }

    public function test_documents_expiry_alerts(): void
    {
        $admin = $this->admin();
        $e = Employee::create(['name' => 'TEST Iqama holder']);
        $this->actingAs($admin)->post("/employees/{$e->id}/documents", ['doc_type' => 'IQAMA', 'doc_number' => '2111', 'expiry_date' => now()->addDays(20)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/employees/{$e->id}/documents", ['doc_type' => 'OTHER'])->assertSessionHasErrors('notes');
        $this->actingAs($admin)->post("/employees/{$e->id}/documents", ['doc_type' => 'PASSPORT', 'issue_date' => '2026-05-01', 'expiry_date' => '2026-01-01'])->assertSessionHasErrors('expiry_date');

        $doc = EmployeeDocument::sole();
        $this->assertSame('soon', $doc->state());
        $this->actingAs($admin)->get('/employees')->assertSee('وثائق تحتاج تجديدًا')->assertSee('TEST Iqama holder');
        $this->actingAs($admin)->get('/employees?f[]=docs')->assertSee('TEST Iqama holder');

        $this->actingAs($admin)->put("/employee-documents/{$doc->id}", ['doc_type' => 'IQAMA', 'expiry_date' => now()->addYear()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('ok', $doc->fresh()->state());
        $this->actingAs($admin)->delete("/employee-documents/{$doc->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, EmployeeDocument::count());
    }
}
