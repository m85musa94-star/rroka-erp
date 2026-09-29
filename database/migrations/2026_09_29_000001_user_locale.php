<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Per-user interface language (ar/en). Same SQL is in rroka_schema.sql; idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Per-user interface language.
ALTER TABLE users ADD COLUMN IF NOT EXISTS locale text NOT NULL DEFAULT 'ar';
DO $$ BEGIN
    ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('ar', 'en'));
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE users DROP COLUMN IF EXISTS locale');
    }
};
