<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OverheadRate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['basis', 'rate_pct', 'effective_from', 'basis_note', 'entered_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date'];
    }
}
