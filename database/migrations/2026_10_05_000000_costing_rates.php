<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Costing engine phase 1: cost centres, employee and machine cost cards, electricity
 * rate, overhead pools, standard material prices and waste. Same SQL is at the end
 * of rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Costing engine, phase 1 (2026-10-04, see docs/COSTING_ENGINE_PLAN.md):
-- cost centres and the rates the standard cost is built from. Every rate is
-- a numbered version: DRAFT -> APPROVED (frozen) | CANCELLED, with its source,
-- who entered and who approved it. Nothing is defaulted (Zero Assumption):
-- a missing rate stays missing and the cost that needs it shows as incomplete.
-- ---------------------------------------------------------------------

INSERT INTO permissions (code, description) VALUES
    ('cost_rates.approve', 'اعتماد بطاقات التكلفة والمعدلات (تصبح نهائية)')
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS cost_centers (
    id            bigserial PRIMARY KEY,
    code          text NOT NULL UNIQUE CHECK (btrim(code) <> ''),
    name          text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    driver        text NOT NULL CHECK (driver IN ('LABOR_HOURS', 'MACHINE_HOURS')),  -- what absorbs its overhead
    department_id bigint REFERENCES departments(id),
    notes         text,
    is_active     boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE machines ADD COLUMN IF NOT EXISTS cost_center_id bigint REFERENCES cost_centers(id);

-- One guard for every versioned cost record. TG_ARGV[0] lists the columns that
-- identify the series (a worker, a machine, ...; '' = one global series); they are
-- compared case- and space-insensitively.
-- Version numbers follow the series; an approved record is final; approval
-- cannot back-date a series (it would silently re-price work already costed).
CREATE OR REPLACE FUNCTION fn_cost_record_guard() RETURNS trigger AS $$
DECLARE
    v_key    text := TG_ARGV[0];
    v_col    text;
    v_where  text := 'true';
    v_latest date;
BEGIN
    IF v_key <> '' THEN
        FOREACH v_col IN ARRAY string_to_array(v_key, ',') LOOP
            v_where := v_where || format(' AND lower(btrim(%I::text)) IS NOT DISTINCT FROM lower(btrim(%L))', v_col, to_jsonb(NEW) ->> v_col);
        END LOOP;
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' THEN
            RAISE EXCEPTION 'RROKA_COST_RECORD_TRANSITION: a cost record starts as draft' USING ERRCODE = 'P0001';
        END IF;
        EXECUTE format('SELECT COALESCE(max(version), 0) + 1 FROM %I WHERE %s', TG_TABLE_NAME, v_where) INTO NEW.version;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'DRAFT' THEN
        IF (to_jsonb(NEW) - 'updated_at') = (to_jsonb(OLD) - 'updated_at') THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'RROKA_COST_RECORD_LOCKED: % record cannot change', OLD.status USING ERRCODE = 'P0001';
    END IF;
    IF NEW.version <> OLD.version THEN
        RAISE EXCEPTION 'RROKA_COST_RECORD_LOCKED: series and version are fixed' USING ERRCODE = 'P0001';
    END IF;
    IF v_key <> '' THEN
        FOREACH v_col IN ARRAY string_to_array(v_key, ',') LOOP
            IF (to_jsonb(NEW) ->> v_col) IS DISTINCT FROM (to_jsonb(OLD) ->> v_col) THEN
                RAISE EXCEPTION 'RROKA_COST_RECORD_LOCKED: series and version are fixed' USING ERRCODE = 'P0001';
            END IF;
        END LOOP;
    END IF;
    IF NEW.status = 'APPROVED' THEN
        EXECUTE format('SELECT max(effective_from) FROM %I WHERE status = ''APPROVED'' AND %s', TG_TABLE_NAME, v_where) INTO v_latest;
        IF v_latest IS NOT NULL AND NEW.effective_from <= v_latest THEN
            RAISE EXCEPTION 'RROKA_RATE_BACKDATED: an approved version starts %; a new one must start later', v_latest USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

-- Electricity price per kWh (one series for the workshop).
CREATE TABLE IF NOT EXISTS energy_rates (
    id             bigserial PRIMARY KEY,
    version        int NOT NULL DEFAULT 0,
    effective_from date NOT NULL,
    rate_per_kwh   numeric(10,4) NOT NULL CHECK (rate_per_kwh > 0),
    source         text NOT NULL CHECK (btrim(source) <> ''),
    estimated      boolean NOT NULL DEFAULT false,
    notes          text,
    status         text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by     bigint REFERENCES users(id),
    approved_by    bigint REFERENCES users(id),
    approved_at    timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);

CREATE OR REPLACE FUNCTION fn_energy_rate_on(p_date date) RETURNS energy_rates AS $$
    SELECT * FROM energy_rates WHERE status = 'APPROVED' AND effective_from <= p_date ORDER BY effective_from DESC LIMIT 1
$$ LANGUAGE sql STABLE;

-- Employee cost card: monthly cost components and productive capacity give the
-- productive hourly rate (never salary ÷ number of products). Every deduction
-- from theoretical to practical hours is itemised, so low productivity cannot
-- hide inside an arbitrary capacity figure.
CREATE TABLE IF NOT EXISTS employee_cost_cards (
    id                  bigserial PRIMARY KEY,
    employee_id         bigint NOT NULL REFERENCES workers(id),
    version             int NOT NULL DEFAULT 0,
    effective_from      date NOT NULL,
    basic_salary        numeric(12,2) NOT NULL CHECK (basic_salary > 0),
    housing             numeric(12,2) NOT NULL CHECK (housing >= 0),
    transportation      numeric(12,2) NOT NULL CHECK (transportation >= 0),
    insurance           numeric(12,2) NOT NULL CHECK (insurance >= 0),
    government_fees     numeric(12,2) NOT NULL CHECK (government_fees >= 0),   -- residency, work permit, ... per month
    allowances          numeric(12,2) NOT NULL CHECK (allowances >= 0),
    other_costs         numeric(12,2) NOT NULL CHECK (other_costs >= 0),
    theoretical_hours   numeric(7,2) NOT NULL CHECK (theoretical_hours > 0),     -- per month
    break_hours         numeric(7,2) NOT NULL CHECK (break_hours >= 0),
    cleaning_hours      numeric(7,2) NOT NULL CHECK (cleaning_hours >= 0),
    maintenance_hours   numeric(7,2) NOT NULL CHECK (maintenance_hours >= 0),
    setup_hours         numeric(7,2) NOT NULL CHECK (setup_hours >= 0),
    meeting_hours       numeric(7,2) NOT NULL CHECK (meeting_hours >= 0),
    downtime_hours      numeric(7,2) NOT NULL CHECK (downtime_hours >= 0),
    waiting_hours       numeric(7,2) NOT NULL CHECK (waiting_hours >= 0),
    other_nonproductive_hours numeric(7,2) NOT NULL CHECK (other_nonproductive_hours >= 0),
    monthly_cost        numeric(14,2) GENERATED ALWAYS AS
                        (basic_salary + housing + transportation + insurance + government_fees + allowances + other_costs) STORED,
    practical_hours     numeric(7,2) GENERATED ALWAYS AS
                        (theoretical_hours - (break_hours + cleaning_hours + maintenance_hours + setup_hours + meeting_hours
                                              + downtime_hours + waiting_hours + other_nonproductive_hours)) STORED,
    hourly_rate         numeric(12,4) GENERATED ALWAYS AS
                        (round((basic_salary + housing + transportation + insurance + government_fees + allowances + other_costs)
                               / NULLIF(theoretical_hours - (break_hours + cleaning_hours + maintenance_hours + setup_hours + meeting_hours
                                                             + downtime_hours + waiting_hours + other_nonproductive_hours), 0), 4)) STORED,
    source              text NOT NULL CHECK (btrim(source) <> ''),
    estimated           boolean NOT NULL DEFAULT false,
    notes               text,
    status              text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by          bigint REFERENCES users(id),
    approved_by         bigint REFERENCES users(id),
    approved_at         timestamptz,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (theoretical_hours > break_hours + cleaning_hours + maintenance_hours + setup_hours + meeting_hours
                               + downtime_hours + waiting_hours + other_nonproductive_hours),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_employee_cost_cards_employee ON employee_cost_cards (employee_id, effective_from);

-- How the employee's productive hours split between cost centres (must total 100%).
CREATE TABLE IF NOT EXISTS employee_cost_card_shares (
    id             bigserial PRIMARY KEY,
    card_id        bigint NOT NULL REFERENCES employee_cost_cards(id) ON DELETE CASCADE,
    cost_center_id bigint NOT NULL REFERENCES cost_centers(id),
    share_pct      numeric(5,2) NOT NULL CHECK (share_pct > 0 AND share_pct <= 100),
    UNIQUE (card_id, cost_center_id)
);

-- Machine cost card: depreciation, electricity, maintenance, spare parts and
-- other running costs per practical machine hour. The electricity price is
-- copied from the approved energy rate in force on the card's start date.
CREATE TABLE IF NOT EXISTS machine_cost_cards (
    id                        bigserial PRIMARY KEY,
    machine_id                bigint NOT NULL REFERENCES machines(id),
    version                   int NOT NULL DEFAULT 0,
    effective_from            date NOT NULL,
    acquisition_cost          numeric(14,2) NOT NULL CHECK (acquisition_cost >= 0),
    residual_value            numeric(14,2) NOT NULL CHECK (residual_value >= 0),
    useful_life_years         numeric(5,2) NOT NULL CHECK (useful_life_years > 0),
    theoretical_annual_hours  numeric(8,2) NOT NULL CHECK (theoretical_annual_hours > 0),
    practical_annual_hours    numeric(8,2) NOT NULL CHECK (practical_annual_hours > 0),
    power_kw                  numeric(8,3) NOT NULL CHECK (power_kw >= 0),
    load_factor               numeric(4,3) NOT NULL CHECK (load_factor >= 0 AND load_factor <= 1),
    annual_maintenance        numeric(14,2) NOT NULL CHECK (annual_maintenance >= 0),
    annual_spare_parts        numeric(14,2) NOT NULL CHECK (annual_spare_parts >= 0),
    annual_other              numeric(14,2) NOT NULL CHECK (annual_other >= 0),
    energy_rate_id            bigint REFERENCES energy_rates(id),
    electricity_rate          numeric(10,4),
    depreciation_per_hour     numeric(12,4) GENERATED ALWAYS AS
                              (round((acquisition_cost - residual_value) / useful_life_years / practical_annual_hours, 4)) STORED,
    electricity_per_hour      numeric(12,4) GENERATED ALWAYS AS
                              (round(CASE WHEN power_kw = 0 THEN 0 ELSE power_kw * load_factor * electricity_rate END, 4)) STORED,
    maintenance_per_hour      numeric(12,4) GENERATED ALWAYS AS (round(annual_maintenance / practical_annual_hours, 4)) STORED,
    spare_parts_per_hour      numeric(12,4) GENERATED ALWAYS AS (round(annual_spare_parts / practical_annual_hours, 4)) STORED,
    other_per_hour            numeric(12,4) GENERATED ALWAYS AS (round(annual_other / practical_annual_hours, 4)) STORED,
    hourly_rate               numeric(12,4) GENERATED ALWAYS AS
                              (round((acquisition_cost - residual_value) / useful_life_years / practical_annual_hours, 4)
                               + round(CASE WHEN power_kw = 0 THEN 0 ELSE power_kw * load_factor * electricity_rate END, 4)
                               + round(annual_maintenance / practical_annual_hours, 4)
                               + round(annual_spare_parts / practical_annual_hours, 4)
                               + round(annual_other / practical_annual_hours, 4)) STORED,
    source                    text NOT NULL CHECK (btrim(source) <> ''),
    estimated                 boolean NOT NULL DEFAULT false,
    notes                     text,
    status                    text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by                bigint REFERENCES users(id),
    approved_by               bigint REFERENCES users(id),
    approved_at               timestamptz,
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    CHECK (residual_value <= acquisition_cost),
    CHECK (practical_annual_hours <= theoretical_annual_hours),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_machine_cost_cards_machine ON machine_cost_cards (machine_id, effective_from);

-- Standard purchase price per material (what the standard cost uses), with its basis.
CREATE TABLE IF NOT EXISTS material_standard_prices (
    id             bigserial PRIMARY KEY,
    material_id    bigint NOT NULL REFERENCES raw_materials(id),
    version        int NOT NULL DEFAULT 0,
    effective_from date NOT NULL,
    unit_price     numeric(14,4) NOT NULL CHECK (unit_price > 0),
    price_basis    text NOT NULL CHECK (price_basis IN ('LAST_PURCHASE', 'AVERAGE_COST', 'SUPPLIER_QUOTE', 'MANUAL')),
    source         text NOT NULL CHECK (btrim(source) <> ''),
    estimated      boolean NOT NULL DEFAULT false,
    notes          text,
    status         text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by     bigint REFERENCES users(id),
    approved_by    bigint REFERENCES users(id),
    approved_at    timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS ix_material_standard_prices ON material_standard_prices (material_id, effective_from);

-- Standard waste % by material, or by material category; the material's own rate wins.
CREATE TABLE IF NOT EXISTS waste_defaults (
    id             bigserial PRIMARY KEY,
    material_id    bigint REFERENCES raw_materials(id),
    category       text CHECK (category IS NULL OR (btrim(category) <> '' AND category = btrim(category))),
    subject_key    text GENERATED ALWAYS AS (CASE WHEN material_id IS NOT NULL THEN 'M:' || material_id ELSE 'C:' || lower(btrim(category)) END) STORED,
    version        int NOT NULL DEFAULT 0,
    effective_from date NOT NULL,
    waste_pct      numeric(5,2) NOT NULL CHECK (waste_pct >= 0 AND waste_pct < 100),
    source         text NOT NULL CHECK (btrim(source) <> ''),
    estimated      boolean NOT NULL DEFAULT false,
    notes          text,
    status         text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by     bigint REFERENCES users(id),
    approved_by    bigint REFERENCES users(id),
    approved_at    timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (num_nonnulls(material_id, category) = 1),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);

-- Overhead pools: expected indirect cost of a period, absorbed through a cost
-- driver at practical capacity (never ÷ number of products). Manufacturing pools
-- belong to a cost centre (or the whole factory); the selling & administrative
-- pool gives the % added for the fully loaded cost.
CREATE TABLE IF NOT EXISTS overhead_pools (
    id                          bigserial PRIMARY KEY,
    kind                        text NOT NULL CHECK (kind IN ('MANUFACTURING', 'SELLING_ADMIN')),
    cost_center_id              bigint REFERENCES cost_centers(id),          -- NULL = whole factory
    pool_key                    text GENERATED ALWAYS AS (kind || ':' || COALESCE(cost_center_id::text, 'ALL')) STORED,
    version                     int NOT NULL DEFAULT 0,
    effective_from              date NOT NULL,                               -- period start
    period_to                   date NOT NULL,
    driver                      text NOT NULL CHECK (driver IN ('LABOR_HOURS', 'MACHINE_HOURS', 'PCT_OF_MANUFACTURING_COST')),
    theoretical_capacity        numeric(12,2) CHECK (theoretical_capacity > 0),
    practical_capacity          numeric(12,2) CHECK (practical_capacity > 0),  -- driver hours in the period
    budgeted_manufacturing_cost numeric(16,2) CHECK (budgeted_manufacturing_cost > 0),
    gross_cost                  numeric(16,2),   -- frozen at approval
    machine_energy_deduction    numeric(16,2),   -- frozen at approval
    net_cost                    numeric(16,2),   -- frozen at approval
    rate                        numeric(14,4),   -- per driver hour, or % for selling & admin; frozen at approval
    source                      text NOT NULL CHECK (btrim(source) <> ''),
    estimated                   boolean NOT NULL DEFAULT false,
    notes                       text,
    status                      text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by                  bigint REFERENCES users(id),
    approved_by                 bigint REFERENCES users(id),
    approved_at                 timestamptz,
    created_at                  timestamptz NOT NULL DEFAULT now(),
    updated_at                  timestamptz NOT NULL DEFAULT now(),
    CHECK (period_to >= effective_from),
    CHECK ((kind = 'SELLING_ADMIN') = (driver = 'PCT_OF_MANUFACTURING_COST')),
    CHECK (kind = 'MANUFACTURING' OR cost_center_id IS NULL),
    CHECK (kind <> 'MANUFACTURING' OR practical_capacity IS NOT NULL),
    CHECK (kind <> 'SELLING_ADMIN' OR budgeted_manufacturing_cost IS NOT NULL),
    CHECK (theoretical_capacity IS NULL OR practical_capacity IS NULL OR practical_capacity <= theoretical_capacity),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND rate IS NOT NULL))
);

CREATE TABLE IF NOT EXISTS overhead_pool_lines (
    id          bigserial PRIMARY KEY,
    pool_id     bigint NOT NULL REFERENCES overhead_pools(id) ON DELETE CASCADE,
    category    text NOT NULL CHECK (category IN ('RENT', 'GENERAL_ELECTRICITY', 'SUPERVISION', 'INDIRECT_LABOR', 'CLEANING',
                    'MAINTENANCE', 'CONSUMABLES', 'DEPRECIATION', 'INSURANCE', 'OTHER_PRODUCTION', 'SELLING', 'ADMINISTRATIVE')),
    description text NOT NULL CHECK (btrim(description) <> ''),
    amount      numeric(14,2) NOT NULL CHECK (amount >= 0),
    employee_id bigint REFERENCES workers(id),
    machine_id  bigint REFERENCES machines(id),
    CHECK (category NOT IN ('SUPERVISION', 'INDIRECT_LABOR') OR employee_id IS NOT NULL),
    CHECK (machine_id IS NULL OR category = 'DEPRECIATION')
);

-- Expected electricity of the machines (kW × load × kWh price × practical hours),
-- pro-rated to a period: what machine hour rates already charge.
CREATE OR REPLACE FUNCTION fn_machine_energy(p_from date, p_to date, p_cost_center bigint) RETURNS numeric AS $$
    SELECT COALESCE(round(sum(c.power_kw * c.load_factor * c.electricity_rate * c.practical_annual_hours
                              * ((p_to - p_from + 1)::numeric / 365)), 2), 0)
      FROM machines m
      JOIN LATERAL (SELECT * FROM machine_cost_cards x
                     WHERE x.machine_id = m.id AND x.status = 'APPROVED' AND x.effective_from <= p_to
                     ORDER BY x.effective_from DESC LIMIT 1) c ON true
     WHERE p_cost_center IS NULL OR m.cost_center_id = p_cost_center
$$ LANGUAGE sql STABLE;

-- Draft-only lines, and no cost counted twice: a direct worker in an indirect
-- labour line, a machine already depreciated in its hour rate, selling/admin
-- costs in a manufacturing pool (or the reverse), factory electricity outside
-- the whole-factory pool.
CREATE OR REPLACE FUNCTION fn_overhead_line_guard() RETURNS trigger AS $$
DECLARE
    v_pool overhead_pools%ROWTYPE;
BEGIN
    SELECT * INTO v_pool FROM overhead_pools WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.pool_id ELSE NEW.pool_id END;
    IF v_pool.status <> 'DRAFT' THEN
        RAISE EXCEPTION 'RROKA_COST_RECORD_LOCKED: lines of a % pool cannot change', v_pool.status USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    IF (v_pool.kind = 'SELLING_ADMIN') <> (NEW.category IN ('SELLING', 'ADMINISTRATIVE')) THEN
        RAISE EXCEPTION 'RROKA_POOL_CATEGORY: % does not belong in a % pool', NEW.category, v_pool.kind USING ERRCODE = 'P0001';
    END IF;
    IF NEW.category = 'GENERAL_ELECTRICITY' AND v_pool.cost_center_id IS NOT NULL THEN
        RAISE EXCEPTION 'RROKA_POOL_CATEGORY: factory electricity belongs in the whole-factory pool' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.employee_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM employee_cost_cards c WHERE c.employee_id = NEW.employee_id AND c.status = 'APPROVED'
           AND c.effective_from <= v_pool.period_to) THEN
        RAISE EXCEPTION 'RROKA_DOUBLE_COUNT_LABOR: this employee is costed as direct labour' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.machine_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM machine_cost_cards c WHERE c.machine_id = NEW.machine_id AND c.status = 'APPROVED'
           AND c.effective_from <= v_pool.period_to) THEN
        RAISE EXCEPTION 'RROKA_DOUBLE_COUNT_MACHINE: this machine is depreciated in its hour rate' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_overhead_line_guard ON overhead_pool_lines;
