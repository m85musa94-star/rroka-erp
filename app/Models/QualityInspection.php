<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityInspection extends Model
{
    public $timestamps = false;

    public const STAGES = ['IN_PROCESS', 'FINAL', 'PRE_DELIVERY', 'POST_INSTALLATION'];

    public const RESULTS = ['PASS', 'FAIL', 'REWORK'];

    protected $fillable = ['production_order_id', 'installation_id', 'stage', 'result', 'findings'];

    protected function casts(): array
    {
        return ['inspected_at' => 'datetime'];
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }
}
