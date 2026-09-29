<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'is_overhead', 'daftra_account_ref', 'is_active'];

    protected function casts(): array
    {
        return ['is_overhead' => 'boolean', 'is_active' => 'boolean'];
    }
}
