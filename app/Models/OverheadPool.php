<?php

namespace App\Models;

use App\Models\Concerns\VersionedCostRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Expected indirect cost of a period, absorbed through a cost driver at practical capacity. */
class OverheadPool extends Model
{
    use VersionedCostRecord;

    public const MANUFACTURING_CATEGORIES = ['RENT', 'GENERAL_ELECTRICITY', 'SUPERVISION', 'INDIRECT_LABOR', 'CLEANING',
        'MAINTENANCE', 'CONSUMABLES', 'DEPRECIATION', 'INSURANCE', 'OTHER_PRODUCTION'];

    public const SELLING_ADMIN_CATEGORIES = ['SELLING', 'ADMINISTRATIVE'];

    protected $fillable = ['kind', 'cost_center_id', 'effective_from', 'period_to', 'driver', 'theoretical_capacity',
        'practical_capacity', 'budgeted_manufacturing_cost', 'source', 'estimated', 'notes'];

    protected function casts(): array
    {
        return ['period_to' => 'date'];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OverheadPoolLine::class, 'pool_id')->orderBy('id');
    }

    public function categories(): array
    {
        return $this->kind === 'SELLING_ADMIN' ? self::SELLING_ADMIN_CATEGORIES : self::MANUFACTURING_CATEGORIES;
    }
}
