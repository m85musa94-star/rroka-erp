<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A VAT return as filed with ZATCA; approval locks the period and posts the settlement, then its payment. */
class VatReturn extends Model
{
    protected $fillable = ['period_start', 'period_end', 'filed_output_vat', 'filed_input_vat', 'include_verify', 'verify_reason', 'notes', 'attachment_id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'paid_date' => 'date', 'approved_at' => 'datetime', 'include_verify' => 'boolean'];
    }

    public function paidAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'paid_account_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(StudioAsset::class, 'attachment_id');
    }

    /** Net filed: due to ZATCA (> 0) or refundable (< 0). */
    public function filedNet(): ?float
    {
        return $this->filed_output_vat === null ? null : (float) $this->filed_output_vat - (float) $this->filed_input_vat;
    }
}
