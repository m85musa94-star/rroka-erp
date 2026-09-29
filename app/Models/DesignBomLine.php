<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignBomLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['design_version_id', 'material_id', 'quantity', 'waste_pct'];

    public function material(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'material_id');
    }

    /** Quantity to plan for, including the waste allowance. */
    public function plannedQty(): float
    {
        return round((float) $this->quantity * (1 + (float) $this->waste_pct / 100), 4);
    }
}
