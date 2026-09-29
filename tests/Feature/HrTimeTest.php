<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HrTimeTest extends ApiTestCase
{
    public function test_contract_lifecycle_and_salary_confidentiality(): void
    {
        $admin = $this->admin();
        $e = Employee::create(['name' => 'TEST Omar']);
        $terms = ['employee_id' => $e->id, 'contract_type' => 'FIXED_TERM', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'basic_salary' => 3000, 'housing_allowance' => 750, 'transport_allowance' => 300, 'other_allowance' => 0];
        $this->actingAs($admin)->post('/contracts', ['end_date' => null] + $terms)->assertSessionHasErrors('end_date');
        $this->actingAs($admin)->post('/contracts', $terms)->assertRedirect();
        $c = EmployeeContract::sole();
        $this->assertSame('DRAFT', $c->status);
        $this->assertSame(4050.0, $c->monthlyGross());

        $this->actingAs($admin)->put("/contracts/{$c->id}", ['basic_salary' => 3200] + $terms)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/contracts/{$c->id}/start")->assertSessionHasNoErrors();
        $this->actingAs($admin)->put("/contracts/{$c->id}", ['basic_salary' => 9999] + $terms)->assertSessionHasErrors('rule');
        $this->actingAs($admin)->post('/contracts', $terms)->assertRedirect();
        $second = EmployeeContract::latest('id')->first();
        $this->actingAs($admin)->post("/contracts/{$second->id}/start")->assertSessionHasErrors('rule'); // one running per employee
        $this->actingAs($admin)->post("/contracts/{$c->id}/close", ['end_date' => '2026-09-30'])->assertSessionHasNoErrors();
        $this->assertSame('EXPIRED', $c->fresh()->status);

        // Salaries are visible only with hr.contracts.
        $hrViewer = $this->userWith(['hr.view', 'hr.manage']);
        $this->actingAs($hrViewer)->get("/employees/{$e->id}")->assertOk()->assertDontSee('4,250.00');
        $this->actingAs($hrViewer)->get('/contracts')->assertForbidden();
        $this->actingAs($admin)->get("/employees/{$e->id}")->assertSee('4,250.00');
        $this->actingAs($admin)->get('/contracts?f[]=running')->assertOk();
    }

    public function test_attendance_board_toggle_and_corrections(): void
    {
        $admin = $this->admin();
        $e = Employee::create(['name' => 'TEST Saleh']);
        $this->actingAs($admin)->get('/attendance')->assertOk()->assertSee('TEST Saleh');
        $this->actingAs($admin)->post("/attendance/toggle/{$e->id}")->assertSessionHasNoErrors();
        $this->assertNull(Attendance::sole()->check_out);
        // Check-in three hours ago (the database clock is real, so no time travel).
        Attendance::sole()->forceFill(['check_in' => now()->subHours(3)])->save();
        $this->actingAs($admin)->post("/attendance/toggle/{$e->id}")->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(3.0, (float) Attendance::sole()->fresh()->worked_hours, 0.05);

        // Manual record that overlaps is refused by the database.
        $a = Attendance::sole();
        $tz = config('app.timezone');
        $this->actingAs($admin)->post('/attendance', ['employee_id' => $e->id, 'check_in' => $a->check_in->timezone($tz)->format('Y-m-d H:i'), 'notes' => 'dup'])->assertSessionHasErrors('rule');
        $this->actingAs($admin)->put("/attendance/{$a->id}", ['employee_id' => $e->id, 'check_in' => $a->check_in->timezone($tz)->subHour()->format('Y-m-d H:i'),
            'check_out' => $a->check_out->timezone($tz)->format('Y-m-d H:i'), 'notes' => 'forgot badge'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(4.0, (float) $a->fresh()->worked_hours, 0.05);
        $this->actingAs($admin)->get('/attendance/records?g=employee')->assertOk()->assertSee('TEST Saleh');
        $this->actingAs($this->userWith(['hr.view']))->get('/attendance')->assertForbidden();
    }

    public function test_time_off_self_service_approval_balance_and_segregation(): void
    {
        $approver = $this->admin();
        $staffUser = User::factory()->create();
        $e = Employee::create(['name' => 'TEST Nasser', 'user_id' => $staffUser->id]);
        $this->actingAs($approver)->post('/leave-types', ['name' => 'TEST annual', 'is_paid' => 1, 'requires_allocation' => 1])->assertSessionHasNoErrors();
        $type = LeaveType::sole();
        $this->actingAs($approver)->post('/leave-allocations', ['employee_id' => $e->id, 'leave_type_id' => $type->id, 'days' => 5,
            'valid_from' => now()->startOfYear()->toDateString(), 'valid_to' => now()->endOfYear()->toDateString(), 'reason' => 'TEST yearly'])->assertSessionHasNoErrors();

        // The employee requests their own leave and sees their balance, but cannot approve.
        $from = now()->startOfYear()->addDays(40);
        $this->actingAs($staffUser)->get('/leaves')->assertOk()->assertSee('5');
        $this->actingAs($staffUser)->post('/leaves', ['employee_id' => $e->id, 'leave_type_id' => $type->id,
            'date_from' => $from->toDateString(), 'date_to' => $from->copy()->addDays(3)->toDateString(), 'days' => 4])->assertSessionHasNoErrors();
        $r = LeaveRequest::sole();
        $this->actingAs($staffUser)->post("/leaves/{$r->id}/approve")->assertForbidden();
        $other = Employee::create(['name' => 'TEST other']);
        $this->actingAs($staffUser)->post('/leaves', ['employee_id' => $other->id, 'leave_type_id' => $type->id,
            'date_from' => $from->toDateString(), 'date_to' => $from->toDateString(), 'days' => 1])->assertForbidden();

        // An approver who is also the employee cannot approve their own request.
        $e->forceFill(['user_id' => $approver->id])->save();
        $this->actingAs($approver)->post("/leaves/{$r->id}/approve")->assertSessionHasErrors('rule');
        $e->forceFill(['user_id' => $staffUser->id])->save();
        $this->actingAs($approver)->post("/leaves/{$r->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $r->fresh()->status);
        $this->assertEquals(1, (float) DB::scalar('select fn_leave_balance(?, ?, ?)', [$e->id, $type->id, $from->toDateString()]));

        // Balance limit and refusal reason.
        $this->actingAs($staffUser)->post('/leaves', ['employee_id' => $e->id, 'leave_type_id' => $type->id,
            'date_from' => $from->copy()->addDays(20)->toDateString(), 'date_to' => $from->copy()->addDays(21)->toDateString(), 'days' => 2]);
        $r2 = LeaveRequest::latest('id')->first();
        $this->actingAs($approver)->post("/leaves/{$r2->id}/approve")->assertSessionHasErrors('rule');
        $this->actingAs($approver)->post("/leaves/{$r2->id}/refuse")->assertSessionHasErrors('refusal_reason');
        $this->actingAs($approver)->post("/leaves/{$r2->id}/refuse", ['refusal_reason' => 'TEST busy season'])->assertSessionHasNoErrors();

        // No attendance on an approved leave day.
        $this->actingAs($approver)->post('/attendance', ['employee_id' => $e->id, 'check_in' => $from->copy()->addDay()->format('Y-m-d').' 08:00'])->assertSessionHasErrors('rule');
        $this->actingAs($approver)->get('/leaves/settings')->assertOk()->assertSee('TEST yearly');
        $this->actingAs($approver)->get("/employees/{$e->id}")->assertOk()->assertSee('TEST annual');
        $this->actingAs($this->userWith(['clients.view']))->get('/leaves')->assertForbidden();
    }
}
