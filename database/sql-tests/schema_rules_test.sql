-- =====================================================================
-- Business-rule tests for rroka_schema.sql. Run on a FRESH database:
--   scripts/test-db.sh
-- Every rule is tested both ways: the allowed path succeeds and the
-- forbidden path is rejected with the expected RROKA_* error.
-- Test data below is synthetic and exists only inside this test run.
-- =====================================================================

\set ON_ERROR_STOP 1
SET client_min_messages = warning;

CREATE TEMP TABLE test_results (name text, ok boolean, detail text);

-- Expect `sql` to fail with a message containing `needle`.
CREATE FUNCTION pg_temp.expect_error(test_name text, sql text, needle text) RETURNS void AS $$
BEGIN
    BEGIN
        EXECUTE sql;
        INSERT INTO test_results VALUES (test_name, false, 'no error raised');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO test_results VALUES (test_name, position(needle IN SQLERRM) > 0, SQLERRM);
    END;
END $$ LANGUAGE plpgsql;

CREATE FUNCTION pg_temp.expect_ok(test_name text, sql text) RETURNS void AS $$
BEGIN
    EXECUTE sql;
    INSERT INTO test_results VALUES (test_name, true, NULL);
EXCEPTION WHEN OTHERS THEN
    INSERT INTO test_results VALUES (test_name, false, SQLERRM);
END $$ LANGUAGE plpgsql;

CREATE FUNCTION pg_temp.expect_eq(test_name text, actual anyelement, expected anyelement) RETURNS void AS $$
BEGIN
    INSERT INTO test_results VALUES (test_name, actual IS NOT DISTINCT FROM expected,
        format('actual=%s expected=%s', actual, expected));
END $$ LANGUAGE plpgsql;

-- ---------------------------------------------------------------------
-- Fixtures
-- ---------------------------------------------------------------------
INSERT INTO users (id, name, email, password) VALUES
    (1, 'TEST user', 'test@example.invalid', 'x'), (2, 'TEST approver', 'approver@example.invalid', 'x');
SELECT setval('users_id_seq', 10);
SELECT set_config('rroka.user_id', '1', false);

INSERT INTO clients (id, business_name) VALUES (1, 'TEST client A'), (2, 'TEST client B');
INSERT INTO quotations (id, client_id) VALUES (1, 1), (2, 1), (3, 2);
INSERT INTO quotation_lines (quotation_id, line_no, description, quantity, unit_price) VALUES
    (1, 1, 'خزانة', 2, 1500), (1, 2, 'طاولة', 1, 1000), (2, 1, 'رف', 1, 300);

-- ---------------------------------------------------------------------
-- Quotations
-- ---------------------------------------------------------------------
SELECT pg_temp.expect_error('quotation: cannot send empty quotation',
    'UPDATE quotations SET status = ''SENT'' WHERE id = 3', 'RROKA_QUOTATION_EMPTY');
SELECT pg_temp.expect_error('quotation: DRAFT -> APPROVED skips SENT',
    'UPDATE quotations SET status = ''APPROVED'', approved_at = now(), approved_by = 2 WHERE id = 1',
    'RROKA_QUOTATION_TRANSITION');
SELECT pg_temp.expect_error('quotation: discount above subtotal rejected',
    'UPDATE quotations SET status = ''SENT'', discount_amount = 999999 WHERE id = 1', 'RROKA_QUOTATION_NEGATIVE');
SELECT pg_temp.expect_ok('quotation: DRAFT -> SENT',
    'UPDATE quotations SET status = ''SENT'', discount_amount = 500 WHERE id = 1');
SELECT pg_temp.expect_error('quotation: header frozen once SENT (discount)',
    'UPDATE quotations SET discount_amount = 0 WHERE id = 1', 'RROKA_QUOTATION_LOCKED');
SELECT pg_temp.expect_error('quotation: approving cannot sneak in other changes',
    'UPDATE quotations SET status = ''APPROVED'', approved_at = now(), approved_by = 2, discount_amount = 0 WHERE id = 1',
    'RROKA_QUOTATION_LOCKED');
SELECT pg_temp.expect_error('quotation: lines frozen once SENT',
    'UPDATE quotation_lines SET unit_price = 1 WHERE quotation_id = 1 AND line_no = 1', 'RROKA_QUOTATION_LOCKED');
