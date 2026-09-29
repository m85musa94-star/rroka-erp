<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Terms are entered from the signed contract; a running contract's terms are fixed by the database. */
class EmployeeContract extends Model
{
    public const STATUSES = ['DRAFT', 'RUNNING', 'EXPIRED', 'CANCELLED'];

    public const WARN_DAYS = 60;

    protected $fillable = ['employee_id', 'contract_type', 'start_date', 'end_date', 'basic_salary', 'housing_allowance',
        'transport_allowance', 'other_allowance', 'weekly_hours', 'notes'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function monthlyGross(): float
    {
        return round((float) $this->basic_salary + (float) $this->housing_allowance + (float) $this->transport_allowance + (float) $this->other_allowance, 2);
    }

    public function endingSoon(): bool
    {
        return $this->status === 'RUNNING' && $this->end_date && $this->end_date->lte(today()->addDays(self::WARN_DAYS));
    }
}
