<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrder extends Model
{
    public const STATUSES = ['PLANNED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];

    protected $fillable = ['project_id', 'design_version_id', 'planned_start', 'planned_end', 'notes'];

    protected function casts(): array
    {
        return ['planned_start' => 'date', 'planned_end' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function designVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class);
    }

    public function laborLogs(): HasMany
    {
        return $this->hasMany(LaborLog::class)->orderByDesc('work_date')->orderByDesc('id');
    }

    public function machineLogs(): HasMany
    {
        return $this->hasMany(MachineLog::class)->orderByDesc('work_date')->orderByDesc('id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(QualityInspection::class)->orderByDesc('inspected_at');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderByDesc('id');
    }
}
