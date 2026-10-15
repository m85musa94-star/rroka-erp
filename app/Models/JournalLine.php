<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['account_id', 'debit', 'credit', 'description', 'project_id', 'cost_center_id', 'partner_type', 'partner_id'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** The client, supplier or employee the line belongs to, by name. */
    public function partnerName(): ?string
    {
        return match ($this->partner_type) {
            'CLIENT' => Client::whereKey($this->partner_id)->value('business_name'),
            'SUPPLIER' => Supplier::whereKey($this->partner_id)->value('name'),
            'EMPLOYEE' => Employee::whereKey($this->partner_id)->value('name'),
            default => null,
        };
    }

    /** "CLIENT:3"-style key used by the entry form's partner select. */
    public function partnerKey(): string
    {
        return $this->partner_type ? $this->partner_type.':'.$this->partner_id : '';
    }
}