CREATE TRIGGER trg_overhead_line_guard BEFORE INSERT OR UPDATE OR DELETE ON overhead_pool_lines
    FOR EACH ROW EXECUTE FUNCTION fn_overhead_line_guard();

-- Pool: the driver follows the cost centre; approval freezes the figures and refuses
-- an overlapping approved pool for the same centre.
CREATE OR REPLACE FUNCTION fn_overhead_pool_guard() RETURNS trigger AS $$
DECLARE
    v_gross  numeric;
    v_elec   numeric;
    v_energy numeric := 0;
BEGIN
    IF NEW.status = 'DRAFT' AND NEW.cost_center_id IS NOT NULL THEN
        SELECT driver INTO NEW.driver FROM cost_centers WHERE id = NEW.cost_center_id;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.status = 'DRAFT' AND NEW.status = 'APPROVED' THEN
        IF EXISTS (SELECT 1 FROM overhead_pools p WHERE p.kind = NEW.kind AND p.cost_center_id IS NOT DISTINCT FROM NEW.cost_center_id
                     AND p.status = 'APPROVED' AND p.id <> NEW.id
                     AND p.effective_from <= NEW.period_to AND p.period_to >= NEW.effective_from) THEN
            RAISE EXCEPTION 'RROKA_POOL_OVERLAP: an approved pool already covers this period' USING ERRCODE = 'P0001';
        END IF;
        SELECT sum(amount), sum(amount) FILTER (WHERE category = 'GENERAL_ELECTRICITY') INTO v_gross, v_elec
          FROM overhead_pool_lines WHERE pool_id = NEW.id;
        IF v_gross IS NULL THEN
            RAISE EXCEPTION 'RROKA_POOL_EMPTY' USING ERRCODE = 'P0001';
        END IF;
        IF v_elec IS NOT NULL THEN
            v_energy := fn_machine_energy(NEW.effective_from, NEW.period_to, NULL);
            IF v_energy > v_elec THEN
                RAISE EXCEPTION 'RROKA_DOUBLE_COUNT_ELECTRICITY: the bill (%) is less than the machines'' own electricity (%)', v_elec, v_energy
                    USING ERRCODE = 'P0001';
            END IF;
        END IF;
        NEW.gross_cost := v_gross;
        NEW.machine_energy_deduction := v_energy;
        NEW.net_cost := v_gross - v_energy;
        NEW.rate := CASE WHEN NEW.kind = 'SELLING_ADMIN'
                         THEN round((v_gross - v_energy) / NEW.budgeted_manufacturing_cost * 100, 4)
                         ELSE round((v_gross - v_energy) / NEW.practical_capacity, 4) END;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

