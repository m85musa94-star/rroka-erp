<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable: the database refuses updates and deletes (post a reversing movement). */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['material_id', 'movement_type', 'quantity', 'unit_cost', 'project_id', 'production_order_id',
        'from_reservation', 'reference', 'reason', 'moved_at'];

    protected function casts(): array
    {
        return ['moved_at' => 'datetime', 'from_reservation' => 'boolean'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'material_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }
}
