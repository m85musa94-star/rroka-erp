<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerRate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['worker_id', 'hourly_cost', 'effective_from', 'basis_note', 'entered_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date'];
    }
}
