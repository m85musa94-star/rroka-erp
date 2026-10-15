<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner transactions, expenses paid by the owner, and partner/owner rules on journal lines. Same SQL is at the end of rroka_schema.sql. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Owner transactions and controls on manual entries (2026-10-15, owner
-- approval). Funding / withdrawals are documents; an expense may be paid
-- by the owner from their own money (method OWNER: Cr owner current).
-- The owner account takes no manual entry (opening and reversals aside);
-- receivables / payables lines always name their client / supplier.
-- ---------------------------------------------------------------------
ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_payment_method_check;
ALTER TABLE expenses ADD CONSTRAINT expenses_payment_method_check CHECK (payment_method IN ('CASH', 'BANK', 'CARD', 'PETTY_CASH', 'CREDIT', 'OWNER'));

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

    IF NEW.payment_method = 'OWNER' THEN
        IF NEW.payment_account_id IS NOT NULL THEN
            RAISE EXCEPTION 'RROKA_EXPENSE_OWNER: paid by the owner = no cash box or bank' USING ERRCODE = 'P0001';
        END IF;
        NEW.paid_by_employee_id := NULL;
        RETURN NEW;
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

CREATE SEQUENCE IF NOT EXISTS seq_owner_txn_no;
CREATE TABLE IF NOT EXISTS owner_transactions (
    id                 bigserial PRIMARY KEY,
    txn_no             text NOT NULL UNIQUE DEFAULT fn_doc_number('OW', 'seq_owner_txn_no'),
    kind               text NOT NULL CHECK (kind IN ('FUNDING', 'WITHDRAWAL')),
    txn_date           date NOT NULL,
    payment_account_id bigint NOT NULL REFERENCES payment_accounts(id),
    amount             numeric(14,2) NOT NULL CHECK (amount > 0),
    description        text NOT NULL CHECK (btrim(description) <> ''),
    reference          text,
    attachment_id      bigint REFERENCES studio_assets(id),
    status             text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    approved_by        bigint REFERENCES users(id),
    approved_at        timestamptz,
    created_by         bigint REFERENCES users(id),
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);

CREATE OR REPLACE FUNCTION fn_owner_transaction_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' THEN
            RAISE EXCEPTION 'RROKA_SALES_TRANSITION: a document starts as draft' USING ERRCODE = 'P0001';
        END IF;
    ELSIF OLD.status <> 'DRAFT' THEN
        IF (to_jsonb(NEW) - 'updated_at') = (to_jsonb(OLD) - 'updated_at') THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'RROKA_SALES_LOCKED: owner transaction % is %', OLD.id, OLD.status USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'APPROVED' AND NOT (SELECT is_active FROM payment_accounts WHERE id = NEW.payment_account_id) THEN
        RAISE EXCEPTION 'RROKA_PAYMENT_ACCOUNT_INACTIVE' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_owner_transaction_guard ON owner_transactions;
CREATE TRIGGER trg_owner_transaction_guard BEFORE INSERT OR UPDATE ON owner_transactions
    FOR EACH ROW EXECUTE FUNCTION fn_owner_transaction_guard();
DO $$
BEGIN
    DROP TRIGGER IF EXISTS trg_audit_owner_transactions ON owner_transactions;
    CREATE TRIGGER trg_audit_owner_transactions AFTER INSERT OR UPDATE OR DELETE ON owner_transactions FOR EACH ROW EXECUTE FUNCTION fn_audit();
    DROP TRIGGER IF EXISTS trg_touch_owner_transactions ON owner_transactions;
    CREATE TRIGGER trg_touch_owner_transactions BEFORE UPDATE ON owner_transactions FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at();
    DROP TRIGGER IF EXISTS trg_delete_draft_only ON owner_transactions;
    CREATE TRIGGER trg_delete_draft_only BEFORE DELETE ON owner_transactions FOR EACH ROW EXECUTE FUNCTION fn_delete_draft_only();
END $$;

-- The owner's statement from the documents: cash in, expenses paid for the
-- workshop, withdrawals (the books hold the same in the owner account).
CREATE OR REPLACE VIEW v_owner_statement AS
SELECT o.txn_date AS line_date, 'OWNER'::text AS source, o.id AS source_id, o.txn_no AS doc_no, o.description,
       CASE o.kind WHEN 'FUNDING' THEN o.amount ELSE 0 END::numeric(14,2) AS credit,
       CASE o.kind WHEN 'WITHDRAWAL' THEN o.amount ELSE 0 END::numeric(14,2) AS debit, o.kind
  FROM owner_transactions o WHERE o.status = 'APPROVED'
