<?php

namespace App\Models;

use App\Models\Concerns\VersionedCostRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Depreciation, electricity, maintenance, spare parts and other cost per practical machine hour. */
class MachineCostCard extends Model
{
    use VersionedCostRecord;

    public const INPUTS = ['acquisition_cost', 'residual_value', 'useful_life_years', 'theoretical_annual_hours', 'practical_annual_hours',
        'power_kw', 'load_factor', 'annual_maintenance', 'annual_spare_parts', 'annual_other'];

    protected $fillable = ['machine_id', 'effective_from', ...self::INPUTS, 'source', 'estimated', 'notes'];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
