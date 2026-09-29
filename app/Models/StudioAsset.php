<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudioAsset extends Model
{
    public const CATEGORIES = ['CLIENT_REFERENCE', 'FINISHED_WORK', 'CATALOG', 'SITE', 'MATERIAL'];

    // File identity (disk, path, sha256, size, type) is set once by StudioStorage; the database refuses changes.
    protected $fillable = ['title', 'category', 'client_id', 'project_id', 'tags', 'notes'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function quotationLines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    /** Images a quotation for this customer may show: everything except other customers' own photos. */
    public function scopeUsableFor($query, ?int $clientId)
    {
        return $query->where(fn ($q) => $q->where('category', '<>', 'CLIENT_REFERENCE')
            ->when($clientId, fn ($q) => $q->orWhere('client_id', $clientId)));
    }

    public function url(bool $thumb = true): string
    {
        return route('studio.file', [$this, $thumb && $this->thumb_path ? 'thumb' : 'full']);
    }
}
