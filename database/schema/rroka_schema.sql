-- =====================================================================
-- RRoka ERP — Operations & Costing schema (PostgreSQL 16)
-- ---------------------------------------------------------------------
-- Scope: operations and job costing ONLY. No accounting ledger, no tax
-- engine: invoicing, collections, VAT/ZATCA and Zakat live in Daftra.
--
-- Zero Assumption Policy: cost rates are never defaulted. A missing rate
-- makes the affected cost NULL (not configured), never zero.
--
-- Business rules are enforced here (triggers/constraints), and again at
-- the API layer. The UI is never the only guard.
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- 0. Shared helpers
-- ---------------------------------------------------------------------

CREATE OR REPLACE FUNCTION fn_touch_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END $$ LANGUAGE plpgsql;

-- The application sets `SET LOCAL rroka.user_id = '<id>'` per transaction
-- so the audit log knows who acted.
CREATE OR REPLACE FUNCTION fn_current_app_user() RETURNS bigint AS $$
    SELECT NULLIF(current_setting('rroka.user_id', true), '')::bigint
$$ LANGUAGE sql STABLE;

-- Human-readable document numbers: PREFIX-YYYY-000001.
-- These are operational references only; official invoice numbering is Daftra's.
CREATE SEQUENCE IF NOT EXISTS seq_client_no;
CREATE SEQUENCE IF NOT EXISTS seq_survey_no;
CREATE SEQUENCE IF NOT EXISTS seq_quotation_no;
CREATE SEQUENCE IF NOT EXISTS seq_project_no;
CREATE SEQUENCE IF NOT EXISTS seq_production_order_no;

CREATE OR REPLACE FUNCTION fn_doc_number(prefix text, seq regclass) RETURNS text AS $$
    SELECT prefix || '-' || to_char(now(), 'YYYY') || '-' || lpad(nextval(seq)::text, 6, '0')
$$ LANGUAGE sql VOLATILE;

-- ---------------------------------------------------------------------
-- 1. Access control (RBAC)
-- Role names/assignments are a business decision: no roles are seeded.
-- ---------------------------------------------------------------------

CREATE TABLE users (
    id                bigserial PRIMARY KEY,
    name              text        NOT NULL,
    email             text        NOT NULL UNIQUE,
    email_verified_at timestamptz,
    password          text        NOT NULL,
    remember_token    text,
    is_active         boolean     NOT NULL DEFAULT true,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);

-- Per-user interface language.
ALTER TABLE users ADD COLUMN IF NOT EXISTS locale text NOT NULL DEFAULT 'ar';
DO $$ BEGIN
    ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('ar', 'en'));
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;
-- Per-user colour theme: follow the device (system), or always light / dark.
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme text NOT NULL DEFAULT 'system';
DO $$ BEGIN
    ALTER TABLE users ADD CONSTRAINT users_theme_check CHECK (theme IN ('system', 'light', 'dark'));
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

CREATE TABLE roles (
    id          bigserial PRIMARY KEY,
    code        text NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_]*$'),
    name_ar     text NOT NULL,
    description text,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE permissions (
    id          bigserial PRIMARY KEY,
    code        text NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_.]*$'),
    description text
);

