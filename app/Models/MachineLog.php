<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['production_order_id', 'machine_id', 'work_date', 'hours'];

    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
