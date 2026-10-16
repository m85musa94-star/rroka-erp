<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Data import from filled templates: batches, their records, and preview numbering. Same SQL is at the end of rroka_schema.sql. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Data import from filled templates (2026-10-17). Each row of an uploaded file
-- goes through the same save action as the screen it belongs to. The check runs
-- the save inside a savepoint and rolls it back; the import runs it again for
-- real, all rows or none. Batches and the records they made are kept on record.
-- ---------------------------------------------------------------------
-- A check (preview) must not use up document numbers: while `rroka.import_preview`
-- is on, numbers come from a throwaway sequence, and a marker row makes any
-- attempt to commit preview data fail at commit time.
CREATE SEQUENCE IF NOT EXISTS seq_import_preview;
CREATE SEQUENCE IF NOT EXISTS seq_import_no;
CREATE TABLE IF NOT EXISTS import_preview_marks (
    id         bigserial PRIMARY KEY,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE OR REPLACE FUNCTION fn_import_preview_never_commits() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'RROKA_IMPORT_PREVIEW_COMMIT: data written while checking a file is never saved' USING ERRCODE = 'P0001';
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_import_preview_never_commits ON import_preview_marks;
CREATE CONSTRAINT TRIGGER trg_import_preview_never_commits AFTER INSERT ON import_preview_marks
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fn_import_preview_never_commits();

CREATE OR REPLACE FUNCTION fn_doc_number(prefix text, seq regclass) RETURNS text AS $$
BEGIN
    IF current_setting('rroka.import_preview', true) = 'on' THEN
        INSERT INTO import_preview_marks DEFAULT VALUES;
        RETURN prefix || '-' || to_char(now(), 'YYYY') || '-T' || lpad(nextval('seq_import_preview')::text, 6, '0');
    END IF;
    RETURN prefix || '-' || to_char(now(), 'YYYY') || '-' || lpad(nextval(seq)::text, 6, '0');
END $$ LANGUAGE plpgsql VOLATILE;

CREATE TABLE IF NOT EXISTS import_batches (
    id            bigserial PRIMARY KEY,
    batch_no      text NOT NULL UNIQUE DEFAULT fn_doc_number('IMP', 'seq_import_no'),
    import_type   text NOT NULL CHECK (import_type ~ '^[a-z_]+$'),
    file_name     text NOT NULL CHECK (length(file_name) BETWEEN 1 AND 255),
    file_sha256   text NOT NULL CHECK (file_sha256 ~ '^[0-9a-f]{64}$'),
    rows          jsonb NOT NULL CHECK (jsonb_typeof(rows) = 'array'),
    row_count     integer NOT NULL CHECK (row_count >= 0),
    status        text NOT NULL DEFAULT 'PREVIEW' CHECK (status IN ('PREVIEW', 'IMPORTED', 'DISCARDED')),
    result        jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(result) = 'array'),
    error_count   integer NOT NULL DEFAULT 0 CHECK (error_count >= 0),
    record_count  integer NOT NULL DEFAULT 0 CHECK (record_count >= 0),
    checked_at    timestamptz,
    created_by    bigint NOT NULL REFERENCES users(id),
    created_at    timestamptz NOT NULL DEFAULT now(),
    imported_by   bigint REFERENCES users(id),
    imported_at   timestamptz,
    CHECK ((status = 'IMPORTED') = (imported_by IS NOT NULL AND imported_at IS NOT NULL)),
    CHECK (status <> 'IMPORTED' OR error_count = 0)
);
-- The same file is imported once per screen.
CREATE UNIQUE INDEX IF NOT EXISTS ux_import_batches_file ON import_batches (import_type, file_sha256) WHERE status = 'IMPORTED';
CREATE INDEX IF NOT EXISTS ix_import_batches_type ON import_batches (import_type, created_at DESC);

CREATE TABLE IF NOT EXISTS import_batch_records (
    id            bigserial PRIMARY KEY,
    batch_id      bigint NOT NULL REFERENCES import_batches(id),
    row_no        integer NOT NULL CHECK (row_no > 0),
    record_table  text NOT NULL CHECK (record_table ~ '^[a-z_]+$'),
    record_id     bigint NOT NULL,
    UNIQUE (record_table, record_id)
);
CREATE INDEX IF NOT EXISTS ix_import_batch_records_batch ON import_batch_records (batch_id);

-- A batch is checked (PREVIEW) any number of times, then imported or discarded once;
-- its rows never change and nothing is deleted.
CREATE OR REPLACE FUNCTION fn_import_batch_guard() RETURNS trigger AS $$
DECLARE
    n integer;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'RROKA_IMPORT_LOCKED: an import batch stays on record' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'PREVIEW' THEN
            RAISE EXCEPTION 'RROKA_IMPORT_LOCKED: a batch starts as a check' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.row_count <> jsonb_array_length(NEW.rows) THEN
            RAISE EXCEPTION 'RROKA_IMPORT_LOCKED: row count does not match the rows' USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'PREVIEW' THEN
        RAISE EXCEPTION 'RROKA_IMPORT_LOCKED: an imported or discarded batch does not change' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.rows IS DISTINCT FROM OLD.rows OR NEW.row_count <> OLD.row_count OR NEW.import_type <> OLD.import_type
       OR NEW.file_sha256 <> OLD.file_sha256 OR NEW.file_name <> OLD.file_name OR NEW.batch_no <> OLD.batch_no
       OR NEW.created_by <> OLD.created_by OR NEW.created_at <> OLD.created_at THEN
        RAISE EXCEPTION 'RROKA_IMPORT_LOCKED: the uploaded rows do not change; upload the file again' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'IMPORTED' THEN
        SELECT count(*) INTO n FROM import_batch_records WHERE batch_id = NEW.id;
        IF n <> NEW.record_count THEN
            RAISE EXCEPTION 'RROKA_IMPORT_LOCKED: imported record count does not match' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_import_batch_guard ON import_batches;
CREATE TRIGGER trg_import_batch_guard BEFORE INSERT OR UPDATE OR DELETE ON import_batches
    FOR EACH ROW EXECUTE FUNCTION fn_import_batch_guard();

-- Records are linked while their batch is being imported, never after; append-only.
CREATE OR REPLACE FUNCTION fn_import_batch_record_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'RROKA_IMPORT_LOCKED: an imported record link stays on record' USING ERRCODE = 'P0001';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM import_batches WHERE id = NEW.batch_id AND status = 'PREVIEW') THEN
        RAISE EXCEPTION 'RROKA_IMPORT_LOCKED: records are linked only while their batch is imported' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_import_batch_record_guard ON import_batch_records;
CREATE TRIGGER trg_import_batch_record_guard BEFORE INSERT OR UPDATE OR DELETE ON import_batch_records
    FOR EACH ROW EXECUTE FUNCTION fn_import_batch_record_guard();
DO $$
BEGIN
    DROP TRIGGER IF EXISTS trg_audit_import_batches ON import_batches;
    CREATE TRIGGER trg_audit_import_batches AFTER INSERT OR UPDATE OR DELETE ON import_batches FOR EACH ROW EXECUTE FUNCTION fn_audit();
    DROP TRIGGER IF EXISTS trg_audit_import_batch_records ON import_batch_records;
    CREATE TRIGGER trg_audit_import_batch_records AFTER INSERT OR UPDATE OR DELETE ON import_batch_records FOR EACH ROW EXECUTE FUNCTION fn_audit();
END $$;

SQL);
    }
};