SELECT pg_temp.expect_error('quotation: APPROVED requires approver (CHECK)',
    'UPDATE quotations SET status = ''APPROVED'' WHERE id = 1', 'check constraint');
SELECT pg_temp.expect_ok('quotation: SENT -> APPROVED',
    'UPDATE quotations SET status = ''APPROVED'', approved_at = now(), approved_by = 2 WHERE id = 1');
SELECT pg_temp.expect_error('quotation: APPROVED is frozen',
    'UPDATE quotations SET notes = ''x'' WHERE id = 1', 'RROKA_QUOTATION_LOCKED');
SELECT pg_temp.expect_ok('quotation: Daftra estimate id can be linked after approval',
    'UPDATE quotations SET daftra_estimate_id = 5001 WHERE id = 1');
SELECT pg_temp.expect_eq('quotation: net before VAT = 4000 - 500',
    (SELECT net_before_vat FROM v_quotation_totals WHERE quotation_id = 1), 3500.00::numeric);

-- ---------------------------------------------------------------------
-- Projects
-- ---------------------------------------------------------------------
SELECT pg_temp.expect_error('project: rejected on DRAFT quotation',
    'INSERT INTO projects (quotation_id, title, contract_value) VALUES (2, ''x'', 0)',
    'RROKA_PROJECT_NEEDS_APPROVED_QUOTATION');
SELECT pg_temp.expect_error('project: client must match quotation',
    'INSERT INTO projects (quotation_id, client_id, title, contract_value) VALUES (1, 2, ''x'', 0)',
    'RROKA_PROJECT_CLIENT_MISMATCH');
SELECT pg_temp.expect_ok('project: created on APPROVED quotation',
    'INSERT INTO projects (id, quotation_id, title, contract_value, start_date) VALUES (1, 1, ''TEST kitchen'', 1, ''2026-01-10'')');
SELECT pg_temp.expect_eq('project: contract value taken from quotation, not typed',
    (SELECT contract_value FROM projects WHERE id = 1), 3500.00::numeric);
SELECT pg_temp.expect_eq('project: client filled from quotation',
    (SELECT client_id FROM projects WHERE id = 1), 1::bigint);
SELECT pg_temp.expect_error('project: one project per quotation',
    'INSERT INTO projects (quotation_id, title, contract_value) VALUES (1, ''dup'', 0)', 'duplicate key');
SELECT pg_temp.expect_error('project: contract value immutable',
    'UPDATE projects SET contract_value = 99999 WHERE id = 1', 'RROKA_PROJECT_IMMUTABLE');

-- ---------------------------------------------------------------------
-- Designs
-- ---------------------------------------------------------------------
INSERT INTO designs (id, project_id, title) VALUES (1, 1, 'TEST design');
INSERT INTO design_versions (id, design_id, version_no) VALUES (1, 1, 1), (2, 1, 2);

SELECT pg_temp.expect_error('design: release requires client approval first',
    'UPDATE design_versions SET status = ''RELEASED_FOR_PRODUCTION'', released_at = now(), released_by = 1 WHERE id = 1',
    'RROKA_DESIGN_RELEASE_NEEDS_CLIENT_APPROVAL');
SELECT pg_temp.expect_error('design: DRAFT cannot skip client review',
    'UPDATE design_versions SET status = ''CLIENT_APPROVED'', client_approved_at = now() WHERE id = 1', 'RROKA_DESIGN_TRANSITION');
UPDATE design_versions SET status = 'CLIENT_REVIEW' WHERE id IN (1, 2);
SELECT pg_temp.expect_error('design: client approval needs its date',
    'UPDATE design_versions SET status = ''CLIENT_APPROVED'' WHERE id = 1', 'RROKA_DESIGN_TRANSITION');
UPDATE design_versions SET status = 'CLIENT_APPROVED', client_approved_at = now() WHERE id IN (1, 2);
SELECT pg_temp.expect_error('design: a new version starts as DRAFT',
    'INSERT INTO design_versions (design_id, version_no, status) VALUES (1, 9, ''CLIENT_APPROVED'')', 'RROKA_DESIGN_TRANSITION');
SELECT pg_temp.expect_ok('design: release v1',
    'UPDATE design_versions SET status = ''RELEASED_FOR_PRODUCTION'', released_at = now(), released_by = 1 WHERE id = 1');
