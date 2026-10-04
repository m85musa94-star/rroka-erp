<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostEstimateOperation extends Model
{
    public $timestamps = false;

    protected $fillable = ['estimate_id', 'seq', 'operation', 'cost_center_id', 'employee_id', 'labor_hours', 'setup_hours',
        'machine_id', 'machine_hours', 'machine_setup_hours', 'notes'];
}
