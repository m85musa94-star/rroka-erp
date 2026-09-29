<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RawMaterial extends Model
{
    protected $fillable = ['code', 'name', 'category', 'uom', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function balance(): HasOne
    {
        return $this->hasOne(StockBalance::class, 'material_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'material_id')->orderByDesc('id');
    }
}
