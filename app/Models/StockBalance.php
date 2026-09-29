<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Derived from stock_movements by the database; read-only here. */
class StockBalance extends Model
{
    protected $primaryKey = 'material_id';

    public $incrementing = false;

    public $timestamps = false;

    public function available(): float
    {
        return (float) $this->qty_on_hand - (float) $this->qty_reserved;
    }
}
