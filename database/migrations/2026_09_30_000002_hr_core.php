<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR core: departments, job positions, the employee file on `workers`, and
 * employee documents. The same SQL lives at the end of rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- HR core (Odoo Employees style): departments, job positions, the employee
-- file (the existing `workers` table IS the employee master, so costing,
-- time logs and installation keep working), and identity/work documents
-- with expiry dates. Personal data: restricted by permission in the app.
-- ---------------------------------------------------------------------

CREATE SEQUENCE IF NOT EXISTS seq_employee_no;

INSERT INTO permissions (code, description) VALUES
    ('hr.view', 'عرض دليل الموظفين'),
    ('hr.manage', 'إدارة ملفات الموظفين ووثائقهم'),
    ('hr.contracts', 'عرض العقود والرواتب وإدارتها'),
    ('hr.attendance', 'تسجيل الحضور والانصراف'),
    ('hr.leave_approve', 'اعتماد الإجازات وأرصدتها')
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS departments (
    id          bigserial PRIMARY KEY,
    name        text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    parent_id   bigint REFERENCES departments(id),
    manager_id  bigint REFERENCES workers(id),
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    CHECK (parent_id IS NULL OR parent_id <> id)
);

CREATE TABLE IF NOT EXISTS job_positions (
    id             bigserial PRIMARY KEY,
    name           text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    department_id  bigint REFERENCES departments(id),
    description    text,
    is_active      boolean NOT NULL DEFAULT true,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE workers ADD COLUMN IF NOT EXISTS employee_no text DEFAULT fn_doc_number('E', 'seq_employee_no');
DO $$ BEGIN
    ALTER TABLE workers ALTER COLUMN employee_no SET NOT NULL;
    ALTER TABLE workers ADD CONSTRAINT workers_employee_no_key UNIQUE (employee_no);
EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL; END $$;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS department_id bigint REFERENCES departments(id);
ALTER TABLE workers ADD COLUMN IF NOT EXISTS job_id bigint REFERENCES job_positions(id);
ALTER TABLE workers ADD COLUMN IF NOT EXISTS manager_id bigint REFERENCES workers(id);
ALTER TABLE workers ADD COLUMN IF NOT EXISTS work_phone text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS mobile text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS work_email text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS nationality text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS id_type text CHECK (id_type IN ('NATIONAL_ID', 'IQAMA', 'PASSPORT'));
ALTER TABLE workers ADD COLUMN IF NOT EXISTS id_number text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS birth_date date;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS gender text CHECK (gender IN ('M', 'F'));
ALTER TABLE workers ADD COLUMN IF NOT EXISTS hire_date date;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS employment_type text CHECK (employment_type IN ('FULL_TIME', 'PART_TIME', 'CONTRACTOR'));
ALTER TABLE workers ADD COLUMN IF NOT EXISTS is_direct_labor boolean NOT NULL DEFAULT true;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS termination_date date;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS termination_reason text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS iban text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS emergency_contact text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS emergency_phone text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS address text;
ALTER TABLE workers ADD COLUMN IF NOT EXISTS notes text;
DO $$ BEGIN
    ALTER TABLE workers ADD CONSTRAINT workers_termination_after_hire
        CHECK (termination_date IS NULL OR hire_date IS NULL OR termination_date >= hire_date);
    ALTER TABLE workers ADD CONSTRAINT workers_not_own_manager CHECK (manager_id IS NULL OR manager_id <> id);
    ALTER TABLE workers ADD CONSTRAINT workers_iban_format CHECK (iban IS NULL OR iban ~ '^SA[0-9]{2}[0-9A-Z]{20}$');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
CREATE UNIQUE INDEX IF NOT EXISTS ux_workers_id_number ON workers (id_type, id_number) WHERE id_number IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_workers_user ON workers (user_id) WHERE user_id IS NOT NULL;

-- Ending employment needs its date and reason; the reporting line has no loops;
-- an employee's number never changes.
CREATE OR REPLACE FUNCTION fn_employee_guard() RETURNS trigger AS $$
DECLARE
    v_cursor bigint;
    v_steps  int := 0;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.employee_no IS DISTINCT FROM OLD.employee_no THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_IMMUTABLE: employee number cannot change' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.is_active AND NOT NEW.is_active
       AND (NEW.termination_date IS NULL OR NULLIF(btrim(NEW.termination_reason), '') IS NULL) THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_TERMINATION: ending employment needs its date and reason' USING ERRCODE = 'P0001';
    END IF;
    v_cursor := NEW.manager_id;
    WHILE v_cursor IS NOT NULL LOOP
        IF v_cursor = NEW.id OR v_steps > 50 THEN
            RAISE EXCEPTION 'RROKA_EMPLOYEE_MANAGER_LOOP: reporting line would loop' USING ERRCODE = 'P0001';
        END IF;
        SELECT manager_id INTO v_cursor FROM workers WHERE id = v_cursor;
        v_steps := v_steps + 1;
    END LOOP;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_employee_guard ON workers;
CREATE TRIGGER trg_employee_guard BEFORE INSERT OR UPDATE ON workers
    FOR EACH ROW EXECUTE FUNCTION fn_employee_guard();

CREATE TABLE IF NOT EXISTS employee_documents (
    id           bigserial PRIMARY KEY,
    employee_id  bigint NOT NULL REFERENCES workers(id) ON DELETE CASCADE,
    doc_type     text NOT NULL CHECK (doc_type IN ('NATIONAL_ID', 'IQAMA', 'PASSPORT', 'WORK_PERMIT', 'HEALTH_CERT', 'DRIVING_LICENSE', 'OTHER')),
    doc_number   text,
    issue_date   date,
    expiry_date  date,
    notes        text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CHECK (expiry_date IS NULL OR issue_date IS NULL OR expiry_date >= issue_date),
    CHECK (doc_type <> 'OTHER' OR NULLIF(btrim(notes), '') IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS ix_employee_documents_expiry ON employee_documents (expiry_date);

-- Hours cannot be logged for someone outside their employment period.
CREATE OR REPLACE FUNCTION fn_employment_period_guard() RETURNS trigger AS $$
DECLARE
    v_hire date;
    v_end  date;
BEGIN
    SELECT hire_date, termination_date INTO v_hire, v_end FROM workers WHERE id = NEW.worker_id;
    IF (v_hire IS NOT NULL AND NEW.work_date < v_hire) OR (v_end IS NOT NULL AND NEW.work_date > v_end) THEN
        RAISE EXCEPTION 'RROKA_EMPLOYEE_NOT_EMPLOYED: % is outside the employment period', NEW.work_date USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_labor_employment_guard ON labor_logs;
CREATE TRIGGER trg_labor_employment_guard BEFORE INSERT OR UPDATE ON labor_logs
    FOR EACH ROW EXECUTE FUNCTION fn_employment_period_guard();
DROP TRIGGER IF EXISTS trg_installation_employment_guard ON installation_workers;
CREATE TRIGGER trg_installation_employment_guard BEFORE INSERT OR UPDATE ON installation_workers
    FOR EACH ROW EXECUTE FUNCTION fn_employment_period_guard();

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['departments', 'job_positions', 'employee_documents'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND p.code LIKE 'hr.%'
ON CONFLICT DO NOTHING;
SQL);
    }
};