-- Employee card: shares must cover 100% before approval; approval writes the
-- worker's hourly rate (traceable to the card).
ALTER TABLE worker_rates ADD COLUMN IF NOT EXISTS cost_card_id bigint REFERENCES employee_cost_cards(id);
ALTER TABLE machine_rates ADD COLUMN IF NOT EXISTS cost_card_id bigint REFERENCES machine_cost_cards(id);

CREATE OR REPLACE FUNCTION fn_employee_cost_card_approve() RETURNS trigger AS $$
DECLARE
    v_shares numeric;
BEGIN
    IF NEW.status = 'APPROVED' AND OLD.status = 'DRAFT' THEN
        SELECT sum(share_pct) INTO v_shares FROM employee_cost_card_shares WHERE card_id = NEW.id;
        IF v_shares IS DISTINCT FROM 100 THEN
            RAISE EXCEPTION 'RROKA_COST_SHARES_INCOMPLETE: cost centre shares total %', COALESCE(v_shares, 0) USING ERRCODE = 'P0001';
        END IF;
        IF EXISTS (SELECT 1 FROM worker_rates WHERE worker_id = NEW.employee_id AND effective_from = NEW.effective_from) THEN
            RAISE EXCEPTION 'RROKA_RATE_DATE_TAKEN: a rate already starts on %', NEW.effective_from USING ERRCODE = 'P0001';
        END IF;
        INSERT INTO worker_rates (worker_id, hourly_cost, effective_from, basis_note, entered_by, cost_card_id)
        VALUES (NEW.employee_id, NEW.hourly_rate, NEW.effective_from,
                format('بطاقة تكلفة الموظف — الإصدار %s: %s ÷ %s ساعة إنتاجية', NEW.version, NEW.monthly_cost, NEW.practical_hours),
                NEW.approved_by, NEW.id);
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION fn_cost_card_shares_guard() RETURNS trigger AS $$
BEGIN
    IF (SELECT status FROM employee_cost_cards WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.card_id ELSE NEW.card_id END) <> 'DRAFT' THEN
        RAISE EXCEPTION 'RROKA_COST_RECORD_LOCKED: shares of an approved card cannot change' USING ERRCODE = 'P0001';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_cost_card_shares_guard ON employee_cost_card_shares;
