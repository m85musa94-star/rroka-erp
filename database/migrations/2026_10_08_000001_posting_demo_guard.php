<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Demo records never reach the books: no automatic entry, no backlog line, and a
 * manual posting is refused — so the demo tool can still remove them (posted entries
 * are permanent). Same definitions are in rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_is_demo_document(p_type text, p_id bigint) RETURNS boolean AS $$
    SELECT EXISTS (SELECT 1 FROM demo_records WHERE row_id = p_id AND table_name = CASE p_type
        WHEN 'EXPENSE' THEN 'expenses' WHEN 'PURCHASE' THEN 'purchase_invoices'
        WHEN 'TRANSFER' THEN 'treasury_transfers' WHEN 'STOCK' THEN 'stock_movements' END)
$$ LANGUAGE sql STABLE;

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

-- Demo payment accounts and categories do not block switching auto-posting on.
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
SQL);
    }
};
