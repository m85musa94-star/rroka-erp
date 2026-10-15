<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** VAT return: figures from the documents, filed return with lock, settlement and payment entries. Same SQL is at the end of rroka_schema.sql. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- VAT return (2026-10-15, owner approval). The figures come from the
-- approved documents (fn_vat_figures). A filed return is recorded with the
-- amounts actually filed; approving it locks the period for every document
-- that feeds the return (reopened only with a reason) and posts the
-- settlement (output and input VAT into "VAT payable"); its payment to
-- ZATCA is posted from the same return. Input VAT of a supplier without a
-- VAT number is kept apart ("to verify") and enters the deduction only by
-- an explicit decision with its reason.
-- ---------------------------------------------------------------------
ALTER TABLE accounts DROP CONSTRAINT IF EXISTS accounts_system_role_check;
ALTER TABLE accounts ADD CONSTRAINT accounts_system_role_check CHECK (system_role IN (
    'CASH', 'BANK', 'CUSTODY', 'RECEIVABLE', 'INVENTORY', 'WIP', 'INPUT_VAT', 'PAYABLE', 'OUTPUT_VAT',
    'CUSTOMER_ADVANCES', 'CAPITAL', 'OWNER_CURRENT', 'RETAINED_EARNINGS', 'SALES', 'SALES_DISCOUNT',
    'COST_OF_SALES', 'FIXED_ASSETS', 'ACCUMULATED_DEPRECIATION', 'DEPRECIATION', 'INVENTORY_ADJUSTMENT', 'VAT_PAYABLE'));

ALTER TABLE accounting_settings ADD COLUMN IF NOT EXISTS vat_period text CHECK (vat_period IN ('MONTHLY', 'QUARTERLY'));
ALTER TABLE accounting_settings ADD COLUMN IF NOT EXISTS vat_locked_until date;

CREATE TABLE IF NOT EXISTS vat_lock_events (
    id            bigserial PRIMARY KEY,
    locked_before date,
    locked_after  date,
    reason        text NOT NULL CHECK (btrim(reason) <> ''),
    created_by    bigint NOT NULL REFERENCES users(id),
    created_at    timestamptz NOT NULL DEFAULT now()
);

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
    -- The VAT lock only moves back through fn_vat_reopen (with its reason on record).
    IF NEW.vat_locked_until IS DISTINCT FROM OLD.vat_locked_until
       AND (NEW.vat_locked_until IS NULL OR NEW.vat_locked_until < OLD.vat_locked_until)
       AND COALESCE(current_setting('rroka.vat_reopen', true), '') <> 'on' THEN
        RAISE EXCEPTION 'RROKA_VAT_LOCK: a filed VAT period is reopened with a reason' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

-- Reopens filed VAT periods back to p_until (NULL = all), with the reason on record.
CREATE OR REPLACE FUNCTION fn_vat_reopen(p_until date, p_reason text, p_user bigint) RETURNS void AS $$
DECLARE
    v_before date;
BEGIN
    IF NULLIF(btrim(p_reason), '') IS NULL OR p_user IS NULL THEN
        RAISE EXCEPTION 'RROKA_VAT_LOCK: reopening needs the reason and the user' USING ERRCODE = 'P0001';
    END IF;
    SELECT vat_locked_until INTO v_before FROM accounting_settings WHERE id = 1;
    PERFORM set_config('rroka.vat_reopen', 'on', true);
    UPDATE accounting_settings SET vat_locked_until = p_until WHERE id = 1;
    PERFORM set_config('rroka.vat_reopen', '', true);
    INSERT INTO vat_lock_events (locked_before, locked_after, reason, created_by) VALUES (v_before, p_until, p_reason, p_user);
END $$ LANGUAGE plpgsql;

-- A document that feeds the VAT return is not approved inside a filed period.
CREATE OR REPLACE FUNCTION fn_vat_lock_guard() RETURNS trigger AS $$
DECLARE
    v_lock date;
    v_date date;