CREATE TRIGGER trg_cost_card_shares_guard BEFORE INSERT OR UPDATE OR DELETE ON employee_cost_card_shares
    FOR EACH ROW EXECUTE FUNCTION fn_cost_card_shares_guard();

-- Machine card: the electricity price comes from the approved energy rate in force;
-- approval writes the machine's hour rate.
CREATE OR REPLACE FUNCTION fn_machine_cost_card_energy() RETURNS trigger AS $$
DECLARE
    v_rate energy_rates%ROWTYPE;
BEGIN
    IF NEW.status = 'DRAFT' OR (TG_OP = 'UPDATE' AND OLD.status = 'DRAFT') THEN
        v_rate := fn_energy_rate_on(NEW.effective_from);
        NEW.energy_rate_id := v_rate.id;
        NEW.electricity_rate := v_rate.rate_per_kwh;
    END IF;
    IF NEW.status = 'APPROVED' AND TG_OP = 'UPDATE' AND OLD.status = 'DRAFT' AND NEW.power_kw > 0 AND NEW.electricity_rate IS NULL THEN
        RAISE EXCEPTION 'RROKA_ENERGY_RATE_MISSING: no approved electricity rate on %', NEW.effective_from USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION fn_machine_cost_card_approve() RETURNS trigger AS $$
