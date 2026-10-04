<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Read-only row of v_payment_account_summary. */
class PaymentAccountSummary extends Model
{
    protected $table = 'v_payment_account_summary';

    protected $primaryKey = 'account_id';

    public $timestamps = false;
}
