<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Studio (image library) and quotation-line images, for databases created
 * before it existed. The same SQL lives at the end of rroka_schema.sql
 * (fresh installs); IF NOT EXISTS / CREATE OR REPLACE make running both harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Studio — the workshop's image library (customer references, finished
-- work, catalogue, site photos, materials). A quotation line may show one.
-- Files live on the `studio` disk (object storage in production); the row
-- is the record of the file and is immutable in its file identity.
-- ---------------------------------------------------------------------

CREATE SEQUENCE IF NOT EXISTS seq_studio_no;

INSERT INTO permissions (code, description) VALUES
    ('studio.view', 'عرض الاستوديو'),
    ('studio.manage', 'رفع الصور وإدارتها في الاستوديو')
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS studio_assets (
    id           bigserial PRIMARY KEY,
    asset_no     text NOT NULL UNIQUE DEFAULT fn_doc_number('IMG', 'seq_studio_no'),
    title        text NOT NULL CHECK (btrim(title) <> ''),
    category     text NOT NULL CHECK (category IN ('CLIENT_REFERENCE', 'FINISHED_WORK', 'CATALOG', 'SITE', 'MATERIAL')),
    client_id    bigint REFERENCES clients(id),
    project_id   bigint REFERENCES projects(id),
    tags         text,
    notes        text,
    disk         text NOT NULL,
    path         text NOT NULL UNIQUE,
    thumb_path   text,
    mime_type    text NOT NULL CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp')),
    size_bytes   bigint NOT NULL CHECK (size_bytes > 0 AND size_bytes <= 20971520),
    width        int CHECK (width > 0),
    height       int CHECK (height > 0),
    sha256       text NOT NULL UNIQUE CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    uploaded_by  bigint REFERENCES users(id),
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    -- A photo sent by a customer is that customer's data: it must name them.
    CONSTRAINT studio_client_reference_has_client CHECK (category <> 'CLIENT_REFERENCE' OR client_id IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS ix_studio_category ON studio_assets (category);
CREATE INDEX IF NOT EXISTS ix_studio_client ON studio_assets (client_id);
CREATE INDEX IF NOT EXISTS ix_studio_project ON studio_assets (project_id);

ALTER TABLE quotation_lines ADD COLUMN IF NOT EXISTS studio_asset_id bigint REFERENCES studio_assets(id);
CREATE INDEX IF NOT EXISTS ix_quotation_lines_studio ON quotation_lines (studio_asset_id);

-- File identity is fixed; a project fixes the customer; used images stay.
CREATE OR REPLACE FUNCTION fn_studio_asset_guard() RETURNS trigger AS $$
DECLARE
    v_project_client bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (SELECT 1 FROM quotation_lines WHERE studio_asset_id = OLD.id) THEN
            RAISE EXCEPTION 'RROKA_STUDIO_ASSET_IN_USE: % is used in a quotation', OLD.asset_no USING ERRCODE = 'P0001';
        END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.disk, NEW.path, NEW.sha256, NEW.mime_type, NEW.size_bytes, NEW.asset_no)
        IS DISTINCT FROM (OLD.disk, OLD.path, OLD.sha256, OLD.mime_type, OLD.size_bytes, OLD.asset_no) THEN
        RAISE EXCEPTION 'RROKA_STUDIO_FILE_IMMUTABLE: upload a new image instead' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.project_id IS NOT NULL THEN
        SELECT client_id INTO v_project_client FROM projects WHERE id = NEW.project_id;
        IF NEW.client_id IS NULL THEN
            NEW.client_id := v_project_client;
        ELSIF NEW.client_id <> v_project_client THEN
            RAISE EXCEPTION 'RROKA_STUDIO_CLIENT_MISMATCH: project belongs to another customer' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    -- A customer's own photo cannot be re-labelled away from them once used in a quotation.
    IF TG_OP = 'UPDATE' AND OLD.category = 'CLIENT_REFERENCE'
       AND (NEW.category <> 'CLIENT_REFERENCE' OR NEW.client_id IS DISTINCT FROM OLD.client_id)
       AND EXISTS (SELECT 1 FROM quotation_lines WHERE studio_asset_id = OLD.id) THEN
        RAISE EXCEPTION 'RROKA_STUDIO_ASSET_IN_USE: a used customer photo keeps its customer' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_studio_asset_guard ON studio_assets;
CREATE TRIGGER trg_studio_asset_guard BEFORE INSERT OR UPDATE OR DELETE ON studio_assets
    FOR EACH ROW EXECUTE FUNCTION fn_studio_asset_guard();

-- A customer's photo may only appear in that customer's quotations.
CREATE OR REPLACE FUNCTION fn_quotation_line_asset_guard() RETURNS trigger AS $$
DECLARE
    v_category text;
    v_owner    bigint;
    v_client   bigint;
BEGIN
    IF NEW.studio_asset_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT category, client_id INTO v_category, v_owner FROM studio_assets WHERE id = NEW.studio_asset_id;
    SELECT client_id INTO v_client FROM quotations WHERE id = NEW.quotation_id;
    IF v_category = 'CLIENT_REFERENCE' AND v_owner <> v_client THEN
        RAISE EXCEPTION 'RROKA_STUDIO_PRIVATE_ASSET: image belongs to another customer' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_quotation_line_asset_guard ON quotation_lines;
CREATE TRIGGER trg_quotation_line_asset_guard BEFORE INSERT OR UPDATE OF studio_asset_id, quotation_id ON quotation_lines
    FOR EACH ROW EXECUTE FUNCTION fn_quotation_line_asset_guard();

CREATE OR REPLACE FUNCTION fn_quotation_client_asset_guard() RETURNS trigger AS $$
BEGIN
    IF NEW.client_id IS DISTINCT FROM OLD.client_id AND EXISTS (
        SELECT 1 FROM quotation_lines l JOIN studio_assets a ON a.id = l.studio_asset_id
         WHERE l.quotation_id = NEW.id AND a.category = 'CLIENT_REFERENCE' AND a.client_id <> NEW.client_id) THEN
        RAISE EXCEPTION 'RROKA_STUDIO_PRIVATE_ASSET: quotation shows another customer''s photo' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_quotation_client_asset_guard ON quotations;
CREATE TRIGGER trg_quotation_client_asset_guard BEFORE UPDATE OF client_id ON quotations
    FOR EACH ROW EXECUTE FUNCTION fn_quotation_client_asset_guard();

DROP TRIGGER IF EXISTS trg_audit_studio_assets ON studio_assets;
CREATE TRIGGER trg_audit_studio_assets AFTER INSERT OR UPDATE OR DELETE ON studio_assets
    FOR EACH ROW EXECUTE FUNCTION fn_audit();
DROP TRIGGER IF EXISTS trg_touch_studio_assets ON studio_assets;
CREATE TRIGGER trg_touch_studio_assets BEFORE UPDATE ON studio_assets
    FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at();

-- The technical admin role holds every permission, including new ones.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND p.code IN ('studio.view', 'studio.manage')
ON CONFLICT DO NOTHING;
SQL);
    }
};