BEGIN
    IF NEW.status = 'APPROVED' AND OLD.status = 'DRAFT' THEN
        IF NEW.hourly_rate IS NULL OR NEW.hourly_rate <= 0 THEN
            RAISE EXCEPTION 'RROKA_RATE_ZERO: the machine hour rate must be above zero' USING ERRCODE = 'P0001';
        END IF;
        IF EXISTS (SELECT 1 FROM machine_rates WHERE machine_id = NEW.machine_id AND effective_from = NEW.effective_from) THEN
            RAISE EXCEPTION 'RROKA_RATE_DATE_TAKEN: a rate already starts on %', NEW.effective_from USING ERRCODE = 'P0001';
        END IF;
        INSERT INTO machine_rates (machine_id, hourly_cost, effective_from, basis_note, entered_by, cost_card_id)
        VALUES (NEW.machine_id, NEW.hourly_rate, NEW.effective_from,
                format('بطاقة تكلفة الآلة — الإصدار %s: إهلاك %s + كهرباء %s + صيانة %s + قطع غيار %s + أخرى %s للساعة',
                       NEW.version, NEW.depreciation_per_hour, NEW.electricity_per_hour, NEW.maintenance_per_hour,
                       NEW.spare_parts_per_hour, NEW.other_per_hour),
                NEW.approved_by, NEW.id);
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_a_cost_guard ON energy_rates;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON energy_rates FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('');
DROP TRIGGER IF EXISTS trg_a_cost_guard ON employee_cost_cards;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON employee_cost_cards FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('employee_id');
DROP TRIGGER IF EXISTS trg_a_cost_guard ON machine_cost_cards;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON machine_cost_cards FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('machine_id');
DROP TRIGGER IF EXISTS trg_a_cost_guard ON material_standard_prices;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON material_standard_prices FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('material_id');
DROP TRIGGER IF EXISTS trg_a_cost_guard ON waste_defaults;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON waste_defaults FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('material_id,category');
DROP TRIGGER IF EXISTS trg_a_cost_guard ON overhead_pools;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON overhead_pools FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('kind,cost_center_id');
DROP TRIGGER IF EXISTS trg_b_pool_guard ON overhead_pools;
CREATE TRIGGER trg_b_pool_guard BEFORE INSERT OR UPDATE ON overhead_pools FOR EACH ROW EXECUTE FUNCTION fn_overhead_pool_guard();
DROP TRIGGER IF EXISTS trg_b_machine_card_energy ON machine_cost_cards;
CREATE TRIGGER trg_b_machine_card_energy BEFORE INSERT OR UPDATE ON machine_cost_cards FOR EACH ROW EXECUTE FUNCTION fn_machine_cost_card_energy();
DROP TRIGGER IF EXISTS trg_employee_cost_card_approve ON employee_cost_cards;
CREATE TRIGGER trg_employee_cost_card_approve AFTER UPDATE ON employee_cost_cards FOR EACH ROW EXECUTE FUNCTION fn_employee_cost_card_approve();
DROP TRIGGER IF EXISTS trg_machine_cost_card_approve ON machine_cost_cards;
CREATE TRIGGER trg_machine_cost_card_approve AFTER UPDATE ON machine_cost_cards FOR EACH ROW EXECUTE FUNCTION fn_machine_cost_card_approve();

