<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Past projects (quotation exempt from the cost sheet, with the reason) and customer invoices/receipts posted to the books. Same SQL is at the end of rroka_schema.sql. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Past work and sales (2026-10-13, owner decision: the work done before the
-- system is entered by hand, without stock; project costs go to the project,
-- customer invoices as issued in Daftra).
--
-- 1. A past project: its quotation is exempt from the cost sheet (the cost
--    rates are not set, and the price was agreed long ago). The exemption is
--    always explained, fixed at creation, and a past quotation never goes to
--    Daftra (the invoice there already exists).
-- ---------------------------------------------------------------------
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS costing_exempt_reason text;
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS is_legacy boolean NOT NULL DEFAULT false;

CREATE OR REPLACE FUNCTION fn_quotation_exemption() RETURNS trigger AS $$
BEGIN
    NEW.costing_exempt_reason := NULLIF(btrim(NEW.costing_exempt_reason), '');
    IF TG_OP = 'INSERT' THEN
        IF NEW.is_legacy OR NEW.costing_exempt_reason IS NOT NULL THEN
            NEW.requires_costing := false;
        END IF;
        IF NOT NEW.requires_costing AND NEW.costing_exempt_reason IS NULL THEN
            RAISE EXCEPTION 'RROKA_QUOTATION_EXEMPT_REASON: a quotation without a cost sheet needs the reason' USING ERRCODE = 'P0001';
        END IF;
    ELSIF NEW.requires_costing IS DISTINCT FROM OLD.requires_costing OR NEW.is_legacy IS DISTINCT FROM OLD.is_legacy
          OR NEW.costing_exempt_reason IS DISTINCT FROM OLD.costing_exempt_reason THEN
        RAISE EXCEPTION 'RROKA_QUOTATION_EXEMPT_LOCKED: the cost-sheet exemption is fixed at creation' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.is_legacy AND NEW.daftra_estimate_id IS NOT NULL THEN
        RAISE EXCEPTION 'RROKA_QUOTATION_LEGACY_DAFTRA: a past quotation is not sent to Daftra' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_quotation_exemption ON quotations;
CREATE TRIGGER trg_quotation_exemption BEFORE INSERT OR UPDATE ON quotations
    FOR EACH ROW EXECUTE FUNCTION fn_quotation_exemption();

-- ---------------------------------------------------------------------
-- 2. Customer invoices and receipts. The tax invoice is issued in Daftra
--    (the ZATCA gate); here it is recorded with Daftra's number exactly as
--    issued, for the books, the client balance and the project's revenue.
--    A credit note refers to its invoice and never exceeds what is left of it.
--    Receipts reduce the client's balance (one receivable per client; a
--    receipt before the invoice leaves a credit balance = advance).
-- ---------------------------------------------------------------------
CREATE SEQUENCE IF NOT EXISTS seq_sales_invoice_no;
CREATE SEQUENCE IF NOT EXISTS seq_customer_receipt_no;

INSERT INTO permissions (code, description) VALUES
    ('sales.view', 'عرض فواتير العملاء والتحصيل وأرصدة العملاء'),
    ('sales.manage', 'إدخال فواتير العملاء وسندات القبض'),
    ('sales.approve', 'اعتماد فواتير العملاء وسندات القبض (تُرحَّل للدفاتر)')
ON CONFLICT (code) DO NOTHING;
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND p.code IN ('sales.view', 'sales.manage', 'sales.approve')
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS sales_invoices (
    id                  bigserial PRIMARY KEY,
    invoice_no          text NOT NULL UNIQUE DEFAULT fn_doc_number('SI', 'seq_sales_invoice_no'),
    kind                text NOT NULL DEFAULT 'INVOICE' CHECK (kind IN ('INVOICE', 'CREDIT_NOTE')),
    daftra_invoice_no   text NOT NULL CHECK (btrim(daftra_invoice_no) <> ''),
    invoice_date        date NOT NULL,
    client_id           bigint NOT NULL REFERENCES clients(id),
    project_id          bigint REFERENCES projects(id),
    original_invoice_id bigint REFERENCES sales_invoices(id),
    description         text NOT NULL CHECK (btrim(description) <> ''),
    amount              numeric(14,2) NOT NULL CHECK (amount > 0),        -- before VAT
    vat_amount          numeric(14,2) NOT NULL DEFAULT 0 CHECK (vat_amount >= 0 AND vat_amount <= amount),
    total               numeric(14,2) GENERATED ALWAYS AS (amount + vat_amount) STORED,
    notes               text,
    attachment_id       bigint REFERENCES studio_assets(id),
    status              text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    approved_by         bigint REFERENCES users(id),
    approved_at         timestamptz,
    created_by          bigint REFERENCES users(id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'CREDIT_NOTE') = (original_invoice_id IS NOT NULL)),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
