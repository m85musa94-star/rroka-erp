<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid to a supplier from a cash box, bank or custody; settles invoices on credit. */
class SupplierPayment extends Model
{
    protected $fillable = ['payment_date', 'supplier_id', 'payment_account_id', 'expense_id', 'purchase_invoice_id', 'amount',
        'reference', 'description', 'attachment_id'];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'approved_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(StudioAsset::class, 'attachment_id');
    }

    /** The invoice this payment settles, as "number — supplier invoice no.". */
    public function invoiceLabel(): ?string
    {
        return match (true) {
            $this->expense_id !== null => $this->expense?->expense_no.' — '.$this->expense?->reference,
            $this->purchase_invoice_id !== null => $this->purchaseInvoice?->purchase_no.' — '.$this->purchaseInvoice?->supplier_invoice_no,
            default => null,
        };
    }

    public function invoiceUrl(): ?string
    {
        return match (true) {
            $this->expense_id !== null => route('expenses.show', $this->expense_id),
            $this->purchase_invoice_id !== null => route('purchases.show', $this->purchase_invoice_id),
            default => null,
        };
    }

    public function selfApproved(): bool
    {
        return $this->status === 'APPROVED' && $this->approved_by !== null && $this->approved_by === $this->created_by;
    }
}
