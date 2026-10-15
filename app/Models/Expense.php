<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    public const METHODS = ['CASH', 'BANK', 'CARD', 'PETTY_CASH', 'CREDIT', 'OWNER'];

    protected $fillable = ['expense_date', 'category_id', 'supplier_id', 'payee', 'description', 'amount', 'vat_amount',
        'payment_method', 'paid_by_employee_id', 'payment_account_id', 'project_id', 'reference', 'attachment_id'];

    protected function casts(): array
    {
        return ['expense_date' => 'date', 'approved_at' => 'datetime'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'paid_by_employee_id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(StudioAsset::class, 'attachment_id');
    }

    /** Payments made against this invoice on credit. */
    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderBy('payment_date');
    }

    public function isCredit(): bool
    {
        return $this->payment_method === 'CREDIT';
    }

    /** What is still owed on an invoice on credit. */
    public function openAmount(): float
    {
        return (float) $this->amount + (float) $this->vat_amount - (float) $this->supplierPayments()->where('status', 'APPROVED')->sum('amount');
    }

    public function selfApproved(): bool
    {
        return $this->status === 'APPROVED' && $this->approved_by !== null && $this->approved_by === $this->created_by;
    }

    public function payeeName(): string
    {
        return $this->supplier?->name ?? (string) $this->payee;
    }
}
