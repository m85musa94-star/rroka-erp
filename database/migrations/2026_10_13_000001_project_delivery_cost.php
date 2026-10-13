<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Project delivery moves its cost from work in progress to the cost of delivered projects. Same SQL is at the end of rroka_schema.sql. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Project delivery (2026-10-13, owner approval): delivering a project moves
-- its cost from work in progress to the cost of delivered projects (one
-- entry, dated the delivery day); a cost that arrives after delivery goes
-- straight to that cost. Work in progress = the open projects only.
-- ---------------------------------------------------------------------
ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_source_type_check;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_source_type_check CHECK (source_type IN (
    'MANUAL', 'OPENING', 'REVERSAL', 'EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE'));
ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_document_source;
ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_document_source CHECK (
    source_type NOT IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE') OR source_id IS NOT NULL);
DROP INDEX IF EXISTS ux_journal_entries_document;
CREATE UNIQUE INDEX ux_journal_entries_document ON journal_entries (source_type, source_id)
    WHERE source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE');
ALTER TABLE posting_exclusions DROP CONSTRAINT IF EXISTS posting_exclusions_source_type_check;
ALTER TABLE posting_exclusions ADD CONSTRAINT posting_exclusions_source_type_check CHECK (
    source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE'));

CREATE OR REPLACE FUNCTION fn_is_demo_document(p_type text, p_id bigint) RETURNS boolean AS $$
    SELECT EXISTS (SELECT 1 FROM demo_records WHERE row_id = p_id AND table_name = CASE p_type
        WHEN 'EXPENSE' THEN 'expenses' WHEN 'PURCHASE' THEN 'purchase_invoices'
        WHEN 'TRANSFER' THEN 'treasury_transfers' WHEN 'STOCK' THEN 'stock_movements'
        WHEN 'SALE' THEN 'sales_invoices' WHEN 'RECEIPT' THEN 'customer_receipts' WHEN 'PROJECT_CLOSE' THEN 'projects' END)
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_posting_gaps() RETURNS TABLE (gap_type text, ref_id bigint, label text) AS $$
    SELECT 'ROLE'::text, NULL::bigint, r
      FROM unnest(ARRAY['INVENTORY', 'WIP', 'INPUT_VAT', 'PAYABLE', 'INVENTORY_ADJUSTMENT', 'RECEIVABLE', 'SALES', 'OUTPUT_VAT', 'COST_OF_SALES']) AS r
     WHERE NOT EXISTS (SELECT 1 FROM accounts a WHERE a.system_role = r AND a.is_active AND a.is_postable)
    UNION ALL
    SELECT 'PAYMENT_ACCOUNT', id, name FROM payment_accounts WHERE is_active AND account_id IS NULL
       AND id NOT IN (SELECT row_id FROM demo_records WHERE table_name = 'payment_accounts')
    UNION ALL
    SELECT 'EXPENSE_CATEGORY', id, name FROM expense_categories WHERE is_active AND account_id IS NULL
       AND id NOT IN (SELECT row_id FROM demo_records WHERE table_name = 'expense_categories')
$$ LANGUAGE sql STABLE;

-- Where a project's cost is booked: work in progress while open, the cost of
-- delivered projects once delivered.
CREATE OR REPLACE FUNCTION fn_project_cost_account(p_project bigint) RETURNS bigint AS $$
    SELECT fn_role_account(CASE WHEN (SELECT status FROM projects WHERE id = p_project) = 'COMPLETED' THEN 'COST_OF_SALES' ELSE 'WIP' END)
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_journal_entry_auto() RETURNS trigger AS $$
BEGIN
    IF ((TG_OP <> 'INSERT' AND OLD.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE'))
        OR (TG_OP <> 'DELETE' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE')))
       AND COALESCE(current_setting('rroka.auto_posting', true), '') <> 'on' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_AUTO: entries of documents are created by approving the document' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'INSERT' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE') AND fn_is_demo_document(NEW.source_type, NEW.source_id) THEN
        RAISE EXCEPTION 'RROKA_POSTING_DEMO: demo data is never posted to the books' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP <> 'DELETE' AND NEW.reverses_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM journal_entries WHERE id = NEW.reverses_id AND source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK', 'SALE', 'RECEIPT', 'PROJECT_CLOSE')) THEN
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
                jsonb_build_object('account_id', CASE WHEN e.project_id IS NULL THEN v_cat ELSE fn_project_cost_account(e.project_id) END,
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
    ELSE
        RAISE EXCEPTION 'RROKA_POSTING_NOT_APPROVED: unknown document type %', p_type USING ERRCODE = 'P0001';
    END CASE;
END $$ LANGUAGE plpgsql;

-- Delivery posts at once when auto-posting is on (a delivery before the books
-- start belongs to the opening balances).
CREATE OR REPLACE FUNCTION fn_project_close_post() RETURNS trigger AS $$
DECLARE
    s accounting_settings%ROWTYPE;
BEGIN
    SELECT * INTO s FROM accounting_settings WHERE id = 1;
    IF NOT COALESCE(s.auto_posting, false) OR COALESCE(current_setting('rroka.demo', true), '') = 'on'
       OR fn_is_demo_document('PROJECT_CLOSE', NEW.id)
       OR (NEW.completed_at AT TIME ZONE 'Asia/Riyadh')::date < s.books_start THEN
        RETURN NULL;
    END IF;
    PERFORM fn_post_document('PROJECT_CLOSE', NEW.id, NULL);
    RETURN NULL;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_project_close_post_gl ON projects;
CREATE TRIGGER trg_project_close_post_gl AFTER UPDATE OF status ON projects FOR EACH ROW
    WHEN (NEW.status = 'COMPLETED' AND OLD.status IS DISTINCT FROM 'COMPLETED')
    EXECUTE FUNCTION fn_project_close_post();

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
