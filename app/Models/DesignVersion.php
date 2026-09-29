<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DesignVersion extends Model
{
    public const STATUSES = ['DRAFT', 'CLIENT_REVIEW', 'CLIENT_APPROVED', 'RELEASED_FOR_PRODUCTION', 'SUPERSEDED', 'REJECTED'];

    protected $fillable = ['design_id', 'version_no', 'file_url', 'change_notes'];

    protected function casts(): array
    {
        return ['client_approved_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    public function bomLines(): HasMany
    {
        return $this->hasMany(DesignBomLine::class)->orderBy('id');
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    public function bomEditable(): bool
    {
        return in_array($this->status, ['DRAFT', 'CLIENT_REVIEW', 'CLIENT_APPROVED'], true);
    }
}
