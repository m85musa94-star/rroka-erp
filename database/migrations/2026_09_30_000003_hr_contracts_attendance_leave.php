<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR contracts, attendance and time off. The same SQL lives at the end of rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- HR: contracts, attendance, time off (Odoo Contracts / Attendances / Time Off)
-- Pay figures are entered from the signed contract; nothing is defaulted.
-- No payroll is computed here (GOSI, WPS and postings stay outside the system).
-- ---------------------------------------------------------------------

CREATE SEQUENCE IF NOT EXISTS seq_contract_no;

CREATE TABLE IF NOT EXISTS employee_contracts (
    id                   bigserial PRIMARY KEY,
    contract_no          text NOT NULL UNIQUE DEFAULT fn_doc_number('HC', 'seq_contract_no'),
    employee_id          bigint NOT NULL REFERENCES workers(id),
    contract_type        text NOT NULL CHECK (contract_type IN ('FIXED_TERM', 'INDEFINITE')),
    start_date           date NOT NULL,
    end_date             date,
    basic_salary         numeric(12,2) NOT NULL CHECK (basic_salary > 0),
    housing_allowance    numeric(12,2) NOT NULL CHECK (housing_allowance >= 0),
    transport_allowance  numeric(12,2) NOT NULL CHECK (transport_allowance >= 0),
    other_allowance      numeric(12,2) NOT NULL CHECK (other_allowance >= 0),
    weekly_hours         numeric(5,2) CHECK (weekly_hours > 0 AND weekly_hours <= 168),
    status               text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'RUNNING', 'EXPIRED', 'CANCELLED')),
    notes                text,
    created_by           bigint REFERENCES users(id),
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CHECK (end_date IS NULL OR end_date >= start_date),
    CHECK (contract_type <> 'FIXED_TERM' OR end_date IS NOT NULL)
);
-- One running contract per employee.
CREATE UNIQUE INDEX IF NOT EXISTS ux_contract_one_running ON employee_contracts (employee_id) WHERE status = 'RUNNING';

-- DRAFT -> RUNNING | CANCELLED; RUNNING -> EXPIRED | CANCELLED. Once running,
-- the terms are fixed (a change is a new contract); only closing is allowed.
CREATE OR REPLACE FUNCTION fn_contract_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status NOT IN ('DRAFT', 'RUNNING') THEN
            RAISE EXCEPTION 'RROKA_CONTRACT_TRANSITION: a contract starts as draft or running' USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.employee_id <> OLD.employee_id OR NEW.contract_no <> OLD.contract_no THEN
        RAISE EXCEPTION 'RROKA_CONTRACT_LOCKED: contract owner and number are fixed' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status <> OLD.status AND NOT (
           (OLD.status = 'DRAFT'   AND NEW.status IN ('RUNNING', 'CANCELLED'))
        OR (OLD.status = 'RUNNING' AND NEW.status IN ('EXPIRED', 'CANCELLED'))
    ) THEN
        RAISE EXCEPTION 'RROKA_CONTRACT_TRANSITION: % -> % not allowed', OLD.status, NEW.status USING ERRCODE = 'P0001';
    END IF;
    IF OLD.status <> 'DRAFT' AND (
        (to_jsonb(NEW) - 'status' - 'end_date' - 'notes' - 'updated_at') <> (to_jsonb(OLD) - 'status' - 'end_date' - 'notes' - 'updated_at')
        OR (NEW.end_date IS DISTINCT FROM OLD.end_date AND NEW.status = OLD.status)
    ) THEN
        RAISE EXCEPTION 'RROKA_CONTRACT_LOCKED: terms of a % contract cannot change; create a new contract', OLD.status USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_contract_guard ON employee_contracts;
CREATE TRIGGER trg_contract_guard BEFORE INSERT OR UPDATE ON employee_contracts
    FOR EACH ROW EXECUTE FUNCTION fn_contract_guard();

CREATE OR REPLACE VIEW v_contract_totals AS
SELECT c.id AS contract_id, c.employee_id, c.status,
       c.basic_salary + c.housing_allowance + c.transport_allowance + c.other_allowance AS monthly_gross
FROM employee_contracts c;

