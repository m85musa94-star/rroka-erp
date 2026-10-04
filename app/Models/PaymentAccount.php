<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Where money is paid from: a cash box, a bank account, or an employee's custody
 * (petty cash). Each maps to a Daftra treasury; only custody has a balance here.
 */
class PaymentAccount extends Model
{
    public const KINDS = ['CASH', 'BANK', 'CUSTODY'];

    /** The payment methods each kind of account allows (the database enforces the same). */
    public const METHODS = ['CASH' => ['CASH'], 'BANK' => ['BANK', 'CARD'], 'CUSTODY' => ['PETTY_CASH']];

    protected $fillable = ['name', 'kind', 'bank_name', 'iban', 'employee_id', 'custody_limit', 'daftra_treasury_ref', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(PaymentAccountSummary::class, 'account_id');
    }

    public function isCustody(): bool
    {
        return $this->kind === 'CUSTODY';
    }
}
