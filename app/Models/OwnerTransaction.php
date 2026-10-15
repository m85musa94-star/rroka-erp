<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Owner funding (cash in) or withdrawal (cash out), against the owner current account. */
class OwnerTransaction extends Model
{
    public const KINDS = ['FUNDING', 'WITHDRAWAL'];

    protected $fillable = ['kind', 'txn_date', 'payment_account_id', 'amount', 'description', 'reference', 'attachment_id'];

    protected function casts(): array
    {
        return ['txn_date' => 'date', 'approved_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(StudioAsset::class, 'attachment_id');
    }

    public function selfApproved(): bool
    {
        return $this->status === 'APPROVED' && $this->approved_by !== null && $this->approved_by === $this->created_by;
    }
}
