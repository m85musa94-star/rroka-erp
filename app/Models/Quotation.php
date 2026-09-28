<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Quotation extends Model
{
    protected $fillable = ['client_id', 'survey_id', 'issue_date', 'valid_until', 'discount_amount', 'notes'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'approved_at' => 'datetime',
            'discount_amount' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)->orderBy('line_no');
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    /** Totals come from the database view so there is one calculation, not two. */
    public function totals(): array
    {
        $row = DB::table('v_quotation_totals')->where('quotation_id', $this->id)->first();

        return [
            'subtotal' => $row->subtotal,
            'discount_amount' => $row->discount_amount,
            'net_before_vat' => $row->net_before_vat,
        ];
    }
}
