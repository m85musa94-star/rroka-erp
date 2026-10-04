<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Carpentry, CNC, upholstery, ...: where cost is incurred and how its overhead is absorbed. */
class CostCenter extends Model
{
    public const DRIVERS = ['LABOR_HOURS', 'MACHINE_HOURS'];

    protected $fillable = ['code', 'name', 'driver', 'department_id', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