SELECT pg_temp.expect_error('design: only ONE released version per design',
    'UPDATE design_versions SET status = ''RELEASED_FOR_PRODUCTION'', released_at = now(), released_by = 1 WHERE id = 2',
    'ux_design_one_released');

-- ---------------------------------------------------------------------
-- Materials, BOM
-- ---------------------------------------------------------------------
INSERT INTO raw_materials (id, code, name, uom) VALUES (1, 'MDF18', 'TEST MDF 18mm', 'لوح'), (2, 'HNG', 'TEST hinge', 'حبة');
INSERT INTO design_versions (id, design_id, version_no) VALUES (3, 1, 3);
SELECT pg_temp.expect_ok('bom: editable on draft version',
    'INSERT INTO design_bom_lines (design_version_id, material_id, quantity, waste_pct) VALUES (3, 1, 5, 10)');
SELECT pg_temp.expect_error('bom: released version BOM is frozen',
    'INSERT INTO design_bom_lines (design_version_id, material_id, quantity) VALUES (1, 1, 5)', 'RROKA_BOM_LOCKED');

-- ---------------------------------------------------------------------
-- Production orders
-- ---------------------------------------------------------------------
SELECT pg_temp.expect_error('production: rejected on unreleased design version',
    'INSERT INTO production_orders (project_id, design_version_id) VALUES (1, 2)',
    'RROKA_PRODUCTION_NEEDS_RELEASED_DESIGN');
SELECT pg_temp.expect_ok('production: created on released version',
    'INSERT INTO production_orders (id, project_id, design_version_id) VALUES (1, 1, 1)');

-- ---------------------------------------------------------------------
-- Inventory
-- ---------------------------------------------------------------------
SELECT pg_temp.expect_error('stock: balances cannot be written directly',
    'INSERT INTO stock_balances (material_id, qty_on_hand) VALUES (2, 100)', 'RROKA_DERIVED_TABLE');
SELECT pg_temp.expect_error('stock: receipt needs a unit cost',
    'INSERT INTO stock_movements (material_id, movement_type, quantity) VALUES (1, ''RECEIPT'', 10)', 'check constraint');
SELECT pg_temp.expect_ok('stock: receipt 10 @ 100',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, unit_cost) VALUES (1, ''RECEIPT'', 10, 100)');
SELECT pg_temp.expect_ok('stock: receipt 10 @ 120',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, unit_cost) VALUES (1, ''RECEIPT'', 10, 120)');
SELECT pg_temp.expect_eq('stock: weighted average cost = 110',
    (SELECT avg_unit_cost FROM stock_balances WHERE material_id = 1), 110.0000::numeric);
SELECT pg_temp.expect_ok('stock: reserve 8 for project',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, project_id) VALUES (1, ''RESERVE'', 8, 1)');
SELECT pg_temp.expect_error('stock: cannot reserve beyond available (20 - 8 = 12)',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, project_id) VALUES (1, ''RESERVE'', 13, 1)',
    'RROKA_STOCK_INSUFFICIENT');
SELECT pg_temp.expect_error('stock: free issue cannot eat reserved stock',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, project_id) VALUES (1, ''ISSUE'', 13, 1)',
    'RROKA_STOCK_INSUFFICIENT');
SELECT pg_temp.expect_error('stock: issue from reservation limited to reservation',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, project_id, from_reservation) VALUES (1, ''ISSUE'', 9, 1, true)',
    'RROKA_STOCK_ISSUE_EXCEEDS_RESERVATION');
SELECT pg_temp.expect_ok('stock: issue 6 from reservation',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, project_id, production_order_id, from_reservation) VALUES (1, ''ISSUE'', 6, 1, 1, true)');
SELECT pg_temp.expect_eq('stock: on_hand/reserved/issued = 14/2/6',
    (SELECT format('%s/%s/%s', qty_on_hand::int, qty_reserved::int, qty_issued_total::int) FROM stock_balances WHERE material_id = 1),
    '14/2/6');
SELECT pg_temp.expect_eq('stock: issue priced at average cost',
    (SELECT unit_cost FROM stock_movements WHERE movement_type = 'ISSUE' ORDER BY id DESC LIMIT 1), 110.0000::numeric);
SELECT pg_temp.expect_error('stock: return cannot exceed net issued',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, project_id) VALUES (1, ''RETURN'', 7, 1)',
    'RROKA_STOCK_RETURN_EXCEEDS_ISSUED');
