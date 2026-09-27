<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];

    public function rates(): HasMany
    {
        return $this->hasMany(MachineRate::class)->orderByDesc('effective_from');
    }
}
