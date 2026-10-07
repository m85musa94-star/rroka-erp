<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * A journal entry. Drafts can change; posting (database trigger) checks balance, books start
 * and period, and gives the gap-free number. A posted entry is final: corrections are reversals.
 */
class JournalEntry extends Model
{
    public const SOURCES = ['MANUAL', 'OPENING', 'REVERSAL'];

    /** Entries written by approving a document (fn_post_document): never edited or reversed by hand. */
    public const DOCUMENT_SOURCES = ['EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK'];

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

    /** Posted by the person who prepared it: allowed (small team) and flagged for review. A document entry is posted by its approver. */
    public function selfPosted(): bool
    {
        return $this->isPosted() && ! $this->isDocument() && $this->posted_by === $this->created_by;
    }

    public function isDocument(): bool
    {
        return in_array($this->source_type, self::DOCUMENT_SOURCES, true);
    }

    /** Link to the document an automatic entry came from. */
    public function sourceUrl(): ?string
    {
        return self::documentUrl($this->source_type, $this->source_id);
    }

    public static function documentUrl(?string $type, ?int $id): ?string
    {
        return match ($type) {
            'EXPENSE' => route('expenses.show', $id),
            'PURCHASE' => route('purchases.show', $id),
            'TRANSFER' => route('treasury.transfers.show', $id),
            'STOCK' => ($m = DB::table('stock_movements')->where('id', $id)->value('material_id')) ? route('materials.show', $m) : null,
            default => null,
        };
    }

    /** The posted entry of a document, if any. */
    public static function forDocument(string $type, int $id): ?self
    {
        return self::where('source_type', $type)->where('source_id', $id)->first();
    }

    public function title(): string
    {
        return $this->entry_no ?? __('مسودة قيد #:id', ['id' => $this->id]);
    }
}
