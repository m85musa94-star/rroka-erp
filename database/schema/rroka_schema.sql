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

COMMIT;
