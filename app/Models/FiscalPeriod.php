<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A calendar month of the books: OPEN or CLOSED (closing and reopening rules live in the database). */
class FiscalPeriod extends Model
{
    protected $fillable = ['period_start'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'closed_at' => 'datetime'];
    }
}
