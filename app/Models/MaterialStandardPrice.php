<?php

namespace App\Models;

use App\Models\Concerns\VersionedCostRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialStandardPrice extends Model
{
    use VersionedCostRecord;

    public const BASES = ['LAST_PURCHASE', 'AVERAGE_COST', 'SUPPLIER_QUOTE', 'MANUAL'];

    protected $fillable = ['material_id', 'effective_from', 'unit_price', 'price_basis', 'source', 'estimated', 'notes'];

    public function material(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'material_id');
    }
}
