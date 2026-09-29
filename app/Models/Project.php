<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Project extends Model
{
    // contract_value and client_id are set by the database from the quotation.
    protected $fillable = ['quotation_id', 'title', 'start_date', 'target_date', 'manager_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'target_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function designs(): HasMany
    {
        return $this->hasMany(Design::class)->orderBy('id');
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class)->orderByDesc('id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function actualCost(): object
    {
        return DB::table('v_project_actual_cost')->where('project_id', $this->id)->first();
    }
}
