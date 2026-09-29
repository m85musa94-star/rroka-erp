<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    public const STATUSES = ['SUBMITTED', 'APPROVED', 'REFUSED', 'CANCELLED'];

    protected $fillable = ['employee_id', 'leave_type_id', 'date_from', 'date_to', 'days', 'reason'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'approved_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