CREATE TABLE role_permissions (
    role_id       bigint NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id bigint NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_roles (
    user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id bigint NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

-- Permission catalogue = what the system can do (technical, not business data).
INSERT INTO permissions (code, description) VALUES
    ('clients.view', 'عرض العملاء'),
    ('clients.manage', 'إضافة وتعديل العملاء'),
    ('surveys.manage', 'إدارة المعاينات'),
    ('quotations.view', 'عرض عروض الأسعار'),
    ('quotations.manage', 'إعداد وتعديل عروض الأسعار'),
    ('quotations.approve', 'اعتماد عروض الأسعار'),
    ('projects.view', 'عرض المشاريع'),
    ('projects.manage', 'إنشاء وإدارة المشاريع'),
    ('designs.manage', 'إدارة التصاميم ونسخها'),
    ('designs.release', 'إصدار نسخة تصميم للإنتاج'),
    ('bom.manage', 'إدارة قوائم المواد'),
    ('inventory.view', 'عرض أرصدة الخامات'),
    ('inventory.move', 'تسجيل حركات الخامات'),
    ('production.manage', 'إدارة أوامر الإنتاج'),
    ('production.log_time', 'تسجيل ساعات العمل والآلات'),
    ('quality.inspect', 'تسجيل فحوصات الجودة'),
    ('installations.manage', 'إدارة التركيب'),
    ('costing.view', 'عرض التكلفة الفعلية والربحية'),
    ('settings.cost_rates', 'إدخال معدلات التكلفة'),
    ('daftra.sync', 'المزامنة مع دفترة'),
    ('users.manage', 'إدارة المستخدمين والأدوار'),
    ('audit.view', 'عرض سجل التدقيق');

-- ---------------------------------------------------------------------
-- 2. Clients
-- ---------------------------------------------------------------------

CREATE TABLE clients (
    id                   bigserial PRIMARY KEY,
    client_no            text NOT NULL UNIQUE DEFAULT fn_doc_number('C', 'seq_client_no'),
    business_name        text NOT NULL CHECK (btrim(business_name) <> ''),
    client_type          text NOT NULL DEFAULT 'INDIVIDUAL'
                         CHECK (client_type IN ('INDIVIDUAL', 'COMPANY')),
    phone                text,
    email                text,
    vat_number           text CHECK (vat_number IS NULL OR vat_number ~ '^[0-9]{15}$'),
    commercial_reg_no    text,
    city                 text,
    address              text,
    notes                text,
    daftra_client_id     bigint UNIQUE,
    daftra_client_number text,
    created_by           bigint REFERENCES users(id),
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- 3. Site surveys (المعاينات)
-- ---------------------------------------------------------------------

CREATE TABLE site_surveys (
    id             bigserial PRIMARY KEY,
    survey_no      text NOT NULL UNIQUE DEFAULT fn_doc_number('SV', 'seq_survey_no'),
    client_id      bigint NOT NULL REFERENCES clients(id),
    scheduled_at   timestamptz,
    performed_at   timestamptz,
    surveyor_id    bigint REFERENCES users(id),
    site_address   text,
    measurements   jsonb,
    notes          text,
    status         text NOT NULL DEFAULT 'SCHEDULED'
                   CHECK (status IN ('SCHEDULED', 'COMPLETED', 'CANCELLED')),
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'COMPLETED' OR performed_at IS NOT NULL)
);

-- ---------------------------------------------------------------------
-- 4. Quotations (عروض الأسعار)
-- Prices are stored before VAT. Tax is computed by Daftra, not here.
-- ---------------------------------------------------------------------

CREATE TABLE quotations (
    id                 bigserial PRIMARY KEY,
    quotation_no       text NOT NULL UNIQUE DEFAULT fn_doc_number('Q', 'seq_quotation_no'),
    client_id          bigint NOT NULL REFERENCES clients(id),
    survey_id          bigint REFERENCES site_surveys(id),
    status             text NOT NULL DEFAULT 'DRAFT'
                       CHECK (status IN ('DRAFT', 'SENT', 'APPROVED', 'REJECTED', 'EXPIRED', 'CANCELLED')),
    issue_date         date NOT NULL DEFAULT current_date,
    valid_until        date,
    discount_amount    numeric(14,2) NOT NULL DEFAULT 0 CHECK (discount_amount >= 0),
    notes              text,
    approved_at        timestamptz,
    approved_by        bigint REFERENCES users(id),
    daftra_estimate_id bigint UNIQUE,
    created_by         bigint REFERENCES users(id),
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (valid_until IS NULL OR valid_until >= issue_date),
    CHECK (status <> 'APPROVED' OR (approved_at IS NOT NULL AND approved_by IS NOT NULL))
);

CREATE TABLE quotation_lines (
    id             bigserial PRIMARY KEY,
    quotation_id   bigint NOT NULL REFERENCES quotations(id) ON DELETE CASCADE,
    line_no        int    NOT NULL CHECK (line_no > 0),
    description    text   NOT NULL CHECK (btrim(description) <> ''),
    quantity       numeric(12,3) NOT NULL CHECK (quantity > 0),
    unit           text   NOT NULL DEFAULT 'قطعة',
    unit_price     numeric(14,2) NOT NULL CHECK (unit_price >= 0),
    line_total     numeric(16,2) GENERATED ALWAYS AS (round(quantity * unit_price, 2)) STORED,
    daftra_tax_id  bigint,
    UNIQUE (quotation_id, line_no)
);

CREATE VIEW v_quotation_totals AS
SELECT q.id AS quotation_id,
       COALESCE(sum(l.line_total), 0)                     AS subtotal,
       q.discount_amount,
       COALESCE(sum(l.line_total), 0) - q.discount_amount AS net_before_vat
FROM quotations q
LEFT JOIN quotation_lines l ON l.quotation_id = q.id
GROUP BY q.id;

-- Allowed status transitions; an approved/closed quotation is frozen.
CREATE OR REPLACE FUNCTION fn_quotation_guard() RETURNS trigger AS $$
DECLARE
    v_lines int;
    v_net   numeric;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        -- Only a DRAFT is editable. After that the only changes allowed are
        -- linking the Daftra estimate, and (from SENT) a status move with its approval stamp.
        IF OLD.status <> 'DRAFT' THEN
            IF NEW.status = OLD.status
               AND (to_jsonb(NEW) - 'daftra_estimate_id' - 'updated_at')
                 = (to_jsonb(OLD) - 'daftra_estimate_id' - 'updated_at') THEN
                RETURN NEW;
            END IF;
            IF NOT (OLD.status = 'SENT' AND NEW.status <> 'SENT'
               AND (to_jsonb(NEW) - 'status' - 'approved_at' - 'approved_by' - 'daftra_estimate_id' - 'updated_at')
                 = (to_jsonb(OLD) - 'status' - 'approved_at' - 'approved_by' - 'daftra_estimate_id' - 'updated_at')) THEN
                RAISE EXCEPTION 'RROKA_QUOTATION_LOCKED: quotation % is % and cannot be modified', OLD.quotation_no, OLD.status
                    USING ERRCODE = 'P0001';
            END IF;
        END IF;

        IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
               (OLD.status = 'DRAFT' AND NEW.status IN ('SENT', 'CANCELLED'))
            OR (OLD.status = 'SENT'  AND NEW.status IN ('APPROVED', 'REJECTED', 'EXPIRED', 'DRAFT', 'CANCELLED'))
        ) THEN
            RAISE EXCEPTION 'RROKA_QUOTATION_TRANSITION: % -> % not allowed', OLD.status, NEW.status
                USING ERRCODE = 'P0001';
        END IF;
    END IF;

    IF NEW.status IN ('SENT', 'APPROVED') AND (TG_OP = 'INSERT' OR NEW.status IS DISTINCT FROM OLD.status) THEN
        SELECT count(*), COALESCE(sum(line_total), 0) - NEW.discount_amount
          INTO v_lines, v_net
          FROM quotation_lines WHERE quotation_id = NEW.id;
        IF v_lines = 0 THEN
            RAISE EXCEPTION 'RROKA_QUOTATION_EMPTY: quotation has no lines' USING ERRCODE = 'P0001';
        END IF;
        IF v_net < 0 THEN
            RAISE EXCEPTION 'RROKA_QUOTATION_NEGATIVE: discount exceeds subtotal' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_quotation_guard BEFORE INSERT OR UPDATE ON quotations
    FOR EACH ROW EXECUTE FUNCTION fn_quotation_guard();

CREATE OR REPLACE FUNCTION fn_quotation_lines_guard() RETURNS trigger AS $$
DECLARE
    v_status text;
BEGIN
    SELECT status INTO v_status FROM quotations
     WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.quotation_id ELSE NEW.quotation_id END;
    -- v_status is NULL during a cascade delete of the parent: allow.
    IF v_status IS NOT NULL AND v_status <> 'DRAFT' THEN
        RAISE EXCEPTION 'RROKA_QUOTATION_LOCKED: lines can only change while quotation is DRAFT (now %)', v_status
            USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.quotation_id <> OLD.quotation_id THEN
        RAISE EXCEPTION 'RROKA_QUOTATION_LOCKED: a line cannot move between quotations' USING ERRCODE = 'P0001';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_quotation_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON quotation_lines
    FOR EACH ROW EXECUTE FUNCTION fn_quotation_lines_guard();

-- ---------------------------------------------------------------------
-- 5. Projects — only from an APPROVED quotation (one project per quote)
-- ---------------------------------------------------------------------

CREATE TABLE projects (
    id              bigserial PRIMARY KEY,
    project_no      text NOT NULL UNIQUE DEFAULT fn_doc_number('P', 'seq_project_no'),
    quotation_id    bigint NOT NULL UNIQUE REFERENCES quotations(id),
    client_id       bigint NOT NULL REFERENCES clients(id),
    title           text   NOT NULL,
    contract_value  numeric(16,2) NOT NULL,   -- net before VAT, copied from the quotation
    status          text NOT NULL DEFAULT 'ACTIVE'
                    CHECK (status IN ('ACTIVE', 'IN_PRODUCTION', 'INSTALLATION', 'COMPLETED', 'ON_HOLD', 'CANCELLED')),
    start_date      date NOT NULL DEFAULT current_date,
    target_date     date,
    completed_at    timestamptz,
    manager_id      bigint REFERENCES users(id),
    created_by      bigint REFERENCES users(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'COMPLETED' OR completed_at IS NOT NULL)
);

CREATE OR REPLACE FUNCTION fn_project_guard() RETURNS trigger AS $$
DECLARE
    q record;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.quotation_id <> OLD.quotation_id OR NEW.client_id <> OLD.client_id
           OR NEW.contract_value <> OLD.contract_value THEN
            RAISE EXCEPTION 'RROKA_PROJECT_IMMUTABLE: quotation, client and contract value are fixed at creation'
                USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;

    SELECT q2.status, q2.client_id, t.net_before_vat INTO q
      FROM quotations q2 JOIN v_quotation_totals t ON t.quotation_id = q2.id
     WHERE q2.id = NEW.quotation_id;

    IF q.status IS DISTINCT FROM 'APPROVED' THEN
        RAISE EXCEPTION 'RROKA_PROJECT_NEEDS_APPROVED_QUOTATION: quotation status is %', COALESCE(q.status, 'NOT FOUND')
            USING ERRCODE = 'P0001';
    END IF;
    IF NEW.client_id IS NULL THEN
        NEW.client_id := q.client_id;
    ELSIF NEW.client_id <> q.client_id THEN
        RAISE EXCEPTION 'RROKA_PROJECT_CLIENT_MISMATCH' USING ERRCODE = 'P0001';
    END IF;
    NEW.contract_value := q.net_before_vat;   -- never typed by hand
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_project_guard BEFORE INSERT OR UPDATE ON projects
    FOR EACH ROW EXECUTE FUNCTION fn_project_guard();

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

-- ---------------------------------------------------------------------
-- 6. Designs and versions
-- ---------------------------------------------------------------------

CREATE TABLE designs (
    id          bigserial PRIMARY KEY,
    project_id  bigint NOT NULL REFERENCES projects(id),
    title       text   NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE design_versions (
    id              bigserial PRIMARY KEY,
    design_id       bigint NOT NULL REFERENCES designs(id),
    version_no      int    NOT NULL CHECK (version_no > 0),
    status          text   NOT NULL DEFAULT 'DRAFT'
                    CHECK (status IN ('DRAFT', 'CLIENT_REVIEW', 'CLIENT_APPROVED', 'RELEASED_FOR_PRODUCTION', 'SUPERSEDED', 'REJECTED')),
    file_url        text,
    change_notes    text,
    client_approved_at timestamptz,
    released_at     timestamptz,
    released_by     bigint REFERENCES users(id),
    created_by      bigint REFERENCES users(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (design_id, version_no),
    CHECK (status <> 'RELEASED_FOR_PRODUCTION' OR (released_at IS NOT NULL AND released_by IS NOT NULL))
);

-- At most ONE version per design may be released for production.
CREATE UNIQUE INDEX ux_design_one_released
    ON design_versions (design_id) WHERE status = 'RELEASED_FOR_PRODUCTION';

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

CREATE TRIGGER trg_design_version_guard BEFORE INSERT OR UPDATE ON design_versions
    FOR EACH ROW EXECUTE FUNCTION fn_design_version_guard();

-- ---------------------------------------------------------------------
-- 7. Raw materials (خامات ومستلزمات) — no finished-goods stock exists
-- ---------------------------------------------------------------------

CREATE TABLE raw_materials (
    id          bigserial PRIMARY KEY,
    code        text NOT NULL UNIQUE,
    name        text NOT NULL,
    category    text,
    uom         text NOT NULL,
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- 8. Bills of materials
-- ---------------------------------------------------------------------

CREATE TABLE bom_templates (
    id          bigserial PRIMARY KEY,
    name        text NOT NULL UNIQUE,
    description text,
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE bom_template_lines (
    id              bigserial PRIMARY KEY,
    bom_template_id bigint NOT NULL REFERENCES bom_templates(id) ON DELETE CASCADE,
    material_id     bigint NOT NULL REFERENCES raw_materials(id),
    quantity        numeric(14,4) NOT NULL CHECK (quantity > 0),
    waste_pct       numeric(5,2)  NOT NULL DEFAULT 0 CHECK (waste_pct >= 0 AND waste_pct < 100),
    UNIQUE (bom_template_id, material_id)
);

-- BOM of a specific design version (what production will actually consume).
CREATE TABLE design_bom_lines (
    id                bigserial PRIMARY KEY,
    design_version_id bigint NOT NULL REFERENCES design_versions(id) ON DELETE CASCADE,
    material_id       bigint NOT NULL REFERENCES raw_materials(id),
    quantity          numeric(14,4) NOT NULL CHECK (quantity > 0),
    waste_pct         numeric(5,2)  NOT NULL DEFAULT 0 CHECK (waste_pct >= 0 AND waste_pct < 100),
    UNIQUE (design_version_id, material_id)
);

-- A released BOM is frozen: changes require a new design version.
CREATE OR REPLACE FUNCTION fn_design_bom_guard() RETURNS trigger AS $$
DECLARE
    v_status text;
BEGIN
    SELECT status INTO v_status FROM design_versions
     WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.design_version_id ELSE NEW.design_version_id END;
    IF v_status IN ('RELEASED_FOR_PRODUCTION', 'SUPERSEDED') THEN
        RAISE EXCEPTION 'RROKA_BOM_LOCKED: BOM of a % version cannot change', v_status USING ERRCODE = 'P0001';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_design_bom_guard BEFORE INSERT OR UPDATE OR DELETE ON design_bom_lines
    FOR EACH ROW EXECUTE FUNCTION fn_design_bom_guard();

-- ---------------------------------------------------------------------
-- 9. Production orders — only on a RELEASED design version of the project
-- ---------------------------------------------------------------------

CREATE TABLE production_orders (
    id                bigserial PRIMARY KEY,
    order_no          text NOT NULL UNIQUE DEFAULT fn_doc_number('PO', 'seq_production_order_no'),
    project_id        bigint NOT NULL REFERENCES projects(id),
    design_version_id bigint NOT NULL REFERENCES design_versions(id),
    status            text NOT NULL DEFAULT 'PLANNED'
                      CHECK (status IN ('PLANNED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED')),
    planned_start     date,
    planned_end       date,
    started_at        timestamptz,
    completed_at      timestamptz,
    notes             text,
    created_by        bigint REFERENCES users(id),
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    CHECK (planned_end IS NULL OR planned_start IS NULL OR planned_end >= planned_start),
    CHECK (status <> 'COMPLETED' OR completed_at IS NOT NULL)
);

CREATE OR REPLACE FUNCTION fn_production_order_guard() RETURNS trigger AS $$
DECLARE
    v_status     text;
    v_project_id bigint;
    v_proj_state text;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.project_id <> OLD.project_id OR NEW.design_version_id <> OLD.design_version_id THEN
            RAISE EXCEPTION 'RROKA_PRODUCTION_ORDER_IMMUTABLE' USING ERRCODE = 'P0001';
        END IF;
        IF OLD.status IN ('COMPLETED', 'CANCELLED') AND NEW.status <> OLD.status THEN
            RAISE EXCEPTION 'RROKA_PRODUCTION_ORDER_CLOSED' USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;

    SELECT dv.status, d.project_id INTO v_status, v_project_id
      FROM design_versions dv JOIN designs d ON d.id = dv.design_id
     WHERE dv.id = NEW.design_version_id;

    IF v_status IS DISTINCT FROM 'RELEASED_FOR_PRODUCTION' THEN
        RAISE EXCEPTION 'RROKA_PRODUCTION_NEEDS_RELEASED_DESIGN: design version status is %', COALESCE(v_status, 'NOT FOUND')
            USING ERRCODE = 'P0001';
    END IF;
    IF v_project_id <> NEW.project_id THEN
        RAISE EXCEPTION 'RROKA_PRODUCTION_DESIGN_PROJECT_MISMATCH' USING ERRCODE = 'P0001';
    END IF;
    SELECT status INTO v_proj_state FROM projects WHERE id = NEW.project_id;
    IF v_proj_state IN ('COMPLETED', 'CANCELLED', 'ON_HOLD') THEN
        RAISE EXCEPTION 'RROKA_PRODUCTION_PROJECT_NOT_ACTIVE: project is %', v_proj_state USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_production_order_guard BEFORE INSERT OR UPDATE ON production_orders
    FOR EACH ROW EXECUTE FUNCTION fn_production_order_guard();

-- ---------------------------------------------------------------------
-- 10. Inventory: immutable movements + derived balances
-- ---------------------------------------------------------------------

CREATE TABLE stock_movements (
    id                  bigserial PRIMARY KEY,
    material_id         bigint NOT NULL REFERENCES raw_materials(id),
    movement_type       text   NOT NULL CHECK (movement_type IN
                        ('RECEIPT', 'RESERVE', 'UNRESERVE', 'ISSUE', 'RETURN', 'ADJUST_IN', 'ADJUST_OUT')),
    quantity            numeric(14,4) NOT NULL CHECK (quantity > 0),
    -- RECEIPT/ADJUST_IN: entered cost. ISSUE/RETURN/ADJUST_OUT: filled by the trigger from average cost.
    unit_cost           numeric(14,4) CHECK (unit_cost IS NULL OR unit_cost >= 0),
    project_id          bigint REFERENCES projects(id),
    production_order_id bigint REFERENCES production_orders(id),
    from_reservation    boolean NOT NULL DEFAULT false,
    reference           text,   -- e.g. supplier delivery note / Daftra purchase ref
    reason              text,
    moved_at            timestamptz NOT NULL DEFAULT now(),
    created_by          bigint REFERENCES users(id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (movement_type NOT IN ('RESERVE', 'UNRESERVE', 'ISSUE', 'RETURN') OR project_id IS NOT NULL),
    CHECK (movement_type NOT IN ('ADJUST_IN', 'ADJUST_OUT') OR reason IS NOT NULL),
    CHECK (movement_type = 'ISSUE' OR from_reservation = false),
    CHECK (movement_type NOT IN ('RECEIPT', 'ADJUST_IN') OR unit_cost IS NOT NULL)
);

CREATE INDEX ix_stock_movements_material ON stock_movements (material_id);
CREATE INDEX ix_stock_movements_project  ON stock_movements (project_id);

CREATE TABLE stock_balances (
    material_id       bigint PRIMARY KEY REFERENCES raw_materials(id),
    qty_on_hand       numeric(14,4) NOT NULL DEFAULT 0 CHECK (qty_on_hand >= 0),
    qty_reserved      numeric(14,4) NOT NULL DEFAULT 0 CHECK (qty_reserved >= 0),
    qty_issued_total  numeric(14,4) NOT NULL DEFAULT 0 CHECK (qty_issued_total >= 0),
    avg_unit_cost     numeric(14,4),   -- NULL until the first costed receipt
    updated_at        timestamptz NOT NULL DEFAULT now(),
    CHECK (qty_reserved <= qty_on_hand)
);

-- Balances are derived: only the movement trigger may write them.
CREATE OR REPLACE FUNCTION fn_stock_balances_protect() RETURNS trigger AS $$
BEGIN
    IF COALESCE(current_setting('rroka.stock_internal', true), '') <> 'on' THEN
        RAISE EXCEPTION 'RROKA_DERIVED_TABLE: stock_balances is maintained by stock_movements only'
            USING ERRCODE = 'P0001';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_stock_balances_protect BEFORE INSERT OR UPDATE OR DELETE ON stock_balances
    FOR EACH ROW EXECUTE FUNCTION fn_stock_balances_protect();

CREATE OR REPLACE FUNCTION fn_stock_movements_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'RROKA_MOVEMENT_IMMUTABLE: post a reversing movement instead' USING ERRCODE = 'P0001';
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_stock_movements_immutable BEFORE UPDATE OR DELETE ON stock_movements
    FOR EACH ROW EXECUTE FUNCTION fn_stock_movements_immutable();

-- Per-project reservation still open for a material.
CREATE OR REPLACE FUNCTION fn_project_reserved(p_project bigint, p_material bigint) RETURNS numeric AS $$
    SELECT COALESCE(sum(CASE
               WHEN movement_type = 'RESERVE' THEN quantity
               WHEN movement_type = 'UNRESERVE' THEN -quantity
               WHEN movement_type = 'ISSUE' AND from_reservation THEN -quantity
               ELSE 0 END), 0)
      FROM stock_movements
     WHERE project_id = p_project AND material_id = p_material
$$ LANGUAGE sql STABLE;

-- Net quantity issued to a project (issues minus returns).
CREATE OR REPLACE FUNCTION fn_project_issued(p_project bigint, p_material bigint) RETURNS numeric AS $$
    SELECT COALESCE(sum(CASE
               WHEN movement_type = 'ISSUE' THEN quantity
               WHEN movement_type = 'RETURN' THEN -quantity
               ELSE 0 END), 0)
      FROM stock_movements
     WHERE project_id = p_project AND material_id = p_material
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_stock_movement_apply() RETURNS trigger AS $$
DECLARE
    b         stock_balances%ROWTYPE;
    v_avail   numeric;
    v_po_proj bigint;
BEGIN
    -- Serialize movements per material.
    PERFORM set_config('rroka.stock_internal', 'on', true);
    INSERT INTO stock_balances (material_id) VALUES (NEW.material_id) ON CONFLICT DO NOTHING;
    SELECT * INTO b FROM stock_balances WHERE material_id = NEW.material_id FOR UPDATE;
    v_avail := b.qty_on_hand - b.qty_reserved;

    IF NEW.production_order_id IS NOT NULL THEN
        SELECT project_id INTO v_po_proj FROM production_orders WHERE id = NEW.production_order_id;
        IF v_po_proj IS DISTINCT FROM NEW.project_id THEN
            RAISE EXCEPTION 'RROKA_STOCK_PROJECT_MISMATCH: production order belongs to another project'
                USING ERRCODE = 'P0001';
        END IF;
    END IF;

    CASE NEW.movement_type
    WHEN 'RECEIPT', 'ADJUST_IN' THEN
        b.avg_unit_cost := CASE
            WHEN b.qty_on_hand = 0 OR b.avg_unit_cost IS NULL THEN NEW.unit_cost
            ELSE round((b.qty_on_hand * b.avg_unit_cost + NEW.quantity * NEW.unit_cost)
                       / (b.qty_on_hand + NEW.quantity), 4)
        END;
        b.qty_on_hand := b.qty_on_hand + NEW.quantity;

    WHEN 'RESERVE' THEN
        IF NEW.quantity > v_avail THEN
            RAISE EXCEPTION 'RROKA_STOCK_INSUFFICIENT: available %, requested %', v_avail, NEW.quantity
                USING ERRCODE = 'P0001';
        END IF;
        b.qty_reserved := b.qty_reserved + NEW.quantity;

    WHEN 'UNRESERVE' THEN
        IF NEW.quantity > fn_project_reserved(NEW.project_id, NEW.material_id) THEN
            RAISE EXCEPTION 'RROKA_STOCK_UNRESERVE_EXCEEDS: project reservation is %',
                fn_project_reserved(NEW.project_id, NEW.material_id) USING ERRCODE = 'P0001';
        END IF;
        b.qty_reserved := b.qty_reserved - NEW.quantity;

    WHEN 'ISSUE' THEN
        IF NEW.from_reservation THEN
            IF NEW.quantity > fn_project_reserved(NEW.project_id, NEW.material_id) THEN
                RAISE EXCEPTION 'RROKA_STOCK_ISSUE_EXCEEDS_RESERVATION: project reservation is %',
                    fn_project_reserved(NEW.project_id, NEW.material_id) USING ERRCODE = 'P0001';
            END IF;
            b.qty_reserved := b.qty_reserved - NEW.quantity;
        ELSIF NEW.quantity > v_avail THEN
            RAISE EXCEPTION 'RROKA_STOCK_INSUFFICIENT: available %, requested %', v_avail, NEW.quantity
                USING ERRCODE = 'P0001';
        END IF;
        b.qty_on_hand      := b.qty_on_hand - NEW.quantity;
        b.qty_issued_total := b.qty_issued_total + NEW.quantity;
        NEW.unit_cost      := b.avg_unit_cost;  -- cost of what actually left the store

    WHEN 'RETURN' THEN
        IF NEW.quantity > fn_project_issued(NEW.project_id, NEW.material_id) THEN
            RAISE EXCEPTION 'RROKA_STOCK_RETURN_EXCEEDS_ISSUED: project net issued is %',
                fn_project_issued(NEW.project_id, NEW.material_id) USING ERRCODE = 'P0001';
        END IF;
        NEW.unit_cost := b.avg_unit_cost;
        b.qty_on_hand      := b.qty_on_hand + NEW.quantity;
        b.qty_issued_total := b.qty_issued_total - NEW.quantity;

    WHEN 'ADJUST_OUT' THEN
        IF NEW.quantity > v_avail THEN
            RAISE EXCEPTION 'RROKA_STOCK_INSUFFICIENT: available %, requested %', v_avail, NEW.quantity
                USING ERRCODE = 'P0001';
        END IF;
        NEW.unit_cost := b.avg_unit_cost;
        b.qty_on_hand := b.qty_on_hand - NEW.quantity;
    END CASE;

    UPDATE stock_balances
       SET qty_on_hand = b.qty_on_hand, qty_reserved = b.qty_reserved,
           qty_issued_total = b.qty_issued_total, avg_unit_cost = b.avg_unit_cost,
           updated_at = now()
     WHERE material_id = NEW.material_id;
    PERFORM set_config('rroka.stock_internal', 'off', true);
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_stock_movement_apply BEFORE INSERT ON stock_movements
    FOR EACH ROW EXECUTE FUNCTION fn_stock_movement_apply();

-- ---------------------------------------------------------------------
-- 11. Cost rates (Zero Assumption: nothing seeded, nothing defaulted)
-- Rates are effective-dated so history never gets re-priced.
-- ---------------------------------------------------------------------

CREATE TABLE workers (
    id          bigserial PRIMARY KEY,
    name        text NOT NULL,
    trade       text,              -- نجار، دهّان، فني تركيب ...
    user_id     bigint REFERENCES users(id),
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE worker_rates (
    id             bigserial PRIMARY KEY,
    worker_id      bigint NOT NULL REFERENCES workers(id),
    hourly_cost    numeric(12,4) NOT NULL CHECK (hourly_cost > 0),
    effective_from date NOT NULL,
    basis_note     text NOT NULL,  -- how the figure was derived (salary + allowances / hours)
    entered_by     bigint REFERENCES users(id),
    created_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (worker_id, effective_from)
);

CREATE TABLE machines (
    id          bigserial PRIMARY KEY,
    code        text NOT NULL UNIQUE,
    name        text NOT NULL,
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE machine_rates (
    id             bigserial PRIMARY KEY,
    machine_id     bigint NOT NULL REFERENCES machines(id),
    hourly_cost    numeric(12,4) NOT NULL CHECK (hourly_cost > 0),
    effective_from date NOT NULL,
    basis_note     text NOT NULL,
    entered_by     bigint REFERENCES users(id),
    created_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (machine_id, effective_from)
);

-- Overhead absorption. The basis is the owner's choice, recorded with the rate.
CREATE TABLE overhead_rates (
    id             bigserial PRIMARY KEY,
    basis          text NOT NULL CHECK (basis IN ('PCT_OF_DIRECT_LABOR', 'PCT_OF_PRIME_COST')),
    rate_pct       numeric(7,4) NOT NULL CHECK (rate_pct >= 0),
    effective_from date NOT NULL UNIQUE,
    basis_note     text NOT NULL,
    entered_by     bigint REFERENCES users(id),
    created_at     timestamptz NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION fn_rates_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'RROKA_RATE_HISTORY: rates are append-only; add a new effective_from row'
        USING ERRCODE = 'P0001';
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_worker_rates_append_only  BEFORE UPDATE OR DELETE ON worker_rates
    FOR EACH ROW EXECUTE FUNCTION fn_rates_append_only();
CREATE TRIGGER trg_machine_rates_append_only BEFORE UPDATE OR DELETE ON machine_rates
    FOR EACH ROW EXECUTE FUNCTION fn_rates_append_only();
CREATE TRIGGER trg_overhead_rates_append_only BEFORE UPDATE OR DELETE ON overhead_rates
    FOR EACH ROW EXECUTE FUNCTION fn_rates_append_only();

-- ---------------------------------------------------------------------
-- 12. Time logs (labor & machine)
-- ---------------------------------------------------------------------

CREATE TABLE labor_logs (
    id                  bigserial PRIMARY KEY,
    production_order_id bigint NOT NULL REFERENCES production_orders(id),
    worker_id           bigint NOT NULL REFERENCES workers(id),
    work_date           date   NOT NULL,
    hours               numeric(6,2) NOT NULL CHECK (hours > 0 AND hours <= 24),
    activity            text,
    created_by          bigint REFERENCES users(id),
    created_at          timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE machine_logs (
    id                  bigserial PRIMARY KEY,
    production_order_id bigint NOT NULL REFERENCES production_orders(id),
    machine_id          bigint NOT NULL REFERENCES machines(id),
    work_date           date   NOT NULL,
    hours               numeric(6,2) NOT NULL CHECK (hours > 0 AND hours <= 24),
    created_by          bigint REFERENCES users(id),
    created_at          timestamptz NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION fn_time_log_guard() RETURNS trigger AS $$
DECLARE
    v_status text;
BEGIN
    SELECT status INTO v_status FROM production_orders WHERE id = NEW.production_order_id;
    IF v_status IN ('PLANNED', 'CANCELLED') THEN
        RAISE EXCEPTION 'RROKA_TIME_LOG_ORDER_STATE: cannot log time on a % order', v_status USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_labor_log_guard   BEFORE INSERT ON labor_logs   FOR EACH ROW EXECUTE FUNCTION fn_time_log_guard();
CREATE TRIGGER trg_machine_log_guard BEFORE INSERT ON machine_logs FOR EACH ROW EXECUTE FUNCTION fn_time_log_guard();

-- Worker hours per day across all orders cannot exceed 24.
CREATE OR REPLACE FUNCTION fn_labor_daily_cap() RETURNS trigger AS $$
BEGIN
    IF (SELECT sum(hours) FROM labor_logs WHERE worker_id = NEW.worker_id AND work_date = NEW.work_date) > 24 THEN
        RAISE EXCEPTION 'RROKA_LABOR_DAY_OVER_24H' USING ERRCODE = 'P0001';
    END IF;
    RETURN NULL;
END $$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER trg_labor_daily_cap AFTER INSERT OR UPDATE ON labor_logs
    FOR EACH ROW EXECUTE FUNCTION fn_labor_daily_cap();

-- ---------------------------------------------------------------------
-- 13. Quality
-- ---------------------------------------------------------------------

CREATE TABLE quality_inspections (
    id                  bigserial PRIMARY KEY,
    production_order_id bigint REFERENCES production_orders(id),
    installation_id     bigint,   -- FK added after installations
    stage               text NOT NULL CHECK (stage IN ('IN_PROCESS', 'FINAL', 'PRE_DELIVERY', 'POST_INSTALLATION')),
    result              text NOT NULL CHECK (result IN ('PASS', 'FAIL', 'REWORK')),
    findings            text,
    inspector_id        bigint NOT NULL REFERENCES users(id),
    inspected_at        timestamptz NOT NULL DEFAULT now(),
    CHECK (num_nonnulls(production_order_id, installation_id) = 1),
    CHECK (result = 'PASS' OR findings IS NOT NULL)
);

-- A production order cannot be COMPLETED without a passing FINAL inspection.
CREATE OR REPLACE FUNCTION fn_production_completion_guard() RETURNS trigger AS $$
BEGIN
    IF NEW.status = 'COMPLETED' AND OLD.status <> 'COMPLETED' AND NOT EXISTS (
        SELECT 1 FROM quality_inspections
         WHERE production_order_id = NEW.id AND stage = 'FINAL' AND result = 'PASS'
    ) THEN
        RAISE EXCEPTION 'RROKA_PRODUCTION_NEEDS_FINAL_QC' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_production_completion_guard BEFORE UPDATE ON production_orders
    FOR EACH ROW EXECUTE FUNCTION fn_production_completion_guard();

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

CREATE TRIGGER trg_production_a_stage_guard BEFORE UPDATE OF status ON production_orders
    FOR EACH ROW EXECUTE FUNCTION fn_production_order_status_guard();

-- ---------------------------------------------------------------------
-- 14. Installation
-- ---------------------------------------------------------------------

CREATE TABLE installations (
    id               bigserial PRIMARY KEY,
    project_id       bigint NOT NULL REFERENCES projects(id),
    scheduled_date   date,
    site_address     text,
    status           text NOT NULL DEFAULT 'SCHEDULED'
                     CHECK (status IN ('SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED')),
    completed_at     timestamptz,
    client_signoff_name text,
    client_signoff_at   timestamptz,
    notes            text,
    created_by       bigint REFERENCES users(id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'COMPLETED' OR (completed_at IS NOT NULL AND client_signoff_at IS NOT NULL))
);

ALTER TABLE quality_inspections
    ADD CONSTRAINT fk_qi_installation FOREIGN KEY (installation_id) REFERENCES installations(id);

CREATE TABLE installation_workers (
    installation_id bigint NOT NULL REFERENCES installations(id) ON DELETE CASCADE,
    worker_id       bigint NOT NULL REFERENCES workers(id),
    work_date       date   NOT NULL,
    hours           numeric(6,2) NOT NULL CHECK (hours > 0 AND hours <= 24),
    PRIMARY KEY (installation_id, worker_id, work_date)
);

-- ---------------------------------------------------------------------
-- 15. Daftra integration log (every call is recorded, success or not)
-- ---------------------------------------------------------------------

CREATE TABLE daftra_sync_log (
    id              bigserial PRIMARY KEY,
    entity_type     text NOT NULL CHECK (entity_type IN ('CLIENT', 'QUOTATION', 'INVOICE')),
    entity_id       bigint NOT NULL,
    operation       text NOT NULL,              -- e.g. POST /clients
    status          text NOT NULL DEFAULT 'PENDING'
                    CHECK (status IN ('PENDING', 'SUCCESS', 'FAILED')),
    attempt         int  NOT NULL DEFAULT 1 CHECK (attempt > 0),
    request_payload jsonb,                      -- never contains credentials
    http_status     int,
    response_body   jsonb,
    daftra_id       bigint,
    error_message   text,
    requested_by    bigint REFERENCES users(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    finished_at     timestamptz,
    CHECK (status <> 'SUCCESS' OR daftra_id IS NOT NULL),
    CHECK (status <> 'FAILED'  OR error_message IS NOT NULL)
);

CREATE INDEX ix_daftra_sync_entity ON daftra_sync_log (entity_type, entity_id);

-- ---------------------------------------------------------------------
-- 16. Audit trail
-- ---------------------------------------------------------------------

CREATE TABLE audit_log (
    id          bigserial PRIMARY KEY,
    table_name  text NOT NULL,
    row_id      bigint,
    action      text NOT NULL CHECK (action IN ('INSERT', 'UPDATE', 'DELETE')),
    old_data    jsonb,
    new_data    jsonb,
    user_id     bigint,
    at          timestamptz NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION fn_audit() RETURNS trigger AS $$
DECLARE
    v_old jsonb := CASE WHEN TG_OP <> 'INSERT' THEN to_jsonb(OLD) END;
    v_new jsonb := CASE WHEN TG_OP <> 'DELETE' THEN to_jsonb(NEW) END;
BEGIN
    INSERT INTO audit_log (table_name, row_id, action, old_data, new_data, user_id)
    VALUES (TG_TABLE_NAME, (COALESCE(v_new, v_old)->>'id')::bigint, TG_OP, v_old, v_new, fn_current_app_user());
    RETURN NULL;
END $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION fn_audit_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'RROKA_AUDIT_IMMUTABLE' USING ERRCODE = 'P0001';
END $$ LANGUAGE plpgsql;

CREATE TRIGGER trg_audit_log_immutable BEFORE UPDATE OR DELETE ON audit_log
    FOR EACH ROW EXECUTE FUNCTION fn_audit_immutable();

DO $$
DECLARE
    t text;
BEGIN
    FOREACH t IN ARRAY ARRAY[
        'users', 'roles', 'role_permissions', 'user_roles', 'clients', 'site_surveys',
        'quotations', 'quotation_lines', 'projects', 'designs', 'design_versions',
        'raw_materials', 'bom_templates', 'bom_template_lines', 'design_bom_lines',
        'production_orders', 'stock_movements', 'workers', 'worker_rates', 'machines',
        'machine_rates', 'overhead_rates', 'labor_logs', 'machine_logs',
        'quality_inspections', 'installations', 'installation_workers'
    ] LOOP
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I
                        FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
    END LOOP;

    FOR t IN
        SELECT c.table_name FROM information_schema.columns c
         WHERE c.table_schema = 'public' AND c.column_name = 'updated_at'
           AND c.table_name IN (SELECT table_name FROM information_schema.tables
                                 WHERE table_schema = 'public' AND table_type = 'BASE TABLE')
           AND c.table_name <> 'stock_balances'
    LOOP
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I
                        FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

-- ---------------------------------------------------------------------
-- 17. Job costing views
-- Any cost component with a missing rate is NULL, and so is every total
-- built on it. The *_missing counters say exactly what is missing.
-- ---------------------------------------------------------------------

CREATE VIEW v_labor_cost_lines AS
SELECT 'PRODUCTION'::text AS source, l.id AS log_id, po.project_id, l.worker_id, l.work_date, l.hours,
       r.hourly_cost,
       round(l.hours * r.hourly_cost, 2) AS cost        -- NULL when no rate is effective
FROM labor_logs l
JOIN production_orders po ON po.id = l.production_order_id
LEFT JOIN LATERAL (
    SELECT hourly_cost FROM worker_rates wr
     WHERE wr.worker_id = l.worker_id AND wr.effective_from <= l.work_date
     ORDER BY wr.effective_from DESC LIMIT 1
) r ON true
UNION ALL
SELECT 'INSTALLATION', NULL, i.project_id, iw.worker_id, iw.work_date, iw.hours,
       r.hourly_cost, round(iw.hours * r.hourly_cost, 2)
FROM installation_workers iw
JOIN installations i ON i.id = iw.installation_id
LEFT JOIN LATERAL (
    SELECT hourly_cost FROM worker_rates wr
     WHERE wr.worker_id = iw.worker_id AND wr.effective_from <= iw.work_date
     ORDER BY wr.effective_from DESC LIMIT 1
) r ON true;

CREATE VIEW v_machine_cost_lines AS
SELECT m.id AS log_id, po.project_id, m.machine_id, m.work_date, m.hours,
       r.hourly_cost, round(m.hours * r.hourly_cost, 2) AS cost
FROM machine_logs m
JOIN production_orders po ON po.id = m.production_order_id
LEFT JOIN LATERAL (
    SELECT hourly_cost FROM machine_rates mr
     WHERE mr.machine_id = m.machine_id AND mr.effective_from <= m.work_date
     ORDER BY mr.effective_from DESC LIMIT 1
) r ON true;

CREATE VIEW v_project_actual_cost AS
WITH mat AS (
    SELECT project_id,
           sum(CASE movement_type WHEN 'ISSUE' THEN quantity * unit_cost
                                  WHEN 'RETURN' THEN -quantity * unit_cost END) AS cost,
           count(*) FILTER (WHERE movement_type IN ('ISSUE', 'RETURN') AND unit_cost IS NULL) AS missing
      FROM stock_movements
     WHERE movement_type IN ('ISSUE', 'RETURN')
     GROUP BY project_id
),
lab AS (
    SELECT project_id, sum(hours) AS hours, sum(cost) AS cost, count(*) FILTER (WHERE cost IS NULL) AS missing
      FROM v_labor_cost_lines GROUP BY project_id
),
mac AS (
    SELECT project_id, sum(hours) AS hours, sum(cost) AS cost, count(*) FILTER (WHERE cost IS NULL) AS missing
      FROM v_machine_cost_lines GROUP BY project_id
),
oh AS (
    SELECT p.id AS project_id, o.basis, o.rate_pct
      FROM projects p
      LEFT JOIN LATERAL (
          SELECT basis, rate_pct FROM overhead_rates
           WHERE effective_from <= p.start_date ORDER BY effective_from DESC LIMIT 1
      ) o ON true
),
base AS (
    SELECT p.id AS project_id, p.project_no, p.contract_value, p.status,
           CASE WHEN COALESCE(mat.missing, 0) = 0 THEN round(COALESCE(mat.cost, 0), 2) END AS material_cost,
           COALESCE(mat.missing, 0) AS material_lines_missing_cost,
           COALESCE(lab.hours, 0) AS labor_hours,
           CASE WHEN COALESCE(lab.missing, 0) = 0 THEN COALESCE(lab.cost, 0) END AS labor_cost,
           COALESCE(lab.missing, 0) AS labor_lines_missing_rate,
           COALESCE(mac.hours, 0) AS machine_hours,
           CASE WHEN COALESCE(mac.missing, 0) = 0 THEN COALESCE(mac.cost, 0) END AS machine_cost,
           COALESCE(mac.missing, 0) AS machine_lines_missing_rate,
           oh.basis AS overhead_basis, oh.rate_pct AS overhead_rate_pct
      FROM projects p
      LEFT JOIN mat ON mat.project_id = p.id
      LEFT JOIN lab ON lab.project_id = p.id
      LEFT JOIN mac ON mac.project_id = p.id
      LEFT JOIN oh  ON oh.project_id  = p.id
),
calc AS (
    SELECT b.*,
           round(CASE b.overhead_basis
                 WHEN 'PCT_OF_DIRECT_LABOR' THEN b.labor_cost * b.overhead_rate_pct / 100
                 WHEN 'PCT_OF_PRIME_COST'   THEN (b.material_cost + b.labor_cost + b.machine_cost) * b.overhead_rate_pct / 100
                 END, 2) AS overhead_cost   -- NULL when no overhead rate is configured
      FROM base b
)
SELECT c.*,
       c.material_cost + c.labor_cost + c.machine_cost + c.overhead_cost AS total_cost,
       c.contract_value - (c.material_cost + c.labor_cost + c.machine_cost + c.overhead_cost) AS gross_profit,
       CASE WHEN c.contract_value > 0 THEN
           round((c.contract_value - (c.material_cost + c.labor_cost + c.machine_cost + c.overhead_cost))
                 / c.contract_value * 100, 2) END AS gross_margin_pct,
       array_remove(ARRAY[
           CASE WHEN c.material_cost IS NULL THEN 'MATERIAL_COST_UNKNOWN' END,
           CASE WHEN c.labor_cost    IS NULL THEN 'WORKER_RATE_MISSING' END,
           CASE WHEN c.machine_cost  IS NULL THEN 'MACHINE_RATE_MISSING' END,
           CASE WHEN c.overhead_rate_pct IS NULL THEN 'OVERHEAD_RATE_MISSING' END
       ], NULL) AS costing_gaps
FROM calc c;

-- Material consumption vs. released BOM (variance analysis).
CREATE VIEW v_project_material_variance AS
WITH planned AS (
    SELECT d.project_id, bl.material_id,
           sum(bl.quantity * (1 + bl.waste_pct / 100)) AS qty_planned
      FROM design_bom_lines bl
      JOIN design_versions dv ON dv.id = bl.design_version_id AND dv.status = 'RELEASED_FOR_PRODUCTION'
      JOIN designs d ON d.id = dv.design_id
     GROUP BY d.project_id, bl.material_id
),
actual AS (
    SELECT project_id, material_id,
           sum(CASE movement_type WHEN 'ISSUE' THEN quantity WHEN 'RETURN' THEN -quantity END) AS qty_actual
      FROM stock_movements WHERE movement_type IN ('ISSUE', 'RETURN')
     GROUP BY project_id, material_id
)
SELECT COALESCE(p.project_id, a.project_id)   AS project_id,
       COALESCE(p.material_id, a.material_id) AS material_id,
       COALESCE(p.qty_planned, 0) AS qty_planned,
       COALESCE(a.qty_actual, 0)  AS qty_actual,
       COALESCE(a.qty_actual, 0) - COALESCE(p.qty_planned, 0) AS qty_variance,
       (p.material_id IS NULL) AS not_in_bom
FROM planned p
FULL JOIN actual a ON a.project_id = p.project_id AND a.material_id = p.material_id;

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

-- ---------------------------------------------------------------------
-- HR core (Odoo Employees style): departments, job positions, the employee
-- file (the existing `workers` table IS the employee master, so costing,
-- time logs and installation keep working), and identity/work documents
-- with expiry dates. Personal data: restricted by permission in the app.
-- ---------------------------------------------------------------------

CREATE SEQUENCE IF NOT EXISTS seq_employee_no;

INSERT INTO permissions (code, description) VALUES
    ('hr.view', 'عرض دليل الموظفين'),
    ('hr.manage', 'إدارة ملفات الموظفين ووثائقهم'),
    ('hr.contracts', 'عرض العقود والرواتب وإدارتها'),
    ('hr.attendance', 'تسجيل الحضور والانصراف'),
    ('hr.leave_approve', 'اعتماد الإجازات وأرصدتها')
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS departments (
    id          bigserial PRIMARY KEY,
    name        text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    parent_id   bigint REFERENCES departments(id),
    manager_id  bigint REFERENCES workers(id),
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    CHECK (parent_id IS NULL OR parent_id <> id)
);

CREATE TABLE IF NOT EXISTS job_positions (
    id             bigserial PRIMARY KEY,
    name           text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    department_id  bigint REFERENCES departments(id),
    description    text,
    is_active      boolean NOT NULL DEFAULT true,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE workers ADD COLUMN IF NOT EXISTS employee_no text DEFAULT fn_doc_number('E', 'seq_employee_no');
DO $$ BEGIN
    ALTER TABLE workers ALTER COLUMN employee_no SET NOT NULL;
    ALTER TABLE workers ADD CONSTRAINT workers_employee_no_key UNIQUE (employee_no);
EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL; END $$;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS department_id bigint REFERENCES departments(id);
ALTER TABLE workers ADD COLUMN IF NOT EXISTS job_id bigint REFERENCES job_positions(id);
ALTER TABLE workers ADD COLUMN IF NOT EXISTS manager_id bigint REFERENCES workers(id);
ALTER TABLE workers ADD COLUMN IF NOT EXISTS work_phone text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS mobile text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS work_email text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS nationality text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS id_type text CHECK (id_type IN ('NATIONAL_ID', 'IQAMA', 'PASSPORT'));
ALTER TABLE workers ADD COLUMN IF NOT EXISTS id_number text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS birth_date date;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS gender text CHECK (gender IN ('M', 'F'));
ALTER TABLE workers ADD COLUMN IF NOT EXISTS hire_date date;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS employment_type text CHECK (employment_type IN ('FULL_TIME', 'PART_TIME', 'CONTRACTOR'));
ALTER TABLE workers ADD COLUMN IF NOT EXISTS is_direct_labor boolean NOT NULL DEFAULT true;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS termination_date date;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS termination_reason text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS iban text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS emergency_contact text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS emergency_phone text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS address text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS notes text;
DO $$ BEGIN
    ALTER TABLE workers ADD CONSTRAINT workers_termination_after_hire
        CHECK (termination_date IS NULL OR hire_date IS NULL OR termination_date >= hire_date);
    ALTER TABLE workers ADD CONSTRAINT workers_not_own_manager CHECK (manager_id IS NULL OR manager_id <> id);
    ALTER TABLE workers ADD CONSTRAINT workers_iban_format CHECK (iban IS NULL OR iban ~ '^SA[0-9]{2}[0-9A-Z]{20}$');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
CREATE UNIQUE INDEX IF NOT EXISTS ux_workers_id_number ON workers (id_type, id_number) WHERE id_number IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_workers_user ON workers (user_id) WHERE user_id IS NOT NULL;

-- Ending employment needs its date and reason; the reporting line has no loops;
-- an employee's number never changes.
CREATE OR REPLACE FUNCTION fn_employee_guard() RETURNS trigger AS $$
DECLARE
    v_cursor bigint;
    v_steps  int := 0;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.employee_no IS DISTINCT FROM OLD.employee_no THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_IMMUTABLE: employee number cannot change' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.is_active AND NOT NEW.is_active
       AND (NEW.termination_date IS NULL OR NULLIF(btrim(NEW.termination_reason), '') IS NULL) THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_TERMINATION: ending employment needs its date and reason' USING ERRCODE = 'P0001';
    END IF;
    v_cursor := NEW.manager_id;
    WHILE v_cursor IS NOT NULL LOOP
        IF v_cursor = NEW.id OR v_steps > 50 THEN
            RAISE EXCEPTION 'RROKA_EMPLOYEE_MANAGER_LOOP: reporting line would loop' USING ERRCODE = 'P0001';
        END IF;
        SELECT manager_id INTO v_cursor FROM workers WHERE id = v_cursor;
        v_steps := v_steps + 1;
    END LOOP;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_employee_guard ON workers;
CREATE TRIGGER trg_employee_guard BEFORE INSERT OR UPDATE ON workers
    FOR EACH ROW EXECUTE FUNCTION fn_employee_guard();

CREATE TABLE IF NOT EXISTS employee_documents (
    id           bigserial PRIMARY KEY,
    employee_id  bigint NOT NULL REFERENCES workers(id) ON DELETE CASCADE,
    doc_type     text NOT NULL CHECK (doc_type IN ('NATIONAL_ID', 'IQAMA', 'PASSPORT', 'WORK_PERMIT', 'HEALTH_CERT', 'DRIVING_LICENSE', 'OTHER')),
    doc_number   text,
    issue_date   date,
    expiry_date  date,
    notes        text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CHECK (expiry_date IS NULL OR issue_date IS NULL OR expiry_date >= issue_date),
    CHECK (doc_type <> 'OTHER' OR NULLIF(btrim(notes), '') IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS ix_employee_documents_expiry ON employee_documents (expiry_date);

-- Hours cannot be logged for someone outside their employment period.
CREATE OR REPLACE FUNCTION fn_employment_period_guard() RETURNS trigger AS $$
DECLARE
    v_hire date;
    v_end  date;
BEGIN
    SELECT hire_date, termination_date INTO v_hire, v_end FROM workers WHERE id = NEW.worker_id;
    IF (v_hire IS NOT NULL AND NEW.work_date < v_hire) OR (v_end IS NOT NULL AND NEW.work_date > v_end) THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_NOT_EMPLOYED: % is outside the employment period', NEW.work_date USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_labor_employment_guard ON labor_logs;
CREATE TRIGGER trg_labor_employment_guard BEFORE INSERT OR UPDATE ON labor_logs
    FOR EACH ROW EXECUTE FUNCTION fn_employment_period_guard();
DROP TRIGGER IF EXISTS trg_installation_employment_guard ON installation_workers;
CREATE TRIGGER trg_installation_employment_guard BEFORE INSERT OR UPDATE ON installation_workers
    FOR EACH ROW EXECUTE FUNCTION fn_employment_period_guard();

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['departments', 'job_positions', 'employee_documents'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND p.code LIKE 'hr.%'
ON CONFLICT DO NOTHING;

-- ---------------------------------------------------------------------
-- HR: contracts, attendance, time off (Odoo Contracts / Attendances / Time Off)
-- Pay figures are entered from the signed contract; nothing is defaulted.
-- No payroll is computed here (GOSI, WPS and postings stay outside the system).
-- ---------------------------------------------------------------------

CREATE SEQUENCE IF NOT EXISTS seq_contract_no;

CREATE TABLE IF NOT EXISTS employee_contracts (
    id                   bigserial PRIMARY KEY,
    contract_no          text NOT NULL UNIQUE DEFAULT fn_doc_number('HC', 'seq_contract_no'),
    employee_id          bigint NOT NULL REFERENCES workers(id),
    contract_type        text NOT NULL CHECK (contract_type IN ('FIXED_TERM', 'INDEFINITE')),
    start_date           date NOT NULL,
    end_date             date,
    basic_salary         numeric(12,2) NOT NULL CHECK (basic_salary > 0),
    housing_allowance    numeric(12,2) NOT NULL CHECK (housing_allowance >= 0),
    transport_allowance  numeric(12,2) NOT NULL CHECK (transport_allowance >= 0),
    other_allowance      numeric(12,2) NOT NULL CHECK (other_allowance >= 0),
    weekly_hours         numeric(5,2) CHECK (weekly_hours > 0 AND weekly_hours <= 168),
    status               text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'RUNNING', 'EXPIRED', 'CANCELLED')),
    notes                text,
    created_by           bigint REFERENCES users(id),
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CHECK (end_date IS NULL OR end_date >= start_date),
    CHECK (contract_type <> 'FIXED_TERM' OR end_date IS NOT NULL)
);
-- One running contract per employee.
CREATE UNIQUE INDEX IF NOT EXISTS ux_contract_one_running ON employee_contracts (employee_id) WHERE status = 'RUNNING';

-- DRAFT -> RUNNING | CANCELLED; RUNNING -> EXPIRED | CANCELLED. Once running,
-- the terms are fixed (a change is a new contract); only closing is allowed.
CREATE OR REPLACE FUNCTION fn_contract_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status NOT IN ('DRAFT', 'RUNNING') THEN
            RAISE EXCEPTION 'RROKA_CONTRACT_TRANSITION: a contract starts as draft or running' USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.employee_id <> OLD.employee_id OR NEW.contract_no <> OLD.contract_no THEN
        RAISE EXCEPTION 'RROKA_CONTRACT_LOCKED: contract owner and number are fixed' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status <> OLD.status AND NOT (
           (OLD.status = 'DRAFT'   AND NEW.status IN ('RUNNING', 'CANCELLED'))
        OR (OLD.status = 'RUNNING' AND NEW.status IN ('EXPIRED', 'CANCELLED'))
    ) THEN
        RAISE EXCEPTION 'RROKA_CONTRACT_TRANSITION: % -> % not allowed', OLD.status, NEW.status USING ERRCODE = 'P0001';
    END IF;
    IF OLD.status <> 'DRAFT' AND (
        (to_jsonb(NEW) - 'status' - 'end_date' - 'notes' - 'updated_at') <> (to_jsonb(OLD) - 'status' - 'end_date' - 'notes' - 'updated_at')
        OR (NEW.end_date IS DISTINCT FROM OLD.end_date AND NEW.status = OLD.status)
    ) THEN
        RAISE EXCEPTION 'RROKA_CONTRACT_LOCKED: terms of a % contract cannot change; create a new contract', OLD.status USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_contract_guard ON employee_contracts;
CREATE TRIGGER trg_contract_guard BEFORE INSERT OR UPDATE ON employee_contracts
    FOR EACH ROW EXECUTE FUNCTION fn_contract_guard();

CREATE OR REPLACE VIEW v_contract_totals AS
SELECT c.id AS contract_id, c.employee_id, c.status,
       c.basic_salary + c.housing_allowance + c.transport_allowance + c.other_allowance AS monthly_gross
FROM employee_contracts c;

-- Time off: types, allocations (granted days), requests.
CREATE TABLE IF NOT EXISTS leave_types (
    id                   bigserial PRIMARY KEY,
    name                 text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    is_paid              boolean NOT NULL,
    requires_allocation  boolean NOT NULL,
    is_active            boolean NOT NULL DEFAULT true,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS leave_allocations (
    id             bigserial PRIMARY KEY,
    employee_id    bigint NOT NULL REFERENCES workers(id),
    leave_type_id  bigint NOT NULL REFERENCES leave_types(id),
    days           numeric(6,2) NOT NULL CHECK (days <> 0),   -- negative = a correcting deduction
    valid_from     date NOT NULL,
    valid_to       date NOT NULL,
    reason         text NOT NULL CHECK (btrim(reason) <> ''),
    approved_by    bigint NOT NULL REFERENCES users(id),
    created_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (valid_to >= valid_from)
);

CREATE TABLE IF NOT EXISTS leave_requests (
    id              bigserial PRIMARY KEY,
    employee_id     bigint NOT NULL REFERENCES workers(id),
    leave_type_id   bigint NOT NULL REFERENCES leave_types(id),
    date_from       date NOT NULL,
    date_to         date NOT NULL,
    days            numeric(6,2) NOT NULL CHECK (days > 0),
    reason          text,
    status          text NOT NULL DEFAULT 'SUBMITTED' CHECK (status IN ('SUBMITTED', 'APPROVED', 'REFUSED', 'CANCELLED')),
    approved_by     bigint REFERENCES users(id),
    approved_at     timestamptz,
    refusal_reason  text,
    created_by      bigint REFERENCES users(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (date_to >= date_from),
    CHECK (days <= date_to - date_from + 1),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
    CHECK (status <> 'REFUSED' OR NULLIF(btrim(refusal_reason), '') IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS ix_leave_requests_employee ON leave_requests (employee_id, date_from);

-- Approved days of a type within an allocation window vs granted days.
CREATE OR REPLACE FUNCTION fn_leave_balance(p_employee bigint, p_type bigint, p_on date) RETURNS numeric AS $$
    SELECT COALESCE((SELECT sum(days) FROM leave_allocations
                      WHERE employee_id = p_employee AND leave_type_id = p_type AND p_on BETWEEN valid_from AND valid_to), 0)
         - COALESCE((SELECT sum(r.days) FROM leave_requests r
                      WHERE r.employee_id = p_employee AND r.leave_type_id = p_type AND r.status = 'APPROVED'
                        AND EXISTS (SELECT 1 FROM leave_allocations a
                                     WHERE a.employee_id = p_employee AND a.leave_type_id = p_type
                                       AND p_on BETWEEN a.valid_from AND a.valid_to
                                       AND r.date_from BETWEEN a.valid_from AND a.valid_to)), 0)
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_leave_request_guard() RETURNS trigger AS $$
DECLARE
    v_needs_alloc boolean;
    v_emp_user    bigint;
    v_balance     numeric;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.status <> OLD.status AND NOT (
               (OLD.status = 'SUBMITTED' AND NEW.status IN ('APPROVED', 'REFUSED', 'CANCELLED'))
            OR (OLD.status = 'APPROVED'  AND NEW.status = 'CANCELLED')
        ) THEN
            RAISE EXCEPTION 'RROKA_LEAVE_TRANSITION: % -> % not allowed', OLD.status, NEW.status USING ERRCODE = 'P0001';
        END IF;
        IF OLD.status <> 'SUBMITTED' AND (to_jsonb(NEW) - 'status' - 'updated_at') <> (to_jsonb(OLD) - 'status' - 'updated_at') THEN
            RAISE EXCEPTION 'RROKA_LEAVE_LOCKED: a decided request cannot change' USING ERRCODE = 'P0001';
        END IF;
    ELSIF NEW.status <> 'SUBMITTED' THEN
        RAISE EXCEPTION 'RROKA_LEAVE_TRANSITION: a request starts as submitted' USING ERRCODE = 'P0001';
    END IF;

    IF NEW.status IN ('SUBMITTED', 'APPROVED') AND EXISTS (
        SELECT 1 FROM leave_requests o
         WHERE o.employee_id = NEW.employee_id AND o.id <> NEW.id AND o.status IN ('SUBMITTED', 'APPROVED')
           AND o.date_from <= NEW.date_to AND o.date_to >= NEW.date_from) THEN
        RAISE EXCEPTION 'RROKA_LEAVE_OVERLAP: another request covers these dates' USING ERRCODE = 'P0001';
    END IF;

    IF NEW.status = 'APPROVED' AND (TG_OP = 'INSERT' OR OLD.status <> 'APPROVED') THEN
        -- Segregation of duties: nobody approves their own time off.
        SELECT user_id INTO v_emp_user FROM workers WHERE id = NEW.employee_id;
        IF v_emp_user IS NOT NULL AND v_emp_user = NEW.approved_by THEN
            RAISE EXCEPTION 'RROKA_LEAVE_SELF_APPROVAL' USING ERRCODE = 'P0001';
        END IF;
        SELECT requires_allocation INTO v_needs_alloc FROM leave_types WHERE id = NEW.leave_type_id;
        IF v_needs_alloc THEN
            v_balance := fn_leave_balance(NEW.employee_id, NEW.leave_type_id, NEW.date_from);
            IF NEW.days > v_balance THEN
                RAISE EXCEPTION 'RROKA_LEAVE_BALANCE: balance is %, requested %', v_balance, NEW.days USING ERRCODE = 'P0001';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_leave_request_guard ON leave_requests;
CREATE TRIGGER trg_leave_request_guard BEFORE INSERT OR UPDATE ON leave_requests
    FOR EACH ROW EXECUTE FUNCTION fn_leave_request_guard();

-- Serialize approvals per employee so two concurrent approvals cannot both pass the balance check.
CREATE OR REPLACE FUNCTION fn_leave_lock_employee() RETURNS trigger AS $$
BEGIN
    PERFORM 1 FROM workers WHERE id = NEW.employee_id FOR UPDATE;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_leave_a_lock ON leave_requests;
CREATE TRIGGER trg_leave_a_lock BEFORE INSERT OR UPDATE ON leave_requests
    FOR EACH ROW EXECUTE FUNCTION fn_leave_lock_employee();

-- Allocations are a ledger: a correction is a new (possibly negative) allocation with its reason.
CREATE OR REPLACE FUNCTION fn_allocation_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'RROKA_ALLOCATION_IMMUTABLE: add a new allocation instead' USING ERRCODE = 'P0001';
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_allocation_immutable ON leave_allocations;
CREATE TRIGGER trg_allocation_immutable BEFORE UPDATE OR DELETE ON leave_allocations
    FOR EACH ROW EXECUTE FUNCTION fn_allocation_immutable();

-- Attendance: check-in/out; one open record per employee, no overlaps, max 24h.
CREATE TABLE IF NOT EXISTS attendances (
    id            bigserial PRIMARY KEY,
    employee_id   bigint NOT NULL REFERENCES workers(id),
    check_in      timestamptz NOT NULL,
    check_out     timestamptz,
    worked_hours  numeric(6,2) GENERATED ALWAYS AS (round((extract(epoch FROM (check_out - check_in)) / 3600)::numeric, 2)) STORED,
    notes         text,
    created_by    bigint REFERENCES users(id),
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK (check_out IS NULL OR (check_out > check_in AND check_out - check_in <= interval '24 hours'))
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_attendance_one_open ON attendances (employee_id) WHERE check_out IS NULL;
CREATE INDEX IF NOT EXISTS ix_attendance_employee ON attendances (employee_id, check_in);

CREATE OR REPLACE FUNCTION fn_attendance_guard() RETURNS trigger AS $$
DECLARE
    v_active boolean;
BEGIN
    IF NEW.check_in > now() + interval '5 minutes' OR NEW.check_out > now() + interval '5 minutes' THEN
        RAISE EXCEPTION 'RROKA_ATTENDANCE_FUTURE' USING ERRCODE = 'P0001';
    END IF;
    SELECT is_active INTO v_active FROM workers WHERE id = NEW.employee_id;
    IF NOT v_active THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_NOT_EMPLOYED: employee is not active' USING ERRCODE = 'P0001';
    END IF;
    IF EXISTS (SELECT 1 FROM attendances a WHERE a.employee_id = NEW.employee_id AND a.id <> NEW.id
                  AND a.check_in < COALESCE(NEW.check_out, 'infinity') AND COALESCE(a.check_out, 'infinity') > NEW.check_in) THEN
        RAISE EXCEPTION 'RROKA_ATTENDANCE_OVERLAP' USING ERRCODE = 'P0001';
    END IF;
    IF EXISTS (SELECT 1 FROM leave_requests r WHERE r.employee_id = NEW.employee_id AND r.status = 'APPROVED'
                  AND (NEW.check_in AT TIME ZONE 'Asia/Riyadh')::date BETWEEN r.date_from AND r.date_to) THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_ON_LEAVE' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_attendance_guard ON attendances;
CREATE TRIGGER trg_attendance_guard BEFORE INSERT OR UPDATE ON attendances
    FOR EACH ROW EXECUTE FUNCTION fn_attendance_guard();

-- Production hours cannot be charged for a day the employee is on approved leave.
CREATE OR REPLACE FUNCTION fn_labor_leave_guard() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM leave_requests r WHERE r.employee_id = NEW.worker_id AND r.status = 'APPROVED'
                  AND NEW.work_date BETWEEN r.date_from AND r.date_to) THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_ON_LEAVE' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_labor_leave_guard ON labor_logs;
CREATE TRIGGER trg_labor_leave_guard BEFORE INSERT OR UPDATE ON labor_logs
    FOR EACH ROW EXECUTE FUNCTION fn_labor_leave_guard();
DROP TRIGGER IF EXISTS trg_installation_leave_guard ON installation_workers;
CREATE TRIGGER trg_installation_leave_guard BEFORE INSERT OR UPDATE ON installation_workers
    FOR EACH ROW EXECUTE FUNCTION fn_labor_leave_guard();

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['employee_contracts', 'leave_types', 'leave_allocations', 'leave_requests', 'attendances'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
    END LOOP;
    FOREACH t IN ARRAY ARRAY['employee_contracts', 'leave_types', 'leave_requests', 'attendances'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

-- ---------------------------------------------------------------------
-- Purchasing & expenses (trial approved 2026-09-30, see PROJECT_CONTEXT):
-- source documents are captured ONCE here; approval posts stock receipts;
-- Daftra (the ledger) receives them later through the API. No journal,
-- no tax computation here: VAT is copied from the supplier's document.
-- ---------------------------------------------------------------------

CREATE SEQUENCE IF NOT EXISTS seq_supplier_no;
CREATE SEQUENCE IF NOT EXISTS seq_purchase_no;
CREATE SEQUENCE IF NOT EXISTS seq_expense_no;

INSERT INTO permissions (code, description) VALUES
    ('purchases.view', 'عرض الموردين وفواتير المشتريات'),
    ('purchases.manage', 'إدخال الموردين وفواتير المشتريات'),
    ('purchases.approve', 'اعتماد فواتير المشتريات (تُدخل المخزون)'),
    ('expenses.view', 'عرض المصروفات'),
    ('expenses.manage', 'إدخال المصروفات'),
    ('expenses.approve', 'اعتماد المصروفات')
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS suppliers (
    id                 bigserial PRIMARY KEY,
    supplier_no        text NOT NULL UNIQUE DEFAULT fn_doc_number('S', 'seq_supplier_no'),
    name               text NOT NULL CHECK (btrim(name) <> ''),
    vat_number         text CHECK (vat_number IS NULL OR vat_number ~ '^[0-9]{15}$'),
    commercial_reg_no  text,
    phone              text,
    email              text,
    city               text,
    address            text,
    iban               text CHECK (iban IS NULL OR iban ~ '^SA[0-9]{2}[0-9A-Z]{20}$'),
    notes              text,
    is_active          boolean NOT NULL DEFAULT true,
    daftra_supplier_id bigint UNIQUE,
    created_by         bigint REFERENCES users(id),
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_suppliers_vat ON suppliers (vat_number) WHERE vat_number IS NOT NULL;

CREATE TABLE IF NOT EXISTS purchase_invoices (
    id                   bigserial PRIMARY KEY,
    purchase_no          text NOT NULL UNIQUE DEFAULT fn_doc_number('PI', 'seq_purchase_no'),
    supplier_id          bigint NOT NULL REFERENCES suppliers(id),
    supplier_invoice_no  text NOT NULL CHECK (btrim(supplier_invoice_no) <> ''),
    invoice_date         date NOT NULL,
    due_date             date,
    discount_amount      numeric(14,2) NOT NULL CHECK (discount_amount >= 0),
    vat_amount           numeric(14,2) NOT NULL CHECK (vat_amount >= 0),   -- as printed on the supplier's invoice
    notes                text,
    attachment_id        bigint REFERENCES studio_assets(id),
    status               text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    approved_by          bigint REFERENCES users(id),
    approved_at          timestamptz,
    daftra_purchase_id   bigint UNIQUE,
    created_by           bigint REFERENCES users(id),
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CHECK (due_date IS NULL OR due_date >= invoice_date),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
-- The same supplier invoice cannot be entered twice.
CREATE UNIQUE INDEX IF NOT EXISTS ux_purchase_supplier_invoice
    ON purchase_invoices (supplier_id, lower(btrim(supplier_invoice_no))) WHERE status <> 'CANCELLED';

CREATE TABLE IF NOT EXISTS purchase_invoice_lines (
    id                   bigserial PRIMARY KEY,
    purchase_invoice_id  bigint NOT NULL REFERENCES purchase_invoices(id) ON DELETE CASCADE,
    line_no              int NOT NULL CHECK (line_no > 0),
    material_id          bigint NOT NULL REFERENCES raw_materials(id),
    quantity             numeric(14,4) NOT NULL CHECK (quantity > 0),
    unit_price           numeric(14,4) NOT NULL CHECK (unit_price >= 0),
    line_total           numeric(16,2) GENERATED ALWAYS AS (round(quantity * unit_price, 2)) STORED,
    UNIQUE (purchase_invoice_id, line_no)
);

ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS purchase_invoice_line_id bigint REFERENCES purchase_invoice_lines(id);
CREATE UNIQUE INDEX IF NOT EXISTS ux_stock_receipt_per_purchase_line ON stock_movements (purchase_invoice_line_id)
    WHERE purchase_invoice_line_id IS NOT NULL;

CREATE OR REPLACE VIEW v_purchase_totals AS
SELECT p.id AS purchase_invoice_id,
       COALESCE(sum(l.line_total), 0) AS subtotal,
       p.discount_amount,
       COALESCE(sum(l.line_total), 0) - p.discount_amount AS net_before_vat,
       p.vat_amount,
       COALESCE(sum(l.line_total), 0) - p.discount_amount + p.vat_amount AS total
FROM purchase_invoices p
LEFT JOIN purchase_invoice_lines l ON l.purchase_invoice_id = p.id
GROUP BY p.id;

-- DRAFT is editable; APPROVED is final (it has moved stock); only a draft is cancelled.
CREATE OR REPLACE FUNCTION fn_purchase_invoice_guard() RETURNS trigger AS $$
DECLARE
    v_lines int;
    v_sub   numeric;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' THEN
            RAISE EXCEPTION 'RROKA_PURCHASE_TRANSITION: a purchase invoice starts as draft' USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'DRAFT' THEN
        IF NEW.status = OLD.status
           AND (to_jsonb(NEW) - 'daftra_purchase_id' - 'updated_at') = (to_jsonb(OLD) - 'daftra_purchase_id' - 'updated_at') THEN
            RETURN NEW;   -- linking the Daftra id is the only change allowed after approval
        END IF;
        RAISE EXCEPTION 'RROKA_PURCHASE_LOCKED: % purchase invoice cannot change', OLD.status USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'APPROVED' THEN
        SELECT count(*), COALESCE(sum(line_total), 0) INTO v_lines, v_sub
          FROM purchase_invoice_lines WHERE purchase_invoice_id = NEW.id;
        IF v_lines = 0 THEN
            RAISE EXCEPTION 'RROKA_PURCHASE_EMPTY' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.discount_amount > v_sub THEN
            RAISE EXCEPTION 'RROKA_PURCHASE_NEGATIVE' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_purchase_invoice_guard ON purchase_invoices;
CREATE TRIGGER trg_purchase_invoice_guard BEFORE INSERT OR UPDATE ON purchase_invoices
    FOR EACH ROW EXECUTE FUNCTION fn_purchase_invoice_guard();

CREATE OR REPLACE FUNCTION fn_purchase_lines_guard() RETURNS trigger AS $$
DECLARE
    v_status text;
BEGIN
    SELECT status INTO v_status FROM purchase_invoices
     WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.purchase_invoice_id ELSE NEW.purchase_invoice_id END;
    IF v_status IS NOT NULL AND v_status <> 'DRAFT' THEN
        RAISE EXCEPTION 'RROKA_PURCHASE_LOCKED: lines change only while draft' USING ERRCODE = 'P0001';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_purchase_lines_guard ON purchase_invoice_lines;
CREATE TRIGGER trg_purchase_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON purchase_invoice_lines
    FOR EACH ROW EXECUTE FUNCTION fn_purchase_lines_guard();

-- Approval posts one stock RECEIPT per line at its net unit cost: the invoice
-- discount is spread over lines by value (the last line absorbs rounding).
CREATE OR REPLACE FUNCTION fn_purchase_invoice_post() RETURNS trigger AS $$
DECLARE
    v_sub      numeric;
    v_left     numeric;
    v_share    numeric;
    v_count    int;
    v_i        int := 0;
    l          purchase_invoice_lines%ROWTYPE;
BEGIN
    IF NEW.status <> 'APPROVED' OR OLD.status = 'APPROVED' THEN
        RETURN NULL;
    END IF;
    SELECT COALESCE(sum(line_total), 0), count(*) INTO v_sub, v_count
      FROM purchase_invoice_lines WHERE purchase_invoice_id = NEW.id;
    v_left := NEW.discount_amount;
    FOR l IN SELECT * FROM purchase_invoice_lines WHERE purchase_invoice_id = NEW.id ORDER BY line_no LOOP
        v_i := v_i + 1;
        v_share := CASE WHEN v_i = v_count THEN v_left
                        WHEN v_sub = 0 THEN 0
                        ELSE round(NEW.discount_amount * l.line_total / v_sub, 2) END;
        v_left := v_left - v_share;
        INSERT INTO stock_movements (material_id, movement_type, quantity, unit_cost, reference, moved_at,
                                     created_by, purchase_invoice_line_id)
        VALUES (l.material_id, 'RECEIPT', l.quantity, round((l.line_total - v_share) / l.quantity, 4),
                NEW.purchase_no || ' / ' || NEW.supplier_invoice_no, NEW.invoice_date::timestamptz,
                NEW.approved_by, l.id);
    END LOOP;
    RETURN NULL;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_purchase_invoice_post ON purchase_invoices;
CREATE TRIGGER trg_purchase_invoice_post AFTER UPDATE OF status ON purchase_invoices
    FOR EACH ROW EXECUTE FUNCTION fn_purchase_invoice_post();

-- Expenses
CREATE TABLE IF NOT EXISTS expense_categories (
    id                  bigserial PRIMARY KEY,
    name                text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    is_overhead         boolean NOT NULL,     -- indirect workshop cost (vs. selling/admin)
    daftra_account_ref  text,                 -- the Daftra account it posts to; filled when the mapping is verified
    is_active           boolean NOT NULL DEFAULT true,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS expenses (
    id               bigserial PRIMARY KEY,
    expense_no       text NOT NULL UNIQUE DEFAULT fn_doc_number('EX', 'seq_expense_no'),
    expense_date     date NOT NULL,
    category_id      bigint NOT NULL REFERENCES expense_categories(id),
    supplier_id      bigint REFERENCES suppliers(id),
    payee            text,
    description      text NOT NULL CHECK (btrim(description) <> ''),
    amount           numeric(14,2) NOT NULL CHECK (amount > 0),     -- before VAT
    vat_amount       numeric(14,2) NOT NULL CHECK (vat_amount >= 0), -- as printed on the receipt
    payment_method   text NOT NULL CHECK (payment_method IN ('CASH', 'BANK', 'CARD', 'PETTY_CASH')),
    paid_by_employee_id bigint REFERENCES workers(id),
    project_id       bigint REFERENCES projects(id),
    reference        text,
    attachment_id    bigint REFERENCES studio_assets(id),
    status           text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    approved_by      bigint REFERENCES users(id),
    approved_at      timestamptz,
    daftra_expense_id bigint UNIQUE,
    created_by       bigint REFERENCES users(id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (num_nonnulls(supplier_id, NULLIF(btrim(payee), '')) >= 1),
    CHECK (payment_method <> 'PETTY_CASH' OR paid_by_employee_id IS NOT NULL),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_expenses_project ON expenses (project_id);
CREATE INDEX IF NOT EXISTS ix_expenses_date ON expenses (expense_date);

CREATE OR REPLACE FUNCTION fn_expense_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' THEN
            RAISE EXCEPTION 'RROKA_EXPENSE_TRANSITION: an expense starts as draft' USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'DRAFT' THEN
        IF NEW.status = OLD.status
           AND (to_jsonb(NEW) - 'daftra_expense_id' - 'updated_at') = (to_jsonb(OLD) - 'daftra_expense_id' - 'updated_at') THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'RROKA_EXPENSE_LOCKED: % expense cannot change', OLD.status USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_expense_guard ON expenses;
CREATE TRIGGER trg_expense_guard BEFORE INSERT OR UPDATE ON expenses
    FOR EACH ROW EXECUTE FUNCTION fn_expense_guard();

-- Approved project expenses are a direct cost of the project (always a real,
-- entered amount). Same columns as before, plus direct_expense_cost at the end.
CREATE OR REPLACE VIEW v_project_actual_cost AS
WITH mat AS (
    SELECT project_id,
           sum(CASE movement_type WHEN 'ISSUE' THEN quantity * unit_cost
                                  WHEN 'RETURN' THEN -quantity * unit_cost END) AS cost,
           count(*) FILTER (WHERE movement_type IN ('ISSUE', 'RETURN') AND unit_cost IS NULL) AS missing
      FROM stock_movements
     WHERE movement_type IN ('ISSUE', 'RETURN')
     GROUP BY project_id
),
lab AS (
    SELECT project_id, sum(hours) AS hours, sum(cost) AS cost, count(*) FILTER (WHERE cost IS NULL) AS missing
      FROM v_labor_cost_lines GROUP BY project_id
),
mac AS (
    SELECT project_id, sum(hours) AS hours, sum(cost) AS cost, count(*) FILTER (WHERE cost IS NULL) AS missing
      FROM v_machine_cost_lines GROUP BY project_id
),
exp AS (
    SELECT project_id, sum(amount) AS cost FROM expenses
     WHERE status = 'APPROVED' AND project_id IS NOT NULL GROUP BY project_id
),
oh AS (
    SELECT p.id AS project_id, o.basis, o.rate_pct
      FROM projects p
      LEFT JOIN LATERAL (
          SELECT basis, rate_pct FROM overhead_rates
           WHERE effective_from <= p.start_date ORDER BY effective_from DESC LIMIT 1
      ) o ON true
),
base AS (
    SELECT p.id AS project_id, p.project_no, p.contract_value, p.status,
           CASE WHEN COALESCE(mat.missing, 0) = 0 THEN round(COALESCE(mat.cost, 0), 2) END AS material_cost,
           COALESCE(mat.missing, 0) AS material_lines_missing_cost,
           COALESCE(lab.hours, 0) AS labor_hours,
           CASE WHEN COALESCE(lab.missing, 0) = 0 THEN COALESCE(lab.cost, 0) END AS labor_cost,
           COALESCE(lab.missing, 0) AS labor_lines_missing_rate,
           COALESCE(mac.hours, 0) AS machine_hours,
           CASE WHEN COALESCE(mac.missing, 0) = 0 THEN COALESCE(mac.cost, 0) END AS machine_cost,
           COALESCE(mac.missing, 0) AS machine_lines_missing_rate,
           oh.basis AS overhead_basis, oh.rate_pct AS overhead_rate_pct,
           COALESCE(exp.cost, 0) AS direct_expense_cost
      FROM projects p
      LEFT JOIN mat ON mat.project_id = p.id
      LEFT JOIN lab ON lab.project_id = p.id
      LEFT JOIN mac ON mac.project_id = p.id
      LEFT JOIN exp ON exp.project_id = p.id
      LEFT JOIN oh  ON oh.project_id  = p.id
),
calc AS (
    SELECT b.*,
           round(CASE b.overhead_basis
                 WHEN 'PCT_OF_DIRECT_LABOR' THEN b.labor_cost * b.overhead_rate_pct / 100
                 WHEN 'PCT_OF_PRIME_COST'   THEN (b.material_cost + b.labor_cost + b.machine_cost) * b.overhead_rate_pct / 100
                 END, 2) AS overhead_cost
      FROM base b
)
SELECT c.project_id, c.project_no, c.contract_value, c.status, c.material_cost, c.material_lines_missing_cost,
       c.labor_hours, c.labor_cost, c.labor_lines_missing_rate, c.machine_hours, c.machine_cost,
       c.machine_lines_missing_rate, c.overhead_basis, c.overhead_rate_pct, c.overhead_cost,
       c.material_cost + c.labor_cost + c.machine_cost + c.overhead_cost + c.direct_expense_cost AS total_cost,
       c.contract_value - (c.material_cost + c.labor_cost + c.machine_cost + c.overhead_cost + c.direct_expense_cost) AS gross_profit,
       CASE WHEN c.contract_value > 0 THEN
           round((c.contract_value - (c.material_cost + c.labor_cost + c.machine_cost + c.overhead_cost + c.direct_expense_cost))
                 / c.contract_value * 100, 2) END AS gross_margin_pct,
       array_remove(ARRAY[
           CASE WHEN c.material_cost IS NULL THEN 'MATERIAL_COST_UNKNOWN' END,
           CASE WHEN c.labor_cost    IS NULL THEN 'WORKER_RATE_MISSING' END,
           CASE WHEN c.machine_cost  IS NULL THEN 'MACHINE_RATE_MISSING' END,
           CASE WHEN c.overhead_rate_pct IS NULL THEN 'OVERHEAD_RATE_MISSING' END
       ], NULL) AS costing_gaps,
       c.direct_expense_cost
FROM calc c;

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['suppliers', 'purchase_invoices', 'purchase_invoice_lines', 'expense_categories', 'expenses'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
    END LOOP;
    FOREACH t IN ARRAY ARRAY['suppliers', 'purchase_invoices', 'expense_categories', 'expenses'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

-- Receipts and invoices are kept as private studio files, never listed in the studio.
ALTER TABLE studio_assets DROP CONSTRAINT IF EXISTS studio_assets_category_check;
ALTER TABLE studio_assets ADD CONSTRAINT studio_assets_category_check
    CHECK (category IN ('CLIENT_REFERENCE', 'FINISHED_WORK', 'CATALOG', 'SITE', 'MATERIAL', 'DOCUMENT'));

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND (p.code LIKE 'purchases.%' OR p.code LIKE 'expenses.%')
ON CONFLICT DO NOTHING;

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

-- ---------------------------------------------------------------------
-- Treasury (trial approved 2026-10-04, see PROJECT_CONTEXT): where money was
-- paid from. Cash boxes, bank accounts and employee custody (petty cash) are
-- recorded here; each maps to a Daftra treasury, where cash and bank balances
-- and the bank reconciliation live. No journal entries here.
-- Only custody has a balance here, because every custody flow (issue,
-- spending, return) is recorded in this system. Cash and bank also receive
-- customer collections, which are recorded in Daftra, so a balance computed
-- here would be incomplete — it is never shown.
-- ---------------------------------------------------------------------

CREATE SEQUENCE IF NOT EXISTS seq_transfer_no;

INSERT INTO permissions (code, description) VALUES
    ('treasury.view', 'عرض الخزائن والحسابات البنكية والعهد'),
    ('treasury.manage', 'إدارة الخزائن والحسابات وإدخال التحويلات والعهد'),
    ('treasury.approve', 'اعتماد التحويلات وصرف العهد وإرجاعها')
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS payment_accounts (
    id                  bigserial PRIMARY KEY,
    name                text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    kind                text NOT NULL CHECK (kind IN ('CASH', 'BANK', 'CUSTODY')),
    bank_name           text,
    iban                text CHECK (iban IS NULL OR iban ~ '^SA[0-9]{2}[0-9A-Z]{20}$'),
    employee_id         bigint REFERENCES workers(id),     -- the custodian (custody only)
    custody_limit       numeric(14,2) CHECK (custody_limit IS NULL OR custody_limit > 0),
    daftra_treasury_ref text,                               -- the matching Daftra treasury; filled when the link is verified
    notes               text,
    is_active           boolean NOT NULL DEFAULT true,
    created_by          bigint REFERENCES users(id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'CUSTODY') = (employee_id IS NOT NULL)),
    CHECK (kind = 'CUSTODY' OR custody_limit IS NULL),
    CHECK (kind = 'BANK' OR (bank_name IS NULL AND iban IS NULL))
);
-- One open custody per employee: a second one would split what they must account for.
CREATE UNIQUE INDEX IF NOT EXISTS ux_payment_accounts_one_custody
    ON payment_accounts (employee_id) WHERE kind = 'CUSTODY' AND is_active;

-- Internal transfers (Odoo: internal transfer between journals): issuing custody
-- from the cash box or bank, returning it, depositing cash at the bank, ...
CREATE TABLE IF NOT EXISTS treasury_transfers (
    id                 bigserial PRIMARY KEY,
    transfer_no        text NOT NULL UNIQUE DEFAULT fn_doc_number('TR', 'seq_transfer_no'),
    transfer_date      date NOT NULL,
    from_account_id    bigint NOT NULL REFERENCES payment_accounts(id),
    to_account_id      bigint NOT NULL REFERENCES payment_accounts(id),
    amount             numeric(14,2) NOT NULL CHECK (amount > 0),
    reference          text,
    notes              text,
    status             text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    approved_by        bigint REFERENCES users(id),
    approved_at        timestamptz,
    daftra_transfer_id bigint UNIQUE,
    created_by         bigint REFERENCES users(id),
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (from_account_id <> to_account_id),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_treasury_transfers_from ON treasury_transfers (from_account_id);
CREATE INDEX IF NOT EXISTS ix_treasury_transfers_to ON treasury_transfers (to_account_id);

ALTER TABLE expenses ADD COLUMN IF NOT EXISTS payment_account_id bigint REFERENCES payment_accounts(id);
CREATE INDEX IF NOT EXISTS ix_expenses_payment_account ON expenses (payment_account_id);

-- What the custodian holds: approved transfers in − approved transfers out − approved
-- expenses paid from it (amount + VAT, the money actually paid). Negative means the
-- employee paid from their own pocket and is owed that amount.
CREATE OR REPLACE FUNCTION fn_custody_balance(p_account bigint) RETURNS numeric AS $$
    SELECT COALESCE((SELECT sum(amount) FROM treasury_transfers WHERE to_account_id = p_account AND status = 'APPROVED'), 0)
         - COALESCE((SELECT sum(amount) FROM treasury_transfers WHERE from_account_id = p_account AND status = 'APPROVED'), 0)
         - COALESCE((SELECT sum(amount + vat_amount) FROM expenses WHERE payment_account_id = p_account AND status = 'APPROVED'), 0)
$$ LANGUAGE sql STABLE;

-- Kind and custodian are fixed (a different use is a new account); a custody is
-- closed only once settled to zero.
CREATE OR REPLACE FUNCTION fn_payment_account_guard() RETURNS trigger AS $$
BEGIN
    IF NEW.kind <> OLD.kind OR NEW.employee_id IS DISTINCT FROM OLD.employee_id THEN
        RAISE EXCEPTION 'RROKA_PAYMENT_ACCOUNT_LOCKED: kind and custodian cannot change' USING ERRCODE = 'P0001';
    END IF;
    IF OLD.is_active AND NOT NEW.is_active AND NEW.kind = 'CUSTODY' AND fn_custody_balance(NEW.id) <> 0 THEN
        RAISE EXCEPTION 'RROKA_CUSTODY_NOT_SETTLED: balance is %', fn_custody_balance(NEW.id) USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_payment_account_guard ON payment_accounts;
CREATE TRIGGER trg_payment_account_guard BEFORE UPDATE ON payment_accounts
    FOR EACH ROW EXECUTE FUNCTION fn_payment_account_guard();

CREATE OR REPLACE FUNCTION fn_treasury_transfer_guard() RETURNS trigger AS $$
DECLARE
    v_from payment_accounts%ROWTYPE;
    v_to   payment_accounts%ROWTYPE;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' THEN
            RAISE EXCEPTION 'RROKA_TRANSFER_TRANSITION: a transfer starts as draft' USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'DRAFT' THEN
        IF NEW.status = OLD.status
           AND (to_jsonb(NEW) - 'daftra_transfer_id' - 'updated_at') = (to_jsonb(OLD) - 'daftra_transfer_id' - 'updated_at') THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'RROKA_TRANSFER_LOCKED: % transfer cannot change', OLD.status USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'APPROVED' THEN
        -- Serialise approvals on the same accounts so two cannot both pass a balance check.
        PERFORM 1 FROM payment_accounts WHERE id IN (NEW.from_account_id, NEW.to_account_id) ORDER BY id FOR UPDATE;
        SELECT * INTO v_from FROM payment_accounts WHERE id = NEW.from_account_id;
        SELECT * INTO v_to FROM payment_accounts WHERE id = NEW.to_account_id;
        IF NOT v_from.is_active OR NOT v_to.is_active THEN
            RAISE EXCEPTION 'RROKA_PAYMENT_ACCOUNT_INACTIVE' USING ERRCODE = 'P0001';
        END IF;
        IF v_from.kind = 'CUSTODY' AND fn_custody_balance(v_from.id) < NEW.amount THEN
            RAISE EXCEPTION 'RROKA_CUSTODY_INSUFFICIENT: custody holds %, transfer is %', fn_custody_balance(v_from.id), NEW.amount
                USING ERRCODE = 'P0001';
        END IF;
        IF v_to.kind = 'CUSTODY' AND v_to.custody_limit IS NOT NULL AND fn_custody_balance(v_to.id) + NEW.amount > v_to.custody_limit THEN
            RAISE EXCEPTION 'RROKA_CUSTODY_LIMIT: limit is %, custody would hold %', v_to.custody_limit, fn_custody_balance(v_to.id) + NEW.amount
                USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_treasury_transfer_guard ON treasury_transfers;
CREATE TRIGGER trg_treasury_transfer_guard BEFORE INSERT OR UPDATE ON treasury_transfers
    FOR EACH ROW EXECUTE FUNCTION fn_treasury_transfer_guard();

-- Extends the expense guard above: the payment account must match the payment
-- method (cash box ↔ cash, bank ↔ transfer or card, custody ↔ petty cash, and the
-- custodian is the employee who paid), and an expense is approved only once it
-- says where it was paid from.
CREATE OR REPLACE FUNCTION fn_expense_guard() RETURNS trigger AS $$
DECLARE
    v_acc payment_accounts%ROWTYPE;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' THEN
            RAISE EXCEPTION 'RROKA_EXPENSE_TRANSITION: an expense starts as draft' USING ERRCODE = 'P0001';
        END IF;
    ELSIF OLD.status <> 'DRAFT' THEN
        IF NEW.status = OLD.status
           AND (to_jsonb(NEW) - 'daftra_expense_id' - 'updated_at') = (to_jsonb(OLD) - 'daftra_expense_id' - 'updated_at') THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'RROKA_EXPENSE_LOCKED: % expense cannot change', OLD.status USING ERRCODE = 'P0001';
    END IF;

    IF NEW.payment_account_id IS NOT NULL THEN
        SELECT * INTO v_acc FROM payment_accounts WHERE id = NEW.payment_account_id;
        IF (v_acc.kind = 'CASH' AND NEW.payment_method <> 'CASH')
           OR (v_acc.kind = 'BANK' AND NEW.payment_method NOT IN ('BANK', 'CARD'))
           OR (v_acc.kind = 'CUSTODY' AND NEW.payment_method <> 'PETTY_CASH') THEN
            RAISE EXCEPTION 'RROKA_EXPENSE_PAYMENT_MISMATCH: % account, % method', v_acc.kind, NEW.payment_method USING ERRCODE = 'P0001';
        END IF;
        IF v_acc.kind = 'CUSTODY' THEN
            NEW.paid_by_employee_id := v_acc.employee_id;
        END IF;
    END IF;
    IF NEW.status = 'APPROVED' THEN
        IF NEW.payment_account_id IS NULL THEN
            RAISE EXCEPTION 'RROKA_EXPENSE_NEEDS_PAYMENT_ACCOUNT' USING ERRCODE = 'P0001';
        END IF;
        IF NOT v_acc.is_active THEN
            RAISE EXCEPTION 'RROKA_PAYMENT_ACCOUNT_INACTIVE' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

-- One line per money movement on an account (cancelled documents excluded).
CREATE OR REPLACE VIEW v_payment_account_lines AS
SELECT e.payment_account_id AS account_id, e.expense_date AS line_date, 'EXPENSE'::text AS source, e.id AS source_id,
       e.expense_no AS doc_no, e.description, NULL::bigint AS counterpart_account_id,
       0::numeric(14,2) AS amount_in, (e.amount + e.vat_amount)::numeric(14,2) AS amount_out,
       e.status, e.reference, (e.daftra_expense_id IS NOT NULL) AS in_daftra, e.created_at
  FROM expenses e
 WHERE e.payment_account_id IS NOT NULL AND e.status <> 'CANCELLED'
UNION ALL
SELECT t.to_account_id, t.transfer_date, 'TRANSFER_IN', t.id, t.transfer_no, COALESCE(t.notes, ''), t.from_account_id,
       t.amount, 0, t.status, t.reference, (t.daftra_transfer_id IS NOT NULL), t.created_at
  FROM treasury_transfers t WHERE t.status <> 'CANCELLED'
UNION ALL
SELECT t.from_account_id, t.transfer_date, 'TRANSFER_OUT', t.id, t.transfer_no, COALESCE(t.notes, ''), t.to_account_id,
       0, t.amount, t.status, t.reference, (t.daftra_transfer_id IS NOT NULL), t.created_at
  FROM treasury_transfers t WHERE t.status <> 'CANCELLED';

-- Per account: approved movements recorded here, drafts waiting, what is not yet in
-- Daftra, and — for custody only — the balance the custodian must account for.
CREATE OR REPLACE VIEW v_payment_account_summary AS
SELECT a.id AS account_id,
       COALESCE(sum(l.amount_in) FILTER (WHERE l.status = 'APPROVED'), 0) AS approved_in,
       COALESCE(sum(l.amount_out) FILTER (WHERE l.status = 'APPROVED'), 0) AS approved_out,
       COALESCE(sum(l.amount_out) FILTER (WHERE l.status = 'DRAFT'), 0) AS draft_out,
       count(l.*) FILTER (WHERE l.status = 'DRAFT') AS drafts,
       count(l.*) FILTER (WHERE l.status = 'APPROVED' AND NOT l.in_daftra) AS not_in_daftra,
       CASE WHEN a.kind = 'CUSTODY'
            THEN COALESCE(sum(l.amount_in - l.amount_out) FILTER (WHERE l.status = 'APPROVED'), 0) END AS custody_balance
  FROM payment_accounts a
  LEFT JOIN v_payment_account_lines l ON l.account_id = a.id
 GROUP BY a.id, a.kind;

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['payment_accounts', 'treasury_transfers'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND p.code LIKE 'treasury.%'
ON CONFLICT DO NOTHING;

-- ---------------------------------------------------------------------
-- Costing engine, phase 1 (2026-10-04, see docs/COSTING_ENGINE_PLAN.md):
-- cost centres and the rates the standard cost is built from. Every rate is
-- a numbered version: DRAFT -> APPROVED (frozen) | CANCELLED, with its source,
-- who entered and who approved it. Nothing is defaulted (Zero Assumption):
-- a missing rate stays missing and the cost that needs it shows as incomplete.
-- ---------------------------------------------------------------------

INSERT INTO permissions (code, description) VALUES
    ('cost_rates.approve', 'اعتماد بطاقات التكلفة والمعدلات (تصبح نهائية)')
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS cost_centers (
    id            bigserial PRIMARY KEY,
    code          text NOT NULL UNIQUE CHECK (btrim(code) <> ''),
    name          text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    driver        text NOT NULL CHECK (driver IN ('LABOR_HOURS', 'MACHINE_HOURS')),  -- what absorbs its overhead
    department_id bigint REFERENCES departments(id),
    notes         text,
    is_active     boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE machines ADD COLUMN IF NOT EXISTS cost_center_id bigint REFERENCES cost_centers(id);

-- One guard for every versioned cost record. TG_ARGV[0] lists the columns that
-- identify the series (a worker, a machine, ...; '' = one global series); they are
-- compared case- and space-insensitively.
-- Version numbers follow the series; an approved record is final; approval
-- cannot back-date a series (it would silently re-price work already costed).
CREATE OR REPLACE FUNCTION fn_cost_record_guard() RETURNS trigger AS $$
DECLARE
    v_key    text := TG_ARGV[0];
    v_col    text;
    v_where  text := 'true';
    v_latest date;
BEGIN
    IF v_key <> '' THEN
        FOREACH v_col IN ARRAY string_to_array(v_key, ',') LOOP
            v_where := v_where || format(' AND lower(btrim(%I::text)) IS NOT DISTINCT FROM lower(btrim(%L))', v_col, to_jsonb(NEW) ->> v_col);
        END LOOP;
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' THEN
            RAISE EXCEPTION 'RROKA_COST_RECORD_TRANSITION: a cost record starts as draft' USING ERRCODE = 'P0001';
        END IF;
        EXECUTE format('SELECT COALESCE(max(version), 0) + 1 FROM %I WHERE %s', TG_TABLE_NAME, v_where) INTO NEW.version;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'DRAFT' THEN
        IF (to_jsonb(NEW) - 'updated_at') = (to_jsonb(OLD) - 'updated_at') THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'RROKA_COST_RECORD_LOCKED: % record cannot change', OLD.status USING ERRCODE = 'P0001';
    END IF;
    IF NEW.version <> OLD.version THEN
        RAISE EXCEPTION 'RROKA_COST_RECORD_LOCKED: series and version are fixed' USING ERRCODE = 'P0001';
    END IF;
    IF v_key <> '' THEN
        FOREACH v_col IN ARRAY string_to_array(v_key, ',') LOOP
            IF (to_jsonb(NEW) ->> v_col) IS DISTINCT FROM (to_jsonb(OLD) ->> v_col) THEN
                RAISE EXCEPTION 'RROKA_COST_RECORD_LOCKED: series and version are fixed' USING ERRCODE = 'P0001';
            END IF;
        END LOOP;
    END IF;
    IF NEW.status = 'APPROVED' THEN
        EXECUTE format('SELECT max(effective_from) FROM %I WHERE status = ''APPROVED'' AND %s', TG_TABLE_NAME, v_where) INTO v_latest;
        IF v_latest IS NOT NULL AND NEW.effective_from <= v_latest THEN
            RAISE EXCEPTION 'RROKA_RATE_BACKDATED: an approved version starts %; a new one must start later', v_latest USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

-- Electricity price per kWh (one series for the workshop).
CREATE TABLE IF NOT EXISTS energy_rates (
    id             bigserial PRIMARY KEY,
    version        int NOT NULL DEFAULT 0,
    effective_from date NOT NULL,
    rate_per_kwh   numeric(10,4) NOT NULL CHECK (rate_per_kwh > 0),
    source         text NOT NULL CHECK (btrim(source) <> ''),
    estimated      boolean NOT NULL DEFAULT false,
    notes          text,
    status         text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by     bigint REFERENCES users(id),
    approved_by    bigint REFERENCES users(id),
    approved_at    timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);

CREATE OR REPLACE FUNCTION fn_energy_rate_on(p_date date) RETURNS energy_rates AS $$
    SELECT * FROM energy_rates WHERE status = 'APPROVED' AND effective_from <= p_date ORDER BY effective_from DESC LIMIT 1
$$ LANGUAGE sql STABLE;

-- Employee cost card: monthly cost components and productive capacity give the
-- productive hourly rate (never salary ÷ number of products). Every deduction
-- from theoretical to practical hours is itemised, so low productivity cannot
-- hide inside an arbitrary capacity figure.
CREATE TABLE IF NOT EXISTS employee_cost_cards (
    id                  bigserial PRIMARY KEY,
    employee_id         bigint NOT NULL REFERENCES workers(id),
    version             int NOT NULL DEFAULT 0,
    effective_from      date NOT NULL,
    basic_salary        numeric(12,2) NOT NULL CHECK (basic_salary > 0),
    housing             numeric(12,2) NOT NULL CHECK (housing >= 0),
    transportation      numeric(12,2) NOT NULL CHECK (transportation >= 0),
    insurance           numeric(12,2) NOT NULL CHECK (insurance >= 0),
    government_fees     numeric(12,2) NOT NULL CHECK (government_fees >= 0),   -- residency, work permit, ... per month
    allowances          numeric(12,2) NOT NULL CHECK (allowances >= 0),
    other_costs         numeric(12,2) NOT NULL CHECK (other_costs >= 0),
    theoretical_hours   numeric(7,2) NOT NULL CHECK (theoretical_hours > 0),     -- per month
    break_hours         numeric(7,2) NOT NULL CHECK (break_hours >= 0),
    cleaning_hours      numeric(7,2) NOT NULL CHECK (cleaning_hours >= 0),
    maintenance_hours   numeric(7,2) NOT NULL CHECK (maintenance_hours >= 0),
    setup_hours         numeric(7,2) NOT NULL CHECK (setup_hours >= 0),
    meeting_hours       numeric(7,2) NOT NULL CHECK (meeting_hours >= 0),
    downtime_hours      numeric(7,2) NOT NULL CHECK (downtime_hours >= 0),
    waiting_hours       numeric(7,2) NOT NULL CHECK (waiting_hours >= 0),
    other_nonproductive_hours numeric(7,2) NOT NULL CHECK (other_nonproductive_hours >= 0),
    monthly_cost        numeric(14,2) GENERATED ALWAYS AS
                        (basic_salary + housing + transportation + insurance + government_fees + allowances + other_costs) STORED,
    practical_hours     numeric(7,2) GENERATED ALWAYS AS
                        (theoretical_hours - (break_hours + cleaning_hours + maintenance_hours + setup_hours + meeting_hours
                                              + downtime_hours + waiting_hours + other_nonproductive_hours)) STORED,
    hourly_rate         numeric(12,4) GENERATED ALWAYS AS
                        (round((basic_salary + housing + transportation + insurance + government_fees + allowances + other_costs)
                               / NULLIF(theoretical_hours - (break_hours + cleaning_hours + maintenance_hours + setup_hours + meeting_hours
                                                             + downtime_hours + waiting_hours + other_nonproductive_hours), 0), 4)) STORED,
    source              text NOT NULL CHECK (btrim(source) <> ''),
    estimated           boolean NOT NULL DEFAULT false,
    notes               text,
    status              text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by          bigint REFERENCES users(id),
    approved_by         bigint REFERENCES users(id),
    approved_at         timestamptz,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (theoretical_hours > break_hours + cleaning_hours + maintenance_hours + setup_hours + meeting_hours
                               + downtime_hours + waiting_hours + other_nonproductive_hours),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_employee_cost_cards_employee ON employee_cost_cards (employee_id, effective_from);

-- How the employee's productive hours split between cost centres (must total 100%).
CREATE TABLE IF NOT EXISTS employee_cost_card_shares (
    id             bigserial PRIMARY KEY,
    card_id        bigint NOT NULL REFERENCES employee_cost_cards(id) ON DELETE CASCADE,
    cost_center_id bigint NOT NULL REFERENCES cost_centers(id),
    share_pct      numeric(5,2) NOT NULL CHECK (share_pct > 0 AND share_pct <= 100),
    UNIQUE (card_id, cost_center_id)
);

-- Machine cost card: depreciation, electricity, maintenance, spare parts and
-- other running costs per practical machine hour. The electricity price is
-- copied from the approved energy rate in force on the card's start date.
CREATE TABLE IF NOT EXISTS machine_cost_cards (
    id                        bigserial PRIMARY KEY,
    machine_id                bigint NOT NULL REFERENCES machines(id),
    version                   int NOT NULL DEFAULT 0,
    effective_from            date NOT NULL,
    acquisition_cost          numeric(14,2) NOT NULL CHECK (acquisition_cost >= 0),
    residual_value            numeric(14,2) NOT NULL CHECK (residual_value >= 0),
    useful_life_years         numeric(5,2) NOT NULL CHECK (useful_life_years > 0),
    theoretical_annual_hours  numeric(8,2) NOT NULL CHECK (theoretical_annual_hours > 0),
    practical_annual_hours    numeric(8,2) NOT NULL CHECK (practical_annual_hours > 0),
    power_kw                  numeric(8,3) NOT NULL CHECK (power_kw >= 0),
    load_factor               numeric(4,3) NOT NULL CHECK (load_factor >= 0 AND load_factor <= 1),
    annual_maintenance        numeric(14,2) NOT NULL CHECK (annual_maintenance >= 0),
    annual_spare_parts        numeric(14,2) NOT NULL CHECK (annual_spare_parts >= 0),
    annual_other              numeric(14,2) NOT NULL CHECK (annual_other >= 0),
    energy_rate_id            bigint REFERENCES energy_rates(id),
    electricity_rate          numeric(10,4),
    depreciation_per_hour     numeric(12,4) GENERATED ALWAYS AS
                              (round((acquisition_cost - residual_value) / useful_life_years / practical_annual_hours, 4)) STORED,
    electricity_per_hour      numeric(12,4) GENERATED ALWAYS AS
                              (round(CASE WHEN power_kw = 0 THEN 0 ELSE power_kw * load_factor * electricity_rate END, 4)) STORED,
    maintenance_per_hour      numeric(12,4) GENERATED ALWAYS AS (round(annual_maintenance / practical_annual_hours, 4)) STORED,
    spare_parts_per_hour      numeric(12,4) GENERATED ALWAYS AS (round(annual_spare_parts / practical_annual_hours, 4)) STORED,
    other_per_hour            numeric(12,4) GENERATED ALWAYS AS (round(annual_other / practical_annual_hours, 4)) STORED,
    hourly_rate               numeric(12,4) GENERATED ALWAYS AS
                              (round((acquisition_cost - residual_value) / useful_life_years / practical_annual_hours, 4)
                               + round(CASE WHEN power_kw = 0 THEN 0 ELSE power_kw * load_factor * electricity_rate END, 4)
                               + round(annual_maintenance / practical_annual_hours, 4)
                               + round(annual_spare_parts / practical_annual_hours, 4)
                               + round(annual_other / practical_annual_hours, 4)) STORED,
    source                    text NOT NULL CHECK (btrim(source) <> ''),
    estimated                 boolean NOT NULL DEFAULT false,
    notes                     text,
    status                    text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by                bigint REFERENCES users(id),
    approved_by               bigint REFERENCES users(id),
    approved_at               timestamptz,
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    CHECK (residual_value <= acquisition_cost),
    CHECK (practical_annual_hours <= theoretical_annual_hours),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_machine_cost_cards_machine ON machine_cost_cards (machine_id, effective_from);

-- Standard purchase price per material (what the standard cost uses), with its basis.
CREATE TABLE IF NOT EXISTS material_standard_prices (
    id             bigserial PRIMARY KEY,
    material_id    bigint NOT NULL REFERENCES raw_materials(id),
    version        int NOT NULL DEFAULT 0,
    effective_from date NOT NULL,
    unit_price     numeric(14,4) NOT NULL CHECK (unit_price > 0),
    price_basis    text NOT NULL CHECK (price_basis IN ('LAST_PURCHASE', 'AVERAGE_COST', 'SUPPLIER_QUOTE', 'MANUAL')),
    source         text NOT NULL CHECK (btrim(source) <> ''),
    estimated      boolean NOT NULL DEFAULT false,
    notes          text,
    status         text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by     bigint REFERENCES users(id),
    approved_by    bigint REFERENCES users(id),
    approved_at    timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_material_standard_prices ON material_standard_prices (material_id, effective_from);

-- Standard waste % by material, or by material category; the material's own rate wins.
CREATE TABLE IF NOT EXISTS waste_defaults (
    id             bigserial PRIMARY KEY,
    material_id    bigint REFERENCES raw_materials(id),
    category       text CHECK (category IS NULL OR (btrim(category) <> '' AND category = btrim(category))),
    subject_key    text GENERATED ALWAYS AS (CASE WHEN material_id IS NOT NULL THEN 'M:' || material_id ELSE 'C:' || lower(btrim(category)) END) STORED,
    version        int NOT NULL DEFAULT 0,
    effective_from date NOT NULL,
    waste_pct      numeric(5,2) NOT NULL CHECK (waste_pct >= 0 AND waste_pct < 100),
    source         text NOT NULL CHECK (btrim(source) <> ''),
    estimated      boolean NOT NULL DEFAULT false,
    notes          text,
    status         text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by     bigint REFERENCES users(id),
    approved_by    bigint REFERENCES users(id),
    approved_at    timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (num_nonnulls(material_id, category) = 1),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);

-- Overhead pools: expected indirect cost of a period, absorbed through a cost
-- driver at practical capacity (never ÷ number of products). Manufacturing pools
-- belong to a cost centre (or the whole factory); the selling & administrative
-- pool gives the % added for the fully loaded cost.
CREATE TABLE IF NOT EXISTS overhead_pools (
    id                          bigserial PRIMARY KEY,
    kind                        text NOT NULL CHECK (kind IN ('MANUFACTURING', 'SELLING_ADMIN')),
    cost_center_id              bigint REFERENCES cost_centers(id),          -- NULL = whole factory
    pool_key                    text GENERATED ALWAYS AS (kind || ':' || COALESCE(cost_center_id::text, 'ALL')) STORED,
    version                     int NOT NULL DEFAULT 0,
    effective_from              date NOT NULL,                               -- period start
    period_to                   date NOT NULL,
    driver                      text NOT NULL CHECK (driver IN ('LABOR_HOURS', 'MACHINE_HOURS', 'PCT_OF_MANUFACTURING_COST')),
    theoretical_capacity        numeric(12,2) CHECK (theoretical_capacity > 0),
    practical_capacity          numeric(12,2) CHECK (practical_capacity > 0),  -- driver hours in the period
    budgeted_manufacturing_cost numeric(16,2) CHECK (budgeted_manufacturing_cost > 0),
    gross_cost                  numeric(16,2),   -- frozen at approval
    machine_energy_deduction    numeric(16,2),   -- frozen at approval
    net_cost                    numeric(16,2),   -- frozen at approval
    rate                        numeric(14,4),   -- per driver hour, or % for selling & admin; frozen at approval
    source                      text NOT NULL CHECK (btrim(source) <> ''),
    estimated                   boolean NOT NULL DEFAULT false,
    notes                       text,
    status                      text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by                  bigint REFERENCES users(id),
    approved_by                 bigint REFERENCES users(id),
    approved_at                 timestamptz,
    created_at                  timestamptz NOT NULL DEFAULT now(),
    updated_at                  timestamptz NOT NULL DEFAULT now(),
    CHECK (period_to >= effective_from),
    CHECK ((kind = 'SELLING_ADMIN') = (driver = 'PCT_OF_MANUFACTURING_COST')),
    CHECK (kind = 'MANUFACTURING' OR cost_center_id IS NULL),
    CHECK (kind <> 'MANUFACTURING' OR practical_capacity IS NOT NULL),
    CHECK (kind <> 'SELLING_ADMIN' OR budgeted_manufacturing_cost IS NOT NULL),
    CHECK (theoretical_capacity IS NULL OR practical_capacity IS NULL OR practical_capacity <= theoretical_capacity),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND rate IS NOT NULL))
);

CREATE TABLE IF NOT EXISTS overhead_pool_lines (
    id          bigserial PRIMARY KEY,
    pool_id     bigint NOT NULL REFERENCES overhead_pools(id) ON DELETE CASCADE,
    category    text NOT NULL CHECK (category IN ('RENT', 'GENERAL_ELECTRICITY', 'SUPERVISION', 'INDIRECT_LABOR', 'CLEANING',
                    'MAINTENANCE', 'CONSUMABLES', 'DEPRECIATION', 'INSURANCE', 'OTHER_PRODUCTION', 'SELLING', 'ADMINISTRATIVE')),
    description text NOT NULL CHECK (btrim(description) <> ''),
    amount      numeric(14,2) NOT NULL CHECK (amount >= 0),
    employee_id bigint REFERENCES workers(id),
    machine_id  bigint REFERENCES machines(id),
    CHECK (category NOT IN ('SUPERVISION', 'INDIRECT_LABOR') OR employee_id IS NOT NULL),
    CHECK (machine_id IS NULL OR category = 'DEPRECIATION')
);

-- Expected electricity of the machines (kW × load × kWh price × practical hours),
-- pro-rated to a period: what machine hour rates already charge.
CREATE OR REPLACE FUNCTION fn_machine_energy(p_from date, p_to date, p_cost_center bigint) RETURNS numeric AS $$
    SELECT COALESCE(round(sum(c.power_kw * c.load_factor * c.electricity_rate * c.practical_annual_hours
                              * ((p_to - p_from + 1)::numeric / 365)), 2), 0)
      FROM machines m
      JOIN LATERAL (SELECT * FROM machine_cost_cards x
                     WHERE x.machine_id = m.id AND x.status = 'APPROVED' AND x.effective_from <= p_to
                     ORDER BY x.effective_from DESC LIMIT 1) c ON true
     WHERE p_cost_center IS NULL OR m.cost_center_id = p_cost_center
$$ LANGUAGE sql STABLE;

-- Draft-only lines, and no cost counted twice: a direct worker in an indirect
-- labour line, a machine already depreciated in its hour rate, selling/admin
-- costs in a manufacturing pool (or the reverse), factory electricity outside
-- the whole-factory pool.
CREATE OR REPLACE FUNCTION fn_overhead_line_guard() RETURNS trigger AS $$
DECLARE
    v_pool overhead_pools%ROWTYPE;
BEGIN
    SELECT * INTO v_pool FROM overhead_pools WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.pool_id ELSE NEW.pool_id END;
    IF v_pool.status <> 'DRAFT' THEN
        RAISE EXCEPTION 'RROKA_COST_RECORD_LOCKED: lines of a % pool cannot change', v_pool.status USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    IF (v_pool.kind = 'SELLING_ADMIN') <> (NEW.category IN ('SELLING', 'ADMINISTRATIVE')) THEN
        RAISE EXCEPTION 'RROKA_POOL_CATEGORY: % does not belong in a % pool', NEW.category, v_pool.kind USING ERRCODE = 'P0001';
    END IF;
    IF NEW.category = 'GENERAL_ELECTRICITY' AND v_pool.cost_center_id IS NOT NULL THEN
        RAISE EXCEPTION 'RROKA_POOL_CATEGORY: factory electricity belongs in the whole-factory pool' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.employee_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM employee_cost_cards c WHERE c.employee_id = NEW.employee_id AND c.status = 'APPROVED'
           AND c.effective_from <= v_pool.period_to) THEN
        RAISE EXCEPTION 'RROKA_DOUBLE_COUNT_LABOR: this employee is costed as direct labour' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.machine_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM machine_cost_cards c WHERE c.machine_id = NEW.machine_id AND c.status = 'APPROVED'
           AND c.effective_from <= v_pool.period_to) THEN
        RAISE EXCEPTION 'RROKA_DOUBLE_COUNT_MACHINE: this machine is depreciated in its hour rate' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_overhead_line_guard ON overhead_pool_lines;
CREATE TRIGGER trg_overhead_line_guard BEFORE INSERT OR UPDATE OR DELETE ON overhead_pool_lines
    FOR EACH ROW EXECUTE FUNCTION fn_overhead_line_guard();

-- Pool: the driver follows the cost centre; approval freezes the figures and refuses
-- an overlapping approved pool for the same centre.
CREATE OR REPLACE FUNCTION fn_overhead_pool_guard() RETURNS trigger AS $$
DECLARE
    v_gross  numeric;
    v_elec   numeric;
    v_energy numeric := 0;
BEGIN
    IF NEW.status = 'DRAFT' AND NEW.cost_center_id IS NOT NULL THEN
        SELECT driver INTO NEW.driver FROM cost_centers WHERE id = NEW.cost_center_id;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.status = 'DRAFT' AND NEW.status = 'APPROVED' THEN
        IF EXISTS (SELECT 1 FROM overhead_pools p WHERE p.kind = NEW.kind AND p.cost_center_id IS NOT DISTINCT FROM NEW.cost_center_id
                     AND p.status = 'APPROVED' AND p.id <> NEW.id
                     AND p.effective_from <= NEW.period_to AND p.period_to >= NEW.effective_from) THEN
            RAISE EXCEPTION 'RROKA_POOL_OVERLAP: an approved pool already covers this period' USING ERRCODE = 'P0001';
        END IF;
        SELECT sum(amount), sum(amount) FILTER (WHERE category = 'GENERAL_ELECTRICITY') INTO v_gross, v_elec
          FROM overhead_pool_lines WHERE pool_id = NEW.id;
        IF v_gross IS NULL THEN
            RAISE EXCEPTION 'RROKA_POOL_EMPTY' USING ERRCODE = 'P0001';
        END IF;
        IF v_elec IS NOT NULL THEN
            v_energy := fn_machine_energy(NEW.effective_from, NEW.period_to, NULL);
            IF v_energy > v_elec THEN
                RAISE EXCEPTION 'RROKA_DOUBLE_COUNT_ELECTRICITY: the bill (%) is less than the machines'' own electricity (%)', v_elec, v_energy
                    USING ERRCODE = 'P0001';
            END IF;
        END IF;
        NEW.gross_cost := v_gross;
        NEW.machine_energy_deduction := v_energy;
        NEW.net_cost := v_gross - v_energy;
        NEW.rate := CASE WHEN NEW.kind = 'SELLING_ADMIN'
                         THEN round((v_gross - v_energy) / NEW.budgeted_manufacturing_cost * 100, 4)
                         ELSE round((v_gross - v_energy) / NEW.practical_capacity, 4) END;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

-- Employee card: shares must cover 100% before approval; approval writes the
-- worker's hourly rate (traceable to the card).
ALTER TABLE worker_rates ADD COLUMN IF NOT EXISTS cost_card_id bigint REFERENCES employee_cost_cards(id);
ALTER TABLE machine_rates ADD COLUMN IF NOT EXISTS cost_card_id bigint REFERENCES machine_cost_cards(id);

CREATE OR REPLACE FUNCTION fn_employee_cost_card_approve() RETURNS trigger AS $$
DECLARE
    v_shares numeric;
BEGIN
    IF NEW.status = 'APPROVED' AND OLD.status = 'DRAFT' THEN
        SELECT sum(share_pct) INTO v_shares FROM employee_cost_card_shares WHERE card_id = NEW.id;
        IF v_shares IS DISTINCT FROM 100 THEN
            RAISE EXCEPTION 'RROKA_COST_SHARES_INCOMPLETE: cost centre shares total %', COALESCE(v_shares, 0) USING ERRCODE = 'P0001';
        END IF;
        IF EXISTS (SELECT 1 FROM worker_rates WHERE worker_id = NEW.employee_id AND effective_from = NEW.effective_from) THEN
            RAISE EXCEPTION 'RROKA_RATE_DATE_TAKEN: a rate already starts on %', NEW.effective_from USING ERRCODE = 'P0001';
        END IF;
        INSERT INTO worker_rates (worker_id, hourly_cost, effective_from, basis_note, entered_by, cost_card_id)
        VALUES (NEW.employee_id, NEW.hourly_rate, NEW.effective_from,
                format('بطاقة تكلفة الموظف — الإصدار %s: %s ÷ %s ساعة إنتاجية', NEW.version, NEW.monthly_cost, NEW.practical_hours),
                NEW.approved_by, NEW.id);
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION fn_cost_card_shares_guard() RETURNS trigger AS $$
BEGIN
    IF (SELECT status FROM employee_cost_cards WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.card_id ELSE NEW.card_id END) <> 'DRAFT' THEN
        RAISE EXCEPTION 'RROKA_COST_RECORD_LOCKED: shares of an approved card cannot change' USING ERRCODE = 'P0001';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_cost_card_shares_guard ON employee_cost_card_shares;
CREATE TRIGGER trg_cost_card_shares_guard BEFORE INSERT OR UPDATE OR DELETE ON employee_cost_card_shares
    FOR EACH ROW EXECUTE FUNCTION fn_cost_card_shares_guard();

-- Machine card: the electricity price comes from the approved energy rate in force;
-- approval writes the machine's hour rate.
CREATE OR REPLACE FUNCTION fn_machine_cost_card_energy() RETURNS trigger AS $$
DECLARE
    v_rate energy_rates%ROWTYPE;
BEGIN
    IF NEW.status = 'DRAFT' OR (TG_OP = 'UPDATE' AND OLD.status = 'DRAFT') THEN
        v_rate := fn_energy_rate_on(NEW.effective_from);
        NEW.energy_rate_id := v_rate.id;
        NEW.electricity_rate := v_rate.rate_per_kwh;
    END IF;
    IF NEW.status = 'APPROVED' AND TG_OP = 'UPDATE' AND OLD.status = 'DRAFT' AND NEW.power_kw > 0 AND NEW.electricity_rate IS NULL THEN
        RAISE EXCEPTION 'RROKA_ENERGY_RATE_MISSING: no approved electricity rate on %', NEW.effective_from USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION fn_machine_cost_card_approve() RETURNS trigger AS $$
BEGIN
    IF NEW.status = 'APPROVED' AND OLD.status = 'DRAFT' THEN
        IF NEW.hourly_rate IS NULL OR NEW.hourly_rate <= 0 THEN
            RAISE EXCEPTION 'RROKA_RATE_ZERO: the machine hour rate must be above zero' USING ERRCODE = 'P0001';
        END IF;
        IF EXISTS (SELECT 1 FROM machine_rates WHERE machine_id = NEW.machine_id AND effective_from = NEW.effective_from) THEN
            RAISE EXCEPTION 'RROKA_RATE_DATE_TAKEN: a rate already starts on %', NEW.effective_from USING ERRCODE = 'P0001';
        END IF;
        INSERT INTO machine_rates (machine_id, hourly_cost, effective_from, basis_note, entered_by, cost_card_id)
        VALUES (NEW.machine_id, NEW.hourly_rate, NEW.effective_from,
                format('بطاقة تكلفة الآلة — الإصدار %s: إهلاك %s + كهرباء %s + صيانة %s + قطع غيار %s + أخرى %s للساعة',
                       NEW.version, NEW.depreciation_per_hour, NEW.electricity_per_hour, NEW.maintenance_per_hour,
                       NEW.spare_parts_per_hour, NEW.other_per_hour),
                NEW.approved_by, NEW.id);
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_a_cost_guard ON energy_rates;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON energy_rates FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('');
DROP TRIGGER IF EXISTS trg_a_cost_guard ON employee_cost_cards;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON employee_cost_cards FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('employee_id');
DROP TRIGGER IF EXISTS trg_a_cost_guard ON machine_cost_cards;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON machine_cost_cards FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('machine_id');
DROP TRIGGER IF EXISTS trg_a_cost_guard ON material_standard_prices;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON material_standard_prices FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('material_id');
DROP TRIGGER IF EXISTS trg_a_cost_guard ON waste_defaults;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON waste_defaults FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('material_id,category');
DROP TRIGGER IF EXISTS trg_a_cost_guard ON overhead_pools;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON overhead_pools FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('kind,cost_center_id');
DROP TRIGGER IF EXISTS trg_b_pool_guard ON overhead_pools;
CREATE TRIGGER trg_b_pool_guard BEFORE INSERT OR UPDATE ON overhead_pools FOR EACH ROW EXECUTE FUNCTION fn_overhead_pool_guard();
DROP TRIGGER IF EXISTS trg_b_machine_card_energy ON machine_cost_cards;
CREATE TRIGGER trg_b_machine_card_energy BEFORE INSERT OR UPDATE ON machine_cost_cards FOR EACH ROW EXECUTE FUNCTION fn_machine_cost_card_energy();
DROP TRIGGER IF EXISTS trg_employee_cost_card_approve ON employee_cost_cards;
CREATE TRIGGER trg_employee_cost_card_approve AFTER UPDATE ON employee_cost_cards FOR EACH ROW EXECUTE FUNCTION fn_employee_cost_card_approve();
DROP TRIGGER IF EXISTS trg_machine_cost_card_approve ON machine_cost_cards;
CREATE TRIGGER trg_machine_cost_card_approve AFTER UPDATE ON machine_cost_cards FOR EACH ROW EXECUTE FUNCTION fn_machine_cost_card_approve();

-- Standard price and waste in force on a date (NULL when none: never assumed).
CREATE OR REPLACE FUNCTION fn_standard_price(p_material bigint, p_on date) RETURNS numeric AS $$
    SELECT unit_price FROM material_standard_prices
     WHERE material_id = p_material AND status = 'APPROVED' AND effective_from <= p_on
     ORDER BY effective_from DESC LIMIT 1
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_standard_waste_pct(p_material bigint, p_on date) RETURNS numeric AS $$
    SELECT COALESCE(
        (SELECT waste_pct FROM waste_defaults WHERE subject_key = 'M:' || p_material AND status = 'APPROVED' AND effective_from <= p_on
          ORDER BY effective_from DESC LIMIT 1),
        (SELECT w.waste_pct FROM waste_defaults w JOIN raw_materials m ON m.id = p_material
          WHERE w.subject_key = 'C:' || lower(btrim(m.category)) AND w.status = 'APPROVED' AND w.effective_from <= p_on
          ORDER BY w.effective_from DESC LIMIT 1))
$$ LANGUAGE sql STABLE;

-- Validity periods: each approved version runs until the next one starts.
CREATE OR REPLACE VIEW v_cost_rate_periods AS
SELECT 'energy_rates'::text AS table_name, id, ''::text AS series, version, effective_from,
       lead(effective_from) OVER (ORDER BY effective_from) - 1 AS effective_to
  FROM energy_rates WHERE status = 'APPROVED'
UNION ALL
SELECT 'employee_cost_cards', id, employee_id::text, version, effective_from,
       lead(effective_from) OVER (PARTITION BY employee_id ORDER BY effective_from) - 1
  FROM employee_cost_cards WHERE status = 'APPROVED'
UNION ALL
SELECT 'machine_cost_cards', id, machine_id::text, version, effective_from,
       lead(effective_from) OVER (PARTITION BY machine_id ORDER BY effective_from) - 1
  FROM machine_cost_cards WHERE status = 'APPROVED'
UNION ALL
SELECT 'material_standard_prices', id, material_id::text, version, effective_from,
       lead(effective_from) OVER (PARTITION BY material_id ORDER BY effective_from) - 1
  FROM material_standard_prices WHERE status = 'APPROVED'
UNION ALL
SELECT 'waste_defaults', id, subject_key, version, effective_from,
       lead(effective_from) OVER (PARTITION BY subject_key ORDER BY effective_from) - 1
  FROM waste_defaults WHERE status = 'APPROVED';

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['cost_centers', 'energy_rates', 'employee_cost_cards', 'employee_cost_card_shares', 'machine_cost_cards',
                             'material_standard_prices', 'waste_defaults', 'overhead_pools', 'overhead_pool_lines'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
    END LOOP;
    FOREACH t IN ARRAY ARRAY['cost_centers', 'energy_rates', 'employee_cost_cards', 'machine_cost_cards',
                             'material_standard_prices', 'waste_defaults', 'overhead_pools'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND p.code = 'cost_rates.approve'
ON CONFLICT DO NOTHING;

-- ---------------------------------------------------------------------
-- Costing engine, phase 2 (2026-10-05): standard cost and pricing per
-- quotation line. Every figure is computed here from the approved rates in
-- force on the quotation date; approval of a quotation that requires costing
-- is refused until every line's estimate is complete, and freezes an
-- immutable snapshot (figures + the ids/versions of every rate used).
-- VAT (approved 2026-10-05): computed on the quotation from the rate the
-- user picks; no rate picked = no VAT. Posting and returns stay in Daftra.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS vat_rates (
    id          bigserial PRIMARY KEY,
    name        text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    rate_pct    numeric(5,2) NOT NULL CHECK (rate_pct >= 0 AND rate_pct < 100),
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION fn_vat_rate_guard() RETURNS trigger AS $$
BEGIN
    IF NEW.rate_pct <> OLD.rate_pct THEN
        RAISE EXCEPTION 'RROKA_VAT_RATE_LOCKED: a rate cannot change; add a new one' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_vat_rate_guard ON vat_rates;
CREATE TRIGGER trg_vat_rate_guard BEFORE UPDATE ON vat_rates FOR EACH ROW EXECUTE FUNCTION fn_vat_rate_guard();

ALTER TABLE quotations ADD COLUMN IF NOT EXISTS vat_rate_id bigint REFERENCES vat_rates(id);
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS vat_pct numeric(5,2);
-- Quotations created before costing existed keep working; every new one requires it.
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS requires_costing boolean NOT NULL DEFAULT false;
ALTER TABLE quotations ALTER COLUMN requires_costing SET DEFAULT true;

-- The VAT percentage is copied from the chosen rate (only an active one can be chosen).
CREATE OR REPLACE FUNCTION fn_quotation_vat() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' OR NEW.vat_rate_id IS DISTINCT FROM OLD.vat_rate_id THEN
        IF NEW.vat_rate_id IS NULL THEN
            NEW.vat_pct := NULL;
        ELSE
            SELECT rate_pct INTO NEW.vat_pct FROM vat_rates WHERE id = NEW.vat_rate_id AND is_active;
            IF NEW.vat_pct IS NULL THEN
                RAISE EXCEPTION 'RROKA_VAT_RATE_INACTIVE' USING ERRCODE = 'P0001';
            END IF;
        END IF;
    ELSIF NEW.vat_pct IS DISTINCT FROM OLD.vat_pct THEN
        NEW.vat_pct := OLD.vat_pct;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_quotation_a_vat ON quotations;
CREATE TRIGGER trg_quotation_a_vat BEFORE INSERT OR UPDATE ON quotations FOR EACH ROW EXECUTE FUNCTION fn_quotation_vat();

CREATE OR REPLACE VIEW v_quotation_totals AS
SELECT q.id AS quotation_id,
       COALESCE(sum(l.line_total), 0)                     AS subtotal,
       q.discount_amount,
       COALESCE(sum(l.line_total), 0) - q.discount_amount AS net_before_vat,
       q.vat_pct,
       round((COALESCE(sum(l.line_total), 0) - q.discount_amount) * COALESCE(q.vat_pct, 0) / 100, 2) AS vat_amount,
       COALESCE(sum(l.line_total), 0) - q.discount_amount
         + round((COALESCE(sum(l.line_total), 0) - q.discount_amount) * COALESCE(q.vat_pct, 0) / 100, 2) AS total_incl_vat
FROM quotations q
LEFT JOIN quotation_lines l ON l.quotation_id = q.id
GROUP BY q.id;

-- Pricing policy (versioned): default method, target and minimum margin.
CREATE TABLE IF NOT EXISTS pricing_policies (
    id              bigserial PRIMARY KEY,
    version         int NOT NULL DEFAULT 0,
    effective_from  date NOT NULL,
    pricing_method  text NOT NULL CHECK (pricing_method IN ('MARGIN', 'MARKUP')),
    target_pct      numeric(6,2) NOT NULL CHECK (target_pct >= 0),
    min_margin_pct  numeric(5,2) CHECK (min_margin_pct >= 0 AND min_margin_pct < 100),
    source          text NOT NULL CHECK (btrim(source) <> ''),
    estimated       boolean NOT NULL DEFAULT false,
    notes           text,
    status          text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by      bigint REFERENCES users(id),
    approved_by     bigint REFERENCES users(id),
    approved_at     timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (pricing_method <> 'MARGIN' OR target_pct < 100),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
DROP TRIGGER IF EXISTS trg_a_cost_guard ON pricing_policies;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON pricing_policies FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('');

-- Cost estimate of one quotation line (keyed by line number: lines are rewritten on edit).
CREATE TABLE IF NOT EXISTS cost_estimates (
    id                    bigserial PRIMARY KEY,
    quotation_id          bigint NOT NULL REFERENCES quotations(id) ON DELETE CASCADE,
    line_no               int NOT NULL CHECK (line_no > 0),
    product_category      text,
    dimensions            text,
    specifications        text,      -- material grade, fabric, wood type, foam, accessories
    pricing_method        text NOT NULL CHECK (pricing_method IN ('MARGIN', 'MARKUP')),
    target_pct            numeric(6,2) NOT NULL CHECK (target_pct >= 0),
    min_margin_pct        numeric(5,2) CHECK (min_margin_pct >= 0 AND min_margin_pct < 100),
    installation_required boolean NOT NULL DEFAULT false,
    delivery_required     boolean NOT NULL DEFAULT false,
    notes                 text,
    created_by            bigint REFERENCES users(id),
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    UNIQUE (quotation_id, line_no),
    CHECK (pricing_method <> 'MARGIN' OR target_pct < 100)
);

-- BOM of the estimate, per unit of the line. Waste and price come from the
-- approved standards unless overridden here (an overriding price names its source).
CREATE TABLE IF NOT EXISTS cost_estimate_materials (
    id           bigserial PRIMARY KEY,
    estimate_id  bigint NOT NULL REFERENCES cost_estimates(id) ON DELETE CASCADE,
    material_id  bigint NOT NULL REFERENCES raw_materials(id),
    quantity     numeric(14,4) NOT NULL CHECK (quantity > 0),
    waste_pct    numeric(5,2) CHECK (waste_pct >= 0 AND waste_pct < 100),
    unit_price   numeric(14,4) CHECK (unit_price > 0),
    price_source text,
    notes        text,
    CHECK (unit_price IS NULL OR NULLIF(btrim(price_source), '') IS NOT NULL)
);

-- Routing: operations with their cost centre, hours per unit and setup hours per batch.
CREATE TABLE IF NOT EXISTS cost_estimate_operations (
    id                  bigserial PRIMARY KEY,
    estimate_id         bigint NOT NULL REFERENCES cost_estimates(id) ON DELETE CASCADE,
    seq                 int NOT NULL CHECK (seq > 0),
    operation           text NOT NULL CHECK (btrim(operation) <> ''),
    cost_center_id      bigint NOT NULL REFERENCES cost_centers(id),
    employee_id         bigint REFERENCES workers(id),       -- optional: that employee's rate instead of the centre's
    labor_hours         numeric(10,3) NOT NULL CHECK (labor_hours >= 0),          -- per unit
    setup_hours         numeric(10,3) NOT NULL CHECK (setup_hours >= 0),          -- once per batch
    machine_id          bigint REFERENCES machines(id),
    machine_hours       numeric(10,3) NOT NULL DEFAULT 0 CHECK (machine_hours >= 0),          -- per unit
    machine_setup_hours numeric(10,3) NOT NULL DEFAULT 0 CHECK (machine_setup_hours >= 0),    -- once per batch
    notes               text,
    CHECK (machine_id IS NOT NULL OR (machine_hours = 0 AND machine_setup_hours = 0)),
    CHECK (labor_hours + setup_hours + machine_hours + machine_setup_hours > 0)
);

CREATE TABLE IF NOT EXISTS cost_estimate_direct_costs (
    id          bigserial PRIMARY KEY,
    estimate_id bigint NOT NULL REFERENCES cost_estimates(id) ON DELETE CASCADE,
    cost_type   text NOT NULL CHECK (cost_type IN ('EXTERNAL_MANUFACTURING', 'SUBCONTRACTOR', 'SPECIAL_DELIVERY', 'INSTALLATION', 'CRANE',
                    'SPECIAL_TRANSPORT', 'EXTERNAL_PAINTING', 'SPECIAL_DESIGN', 'COMMISSION', 'OTHER')),
    description text NOT NULL CHECK (btrim(description) <> ''),
    amount      numeric(14,2) NOT NULL CHECK (amount >= 0),
    basis       text NOT NULL CHECK (basis IN ('PER_UNIT', 'ONE_TIME'))
);

-- An estimate changes only while its quotation is a draft or sent (not yet decided),
-- and only for a line that exists.
CREATE OR REPLACE FUNCTION fn_estimate_guard() RETURNS trigger AS $$
DECLARE
    v_estimate bigint;
    v_quote    bigint;
    v_status   text;
BEGIN
    IF TG_TABLE_NAME = 'cost_estimates' THEN
        v_quote := CASE WHEN TG_OP = 'DELETE' THEN (to_jsonb(OLD) ->> 'quotation_id')::bigint ELSE (to_jsonb(NEW) ->> 'quotation_id')::bigint END;
    ELSE
        v_estimate := CASE WHEN TG_OP = 'DELETE' THEN (to_jsonb(OLD) ->> 'estimate_id')::bigint ELSE (to_jsonb(NEW) ->> 'estimate_id')::bigint END;
        SELECT quotation_id INTO v_quote FROM cost_estimates WHERE id = v_estimate;
    END IF;
    SELECT status INTO v_status FROM quotations WHERE id = v_quote;
    IF v_status IS NOT NULL AND v_status NOT IN ('DRAFT', 'SENT') THEN
        RAISE EXCEPTION 'RROKA_ESTIMATE_LOCKED: the quotation is %', v_status USING ERRCODE = 'P0001';
    END IF;
    IF TG_TABLE_NAME = 'cost_estimates' AND TG_OP = 'INSERT' THEN
        IF NOT EXISTS (SELECT 1 FROM quotation_lines WHERE quotation_id = v_quote AND line_no = (to_jsonb(NEW) ->> 'line_no')::int) THEN
            RAISE EXCEPTION 'RROKA_ESTIMATE_NO_LINE: line % does not exist', to_jsonb(NEW) ->> 'line_no' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$ LANGUAGE plpgsql;

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['cost_estimates', 'cost_estimate_materials', 'cost_estimate_operations', 'cost_estimate_direct_costs'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_estimate_guard ON %I', t);
        EXECUTE format('CREATE TRIGGER trg_estimate_guard BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION fn_estimate_guard()', t);
    END LOOP;
END $$;

-- Material lines: standard waste (the material's own, else its category's) and
-- standard price in force on the quotation date, unless overridden.
CREATE OR REPLACE VIEW v_estimate_material_lines AS
SELECT m.id, m.estimate_id, e.quotation_id, e.line_no, m.material_id, rm.code, rm.name, rm.category, rm.uom,
       m.quantity AS qty_per_unit, l.quantity AS line_qty,
       COALESCE(m.waste_pct, wd.waste_pct) AS waste_pct,
       CASE WHEN m.waste_pct IS NOT NULL THEN 'OVERRIDE' WHEN wd.id IS NOT NULL THEN 'STANDARD' END AS waste_source,
       wd.id AS waste_default_id, wd.version AS waste_version,
       COALESCE(m.unit_price, sp.unit_price) AS unit_price,
       CASE WHEN m.unit_price IS NOT NULL THEN 'OVERRIDE' WHEN sp.id IS NOT NULL THEN 'STANDARD' END AS price_source,
       m.price_source AS override_source, sp.id AS standard_price_id, sp.version AS price_version,
       round(m.quantity * (1 + COALESCE(m.waste_pct, wd.waste_pct) / 100), 4) AS adjusted_qty_per_unit,
       round(m.quantity * (1 + COALESCE(m.waste_pct, wd.waste_pct) / 100) * l.quantity * COALESCE(m.unit_price, sp.unit_price), 2) AS total_cost
  FROM cost_estimate_materials m
  JOIN cost_estimates e ON e.id = m.estimate_id
  JOIN quotations q ON q.id = e.quotation_id
  JOIN quotation_lines l ON l.quotation_id = e.quotation_id AND l.line_no = e.line_no
  JOIN raw_materials rm ON rm.id = m.material_id
  LEFT JOIN LATERAL (SELECT id, version, unit_price FROM material_standard_prices
                      WHERE material_id = m.material_id AND status = 'APPROVED' AND effective_from <= q.issue_date
                      ORDER BY effective_from DESC LIMIT 1) sp ON true
  LEFT JOIN LATERAL (SELECT w.id, w.version, w.waste_pct FROM waste_defaults w
                      WHERE w.status = 'APPROVED' AND w.effective_from <= q.issue_date
                        AND (w.subject_key = 'M:' || m.material_id OR w.subject_key = 'C:' || lower(btrim(rm.category)))
                      ORDER BY (w.material_id IS NOT NULL) DESC, w.effective_from DESC LIMIT 1) wd ON true;

-- Operation lines: hours (per unit × quantity + setup once per batch), labour rate
-- (the named employee's, else the centre's blended rate from approved cost cards),
-- machine rate, and the centre's overhead rate applied to its driver.
CREATE OR REPLACE VIEW v_estimate_operation_lines AS
SELECT x.*,
       CASE WHEN x.total_labor_hours = 0 THEN 0 ELSE round(x.total_labor_hours * x.labor_rate, 2) END AS labor_cost,
       CASE WHEN x.total_machine_hours = 0 THEN 0 ELSE round(x.total_machine_hours * x.machine_rate, 2) END AS machine_cost,
       CASE WHEN x.driver_hours = 0 THEN 0 ELSE round(x.driver_hours * x.overhead_rate, 2) END AS overhead_cost
  FROM (
    SELECT o.id, o.estimate_id, e.quotation_id, e.line_no, o.seq, o.operation, o.cost_center_id, cc.name AS cost_center_name, cc.driver,
           o.employee_id, o.machine_id, o.labor_hours, o.setup_hours, o.machine_hours, o.machine_setup_hours, l.quantity AS line_qty,
           o.labor_hours * l.quantity + o.setup_hours AS total_labor_hours,
           o.machine_hours * l.quantity + o.machine_setup_hours AS total_machine_hours,
           CASE WHEN cc.driver = 'MACHINE_HOURS' THEN o.machine_hours * l.quantity + o.machine_setup_hours
                ELSE o.labor_hours * l.quantity + o.setup_hours END AS driver_hours,
           CASE WHEN o.employee_id IS NOT NULL THEN wr.hourly_cost ELSE clr.rate END AS labor_rate,
           CASE WHEN o.employee_id IS NOT NULL THEN 'EMPLOYEE' ELSE 'CENTER' END AS labor_rate_basis,
           wr.id AS worker_rate_id, clr.cards AS center_rate_cards,
           mr.hourly_cost AS machine_rate, mr.id AS machine_rate_id,
           pool.id AS pool_id, pool.version AS pool_version, pool.rate AS overhead_rate
      FROM cost_estimate_operations o
      JOIN cost_estimates e ON e.id = o.estimate_id
      JOIN quotations q ON q.id = e.quotation_id
      JOIN quotation_lines l ON l.quotation_id = e.quotation_id AND l.line_no = e.line_no
      JOIN cost_centers cc ON cc.id = o.cost_center_id
      LEFT JOIN LATERAL (SELECT id, hourly_cost FROM worker_rates
                          WHERE worker_id = o.employee_id AND effective_from <= q.issue_date
                          ORDER BY effective_from DESC LIMIT 1) wr ON true
      LEFT JOIN LATERAL (
          SELECT round(sum(c.monthly_cost * s.share_pct) / NULLIF(sum(c.practical_hours * s.share_pct), 0), 4) AS rate,
                 array_agg(c.id ORDER BY c.id) AS cards
            FROM employee_cost_card_shares s
            JOIN employee_cost_cards c ON c.id = s.card_id
           WHERE s.cost_center_id = o.cost_center_id
             AND c.id = (SELECT c2.id FROM employee_cost_cards c2
                          WHERE c2.employee_id = c.employee_id AND c2.status = 'APPROVED' AND c2.effective_from <= q.issue_date
                          ORDER BY c2.effective_from DESC LIMIT 1)) clr ON true
      LEFT JOIN LATERAL (SELECT id, hourly_cost FROM machine_rates
                          WHERE machine_id = o.machine_id AND effective_from <= q.issue_date
                          ORDER BY effective_from DESC LIMIT 1) mr ON true
      LEFT JOIN LATERAL (SELECT id, version, rate FROM overhead_pools
                          WHERE kind = 'MANUFACTURING' AND cost_center_id = o.cost_center_id AND status = 'APPROVED'
                            AND effective_from <= q.issue_date AND period_to >= q.issue_date
                          ORDER BY effective_from DESC LIMIT 1) pool ON true
  ) x;

-- The cost sheet of each estimate: direct cost, manufacturing cost, fully loaded
-- cost, recommended price and the margin of the quoted price. A missing rate
-- leaves the figure NULL and is named in `missing` — never replaced by zero.
CREATE OR REPLACE VIEW v_estimate_costs AS
WITH mat AS (
    SELECT estimate_id, sum(total_cost) AS cost, count(*) FILTER (WHERE total_cost IS NULL) AS missing, count(*) AS n
      FROM v_estimate_material_lines GROUP BY estimate_id
), ops AS (
    SELECT estimate_id, sum(labor_cost) AS labor, count(*) FILTER (WHERE labor_cost IS NULL) AS labor_missing,
           sum(machine_cost) AS machine, count(*) FILTER (WHERE machine_cost IS NULL) AS machine_missing,
           sum(overhead_cost) AS overhead, count(*) FILTER (WHERE overhead_cost IS NULL) AS overhead_missing,
           sum(total_labor_hours) AS labor_hours, sum(total_machine_hours) AS machine_hours, count(*) AS n
      FROM v_estimate_operation_lines GROUP BY estimate_id
), dir AS (
    SELECT d.estimate_id, sum(CASE d.basis WHEN 'PER_UNIT' THEN d.amount * l.quantity ELSE d.amount END) AS cost, count(*) AS n
      FROM cost_estimate_direct_costs d
      JOIN cost_estimates e ON e.id = d.estimate_id
      JOIN quotation_lines l ON l.quotation_id = e.quotation_id AND l.line_no = e.line_no
     GROUP BY d.estimate_id
), base AS (
    SELECT e.id AS estimate_id, e.quotation_id, e.line_no, l.quantity, e.pricing_method, e.target_pct, e.min_margin_pct,
           l.line_total, round(q.discount_amount * l.line_total / NULLIF(t.subtotal, 0), 2) AS discount_share,
           COALESCE(mat.n, 0) + COALESCE(ops.n, 0) + COALESCE(dir.n, 0) AS components,
           CASE WHEN COALESCE(mat.missing, 0) = 0 THEN round(COALESCE(mat.cost, 0), 2) END AS materials_cost,
           CASE WHEN COALESCE(ops.labor_missing, 0) = 0 THEN COALESCE(ops.labor, 0) END AS labor_cost,
           CASE WHEN COALESCE(ops.machine_missing, 0) = 0 THEN COALESCE(ops.machine, 0) END AS machine_cost,
           round(COALESCE(dir.cost, 0), 2) AS direct_other_cost,
           CASE WHEN COALESCE(ops.overhead_missing, 0) = 0 THEN COALESCE(ops.overhead, 0) END AS center_overhead,
           CASE WHEN fp.id IS NULL THEN 0
                ELSE round(CASE fp.driver WHEN 'MACHINE_HOURS' THEN COALESCE(ops.machine_hours, 0) ELSE COALESCE(ops.labor_hours, 0) END * fp.rate, 2)
           END AS factory_overhead,
           fp.id AS factory_pool_id, fp.version AS factory_pool_version, fp.rate AS factory_pool_rate,
           sa.id AS sa_pool_id, sa.version AS sa_pool_version, sa.rate AS selling_admin_pct,
           COALESCE(mat.missing, 0) AS material_lines_missing, COALESCE(ops.labor_missing, 0) AS labor_lines_missing,
           COALESCE(ops.machine_missing, 0) AS machine_lines_missing, COALESCE(ops.overhead_missing, 0) AS overhead_lines_missing,
           COALESCE(ops.labor_hours, 0) AS labor_hours, COALESCE(ops.machine_hours, 0) AS machine_hours
      FROM cost_estimates e
      JOIN quotations q ON q.id = e.quotation_id
      JOIN v_quotation_totals t ON t.quotation_id = q.id
      JOIN quotation_lines l ON l.quotation_id = e.quotation_id AND l.line_no = e.line_no
      LEFT JOIN mat ON mat.estimate_id = e.id
      LEFT JOIN ops ON ops.estimate_id = e.id
      LEFT JOIN dir ON dir.estimate_id = e.id
      LEFT JOIN LATERAL (SELECT id, version, rate, driver FROM overhead_pools
                          WHERE kind = 'MANUFACTURING' AND cost_center_id IS NULL AND status = 'APPROVED'
                            AND effective_from <= q.issue_date AND period_to >= q.issue_date
                          ORDER BY effective_from DESC LIMIT 1) fp ON true
      LEFT JOIN LATERAL (SELECT id, version, rate FROM overhead_pools
                          WHERE kind = 'SELLING_ADMIN' AND status = 'APPROVED'
                            AND effective_from <= q.issue_date AND period_to >= q.issue_date
                          ORDER BY effective_from DESC LIMIT 1) sa ON true
), cost AS (
    SELECT b.*,
           b.materials_cost + b.labor_cost + b.machine_cost + b.direct_other_cost AS direct_cost,
           b.center_overhead + b.factory_overhead AS overhead_cost,
           b.line_total - b.discount_share AS net_price
      FROM base b
), mfg AS (
    SELECT c.*, c.direct_cost + c.overhead_cost AS manufacturing_cost FROM cost c
), full_cost AS (
    SELECT m.*, round(m.manufacturing_cost * (1 + m.selling_admin_pct / 100), 2) AS fully_loaded_cost FROM mfg m
), priced AS (
    SELECT f.*,
           CASE f.pricing_method WHEN 'MARGIN' THEN round(f.fully_loaded_cost / (1 - f.target_pct / 100), 2)
                                 ELSE round(f.fully_loaded_cost * (1 + f.target_pct / 100), 2) END AS recommended_price
      FROM full_cost f
)
SELECT p.estimate_id, p.quotation_id, p.line_no, p.quantity, p.pricing_method, p.target_pct, p.min_margin_pct,
       p.materials_cost, p.labor_cost, p.machine_cost, p.direct_other_cost, p.direct_cost,
       p.center_overhead, p.factory_overhead, p.overhead_cost, p.manufacturing_cost,
       p.selling_admin_pct, p.fully_loaded_cost, p.recommended_price,
       p.line_total, p.discount_share, p.net_price,
       p.net_price - p.manufacturing_cost AS gross_profit,
       CASE WHEN p.net_price > 0 THEN round((p.net_price - p.manufacturing_cost) / p.net_price * 100, 2) END AS gross_margin_pct,
       CASE WHEN p.manufacturing_cost > 0 THEN round((p.net_price - p.manufacturing_cost) / p.manufacturing_cost * 100, 2) END AS markup_pct,
       p.net_price - p.fully_loaded_cost AS profit_after_overheads,
       CASE WHEN p.net_price > 0 THEN round((p.net_price - p.fully_loaded_cost) / p.net_price * 100, 2) END AS margin_after_overheads_pct,
       p.net_price - (p.materials_cost + p.direct_other_cost) AS contribution,
       p.labor_hours, p.machine_hours,
       p.factory_pool_id, p.factory_pool_version, p.factory_pool_rate, p.sa_pool_id, p.sa_pool_version,
       array_remove(ARRAY[
           CASE WHEN p.components = 0 THEN 'EMPTY_ESTIMATE' END,
           CASE WHEN p.material_lines_missing > 0 THEN 'MATERIAL_PRICE_OR_WASTE_MISSING' END,
           CASE WHEN p.labor_lines_missing > 0 THEN 'LABOR_RATE_MISSING' END,
           CASE WHEN p.machine_lines_missing > 0 THEN 'MACHINE_RATE_MISSING' END,
           CASE WHEN p.overhead_lines_missing > 0 THEN 'OVERHEAD_POOL_MISSING' END,
           CASE WHEN p.selling_admin_pct IS NULL THEN 'SELLING_ADMIN_POOL_MISSING' END
       ], NULL) AS missing,
       array_remove(ARRAY[
           CASE WHEN p.net_price < p.fully_loaded_cost THEN 'BELOW_FULLY_LOADED' END,
           CASE WHEN p.net_price < p.recommended_price THEN 'BELOW_TARGET' END,
           CASE WHEN p.net_price > 0 AND p.min_margin_pct IS NOT NULL
                 AND (p.net_price - p.fully_loaded_cost) / p.net_price * 100 < p.min_margin_pct THEN 'BELOW_MIN_MARGIN' END
       ], NULL) AS warnings
  FROM priced p;

-- Frozen at approval: never changes afterwards, whatever happens to the rates.
CREATE TABLE IF NOT EXISTS cost_estimate_snapshots (
    id                    bigserial PRIMARY KEY,
    estimate_id           bigint NOT NULL UNIQUE REFERENCES cost_estimates(id),
    quotation_id          bigint NOT NULL REFERENCES quotations(id),
    line_no               int NOT NULL,
    frozen_at             timestamptz NOT NULL DEFAULT now(),
    quantity              numeric(12,3) NOT NULL,
    materials_cost        numeric(16,2),
    labor_cost            numeric(16,2),
    machine_cost          numeric(16,2),
    direct_other_cost     numeric(16,2),
    overhead_cost         numeric(16,2),
    direct_cost           numeric(16,2),
    manufacturing_cost    numeric(16,2),
    selling_admin_pct     numeric(14,4),
    fully_loaded_cost     numeric(16,2),
    pricing_method        text NOT NULL,
    target_pct            numeric(6,2) NOT NULL,
    recommended_price     numeric(16,2),
    net_price             numeric(16,2) NOT NULL,
    gross_profit          numeric(16,2),
    gross_margin_pct      numeric(8,2),
    labor_hours           numeric(12,3) NOT NULL,
    machine_hours         numeric(12,3) NOT NULL,
    missing               text[] NOT NULL,
    warnings              text[] NOT NULL,
    detail                jsonb NOT NULL
);

CREATE OR REPLACE FUNCTION fn_snapshot_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'RROKA_SNAPSHOT_IMMUTABLE: the standard cost frozen at approval cannot change' USING ERRCODE = 'P0001';
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_snapshot_immutable ON cost_estimate_snapshots;
CREATE TRIGGER trg_snapshot_immutable BEFORE UPDATE OR DELETE ON cost_estimate_snapshots FOR EACH ROW EXECUTE FUNCTION fn_snapshot_immutable();

-- Approval: a quotation that requires costing needs a complete estimate on every
-- line; the estimates are then frozen with every rate they used.
CREATE OR REPLACE FUNCTION fn_quotation_costing_gate() RETURNS trigger AS $$
BEGIN
    IF NEW.status = 'APPROVED' AND OLD.status <> 'APPROVED' THEN
        IF NEW.requires_costing AND EXISTS (
            SELECT 1 FROM quotation_lines l
              LEFT JOIN cost_estimates e ON e.quotation_id = l.quotation_id AND e.line_no = l.line_no
              LEFT JOIN v_estimate_costs c ON c.estimate_id = e.id
             WHERE l.quotation_id = NEW.id AND (e.id IS NULL OR cardinality(c.missing) > 0)) THEN
            RAISE EXCEPTION 'RROKA_QUOTATION_COSTING_INCOMPLETE: every line needs a complete cost estimate' USING ERRCODE = 'P0001';
        END IF;
        INSERT INTO cost_estimate_snapshots (estimate_id, quotation_id, line_no, quantity, materials_cost, labor_cost, machine_cost,
               direct_other_cost, overhead_cost, direct_cost, manufacturing_cost, selling_admin_pct, fully_loaded_cost, pricing_method,
               target_pct, recommended_price, net_price, gross_profit, gross_margin_pct, labor_hours, machine_hours, missing, warnings, detail)
        SELECT c.estimate_id, c.quotation_id, c.line_no, c.quantity, c.materials_cost, c.labor_cost, c.machine_cost,
               c.direct_other_cost, c.overhead_cost, c.direct_cost, c.manufacturing_cost, c.selling_admin_pct, c.fully_loaded_cost, c.pricing_method,
               c.target_pct, c.recommended_price, c.net_price, c.gross_profit, c.gross_margin_pct, c.labor_hours, c.machine_hours, c.missing, c.warnings,
               jsonb_build_object(
                   'materials', COALESCE((SELECT jsonb_agg(to_jsonb(m) ORDER BY m.id) FROM v_estimate_material_lines m WHERE m.estimate_id = c.estimate_id), '[]'),
                   'operations', COALESCE((SELECT jsonb_agg(to_jsonb(o) ORDER BY o.seq, o.id) FROM v_estimate_operation_lines o WHERE o.estimate_id = c.estimate_id), '[]'),
                   'direct_costs', COALESCE((SELECT jsonb_agg(to_jsonb(d) ORDER BY d.id) FROM cost_estimate_direct_costs d WHERE d.estimate_id = c.estimate_id), '[]'),
                   'factory_pool', jsonb_build_object('id', c.factory_pool_id, 'version', c.factory_pool_version, 'rate', c.factory_pool_rate),
                   'selling_admin_pool', jsonb_build_object('id', c.sa_pool_id, 'version', c.sa_pool_version, 'pct', c.selling_admin_pct),
                   'quotation_date', (SELECT issue_date FROM quotations WHERE id = c.quotation_id))
          FROM v_estimate_costs c
          JOIN quotation_lines l ON l.quotation_id = c.quotation_id AND l.line_no = c.line_no
         WHERE c.quotation_id = NEW.id;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_quotation_costing_gate ON quotations;
CREATE TRIGGER trg_quotation_costing_gate AFTER UPDATE OF status ON quotations FOR EACH ROW EXECUTE FUNCTION fn_quotation_costing_gate();

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['vat_rates', 'pricing_policies', 'cost_estimates', 'cost_estimate_materials', 'cost_estimate_operations',
                             'cost_estimate_direct_costs', 'cost_estimate_snapshots'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
    END LOOP;
    FOREACH t IN ARRAY ARRAY['vat_rates', 'pricing_policies', 'cost_estimates'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

-- ---------------------------------------------------------------------
-- Accounting, phase 1 (2026-10-06): chart of accounts, fiscal periods and
-- the journal. Governing rule 1 was formally changed: the books are kept
-- here; Daftra only stamps the tax invoice. Controls enforced here:
-- a posted entry is final (corrections by a mirror reversal only), every
-- entry balances, numbering is gap-free per year and given at posting,
-- nothing posts before the books start or into a closed period, and only
-- leaf (postable) active accounts take lines.
-- ---------------------------------------------------------------------

-- One row: the date the books start (owner decision 2026-10-06: 2026-01-01).
CREATE TABLE IF NOT EXISTS accounting_settings (
    id           smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    books_start  date,
    updated_at   timestamptz NOT NULL DEFAULT now()
);
INSERT INTO accounting_settings (id, books_start) VALUES (1, DATE '2026-01-01') ON CONFLICT (id) DO NOTHING;

CREATE TABLE IF NOT EXISTS accounts (
    id           bigserial PRIMARY KEY,
    code         text NOT NULL UNIQUE CHECK (code ~ '^[0-9]{1,12}$'),
    name         text NOT NULL CHECK (btrim(name) <> ''),
    name_en      text,
    account_type text NOT NULL CHECK (account_type IN ('ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE')),
    parent_id    bigint REFERENCES accounts(id),
    is_postable  boolean NOT NULL DEFAULT true,
    -- Accounts the system posts to automatically (one account per role).
    system_role  text UNIQUE CHECK (system_role IN ('CASH', 'BANK', 'CUSTODY', 'RECEIVABLE', 'INVENTORY', 'WIP', 'INPUT_VAT',
                                                    'PAYABLE', 'OUTPUT_VAT', 'CUSTOMER_ADVANCES', 'CAPITAL', 'OWNER_CURRENT',
                                                    'RETAINED_EARNINGS', 'SALES', 'SALES_DISCOUNT', 'COST_OF_SALES',
                                                    'FIXED_ASSETS', 'ACCUMULATED_DEPRECIATION', 'DEPRECIATION')),
    is_active    boolean NOT NULL DEFAULT true,
    notes        text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CHECK (parent_id IS DISTINCT FROM id)
);
CREATE INDEX IF NOT EXISTS ix_accounts_parent ON accounts (parent_id);

CREATE TABLE IF NOT EXISTS fiscal_periods (
    id            bigserial PRIMARY KEY,
    period_start  date NOT NULL UNIQUE CHECK (extract(day FROM period_start) = 1),
    period_end    date GENERATED ALWAYS AS ((period_start + interval '1 month' - interval '1 day')::date) STORED,
    status        text NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN', 'CLOSED')),
    closed_by     bigint REFERENCES users(id),
    closed_at     timestamptz,
    reopen_reason text,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'CLOSED' OR (closed_by IS NOT NULL AND closed_at IS NOT NULL))
);

-- Gap-free numbering: the counter row is locked by the posting transaction,
-- so a rolled-back posting never consumes a number.
CREATE TABLE IF NOT EXISTS journal_sequences (
    fiscal_year  integer PRIMARY KEY,
    last_no      integer NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS journal_entries (
    id           bigserial PRIMARY KEY,
    entry_no     text UNIQUE,
    fiscal_year  integer,
    seq_no       integer,
    entry_date   date NOT NULL,
    description  text NOT NULL CHECK (btrim(description) <> ''),
    source_type  text NOT NULL DEFAULT 'MANUAL' CHECK (source_type IN ('MANUAL', 'OPENING', 'REVERSAL')),
    source_id    bigint,
    reference    text,
    status       text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'POSTED')),
    reverses_id  bigint UNIQUE REFERENCES journal_entries(id),
    created_by   bigint NOT NULL REFERENCES users(id),
    posted_by    bigint REFERENCES users(id),
    posted_at    timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (fiscal_year, seq_no),
    CHECK ((source_type = 'REVERSAL') = (reverses_id IS NOT NULL)),
    CHECK (status <> 'POSTED' OR (entry_no IS NOT NULL AND posted_by IS NOT NULL AND posted_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_journal_entries_date ON journal_entries (entry_date);
CREATE INDEX IF NOT EXISTS ix_journal_entries_source ON journal_entries (source_type, source_id);

CREATE TABLE IF NOT EXISTS journal_lines (
    id             bigserial PRIMARY KEY,
    entry_id       bigint NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
    account_id     bigint NOT NULL REFERENCES accounts(id),
    debit          numeric(14,2) NOT NULL DEFAULT 0 CHECK (debit >= 0),
    credit         numeric(14,2) NOT NULL DEFAULT 0 CHECK (credit >= 0),
    description    text,
    project_id     bigint REFERENCES projects(id),
    cost_center_id bigint REFERENCES cost_centers(id),
    partner_type   text CHECK (partner_type IN ('CLIENT', 'SUPPLIER', 'EMPLOYEE')),
    partner_id     bigint,
    CHECK ((debit > 0) <> (credit > 0)),
    CHECK ((partner_type IS NULL) = (partner_id IS NULL))
);
CREATE INDEX IF NOT EXISTS ix_journal_lines_entry ON journal_lines (entry_id);
CREATE INDEX IF NOT EXISTS ix_journal_lines_account ON journal_lines (account_id);
CREATE INDEX IF NOT EXISTS ix_journal_lines_project ON journal_lines (project_id) WHERE project_id IS NOT NULL;

-- Chart rules: a parent is a non-postable account of the same type; no
-- cycles; an account with lines keeps its type and stays postable; a
-- postable account cannot have children.
CREATE OR REPLACE FUNCTION fn_account_guard() RETURNS trigger AS $$
DECLARE
    v_parent accounts%ROWTYPE;
    v_used boolean;
BEGIN
    IF NEW.parent_id IS NOT NULL THEN
        SELECT * INTO v_parent FROM accounts WHERE id = NEW.parent_id;
        IF v_parent.is_postable THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_PARENT: parent % is postable; only a group account can have children', v_parent.code USING ERRCODE = 'P0001';
        END IF;
        IF v_parent.account_type <> NEW.account_type THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_PARENT: parent % is %, child is %', v_parent.code, v_parent.account_type, NEW.account_type USING ERRCODE = 'P0001';
        END IF;
        IF TG_OP = 'UPDATE' AND EXISTS (
            WITH RECURSIVE up AS (SELECT id, parent_id FROM accounts WHERE id = NEW.parent_id
                                  UNION ALL SELECT a.id, a.parent_id FROM accounts a JOIN up ON a.id = up.parent_id)
            SELECT 1 FROM up WHERE id = NEW.id) THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_PARENT: account % cannot be under its own descendant', NEW.code USING ERRCODE = 'P0001';
        END IF;
    END IF;
    IF NEW.system_role IS NOT NULL AND NOT NEW.is_postable THEN
        RAISE EXCEPTION 'RROKA_ACCOUNT_ROLE: a system role needs a postable account' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF NEW.is_postable AND NOT OLD.is_postable AND EXISTS (SELECT 1 FROM accounts WHERE parent_id = NEW.id) THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_PARENT: account % has children and cannot be postable', NEW.code USING ERRCODE = 'P0001';
        END IF;
        IF NEW.account_type <> OLD.account_type AND EXISTS (SELECT 1 FROM accounts WHERE parent_id = NEW.id) THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_PARENT: account % has children; its type cannot change', NEW.code USING ERRCODE = 'P0001';
        END IF;
        v_used := EXISTS (SELECT 1 FROM journal_lines WHERE account_id = NEW.id);
        IF v_used AND (NEW.account_type <> OLD.account_type OR NOT NEW.is_postable OR NEW.code <> OLD.code) THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_USED: account % has entries; its code, type and postability are fixed', OLD.code USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_account_guard ON accounts;
CREATE TRIGGER trg_account_guard BEFORE INSERT OR UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION fn_account_guard();

-- Lines change only while their entry is a draft, and only on active leaf accounts.
CREATE OR REPLACE FUNCTION fn_journal_line_guard() RETURNS trigger AS $$
DECLARE
    v_status text;
    v_acc accounts%ROWTYPE;
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        SELECT status INTO v_status FROM journal_entries WHERE id = OLD.entry_id;
        -- A deleted draft cascades to its lines: the entry row is already gone (status NULL).
        IF v_status = 'POSTED' THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_LOCKED: entry is posted; correct it with a reversal' USING ERRCODE = 'P0001';
        END IF;
        IF TG_OP = 'DELETE' THEN
            RETURN OLD;
        END IF;
    END IF;
    SELECT status INTO v_status FROM journal_entries WHERE id = NEW.entry_id;
    IF v_status = 'POSTED' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_LOCKED: entry is posted; correct it with a reversal' USING ERRCODE = 'P0001';
    END IF;
    SELECT * INTO v_acc FROM accounts WHERE id = NEW.account_id;
    IF NOT v_acc.is_postable OR NOT v_acc.is_active THEN
        RAISE EXCEPTION 'RROKA_ACCOUNT_NOT_POSTABLE: account % is a group or inactive account', v_acc.code USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_journal_line_guard ON journal_lines;
CREATE TRIGGER trg_journal_line_guard BEFORE INSERT OR UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION fn_journal_line_guard();

-- Entries: a draft can change or be deleted; posting checks the books and
-- gives the number; a posted entry never changes. A reversal must mirror
-- its original exactly (account, project, cost centre, partner).
CREATE OR REPLACE FUNCTION fn_journal_entry_guard() RETURNS trigger AS $$
DECLARE
    v_start date;
    v_dr numeric;
    v_cr numeric;
    v_n integer;
    v_period text;
    v_orig journal_entries%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status = 'POSTED' THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_LOCKED: entry % is posted; correct it with a reversal', OLD.entry_no USING ERRCODE = 'P0001';
        END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.status = 'POSTED' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_LOCKED: entry % is posted; correct it with a reversal', OLD.entry_no USING ERRCODE = 'P0001';
    END IF;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO v_orig FROM journal_entries WHERE id = NEW.reverses_id;
        IF v_orig.status <> 'POSTED' THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_REVERSAL: only a posted entry can be reversed' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.entry_date < v_orig.entry_date THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_REVERSAL: a reversal cannot be dated before the entry it reverses' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    IF NEW.status = 'POSTED' THEN
        IF TG_OP = 'INSERT' THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_UNBALANCED: an entry is created as a draft and posted after its lines' USING ERRCODE = 'P0001';
        END IF;
        SELECT books_start INTO v_start FROM accounting_settings WHERE id = 1;
        IF v_start IS NULL THEN
            RAISE EXCEPTION 'RROKA_BOOKS_NOT_STARTED: the books start date is not set' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.entry_date < v_start THEN
            RAISE EXCEPTION 'RROKA_BOOKS_NOT_STARTED: % is before the books start (%)', NEW.entry_date, v_start USING ERRCODE = 'P0001';
        END IF;
        SELECT status INTO v_period FROM fiscal_periods WHERE period_start = date_trunc('month', NEW.entry_date)::date;
        IF v_period = 'CLOSED' THEN
            RAISE EXCEPTION 'RROKA_PERIOD_CLOSED: the period of % is closed', NEW.entry_date USING ERRCODE = 'P0001';
        END IF;
        SELECT COALESCE(sum(debit), 0), COALESCE(sum(credit), 0), count(*) INTO v_dr, v_cr, v_n FROM journal_lines WHERE entry_id = NEW.id;
        IF v_n < 2 OR v_dr <> v_cr OR v_dr = 0 THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_UNBALANCED: debit % / credit % over % lines', v_dr, v_cr, v_n USING ERRCODE = 'P0001';
        END IF;
        IF NEW.reverses_id IS NOT NULL AND EXISTS (
            (SELECT account_id, project_id, cost_center_id, partner_type, partner_id, sum(debit - credit) AS net
               FROM journal_lines WHERE entry_id = NEW.id GROUP BY 1, 2, 3, 4, 5
             EXCEPT
             SELECT account_id, project_id, cost_center_id, partner_type, partner_id, sum(credit - debit)
               FROM journal_lines WHERE entry_id = NEW.reverses_id GROUP BY 1, 2, 3, 4, 5)
            UNION ALL
            (SELECT account_id, project_id, cost_center_id, partner_type, partner_id, sum(credit - debit)
               FROM journal_lines WHERE entry_id = NEW.reverses_id GROUP BY 1, 2, 3, 4, 5
             EXCEPT
             SELECT account_id, project_id, cost_center_id, partner_type, partner_id, sum(debit - credit)
               FROM journal_lines WHERE entry_id = NEW.id GROUP BY 1, 2, 3, 4, 5)) THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_REVERSAL: a reversal must mirror the original entry exactly' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.posted_by IS NULL THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_UNBALANCED: posting needs the posting user' USING ERRCODE = 'P0001';
        END IF;
        INSERT INTO fiscal_periods (period_start) VALUES (date_trunc('month', NEW.entry_date)::date) ON CONFLICT (period_start) DO NOTHING;
        NEW.fiscal_year := extract(year FROM NEW.entry_date)::integer;
        INSERT INTO journal_sequences (fiscal_year, last_no) VALUES (NEW.fiscal_year, 1)
            ON CONFLICT (fiscal_year) DO UPDATE SET last_no = journal_sequences.last_no + 1
            RETURNING last_no INTO NEW.seq_no;
        NEW.entry_no := format('JV-%s-%s', NEW.fiscal_year, lpad(NEW.seq_no::text, 5, '0'));
        NEW.posted_at := now();
    ELSE
        NEW.entry_no := NULL;
        NEW.fiscal_year := NULL;
        NEW.seq_no := NULL;
        NEW.posted_by := NULL;
        NEW.posted_at := NULL;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_journal_entry_guard ON journal_entries;
CREATE TRIGGER trg_journal_entry_guard BEFORE INSERT OR UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION fn_journal_entry_guard();

-- Periods: closing needs no draft dated in the period and every earlier
-- period closed; reopening needs a written reason and no later closed period.
CREATE OR REPLACE FUNCTION fn_fiscal_period_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'RROKA_PERIOD_CLOSED: a fiscal period cannot be deleted' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.period_start <> OLD.period_start THEN
        RAISE EXCEPTION 'RROKA_PERIOD_CLOSED: a period keeps its dates' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'CLOSED' AND OLD.status = 'OPEN' THEN
        -- period_end is a generated column: not yet computed inside a BEFORE trigger.
        IF EXISTS (SELECT 1 FROM journal_entries WHERE status = 'DRAFT'
                      AND entry_date >= NEW.period_start AND entry_date < NEW.period_start + interval '1 month') THEN
            RAISE EXCEPTION 'RROKA_PERIOD_DRAFTS: post or delete the drafts dated in the period before closing it' USING ERRCODE = 'P0001';
        END IF;
        IF EXISTS (SELECT 1 FROM fiscal_periods WHERE period_start < NEW.period_start AND status = 'OPEN') THEN
            RAISE EXCEPTION 'RROKA_PERIOD_ORDER: close the earlier periods first' USING ERRCODE = 'P0001';
        END IF;
        NEW.reopen_reason := NULL;
    ELSIF NEW.status = 'OPEN' AND OLD.status = 'CLOSED' THEN
        IF NEW.reopen_reason IS NULL OR btrim(NEW.reopen_reason) = '' THEN
            RAISE EXCEPTION 'RROKA_PERIOD_REOPEN: reopening a closed period needs a written reason' USING ERRCODE = 'P0001';
        END IF;
        IF EXISTS (SELECT 1 FROM fiscal_periods WHERE period_start > NEW.period_start AND status = 'CLOSED') THEN
            RAISE EXCEPTION 'RROKA_PERIOD_ORDER: reopen the later closed periods first' USING ERRCODE = 'P0001';
        END IF;
        NEW.closed_by := NULL;
        NEW.closed_at := NULL;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_fiscal_period_guard ON fiscal_periods;
CREATE TRIGGER trg_fiscal_period_guard BEFORE UPDATE OR DELETE ON fiscal_periods FOR EACH ROW EXECUTE FUNCTION fn_fiscal_period_guard();

-- A new period may not be created inside a closed range (it would let a
-- posting slip between closed months).
CREATE OR REPLACE FUNCTION fn_fiscal_period_insert() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM fiscal_periods WHERE period_start > NEW.period_start AND status = 'CLOSED') THEN
        RAISE EXCEPTION 'RROKA_PERIOD_CLOSED: % falls before a closed period', NEW.period_start USING ERRCODE = 'P0001';
    END IF;
    NEW.status := 'OPEN';
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_fiscal_period_insert ON fiscal_periods;
CREATE TRIGGER trg_fiscal_period_insert BEFORE INSERT ON fiscal_periods FOR EACH ROW EXECUTE FUNCTION fn_fiscal_period_insert();

-- Posted lines with their entry and account: the single source of every
-- ledger, trial balance and statement.
CREATE OR REPLACE VIEW v_ledger_lines AS
SELECT l.id AS line_id, e.id AS entry_id, e.entry_no, e.entry_date, e.fiscal_year, e.description AS entry_description,
       e.source_type, e.source_id, e.reference, e.reverses_id, e.posted_by, e.created_by,
       a.id AS account_id, a.code AS account_code, a.name AS account_name, a.account_type,
       l.debit, l.credit, l.debit - l.credit AS net, l.description, l.project_id, l.cost_center_id, l.partner_type, l.partner_id
  FROM journal_lines l
  JOIN journal_entries e ON e.id = l.entry_id AND e.status = 'POSTED'
  JOIN accounts a ON a.id = l.account_id;

INSERT INTO permissions (code, description) VALUES
    ('accounting.view', 'عرض الحسابات والقيود والتقارير المالية'),
    ('accounting.manage', 'إدارة دليل الحسابات وإعداد مسودات القيود'),
    ('accounting.post', 'ترحيل القيود وعكسها'),
    ('accounting.close', 'إقفال الفترات المالية وإعادة فتحها')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND p.code IN ('accounting.view', 'accounting.manage', 'accounting.post', 'accounting.close')
ON CONFLICT DO NOTHING;

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['accounting_settings', 'accounts', 'fiscal_periods', 'journal_entries', 'journal_lines'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
    END LOOP;
    FOREACH t IN ARRAY ARRAY['accounting_settings', 'accounts', 'fiscal_periods', 'journal_entries'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

-- ---------------------------------------------------------------------
-- Accounting (2026-10-07): Odoo-style chart. Every postable account has a
-- detailed type (Receivable, Bank and Cash, Current Assets, Cost of
-- Revenue, ...) that decides its class and its place in the statements;
-- groups (non-postable) carry none. Receivable/payable accounts always
-- allow reconciliation; only one current-year-earnings account.
-- ---------------------------------------------------------------------

ALTER TABLE accounts ADD COLUMN IF NOT EXISTS detail_type text;
ALTER TABLE accounts ADD COLUMN IF NOT EXISTS reconcile boolean NOT NULL DEFAULT false;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'accounts_detail_type_check') THEN
        ALTER TABLE accounts ADD CONSTRAINT accounts_detail_type_check CHECK (detail_type IN (
            'RECEIVABLE', 'BANK_CASH', 'CURRENT_ASSETS', 'NON_CURRENT_ASSETS', 'PREPAYMENTS', 'FIXED_ASSETS',
            'PAYABLE', 'CREDIT_CARD', 'CURRENT_LIABILITIES', 'NON_CURRENT_LIABILITIES',
            'EQUITY', 'CURRENT_YEAR_EARNINGS', 'INCOME', 'OTHER_INCOME', 'EXPENSES', 'DEPRECIATION', 'COST_OF_REVENUE'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'accounts_group_no_detail_type') THEN
        ALTER TABLE accounts ADD CONSTRAINT accounts_group_no_detail_type CHECK (is_postable OR detail_type IS NULL);
    END IF;
END $$;
CREATE UNIQUE INDEX IF NOT EXISTS ux_accounts_current_year_earnings ON accounts ((true)) WHERE detail_type = 'CURRENT_YEAR_EARNINGS';

CREATE OR REPLACE FUNCTION fn_account_class(p_detail text) RETURNS text AS $$
    SELECT CASE
        WHEN p_detail IN ('RECEIVABLE', 'BANK_CASH', 'CURRENT_ASSETS', 'NON_CURRENT_ASSETS', 'PREPAYMENTS', 'FIXED_ASSETS') THEN 'ASSET'
        WHEN p_detail IN ('PAYABLE', 'CREDIT_CARD', 'CURRENT_LIABILITIES', 'NON_CURRENT_LIABILITIES') THEN 'LIABILITY'
        WHEN p_detail IN ('EQUITY', 'CURRENT_YEAR_EARNINGS') THEN 'EQUITY'
        WHEN p_detail IN ('INCOME', 'OTHER_INCOME') THEN 'REVENUE'
        WHEN p_detail IN ('EXPENSES', 'DEPRECIATION', 'COST_OF_REVENUE') THEN 'EXPENSE'
    END
$$ LANGUAGE sql IMMUTABLE;

-- Runs before fn_account_guard (trigger names sort alphabetically), so the
-- class it derives is the one the parent check sees.
CREATE OR REPLACE FUNCTION fn_account_detail() RETURNS trigger AS $$
BEGIN
    IF NEW.detail_type IS NOT NULL THEN
        NEW.account_type := fn_account_class(NEW.detail_type);
        IF NEW.detail_type IN ('RECEIVABLE', 'PAYABLE') THEN
            NEW.reconcile := true;
        END IF;
    ELSIF NEW.is_postable AND (TG_OP = 'INSERT' OR OLD.detail_type IS NOT NULL) THEN
        RAISE EXCEPTION 'RROKA_ACCOUNT_DETAIL_TYPE: a postable account needs its detailed type' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_account_detail ON accounts;
CREATE TRIGGER trg_account_detail BEFORE INSERT OR UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION fn_account_detail();

-- Accounts of the proposed chart get their detailed type (matched on code and name, so an
-- account the owner renamed or re-purposed is left for review rather than guessed).
UPDATE accounts a SET detail_type = m.detail
  FROM (VALUES ('1101', 'النقدية في الصندوق', 'BANK_CASH'), ('1103', 'عهد الموظفين', 'CURRENT_ASSETS'),
               ('1110', 'العملاء (الذمم المدينة)', 'RECEIVABLE'), ('1120', 'مخزون الخامات والمستلزمات', 'CURRENT_ASSETS'),
               ('1130', 'أعمال تحت التنفيذ (تكلفة المشاريع الجارية)', 'CURRENT_ASSETS'), ('1140', 'ضريبة القيمة المضافة — المدخلات', 'CURRENT_ASSETS'),
               ('1150', 'مصروفات مدفوعة مقدمًا', 'PREPAYMENTS'), ('1160', 'سلف الموظفين', 'CURRENT_ASSETS'),
               ('1201', 'المكائن والمعدات', 'FIXED_ASSETS'), ('1202', 'الأثاث والتجهيزات المكتبية', 'FIXED_ASSETS'),
               ('1203', 'السيارات', 'FIXED_ASSETS'), ('1209', 'مجمع إهلاك الأصول الثابتة', 'FIXED_ASSETS'),
               ('2101', 'الموردون (الذمم الدائنة)', 'PAYABLE'), ('2110', 'ضريبة القيمة المضافة — المخرجات', 'CURRENT_LIABILITIES'),
               ('2111', 'ضريبة القيمة المضافة — صافي المستحق للهيئة', 'CURRENT_LIABILITIES'), ('2120', 'دفعات مقدمة من العملاء', 'CURRENT_LIABILITIES'),
               ('2130', 'رواتب مستحقة', 'CURRENT_LIABILITIES'), ('2140', 'مصروفات مستحقة', 'CURRENT_LIABILITIES'),
               ('2150', 'مخصص الزكاة', 'CURRENT_LIABILITIES'), ('2201', 'مخصص مكافأة نهاية الخدمة', 'NON_CURRENT_LIABILITIES'),
               ('3101', 'رأس المال', 'EQUITY'), ('3201', 'جاري المالك', 'EQUITY'), ('3301', 'الأرباح المبقاة', 'EQUITY'),
               ('4101', 'إيرادات المشاريع (تصنيع وتوريد وتركيب)', 'INCOME'), ('4190', 'خصومات المبيعات', 'INCOME'),
               ('4201', 'إيرادات أخرى', 'OTHER_INCOME'), ('5101', 'تكلفة المشاريع المسلّمة', 'COST_OF_REVENUE'),
               ('5201', 'أجور غير مباشرة', 'EXPENSES'), ('5202', 'إيجار الورشة', 'EXPENSES'), ('5203', 'كهرباء ومياه', 'EXPENSES'),
               ('5204', 'صيانة المكائن والمعدات', 'EXPENSES'), ('5205', 'أدوات ومستهلكات الورشة', 'EXPENSES'),
               ('5206', 'إهلاك المكائن والمعدات', 'DEPRECIATION'), ('5301', 'رواتب إدارية', 'EXPENSES'),
               ('5302', 'رسوم حكومية وتأشيرات وإقامات', 'EXPENSES'), ('5303', 'تأمينات اجتماعية وطبي', 'EXPENSES'),
               ('5304', 'اتصالات وإنترنت وبرامج', 'EXPENSES'), ('5305', 'أتعاب مهنية', 'EXPENSES'), ('5306', 'رسوم بنكية', 'EXPENSES'),
               ('5307', 'ضيافة ونثريات مكتبية', 'EXPENSES'), ('5308', 'مصاريف التأسيس', 'EXPENSES'), ('5309', 'وقود ونقل', 'EXPENSES'),
               ('5390', 'مصروفات أخرى', 'EXPENSES'), ('5401', 'تسويق وإعلان', 'EXPENSES'), ('5901', 'مصروف الزكاة', 'EXPENSES'))
       AS m(code, name, detail)
 WHERE a.code = m.code AND a.name = m.name AND a.detail_type IS NULL AND a.is_postable
   AND fn_account_class(m.detail) = a.account_type;

-- ---------------------------------------------------------------------
-- Accounting (2026-10-08): automatic posting from the operational documents
-- (plan step أ). Each approved document posts its own journal entry in the
-- same transaction as the approval, so a document and its entry exist
-- together or not at all:
--   expense         Dr expense account (or WIP for a project) + input VAT / Cr its payment account
--   purchase        Dr inventory + input VAT / Cr payables (supplier)
--   transfer        Dr receiving payment account / Cr sending payment account
--   stock issue     Dr WIP (project) / Cr inventory — return is the mirror
--   stock count     inventory against the stock-adjustment account
-- Auto-posting is switched on by the accountant once every mapping is set;
-- before that, approved documents wait in v_posting_backlog to be posted
-- (or excluded with a written reason, e.g. already in the opening entry).
-- Documents dated before the books start belong to the opening balances.
-- An automatic entry is never edited or reversed by hand: it is created
-- only by fn_journal_auto_insert.
-- ---------------------------------------------------------------------

ALTER TABLE accounts DROP CONSTRAINT IF EXISTS accounts_system_role_check;
ALTER TABLE accounts ADD CONSTRAINT accounts_system_role_check CHECK (system_role IN (
    'CASH', 'BANK', 'CUSTODY', 'RECEIVABLE', 'INVENTORY', 'WIP', 'INPUT_VAT', 'PAYABLE', 'OUTPUT_VAT',
    'CUSTOMER_ADVANCES', 'CAPITAL', 'OWNER_CURRENT', 'RETAINED_EARNINGS', 'SALES', 'SALES_DISCOUNT',
    'COST_OF_SALES', 'FIXED_ASSETS', 'ACCUMULATED_DEPRECIATION', 'DEPRECIATION', 'INVENTORY_ADJUSTMENT'));

ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_source_type_check;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_source_type_check CHECK (source_type IN (
    'MANUAL', 'OPENING', 'REVERSAL', 'EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK'));
ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_document_source;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_document_source CHECK (
    source_type NOT IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK') OR source_id IS NOT NULL);
-- One entry per document, ever.
CREATE UNIQUE INDEX IF NOT EXISTS ux_journal_entries_document ON journal_entries (source_type, source_id)
    WHERE source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK');

ALTER TABLE accounting_settings ADD COLUMN IF NOT EXISTS auto_posting boolean NOT NULL DEFAULT false;
ALTER TABLE accounting_settings ADD COLUMN IF NOT EXISTS auto_posting_since timestamptz;
ALTER TABLE payment_accounts ADD COLUMN IF NOT EXISTS account_id bigint REFERENCES accounts(id);
ALTER TABLE expense_categories ADD COLUMN IF NOT EXISTS account_id bigint REFERENCES accounts(id);

-- A document deliberately left out of the books, with the reason (e.g. it is
-- already inside the opening entry). Removing the row puts it back in the backlog.
CREATE TABLE IF NOT EXISTS posting_exclusions (
    id           bigserial PRIMARY KEY,
    source_type  text NOT NULL CHECK (source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK')),
    source_id    bigint NOT NULL,
    reason       text NOT NULL CHECK (btrim(reason) <> ''),
    created_by   bigint NOT NULL REFERENCES users(id),
    created_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source_type, source_id)
);

CREATE OR REPLACE FUNCTION fn_posting_exclusion_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'RROKA_POSTING_EXCLUDED: an exclusion is removed and re-entered, not edited' USING ERRCODE = 'P0001';
    END IF;
    IF EXISTS (SELECT 1 FROM journal_entries WHERE source_type = NEW.source_type AND source_id = NEW.source_id) THEN
        RAISE EXCEPTION 'RROKA_POSTING_DUPLICATE: the document already has its entry' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_posting_exclusion_guard ON posting_exclusions;
CREATE TRIGGER trg_posting_exclusion_guard BEFORE INSERT OR UPDATE ON posting_exclusions
    FOR EACH ROW EXECUTE FUNCTION fn_posting_exclusion_guard();

-- The account a system role points to; a missing role refuses the posting.
CREATE OR REPLACE FUNCTION fn_role_account(p_role text) RETURNS bigint AS $$
DECLARE
    v bigint;
BEGIN
    SELECT id INTO v FROM accounts WHERE system_role = p_role AND is_active AND is_postable;
    IF v IS NULL THEN
        RAISE EXCEPTION 'RROKA_POSTING_MAPPING: no active account has the role %', p_role USING ERRCODE = 'P0001';
    END IF;
    RETURN v;
END $$ LANGUAGE plpgsql STABLE;

-- What still stops auto-posting from being switched on.
CREATE OR REPLACE FUNCTION fn_posting_gaps() RETURNS TABLE (gap_type text, ref_id bigint, label text) AS $$
    SELECT 'ROLE'::text, NULL::bigint, r
      FROM unnest(ARRAY['INVENTORY', 'WIP', 'INPUT_VAT', 'PAYABLE', 'INVENTORY_ADJUSTMENT']) AS r
     WHERE NOT EXISTS (SELECT 1 FROM accounts a WHERE a.system_role = r AND a.is_active AND a.is_postable)
    UNION ALL
    SELECT 'PAYMENT_ACCOUNT', id, name FROM payment_accounts WHERE is_active AND account_id IS NULL
       AND id NOT IN (SELECT row_id FROM demo_records WHERE table_name = 'payment_accounts')
    UNION ALL
    SELECT 'EXPENSE_CATEGORY', id, name FROM expense_categories WHERE is_active AND account_id IS NULL
       AND id NOT IN (SELECT row_id FROM demo_records WHERE table_name = 'expense_categories')
$$ LANGUAGE sql STABLE;

-- A payment account maps to an asset (cash, bank, custody) or liability (credit card)
-- account; an expense category to an expense or asset (prepaid) account. A mapping is
-- not removed while auto-posting is on.
CREATE OR REPLACE FUNCTION fn_posting_map_guard() RETURNS trigger AS $$
DECLARE
    v accounts%ROWTYPE;
BEGIN
    IF NEW.account_id IS NULL THEN
        IF TG_OP = 'UPDATE' AND OLD.account_id IS NOT NULL AND NEW.is_active
           AND (SELECT auto_posting FROM accounting_settings WHERE id = 1) THEN
            RAISE EXCEPTION 'RROKA_POSTING_MAPPING: % stays mapped while auto-posting is on', NEW.name USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.account_id IS NOT DISTINCT FROM OLD.account_id THEN
        RETURN NEW;
    END IF;
    SELECT * INTO v FROM accounts WHERE id = NEW.account_id;
    IF NOT v.is_postable OR NOT v.is_active THEN
        RAISE EXCEPTION 'RROKA_ACCOUNT_NOT_POSTABLE: account % is a group or inactive account', v.code USING ERRCODE = 'P0001';
    END IF;
    IF (TG_TABLE_NAME = 'payment_accounts' AND v.account_type NOT IN ('ASSET', 'LIABILITY'))
       OR (TG_TABLE_NAME = 'expense_categories' AND v.account_type NOT IN ('EXPENSE', 'ASSET')) THEN
        RAISE EXCEPTION 'RROKA_POSTING_MAP_TYPE: account % (%) does not fit %', v.code, v.account_type, TG_TABLE_NAME USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_payment_account_map ON payment_accounts;
CREATE TRIGGER trg_payment_account_map BEFORE INSERT OR UPDATE ON payment_accounts
    FOR EACH ROW EXECUTE FUNCTION fn_posting_map_guard();
DROP TRIGGER IF EXISTS trg_expense_category_map ON expense_categories;
CREATE TRIGGER trg_expense_category_map BEFORE INSERT OR UPDATE ON expense_categories
    FOR EACH ROW EXECUTE FUNCTION fn_posting_map_guard();

-- Switching auto-posting on needs every mapping in place.
CREATE OR REPLACE FUNCTION fn_accounting_settings_guard() RETURNS trigger AS $$
DECLARE
    v_gaps text;
BEGIN
    IF NEW.auto_posting AND NOT OLD.auto_posting THEN
        SELECT string_agg(gap_type || ':' || label, ', ') INTO v_gaps FROM fn_posting_gaps();
        IF v_gaps IS NOT NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_READY: %', v_gaps USING ERRCODE = 'P0001';
        END IF;
        NEW.auto_posting_since := now();
    ELSIF NOT NEW.auto_posting THEN
        NEW.auto_posting_since := NULL;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_accounting_settings_guard ON accounting_settings;
CREATE TRIGGER trg_accounting_settings_guard BEFORE UPDATE ON accounting_settings
    FOR EACH ROW EXECUTE FUNCTION fn_accounting_settings_guard();

-- Demo records (labelled sample data) never reach the books: the demo tool
-- must stay able to remove them, and posted entries are permanent.
CREATE OR REPLACE FUNCTION fn_is_demo_document(p_type text, p_id bigint) RETURNS boolean AS $$
    SELECT EXISTS (SELECT 1 FROM demo_records WHERE row_id = p_id AND table_name = CASE p_type
        WHEN 'EXPENSE' THEN 'expenses' WHEN 'PURCHASE' THEN 'purchase_invoices'
        WHEN 'TRANSFER' THEN 'treasury_transfers' WHEN 'STOCK' THEN 'stock_movements' END)
$$ LANGUAGE sql STABLE;

-- Automatic entries are written only by fn_journal_auto_insert (which raises a
-- transaction-local flag) and are never reversed by hand: the document is the source.
CREATE OR REPLACE FUNCTION fn_journal_entry_auto() RETURNS trigger AS $$
BEGIN
    IF ((TG_OP <> 'INSERT' AND OLD.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK'))
        OR (TG_OP <> 'DELETE' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK')))
       AND COALESCE(current_setting('rroka.auto_posting', true), '') <> 'on' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_AUTO: entries of documents are created by approving the document' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK') AND fn_is_demo_document(NEW.source_type, NEW.source_id) THEN
        RAISE EXCEPTION 'RROKA_POSTING_DEMO: demo data is never posted to the books' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP <> 'DELETE' AND NEW.reverses_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM journal_entries WHERE id = NEW.reverses_id AND source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK')) THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_AUTO: the entry of a document is not reversed by hand; correct it with a manual entry' USING ERRCODE = 'P0001';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$ LANGUAGE plpgsql;
-- Name sorts before trg_journal_entry_guard, so it runs first.
DROP TRIGGER IF EXISTS trg_journal_entry_auto ON journal_entries;
CREATE TRIGGER trg_journal_entry_auto BEFORE INSERT OR UPDATE OR DELETE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION fn_journal_entry_auto();

-- Writes and posts one document entry. Lines are jsonb objects {account_id, debit,
-- credit, description, project_id, partner_type, partner_id}; zero lines are dropped,
-- and a document worth nothing gets no entry (NULL). Every journal rule (balance,
-- books start, closed period, numbering) applies through the usual guards.
CREATE OR REPLACE FUNCTION fn_journal_auto_insert(p_type text, p_id bigint, p_date date, p_desc text, p_ref text,
                                                  p_user bigint, p_lines jsonb) RETURNS bigint AS $$
DECLARE
    v_id bigint;
    l    jsonb;
    v_dr numeric;
    v_cr numeric;
    v_n  int := 0;
BEGIN
    IF p_user IS NULL THEN
        RAISE EXCEPTION 'RROKA_POSTING_NO_USER: the posting needs the approving user' USING ERRCODE = 'P0001';
    END IF;
    IF EXISTS (SELECT 1 FROM posting_exclusions WHERE source_type = p_type AND source_id = p_id) THEN
        RAISE EXCEPTION 'RROKA_POSTING_EXCLUDED: % % was excluded from the books', p_type, p_ref USING ERRCODE = 'P0001';
    END IF;
    IF EXISTS (SELECT 1 FROM journal_entries WHERE source_type = p_type AND source_id = p_id) THEN
        RAISE EXCEPTION 'RROKA_POSTING_DUPLICATE: % % already has its entry', p_type, p_ref USING ERRCODE = 'P0001';
    END IF;
    PERFORM set_config('rroka.auto_posting', 'on', true);
    INSERT INTO journal_entries (entry_date, description, source_type, source_id, reference, created_by)
    VALUES (p_date, p_desc, p_type, p_id, p_ref, p_user) RETURNING id INTO v_id;
    FOR l IN SELECT * FROM jsonb_array_elements(p_lines) LOOP
        v_dr := round(COALESCE((l->>'debit')::numeric, 0), 2);
        v_cr := round(COALESCE((l->>'credit')::numeric, 0), 2);
        CONTINUE WHEN v_dr = 0 AND v_cr = 0;
        INSERT INTO journal_lines (entry_id, account_id, debit, credit, description, project_id, partner_type, partner_id)
        VALUES (v_id, (l->>'account_id')::bigint, v_dr, v_cr, NULLIF(l->>'description', ''),
                (l->>'project_id')::bigint, l->>'partner_type', (l->>'partner_id')::bigint);
        v_n := v_n + 1;
    END LOOP;
    IF v_n = 0 THEN
        DELETE FROM journal_entries WHERE id = v_id;
        v_id := NULL;
    ELSE
        UPDATE journal_entries SET status = 'POSTED', posted_by = p_user WHERE id = v_id;
    END IF;
    PERFORM set_config('rroka.auto_posting', '', true);
    RETURN v_id;
END $$ LANGUAGE plpgsql;

-- The entry of one document (built from the document as approved). p_user is the
-- posting user; NULL means the document's own approver (or the movement's author).
CREATE OR REPLACE FUNCTION fn_post_document(p_type text, p_id bigint, p_user bigint DEFAULT NULL) RETURNS bigint AS $$
DECLARE
    e   expenses%ROWTYPE;
    p   purchase_invoices%ROWTYPE;
    t   treasury_transfers%ROWTYPE;
    m   stock_movements%ROWTYPE;
    pa  payment_accounts%ROWTYPE;
    pb  payment_accounts%ROWTYPE;
    v_cat   bigint;
    v_name  text;
    v_tot   v_purchase_totals%ROWTYPE;
    v_val   numeric;
    v_inv   bigint;
    v_other bigint;
BEGIN
    CASE p_type
    WHEN 'EXPENSE' THEN
        SELECT * INTO e FROM expenses WHERE id = p_id;
        IF e.status IS DISTINCT FROM 'APPROVED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: expense %', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT * INTO pa FROM payment_accounts WHERE id = e.payment_account_id;
        IF pa.account_id IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_MAPPING: payment account "%" has no ledger account', pa.name USING ERRCODE = 'P0001';
        END IF;
        SELECT account_id, name INTO v_cat, v_name FROM expense_categories WHERE id = e.category_id;
        IF e.project_id IS NULL AND v_cat IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_MAPPING: expense category "%" has no ledger account', v_name USING ERRCODE = 'P0001';
        END IF;
        RETURN fn_journal_auto_insert('EXPENSE', e.id, e.expense_date, e.expense_no || ' — ' || e.description, e.expense_no,
            COALESCE(p_user, e.approved_by), jsonb_build_array(
                jsonb_build_object('account_id', CASE WHEN e.project_id IS NULL THEN v_cat ELSE fn_role_account('WIP') END,
                                   'debit', e.amount, 'description', e.description, 'project_id', e.project_id),
                jsonb_build_object('account_id', CASE WHEN e.vat_amount > 0 THEN fn_role_account('INPUT_VAT') END,
                                   'debit', e.vat_amount),
                jsonb_build_object('account_id', pa.account_id, 'credit', e.amount + e.vat_amount, 'description', pa.name,
                                   'partner_type', CASE WHEN pa.kind = 'CUSTODY' THEN 'EMPLOYEE' END,
                                   'partner_id', CASE WHEN pa.kind = 'CUSTODY' THEN pa.employee_id END)));

    WHEN 'PURCHASE' THEN
        SELECT * INTO p FROM purchase_invoices WHERE id = p_id;
        IF p.status IS DISTINCT FROM 'APPROVED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: purchase invoice %', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT * INTO v_tot FROM v_purchase_totals WHERE purchase_invoice_id = p_id;
        SELECT name INTO v_name FROM suppliers WHERE id = p.supplier_id;
        RETURN fn_journal_auto_insert('PURCHASE', p.id, p.invoice_date,
            p.purchase_no || ' / ' || p.supplier_invoice_no || ' — ' || v_name, p.purchase_no,
            COALESCE(p_user, p.approved_by), jsonb_build_array(
                jsonb_build_object('account_id', fn_role_account('INVENTORY'), 'debit', v_tot.net_before_vat),
                jsonb_build_object('account_id', CASE WHEN v_tot.vat_amount > 0 THEN fn_role_account('INPUT_VAT') END,
                                   'debit', v_tot.vat_amount),
                jsonb_build_object('account_id', fn_role_account('PAYABLE'), 'credit', v_tot.total,
                                   'description', p.supplier_invoice_no, 'partner_type', 'SUPPLIER', 'partner_id', p.supplier_id)));

    WHEN 'TRANSFER' THEN
        SELECT * INTO t FROM treasury_transfers WHERE id = p_id;
        IF t.status IS DISTINCT FROM 'APPROVED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: transfer %', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT * INTO pa FROM payment_accounts WHERE id = t.from_account_id;
        SELECT * INTO pb FROM payment_accounts WHERE id = t.to_account_id;
        IF pa.account_id IS NULL OR pb.account_id IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_MAPPING: payment account "%" has no ledger account',
                CASE WHEN pa.account_id IS NULL THEN pa.name ELSE pb.name END USING ERRCODE = 'P0001';
        END IF;
        RETURN fn_journal_auto_insert('TRANSFER', t.id, t.transfer_date,
            t.transfer_no || ' — ' || pa.name || ' → ' || pb.name, t.transfer_no,
            COALESCE(p_user, t.approved_by), jsonb_build_array(
                jsonb_build_object('account_id', pb.account_id, 'debit', t.amount, 'description', pb.name,
                                   'partner_type', CASE WHEN pb.kind = 'CUSTODY' THEN 'EMPLOYEE' END,
                                   'partner_id', CASE WHEN pb.kind = 'CUSTODY' THEN pb.employee_id END),
                jsonb_build_object('account_id', pa.account_id, 'credit', t.amount, 'description', pa.name,
                                   'partner_type', CASE WHEN pa.kind = 'CUSTODY' THEN 'EMPLOYEE' END,
                                   'partner_id', CASE WHEN pa.kind = 'CUSTODY' THEN pa.employee_id END)));

    WHEN 'STOCK' THEN
        SELECT * INTO m FROM stock_movements WHERE id = p_id;
        IF m.id IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: stock movement %', p_id USING ERRCODE = 'P0001';
        END IF;
        -- Reservations move no value; a purchase receipt is inside the invoice's entry.
        IF m.movement_type IN ('RESERVE', 'UNRESERVE') OR (m.movement_type = 'RECEIPT' AND m.purchase_invoice_line_id IS NOT NULL) THEN
            RETURN NULL;
        END IF;
        IF m.movement_type = 'RECEIPT' THEN
            RAISE EXCEPTION 'RROKA_POSTING_MANUAL_ONLY: a receipt outside a purchase invoice is booked by a manual entry' USING ERRCODE = 'P0001';
        END IF;
        IF m.unit_cost IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_NO_COST: movement % has no cost', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT code || ' ' || name INTO v_name FROM raw_materials WHERE id = m.material_id;
        v_val := round(m.quantity * m.unit_cost, 2);
        v_inv := fn_role_account('INVENTORY');
        v_other := fn_role_account(CASE WHEN m.movement_type IN ('ISSUE', 'RETURN') THEN 'WIP' ELSE 'INVENTORY_ADJUSTMENT' END);
        RETURN fn_journal_auto_insert('STOCK', m.id, (m.moved_at AT TIME ZONE 'Asia/Riyadh')::date,
            'SM-' || m.id || ' ' || CASE m.movement_type WHEN 'ISSUE' THEN 'صرف للإنتاج' WHEN 'RETURN' THEN 'إرجاع للمخزن'
                WHEN 'ADJUST_IN' THEN 'تسوية بالزيادة' ELSE 'تسوية بالنقص' END || ' — ' || v_name || ' × ' || trim_scale(m.quantity), 'SM-' || m.id,
            COALESCE(p_user, m.created_by, NULLIF(current_setting('rroka.user_id', true), '')::bigint), jsonb_build_array(
                jsonb_build_object('account_id', CASE WHEN m.movement_type IN ('ISSUE', 'ADJUST_OUT') THEN v_other ELSE v_inv END,
                                   'debit', v_val, 'description', v_name,
                                   'project_id', CASE WHEN m.movement_type = 'ISSUE' THEN m.project_id END),
                jsonb_build_object('account_id', CASE WHEN m.movement_type IN ('ISSUE', 'ADJUST_OUT') THEN v_inv ELSE v_other END,
                                   'credit', v_val, 'description', v_name,
                                   'project_id', CASE WHEN m.movement_type = 'RETURN' THEN m.project_id END)));
    ELSE
        RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: unknown document type %', p_type USING ERRCODE = 'P0001';
    END CASE;
END $$ LANGUAGE plpgsql;

-- Approval (or a stock movement) posts at once when auto-posting is on. A document
-- dated before the books start is part of the opening balances and posts nothing.
CREATE OR REPLACE FUNCTION fn_auto_post_document() RETURNS trigger AS $$
DECLARE
    s accounting_settings%ROWTYPE;
    v_date date;
BEGIN
    SELECT * INTO s FROM accounting_settings WHERE id = 1;
    IF NOT COALESCE(s.auto_posting, false) OR COALESCE(current_setting('rroka.demo', true), '') = 'on'
       OR fn_is_demo_document(TG_ARGV[0], NEW.id) THEN
        RETURN NULL;   -- off, or demo data being loaded / approved
    END IF;
    IF TG_ARGV[0] = 'STOCK' THEN
        v_date := (NEW.moved_at AT TIME ZONE 'Asia/Riyadh')::date;
    ELSE
        IF NEW.status <> 'APPROVED' OR OLD.status = 'APPROVED' THEN
            RETURN NULL;
        END IF;
        v_date := (to_jsonb(NEW)->>TG_ARGV[1])::date;
    END IF;
    IF v_date < s.books_start THEN
        RETURN NULL;
    END IF;
    PERFORM fn_post_document(TG_ARGV[0], NEW.id, NULL);
    RETURN NULL;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_expense_post_gl ON expenses;
CREATE TRIGGER trg_expense_post_gl AFTER UPDATE OF status ON expenses
    FOR EACH ROW EXECUTE FUNCTION fn_auto_post_document('EXPENSE', 'expense_date');
-- Name sorts after trg_purchase_invoice_post: the stock receipts exist first.
DROP TRIGGER IF EXISTS trg_purchase_invoice_post_gl ON purchase_invoices;
CREATE TRIGGER trg_purchase_invoice_post_gl AFTER UPDATE OF status ON purchase_invoices
    FOR EACH ROW EXECUTE FUNCTION fn_auto_post_document('PURCHASE', 'invoice_date');
DROP TRIGGER IF EXISTS trg_treasury_transfer_post_gl ON treasury_transfers;
CREATE TRIGGER trg_treasury_transfer_post_gl AFTER UPDATE OF status ON treasury_transfers
    FOR EACH ROW EXECUTE FUNCTION fn_auto_post_document('TRANSFER', 'transfer_date');
DROP TRIGGER IF EXISTS trg_stock_movement_post_gl ON stock_movements;
CREATE TRIGGER trg_stock_movement_post_gl AFTER INSERT ON stock_movements
    FOR EACH ROW EXECUTE FUNCTION fn_auto_post_document('STOCK');

-- Approved documents (on or after the books start) with value and no entry, not
-- excluded: what the books still miss.
CREATE OR REPLACE VIEW v_posting_backlog AS
WITH docs AS (
    SELECT 'EXPENSE'::text AS source_type, e.id AS source_id, e.expense_no AS doc_no, e.expense_date AS doc_date,
           e.description, NULL::text AS movement_type, (e.amount + e.vat_amount)::numeric(14,2) AS amount
      FROM expenses e WHERE e.status = 'APPROVED'
    UNION ALL
    SELECT 'PURCHASE', p.id, p.purchase_no, p.invoice_date, s.name || ' — ' || p.supplier_invoice_no, NULL, t.total
      FROM purchase_invoices p JOIN v_purchase_totals t ON t.purchase_invoice_id = p.id JOIN suppliers s ON s.id = p.supplier_id
     WHERE p.status = 'APPROVED'
    UNION ALL
    SELECT 'TRANSFER', t.id, t.transfer_no, t.transfer_date, fa.name || ' → ' || ta.name, NULL, t.amount
      FROM treasury_transfers t JOIN payment_accounts fa ON fa.id = t.from_account_id JOIN payment_accounts ta ON ta.id = t.to_account_id
     WHERE t.status = 'APPROVED'
    UNION ALL
    SELECT 'STOCK', m.id, 'SM-' || m.id, (m.moved_at AT TIME ZONE 'Asia/Riyadh')::date, r.code || ' ' || r.name,
           m.movement_type, round(m.quantity * m.unit_cost, 2)
      FROM stock_movements m JOIN raw_materials r ON r.id = m.material_id
     WHERE m.movement_type IN ('ISSUE', 'RETURN', 'ADJUST_IN', 'ADJUST_OUT')
        OR (m.movement_type = 'RECEIPT' AND m.purchase_invoice_line_id IS NULL)
)
SELECT d.*
  FROM docs d CROSS JOIN accounting_settings s
 WHERE s.id = 1 AND d.doc_date >= s.books_start AND (d.amount IS NULL OR d.amount > 0)
   AND NOT EXISTS (SELECT 1 FROM journal_entries j WHERE j.source_type = d.source_type AND j.source_id = d.source_id)
   AND NOT EXISTS (SELECT 1 FROM posting_exclusions x WHERE x.source_type = d.source_type AND x.source_id = d.source_id)
   AND NOT fn_is_demo_document(d.source_type, d.source_id);

DO $$
BEGIN
    DROP TRIGGER IF EXISTS trg_audit_posting_exclusions ON posting_exclusions;
    CREATE TRIGGER trg_audit_posting_exclusions AFTER INSERT OR UPDATE OR DELETE ON posting_exclusions
        FOR EACH ROW EXECUTE FUNCTION fn_audit();
END $$;

-- ---------------------------------------------------------------------
-- Deleting (2026-10-08, owner request: delete and edit on every screen).
-- A document is deleted only while it is a draft (a quotation also only
-- before it reached Daftra); once approved it is final and corrected by
-- its own path (cancellation, reversal, a correcting entry). Master data
-- (clients, suppliers, materials, categories, accounts, employees) is
-- deleted only if nothing refers to it — the foreign keys refuse it
-- otherwise, and it is archived instead. Every deletion is in audit_log.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_delete_draft_only() RETURNS trigger AS $$
BEGIN
    IF OLD.status <> 'DRAFT' THEN
        RAISE EXCEPTION 'RROKA_DELETE_NOT_DRAFT: % % is %', TG_TABLE_NAME, OLD.id, OLD.status USING ERRCODE = 'P0001';
    END IF;
    IF TG_TABLE_NAME = 'quotations' AND (to_jsonb(OLD)->>'daftra_estimate_id') IS NOT NULL THEN
        RAISE EXCEPTION 'RROKA_DELETE_NOT_DRAFT: quotation % is already in Daftra', OLD.id USING ERRCODE = 'P0001';
    END IF;
    RETURN OLD;
END $$ LANGUAGE plpgsql;
DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['quotations', 'expenses', 'purchase_invoices', 'treasury_transfers'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_delete_draft_only ON %I', t);
        EXECUTE format('CREATE TRIGGER trg_delete_draft_only BEFORE DELETE ON %I FOR EACH ROW EXECUTE FUNCTION fn_delete_draft_only()', t);
    END LOOP;
END $$;

COMMIT;
