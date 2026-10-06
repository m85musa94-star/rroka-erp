<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A journal entry. Drafts can change; posting (database trigger) checks balance, books start
 * and period, and gives the gap-free number. A posted entry is final: corrections are reversals.
 */
class JournalEntry extends Model
{
    public const SOURCES = ['MANUAL', 'OPENING', 'REVERSAL'];

    protected $fillable = ['entry_date', 'description', 'reference', 'source_type'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id')->orderBy('id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'POSTED';
    }

    /** Posted by the person who prepared it: allowed (small team) and flagged for review. */
    public function selfPosted(): bool
    {
        return $this->isPosted() && $this->posted_by === $this->created_by;
    }

    public function title(): string
    {
        return $this->entry_no ?? __('مسودة قيد #:id', ['id' => $this->id]);
    }
}
