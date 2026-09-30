<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Registry of sample (demo) records so they can be removed as a set. Same SQL is in rroka_schema.sql. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Demo data registry: every sample record the "demo data" tool creates is
-- listed here, so the whole set can be removed later without touching real data.
-- Sample records are also labelled "تجريبي" in their names.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS demo_records (
    id          bigserial PRIMARY KEY,
    table_name  text   NOT NULL,
    row_id      bigint NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (table_name, row_id)
);
SQL);
    }
};
