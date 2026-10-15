<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A month does not close with unposted documents or receivables/payables lines without their partner. Same SQL is at the end of rroka_schema.sql. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Closing a month (2026-10-15, owner approval: no period closes with a red
-- exception). After drafts and order (fn_fiscal_period_guard; the trigger name
-- sorts after it): no approved
-- document of the month left unposted (unless excluded with its reason), and
-- no receivables / payables amount of the month without its client / supplier.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_period_close_exceptions() RETURNS trigger AS $$
DECLARE
    v_n int;
BEGIN
    SELECT count(*) INTO v_n FROM v_posting_backlog
     WHERE doc_date >= NEW.period_start AND doc_date < NEW.period_start + interval '1 month';
    IF v_n > 0 THEN
        RAISE EXCEPTION 'RROKA_PERIOD_EXCEPTIONS: % approved document(s) of the month are not posted', v_n USING ERRCODE = 'P0001';
    END IF;
    SELECT count(*) INTO v_n FROM v_ledger_lines l JOIN accounts a ON a.id = l.account_id
     WHERE a.detail_type IN ('RECEIVABLE', 'PAYABLE') AND l.partner_id IS NULL
       AND l.entry_date >= NEW.period_start AND l.entry_date < NEW.period_start + interval '1 month';
    IF v_n > 0 THEN
        RAISE EXCEPTION 'RROKA_PERIOD_EXCEPTIONS: % receivables / payables line(s) of the month have no client / supplier', v_n USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_fiscal_period_guard_exceptions ON fiscal_periods;
CREATE TRIGGER trg_fiscal_period_guard_exceptions BEFORE UPDATE OF status ON fiscal_periods FOR EACH ROW
    WHEN (NEW.status = 'CLOSED' AND OLD.status = 'OPEN') EXECUTE FUNCTION fn_period_close_exceptions();

SQL);
    }
};
