<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    public const TYPES = ['NATIONAL_ID', 'IQAMA', 'PASSPORT', 'WORK_PERMIT', 'HEALTH_CERT', 'DRIVING_LICENSE', 'OTHER'];

    /** Days before expiry when a document starts showing as "expiring soon". */
    public const WARN_DAYS = 60;

    protected $fillable = ['doc_type', 'doc_number', 'issue_date', 'expiry_date', 'notes'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'expiry_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** expired | soon | ok | none */
    public function state(): string
    {
        if (! $this->expiry_date) {
            return 'none';
        }
        if ($this->expiry_date->lt(today())) {
            return 'expired';
        }

        return $this->expiry_date->lte(today()->addDays(self::WARN_DAYS)) ? 'soon' : 'ok';
    }
}