BEGIN
    IF NEW.status <> 'APPROVED' OR OLD.status = 'APPROVED' THEN
        RETURN NEW;
    END IF;
    SELECT vat_locked_until INTO v_lock FROM accounting_settings WHERE id = 1;
    IF v_lock IS NULL THEN
        RETURN NEW;
    END IF;
    v_date := (to_jsonb(NEW)->>TG_ARGV[0])::date;
    IF v_date <= v_lock AND (TG_TABLE_NAME = 'sales_invoices' OR (to_jsonb(NEW)->>'vat_amount')::numeric > 0) THEN
        RAISE EXCEPTION 'RROKA_VAT_PERIOD_LOCKED: % is inside a filed VAT period (until %)', v_date, v_lock USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_sales_invoice_vat_lock ON sales_invoices;
CREATE TRIGGER trg_sales_invoice_vat_lock BEFORE UPDATE OF status ON sales_invoices FOR EACH ROW EXECUTE FUNCTION fn_vat_lock_guard('invoice_date');
DROP TRIGGER IF EXISTS trg_expense_vat_lock ON expenses;
CREATE TRIGGER trg_expense_vat_lock BEFORE UPDATE OF status ON expenses FOR EACH ROW EXECUTE FUNCTION fn_vat_lock_guard('expense_date');
DROP TRIGGER IF EXISTS trg_purchase_invoice_vat_lock ON purchase_invoices;
CREATE TRIGGER trg_purchase_invoice_vat_lock BEFORE UPDATE OF status ON purchase_invoices FOR EACH ROW EXECUTE FUNCTION fn_vat_lock_guard('invoice_date');

-- Every approved document line that feeds the return, classified. A rate other
-- than 15% or 0 is flagged (rate_ok = false): a typing error until shown otherwise.
CREATE OR REPLACE VIEW v_vat_documents AS
SELECT 'SALE'::text AS source_type, si.id AS source_id, si.invoice_no AS doc_no, si.invoice_date AS doc_date,
       c.business_name AS party, NULL::text AS party_vat_no,
       CASE si.kind WHEN 'CREDIT_NOTE' THEN 'SALES_CREDIT' WHEN 'INVOICE' THEN CASE WHEN si.vat_amount > 0 THEN 'SALES_STD' ELSE 'SALES_ZERO' END END AS bucket,
       (CASE si.kind WHEN 'CREDIT_NOTE' THEN -1 ELSE 1 END * si.amount)::numeric(14,2) AS amount,
       (CASE si.kind WHEN 'CREDIT_NOTE' THEN -1 ELSE 1 END * si.vat_amount)::numeric(14,2) AS vat,
       (si.vat_amount = 0 OR abs(si.vat_amount - round(si.amount * 0.15, 2)) <= 0.05) AS rate_ok
  FROM sales_invoices si JOIN clients c ON c.id = si.client_id WHERE si.status = 'APPROVED'
UNION ALL
SELECT 'EXPENSE', e.id, e.expense_no, e.expense_date, COALESCE(s.name, e.payee), s.vat_number,
       CASE WHEN e.vat_amount = 0 THEN 'PURCH_NOVAT' WHEN s.vat_number IS NOT NULL THEN 'PURCH_DEDUCT' ELSE 'PURCH_VERIFY' END,
       e.amount, e.vat_amount, (e.vat_amount = 0 OR abs(e.vat_amount - round(e.amount * 0.15, 2)) <= 0.05)
  FROM expenses e LEFT JOIN suppliers s ON s.id = e.supplier_id WHERE e.status = 'APPROVED'
UNION ALL
SELECT 'PURCHASE', p.id, p.purchase_no, p.invoice_date, s.name, s.vat_number,
       CASE WHEN t.vat_amount = 0 THEN 'PURCH_NOVAT' WHEN s.vat_number IS NOT NULL THEN 'PURCH_DEDUCT' ELSE 'PURCH_VERIFY' END,
       t.net_before_vat, t.vat_amount, (t.vat_amount = 0 OR abs(t.vat_amount - round(t.net_before_vat * 0.15, 2)) <= 0.05)
  FROM purchase_invoices p JOIN v_purchase_totals t ON t.purchase_invoice_id = p.id JOIN suppliers s ON s.id = p.supplier_id
 WHERE p.status = 'APPROVED';

