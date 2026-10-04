<?php

namespace App\Models;

use App\Models\Concerns\VersionedCostRecord;
use Illuminate\Database\Eloquent\Model;

class EnergyRate extends Model
{
    use VersionedCostRecord;

    protected $fillable = ['effective_from', 'rate_per_kwh', 'source', 'estimated', 'notes'];
}
