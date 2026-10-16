<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A record created by an import batch (append-only). */
class ImportBatchRecord extends Model
{
    public $timestamps = false;

    protected $fillable = ['batch_id', 'row_no', 'record_table', 'record_id'];
}
