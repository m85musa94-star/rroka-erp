<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Costing engine phase 2: VAT rates, pricing policy, cost estimates per quotation
 * line (BOM, routing, direct costs), the cost sheet views, and the approval gate that
 * freezes the standard cost. Existing quotations are exempt (requires_costing = false).
 * Same SQL is at the end of rroka_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- ---------------------------------------------------------------------
-- Costing engine, phase 2 (2026-10-05): standard cost and pricing per
-- quotation line. Every figure is computed here from the approved rates in
-- force on the quotation date; approval of a quotation that requires costing
-- is refused until every line's estimate is complete, and freezes an
-- immutable snapshot (figures + the ids/versions of every rate used).
-- VAT (approved 2026-10-05): computed on the quotation from the rate the
-- user picks; no rate picked = no VAT. Posting and returns stay in Daftra.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS vat_rates (
    id          bigserial PRIMARY KEY,
    name        text NOT NULL UNIQUE CHECK (btrim(name) <> ''),
    rate_pct    numeric(5,2) NOT NULL CHECK (rate_pct >= 0 AND rate_pct < 100),
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE OR REPLACE FUNCTION fn_vat_rate_guard() RETURNS trigger AS $$
BEGIN
    IF NEW.rate_pct <> OLD.rate_pct THEN
        RAISE EXCEPTION 'RROKA_VAT_RATE_LOCKED: a rate cannot change; add a new one' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_vat_rate_guard ON vat_rates;
CREATE TRIGGER trg_vat_rate_guard BEFORE UPDATE ON vat_rates FOR EACH ROW EXECUTE FUNCTION fn_vat_rate_guard();

ALTER TABLE quotations ADD COLUMN IF NOT EXISTS vat_rate_id bigint REFERENCES vat_rates(id);
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS vat_pct numeric(5,2);
-- Quotations created before costing existed keep working; every new one requires it.
ALTER TABLE quotations ADD COLUMN IF NOT EXISTS requires_costing boolean NOT NULL DEFAULT false;
ALTER TABLE quotations ALTER COLUMN requires_costing SET DEFAULT true;

-- The VAT percentage is copied from the chosen rate (only an active one can be chosen).
CREATE OR REPLACE FUNCTION fn_quotation_vat() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' OR NEW.vat_rate_id IS DISTINCT FROM OLD.vat_rate_id THEN
        IF NEW.vat_rate_id IS NULL THEN
            NEW.vat_pct := NULL;
        ELSE
            SELECT rate_pct INTO NEW.vat_pct FROM vat_rates WHERE id = NEW.vat_rate_id AND is_active;
            IF NEW.vat_pct IS NULL THEN
                RAISE EXCEPTION 'RROKA_VAT_RATE_INACTIVE' USING ERRCODE = 'P0001';
            END IF;
        END IF;
    ELSIF NEW.vat_pct IS DISTINCT FROM OLD.vat_pct THEN
        NEW.vat_pct := OLD.vat_pct;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_quotation_a_vat ON quotations;
CREATE TRIGGER trg_quotation_a_vat BEFORE INSERT OR UPDATE ON quotations FOR EACH ROW EXECUTE FUNCTION fn_quotation_vat();

CREATE OR REPLACE VIEW v_quotation_totals AS
SELECT q.id AS quotation_id,
       COALESCE(sum(l.line_total), 0)                     AS subtotal,
       q.discount_amount,
       COALESCE(sum(l.line_total), 0) - q.discount_amount AS net_before_vat,
       q.vat_pct,
       round((COALESCE(sum(l.line_total), 0) - q.discount_amount) * COALESCE(q.vat_pct, 0) / 100, 2) AS vat_amount,
       COALESCE(sum(l.line_total), 0) - q.discount_amount
         + round((COALESCE(sum(l.line_total), 0) - q.discount_amount) * COALESCE(q.vat_pct, 0) / 100, 2) AS total_incl_vat
FROM quotations q
LEFT JOIN quotation_lines l ON l.quotation_id = q.id
GROUP BY q.id;

-- Pricing policy (versioned): default method, target and minimum margin.
CREATE TABLE IF NOT EXISTS pricing_policies (
    id              bigserial PRIMARY KEY,
    version         int NOT NULL DEFAULT 0,
    effective_from  date NOT NULL,
    pricing_method  text NOT NULL CHECK (pricing_method IN ('MARGIN', 'MARKUP')),
    target_pct      numeric(6,2) NOT NULL CHECK (target_pct >= 0),
    min_margin_pct  numeric(5,2) CHECK (min_margin_pct >= 0 AND min_margin_pct < 100),
    source          text NOT NULL CHECK (btrim(source) <> ''),
    estimated       boolean NOT NULL DEFAULT false,
    notes           text,
    status          text NOT NULL DEFAULT 'DRAFT' CHECK (status IN ('DRAFT', 'APPROVED', 'CANCELLED')),
    created_by      bigint REFERENCES users(id),
    approved_by     bigint REFERENCES users(id),
    approved_at     timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (pricing_method <> 'MARGIN' OR target_pct < 100),
    CHECK (status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
DROP TRIGGER IF EXISTS trg_a_cost_guard ON pricing_policies;
CREATE TRIGGER trg_a_cost_guard BEFORE INSERT OR UPDATE ON pricing_policies FOR EACH ROW EXECUTE FUNCTION fn_cost_record_guard('');

-- Cost estimate of one quotation line (keyed by line number: lines are rewritten on edit).
CREATE TABLE IF NOT EXISTS cost_estimates (
    id                    bigserial PRIMARY KEY,
    quotation_id          bigint NOT NULL REFERENCES quotations(id) ON DELETE CASCADE,
    line_no               int NOT NULL CHECK (line_no > 0),
    product_category      text,
    dimensions            text,
    specifications        text,      -- material grade, fabric, wood type, foam, accessories
    pricing_method        text NOT NULL CHECK (pricing_method IN ('MARGIN', 'MARKUP')),
    target_pct            numeric(6,2) NOT NULL CHECK (target_pct >= 0),
    min_margin_pct        numeric(5,2) CHECK (min_margin_pct >= 0 AND min_margin_pct < 100),
    installation_required boolean NOT NULL DEFAULT false,
    delivery_required     boolean NOT NULL DEFAULT false,
    notes                 text,
    created_by            bigint REFERENCES users(id),
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    UNIQUE (quotation_id, line_no),
    CHECK (pricing_method <> 'MARGIN' OR target_pct < 100)
);

-- BOM of the estimate, per unit of the line. Waste and price come from the
-- approved standards unless overridden here (an overriding price names its source).
CREATE TABLE IF NOT EXISTS cost_estimate_materials (
    id           bigserial PRIMARY KEY,
    estimate_id  bigint NOT NULL REFERENCES cost_estimates(id) ON DELETE CASCADE,
    material_id  bigint NOT NULL REFERENCES raw_materials(id),
    quantity     numeric(14,4) NOT NULL CHECK (quantity > 0),
    waste_pct    numeric(5,2) CHECK (waste_pct >= 0 AND waste_pct < 100),
    unit_price   numeric(14,4) CHECK (unit_price > 0),
    price_source text,
    notes        text,
    CHECK (unit_price IS NULL OR NULLIF(btrim(price_source), '') IS NOT NULL)
);

-- Routing: operations with their cost centre, hours per unit and setup hours per batch.
CREATE TABLE IF NOT EXISTS cost_estimate_operations (
    id                  bigserial PRIMARY KEY,
    estimate_id         bigint NOT NULL REFERENCES cost_estimates(id) ON DELETE CASCADE,
    seq                 int NOT NULL CHECK (seq > 0),
    operation           text NOT NULL CHECK (btrim(operation) <> ''),
    cost_center_id      bigint NOT NULL REFERENCES cost_centers(id),
    employee_id         bigint REFERENCES workers(id),       -- optional: that employee's rate instead of the centre's
    labor_hours         numeric(10,3) NOT NULL CHECK (labor_hours >= 0),          -- per unit
    setup_hours         numeric(10,3) NOT NULL CHECK (setup_hours >= 0),          -- once per batch
    machine_id          bigint REFERENCES machines(id),
    machine_hours       numeric(10,3) NOT NULL DEFAULT 0 CHECK (machine_hours >= 0),          -- per unit
    machine_setup_hours numeric(10,3) NOT NULL DEFAULT 0 CHECK (machine_setup_hours >= 0),    -- once per batch
    notes               text,
    CHECK (machine_id IS NOT NULL OR (machine_hours = 0 AND machine_setup_hours = 0)),
    CHECK (labor_hours + setup_hours + machine_hours + machine_setup_hours > 0)
);

CREATE TABLE IF NOT EXISTS cost_estimate_direct_costs (
    id          bigserial PRIMARY KEY,
    estimate_id bigint NOT NULL REFERENCES cost_estimates(id) ON DELETE CASCADE,
    cost_type   text NOT NULL CHECK (cost_type IN ('EXTERNAL_MANUFACTURING', 'SUBCONTRACTOR', 'SPECIAL_DELIVERY', 'INSTALLATION', 'CRANE',
                    'SPECIAL_TRANSPORT', 'EXTERNAL_PAINTING', 'SPECIAL_DESIGN', 'COMMISSION', 'OTHER')),
    description text NOT NULL CHECK (btrim(description) <> ''),
    amount      numeric(14,2) NOT NULL CHECK (amount >= 0),
    basis       text NOT NULL CHECK (basis IN ('PER_UNIT', 'ONE_TIME'))
);

-- An estimate changes only while its quotation is a draft or sent (not yet decided),
-- and only for a line that exists.
CREATE OR REPLACE FUNCTION fn_estimate_guard() RETURNS trigger AS $$
DECLARE
    v_estimate bigint;
    v_quote    bigint;
    v_status   text;
BEGIN
    IF TG_TABLE_NAME = 'cost_estimates' THEN
        v_quote := CASE WHEN TG_OP = 'DELETE' THEN (to_jsonb(OLD) ->> 'quotation_id')::bigint ELSE (to_jsonb(NEW) ->> 'quotation_id')::bigint END;
    ELSE
        v_estimate := CASE WHEN TG_OP = 'DELETE' THEN (to_jsonb(OLD) ->> 'estimate_id')::bigint ELSE (to_jsonb(NEW) ->> 'estimate_id')::bigint END;
        SELECT quotation_id INTO v_quote FROM cost_estimates WHERE id = v_estimate;
    END IF;
    SELECT status INTO v_status FROM quotations WHERE id = v_quote;
    IF v_status IS NOT NULL AND v_status NOT IN ('DRAFT', 'SENT') THEN
        RAISE EXCEPTION 'RROKA_ESTIMATE_LOCKED: the quotation is %', v_status USING ERRCODE = 'P0001';
    END IF;
    IF TG_TABLE_NAME = 'cost_estimates' AND TG_OP = 'INSERT' THEN
        IF NOT EXISTS (SELECT 1 FROM quotation_lines WHERE quotation_id = v_quote AND line_no = (to_jsonb(NEW) ->> 'line_no')::int) THEN
            RAISE EXCEPTION 'RROKA_ESTIMATE_NO_LINE: line % does not exist', to_jsonb(NEW) ->> 'line_no' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$ LANGUAGE plpgsql;

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['cost_estimates', 'cost_estimate_materials', 'cost_estimate_operations', 'cost_estimate_direct_costs'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_estimate_guard ON %I', t);
        EXECUTE format('CREATE TRIGGER trg_estimate_guard BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION fn_estimate_guard()', t);
    END LOOP;
END $$;

-- Material lines: standard waste (the material's own, else its category's) and
-- standard price in force on the quotation date, unless overridden.
CREATE OR REPLACE VIEW v_estimate_material_lines AS
SELECT m.id, m.estimate_id, e.quotation_id, e.line_no, m.material_id, rm.code, rm.name, rm.category, rm.uom,
       m.quantity AS qty_per_unit, l.quantity AS line_qty,
       COALESCE(m.waste_pct, wd.waste_pct) AS waste_pct,
       CASE WHEN m.waste_pct IS NOT NULL THEN 'OVERRIDE' WHEN wd.id IS NOT NULL THEN 'STANDARD' END AS waste_source,
       wd.id AS waste_default_id, wd.version AS waste_version,
       COALESCE(m.unit_price, sp.unit_price) AS unit_price,
       CASE WHEN m.unit_price IS NOT NULL THEN 'OVERRIDE' WHEN sp.id IS NOT NULL THEN 'STANDARD' END AS price_source,
       m.price_source AS override_source, sp.id AS standard_price_id, sp.version AS price_version,
       round(m.quantity * (1 + COALESCE(m.waste_pct, wd.waste_pct) / 100), 4) AS adjusted_qty_per_unit,
       round(m.quantity * (1 + COALESCE(m.waste_pct, wd.waste_pct) / 100) * l.quantity * COALESCE(m.unit_price, sp.unit_price), 2) AS total_cost
  FROM cost_estimate_materials m
  JOIN cost_estimates e ON e.id = m.estimate_id
  JOIN quotations q ON q.id = e.quotation_id
  JOIN quotation_lines l ON l.quotation_id = e.quotation_id AND l.line_no = e.line_no
  JOIN raw_materials rm ON rm.id = m.material_id
  LEFT JOIN LATERAL (SELECT id, version, unit_price FROM material_standard_prices
                      WHERE material_id = m.material_id AND status = 'APPROVED' AND effective_from <= q.issue_date
                      ORDER BY effective_from DESC LIMIT 1) sp ON true
  LEFT JOIN LATERAL (SELECT w.id, w.version, w.waste_pct FROM waste_defaults w
                      WHERE w.status = 'APPROVED' AND w.effective_from <= q.issue_date
                        AND (w.subject_key = 'M:' || m.material_id OR w.subject_key = 'C:' || lower(btrim(rm.category)))
                      ORDER BY (w.material_id IS NOT NULL) DESC, w.effective_from DESC LIMIT 1) wd ON true;

-- Operation lines: hours (per unit × quantity + setup once per batch), labour rate
-- (the named employee's, else the centre's blended rate from approved cost cards),
-- machine rate, and the centre's overhead rate applied to its driver.
CREATE OR REPLACE VIEW v_estimate_operation_lines AS
SELECT x.*,
       CASE WHEN x.total_labor_hours = 0 THEN 0 ELSE round(x.total_labor_hours * x.labor_rate, 2) END AS labor_cost,
       CASE WHEN x.total_machine_hours = 0 THEN 0 ELSE round(x.total_machine_hours * x.machine_rate, 2) END AS machine_cost,
       CASE WHEN x.driver_hours = 0 THEN 0 ELSE round(x.driver_hours * x.overhead_rate, 2) END AS overhead_cost
  FROM (
    SELECT o.id, o.estimate_id, e.quotation_id, e.line_no, o.seq, o.operation, o.cost_center_id, cc.name AS cost_center_name, cc.driver,
           o.employee_id, o.machine_id, o.labor_hours, o.setup_hours, o.machine_hours, o.machine_setup_hours, l.quantity AS line_qty,
           o.labor_hours * l.quantity + o.setup_hours AS total_labor_hours,
           o.machine_hours * l.quantity + o.machine_setup_hours AS total_machine_hours,
           CASE WHEN cc.driver = 'MACHINE_HOURS' THEN o.machine_hours * l.quantity + o.machine_setup_hours
                ELSE o.labor_hours * l.quantity + o.setup_hours END AS driver_hours,
           CASE WHEN o.employee_id IS NOT NULL THEN wr.hourly_cost ELSE clr.rate END AS labor_rate,
           CASE WHEN o.employee_id IS NOT NULL THEN 'EMPLOYEE' ELSE 'CENTER' END AS labor_rate_basis,
           wr.id AS worker_rate_id, clr.cards AS center_rate_cards,
           mr.hourly_cost AS machine_rate, mr.id AS machine_rate_id,
           pool.id AS pool_id, pool.version AS pool_version, pool.rate AS overhead_rate
      FROM cost_estimate_operations o
      JOIN cost_estimates e ON e.id = o.estimate_id
      JOIN quotations q ON q.id = e.quotation_id
      JOIN quotation_lines l ON l.quotation_id = e.quotation_id AND l.line_no = e.line_no
      JOIN cost_centers cc ON cc.id = o.cost_center_id
      LEFT JOIN LATERAL (SELECT id, hourly_cost FROM worker_rates
                          WHERE worker_id = o.employee_id AND effective_from <= q.issue_date
                          ORDER BY effective_from DESC LIMIT 1) wr ON true
      LEFT JOIN LATERAL (
          SELECT round(sum(c.monthly_cost * s.share_pct) / NULLIF(sum(c.practical_hours * s.share_pct), 0), 4) AS rate,
                 array_agg(c.id ORDER BY c.id) AS cards
            FROM employee_cost_card_shares s
            JOIN employee_cost_cards c ON c.id = s.card_id
           WHERE s.cost_center_id = o.cost_center_id
             AND c.id = (SELECT c2.id FROM employee_cost_cards c2
                          WHERE c2.employee_id = c.employee_id AND c2.status = 'APPROVED' AND c2.effective_from <= q.issue_date
                          ORDER BY c2.effective_from DESC LIMIT 1)) clr ON true
      LEFT JOIN LATERAL (SELECT id, hourly_cost FROM machine_rates
                          WHERE machine_id = o.machine_id AND effective_from <= q.issue_date
                          ORDER BY effective_from DESC LIMIT 1) mr ON true
      LEFT JOIN LATERAL (SELECT id, version, rate FROM overhead_pools
                          WHERE kind = 'MANUFACTURING' AND cost_center_id = o.cost_center_id AND status = 'APPROVED'
                            AND effective_from <= q.issue_date AND period_to >= q.issue_date
                          ORDER BY effective_from DESC LIMIT 1) pool ON true
  ) x;

-- The cost sheet of each estimate: direct cost, manufacturing cost, fully loaded
-- cost, recommended price and the margin of the quoted price. A missing rate
-- leaves the figure NULL and is named in `missing` — never replaced by zero.
CREATE OR REPLACE VIEW v_estimate_costs AS
WITH mat AS (
    SELECT estimate_id, sum(total_cost) AS cost, count(*) FILTER (WHERE total_cost IS NULL) AS missing, count(*) AS n
      FROM v_estimate_material_lines GROUP BY estimate_id
), ops AS (
    SELECT estimate_id, sum(labor_cost) AS labor, count(*) FILTER (WHERE labor_cost IS NULL) AS labor_missing,
           sum(machine_cost) AS machine, count(*) FILTER (WHERE machine_cost IS NULL) AS machine_missing,
           sum(overhead_cost) AS overhead, count(*) FILTER (WHERE overhead_cost IS NULL) AS overhead_missing,
           sum(total_labor_hours) AS labor_hours, sum(total_machine_hours) AS machine_hours, count(*) AS n
      FROM v_estimate_operation_lines GROUP BY estimate_id
), dir AS (
    SELECT d.estimate_id, sum(CASE d.basis WHEN 'PER_UNIT' THEN d.amount * l.quantity ELSE d.amount END) AS cost, count(*) AS n
      FROM cost_estimate_direct_costs d
      JOIN cost_estimates e ON e.id = d.estimate_id
      JOIN quotation_lines l ON l.quotation_id = e.quotation_id AND l.line_no = e.line_no
     GROUP BY d.estimate_id
), base AS (
    SELECT e.id AS estimate_id, e.quotation_id, e.line_no, l.quantity, e.pricing_method, e.target_pct, e.min_margin_pct,
           l.line_total, round(q.discount_amount * l.line_total / NULLIF(t.subtotal, 0), 2) AS discount_share,
           COALESCE(mat.n, 0) + COALESCE(ops.n, 0) + COALESCE(dir.n, 0) AS components,
           CASE WHEN COALESCE(mat.missing, 0) = 0 THEN round(COALESCE(mat.cost, 0), 2) END AS materials_cost,
           CASE WHEN COALESCE(ops.labor_missing, 0) = 0 THEN COALESCE(ops.labor, 0) END AS labor_cost,
           CASE WHEN COALESCE(ops.machine_missing, 0) = 0 THEN COALESCE(ops.machine, 0) END AS machine_cost,
           round(COALESCE(dir.cost, 0), 2) AS direct_other_cost,
           CASE WHEN COALESCE(ops.overhead_missing, 0) = 0 THEN COALESCE(ops.overhead, 0) END AS center_overhead,
           CASE WHEN fp.id IS NULL THEN 0
                ELSE round(CASE fp.driver WHEN 'MACHINE_HOURS' THEN COALESCE(ops.machine_hours, 0) ELSE COALESCE(ops.labor_hours, 0) END * fp.rate, 2)
           END AS factory_overhead,
           fp.id AS factory_pool_id, fp.version AS factory_pool_version, fp.rate AS factory_pool_rate,
           sa.id AS sa_pool_id, sa.version AS sa_pool_version, sa.rate AS selling_admin_pct,
           COALESCE(mat.missing, 0) AS material_lines_missing, COALESCE(ops.labor_missing, 0) AS labor_lines_missing,
           COALESCE(ops.machine_missing, 0) AS machine_lines_missing, COALESCE(ops.overhead_missing, 0) AS overhead_lines_missing,
           COALESCE(ops.labor_hours, 0) AS labor_hours, COALESCE(ops.machine_hours, 0) AS machine_hours
      FROM cost_estimates e
      JOIN quotations q ON q.id = e.quotation_id
      JOIN v_quotation_totals t ON t.quotation_id = q.id
      JOIN quotation_lines l ON l.quotation_id = e.quotation_id AND l.line_no = e.line_no
      LEFT JOIN mat ON mat.estimate_id = e.id
      LEFT JOIN ops ON ops.estimate_id = e.id
      LEFT JOIN dir ON dir.estimate_id = e.id
      LEFT JOIN LATERAL (SELECT id, version, rate, driver FROM overhead_pools
                          WHERE kind = 'MANUFACTURING' AND cost_center_id IS NULL AND status = 'APPROVED'
                            AND effective_from <= q.issue_date AND period_to >= q.issue_date
                          ORDER BY effective_from DESC LIMIT 1) fp ON true
      LEFT JOIN LATERAL (SELECT id, version, rate FROM overhead_pools
                          WHERE kind = 'SELLING_ADMIN' AND status = 'APPROVED'
                            AND effective_from <= q.issue_date AND period_to >= q.issue_date
                          ORDER BY effective_from DESC LIMIT 1) sa ON true
), cost AS (
    SELECT b.*,
           b.materials_cost + b.labor_cost + b.machine_cost + b.direct_other_cost AS direct_cost,
           b.center_overhead + b.factory_overhead AS overhead_cost,
           b.line_total - b.discount_share AS net_price
      FROM base b
), mfg AS (
    SELECT c.*, c.direct_cost + c.overhead_cost AS manufacturing_cost FROM cost c
), full_cost AS (
    SELECT m.*, round(m.manufacturing_cost * (1 + m.selling_admin_pct / 100), 2) AS fully_loaded_cost FROM mfg m
), priced AS (
    SELECT f.*,
           CASE f.pricing_method WHEN 'MARGIN' THEN round(f.fully_loaded_cost / (1 - f.target_pct / 100), 2)
                                 ELSE round(f.fully_loaded_cost * (1 + f.target_pct / 100), 2) END AS recommended_price
      FROM full_cost f
)
SELECT p.estimate_id, p.quotation_id, p.line_no, p.quantity, p.pricing_method, p.target_pct, p.min_margin_pct,
       p.materials_cost, p.labor_cost, p.machine_cost, p.direct_other_cost, p.direct_cost,
       p.center_overhead, p.factory_overhead, p.overhead_cost, p.manufacturing_cost,
       p.selling_admin_pct, p.fully_loaded_cost, p.recommended_price,
       p.line_total, p.discount_share, p.net_price,
       p.net_price - p.manufacturing_cost AS gross_profit,
       CASE WHEN p.net_price > 0 THEN round((p.net_price - p.manufacturing_cost) / p.net_price * 100, 2) END AS gross_margin_pct,
       CASE WHEN p.manufacturing_cost > 0 THEN round((p.net_price - p.manufacturing_cost) / p.manufacturing_cost * 100, 2) END AS markup_pct,
       p.net_price - p.fully_loaded_cost AS profit_after_overheads,
       CASE WHEN p.net_price > 0 THEN round((p.net_price - p.fully_loaded_cost) / p.net_price * 100, 2) END AS margin_after_overheads_pct,
       p.net_price - (p.materials_cost + p.direct_other_cost) AS contribution,
       p.labor_hours, p.machine_hours,
       p.factory_pool_id, p.factory_pool_version, p.factory_pool_rate, p.sa_pool_id, p.sa_pool_version,
       array_remove(ARRAY[
           CASE WHEN p.components = 0 THEN 'EMPTY_ESTIMATE' END,
           CASE WHEN p.material_lines_missing > 0 THEN 'MATERIAL_PRICE_OR_WASTE_MISSING' END,
           CASE WHEN p.labor_lines_missing > 0 THEN 'LABOR_RATE_MISSING' END,
           CASE WHEN p.machine_lines_missing > 0 THEN 'MACHINE_RATE_MISSING' END,
           CASE WHEN p.overhead_lines_missing > 0 THEN 'OVERHEAD_POOL_MISSING' END,
           CASE WHEN p.selling_admin_pct IS NULL THEN 'SELLING_ADMIN_POOL_MISSING' END
       ], NULL) AS missing,
       array_remove(ARRAY[
           CASE WHEN p.net_price < p.fully_loaded_cost THEN 'BELOW_FULLY_LOADED' END,
           CASE WHEN p.net_price < p.recommended_price THEN 'BELOW_TARGET' END,
           CASE WHEN p.net_price > 0 AND p.min_margin_pct IS NOT NULL
                 AND (p.net_price - p.fully_loaded_cost) / p.net_price * 100 < p.min_margin_pct THEN 'BELOW_MIN_MARGIN' END
       ], NULL) AS warnings
  FROM priced p;

-- Frozen at approval: never changes afterwards, whatever happens to the rates.
CREATE TABLE IF NOT EXISTS cost_estimate_snapshots (
    id                    bigserial PRIMARY KEY,
    estimate_id           bigint NOT NULL UNIQUE REFERENCES cost_estimates(id),
    quotation_id          bigint NOT NULL REFERENCES quotations(id),
    line_no               int NOT NULL,
    frozen_at             timestamptz NOT NULL DEFAULT now(),
    quantity              numeric(12,3) NOT NULL,
    materials_cost        numeric(16,2),
    labor_cost            numeric(16,2),
    machine_cost          numeric(16,2),
    direct_other_cost     numeric(16,2),
    overhead_cost         numeric(16,2),
    direct_cost           numeric(16,2),
    manufacturing_cost    numeric(16,2),
    selling_admin_pct     numeric(14,4),
    fully_loaded_cost     numeric(16,2),
    pricing_method        text NOT NULL,
    target_pct            numeric(6,2) NOT NULL,
    recommended_price     numeric(16,2),
    net_price             numeric(16,2) NOT NULL,
    gross_profit          numeric(16,2),
    gross_margin_pct      numeric(8,2),
    labor_hours           numeric(12,3) NOT NULL,
    machine_hours         numeric(12,3) NOT NULL,
    missing               text[] NOT NULL,
    warnings              text[] NOT NULL,
    detail                jsonb NOT NULL
);

CREATE OR REPLACE FUNCTION fn_snapshot_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'RROKA_SNAPSHOT_IMMUTABLE: the standard cost frozen at approval cannot change' USING ERRCODE = 'P0001';
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_snapshot_immutable ON cost_estimate_snapshots;
CREATE TRIGGER trg_snapshot_immutable BEFORE UPDATE OR DELETE ON cost_estimate_snapshots FOR EACH ROW EXECUTE FUNCTION fn_snapshot_immutable();

-- Approval: a quotation that requires costing needs a complete estimate on every
-- line; the estimates are then frozen with every rate they used.
CREATE OR REPLACE FUNCTION fn_quotation_costing_gate() RETURNS trigger AS $$
BEGIN
    IF NEW.status = 'APPROVED' AND OLD.status <> 'APPROVED' THEN
        IF NEW.requires_costing AND EXISTS (
            SELECT 1 FROM quotation_lines l
              LEFT JOIN cost_estimates e ON e.quotation_id = l.quotation_id AND e.line_no = l.line_no
              LEFT JOIN v_estimate_costs c ON c.estimate_id = e.id
             WHERE l.quotation_id = NEW.id AND (e.id IS NULL OR cardinality(c.missing) > 0)) THEN
            RAISE EXCEPTION 'RROKA_QUOTATION_COSTING_INCOMPLETE: every line needs a complete cost estimate' USING ERRCODE = 'P0001';
        END IF;
        INSERT INTO cost_estimate_snapshots (estimate_id, quotation_id, line_no, quantity, materials_cost, labor_cost, machine_cost,
               direct_other_cost, overhead_cost, direct_cost, manufacturing_cost, selling_admin_pct, fully_loaded_cost, pricing_method,
               target_pct, recommended_price, net_price, gross_profit, gross_margin_pct, labor_hours, machine_hours, missing, warnings, detail)
        SELECT c.estimate_id, c.quotation_id, c.line_no, c.quantity, c.materials_cost, c.labor_cost, c.machine_cost,
               c.direct_other_cost, c.overhead_cost, c.direct_cost, c.manufacturing_cost, c.selling_admin_pct, c.fully_loaded_cost, c.pricing_method,
               c.target_pct, c.recommended_price, c.net_price, c.gross_profit, c.gross_margin_pct, c.labor_hours, c.machine_hours, c.missing, c.warnings,
               jsonb_build_object(
                   'materials', COALESCE((SELECT jsonb_agg(to_jsonb(m) ORDER BY m.id) FROM v_estimate_material_lines m WHERE m.estimate_id = c.estimate_id), '[]'),
                   'operations', COALESCE((SELECT jsonb_agg(to_jsonb(o) ORDER BY o.seq, o.id) FROM v_estimate_operation_lines o WHERE o.estimate_id = c.estimate_id), '[]'),
                   'direct_costs', COALESCE((SELECT jsonb_agg(to_jsonb(d) ORDER BY d.id) FROM cost_estimate_direct_costs d WHERE d.estimate_id = c.estimate_id), '[]'),
                   'factory_pool', jsonb_build_object('id', c.factory_pool_id, 'version', c.factory_pool_version, 'rate', c.factory_pool_rate),
                   'selling_admin_pool', jsonb_build_object('id', c.sa_pool_id, 'version', c.sa_pool_version, 'pct', c.selling_admin_pct),
                   'quotation_date', (SELECT issue_date FROM quotations WHERE id = c.quotation_id))
          FROM v_estimate_costs c
          JOIN quotation_lines l ON l.quotation_id = c.quotation_id AND l.line_no = c.line_no
         WHERE c.quotation_id = NEW.id;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_quotation_costing_gate ON quotations;
CREATE TRIGGER trg_quotation_costing_gate AFTER UPDATE OF status ON quotations FOR EACH ROW EXECUTE FUNCTION fn_quotation_costing_gate();

DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['vat_rates', 'pricing_policies', 'cost_estimates', 'cost_estimate_materials', 'cost_estimate_operations',
                             'cost_estimate_direct_costs', 'cost_estimate_snapshots'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_audit()', t);
    END LOOP;
    FOREACH t IN ARRAY ARRAY['vat_rates', 'pricing_policies', 'cost_estimates'] LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS trg_touch_%1$s ON %1$I', t);
        EXECUTE format('CREATE TRIGGER trg_touch_%1$s BEFORE UPDATE ON %1$I FOR EACH ROW EXECUTE FUNCTION fn_touch_updated_at()', t);
    END LOOP;
END $$;
SQL);
    }
};
