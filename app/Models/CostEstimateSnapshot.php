<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The standard cost frozen when the quotation was approved (immutable). */
class CostEstimateSnapshot extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['frozen_at' => 'datetime', 'detail' => 'array'];
    }

    public function missingList(): array
    {
        return CostEstimate::pgArray($this->getRawOriginal('missing'));
    }

    public function warningList(): array
    {
        return CostEstimate::pgArray($this->getRawOriginal('warnings'));
    }
}
