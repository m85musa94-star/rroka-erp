<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A VAT percentage the user can pick on a quotation (none picked = no VAT). */
class VatRate extends Model
{
    protected $fillable = ['name', 'rate_pct', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
