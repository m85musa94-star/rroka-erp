<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Treasury: cash boxes, bank accounts and employee custody; expenses say where they
 * were paid from; internal transfers (custody issue/return). Same SQL is at the end
 * of rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Treasury (trial approved 2026-10-04, see PROJECT_CONTEXT): where money was
-- paid from. Cash boxes, bank accounts and employee custody (petty cash) are
-- recorded here; each maps to a Daftra treasury, where cash and bank balances
-- and the bank reconciliation live. No journal entries here.
-- Only custody has a balance here, because every custody flow (issue,
-- spending, return) is recorded in this system. Cash and bank also receive
-- customer collections, which are recorded in Daftra, so a balance computed
-- here would be incomplete — it is never shown.
-- ---------------------------------------------------------------------

CREATE SEQUENCE IF NOT EXISTS seq_transfer_no;

INSERT INTO permissions (code, description) VALUES
    ('treasury.view', 'عرض الخزائن والحسابات البنكية والعهد'),
    ('treasury.manage', 'إدارة الخزائن والحسابات وإدخال التحويلات والعهد'),
    ('treasury.approve', 'اعتماد التحويلات وصرف العهد وإرجاعها')
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS payment_accounts (
    id                  bigserial PRIMARY KEY,
    name                text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    kind                text NOT NULL CHECK (kind IN ('CASH', 'BANK', 'CUSTODY')),
    bank_name           text,
    iban                text CHECK (iban IS NULL OR iban ~ '^SA[0-9]{2}[0-9A-Z]{20}$'),
    employee_id         bigint REFERENCES workers(id),     -- the custodian (custody only)
    custody_limit       numeric(14,2) CHECK (custody_limit IS NULL OR custody_limit > 0),
    daftra_treasury_ref text,                               -- the matching Daftra treasury; filled when the link is verified
    notes               text,
    is_active           boolean NOT NULL DEFAULT true,
    created_by          bigint REFERENCES users(id),
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'CUSTODY') = (employee_id IS NOT NULL)),
    CHECK (kind = 'CUSTODY' OR custody_limit IS NULL),
    CHECK (kind = 'BANK' OR (bank_name IS NULL AND iban IS NULL))
);
-- One open custody per employee: a second one would split what they must account for.
CREATE UNIQUE INDEX IF NOT EXISTS ux_payment_accounts_one_custody
    ON payment_accounts (employee_id) WHERE kind = 'CUSTODY' AND is_active;

