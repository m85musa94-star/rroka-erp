<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A movement between two payment accounts: issuing or returning custody, a cash deposit, ... */
class TreasuryTransfer extends Model
{
    protected $fillable = ['transfer_date', 'from_account_id', 'to_account_id', 'amount', 'reference', 'notes'];

    protected function casts(): array
    {
        return ['transfer_date' => 'date', 'approved_at' => 'datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'from_account_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'to_account_id');
    }

    public function selfApproved(): bool
    {
        return $this->status === 'APPROVED' && $this->approved_by !== null && $this->approved_by === $this->created_by;
    }

    /** ISSUE (into custody), RETURN (out of custody) or MOVE (between cash and bank). */
    public function purpose(): string
    {
        return match (true) {
            $this->to?->kind === 'CUSTODY' && $this->from?->kind !== 'CUSTODY' => 'ISSUE',
            $this->from?->kind === 'CUSTODY' && $this->to?->kind !== 'CUSTODY' => 'RETURN',
            default => 'MOVE',
        };
    }
}
