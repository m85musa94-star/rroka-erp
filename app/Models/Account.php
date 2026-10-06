<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A ledger account. Group accounts hold children; only postable (leaf) accounts take entries. */
class Account extends Model
{
    public const TYPES = ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'];

    /** Debit-nature types: their balance is shown as debit − credit; the others as credit − debit. */
    public const DEBIT_TYPES = ['ASSET', 'EXPENSE'];

    public const ROLES = ['CASH', 'BANK', 'CUSTODY', 'RECEIVABLE', 'INVENTORY', 'WIP', 'INPUT_VAT', 'PAYABLE', 'OUTPUT_VAT',
        'CUSTOMER_ADVANCES', 'CAPITAL', 'OWNER_CURRENT', 'RETAINED_EARNINGS', 'SALES', 'SALES_DISCOUNT', 'COST_OF_SALES',
        'FIXED_ASSETS', 'ACCUMULATED_DEPRECIATION', 'DEPRECIATION'];

    protected $fillable = ['code', 'name', 'name_en', 'account_type', 'parent_id', 'is_postable', 'system_role', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_postable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Name in the user's language (English name when set and the user reads English). */
    public function label(): string
    {
        return app()->getLocale() === 'en' && $this->name_en ? $this->name_en : $this->name;
    }

    public function isDebitNature(): bool
    {
        return in_array($this->account_type, self::DEBIT_TYPES, true);
    }
}