-- Totals of a period by bucket.
CREATE OR REPLACE FUNCTION fn_vat_figures(p_from date, p_to date)
RETURNS TABLE (bucket text, amount numeric, vat numeric, docs bigint, rate_flags bigint) AS $$
    SELECT bucket, COALESCE(sum(amount), 0), COALESCE(sum(vat), 0), count(*), count(*) FILTER (WHERE NOT rate_ok)
      FROM v_vat_documents WHERE doc_date BETWEEN p_from AND p_to GROUP BY bucket
$$ LANGUAGE sql STABLE;

CREATE SEQUENCE IF NOT EXISTS seq_vat_return_no;
CREATE TABLE IF NOT EXISTS vat_returns (
    id                  bigserial PRIMARY KEY,
    return_no           text NOT NULL UNIQUE DEFAULT fn_doc_number('VR', 'seq_vat_return_no'),
    period_start        date NOT NULL,
    period_end          date NOT NULL,
    -- From the books when approved (frozen).
    sys_output_vat      numeric(14,2),
    sys_input_vat       numeric(14,2),
    sys_verify_vat      numeric(14,2),
    -- As filed with ZATCA.
    filed_output_vat    numeric(14,2) CHECK (filed_output_vat >= 0),
    filed_input_vat     numeric(14,2) CHECK (filed_input_vat >= 0),
    include_verify      boolean NOT NULL DEFAULT false,
    verify_reason       text,
    notes               text,
    attachment_id       bigint REFERENCES studio_assets(id),
    status              text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    approved_by         bigint REFERENCES users(id),
    approved_at         timestamptz,
    -- Payment to ZATCA (one, after approval).
    paid_date           date,
    paid_account_id     bigint REFERENCES payment_accounts(id),
    paid_amount         numeric(14,2) CHECK (paid_amount > 0),
    paid_reference      text,
    paid_by             bigint REFERENCES users(id),
    created_by          bigint REFERENCES users(id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (period_end >= period_start),
    CHECK (NOT include_verify OR NULLIF(btrim(verify_reason), '') IS NOT NULL),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL
                                    AND filed_output_vat IS NOT NULL AND filed_input_vat IS NOT NULL)),
    CHECK ((paid_date IS NULL) = (paid_account_id IS NULL) AND (paid_date IS NULL) = (paid_amount IS NULL) AND (paid_date IS NULL) = (paid_by IS NULL))
);

