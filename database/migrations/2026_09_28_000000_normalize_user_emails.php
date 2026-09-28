<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Login became case-insensitive; store existing emails lower-case (skip any that would collide). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE users u SET email = lower(trim(u.email))
             WHERE u.email <> lower(trim(u.email))
               AND NOT EXISTS (SELECT 1 FROM users o WHERE o.id <> u.id AND o.email = lower(trim(u.email)))
        SQL);
    }

    public function down(): void
    {
        // Irreversible data normalisation; nothing to undo.
    }
};
