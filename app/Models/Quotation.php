<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Quotation extends Model
{
    protected $fillable = ['client_id', 'survey_id', 'issue_date', 'valid_until', 'discount_amount', 'notes', 'vat_rate_id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'approved_at' => 'datetime',
            'discount_amount' => 'decimal:2',
            'requires_costing' => 'boolean',
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

    public function estimates(): HasMany
    {
        return $this->hasMany(CostEstimate::class)->orderBy('line_no');
    }

    /** Estimates whose line was removed when the lines were rewritten. */
    public function dropOrphanEstimates(): void
    {
        $this->estimates()->whereNotIn('line_no', $this->lines()->pluck('line_no'))->delete();
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
            'vat_pct' => $row->vat_pct,
            'vat_amount' => $row->vat_amount,
            'total_incl_vat' => $row->total_incl_vat,
        ];
    }
}