CREATE OR REPLACE FUNCTION fn_vat_return_guard() RETURNS trigger AS $$
DECLARE
    f record;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' OR NEW.paid_date IS NOT NULL THEN
            RAISE EXCEPTION 'RROKA_SALES_TRANSITION: a document starts as draft' USING ERRCODE = 'P0001';
        END IF;
    ELSIF OLD.status <> 'DRAFT' THEN
        -- Approved: only the one payment may be added, then nothing changes.
        IF OLD.status = 'APPROVED' AND OLD.paid_date IS NULL AND NEW.paid_date IS NOT NULL
           AND (to_jsonb(NEW) - 'paid_date' - 'paid_account_id' - 'paid_amount' - 'paid_reference' - 'paid_by' - 'updated_at')
             = (to_jsonb(OLD) - 'paid_date' - 'paid_account_id' - 'paid_amount' - 'paid_reference' - 'paid_by' - 'updated_at') THEN
            IF NEW.paid_amount > NEW.filed_output_vat - NEW.filed_input_vat THEN
                RAISE EXCEPTION 'RROKA_VAT_PAYMENT_EXCEEDS: the payment exceeds the VAT due' USING ERRCODE = 'P0001';
            END IF;
            IF NEW.paid_date < NEW.period_end THEN
                RAISE EXCEPTION 'RROKA_VAT_PAYMENT_DATE: the VAT is paid after the period ends' USING ERRCODE = 'P0001';
            END IF;
            IF NOT (SELECT is_active FROM payment_accounts WHERE id = NEW.paid_account_id) THEN
                RAISE EXCEPTION 'RROKA_PAYMENT_ACCOUNT_INACTIVE' USING ERRCODE = 'P0001';
            END IF;
            RETURN NEW;
        END IF;
        IF (to_jsonb(NEW) - 'updated_at') = (to_jsonb(OLD) - 'updated_at') THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'RROKA_SALES_LOCKED: VAT return % is %', OLD.return_no, OLD.status USING ERRCODE = 'P0001';
    ELSIF NEW.paid_date IS NOT NULL THEN
        RAISE EXCEPTION 'RROKA_VAT_PAYMENT_DATE: a return is paid after it is approved' USING ERRCODE = 'P0001';
    END IF;

    IF NEW.status = 'APPROVED' THEN
        IF EXISTS (SELECT 1 FROM vat_returns WHERE status = 'APPROVED' AND id <> NEW.id
                    AND daterange(period_start, period_end, '[]') && daterange(NEW.period_start, NEW.period_end, '[]')) THEN
            RAISE EXCEPTION 'RROKA_VAT_RETURN_OVERLAP: another approved return covers part of this period' USING ERRCODE = 'P0001';
        END IF;
        SELECT COALESCE(sum(vat) FILTER (WHERE bucket IN ('SALES_STD', 'SALES_ZERO', 'SALES_CREDIT')), 0) AS output,
               COALESCE(sum(vat) FILTER (WHERE bucket = 'PURCH_DEDUCT'), 0) AS input,
               COALESCE(sum(vat) FILTER (WHERE bucket = 'PURCH_VERIFY'), 0) AS verify
          INTO f FROM fn_vat_figures(NEW.period_start, NEW.period_end);
        NEW.sys_output_vat := f.output;
        NEW.sys_input_vat := f.input + CASE WHEN NEW.include_verify THEN f.verify ELSE 0 END;
        NEW.sys_verify_vat := f.verify;
        -- Filing closes the period for the documents of the return.
        UPDATE accounting_settings SET vat_locked_until = GREATEST(COALESCE(vat_locked_until, NEW.period_end), NEW.period_end) WHERE id = 1;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_vat_return_guard ON vat_returns;
CREATE TRIGGER trg_vat_return_guard BEFORE INSERT OR UPDATE ON vat_returns FOR EACH ROW EXECUTE FUNCTION fn_vat_return_guard();
DO $$
BEGIN
    DROP TRIGGER IF EXISTS trg_audit_vat_returns ON vat_returns;
    CREATE TRIGGER trg_audit_vat_returns AFTER INSERT OR UPDATE OR DELETE ON vat_returns FOR EACH ROW EXECUTE FUNCTION fn_audit();
    DROP TRIGGER IF EXISTS trg_touch_vat_returns ON vat_returns;
    CREATE TRIGGER trg_touch_vat_returns BEFORE UPDATE ON vat_returns FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at();
    DROP TRIGGER IF EXISTS trg_delete_draft_only ON vat_returns;
    CREATE TRIGGER trg_delete_draft_only BEFORE DELETE ON vat_returns FOR EACH ROW EXECUTE FUNCTION fn_delete_draft_only();
    DROP TRIGGER IF EXISTS trg_audit_vat_lock_events ON vat_lock_events;
    CREATE TRIGGER trg_audit_vat_lock_events AFTER INSERT OR UPDATE OR DELETE ON vat_lock_events FOR EACH ROW EXECUTE FUNCTION fn_audit();