-- Standard price and waste in force on a date (NULL when none: never assumed).
CREATE OR REPLACE FUNCTION fn_standard_price(p_material bigint, p_on date) RETURNS numeric AS $$
    SELECT unit_price FROM material_standard_prices
     WHERE material_id = p_material AND status = 'APPROVED' AND effective_from <= p_on
     ORDER BY effective_from DESC LIMIT 1
$$ LANGUAGE sql STABLE;

CREATE OR REPLACE FUNCTION fn_standard_waste_pct(p_material bigint, p_on date) RETURNS numeric AS $$
    SELECT COALESCE(
        (SELECT waste_pct FROM waste_defaults WHERE subject_key = 'M:' || p_material AND status = 'APPROVED' AND effective_from <= p_on
          ORDER BY effective_from DESC LIMIT 1),
        (SELECT w.waste_pct FROM waste_defaults w JOIN raw_materials m ON m.id = p_material
          WHERE w.subject_key = 'C:' || lower(btrim(m.category)) AND w.status = 'APPROVED' AND w.effective_from <= p_on
          ORDER BY w.effective_from DESC LIMIT 1))
$$ LANGUAGE sql STABLE;

-- Validity periods: each approved version runs until the next one starts.
CREATE OR REPLACE VIEW v_cost_rate_periods AS
SELECT 'energy_rates'::text AS table_name, id, ''::text AS series, version, effective_from,
       lead(effective_from) OVER (ORDER BY effective_from) - 1 AS effective_to
  FROM energy_rates WHERE status = 'APPROVED'