UNION ALL
SELECT e.expense_date, 'EXPENSE', e.id, e.expense_no, e.description, (e.amount + e.vat_amount)::numeric(14,2), 0, 'PAID_EXPENSE'
  FROM expenses e WHERE e.status = 'APPROVED' AND e.payment_method = 'OWNER';

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
    -- Every amount on receivables / payables belongs to a named client / supplier.
    IF v_acc.detail_type = 'RECEIVABLE' AND (NEW.partner_type IS DISTINCT FROM 'CLIENT' OR NEW.partner_id IS NULL) THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_PARTNER: a line on % needs the client', v_acc.code USING ERRCODE = 'P0001';
    END IF;
    IF v_acc.detail_type = 'PAYABLE' AND (NEW.partner_type IS DISTINCT FROM 'SUPPLIER' OR NEW.partner_id IS NULL) THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_PARTNER: a line on % needs the supplier', v_acc.code USING ERRCODE = 'P0001';
    END IF;
    IF (NEW.partner_type = 'CLIENT' AND NOT EXISTS (SELECT 1 FROM clients WHERE id = NEW.partner_id))
       OR (NEW.partner_type = 'SUPPLIER' AND NOT EXISTS (SELECT 1 FROM suppliers WHERE id = NEW.partner_id))
       OR (NEW.partner_type = 'EMPLOYEE' AND NOT EXISTS (SELECT 1 FROM workers WHERE id = NEW.partner_id)) THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_PARTNER: unknown % %', NEW.partner_type, NEW.partner_id USING ERRCODE = 'P0001';
    END IF;
    -- The owner's account moves only through owner documents (and the opening entry).
    IF v_acc.system_role = 'OWNER_CURRENT'
       AND (SELECT source_type FROM journal_entries WHERE id = NEW.entry_id) = 'MANUAL' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_OWNER_MANUAL: the owner account moves through the owner documents' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_source_type_check;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_source_type_check CHECK (source_type IN (
    'MANUAL', 'OPENING', 'REVERSAL', 'EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER'));
ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_document_source;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_document_source CHECK (
    source_type NOT IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER') OR source_id IS NOT NULL);
DROP INDEX IF EXISTS ux_journal_entries_document;
CREATE UNIQUE INDEX ux_journal_entries_document ON journal_entries (source_type, source_id)
    WHERE source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER');
ALTER TABLE posting_exclusions DROP CONSTRAINT IF EXISTS posting_exclusions_source_type_check;
ALTER TABLE posting_exclusions ADD CONSTRAINT posting_exclusions_source_type_check CHECK (
    source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER'));

CREATE OR REPLACE FUNCTION fn_is_demo_document(p_type text, p_id bigint) RETURNS boolean AS $$
    SELECT EXISTS (SELECT 1 FROM demo_records WHERE row_id = p_id AND table_name = CASE p_type
        WHEN 'EXPENSE' THEN 'expenses' WHEN 'PURCHASE' THEN 'purchase_invoices'
        WHEN 'TRANSFER' THEN 'treasury_transfers' WHEN 'STOCK' THEN 'stock_movements'
        WHEN 'SALE' THEN 'sales_invoices' WHEN 'RECEIPT' THEN 'customer_receipts' WHEN 'PROJECT_CLOSE' THEN 'projects'
        WHEN 'SUPPLIER_PAYMENT' THEN 'supplier_payments' WHEN 'OWNER' THEN 'owner_transactions' END)
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_posting_gaps() RETURNS TABLE (gap_type text, ref_id bigint, label text) AS $$
    SELECT 'ROLE'::text, NULL::bigint, r
      FROM unnest(ARRAY['INVENTORY', 'WIP', 'INPUT_VAT', 'PAYABLE', 'INVENTORY_ADJUSTMENT', 'RECEIVABLE', 'SALES', 'OUTPUT_VAT', 'COST_OF_SALES', 'OWNER_CURRENT']) AS r
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
    IF ((TG_OP <> 'INSERT' AND OLD.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER'))
        OR (TG_OP <> 'DELETE' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER')))
       AND COALESCE(current_setting('rroka.auto_posting', true), '') <> 'on' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_AUTO: entries of documents are created by approving the document' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER') AND fn_is_demo_document(NEW.source_type, NEW.source_id) THEN
        RAISE EXCEPTION 'RROKA_POSTING_DEMO: demo data is never posted to the books' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP <> 'DELETE' AND NEW.reverses_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM journal_entries WHERE id = NEW.reverses_id AND source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE', 'SUPPLIER_PAYMENT', 'OWNER')) THEN
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
    ELSE
        RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: unknown document type %', p_type USING ERRCODE = 'P0001';
    END CASE;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_owner_transaction_post_gl ON owner_transactions;
CREATE TRIGGER trg_owner_transaction_post_gl AFTER UPDATE OF status ON owner_transactions
    FOR EACH ROW EXECUTE FUNCTION fn_auto_post_document('OWNER', 'txn_date');

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