END $$;

ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_source_type_check;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_source_type_check CHECK (source_type IN (
    'MANUAL', 'OPENING', 'REVERSAL', 'EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER',
    'VAT_RETURN', 'VAT_PAYMENT'));
ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_document_source;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_document_source CHECK (
    source_type NOT IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER', 'VAT_RETURN', 'VAT_PAYMENT') OR source_id IS NOT NULL);
DROP INDEX IF EXISTS ux_journal_entries_document;
CREATE UNIQUE INDEX ux_journal_entries_document ON journal_entries (source_type, source_id)
    WHERE source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER', 'VAT_RETURN', 'VAT_PAYMENT');
ALTER TABLE posting_exclusions DROP CONSTRAINT IF EXISTS posting_exclusions_source_type_check;
ALTER TABLE posting_exclusions ADD CONSTRAINT posting_exclusions_source_type_check CHECK (
    source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER', 'VAT_RETURN', 'VAT_PAYMENT'));

CREATE OR REPLACE FUNCTION fn_is_demo_document(p_type text, p_id bigint) RETURNS boolean AS $$
    SELECT EXISTS (SELECT 1 FROM demo_records WHERE row_id = p_id AND table_name = CASE p_type
        WHEN 'EXPENSE' THEN 'expenses' WHEN 'PURCHASE' THEN 'purchase_invoices'
        WHEN 'TRANSFER' THEN 'treasury_transfers' WHEN 'STOCK' THEN 'stock_movements'
        WHEN 'SALE' THEN 'sales_invoices' WHEN 'RECEIPT' THEN 'customer_receipts' WHEN 'PROJECT_CLOSE' THEN 'projects'
        WHEN 'SUPPLIER_PAYMENT' THEN 'supplier_payments' WHEN 'OWNER' THEN 'owner_transactions'
        WHEN 'VAT_RETURN' THEN 'vat_returns' WHEN 'VAT_PAYMENT' THEN 'vat_returns' END)
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_posting_gaps() RETURNS TABLE (gap_type text, ref_id bigint, label text) AS $$
    SELECT 'ROLE'::text, NULL::bigint, r
      FROM unnest(ARRAY['INVENTORY', 'WIP', 'INPUT_VAT', 'PAYABLE', 'INVENTORY_ADJUSTMENT', 'RECEIVABLE', 'SALES', 'OUTPUT_VAT', 'COST_OF_SALES',
                        'OWNER_CURRENT', 'VAT_PAYABLE']) AS r
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
    IF ((TG_OP <> 'INSERT' AND OLD.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER', 'VAT_RETURN', 'VAT_PAYMENT'))
        OR (TG_OP <> 'DELETE' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER', 'VAT_RETURN', 'VAT_PAYMENT')))
       AND COALESCE(current_setting('rroka.auto_posting', true), '') <> 'on' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_AUTO: entries of documents are created by approving the document' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER', 'VAT_RETURN', 'VAT_PAYMENT') AND fn_is_demo_document(NEW.source_type, NEW.source_id) THEN
        RAISE EXCEPTION 'RROKA_POSTING_DEMO: demo data is never posted to the books' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP <> 'DELETE' AND NEW.reverses_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM journal_entries WHERE id = NEW.reverses_id AND source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER', 'VAT_RETURN', 'VAT_PAYMENT')) THEN
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
    pr  projects%ROWTYPE;
    v_wip bigint;
    sp  supplier_payments%ROWTYPE;
    ow  owner_transactions%ROWTYPE;
    vr  vat_returns%ROWTYPE;
BEGIN
    CASE p_type
    WHEN 'EXPENSE' THEN
        SELECT * INTO e FROM expenses WHERE id = p_id;
        IF e.status IS DISTINCT FROM 'APPROVED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: expense %', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT * INTO pa FROM payment_accounts WHERE id = e.payment_account_id;
        IF e.payment_method NOT IN ('CREDIT', 'OWNER') AND pa.account_id IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_MAPPING: payment account "%" has no ledger account', pa.name USING ERRCODE = 'P0001';
        END IF;
        SELECT account_id, name INTO v_cat, v_name FROM expense_categories WHERE id = e.category_id;
        IF e.project_id IS NULL AND v_cat IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_MAPPING: expense category "%" has no ledger account', v_name USING ERRCODE = 'P0001';
        END IF;
        RETURN fn_journal_auto_insert('EXPENSE', e.id, e.expense_date, e.expense_no || ' — ' || e.description, e.expense_no,
            COALESCE(p_user, e.approved_by), jsonb_build_array(
                jsonb_build_object('account_id', CASE WHEN e.project_id IS NULL THEN v_cat ELSE fn_project_cost_account(e.project_id) END,
                                   'debit', e.amount, 'description', e.description, 'project_id', e.project_id),
                jsonb_build_object('account_id', CASE WHEN e.vat_amount > 0 THEN fn_role_account('INPUT_VAT') END,
                                   'debit', e.vat_amount),
                -- On credit: owed to the supplier; otherwise paid from the cash box, bank or custody.
                CASE WHEN e.payment_method = 'CREDIT' THEN
                    jsonb_build_object('account_id', fn_role_account('PAYABLE'), 'credit', e.amount + e.vat_amount,
                                       'description', e.reference, 'partner_type', 'SUPPLIER', 'partner_id', e.supplier_id)
                WHEN e.payment_method = 'OWNER' THEN
                    -- Paid by the owner from their own money: owed to the owner.
                    jsonb_build_object('account_id', fn_role_account('OWNER_CURRENT'), 'credit', e.amount + e.vat_amount,
                                       'description', e.description)
                ELSE
                    jsonb_build_object('account_id', pa.account_id, 'credit', e.amount + e.vat_amount, 'description', pa.name,
                                       'partner_type', CASE WHEN pa.kind = 'CUSTODY' THEN 'EMPLOYEE' END,
                                       'partner_id', CASE WHEN pa.kind = 'CUSTODY' THEN pa.employee_id END)
                END));

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
        v_other := CASE WHEN m.movement_type IN ('ISSUE', 'RETURN') THEN fn_project_cost_account(m.project_id) ELSE fn_role_account('INVENTORY_ADJUSTMENT') END;
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
    WHEN 'PROJECT_CLOSE' THEN
        -- Delivery: the project's cost leaves work in progress for the cost of
        -- delivered projects, dated the delivery day, so revenue meets its cost.
        SELECT * INTO pr FROM projects WHERE id = p_id;
        IF pr.status IS DISTINCT FROM 'COMPLETED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: project % is not delivered', p_id USING ERRCODE = 'P0001';
        END IF;
        v_wip := fn_role_account('WIP');
        SELECT COALESCE(sum(net), 0) INTO v_val FROM v_ledger_lines WHERE account_id = v_wip AND project_id = p_id;
        RETURN fn_journal_auto_insert('PROJECT_CLOSE', pr.id, (pr.completed_at AT TIME ZONE 'Asia/Riyadh')::date,
            pr.project_no || ' — تسليم المشروع ونقل تكلفته — ' || pr.title, pr.project_no,
            COALESCE(p_user, NULLIF(current_setting('rroka.user_id', true), '')::bigint, pr.created_by), jsonb_build_array(
                jsonb_build_object('account_id', fn_role_account('COST_OF_SALES'), 'debit', GREATEST(v_val, 0), 'credit', GREATEST(-v_val, 0),
                                   'description', pr.title, 'project_id', pr.id),
                jsonb_build_object('account_id', v_wip, 'debit', GREATEST(-v_val, 0), 'credit', GREATEST(v_val, 0),
                                   'description', pr.title, 'project_id', pr.id)));
    WHEN 'SUPPLIER_PAYMENT' THEN
        SELECT * INTO sp FROM supplier_payments WHERE id = p_id;
        IF sp.status IS DISTINCT FROM 'APPROVED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: supplier payment %', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT * INTO pa FROM payment_accounts WHERE id = sp.payment_account_id;
        IF pa.account_id IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_MAPPING: payment account "%" has no ledger account', pa.name USING ERRCODE = 'P0001';
        END IF;
        SELECT name INTO v_name FROM suppliers WHERE id = sp.supplier_id;
        RETURN fn_journal_auto_insert('SUPPLIER_PAYMENT', sp.id, sp.payment_date, sp.payment_no || ' — ' || v_name, sp.payment_no,
            COALESCE(p_user, sp.approved_by), jsonb_build_array(
                jsonb_build_object('account_id', fn_role_account('PAYABLE'), 'debit', sp.amount,
                                   'description', COALESCE(sp.reference, sp.payment_no), 'partner_type', 'SUPPLIER', 'partner_id', sp.supplier_id),
                jsonb_build_object('account_id', pa.account_id, 'credit', sp.amount, 'description', pa.name,
                                   'partner_type', CASE WHEN pa.kind = 'CUSTODY' THEN 'EMPLOYEE' END,
                                   'partner_id', CASE WHEN pa.kind = 'CUSTODY' THEN pa.employee_id END)));
    WHEN 'OWNER' THEN
        SELECT * INTO ow FROM owner_transactions WHERE id = p_id;
        IF ow.status IS DISTINCT FROM 'APPROVED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: owner transaction %', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT * INTO pa FROM payment_accounts WHERE id = ow.payment_account_id;
        IF pa.account_id IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_MAPPING: payment account "%" has no ledger account', pa.name USING ERRCODE = 'P0001';
        END IF;
        v_sign := CASE ow.kind WHEN 'FUNDING' THEN 1 ELSE -1 END;
        RETURN fn_journal_auto_insert('OWNER', ow.id, ow.txn_date,
            ow.txn_no || ' — ' || CASE ow.kind WHEN 'FUNDING' THEN 'تمويل من المالك' ELSE 'سحب للمالك' END || ' — ' || ow.description, ow.txn_no,
            COALESCE(p_user, ow.approved_by), jsonb_build_array(
                jsonb_build_object('account_id', pa.account_id, 'debit', GREATEST(v_sign, 0) * ow.amount, 'credit', GREATEST(-v_sign, 0) * ow.amount,
                                   'description', pa.name,
                                   'partner_type', CASE WHEN pa.kind = 'CUSTODY' THEN 'EMPLOYEE' END,
                                   'partner_id', CASE WHEN pa.kind = 'CUSTODY' THEN pa.employee_id END),
                jsonb_build_object('account_id', fn_role_account('OWNER_CURRENT'), 'debit', GREATEST(-v_sign, 0) * ow.amount,
                                   'credit', GREATEST(v_sign, 0) * ow.amount, 'description', ow.description)));
    WHEN 'VAT_RETURN' THEN
        -- The return as filed: output and input VAT close into the amount due to
        -- (or refundable from) ZATCA, dated the last day of the period.
        SELECT * INTO vr FROM vat_returns WHERE id = p_id;
        IF vr.status IS DISTINCT FROM 'APPROVED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: VAT return %', p_id USING ERRCODE = 'P0001';
        END IF;
        v_val := vr.filed_output_vat - vr.filed_input_vat;
        RETURN fn_journal_auto_insert('VAT_RETURN', vr.id, vr.period_end,
            vr.return_no || ' — إقرار ضريبة القيمة المضافة ' || vr.period_start || ' → ' || vr.period_end, vr.return_no,
            COALESCE(p_user, vr.approved_by), jsonb_build_array(
                jsonb_build_object('account_id', fn_role_account('OUTPUT_VAT'), 'debit', vr.filed_output_vat),
                jsonb_build_object('account_id', fn_role_account('INPUT_VAT'), 'credit', vr.filed_input_vat),
                jsonb_build_object('account_id', fn_role_account('VAT_PAYABLE'), 'debit', GREATEST(-v_val, 0), 'credit', GREATEST(v_val, 0))));

    WHEN 'VAT_PAYMENT' THEN
        SELECT * INTO vr FROM vat_returns WHERE id = p_id;
        IF vr.paid_date IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: VAT return % is not paid', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT * INTO pa FROM payment_accounts WHERE id = vr.paid_account_id;
        IF pa.account_id IS NULL THEN
            RAISE EXCEPTION 'RROKA_POSTING_MAPPING: payment account "%" has no ledger account', pa.name USING ERRCODE = 'P0001';
        END IF;
        RETURN fn_journal_auto_insert('VAT_PAYMENT', vr.id, vr.paid_date, vr.return_no || ' — سداد ضريبة القيمة المضافة للهيئة', vr.return_no,
            COALESCE(p_user, vr.paid_by), jsonb_build_array(
                jsonb_build_object('account_id', fn_role_account('VAT_PAYABLE'), 'debit', vr.paid_amount),
                jsonb_build_object('account_id', pa.account_id, 'credit', vr.paid_amount, 'description', pa.name,
                                   'partner_type', CASE WHEN pa.kind = 'CUSTODY' THEN 'EMPLOYEE' END,
                                   'partner_id', CASE WHEN pa.kind = 'CUSTODY' THEN pa.employee_id END)));
    ELSE
        RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: unknown document type %', p_type USING ERRCODE = 'P0001';
    END CASE;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_vat_return_post_gl ON vat_returns;
CREATE TRIGGER trg_vat_return_post_gl AFTER UPDATE OF status ON vat_returns
    FOR EACH ROW EXECUTE FUNCTION fn_auto_post_document('VAT_RETURN', 'period_end');

-- The payment to ZATCA posts when it is recorded (auto-posting on).
CREATE OR REPLACE FUNCTION fn_vat_payment_post() RETURNS trigger AS $$
DECLARE
    s accounting_settings%ROWTYPE;
BEGIN
    SELECT * INTO s FROM accounting_settings WHERE id = 1;
    IF COALESCE(s.auto_posting, false) AND NEW.paid_date >= s.books_start AND NOT fn_is_demo_document('VAT_PAYMENT', NEW.id) THEN
        PERFORM fn_post_document('VAT_PAYMENT', NEW.id, NULL);
    END IF;
    RETURN NULL;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_vat_payment_post_gl ON vat_returns;
CREATE TRIGGER trg_vat_payment_post_gl AFTER UPDATE OF paid_date ON vat_returns FOR EACH ROW
    WHEN (OLD.paid_date IS NULL AND NEW.paid_date IS NOT NULL) EXECUTE FUNCTION fn_vat_payment_post();

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
    UNION ALL
    -- A delivered project whose work in progress was not moved to cost (amount = what is left in WIP).
    SELECT 'PROJECT_CLOSE', p.id, p.project_no, (p.completed_at AT TIME ZONE 'Asia/Riyadh')::date, p.title, NULL,
           COALESCE((SELECT sum(l.net) FROM v_ledger_lines l JOIN accounts a ON a.id = l.account_id
                      WHERE a.system_role = 'WIP' AND l.project_id = p.id), 0)::numeric(14,2)
      FROM projects p WHERE p.status = 'COMPLETED'
    UNION ALL
    SELECT 'SUPPLIER_PAYMENT', sp.id, sp.payment_no, sp.payment_date, s.name, NULL, sp.amount
      FROM supplier_payments sp JOIN suppliers s ON s.id = sp.supplier_id WHERE sp.status = 'APPROVED'
    UNION ALL
    SELECT 'OWNER', o.id, o.txn_no, o.txn_date, o.description, NULL, o.amount
      FROM owner_transactions o WHERE o.status = 'APPROVED'
    UNION ALL
    SELECT 'VAT_RETURN', v.id, v.return_no, v.period_end, v.period_start || ' → ' || v.period_end, NULL,
           GREATEST(v.filed_output_vat, v.filed_input_vat)
      FROM vat_returns v WHERE v.status = 'APPROVED'
    UNION ALL
    SELECT 'VAT_PAYMENT', v.id, v.return_no, v.paid_date, v.period_start || ' → ' || v.period_end, NULL, v.paid_amount
      FROM vat_returns v WHERE v.paid_date IS NOT NULL
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
