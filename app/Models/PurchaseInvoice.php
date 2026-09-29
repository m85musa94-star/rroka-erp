<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** A supplier's invoice: draft until approved; approval posts the stock receipts (database trigger). */
class PurchaseInvoice extends Model
{
    protected $fillable = ['supplier_id', 'supplier_invoice_no', 'invoice_date', 'due_date', 'discount_amount', 'vat_amount', 'notes', 'attachment_id'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date', 'approved_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class)->orderBy('line_no');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(StudioAsset::class, 'attachment_id');
    }

    public function totals(): object
    {
        return DB::table('v_purchase_totals')->where('purchase_invoice_id', $this->id)->first();
    }

    /** Approved by the same person who entered it: allowed in a small team, reviewed as an exception. */
    public function selfApproved(): bool
    {
        return $this->status === 'APPROVED' && $this->approved_by !== null && $this->approved_by === $this->created_by;
    }
}
