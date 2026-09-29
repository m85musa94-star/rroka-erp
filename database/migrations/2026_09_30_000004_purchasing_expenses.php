<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Suppliers, purchase invoices (approval posts stock receipts), expenses, and
 * project direct expenses in job costing. The same SQL lives at the end of rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
SQL);
    }
};
