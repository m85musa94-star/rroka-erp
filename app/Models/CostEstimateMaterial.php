<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostEstimateMaterial extends Model
{
    public $timestamps = false;

    protected $fillable = ['estimate_id', 'material_id', 'quantity', 'waste_pct', 'unit_price', 'price_source', 'notes'];
}
