<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Project stage transitions, for databases created before this rule existed.
 * The same SQL lives in database/schema/rroka_schema.sql (fresh installs);
 * CREATE OR REPLACE / DROP IF EXISTS make running both harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Project stage transitions (Odoo-style stage bar). Closed projects are final.
CREATE OR REPLACE FUNCTION fn_project_status_guard() RETURNS trigger AS $$
BEGIN
    IF NEW.status = OLD.status THEN
        RETURN NEW;
    END IF;
    IF NOT (
           (OLD.status = 'ACTIVE'        AND NEW.status IN ('IN_PRODUCTION', 'ON_HOLD', 'CANCELLED'))
        OR (OLD.status = 'IN_PRODUCTION' AND NEW.status IN ('INSTALLATION', 'ON_HOLD', 'CANCELLED'))
        OR (OLD.status = 'INSTALLATION'  AND NEW.status IN ('COMPLETED', 'ON_HOLD'))
        OR (OLD.status = 'ON_HOLD'       AND NEW.status IN ('ACTIVE', 'IN_PRODUCTION', 'INSTALLATION', 'CANCELLED'))
    ) THEN
        RAISE EXCEPTION 'RROKA_PROJECT_TRANSITION: % -> % not allowed', OLD.status, NEW.status
            USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_project_status_guard ON projects;
CREATE TRIGGER trg_project_status_guard BEFORE UPDATE OF status ON projects
    FOR EACH ROW EXECUTE FUNCTION fn_project_status_guard();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_project_status_guard ON projects; DROP FUNCTION IF EXISTS fn_project_status_guard();');
    }
};
