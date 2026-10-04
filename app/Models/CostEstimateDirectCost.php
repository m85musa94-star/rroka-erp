<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostEstimateDirectCost extends Model
{
    public $timestamps = false;

    protected $fillable = ['estimate_id', 'cost_type', 'description', 'amount', 'basis'];
}
