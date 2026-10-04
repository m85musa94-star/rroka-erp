<?php

namespace App\Models\Concerns;

/**
 * A numbered cost record: DRAFT -> APPROVED (final) | CANCELLED. The version
 * number and the "no back-dating" rule come from the database (fn_cost_record_guard).
 */
trait VersionedCostRecord
{
    public function initializeVersionedCostRecord(): void
    {
        $this->mergeCasts(['effective_from' => 'date', 'approved_at' => 'datetime', 'estimated' => 'boolean']);
    }

    public function selfApproved(): bool
    {
        return $this->status === 'APPROVED' && $this->approved_by !== null && $this->approved_by === $this->created_by;
    }

    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }
}
