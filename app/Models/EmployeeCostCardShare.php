<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCostCardShare extends Model
{
    public $timestamps = false;

    protected $fillable = ['card_id', 'cost_center_id', 'share_pct'];

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
