<?php

namespace App\Models;

use App\Models\Concerns\VersionedCostRecord;
use Illuminate\Database\Eloquent\Model;

/** Default pricing method, target and minimum margin (versioned). */
class PricingPolicy extends Model
{
    use VersionedCostRecord;

    public const METHODS = ['MARGIN', 'MARKUP'];

    protected $fillable = ['effective_from', 'pricing_method', 'target_pct', 'min_margin_pct', 'source', 'estimated', 'notes'];

    /** The approved policy in force on a date, or null. */
    public static function inForceOn($date): ?self
    {
        return self::where('status', 'APPROVED')->where('effective_from', '<=', $date)->orderByDesc('effective_from')->first();
    }
}
