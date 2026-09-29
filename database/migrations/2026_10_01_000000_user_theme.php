<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Per-user colour theme (system/light/dark). Same SQL is in rroka_schema.sql; idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Per-user colour theme: follow the device (system), or always light / dark.
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme text NOT NULL DEFAULT 'system';
DO $$ BEGIN
    ALTER TABLE users ADD CONSTRAINT users_theme_check CHECK (theme IN ('system', 'light', 'dark'));
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;
SQL);
    }
};
