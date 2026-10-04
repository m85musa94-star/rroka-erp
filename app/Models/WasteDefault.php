<?php

namespace App\Models;

use App\Models\Concerns\VersionedCostRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Standard waste % for one material, or for a whole material category. */
class WasteDefault extends Model
{
    use VersionedCostRecord;

    protected $fillable = ['material_id', 'category', 'effective_from', 'waste_pct', 'source', 'estimated', 'notes'];

    public function material(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'material_id');
    }
}
