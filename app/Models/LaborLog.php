<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaborLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['production_order_id', 'worker_id', 'work_date', 'hours', 'activity'];

    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
