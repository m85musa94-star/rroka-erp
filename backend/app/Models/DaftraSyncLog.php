<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DaftraSyncLog extends Model
{
    protected $table = 'daftra_sync_log';

    public $timestamps = false;

    protected $fillable = [
        'entity_type', 'entity_id', 'operation', 'status', 'attempt', 'request_payload',
        'http_status', 'response_body', 'daftra_id', 'error_message', 'requested_by', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['request_payload' => 'array', 'response_body' => 'array', 'finished_at' => 'datetime'];
    }
}