-- Time off: types, allocations (granted days), requests.
CREATE TABLE IF NOT EXISTS leave_types (
    id                   bigserial PRIMARY KEY,
    name                 text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    is_paid              boolean NOT NULL,
    requires_allocation  boolean NOT NULL,
    is_active            boolean NOT NULL DEFAULT true,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS leave_allocations (
    id             bigserial PRIMARY KEY,
    employee_id    bigint NOT NULL REFERENCES workers(id),
    leave_type_id  bigint NOT NULL REFERENCES leave_types(id),
    days           numeric(6,2) NOT NULL CHECK (days <> 0),   -- negative = a correcting deduction
    valid_from     date NOT NULL,
    valid_to       date NOT NULL,
    reason         text NOT NULL CHECK (btrim(reason) <> ''),
    approved_by    bigint NOT NULL REFERENCES users(id),
    created_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (valid_to >= valid_from)
);

CREATE TABLE IF NOT EXISTS leave_requests (
    id              bigserial PRIMARY KEY,
    employee_id     bigint NOT NULL REFERENCES workers(id),
    leave_type_id   bigint NOT NULL REFERENCES leave_types(id),
    date_from       date NOT NULL,
    date_to         date NOT NULL,
    days            numeric(6,2) NOT NULL CHECK (days > 0),
    reason          text,
    status          text NOT NULL DEFAULT 'SUBMITTED' CHECK (status IN ('SUBMITTED', 'APPROVED', 'REFUSED', 'CANCELLED')),
    approved_by     bigint REFERENCES users(id),
    approved_at     timestamptz,
    refusal_reason  text,
    created_by      bigint REFERENCES users(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (date_to >= date_from),
    CHECK (days <= date_to - date_from + 1),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)),
    CHECK (status <> 'REFUSED' OR NULLIF(btrim(refusal_reason), '') IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS ix_leave_requests_employee ON leave_requests (employee_id, date_from);

-- Approved days of a type within an allocation window vs granted days.
CREATE OR REPLACE FUNCTION fn_leave_balance(p_employee bigint, p_type bigint, p_on date) RETURNS numeric AS $$
    SELECT COALESCE((SELECT sum(days) FROM leave_allocations
                      WHERE employee_id = p_employee AND leave_type_id = p_type AND p_on BETWEEN valid_from AND valid_to), 0)
         - COALESCE((SELECT sum(r.days) FROM leave_requests r
                      WHERE r.employee_id = p_employee AND r.leave_type_id = p_type AND r.status = 'APPROVED'
                        AND EXISTS (SELECT 1 FROM leave_allocations a
                                     WHERE a.employee_id = p_employee AND a.leave_type_id = p_type
                                       AND p_on BETWEEN a.valid_from AND a.valid_to
                                       AND r.date_from BETWEEN a.valid_from AND a.valid_to)), 0)
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_leave_request_guard() RETURNS trigger AS $$
DECLARE
    v_needs_alloc boolean;
    v_emp_user    bigint;
    v_balance     numeric;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.status <> OLD.status AND NOT (
               (OLD.status = 'SUBMITTED' AND NEW.status IN ('APPROVED', 'REFUSED', 'CANCELLED'))
            OR (OLD.status = 'APPROVED'  AND NEW.status = 'CANCELLED')
        ) THEN
            RAISE EXCEPTION 'RROKA_LEAVE_TRANSITION: % -> % not allowed', OLD.status, NEW.status USING ERRCODE = 'P0001';
        END IF;
        IF OLD.status <> 'SUBMITTED' AND (to_jsonb(NEW) - 'status' - 'updated_at') <> (to_jsonb(OLD) - 'status' - 'updated_at') THEN
            RAISE EXCEPTION 'RROKA_LEAVE_LOCKED: a decided request cannot change' USING ERRCODE = 'P0001';
        END IF;
    ELSIF NEW.status <> 'SUBMITTED' THEN
        RAISE EXCEPTION 'RROKA_LEAVE_TRANSITION: a request starts as submitted' USING ERRCODE = 'P0001';
    END IF;

    IF NEW.status IN ('SUBMITTED', 'APPROVED') AND EXISTS (
        SELECT 1 FROM leave_requests o
         WHERE o.employee_id = NEW.employee_id AND o.id <> NEW.id AND o.status IN ('SUBMITTED', 'APPROVED')
           AND o.date_from <= NEW.date_to AND o.date_to >= NEW.date_from) THEN
        RAISE EXCEPTION 'RROKA_LEAVE_OVERLAP: another request covers these dates' USING ERRCODE = 'P0001';
    END IF;

    IF NEW.status = 'APPROVED' AND (TG_OP = 'INSERT' OR OLD.status <> 'APPROVED') THEN
        -- Segregation of duties: nobody approves their own time off.
        SELECT user_id INTO v_emp_user FROM workers WHERE id = NEW.employee_id;
        IF v_emp_user IS NOT NULL AND v_emp_user = NEW.approved_by THEN
            RAISE EXCEPTION 'RROKA_LEAVE_SELF_APPROVAL' USING ERRCODE = 'P0001';
        END IF;
        SELECT requires_allocation INTO v_needs_alloc FROM leave_types WHERE id = NEW.leave_type_id;
        IF v_needs_alloc THEN
            v_balance := fn_leave_balance(NEW.employee_id, NEW.leave_type_id, NEW.date_from);
            IF NEW.days > v_balance THEN
                RAISE EXCEPTION 'RROKA_LEAVE_BALANCE: balance is %, requested %', v_balance, NEW.days USING ERRCODE = 'P0001';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_leave_request_guard ON leave_requests;
CREATE TRIGGER trg_leave_request_guard BEFORE INSERT OR UPDATE ON leave_requests
    FOR EACH ROW EXECUTE FUNCTION fn_leave_request_guard();

-- Serialize approvals per employee so two concurrent approvals cannot both pass the balance check.
CREATE OR REPLACE FUNCTION fn_leave_lock_employee() RETURNS trigger AS $$
BEGIN
    PERFORM 1 FROM workers WHERE id = NEW.employee_id FOR UPDATE;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_leave_a_lock ON leave_requests;
CREATE TRIGGER trg_leave_a_lock BEFORE INSERT OR UPDATE ON leave_requests
    FOR EACH ROW EXECUTE FUNCTION fn_leave_lock_employee();

-- Allocations are a ledger: a correction is a new (possibly negative) allocation with its reason.
CREATE OR REPLACE FUNCTION fn_allocation_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'RROKA_ALLOCATION_IMMUTABLE: add a new allocation instead' USING ERRCODE = 'P0001';
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_allocation_immutable ON leave_allocations;
CREATE TRIGGER trg_allocation_immutable BEFORE UPDATE OR DELETE ON leave_allocations
    FOR EACH ROW EXECUTE FUNCTION fn_allocation_immutable();

-- Attendance: check-in/out; one open record per employee, no overlaps, max 24h.
CREATE TABLE IF NOT EXISTS attendances (
    id            bigserial PRIMARY KEY,
    employee_id   bigint NOT NULL REFERENCES workers(id),
    check_in      timestamptz NOT NULL,
    check_out     timestamptz,
    worked_hours  numeric(6,2) GENERATED ALWAYS AS (round((extract(epoch FROM (check_out - check_in)) / 3600)::numeric, 2)) STORED,
    notes         text,
    created_by    bigint REFERENCES users(id),
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK (check_out IS NULL OR (check_out > check_in AND check_out - check_in <= interval '24 hours'))
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_attendance_one_open ON attendances (employee_id) WHERE check_out IS NULL;
CREATE INDEX IF NOT EXISTS ix_attendance_employee ON attendances (employee_id, check_in);

CREATE OR REPLACE FUNCTION fn_attendance_guard() RETURNS trigger AS $$
DECLARE
    v_active boolean;
BEGIN
    IF NEW.check_in > now() + interval '5 minutes' OR NEW.check_out > now() + interval '5 minutes' THEN
        RAISE EXCEPTION 'RROKA_ATTENDANCE_FUTURE' USING ERRCODE = 'P0001';
    END IF;
    SELECT is_active INTO v_active FROM workers WHERE id = NEW.employee_id;
    IF NOT v_active THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_NOT_EMPLOYED: employee is not active' USING ERRCODE = 'P0001';
    END IF;
    IF EXISTS (SELECT 1 FROM attendances a WHERE a.employee_id = NEW.employee_id AND a.id <> NEW.id
                  AND a.check_in < COALESCE(NEW.check_out, 'infinity') AND COALESCE(a.check_out, 'infinity') > NEW.check_in) THEN
        RAISE EXCEPTION 'RROKA_ATTENDANCE_OVERLAP' USING ERRCODE = 'P0001';
    END IF;
    IF EXISTS (SELECT 1 FROM leave_requests r WHERE r.employee_id = NEW.employee_id AND r.status = 'APPROVED'
                  AND (NEW.check_in AT TIME ZONE 'Asia/Riyadh')::date BETWEEN r.date_from AND r.date_to) THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_ON_LEAVE' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_attendance_guard ON attendances;
CREATE TRIGGER trg_attendance_guard BEFORE INSERT OR UPDATE ON attendances
    FOR EACH ROW EXECUTE FUNCTION fn_attendance_guard();

-- Production hours cannot be charged for a day the employee is on approved leave.
CREATE OR REPLACE FUNCTION fn_labor_leave_guard() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM leave_requests r WHERE r.employee_id = NEW.worker_id AND r.status = 'APPROVED'
                  AND NEW.work_date BETWEEN r.date_from AND r.date_to) THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_ON_LEAVE' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_labor_leave_guard ON labor_logs;
CREATE TRIGGER trg_labor_leave_guard BEFORE INSERT OR UPDATE ON labor_logs
    FOR EACH ROW EXECUTE FUNCTION fn_labor_leave_guard();
DROP TRIGGER IF EXISTS trg_installation_leave_guard ON installation_workers;
CREATE TRIGGER trg_installation_leave_guard BEFORE INSERT OR UPDATE ON installation_workers
    FOR EACH ROW EXECUTE FUNCTION fn_labor_leave_guard();

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['employee_contracts', 'leave_types', 'leave_allocations', 'leave_requests', 'attendances'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
    END LOOP;
    FOREACH t IN ARRAY ARRAY['employee_contracts', 'leave_types', 'leave_requests', 'attendances'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;
SQL);
    }
};
