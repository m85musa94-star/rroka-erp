<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Worker extends Model
{
    protected $fillable = ['name', 'trade', 'user_id', 'is_active'];

    public function rates(): HasMany
    {
        return $this->hasMany(WorkerRate::class)->orderByDesc('effective_from');
    }
}