SELECT pg_temp.expect_ok('stock: return 1',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, project_id) VALUES (1, ''RETURN'', 1, 1)');
SELECT pg_temp.expect_error('stock: unreserve cannot exceed project reservation',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, project_id) VALUES (1, ''UNRESERVE'', 3, 1)',
    'RROKA_STOCK_UNRESERVE_EXCEEDS');
SELECT pg_temp.expect_error('stock: movements are immutable',
    'UPDATE stock_movements SET quantity = 1 WHERE id = (SELECT min(id) FROM stock_movements)', 'RROKA_MOVEMENT_IMMUTABLE');
SELECT pg_temp.expect_error('stock: adjustment needs a reason',
    'INSERT INTO stock_movements (material_id, movement_type, quantity) VALUES (1, ''ADJUST_OUT'', 1)', 'check constraint');
SELECT pg_temp.expect_eq('stock: balance equals replay of movements',
    (SELECT qty_on_hand FROM stock_balances WHERE material_id = 1),
    (SELECT sum(CASE WHEN movement_type IN ('RECEIPT','ADJUST_IN','RETURN') THEN quantity
                     WHEN movement_type IN ('ISSUE','ADJUST_OUT') THEN -quantity ELSE 0 END)
       FROM stock_movements WHERE material_id = 1));

-- ---------------------------------------------------------------------
-- Costing — Zero Assumption Policy
-- ---------------------------------------------------------------------
INSERT INTO workers (id, name) VALUES (1, 'TEST worker 1'), (2, 'TEST worker 2');
INSERT INTO machines (id, code, name) VALUES (1, 'CNC', 'TEST CNC');

SELECT pg_temp.expect_error('time: cannot log on PLANNED order',
    'INSERT INTO labor_logs (production_order_id, worker_id, work_date, hours) VALUES (1, 1, ''2026-02-01'', 8)',
    'RROKA_TIME_LOG_ORDER_STATE');
SELECT pg_temp.expect_error('production: PLANNED cannot jump to COMPLETED',
    'UPDATE production_orders SET status = ''COMPLETED'', completed_at = now() WHERE id = 1', 'RROKA_PRODUCTION_TRANSITION');
UPDATE production_orders SET status = 'IN_PROGRESS' WHERE id = 1;
SELECT pg_temp.expect_eq('production: start stamps started_at', (SELECT started_at IS NOT NULL FROM production_orders WHERE id = 1), true);
INSERT INTO labor_logs (production_order_id, worker_id, work_date, hours) VALUES
    (1, 1, '2026-02-01', 8), (1, 2, '2026-02-01', 4);
INSERT INTO machine_logs (production_order_id, machine_id, work_date, hours) VALUES (1, 1, '2026-02-01', 3);
SELECT pg_temp.expect_error('time: worker cannot exceed 24h per day',
    'INSERT INTO labor_logs (production_order_id, worker_id, work_date, hours) VALUES (1, 1, ''2026-02-01'', 17)',
    'RROKA_LABOR_DAY_OVER_24H');

SELECT pg_temp.expect_eq('costing: material cost known = (6-1) x 110',
    (SELECT material_cost FROM v_project_actual_cost WHERE project_id = 1), 550.00::numeric);
SELECT pg_temp.expect_eq('costing: NO rates -> labor cost is NULL, not 0',
    (SELECT labor_cost FROM v_project_actual_cost WHERE project_id = 1), NULL::numeric);
SELECT pg_temp.expect_eq('costing: NO rates -> total cost and profit are NULL',
    (SELECT (total_cost IS NULL AND gross_profit IS NULL AND gross_margin_pct IS NULL) FROM v_project_actual_cost WHERE project_id = 1), true);
SELECT pg_temp.expect_eq('costing: gaps reported explicitly',
    (SELECT costing_gaps FROM v_project_actual_cost WHERE project_id = 1),
    ARRAY['WORKER_RATE_MISSING', 'MACHINE_RATE_MISSING', 'OVERHEAD_RATE_MISSING']);

-- Only worker 1 gets a rate: labor must STAY NULL (partial sum would understate cost).
INSERT INTO worker_rates (worker_id, hourly_cost, effective_from, basis_note) VALUES (1, 50, '2026-01-01', 'TEST');
SELECT pg_temp.expect_eq('costing: partial rates -> labor still NULL (no understated cost)',
    (SELECT labor_cost FROM v_project_actual_cost WHERE project_id = 1), NULL::numeric);