-- Internal transfers (Odoo: internal transfer between journals): issuing custody
-- from the cash box or bank, returning it, depositing cash at the bank, ...
CREATE TABLE IF NOT EXISTS treasury_transfers (
    id                 bigserial PRIMARY KEY,
    transfer_no        text NOT NULL UNIQUE DEFAULT fn_doc_number('TR', 'seq_transfer_no'),
    transfer_date      date NOT NULL,
    from_account_id    bigint NOT NULL REFERENCES payment_accounts(id),
    to_account_id      bigint NOT NULL REFERENCES payment_accounts(id),
    amount             numeric(14,2) NOT NULL CHECK (amount > 0),
    reference          text,
    notes              text,
    status             text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    approved_by        bigint REFERENCES users(id),
    approved_at        timestamptz,
    daftra_transfer_id bigint UNIQUE,
    created_by         bigint REFERENCES users(id),
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (from_account_id <> to_account_id),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_treasury_transfers_from ON treasury_transfers (from_account_id);
CREATE INDEX IF NOT EXISTS ix_treasury_transfers_to ON treasury_transfers (to_account_id);

ALTER TABLE expenses ADD COLUMN IF NOT EXISTS payment_account_id bigint REFERENCES payment_accounts(id);
CREATE INDEX IF NOT EXISTS ix_expenses_payment_account ON expenses (payment_account_id);

-- What the custodian holds: approved transfers in − approved transfers out − approved
-- expenses paid from it (amount + VAT, the money actually paid). Negative means the
-- employee paid from their own pocket and is owed that amount.
CREATE OR REPLACE FUNCTION fn_custody_balance(p_account bigint) RETURNS numeric AS $$
    SELECT COALESCE((SELECT sum(amount) FROM treasury_transfers WHERE to_account_id = p_account AND status = 'APPROVED'), 0)
         - COALESCE((SELECT sum(amount) FROM treasury_transfers WHERE from_account_id = p_account AND status = 'APPROVED'), 0)
         - COALESCE((SELECT sum(amount + vat_amount) FROM expenses WHERE payment_account_id = p_account AND status = 'APPROVED'), 0)
$$ LANGUAGE sql STABLE;

-- Kind and custodian are fixed (a different use is a new account); a custody is
-- closed only once settled to zero.
CREATE OR REPLACE FUNCTION fn_payment_account_guard() RETURNS trigger AS $$
BEGIN
    IF NEW.kind <> OLD.kind OR NEW.employee_id IS DISTINCT FROM OLD.employee_id THEN
        RAISE EXCEPTION 'RROKA_PAYMENT_ACCOUNT_LOCKED: kind and custodian cannot change' USING ERRCODE = 'P0001';
    END IF;
    IF OLD.is_active AND NOT NEW.is_active AND NEW.kind = 'CUSTODY' AND fn_custody_balance(NEW.id) <> 0 THEN
        RAISE EXCEPTION 'RROKA_CUSTODY_NOT_SETTLED: balance is %', fn_custody_balance(NEW.id) USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_payment_account_guard ON payment_accounts;
CREATE TRIGGER trg_payment_account_guard BEFORE UPDATE ON payment_accounts
    FOR EACH ROW EXECUTE FUNCTION fn_payment_account_guard();

CREATE OR REPLACE FUNCTION fn_treasury_transfer_guard() RETURNS trigger AS $$
DECLARE
    v_from payment_accounts%ROWTYPE;
    v_to   payment_accounts%ROWTYPE;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' THEN
            RAISE EXCEPTION 'RROKA_TRANSFER_TRANSITION: a transfer starts as draft' USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'DRAFT' THEN
        IF NEW.status = OLD.status
           AND (to_jsonb(NEW) - 'daftra_transfer_id' - 'updated_at') = (to_jsonb(OLD) - 'daftra_transfer_id' - 'updated_at') THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'RROKA_TRANSFER_LOCKED: % transfer cannot change', OLD.status USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'APPROVED' THEN
        -- Serialise approvals on the same accounts so two cannot both pass a balance check.
        PERFORM 1 FROM payment_accounts WHERE id IN (NEW.from_account_id, NEW.to_account_id) ORDER BY id FOR UPDATE;
        SELECT * INTO v_from FROM payment_accounts WHERE id = NEW.from_account_id;
        SELECT * INTO v_to FROM payment_accounts WHERE id = NEW.to_account_id;
        IF NOT v_from.is_active OR NOT v_to.is_active THEN
            RAISE EXCEPTION 'RROKA_PAYMENT_ACCOUNT_INACTIVE' USING ERRCODE = 'P0001';
        END IF;
        IF v_from.kind = 'CUSTODY' AND fn_custody_balance(v_from.id) < NEW.amount THEN
            RAISE EXCEPTION 'RROKA_CUSTODY_INSUFFICIENT: custody holds %, transfer is %', fn_custody_balance(v_from.id), NEW.amount
                USING ERRCODE = 'P0001';
        END IF;
        IF v_to.kind = 'CUSTODY' AND v_to.custody_limit IS NOT NULL AND fn_custody_balance(v_to.id) + NEW.amount > v_to.custody_limit THEN
            RAISE EXCEPTION 'RROKA_CUSTODY_LIMIT: limit is %, custody would hold %', v_to.custody_limit, fn_custody_balance(v_to.id) + NEW.amount
                USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_treasury_transfer_guard ON treasury_transfers;
CREATE TRIGGER trg_treasury_transfer_guard BEFORE INSERT OR UPDATE ON treasury_transfers
    FOR EACH ROW EXECUTE FUNCTION fn_treasury_transfer_guard();

-- Extends the expense guard above: the payment account must match the payment
-- method (cash box ↔ cash, bank ↔ transfer or card, custody ↔ petty cash, and the
-- custodian is the employee who paid), and an expense is approved only once it
-- says where it was paid from.
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

-- One line per money movement on an account (cancelled documents excluded).
CREATE OR REPLACE VIEW v_payment_account_lines AS
SELECT e.payment_account_id AS account_id, e.expense_date AS line_date, 'EXPENSE'::text AS source, e.id AS source_id,
       e.expense_no AS doc_no, e.description, NULL::bigint AS counterpart_account_id,
       0::numeric(14,2) AS amount_in, (e.amount + e.vat_amount)::numeric(14,2) AS amount_out,
       e.status, e.reference, (e.daftra_expense_id IS NOT NULL) AS in_daftra, e.created_at
  FROM expenses e
 WHERE e.payment_account_id IS NOT NULL AND e.status <> 'CANCELLED'
UNION ALL
SELECT t.to_account_id, t.transfer_date, 'TRANSFER_IN', t.id, t.transfer_no, COALESCE(t.notes, ''), t.from_account_id,
       t.amount, 0, t.status, t.reference, (t.daftra_transfer_id IS NOT NULL), t.created_at
  FROM treasury_transfers t WHERE t.status <> 'CANCELLED'
UNION ALL
SELECT t.from_account_id, t.transfer_date, 'TRANSFER_OUT', t.id, t.transfer_no, COALESCE(t.notes, ''), t.to_account_id,
       0, t.amount, t.status, t.reference, (t.daftra_transfer_id IS NOT NULL), t.created_at
  FROM treasury_transfers t WHERE t.status <> 'CANCELLED';

-- Per account: approved movements recorded here, drafts waiting, what is not yet in
-- Daftra, and — for custody only — the balance the custodian must account for.
CREATE OR REPLACE VIEW v_payment_account_summary AS
SELECT a.id AS account_id,
       COALESCE(sum(l.amount_in) FILTER (WHERE l.status = 'APPROVED'), 0) AS approved_in,
       COALESCE(sum(l.amount_out) FILTER (WHERE l.status = 'APPROVED'), 0) AS approved_out,
       COALESCE(sum(l.amount_out) FILTER (WHERE l.status = 'DRAFT'), 0) AS draft_out,
       count(l.*) FILTER (WHERE l.status = 'DRAFT') AS drafts,
       count(l.*) FILTER (WHERE l.status = 'APPROVED' AND NOT l.in_daftra) AS not_in_daftra,
       CASE WHEN a.kind = 'CUSTODY'
            THEN COALESCE(sum(l.amount_in - l.amount_out) FILTER (WHERE l.status = 'APPROVED'), 0) END AS custody_balance
  FROM payment_accounts a
  LEFT JOIN v_payment_account_lines l ON l.account_id = a.id
 GROUP BY a.id, a.kind;

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['payment_accounts', 'treasury_transfers'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND p.code LIKE 'treasury.%'
ON CONFLICT DO NOTHING;
SQL);
    }
};
