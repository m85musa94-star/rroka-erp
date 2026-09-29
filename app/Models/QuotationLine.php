<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['line_no', 'description', 'quantity', 'unit', 'unit_price', 'studio_asset_id'];

    public function studioAsset(): BelongsTo
    {
        return $this->belongsTo(StudioAsset::class);
    }
}
