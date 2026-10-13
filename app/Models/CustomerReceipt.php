<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money received from a client into a cash box, bank or custody; reduces the client's balance. */
class CustomerReceipt extends Model
{
    protected $fillable = ['receipt_date', 'client_id', 'project_id', 'sales_invoice_id', 'payment_account_id', 'amount',
        'reference', 'description', 'attachment_id'];

    protected function casts(): array
    {
        return ['receipt_date' => 'date', 'approved_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
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
