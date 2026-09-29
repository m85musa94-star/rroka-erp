<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $fillable = ['name', 'is_paid', 'requires_allocation', 'is_active'];

    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'requires_allocation' => 'boolean', 'is_active' => 'boolean'];
    }
}