-- Daftra's number is entered once (a cancelled draft frees it).
CREATE UNIQUE INDEX IF NOT EXISTS ux_sales_invoices_daftra_no ON sales_invoices (kind, btrim(daftra_invoice_no)) WHERE status <> 'CANCELLED';
CREATE INDEX IF NOT EXISTS ix_sales_invoices_client ON sales_invoices (client_id);
CREATE INDEX IF NOT EXISTS ix_sales_invoices_project ON sales_invoices (project_id);

CREATE TABLE IF NOT EXISTS customer_receipts (
    id                 bigserial PRIMARY KEY,
    receipt_no         text NOT NULL UNIQUE DEFAULT fn_doc_number('RC', 'seq_customer_receipt_no'),
    receipt_date       date NOT NULL,
    client_id          bigint NOT NULL REFERENCES clients(id),
    project_id         bigint REFERENCES projects(id),
    sales_invoice_id   bigint REFERENCES sales_invoices(id),
    payment_account_id bigint NOT NULL REFERENCES payment_accounts(id),
    amount             numeric(14,2) NOT NULL CHECK (amount > 0),
    reference          text,
    description        text,
    attachment_id      bigint REFERENCES studio_assets(id),
    status             text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    approved_by        bigint REFERENCES users(id),
    approved_at        timestamptz,
    created_by         bigint REFERENCES users(id),
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_customer_receipts_client ON customer_receipts (client_id);
CREATE INDEX IF NOT EXISTS ix_customer_receipts_project ON customer_receipts (project_id);

-- Draft → approved or cancelled; after that the document never changes.
-- The project (and the invoice a receipt or credit note refers to) belong to the same client.
CREATE OR REPLACE FUNCTION fn_sales_document_guard() RETURNS trigger AS $$
DECLARE
    v_client  bigint;
    v_inv     sales_invoices%ROWTYPE;
    v_used    numeric;
    v_active  boolean;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' THEN
            RAISE EXCEPTION 'RROKA_SALES_TRANSITION: a document starts as draft' USING ERRCODE = 'P0001';
        END IF;
    ELSIF OLD.status <> 'DRAFT' THEN
        IF (to_jsonb(NEW) - 'updated_at' - 'total') = (to_jsonb(OLD) - 'updated_at' - 'total') THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'RROKA_SALES_LOCKED: % % is %', TG_TABLE_NAME, OLD.id, OLD.status USING ERRCODE = 'P0001';
    END IF;

    IF TG_TABLE_NAME = 'sales_invoices' THEN
        IF NEW.original_invoice_id IS NOT NULL THEN
            SELECT * INTO v_inv FROM sales_invoices WHERE id = NEW.original_invoice_id;
            IF v_inv.kind <> 'INVOICE' OR v_inv.client_id <> NEW.client_id OR v_inv.status = 'CANCELLED' THEN
                RAISE EXCEPTION 'RROKA_SALES_CREDIT_INVOICE: a credit note refers to an invoice of the same client' USING ERRCODE = 'P0001';
            END IF;
            NEW.project_id := COALESCE(NEW.project_id, v_inv.project_id);
            IF NEW.project_id IS DISTINCT FROM v_inv.project_id THEN
                RAISE EXCEPTION 'RROKA_SALES_CREDIT_INVOICE: a credit note stays on its invoice''s project' USING ERRCODE = 'P0001';
            END IF;
            IF NEW.status = 'APPROVED' THEN
                IF v_inv.status <> 'APPROVED' THEN
                    RAISE EXCEPTION 'RROKA_SALES_CREDIT_INVOICE: the invoice is approved first' USING ERRCODE = 'P0001';
                END IF;
                SELECT COALESCE(sum(amount + vat_amount), 0) INTO v_used FROM sales_invoices
                 WHERE original_invoice_id = NEW.original_invoice_id AND status = 'APPROVED' AND id <> NEW.id;
                IF v_used + NEW.amount + NEW.vat_amount > v_inv.amount + v_inv.vat_amount THEN
                    RAISE EXCEPTION 'RROKA_SALES_CREDIT_EXCEEDS: credit notes would exceed the invoice' USING ERRCODE = 'P0001';
                END IF;
            END IF;
        END IF;
    ELSE
        IF NEW.sales_invoice_id IS NOT NULL THEN
            SELECT * INTO v_inv FROM sales_invoices WHERE id = NEW.sales_invoice_id;
            IF v_inv.kind <> 'INVOICE' OR v_inv.client_id <> NEW.client_id OR v_inv.status = 'CANCELLED' THEN
                RAISE EXCEPTION 'RROKA_SALES_RECEIPT_INVOICE: a receipt refers to an invoice of the same client' USING ERRCODE = 'P0001';
            END IF;
            NEW.project_id := COALESCE(NEW.project_id, v_inv.project_id);
        END IF;
        IF NEW.status = 'APPROVED' THEN
            SELECT is_active INTO v_active FROM payment_accounts WHERE id = NEW.payment_account_id;
            IF NOT v_active THEN
                RAISE EXCEPTION 'RROKA_PAYMENT_ACCOUNT_INACTIVE' USING ERRCODE = 'P0001';
            END IF;
        END IF;
    END IF;
    IF NEW.project_id IS NOT NULL THEN
        SELECT client_id INTO v_client FROM projects WHERE id = NEW.project_id;
        IF v_client <> NEW.client_id THEN
            RAISE EXCEPTION 'RROKA_SALES_CLIENT_MISMATCH: the project belongs to another client' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_sales_invoice_guard ON sales_invoices;
CREATE TRIGGER trg_sales_invoice_guard BEFORE INSERT OR UPDATE ON sales_invoices
    FOR EACH ROW EXECUTE FUNCTION fn_sales_document_guard();
DROP TRIGGER IF EXISTS trg_customer_receipt_guard ON customer_receipts;
CREATE TRIGGER trg_customer_receipt_guard BEFORE INSERT OR UPDATE ON customer_receipts
    FOR EACH ROW EXECUTE FUNCTION fn_sales_document_guard();

-- Balances from the approved documents (the books hold the same through the
-- receivable account, by client): invoiced, credited, collected, balance.
CREATE OR REPLACE VIEW v_client_balances AS
WITH m AS (
    SELECT client_id, CASE kind WHEN 'INVOICE' THEN total ELSE 0 END AS invoiced,
           CASE kind WHEN 'CREDIT_NOTE' THEN total ELSE 0 END AS credited, 0::numeric AS collected
      FROM sales_invoices WHERE status = 'APPROVED'
    UNION ALL
    SELECT client_id, 0, 0, amount FROM customer_receipts WHERE status = 'APPROVED'
)
SELECT client_id, sum(invoiced)::numeric(16,2) AS invoiced, sum(credited)::numeric(16,2) AS credited,
       sum(collected)::numeric(16,2) AS collected, sum(invoiced - credited - collected)::numeric(16,2) AS balance
  FROM m GROUP BY client_id;

-- Per project: revenue before VAT (invoices less credit notes) and collections.
CREATE OR REPLACE VIEW v_project_sales AS
WITH m AS (
    SELECT project_id, CASE kind WHEN 'INVOICE' THEN amount ELSE -amount END AS revenue,
           CASE kind WHEN 'INVOICE' THEN total ELSE -total END AS invoiced, 0::numeric AS collected
      FROM sales_invoices WHERE status = 'APPROVED' AND project_id IS NOT NULL
    UNION ALL
    SELECT project_id, 0, 0, amount FROM customer_receipts WHERE status = 'APPROVED' AND project_id IS NOT NULL
)
SELECT project_id, sum(revenue)::numeric(16,2) AS revenue, sum(invoiced)::numeric(16,2) AS invoiced,
       sum(collected)::numeric(16,2) AS collected, sum(invoiced - collected)::numeric(16,2) AS balance
  FROM m GROUP BY project_id;

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['sales_invoices', 'customer_receipts'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
        EXECUTE format('DROP TRIGGER IF EXISTS trg_delete_draft_only ON %I', t);
        EXECUTE format('CREATE TRIGGER trg_delete_draft_only BEFORE DELETE ON %I FOR EACH ROW EXECUTE FUNCTION fn_delete_draft_only()', t);
    END LOOP;
END $$;

-- ---------------------------------------------------------------------
-- 3. Into the books: SALE (Dr receivable / Cr revenue + output VAT; a credit
--    note the reverse) and RECEIPT (Dr cash/bank / Cr receivable), on the
--    same rules as the other documents.
-- ---------------------------------------------------------------------
ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_source_type_check;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_source_type_check CHECK (source_type IN (
    'MANUAL', 'OPENING', 'REVERSAL', 'EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT'));
ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_document_source;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_document_source CHECK (
    source_type NOT IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT') OR source_id IS NOT NULL);
DROP INDEX IF EXISTS ux_journal_entries_document;
CREATE UNIQUE INDEX ux_journal_entries_document ON journal_entries (source_type, source_id)
    WHERE source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT');
ALTER TABLE posting_exclusions DROP CONSTRAINT IF EXISTS posting_exclusions_source_type_check;
ALTER TABLE posting_exclusions ADD CONSTRAINT posting_exclusions_source_type_check CHECK (
    source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT'));

CREATE OR REPLACE FUNCTION fn_is_demo_document(p_type text, p_id bigint) RETURNS boolean AS $$
    SELECT EXISTS (SELECT 1 FROM demo_records WHERE row_id = p_id AND table_name = CASE p_type
        WHEN 'EXPENSE' THEN 'expenses' WHEN 'PURCHASE' THEN 'purchase_invoices'
        WHEN 'TRANSFER' THEN 'treasury_transfers' WHEN 'STOCK' THEN 'stock_movements'
        WHEN 'SALE' THEN 'sales_invoices' WHEN 'RECEIPT' THEN 'customer_receipts' END)
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_posting_gaps() RETURNS TABLE (gap_type text, ref_id bigint, label text) AS $$
    SELECT 'ROLE'::text, NULL::bigint, r
      FROM unnest(ARRAY['INVENTORY', 'WIP', 'INPUT_VAT', 'PAYABLE', 'INVENTORY_ADJUSTMENT', 'RECEIVABLE', 'SALES', 'OUTPUT_VAT']) AS r
     WHERE NOT EXISTS (SELECT 1 FROM accounts a WHERE a.system_role = r AND a.is_active AND a.is_postable)
    UNION ALL
    SELECT 'PAYMENT_ACCOUNT', id, name FROM payment_accounts WHERE is_active AND account_id IS NULL
       AND id NOT IN (SELECT row_id FROM demo_records WHERE table_name = 'payment_accounts')
    UNION ALL
    SELECT 'EXPENSE_CATEGORY', id, name FROM expense_categories WHERE is_active AND account_id IS NULL
       AND id NOT IN (SELECT row_id FROM demo_records WHERE table_name = 'expense_categories')
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_journal_entry_auto() RETURNS trigger AS $$
BEGIN
    IF ((TG_OP <> 'INSERT' AND OLD.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT'))
        OR (TG_OP <> 'DELETE' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT')))
       AND COALESCE(current_setting('rroka.auto_posting', true), '') <> 'on' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_AUTO: entries of documents are created by approving the document' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT') AND fn_is_demo_document(NEW.source_type, NEW.source_id) THEN
        RAISE EXCEPTION 'RROKA_POSTING_DEMO: demo data is never posted to the books' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP <> 'DELETE' AND NEW.reverses_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM journal_entries WHERE id = NEW.reverses_id AND source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT')) THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_AUTO: the entry of a document is not reversed by hand; correct it with a manual entry' USING ERRCODE = 'P0001';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$ LANGUAGE plpgsql;

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
    si  sales_invoices%ROWTYPE;
    rc  customer_receipts%ROWTYPE;
    v_sign numeric;
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
    WHEN 'SALE' THEN
        -- A customer invoice (or credit note) as issued in Daftra: receivable against
        -- project revenue and output VAT; a credit note is the mirror image.
        SELECT * INTO si FROM sales_invoices WHERE id = p_id;
        IF si.status IS DISTINCT FROM 'APPROVED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: sales invoice %', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT business_name INTO v_name FROM clients WHERE id = si.client_id;
        v_sign := CASE si.kind WHEN 'CREDIT_NOTE' THEN -1 ELSE 1 END;
        RETURN fn_journal_auto_insert('SALE', si.id, si.invoice_date,
            si.invoice_no || ' / ' || si.daftra_invoice_no || ' — ' || v_name, si.invoice_no,
            COALESCE(p_user, si.approved_by), jsonb_build_array(
                jsonb_build_object('account_id', fn_role_account('RECEIVABLE'),
                                   'debit', GREATEST(v_sign, 0) * (si.amount + si.vat_amount),
                                   'credit', GREATEST(-v_sign, 0) * (si.amount + si.vat_amount),
                                   'description', si.daftra_invoice_no, 'project_id', si.project_id,
                                   'partner_type', 'CLIENT', 'partner_id', si.client_id),
                jsonb_build_object('account_id', fn_role_account('SALES'),
                                   'debit', GREATEST(-v_sign, 0) * si.amount, 'credit', GREATEST(v_sign, 0) * si.amount,
                                   'description', si.description, 'project_id', si.project_id),
                jsonb_build_object('account_id', CASE WHEN si.vat_amount > 0 THEN fn_role_account('OUTPUT_VAT') END,
                                   'debit', GREATEST(-v_sign, 0) * si.vat_amount, 'credit', GREATEST(v_sign, 0) * si.vat_amount)));

    WHEN 'RECEIPT' THEN
        SELECT * INTO rc FROM customer_receipts WHERE id = p_id;
        IF rc.status IS DISTINCT FROM 'APPROVED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: customer receipt %', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT * INTO pa FROM payment_accounts WHERE id = rc.payment_account_id;
        IF pa.account_id IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_MAPPING: payment account "%" has no ledger account', pa.name USING ERRCODE = 'P0001';
        END IF;
        SELECT business_name INTO v_name FROM clients WHERE id = rc.client_id;
        RETURN fn_journal_auto_insert('RECEIPT', rc.id, rc.receipt_date, rc.receipt_no || ' — ' || v_name, rc.receipt_no,
            COALESCE(p_user, rc.approved_by), jsonb_build_array(
                jsonb_build_object('account_id', pa.account_id, 'debit', rc.amount, 'description', pa.name,
                                   'partner_type', CASE WHEN pa.kind = 'CUSTODY' THEN 'EMPLOYEE' END,
                                   'partner_id', CASE WHEN pa.kind = 'CUSTODY' THEN pa.employee_id END),
                jsonb_build_object('account_id', fn_role_account('RECEIVABLE'), 'credit', rc.amount,
                                   'description', COALESCE(rc.reference, rc.receipt_no), 'project_id', rc.project_id,
                                   'partner_type', 'CLIENT', 'partner_id', rc.client_id)));
    ELSE
        RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: unknown document type %', p_type USING ERRCODE = 'P0001';
    END CASE;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sales_invoice_post_gl ON sales_invoices;
CREATE TRIGGER trg_sales_invoice_post_gl AFTER UPDATE OF status ON sales_invoices
    FOR EACH ROW EXECUTE FUNCTION fn_auto_post_document('SALE', 'invoice_date');
DROP TRIGGER IF EXISTS trg_customer_receipt_post_gl ON customer_receipts;
CREATE TRIGGER trg_customer_receipt_post_gl AFTER UPDATE OF status ON customer_receipts
    FOR EACH ROW EXECUTE FUNCTION fn_auto_post_document('RECEIPT', 'receipt_date');

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
    UNION ALL
    SELECT 'SALE', si.id, si.invoice_no, si.invoice_date, c.business_name || ' — ' || si.daftra_invoice_no,
           CASE WHEN si.kind = 'CREDIT_NOTE' THEN 'CREDIT_NOTE' END, si.total
      FROM sales_invoices si JOIN clients c ON c.id = si.client_id WHERE si.status = 'APPROVED'
    UNION ALL
    SELECT 'RECEIPT', r.id, r.receipt_no, r.receipt_date, c.business_name, NULL, r.amount
      FROM customer_receipts r JOIN clients c ON c.id = r.client_id WHERE r.status = 'APPROVED'
)
SELECT d.*
  FROM docs d CROSS JOIN accounting_settings s
 WHERE s.id = 1 AND d.doc_date >= s.books_start AND (d.amount IS NULL OR d.amount > 0)
   AND NOT EXISTS (SELECT 1 FROM journal_entries j WHERE j.source_type = d.source_type AND j.source_id = d.source_id)
   AND NOT EXISTS (SELECT 1 FROM posting_exclusions x WHERE x.source_type = d.source_type AND x.source_id = d.source_id)
   AND NOT fn_is_demo_document(d.source_type, d.source_id);
SQL);
    }
};
