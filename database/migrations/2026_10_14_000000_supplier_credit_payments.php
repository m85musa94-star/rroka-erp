<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Supplier invoices on credit (expense method CREDIT) and supplier payments, posted to payables. Same SQL is at the end of rroka_schema.sql. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Suppliers on credit (2026-10-14, owner approval). A supplier's invoice not
-- paid at once is an expense with the method CREDIT: no cash box or bank,
-- the supplier and their invoice number required (entered once), and on
-- approval Dr the project (or category) / Cr payables in the supplier's name.
-- A supplier payment (from a cash box, bank or custody) settles it:
-- Dr payables / Cr the account paid from. Balance per supplier = approved
-- purchase invoices + credit expenses − payments.
-- ---------------------------------------------------------------------
ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_payment_method_check;
ALTER TABLE expenses ADD CONSTRAINT expenses_payment_method_check CHECK (payment_method IN ('CASH', 'BANK', 'CARD', 'PETTY_CASH', 'CREDIT'));
-- A supplier's invoice number is entered once per supplier (a cancelled draft frees it).
CREATE UNIQUE INDEX IF NOT EXISTS ux_expenses_credit_invoice ON expenses (supplier_id, btrim(reference))
    WHERE payment_method = 'CREDIT' AND status <> 'CANCELLED';

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

    IF NEW.payment_method = 'CREDIT' THEN
        IF NEW.payment_account_id IS NOT NULL OR NEW.supplier_id IS NULL OR NULLIF(btrim(NEW.reference), '') IS NULL THEN
            RAISE EXCEPTION 'RROKA_EXPENSE_CREDIT: on credit = a registered supplier and their invoice number, no cash box or bank' USING ERRCODE = 'P0001';
        END IF;
        NEW.paid_by_employee_id := NULL;
        RETURN NEW;
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

CREATE SEQUENCE IF NOT EXISTS seq_supplier_payment_no;