SELECT pg_temp.expect_eq('costing: exactly one labor line missing a rate',
    (SELECT labor_lines_missing_rate FROM v_project_actual_cost WHERE project_id = 1), 1::bigint);

-- Rate effective AFTER the work date must not apply retroactively.
INSERT INTO worker_rates (worker_id, hourly_cost, effective_from, basis_note) VALUES (2, 40, '2026-03-01', 'TEST');
SELECT pg_temp.expect_eq('costing: future-dated rate not applied to past work',
    (SELECT labor_cost FROM v_project_actual_cost WHERE project_id = 1), NULL::numeric);
INSERT INTO worker_rates (worker_id, hourly_cost, effective_from, basis_note) VALUES (2, 30, '2026-01-01', 'TEST');
INSERT INTO machine_rates (machine_id, hourly_cost, effective_from, basis_note) VALUES (1, 70, '2026-01-01', 'TEST');
SELECT pg_temp.expect_eq('costing: labor = 8x50 + 4x30 = 520',
    (SELECT labor_cost FROM v_project_actual_cost WHERE project_id = 1), 520.00::numeric);
SELECT pg_temp.expect_eq('costing: machine = 3x70 = 210',
    (SELECT machine_cost FROM v_project_actual_cost WHERE project_id = 1), 210.00::numeric);
SELECT pg_temp.expect_eq('costing: still no total while overhead rate missing',
    (SELECT total_cost FROM v_project_actual_cost WHERE project_id = 1), NULL::numeric);

INSERT INTO overhead_rates (basis, rate_pct, effective_from, basis_note) VALUES ('PCT_OF_DIRECT_LABOR', 20, '2026-01-01', 'TEST');
SELECT pg_temp.expect_eq('costing: overhead = 20% x 520 = 104',
    (SELECT overhead_cost FROM v_project_actual_cost WHERE project_id = 1), 104.00::numeric);
SELECT pg_temp.expect_eq('costing: total = 550 + 520 + 210 + 104 = 1384',
    (SELECT total_cost FROM v_project_actual_cost WHERE project_id = 1), 1384.00::numeric);
SELECT pg_temp.expect_eq('costing: profit = 3500 - 1384 = 2116',
    (SELECT gross_profit FROM v_project_actual_cost WHERE project_id = 1), 2116.00::numeric);
SELECT pg_temp.expect_eq('costing: no gaps once all rates exist',
    (SELECT cardinality(costing_gaps) FROM v_project_actual_cost WHERE project_id = 1), 0);
SELECT pg_temp.expect_error('costing: rate history is append-only',
    'UPDATE worker_rates SET hourly_cost = 1 WHERE worker_id = 1', 'RROKA_RATE_HISTORY');
SELECT pg_temp.expect_error('costing: zero rate rejected (a rate is real or absent)',
    'INSERT INTO worker_rates (worker_id, hourly_cost, effective_from, basis_note) VALUES (1, 0, ''2026-05-01'', ''x'')',
    'check constraint');

-- ---------------------------------------------------------------------
-- Quality gate
-- ---------------------------------------------------------------------
SELECT pg_temp.expect_error('quality: order cannot complete without FINAL pass',
    'UPDATE production_orders SET status = ''COMPLETED'', completed_at = now() WHERE id = 1',
    'RROKA_PRODUCTION_NEEDS_FINAL_QC');
SELECT pg_temp.expect_error('quality: failed inspection needs findings',
    'INSERT INTO quality_inspections (production_order_id, stage, result, inspector_id) VALUES (1, ''FINAL'', ''FAIL'', 2)',
    'check constraint');
INSERT INTO quality_inspections (production_order_id, stage, result, inspector_id) VALUES (1, 'FINAL', 'PASS', 2);
SELECT pg_temp.expect_ok('quality: completes after FINAL pass',
    'UPDATE production_orders SET status = ''COMPLETED'', completed_at = now() WHERE id = 1');
SELECT pg_temp.expect_error('production: closed order cannot reopen',
    'UPDATE production_orders SET status = ''IN_PROGRESS'' WHERE id = 1', 'RROKA_PRODUCTION_ORDER_CLOSED');

