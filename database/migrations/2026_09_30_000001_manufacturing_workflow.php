<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Manufacturing workflow rules (design version stages, production order stages),
 * for databases created before them. The same SQL lives in rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Manufacturing workflow rules (design version stages, production order stages)
-- ---------------------------------------------------------------------

-- Design versions move through the client-review cycle in order; a release
-- needs client approval; closed versions never reopen.
CREATE OR REPLACE FUNCTION fn_design_version_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.design_id <> OLD.design_id THEN
        RAISE EXCEPTION 'RROKA_DESIGN_VERSION_IMMUTABLE' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'RELEASED_FOR_PRODUCTION'
       AND (TG_OP = 'INSERT' OR OLD.status <> 'RELEASED_FOR_PRODUCTION') THEN
        IF TG_OP = 'INSERT' OR OLD.status <> 'CLIENT_APPROVED' THEN
            RAISE EXCEPTION 'RROKA_DESIGN_RELEASE_NEEDS_CLIENT_APPROVAL' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.status IN ('SUPERSEDED', 'REJECTED') AND NEW.status <> OLD.status THEN
        RAISE EXCEPTION 'RROKA_DESIGN_VERSION_CLOSED: % version cannot be reopened', OLD.status USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.status <> 'DRAFT' THEN
        RAISE EXCEPTION 'RROKA_DESIGN_TRANSITION: a new version starts as DRAFT' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.status <> OLD.status AND NOT (
           (OLD.status = 'DRAFT'                   AND NEW.status IN ('CLIENT_REVIEW', 'REJECTED'))
        OR (OLD.status = 'CLIENT_REVIEW'           AND NEW.status IN ('DRAFT', 'CLIENT_APPROVED', 'REJECTED'))
        OR (OLD.status = 'CLIENT_APPROVED'         AND NEW.status IN ('RELEASED_FOR_PRODUCTION', 'SUPERSEDED'))
        OR (OLD.status = 'RELEASED_FOR_PRODUCTION' AND NEW.status = 'SUPERSEDED')
    ) THEN
        RAISE EXCEPTION 'RROKA_DESIGN_TRANSITION: % -> % not allowed', OLD.status, NEW.status USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'CLIENT_APPROVED' AND NEW.client_approved_at IS NULL THEN
        RAISE EXCEPTION 'RROKA_DESIGN_TRANSITION: client approval needs its date' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

-- Production orders: PLANNED -> IN_PROGRESS -> COMPLETED; cancel before completion.
-- Named "a_" so it fires before the other BEFORE UPDATE triggers (alphabetical order).
CREATE OR REPLACE FUNCTION fn_production_order_status_guard() RETURNS trigger AS $$
BEGIN
    IF NEW.status = OLD.status THEN
        RETURN NEW;
    END IF;
    IF NOT (
           (OLD.status = 'PLANNED'     AND NEW.status IN ('IN_PROGRESS', 'CANCELLED'))
        OR (OLD.status = 'IN_PROGRESS' AND NEW.status IN ('COMPLETED', 'CANCELLED'))
    ) THEN
        -- Closed orders keep their dedicated message.
        IF OLD.status IN ('COMPLETED', 'CANCELLED') THEN
            RAISE EXCEPTION 'RROKA_PRODUCTION_ORDER_CLOSED' USING ERRCODE = 'P0001';
        END IF;
        RAISE EXCEPTION 'RROKA_PRODUCTION_TRANSITION: % -> % not allowed', OLD.status, NEW.status USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'IN_PROGRESS' AND NEW.started_at IS NULL THEN
        NEW.started_at := now();
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_production_a_stage_guard ON production_orders;
CREATE TRIGGER trg_production_a_stage_guard BEFORE UPDATE OF status ON production_orders
    FOR EACH ROW EXECUTE FUNCTION fn_production_order_status_guard();
SQL);
    }
};
