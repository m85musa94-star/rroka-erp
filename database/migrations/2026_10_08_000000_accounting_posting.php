<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Automatic posting (accounting plan step أ): mappings from payment accounts and expense
 * categories to the chart, the auto-posting switch, one posted entry per approved
 * document (expense, purchase invoice, treasury transfer, stock movement), exclusions
 * and the backlog view. Same SQL is at the end of rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
    UNION ALL
    SELECT 'EXPENSE_CATEGORY', id, name FROM expense_categories WHERE is_active AND account_id IS NULL
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

-- Automatic entries are written only by fn_journal_auto_insert (which raises a
-- transaction-local flag) and are never reversed by hand: the document is the source.
CREATE OR REPLACE FUNCTION fn_journal_entry_auto() RETURNS trigger AS $$
BEGIN
    IF ((TG_OP <> 'INSERT' AND OLD.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK'))
        OR (TG_OP <> 'DELETE' AND NEW.source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER', 'STOCK')))
       AND COALESCE(current_setting('rroka.auto_posting', true), '') <> 'on' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_AUTO: entries of documents are created by approving the document' USING ERRCODE = 'P0001';
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
            'SM-' || m.id || ' ' || m.movement_type || ' — ' || v_name || ' × ' || trim_scale(m.quantity), 'SM-' || m.id,
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
    IF NOT COALESCE(s.auto_posting, false) THEN
        RETURN NULL;
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
   AND NOT EXISTS (SELECT 1 FROM posting_exclusions x WHERE x.source_type = d.source_type AND x.source_id = d.source_id);

DO $$
BEGIN
    DROP TRIGGER IF EXISTS trg_audit_posting_exclusions ON posting_exclusions;
    CREATE TRIGGER trg_audit_posting_exclusions AFTER INSERT OR UPDATE OR DELETE ON posting_exclusions
        FOR EACH ROW EXECUTE FUNCTION fn_audit();
END $$;
SQL);
    }
};
