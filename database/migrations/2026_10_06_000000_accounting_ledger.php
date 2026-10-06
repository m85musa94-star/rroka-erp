<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accounting phase 1 (governing rule 1 changed 2026-10-06): chart of accounts,
 * fiscal periods and the journal with its controls. Same SQL is at the end of
 * rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Accounting, phase 1 (2026-10-06): chart of accounts, fiscal periods and
-- the journal. Governing rule 1 was formally changed: the books are kept
-- here; Daftra only stamps the tax invoice. Controls enforced here:
-- a posted entry is final (corrections by a mirror reversal only), every
-- entry balances, numbering is gap-free per year and given at posting,
-- nothing posts before the books start or into a closed period, and only
-- leaf (postable) active accounts take lines.
-- ---------------------------------------------------------------------

-- One row: the date the books start (owner decision 2026-10-06: 2026-01-01).
CREATE TABLE IF NOT EXISTS accounting_settings (
    id           smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    books_start  date,
    updated_at   timestamptz NOT NULL DEFAULT now()
);
INSERT INTO accounting_settings (id, books_start) VALUES (1, DATE '2026-01-01') ON CONFLICT (id) DO NOTHING;

CREATE TABLE IF NOT EXISTS accounts (
    id           bigserial PRIMARY KEY,
    code         text NOT NULL UNIQUE CHECK (code ~ '^[0-9]{1,12}$'),
    name         text NOT NULL CHECK (btrim(name) <> ''),
    name_en      text,
    account_type text NOT NULL CHECK (account_type IN ('ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE')),
    parent_id    bigint REFERENCES accounts(id),
    is_postable  boolean NOT NULL DEFAULT true,
    -- Accounts the system posts to automatically (one account per role).
    system_role  text UNIQUE CHECK (system_role IN ('CASH', 'BANK', 'CUSTODY', 'RECEIVABLE', 'INVENTORY', 'WIP', 'INPUT_VAT',
                                                    'PAYABLE', 'OUTPUT_VAT', 'CUSTOMER_ADVANCES', 'CAPITAL', 'OWNER_CURRENT',
                                                    'RETAINED_EARNINGS', 'SALES', 'SALES_DISCOUNT', 'COST_OF_SALES',
                                                    'FIXED_ASSETS', 'ACCUMULATED_DEPRECIATION', 'DEPRECIATION')),
    is_active    boolean NOT NULL DEFAULT true,
    notes        text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CHECK (parent_id IS DISTINCT FROM id)
);
CREATE INDEX IF NOT EXISTS ix_accounts_parent ON accounts (parent_id);

CREATE TABLE IF NOT EXISTS fiscal_periods (
    id            bigserial PRIMARY KEY,
    period_start  date NOT NULL UNIQUE CHECK (extract(day FROM period_start) = 1),
    period_end    date GENERATED ALWAYS AS ((period_start + interval '1 month' - interval '1 day')::date) STORED,
    status        text NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN', 'CLOSED')),
    closed_by     bigint REFERENCES users(id),
    closed_at     timestamptz,
    reopen_reason text,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'CLOSED' OR (closed_by IS NOT NULL AND closed_at IS NOT NULL))
);

