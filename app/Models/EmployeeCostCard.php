<?php

namespace App\Models;

use App\Models\Concerns\VersionedCostRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Monthly cost components and productive capacity → productive hour rate. */
class EmployeeCostCard extends Model
{
    use VersionedCostRecord;

    public const COSTS = ['basic_salary', 'housing', 'transportation', 'insurance', 'government_fees', 'allowances', 'other_costs'];

    public const DEDUCTIONS = ['break_hours', 'cleaning_hours', 'maintenance_hours', 'setup_hours', 'meeting_hours',
        'downtime_hours', 'waiting_hours', 'other_nonproductive_hours'];

    protected $fillable = ['employee_id', 'effective_from', ...self::COSTS, 'theoretical_hours', ...self::DEDUCTIONS, 'source', 'estimated', 'notes'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(EmployeeCostCardShare::class, 'card_id');
    }
}
