<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OverheadPoolLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['pool_id', 'category', 'description', 'amount', 'employee_id', 'machine_id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
