<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Supporting images attached to approved documents, append-only. Same SQL is at the end of rroka_schema.sql. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Supporting images added after approval (2026-10-16). An approved document
-- never changes; its missing receipt / invoice image is attached beside it,
-- append-only (never edited or removed), and recorded in the audit log.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_attachments (
    id           bigserial PRIMARY KEY,
    source_type  text NOT NULL CHECK (source_type IN ('EXPENSE', 'PURCHASE', 'SALE', 'RECEIPT', 'SUPPLIER_PAYMENT', 'OWNER', 'VAT_RETURN')),
    source_id    bigint NOT NULL,
    asset_id     bigint NOT NULL REFERENCES studio_assets(id),
    created_by   bigint NOT NULL REFERENCES users(id),
    created_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source_type, source_id, asset_id)
);
CREATE OR REPLACE FUNCTION fn_document_attachment_guard() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'RROKA_ATTACHMENT_LOCKED: an attached image stays on record' USING ERRCODE = 'P0001';
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_document_attachment_guard ON document_attachments;
CREATE TRIGGER trg_document_attachment_guard BEFORE UPDATE OR DELETE ON document_attachments
    FOR EACH ROW EXECUTE FUNCTION fn_document_attachment_guard();
DO $$
BEGIN
    DROP TRIGGER IF EXISTS trg_audit_document_attachments ON document_attachments;
    CREATE TRIGGER trg_audit_document_attachments AFTER INSERT OR UPDATE OR DELETE ON document_attachments FOR EACH ROW EXECUTE FUNCTION fn_audit();
END $$;

SQL);
    }
};
