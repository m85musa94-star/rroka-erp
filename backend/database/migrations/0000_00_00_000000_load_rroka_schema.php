<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The business schema lives in ../database/schema/rroka_schema.sql, the single
 * source of truth tested by scripts/test-db.sh. This migration loads it as-is
 * (minus its own BEGIN/COMMIT, since Laravel already wraps it in a transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = file_get_contents(base_path('../database/schema/rroka_schema.sql'));
        $sql = preg_replace('/^\s*(BEGIN|COMMIT);\s*$/mi', '', $sql);

        DB::unprepared($sql);
    }

    public function down(): void
    {
        throw new RuntimeException('The RRoka schema cannot be rolled back; restore from backup instead.');
    }
};
