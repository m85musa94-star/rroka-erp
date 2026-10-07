<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Documents are deleted only while they are drafts; stock entry descriptions in Arabic. Same SQL is at the end of rroka_schema.sql. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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

-- Entry descriptions of stock movements in Arabic (the owner's working language).
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
SQL);
    }
};
