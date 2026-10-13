<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer invoice (or credit note) as issued in Daftra, recorded here for the
 * books, the client's balance and the project's revenue. Never issued from here.
 */
class SalesInvoice extends Model
{
    public const KINDS = ['INVOICE', 'CREDIT_NOTE'];

    protected $fillable = ['kind', 'daftra_invoice_no', 'invoice_date', 'client_id', 'project_id', 'original_invoice_id',
        'description', 'amount', 'vat_amount', 'notes', 'attachment_id'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'approved_at' => 'datetime', 'amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id')->orderBy('invoice_date');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class)->orderBy('receipt_date');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(StudioAsset::class, 'attachment_id');
    }

    public function isCreditNote(): bool
    {
        return $this->kind === 'CREDIT_NOTE';
    }

    /** What is still owed on this invoice: total less approved credit notes and receipts. */
    public function openAmount(): float
    {
        return (float) $this->total
            - (float) $this->creditNotes()->where('status', 'APPROVED')->sum('total')
            - (float) $this->receipts()->where('status', 'APPROVED')->sum('amount');
    }

    public function selfApproved(): bool
    {
        return $this->status === 'APPROVED' && $this->approved_by !== null && $this->approved_by === $this->created_by;
    }
}
