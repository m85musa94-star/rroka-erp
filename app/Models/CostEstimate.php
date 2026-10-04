<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/** Standard cost estimate of one quotation line; its figures come from v_estimate_costs. */
class CostEstimate extends Model
{
    public const DIRECT_TYPES = ['EXTERNAL_MANUFACTURING', 'SUBCONTRACTOR', 'SPECIAL_DELIVERY', 'INSTALLATION', 'CRANE',
        'SPECIAL_TRANSPORT', 'EXTERNAL_PAINTING', 'SPECIAL_DESIGN', 'COMMISSION', 'OTHER'];

    protected $fillable = ['quotation_id', 'line_no', 'product_category', 'dimensions', 'specifications', 'pricing_method',
        'target_pct', 'min_margin_pct', 'installation_required', 'delivery_required', 'notes'];

    protected function casts(): array
    {
        return ['installation_required' => 'boolean', 'delivery_required' => 'boolean'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CostEstimateMaterial::class, 'estimate_id')->orderBy('id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(CostEstimateOperation::class, 'estimate_id')->orderBy('seq')->orderBy('id');
    }

    public function directCosts(): HasMany
    {
        return $this->hasMany(CostEstimateDirectCost::class, 'estimate_id')->orderBy('id');
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(CostEstimateSnapshot::class, 'estimate_id');
    }

    /** The live cost sheet (one row of v_estimate_costs, arrays decoded). */
    public function costs(): ?object
    {
        $row = DB::table('v_estimate_costs')->where('estimate_id', $this->id)->first();
        if ($row) {
            $row->missing = self::pgArray($row->missing);
            $row->warnings = self::pgArray($row->warnings);
        }

        return $row;
    }

    public static function pgArray(?string $value): array
    {
        $inner = trim((string) $value, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }
}
