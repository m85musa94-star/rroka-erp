<?php

namespace App\Models;

use App\Imports\ImportRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded template: its rows as read (never changed), the result of the latest
 * check, and — once imported — the records it created (import_batch_records).
 */
class ImportBatch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['import_type', 'file_name', 'file_sha256', 'rows', 'row_count'];

    protected function casts(): array
    {
        return ['rows' => 'array', 'result' => 'array', 'checked_at' => 'datetime', 'imported_at' => 'datetime'];
    }

    public function def(): ?array
    {
        return ImportRegistry::get($this->import_type);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(ImportBatchRecord::class, 'batch_id')->orderBy('row_no');
    }

    /** Check results keyed by the file's row number. */
    public function resultsByRow(): array
    {
        return collect($this->result ?? [])->keyBy('row')->all();
    }
}
