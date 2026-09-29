<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The employee file. Same table as Worker (`workers`): costing, time logs and
 * installation keep using Worker; HR screens use this richer model.
 */
class Employee extends Model
{
    protected $table = 'workers';

    /** Visible to anyone with hr.view (the directory). */
    public const DIRECTORY = ['name', 'trade', 'department_id', 'job_id', 'manager_id', 'work_phone', 'work_email', 'is_direct_labor', 'employment_type', 'hire_date'];

    /** Personal data: hr.manage only. */
    public const PERSONAL = ['mobile', 'nationality', 'id_type', 'id_number', 'birth_date', 'gender', 'iban', 'emergency_contact', 'emergency_phone', 'address', 'notes', 'user_id'];

    protected $fillable = [...self::DIRECTORY, ...self::PERSONAL];

    protected function casts(): array
    {
        return ['hire_date' => 'date', 'birth_date' => 'date', 'termination_date' => 'date', 'is_active' => 'boolean', 'is_direct_labor' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class)->orderBy('expiry_date');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id')->where('is_active', true)->orderBy('name');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class)->orderByDesc('start_date');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class)->orderByDesc('date_from');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LeaveAllocation::class)->orderByDesc('valid_from');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class)->orderByDesc('check_in');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(WorkerRate::class, 'worker_id')->orderByDesc('effective_from');
    }
}