-- Gap-free numbering: the counter row is locked by the posting transaction,
-- so a rolled-back posting never consumes a number.
CREATE TABLE IF NOT EXISTS journal_sequences (
    fiscal_year  integer PRIMARY KEY,
    last_no      integer NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS journal_entries (
    id           bigserial PRIMARY KEY,
    entry_no     text UNIQUE,
    fiscal_year  integer,
    seq_no       integer,
    entry_date   date NOT NULL,
    description  text NOT NULL CHECK (btrim(description) <> ''),
    source_type  text NOT NULL DEFAULT 'MANUAL' CHECK (source_type IN ('MANUAL', 'OPENING', 'REVERSAL')),
    source_id    bigint,
    reference    text,
    status       text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'POSTED')),
    reverses_id  bigint UNIQUE REFERENCES journal_entries(id),
    created_by   bigint NOT NULL REFERENCES users(id),
    posted_by    bigint REFERENCES users(id),
    posted_at    timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (fiscal_year, seq_no),
    CHECK ((source_type = 'REVERSAL') = (reverses_id IS NOT NULL)),
    CHECK (status <> 'POSTED' OR (entry_no IS NOT NULL AND posted_by IS NOT NULL AND posted_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_journal_entries_date ON journal_entries (entry_date);
CREATE INDEX IF NOT EXISTS ix_journal_entries_source ON journal_entries (source_type, source_id);

CREATE TABLE IF NOT EXISTS journal_lines (
    id             bigserial PRIMARY KEY,
    entry_id       bigint NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
    account_id     bigint NOT NULL REFERENCES accounts(id),
    debit          numeric(14,2) NOT NULL DEFAULT 0 CHECK (debit >= 0),
    credit         numeric(14,2) NOT NULL DEFAULT 0 CHECK (credit >= 0),
    description    text,
    project_id     bigint REFERENCES projects(id),
    cost_center_id bigint REFERENCES cost_centers(id),
    partner_type   text CHECK (partner_type IN ('CLIENT', 'SUPPLIER', 'EMPLOYEE')),
    partner_id     bigint,
    CHECK ((debit > 0) <> (credit > 0)),
    CHECK ((partner_type IS NULL) = (partner_id IS NULL))
);
CREATE INDEX IF NOT EXISTS ix_journal_lines_entry ON journal_lines (entry_id);
CREATE INDEX IF NOT EXISTS ix_journal_lines_account ON journal_lines (account_id);
CREATE INDEX IF NOT EXISTS ix_journal_lines_project ON journal_lines (project_id) WHERE project_id IS NOT NULL;

-- Chart rules: a parent is a non-postable account of the same type; no
-- cycles; an account with lines keeps its type and stays postable; a
-- postable account cannot have children.
CREATE OR REPLACE FUNCTION fn_account_guard() RETURNS trigger AS $$
DECLARE
    v_parent accounts%ROWTYPE;
    v_used boolean;
BEGIN
    IF NEW.parent_id IS NOT NULL THEN
        SELECT * INTO v_parent FROM accounts WHERE id = NEW.parent_id;
        IF v_parent.is_postable THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_PARENT: parent % is postable; only a group account can have children', v_parent.code USING ERRCODE = 'P0001';
        END IF;
        IF v_parent.account_type <> NEW.account_type THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_PARENT: parent % is %, child is %', v_parent.code, v_parent.account_type, NEW.account_type USING ERRCODE = 'P0001';
        END IF;
        IF TG_OP = 'UPDATE' AND EXISTS (
            WITH RECURSIVE up AS (SELECT id, parent_id FROM accounts WHERE id = NEW.parent_id
                                  UNION ALL SELECT a.id, a.parent_id FROM accounts a JOIN up ON a.id = up.parent_id)
            SELECT 1 FROM up WHERE id = NEW.id) THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_PARENT: account % cannot be under its own descendant', NEW.code USING ERRCODE = 'P0001';
        END IF;
    END IF;
    IF NEW.system_role IS NOT NULL AND NOT NEW.is_postable THEN
        RAISE EXCEPTION 'RROKA_ACCOUNT_ROLE: a system role needs a postable account' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF NEW.is_postable AND NOT OLD.is_postable AND EXISTS (SELECT 1 FROM accounts WHERE parent_id = NEW.id) THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_PARENT: account % has children and cannot be postable', NEW.code USING ERRCODE = 'P0001';
        END IF;
        IF NEW.account_type <> OLD.account_type AND EXISTS (SELECT 1 FROM accounts WHERE parent_id = NEW.id) THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_PARENT: account % has children; its type cannot change', NEW.code USING ERRCODE = 'P0001';
        END IF;
        v_used := EXISTS (SELECT 1 FROM journal_lines WHERE account_id = NEW.id);
        IF v_used AND (NEW.account_type <> OLD.account_type OR NOT NEW.is_postable OR NEW.code <> OLD.code) THEN
            RAISE EXCEPTION 'RROKA_ACCOUNT_USED: account % has entries; its code, type and postability are fixed', OLD.code USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_account_guard ON accounts;
CREATE TRIGGER trg_account_guard BEFORE INSERT OR UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION fn_account_guard();

-- Lines change only while their entry is a draft, and only on active leaf accounts.
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
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_journal_line_guard ON journal_lines;
CREATE TRIGGER trg_journal_line_guard BEFORE INSERT OR UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION fn_journal_line_guard();

-- Entries: a draft can change or be deleted; posting checks the books and
-- gives the number; a posted entry never changes. A reversal must mirror
-- its original exactly (account, project, cost centre, partner).
CREATE OR REPLACE FUNCTION fn_journal_entry_guard() RETURNS trigger AS $$
DECLARE
    v_start date;
    v_dr numeric;
    v_cr numeric;
    v_n integer;
    v_period text;
    v_orig journal_entries%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status = 'POSTED' THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_LOCKED: entry % is posted; correct it with a reversal', OLD.entry_no USING ERRCODE = 'P0001';
        END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.status = 'POSTED' THEN
        RAISE EXCEPTION 'RROKA_JOURNAL_LOCKED: entry % is posted; correct it with a reversal', OLD.entry_no USING ERRCODE = 'P0001';
    END IF;
    IF NEW.reverses_id IS NOT NULL THEN
        SELECT * INTO v_orig FROM journal_entries WHERE id = NEW.reverses_id;
        IF v_orig.status <> 'POSTED' THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_REVERSAL: only a posted entry can be reversed' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.entry_date < v_orig.entry_date THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_REVERSAL: a reversal cannot be dated before the entry it reverses' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    IF NEW.status = 'POSTED' THEN
        IF TG_OP = 'INSERT' THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_UNBALANCED: an entry is created as a draft and posted after its lines' USING ERRCODE = 'P0001';
        END IF;
        SELECT books_start INTO v_start FROM accounting_settings WHERE id = 1;
        IF v_start IS NULL THEN
            RAISE EXCEPTION 'RROKA_BOOKS_NOT_STARTED: the books start date is not set' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.entry_date < v_start THEN
            RAISE EXCEPTION 'RROKA_BOOKS_NOT_STARTED: % is before the books start (%)', NEW.entry_date, v_start USING ERRCODE = 'P0001';
        END IF;
        SELECT status INTO v_period FROM fiscal_periods WHERE period_start = date_trunc('month', NEW.entry_date)::date;
        IF v_period = 'CLOSED' THEN
            RAISE EXCEPTION 'RROKA_PERIOD_CLOSED: the period of % is closed', NEW.entry_date USING ERRCODE = 'P0001';
        END IF;
        SELECT COALESCE(sum(debit), 0), COALESCE(sum(credit), 0), count(*) INTO v_dr, v_cr, v_n FROM journal_lines WHERE entry_id = NEW.id;
        IF v_n < 2 OR v_dr <> v_cr OR v_dr = 0 THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_UNBALANCED: debit % / credit % over % lines', v_dr, v_cr, v_n USING ERRCODE = 'P0001';
        END IF;
        IF NEW.reverses_id IS NOT NULL AND EXISTS (
            (SELECT account_id, project_id, cost_center_id, partner_type, partner_id, sum(debit - credit) AS net
               FROM journal_lines WHERE entry_id = NEW.id GROUP BY 1, 2, 3, 4, 5
             EXCEPT
             SELECT account_id, project_id, cost_center_id, partner_type, partner_id, sum(credit - debit)
               FROM journal_lines WHERE entry_id = NEW.reverses_id GROUP BY 1, 2, 3, 4, 5)
            UNION ALL
            (SELECT account_id, project_id, cost_center_id, partner_type, partner_id, sum(credit - debit)
               FROM journal_lines WHERE entry_id = NEW.reverses_id GROUP BY 1, 2, 3, 4, 5
             EXCEPT
             SELECT account_id, project_id, cost_center_id, partner_type, partner_id, sum(debit - credit)
               FROM journal_lines WHERE entry_id = NEW.id GROUP BY 1, 2, 3, 4, 5)) THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_REVERSAL: a reversal must mirror the original entry exactly' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.posted_by IS NULL THEN
            RAISE EXCEPTION 'RROKA_JOURNAL_UNBALANCED: posting needs the posting user' USING ERRCODE = 'P0001';
        END IF;
        INSERT INTO fiscal_periods (period_start) VALUES (date_trunc('month', NEW.entry_date)::date) ON CONFLICT (period_start) DO NOTHING;
        NEW.fiscal_year := extract(year FROM NEW.entry_date)::integer;
        INSERT INTO journal_sequences (fiscal_year, last_no) VALUES (NEW.fiscal_year, 1)
            ON CONFLICT (fiscal_year) DO UPDATE SET last_no = journal_sequences.last_no + 1
            RETURNING last_no INTO NEW.seq_no;
        NEW.entry_no := format('JV-%s-%s', NEW.fiscal_year, lpad(NEW.seq_no::text, 5, '0'));
        NEW.posted_at := now();
    ELSE
        NEW.entry_no := NULL;
        NEW.fiscal_year := NULL;
        NEW.seq_no := NULL;
        NEW.posted_by := NULL;
        NEW.posted_at := NULL;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_journal_entry_guard ON journal_entries;
CREATE TRIGGER trg_journal_entry_guard BEFORE INSERT OR UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION fn_journal_entry_guard();

-- Periods: closing needs no draft dated in the period and every earlier
-- period closed; reopening needs a written reason and no later closed period.
CREATE OR REPLACE FUNCTION fn_fiscal_period_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'RROKA_PERIOD_CLOSED: a fiscal period cannot be deleted' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.period_start <> OLD.period_start THEN
        RAISE EXCEPTION 'RROKA_PERIOD_CLOSED: a period keeps its dates' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'CLOSED' AND OLD.status = 'OPEN' THEN
        -- period_end is a generated column: not yet computed inside a BEFORE trigger.
        IF EXISTS (SELECT 1 FROM journal_entries WHERE status = 'DRAFT'
                      AND entry_date >= NEW.period_start AND entry_date < NEW.period_start + interval '1 month') THEN
            RAISE EXCEPTION 'RROKA_PERIOD_DRAFTS: post or delete the drafts dated in the period before closing it' USING ERRCODE = 'P0001';
        END IF;
        IF EXISTS (SELECT 1 FROM fiscal_periods WHERE period_start < NEW.period_start AND status = 'OPEN') THEN
            RAISE EXCEPTION 'RROKA_PERIOD_ORDER: close the earlier periods first' USING ERRCODE = 'P0001';
        END IF;
        NEW.reopen_reason := NULL;
    ELSIF NEW.status = 'OPEN' AND OLD.status = 'CLOSED' THEN
        IF NEW.reopen_reason IS NULL OR btrim(NEW.reopen_reason) = '' THEN
            RAISE EXCEPTION 'RROKA_PERIOD_REOPEN: reopening a closed period needs a written reason' USING ERRCODE = 'P0001';
        END IF;
        IF EXISTS (SELECT 1 FROM fiscal_periods WHERE period_start > NEW.period_start AND status = 'CLOSED') THEN
            RAISE EXCEPTION 'RROKA_PERIOD_ORDER: reopen the later closed periods first' USING ERRCODE = 'P0001';
        END IF;
        NEW.closed_by := NULL;
        NEW.closed_at := NULL;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_fiscal_period_guard ON fiscal_periods;
CREATE TRIGGER trg_fiscal_period_guard BEFORE UPDATE OR DELETE ON fiscal_periods FOR EACH ROW EXECUTE FUNCTION fn_fiscal_period_guard();

-- A new period may not be created inside a closed range (it would let a
-- posting slip between closed months).
CREATE OR REPLACE FUNCTION fn_fiscal_period_insert() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM fiscal_periods WHERE period_start > NEW.period_start AND status = 'CLOSED') THEN
        RAISE EXCEPTION 'RROKA_PERIOD_CLOSED: % falls before a closed period', NEW.period_start USING ERRCODE = 'P0001';
    END IF;
    NEW.status := 'OPEN';
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_fiscal_period_insert ON fiscal_periods;
CREATE TRIGGER trg_fiscal_period_insert BEFORE INSERT ON fiscal_periods FOR EACH ROW EXECUTE FUNCTION fn_fiscal_period_insert();

-- Posted lines with their entry and account: the single source of every
-- ledger, trial balance and statement.
CREATE OR REPLACE VIEW v_ledger_lines AS
SELECT l.id AS line_id, e.id AS entry_id, e.entry_no, e.entry_date, e.fiscal_year, e.description AS entry_description,
       e.source_type, e.source_id, e.reference, e.reverses_id, e.posted_by, e.created_by,
       a.id AS account_id, a.code AS account_code, a.name AS account_name, a.account_type,
       l.debit, l.credit, l.debit - l.credit AS net, l.description, l.project_id, l.cost_center_id, l.partner_type, l.partner_id
  FROM journal_lines l
  JOIN journal_entries e ON e.id = l.entry_id AND e.status = 'POSTED'
  JOIN accounts a ON a.id = l.account_id;

INSERT INTO permissions (code, description) VALUES
    ('accounting.view', 'عرض الحسابات والقيود والتقارير المالية'),
    ('accounting.manage', 'إدارة دليل الحسابات وإعداد مسودات القيود'),
    ('accounting.post', 'ترحيل القيود وعكسها'),
    ('accounting.close', 'إقفال الفترات المالية وإعادة فتحها')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND p.code IN ('accounting.view', 'accounting.manage', 'accounting.post', 'accounting.close')
ON CONFLICT DO NOTHING;

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['accounting_settings', 'accounts', 'fiscal_periods', 'journal_entries', 'journal_lines'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
    END LOOP;
    FOREACH t IN ARRAY ARRAY['accounting_settings', 'accounts', 'fiscal_periods', 'journal_entries'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;
SQL);
    }

    public function down(): void
    {
        // Ledger data is never dropped by a rollback.
    }
};