-- ---------------------------------------------------------------------
-- Installation, Daftra log, audit
-- ---------------------------------------------------------------------
SELECT pg_temp.expect_error('installation: completion needs client sign-off',
    'INSERT INTO installations (project_id, status, completed_at) VALUES (1, ''COMPLETED'', now())', 'check constraint');
SELECT pg_temp.expect_error('daftra log: SUCCESS must carry the Daftra id',
    'INSERT INTO daftra_sync_log (entity_type, entity_id, operation, status) VALUES (''CLIENT'', 1, ''POST /clients'', ''SUCCESS'')',
    'check constraint');
SELECT pg_temp.expect_error('daftra log: FAILED must carry the error',
    'INSERT INTO daftra_sync_log (entity_type, entity_id, operation, status) VALUES (''CLIENT'', 1, ''POST /clients'', ''FAILED'')',
    'check constraint');
SELECT pg_temp.expect_eq('audit: actions recorded with acting user',
    (SELECT count(*) > 0 FROM audit_log WHERE table_name = 'quotations' AND user_id = 1), true);
SELECT pg_temp.expect_error('audit: log is immutable',
    'DELETE FROM audit_log', 'RROKA_AUDIT_IMMUTABLE');
SELECT pg_temp.expect_error('client: VAT number must be 15 digits',
    'INSERT INTO clients (business_name, vat_number) VALUES (''x'', ''123'')', 'check constraint');
SELECT pg_temp.expect_eq('zero assumption: no cost rates are seeded by the schema',
    (SELECT count(*) FROM worker_rates WHERE basis_note <> 'TEST')
  + (SELECT count(*) FROM machine_rates WHERE basis_note <> 'TEST')
  + (SELECT count(*) FROM overhead_rates WHERE basis_note <> 'TEST'), 0::bigint);


-- ---------------------------------------------------------------------
-- Project stage transitions
-- ---------------------------------------------------------------------
SELECT pg_temp.expect_error('project stage: ACTIVE cannot jump to COMPLETED',
    'UPDATE projects SET status = ''COMPLETED'', completed_at = now() WHERE id = 1', 'RROKA_PROJECT_TRANSITION');
SELECT pg_temp.expect_ok('project stage: ACTIVE -> IN_PRODUCTION',
    'UPDATE projects SET status = ''IN_PRODUCTION'' WHERE id = 1');
SELECT pg_temp.expect_ok('project stage: IN_PRODUCTION -> ON_HOLD -> INSTALLATION',
    'UPDATE projects SET status = ''ON_HOLD'' WHERE id = 1; UPDATE projects SET status = ''INSTALLATION'' WHERE id = 1');
SELECT pg_temp.expect_error('project stage: completion needs completed_at (CHECK)',
    'UPDATE projects SET status = ''COMPLETED'' WHERE id = 1', 'check constraint');
SELECT pg_temp.expect_ok('project stage: INSTALLATION -> COMPLETED',
    'UPDATE projects SET status = ''COMPLETED'', completed_at = now() WHERE id = 1');
SELECT pg_temp.expect_error('project stage: completed project is final',
    'UPDATE projects SET status = ''ACTIVE'' WHERE id = 1', 'RROKA_PROJECT_TRANSITION');

-- ---------------------------------------------------------------------
-- Studio (image library)
-- ---------------------------------------------------------------------
INSERT INTO quotations (id, client_id) VALUES (20, 1), (21, 2);
INSERT INTO studio_assets (id, title, category, client_id, disk, path, mime_type, size_bytes, sha256) VALUES
    (1, 'TEST client A photo', 'CLIENT_REFERENCE', 1, 'studio', 't/1.jpg', 'image/jpeg', 100, repeat('a', 64)),
    (2, 'TEST finished wardrobe', 'FINISHED_WORK', NULL, 'studio', 't/2.jpg', 'image/jpeg', 100, repeat('b', 64));

SELECT pg_temp.expect_error('studio: customer photo must name the customer',
    'INSERT INTO studio_assets (title, category, disk, path, mime_type, size_bytes, sha256) VALUES (''x'', ''CLIENT_REFERENCE'', ''studio'', ''t/3.jpg'', ''image/jpeg'', 1, repeat(''c'', 64))',
    'studio_client_reference_has_client');