CREATE TABLE IF NOT EXISTS supplier_payments (
    id                  bigserial PRIMARY KEY,
    payment_no          text NOT NULL UNIQUE DEFAULT fn_doc_number('SP', 'seq_supplier_payment_no'),
    payment_date        date NOT NULL,
    supplier_id         bigint NOT NULL REFERENCES suppliers(id),
    payment_account_id  bigint NOT NULL REFERENCES payment_accounts(id),
    expense_id          bigint REFERENCES expenses(id),
    purchase_invoice_id bigint REFERENCES purchase_invoices(id),
    amount              numeric(14,2) NOT NULL CHECK (amount > 0),
    reference           text,
    description         text,
    attachment_id       bigint REFERENCES studio_assets(id),
    status              text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    approved_by         bigint REFERENCES users(id),
    approved_at         timestamptz,
    created_by          bigint REFERENCES users(id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (num_nonnulls(expense_id, purchase_invoice_id) <= 1),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_supplier_payments_supplier ON supplier_payments (supplier_id);

-- Draft → approved or cancelled, then final. The invoice paid is the same
-- supplier's, on credit and not cancelled; approved payments never exceed it.
CREATE OR REPLACE FUNCTION fn_supplier_payment_guard() RETURNS trigger AS $$
DECLARE
    v_supplier bigint;
    v_total    numeric;
    v_paid     numeric;
    v_ok       boolean;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' THEN
            RAISE EXCEPTION 'RROKA_SALES_TRANSITION: a document starts as draft' USING ERRCODE = 'P0001';
        END IF;
    ELSIF OLD.status <> 'DRAFT' THEN
        IF (to_jsonb(NEW) - 'updated_at') = (to_jsonb(OLD) - 'updated_at') THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'RROKA_SALES_LOCKED: supplier payment % is %', OLD.id, OLD.status USING ERRCODE = 'P0001';
    END IF;
    IF NEW.expense_id IS NOT NULL THEN
        SELECT supplier_id, amount + vat_amount, payment_method = 'CREDIT' AND status <> 'CANCELLED'
          INTO v_supplier, v_total, v_ok FROM expenses WHERE id = NEW.expense_id;
    ELSIF NEW.purchase_invoice_id IS NOT NULL THEN
        SELECT p.supplier_id, t.total, p.status <> 'CANCELLED' INTO v_supplier, v_total, v_ok
          FROM purchase_invoices p JOIN v_purchase_totals t ON t.purchase_invoice_id = p.id WHERE p.id = NEW.purchase_invoice_id;
    END IF;
    IF (NEW.expense_id IS NOT NULL OR NEW.purchase_invoice_id IS NOT NULL)
       AND (v_supplier IS DISTINCT FROM NEW.supplier_id OR NOT v_ok) THEN
        RAISE EXCEPTION 'RROKA_SUPPLIER_PAYMENT_INVOICE: the invoice paid is an open credit invoice of the same supplier' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'APPROVED' THEN
        IF NOT (SELECT is_active FROM payment_accounts WHERE id = NEW.payment_account_id) THEN
            RAISE EXCEPTION 'RROKA_PAYMENT_ACCOUNT_INACTIVE' USING ERRCODE = 'P0001';
        END IF;
        IF v_total IS NOT NULL THEN
            SELECT COALESCE(sum(amount), 0) INTO v_paid FROM supplier_payments
             WHERE status = 'APPROVED' AND id <> NEW.id
               AND (expense_id = NEW.expense_id OR purchase_invoice_id = NEW.purchase_invoice_id);
            IF v_paid + NEW.amount > v_total THEN
                RAISE EXCEPTION 'RROKA_SUPPLIER_PAYMENT_EXCEEDS: payments would exceed the invoice' USING ERRCODE = 'P0001';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_supplier_payment_guard ON supplier_payments;
CREATE TRIGGER trg_supplier_payment_guard BEFORE INSERT OR UPDATE ON supplier_payments
    FOR EACH ROW EXECUTE FUNCTION fn_supplier_payment_guard();

DO $$
BEGIN
    DROP TRIGGER IF EXISTS trg_audit_supplier_payments ON supplier_payments;
    CREATE TRIGGER trg_audit_supplier_payments AFTER INSERT OR UPDATE OR DELETE ON supplier_payments FOR EACH ROW EXECUTE FUNCTION fn_audit();
    DROP TRIGGER IF EXISTS trg_touch_supplier_payments ON supplier_payments;
    CREATE TRIGGER trg_touch_supplier_payments BEFORE UPDATE ON supplier_payments FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at();
    DROP TRIGGER IF EXISTS trg_delete_draft_only ON supplier_payments;
    CREATE TRIGGER trg_delete_draft_only BEFORE DELETE ON supplier_payments FOR EACH ROW EXECUTE FUNCTION fn_delete_draft_only();
END $$;

-- What each supplier is owed, from the approved documents (the books hold the
-- same in payables, by supplier).
CREATE OR REPLACE VIEW v_supplier_balances AS
WITH m AS (
    SELECT p.supplier_id, t.total AS invoiced, 0::numeric AS paid
      FROM purchase_invoices p JOIN v_purchase_totals t ON t.purchase_invoice_id = p.id WHERE p.status = 'APPROVED'
    UNION ALL
    SELECT supplier_id, amount + vat_amount, 0 FROM expenses WHERE status = 'APPROVED' AND payment_method = 'CREDIT'
    UNION ALL
    SELECT supplier_id, 0, amount FROM supplier_payments WHERE status = 'APPROVED'
)
SELECT supplier_id, sum(invoiced)::numeric(16,2) AS invoiced, sum(paid)::numeric(16,2) AS paid,
       sum(invoiced - paid)::numeric(16,2) AS balance
  FROM m GROUP BY supplier_id;

ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_source_type_check;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_source_type_check CHECK (source_type IN (
    'MANUAL', 'OPENING', 'REVERSAL', 'EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT'));
ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_document_source;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_document_source CHECK (
    source_type NOT IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT') OR source_id IS NOT NULL);
DROP INDEX IF EXISTS ux_journal_entries_document;
CREATE UNIQUE INDEX ux_journal_entries_document ON journal_entries (source_type, source_id)
    WHERE source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT');
ALTER TABLE posting_exclusions DROP CONSTRAINT IF EXISTS posting_exclusions_source_type_check;
ALTER TABLE posting_exclusions ADD CONSTRAINT posting_exclusions_source_type_check CHECK (
    source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT'));

CREATE OR REPLACE FUNCTION fn_is_demo_document(p_type text, p_id bigint) RETURNS boolean AS $$
    SELECT EXISTS (SELECT 1 FROM demo_records WHERE row_id = p_id AND table_name = CASE p_type
        WHEN 'EXPENSE' THEN 'expenses' WHEN 'PURCHASE' THEN 'purchase_invoices'
        WHEN 'TRANSFER' THEN 'treasury_transfers' WHEN 'STOCK' THEN 'stock_movements'
        WHEN 'SALE' THEN 'sales_invoices' WHEN 'RECEIPT' THEN 'customer_receipts' WHEN 'PROJECT_CLOSE' THEN 'projects'
        WHEN 'SUPPLIER_PAYMENT' THEN 'supplier_payments' END)
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_journal_entry_auto() RETURNS trigger AS $$
BEGIN
    IF ((TG_OP <> 'INSERT' AND OLD.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT'))
        OR (TG_OP <> 'DELETE' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT')))
       AND COALESCE(current_setting('rroka.auto_posting', true), '') <> 'on' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_AUTO: entries of documents are created by approving the document' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT') AND fn_is_demo_document(NEW.source_type, NEW.source_id) THEN
        RAISE EXCEPTION 'RROKA_POSTING_DEMO: demo data is never posted to the books' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP <> 'DELETE' AND NEW.reverses_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM journal_entries WHERE id = NEW.reverses_id AND source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT')) THEN
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
BEGIN
    CASE p_type
    WHEN 'EXPENSE' THEN
        SELECT * INTO e FROM expenses WHERE id = p_id;
        IF e.status IS DISTINCT FROM 'APPROVED' THEN
            RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: expense %', p_id USING ERRCODE = 'P0001';
        END IF;
        SELECT * INTO pa FROM payment_accounts WHERE id = e.payment_account_id;
        IF e.payment_method <> 'CREDIT' AND pa.account_id IS NULL THEN
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
    ELSE
        RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: unknown document type %', p_type USING ERRCODE = 'P0001';
    END CASE;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_supplier_payment_post_gl ON supplier_payments;
CREATE TRIGGER trg_supplier_payment_post_gl AFTER UPDATE OF status ON supplier_payments
    FOR EACH ROW EXECUTE FUNCTION fn_auto_post_document('SUPPLIER_PAYMENT', 'payment_date');

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