UNION ALL
SELECT 'employee_cost_cards', id, employee_id::text, version, effective_from,
       lead(effective_from) OVER (PARTITION BY employee_id ORDER BY effective_from) - 1
  FROM employee_cost_cards WHERE status = 'APPROVED'
UNION ALL
SELECT 'machine_cost_cards', id, machine_id::text, version, effective_from,
       lead(effective_from) OVER (PARTITION BY machine_id ORDER BY effective_from) - 1
  FROM machine_cost_cards WHERE status = 'APPROVED'
UNION ALL
SELECT 'material_standard_prices', id, material_id::text, version, effective_from,
       lead(effective_from) OVER (PARTITION BY material_id ORDER BY effective_from) - 1
  FROM material_standard_prices WHERE status = 'APPROVED'
UNION ALL
SELECT 'waste_defaults', id, subject_key, version, effective_from,
       lead(effective_from) OVER (PARTITION BY subject_key ORDER BY effective_from) - 1
  FROM waste_defaults WHERE status = 'APPROVED';

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['cost_centers', 'energy_rates', 'employee_cost_cards', 'employee_cost_card_shares', 'machine_cost_cards',
                             'material_standard_prices', 'waste_defaults', 'overhead_pools', 'overhead_pool_lines'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
    END LOOP;
    FOREACH t IN ARRAY ARRAY['cost_centers', 'energy_rates', 'employee_cost_cards', 'machine_cost_cards',
                             'material_standard_prices', 'waste_defaults', 'overhead_pools'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code = 'system_admin' AND p.code = 'cost_rates.approve'
ON CONFLICT DO NOTHING;
SQL);
    }
};