SELECT pg_temp.expect_error('studio: same file cannot be uploaded twice',
    'INSERT INTO studio_assets (title, category, disk, path, mime_type, size_bytes, sha256) VALUES (''x'', ''CATALOG'', ''studio'', ''t/4.jpg'', ''image/jpeg'', 1, repeat(''b'', 64))',
    'duplicate key');
SELECT pg_temp.expect_error('studio: only jpeg/png/webp images',
    'INSERT INTO studio_assets (title, category, disk, path, mime_type, size_bytes, sha256) VALUES (''x'', ''CATALOG'', ''studio'', ''t/5.pdf'', ''application/pdf'', 1, repeat(''d'', 64))',
    'check constraint');
SELECT pg_temp.expect_error('studio: file identity is immutable',
    'UPDATE studio_assets SET path = ''t/other.jpg'' WHERE id = 2', 'RROKA_STUDIO_FILE_IMMUTABLE');
SELECT pg_temp.expect_ok('studio: title and tags are editable',
    'UPDATE studio_assets SET title = ''TEST wardrobe, oak'', tags = ''oak'' WHERE id = 2');
SELECT pg_temp.expect_ok('studio: linking a project fills its customer',
    'UPDATE studio_assets SET project_id = 1 WHERE id = 2');
SELECT pg_temp.expect_eq('studio: customer taken from project', (SELECT client_id FROM studio_assets WHERE id = 2), 1::bigint);
SELECT pg_temp.expect_error('studio: project and customer must agree',
    'UPDATE studio_assets SET client_id = 2 WHERE id = 2', 'RROKA_STUDIO_CLIENT_MISMATCH');

SELECT pg_temp.expect_ok('studio: customer photo in that customer''s quotation',
    'INSERT INTO quotation_lines (quotation_id, line_no, description, quantity, unit_price, studio_asset_id) VALUES (20, 1, ''TEST'', 1, 100, 1)');
SELECT pg_temp.expect_error('studio: customer photo refused in another customer''s quotation',
    'INSERT INTO quotation_lines (quotation_id, line_no, description, quantity, unit_price, studio_asset_id) VALUES (21, 1, ''TEST'', 1, 100, 1)',
    'RROKA_STUDIO_PRIVATE_ASSET');
SELECT pg_temp.expect_ok('studio: finished-work photo usable in any quotation',
    'INSERT INTO quotation_lines (quotation_id, line_no, description, quantity, unit_price, studio_asset_id) VALUES (21, 1, ''TEST'', 1, 100, 2)');
SELECT pg_temp.expect_error('studio: quotation cannot move to another customer while showing a customer photo',
    'UPDATE quotations SET client_id = 2 WHERE id = 20', 'RROKA_STUDIO_PRIVATE_ASSET');
SELECT pg_temp.expect_error('studio: image used in a quotation cannot be deleted',
    'DELETE FROM studio_assets WHERE id = 1', 'RROKA_STUDIO_ASSET_IN_USE');
SELECT pg_temp.expect_error('studio: used customer photo keeps its customer',
    'UPDATE studio_assets SET category = ''CATALOG'' WHERE id = 1', 'RROKA_STUDIO_ASSET_IN_USE');
SELECT pg_temp.expect_ok('studio: unused image can be deleted',
    'INSERT INTO studio_assets (id, title, category, disk, path, mime_type, size_bytes, sha256) VALUES (9, ''x'', ''CATALOG'', ''studio'', ''t/9.jpg'', ''image/png'', 1, repeat(''e'', 64)); DELETE FROM studio_assets WHERE id = 9');
SELECT pg_temp.expect_eq('studio: uploads are audited',
    (SELECT count(*) FROM audit_log WHERE table_name = 'studio_assets' AND row_id = 9), 2::bigint);

-- ---------------------------------------------------------------------
-- Report
-- ---------------------------------------------------------------------
\pset footer off
SELECT CASE WHEN ok THEN 'PASS' ELSE 'FAIL' END AS result, name,
       CASE WHEN ok THEN '' ELSE detail END AS detail
  FROM test_results ORDER BY ok, name;

SELECT count(*) FILTER (WHERE ok) AS passed, count(*) FILTER (WHERE NOT ok) AS failed FROM test_results;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM test_results WHERE NOT ok) THEN
        RAISE EXCEPTION 'SCHEMA TESTS FAILED';
    END IF;
END $$;
