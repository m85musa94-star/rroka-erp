<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['line_no', 'description', 'quantity', 'unit', 'unit_price'];
}
