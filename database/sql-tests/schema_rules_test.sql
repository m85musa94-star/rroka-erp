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
-- Quotations of the workflow tests predate the costing gate (tested in its own section).
INSERT INTO quotations (id, client_id, requires_costing) VALUES (1, 1, false), (2, 1, false), (3, 2, false);
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
INSERT INTO quotations (id, client_id, requires_costing) VALUES (20, 1, false), (21, 2, false);
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
-- HR core
-- ---------------------------------------------------------------------
INSERT INTO departments (id, name) VALUES (1, 'TEST workshop'), (2, 'TEST office');
SELECT pg_temp.expect_eq('hr: existing workers got employee numbers',
    (SELECT count(*) FROM workers WHERE employee_no IS NULL), 0::bigint);
SELECT pg_temp.expect_error('hr: employee number is fixed',
    'UPDATE workers SET employee_no = ''X'' WHERE id = 1', 'RROKA_EMPLOYEE_IMMUTABLE');
SELECT pg_temp.expect_ok('hr: reporting line 2 -> 1',
    'UPDATE workers SET manager_id = 1, department_id = 1 WHERE id = 2');
SELECT pg_temp.expect_error('hr: reporting line cannot loop',
    'UPDATE workers SET manager_id = 2 WHERE id = 1', 'RROKA_EMPLOYEE_MANAGER_LOOP');
SELECT pg_temp.expect_error('hr: IBAN must be a Saudi IBAN',
    'UPDATE workers SET iban = ''DE89370400440532013000'' WHERE id = 1', 'workers_iban_format');
SELECT pg_temp.expect_ok('hr: valid Saudi IBAN shape',
    'UPDATE workers SET iban = ''SA0380000000608010167519'' WHERE id = 1');
SELECT pg_temp.expect_error('hr: ending employment needs date and reason',
    'UPDATE workers SET is_active = false WHERE id = 2', 'RROKA_EMPLOYEE_TERMINATION');
SELECT pg_temp.expect_error('hr: same ID number twice refused',
    'UPDATE workers SET id_type = ''IQAMA'', id_number = ''2000000001'' WHERE id IN (1, 2)', 'ux_workers_id_number');
SELECT pg_temp.expect_error('hr: document expiry after issue',
    'INSERT INTO employee_documents (employee_id, doc_type, issue_date, expiry_date) VALUES (1, ''IQAMA'', ''2026-05-01'', ''2026-01-01'')', 'check constraint');
SELECT pg_temp.expect_ok('hr: document with expiry',
    'INSERT INTO employee_documents (employee_id, doc_type, doc_number, issue_date, expiry_date) VALUES (1, ''IQAMA'', ''2000000001'', ''2026-01-01'', ''2027-01-01'')');
UPDATE workers SET hire_date = '2026-03-01' WHERE id = 2;
SELECT pg_temp.expect_error('hr: no hours before hire date',
    'INSERT INTO labor_logs (production_order_id, worker_id, work_date, hours) VALUES (1, 2, ''2026-02-15'', 1)', 'RROKA_EMPLOYEE_NOT_EMPLOYED');
SELECT pg_temp.expect_ok('hr: end employment with date and reason',
    'UPDATE workers SET is_active = false, termination_date = ''2026-06-30'', termination_reason = ''TEST end of contract'' WHERE id = 2');
SELECT pg_temp.expect_error('hr: termination before hire refused',
    'UPDATE workers SET termination_date = ''2026-01-01'' WHERE id = 2', 'workers_termination_after_hire');

-- ---------------------------------------------------------------------
-- HR contracts, time off, attendance
-- ---------------------------------------------------------------------
INSERT INTO workers (id, name, hire_date, user_id) VALUES (10, 'TEST staff', '2026-01-01', 1), (11, 'TEST staff 2', '2026-01-01', NULL);
SELECT pg_temp.expect_error('contract: fixed term needs an end date',
    'INSERT INTO employee_contracts (employee_id, contract_type, start_date, basic_salary, housing_allowance, transport_allowance, other_allowance) VALUES (10, ''FIXED_TERM'', ''2026-01-01'', 3000, 0, 0, 0)',
    'check constraint');
SELECT pg_temp.expect_ok('contract: running contract',
    'INSERT INTO employee_contracts (id, employee_id, contract_type, start_date, end_date, basic_salary, housing_allowance, transport_allowance, other_allowance, status) VALUES (1, 10, ''FIXED_TERM'', ''2026-01-01'', ''2026-12-31'', 3000, 750, 300, 0, ''RUNNING'')');
SELECT pg_temp.expect_eq('contract: monthly gross = 4050', (SELECT monthly_gross FROM v_contract_totals WHERE contract_id = 1), 4050.00::numeric);
SELECT pg_temp.expect_error('contract: one running contract per employee',
    'INSERT INTO employee_contracts (employee_id, contract_type, start_date, basic_salary, housing_allowance, transport_allowance, other_allowance, status) VALUES (10, ''INDEFINITE'', ''2026-02-01'', 3500, 0, 0, 0, ''RUNNING'')',
    'ux_contract_one_running');
SELECT pg_temp.expect_error('contract: running terms are fixed',
    'UPDATE employee_contracts SET basic_salary = 9000 WHERE id = 1', 'RROKA_CONTRACT_LOCKED');
SELECT pg_temp.expect_error('contract: expired cannot run again',
    'UPDATE employee_contracts SET status = ''EXPIRED'' WHERE id = 1; UPDATE employee_contracts SET status = ''RUNNING'' WHERE id = 1', 'RROKA_CONTRACT_TRANSITION');

INSERT INTO leave_types (id, name, is_paid, requires_allocation) VALUES (1, 'TEST annual', true, true), (2, 'TEST unpaid', false, false);
INSERT INTO leave_allocations (employee_id, leave_type_id, days, valid_from, valid_to, reason, approved_by)
    VALUES (10, 1, 5, '2026-01-01', '2026-12-31', 'TEST', 2);
SELECT pg_temp.expect_error('allocation: zero days refused',
    'INSERT INTO leave_allocations (employee_id, leave_type_id, days, valid_from, valid_to, reason, approved_by) VALUES (10, 1, 0, ''2026-01-01'', ''2026-12-31'', ''x'', 2)', 'check constraint');
SELECT pg_temp.expect_error('allocations are a ledger',
    'UPDATE leave_allocations SET days = 50', 'RROKA_ALLOCATION_IMMUTABLE');
INSERT INTO leave_requests (id, employee_id, leave_type_id, date_from, date_to, days) VALUES (1, 10, 1, '2026-04-05', '2026-04-08', 4);
SELECT pg_temp.expect_error('leave: days cannot exceed the date span',
    'INSERT INTO leave_requests (employee_id, leave_type_id, date_from, date_to, days) VALUES (11, 2, ''2026-04-05'', ''2026-04-06'', 3)', 'check constraint');
SELECT pg_temp.expect_error('leave: overlapping request refused',
    'INSERT INTO leave_requests (employee_id, leave_type_id, date_from, date_to, days) VALUES (10, 2, ''2026-04-07'', ''2026-04-10'', 4)', 'RROKA_LEAVE_OVERLAP');
SELECT pg_temp.expect_error('leave: nobody approves their own leave',
    'UPDATE leave_requests SET status = ''APPROVED'', approved_by = 1, approved_at = now() WHERE id = 1', 'RROKA_LEAVE_SELF_APPROVAL');
SELECT pg_temp.expect_ok('leave: approved by someone else within balance',
    'UPDATE leave_requests SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 1');
SELECT pg_temp.expect_eq('leave: balance 5 - 4 = 1', fn_leave_balance(10, 1, '2026-06-01'), 1.00::numeric);
INSERT INTO leave_requests (id, employee_id, leave_type_id, date_from, date_to, days) VALUES (2, 10, 1, '2026-05-10', '2026-05-11', 2);
SELECT pg_temp.expect_error('leave: cannot approve beyond balance',
    'UPDATE leave_requests SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 2', 'RROKA_LEAVE_BALANCE');
INSERT INTO leave_allocations (employee_id, leave_type_id, days, valid_from, valid_to, reason, approved_by)
    VALUES (10, 1, 2, '2026-01-01', '2026-12-31', 'TEST extra', 2), (10, 1, -1, '2026-01-01', '2026-12-31', 'TEST correction', 2);
SELECT pg_temp.expect_eq('leave: balance after extra 2 and correction -1 = 2', fn_leave_balance(10, 1, '2026-06-01'), 2.00::numeric);
SELECT pg_temp.expect_error('leave: refusal needs a reason',
    'UPDATE leave_requests SET status = ''REFUSED'' WHERE id = 2', 'check constraint');
SELECT pg_temp.expect_error('leave: decided request is locked',
    'UPDATE leave_requests SET days = 1 WHERE id = 1', 'RROKA_LEAVE_LOCKED');

SELECT pg_temp.expect_ok('attendance: check in and out',
    'INSERT INTO attendances (employee_id, check_in, check_out) VALUES (11, ''2026-03-01 07:00+03'', ''2026-03-01 16:00+03'')');
SELECT pg_temp.expect_eq('attendance: worked hours computed', (SELECT worked_hours FROM attendances WHERE employee_id = 11), 9.00::numeric);
SELECT pg_temp.expect_error('attendance: overlapping record refused',
    'INSERT INTO attendances (employee_id, check_in, check_out) VALUES (11, ''2026-03-01 15:00+03'', ''2026-03-01 18:00+03'')', 'RROKA_ATTENDANCE_OVERLAP');
SELECT pg_temp.expect_error('attendance: check-out after check-in',
    'INSERT INTO attendances (employee_id, check_in, check_out) VALUES (11, ''2026-03-02 16:00+03'', ''2026-03-02 07:00+03'')', 'check constraint');
SELECT pg_temp.expect_error('attendance: not on an approved leave day',
    'INSERT INTO attendances (employee_id, check_in) VALUES (10, ''2026-04-06 07:00+03'')', 'RROKA_EMPLOYEE_ON_LEAVE');
SELECT pg_temp.expect_error('attendance: not in the future',
    'INSERT INTO attendances (employee_id, check_in) VALUES (11, now() + interval ''2 days'')', 'RROKA_ATTENDANCE_FUTURE');
SELECT pg_temp.expect_ok('attendance: open record',
    'INSERT INTO attendances (employee_id, check_in) VALUES (11, ''2026-03-03 07:00+03'')');
SELECT pg_temp.expect_error('attendance: an open record blocks a new check-in until check-out',
    'INSERT INTO attendances (employee_id, check_in) VALUES (11, ''2026-03-04 07:00+03'')', 'RROKA_ATTENDANCE_OVERLAP');
SELECT pg_temp.expect_error('time: no production hours on a leave day',
    'INSERT INTO labor_logs (production_order_id, worker_id, work_date, hours) VALUES (1, 10, ''2026-04-06'', 2)', 'RROKA_EMPLOYEE_ON_LEAVE');

-- ---------------------------------------------------------------------
-- Purchasing & expenses
-- ---------------------------------------------------------------------
INSERT INTO suppliers (id, name, vat_number) VALUES (1, 'TEST timber supplier', '300000000000003');
SELECT pg_temp.expect_error('supplier: VAT number is 15 digits',
    'INSERT INTO suppliers (name, vat_number) VALUES (''x'', ''12345'')', 'check constraint');
INSERT INTO raw_materials (id, code, name, uom) VALUES (5, 'PLY', 'TEST plywood', 'sheet'), (6, 'GLUE', 'TEST glue', 'L');
INSERT INTO purchase_invoices (id, supplier_id, supplier_invoice_no, invoice_date, discount_amount, vat_amount)
    VALUES (1, 1, 'INV-77', '2026-03-10', 30, 150);
INSERT INTO purchase_invoice_lines (purchase_invoice_id, line_no, material_id, quantity, unit_price)
    VALUES (1, 1, 5, 10, 90), (1, 2, 6, 5, 20);   -- 900 + 100 = 1000, discount 30
SELECT setval('purchase_invoices_id_seq', 10);
SELECT pg_temp.expect_error('purchase: same supplier invoice cannot be entered twice',
    'INSERT INTO purchase_invoices (supplier_id, supplier_invoice_no, invoice_date, discount_amount, vat_amount) VALUES (1, '' inv-77'', ''2026-03-11'', 0, 0)',
    'ux_purchase_supplier_invoice');
SELECT pg_temp.expect_error('purchase: starts as draft',
    'INSERT INTO purchase_invoices (supplier_id, supplier_invoice_no, invoice_date, discount_amount, vat_amount, status, approved_by, approved_at) VALUES (1, ''X1'', ''2026-03-11'', 0, 0, ''APPROVED'', 1, now())',
    'RROKA_PURCHASE_TRANSITION');
SELECT pg_temp.expect_error('purchase: approval needs approver (CHECK)',
    'UPDATE purchase_invoices SET status = ''APPROVED'' WHERE id = 1', 'check constraint');
SELECT pg_temp.expect_ok('purchase: approve posts stock receipts',
    'UPDATE purchase_invoices SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 1');
SELECT pg_temp.expect_eq('purchase: plywood received at net cost (900 - 27) / 10 = 87.3',
    (SELECT unit_cost FROM stock_movements WHERE purchase_invoice_line_id = (SELECT id FROM purchase_invoice_lines WHERE purchase_invoice_id = 1 AND line_no = 1)),
    87.3000::numeric);
SELECT pg_temp.expect_eq('purchase: discount fully absorbed (net received value = 970)',
    (SELECT sum(quantity * unit_cost) FROM stock_movements WHERE purchase_invoice_line_id IN (SELECT id FROM purchase_invoice_lines WHERE purchase_invoice_id = 1)),
    970.0000::numeric);
SELECT pg_temp.expect_eq('purchase: stock on hand from invoice', (SELECT qty_on_hand FROM stock_balances WHERE material_id = 5), 10.0000::numeric);
SELECT pg_temp.expect_eq('purchase: totals view (net 970 + VAT 150)', (SELECT total FROM v_purchase_totals WHERE purchase_invoice_id = 1), 1120.00::numeric);
SELECT pg_temp.expect_error('purchase: approved invoice is final',
    'UPDATE purchase_invoices SET discount_amount = 0 WHERE id = 1', 'RROKA_PURCHASE_LOCKED');
SELECT pg_temp.expect_error('purchase: approved lines are final',
    'UPDATE purchase_invoice_lines SET quantity = 99 WHERE purchase_invoice_id = 1', 'RROKA_PURCHASE_LOCKED');
SELECT pg_temp.expect_error('purchase: approved invoice cannot be cancelled',
    'UPDATE purchase_invoices SET status = ''CANCELLED'' WHERE id = 1', 'RROKA_PURCHASE_LOCKED');
SELECT pg_temp.expect_ok('purchase: Daftra id can be linked after approval',
    'UPDATE purchase_invoices SET daftra_purchase_id = 555 WHERE id = 1');
INSERT INTO purchase_invoices (id, supplier_id, supplier_invoice_no, invoice_date, discount_amount, vat_amount) VALUES (20, 1, 'INV-78', '2026-03-12', 0, 0);
SELECT pg_temp.expect_error('purchase: cannot approve without lines',
    'UPDATE purchase_invoices SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 20', 'RROKA_PURCHASE_EMPTY');

INSERT INTO expense_categories (id, name, is_overhead) VALUES (1, 'TEST transport', false), (2, 'TEST workshop rent', true);
SELECT pg_temp.expect_error('expense: needs a supplier or payee',
    'INSERT INTO expenses (expense_date, category_id, description, amount, vat_amount, payment_method) VALUES (''2026-03-01'', 1, ''x'', 10, 0, ''CASH'')',
    'check constraint');
SELECT pg_temp.expect_error('expense: petty cash names the employee',
    'INSERT INTO expenses (expense_date, category_id, payee, description, amount, vat_amount, payment_method) VALUES (''2026-03-01'', 1, ''x'', ''x'', 10, 0, ''PETTY_CASH'')',
    'check constraint');
INSERT INTO payment_accounts (id, name, kind) VALUES (1, 'TEST workshop cash box', 'CASH');
INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method, project_id, payment_account_id)
    VALUES (1, '2026-03-02', 1, 'TEST truck', 'TEST delivery to site', 250, 37.5, 'CASH', 1, 1);
SELECT pg_temp.expect_eq('expense: draft is not a project cost yet',
    (SELECT direct_expense_cost FROM v_project_actual_cost WHERE project_id = 1), 0::numeric);
UPDATE expenses SET status = 'APPROVED', approved_by = 2, approved_at = now() WHERE id = 1;
SELECT pg_temp.expect_eq('expense: approved project expense is a direct cost (before VAT)',
    (SELECT direct_expense_cost FROM v_project_actual_cost WHERE project_id = 1), 250.00::numeric);
SELECT pg_temp.expect_error('expense: approved expense is final',
    'UPDATE expenses SET amount = 1 WHERE id = 1', 'RROKA_EXPENSE_LOCKED');

-- ---------------------------------------------------------------------
-- Treasury: payment accounts, custody, transfers
-- ---------------------------------------------------------------------
INSERT INTO payment_accounts (id, name, kind, bank_name, iban) VALUES (2, 'TEST bank', 'BANK', 'TEST bank name', 'SA0380000000608010167519');
SELECT setval('payment_accounts_id_seq', 10);
SELECT pg_temp.expect_error('account: custody names its custodian',
    'INSERT INTO payment_accounts (name, kind) VALUES (''x'', ''CUSTODY'')', 'check constraint');
SELECT pg_temp.expect_error('account: only a bank has bank details',
    'INSERT INTO payment_accounts (name, kind, iban) VALUES (''x'', ''CASH'', ''SA0380000000608010167519'')', 'check constraint');
SELECT pg_temp.expect_error('account: only custody has a limit',
    'INSERT INTO payment_accounts (name, kind, custody_limit) VALUES (''x'', ''CASH'', 100)', 'check constraint');
INSERT INTO payment_accounts (id, name, kind, employee_id, custody_limit) VALUES (3, 'TEST custody staff', 'CUSTODY', 11, 1000);
SELECT pg_temp.expect_error('account: one open custody per employee',
    'INSERT INTO payment_accounts (name, kind, employee_id) VALUES (''x'', ''CUSTODY'', 11)', 'ux_payment_accounts_one_custody');
SELECT pg_temp.expect_error('account: kind cannot change',
    'UPDATE payment_accounts SET kind = ''BANK'' WHERE id = 1', 'RROKA_PAYMENT_ACCOUNT_LOCKED');
SELECT pg_temp.expect_error('account: custodian cannot change',
    'UPDATE payment_accounts SET employee_id = 10 WHERE id = 3', 'RROKA_PAYMENT_ACCOUNT_LOCKED');
SELECT pg_temp.expect_ok('account: name and Daftra treasury can change',
    'UPDATE payment_accounts SET name = ''TEST custody of staff 2'', daftra_treasury_ref = ''7'' WHERE id = 3');

-- Expenses must say where they were paid from, consistently with the method.
INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method)
    VALUES (2, '2026-03-03', 1, 'TEST shop', 'TEST screws', 100, 15, 'CASH');
SELECT pg_temp.expect_error('expense: approval needs the account it was paid from',
    'UPDATE expenses SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 2', 'RROKA_EXPENSE_NEEDS_PAYMENT_ACCOUNT');
SELECT pg_temp.expect_ok('expense: a draft can be cancelled without an account',
    'UPDATE expenses SET status = ''CANCELLED'' WHERE id = 2');
SELECT pg_temp.expect_error('expense: cash method cannot come from a bank account',
    'INSERT INTO expenses (expense_date, category_id, payee, description, amount, vat_amount, payment_method, payment_account_id) VALUES (''2026-03-03'', 1, ''x'', ''x'', 10, 0, ''CASH'', 2)',
    'RROKA_EXPENSE_PAYMENT_MISMATCH');
SELECT pg_temp.expect_error('expense: custody account means petty cash',
    'INSERT INTO expenses (expense_date, category_id, payee, description, amount, vat_amount, payment_method, payment_account_id) VALUES (''2026-03-03'', 1, ''x'', ''x'', 10, 0, ''BANK'', 3)',
    'RROKA_EXPENSE_PAYMENT_MISMATCH');
SELECT pg_temp.expect_ok('expense: card payment from a bank account',
    'INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method, payment_account_id) VALUES (3, ''2026-03-03'', 1, ''x'', ''TEST card'', 10, 0, ''CARD'', 2)');
INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method, paid_by_employee_id, payment_account_id)
    VALUES (4, '2026-03-04', 1, 'TEST hardware shop', 'TEST hinges', 300, 45, 'PETTY_CASH', 10, 3);
SELECT pg_temp.expect_eq('expense: custody spending is recorded against the custodian',
    (SELECT paid_by_employee_id FROM expenses WHERE id = 4), 11::bigint);

-- Transfers: issue custody from the cash box, spend, return.
INSERT INTO treasury_transfers (id, transfer_date, from_account_id, to_account_id, amount) VALUES (1, '2026-03-01', 1, 3, 800);
SELECT setval('treasury_transfers_id_seq', 10);
SELECT pg_temp.expect_error('transfer: starts as draft',
    'INSERT INTO treasury_transfers (transfer_date, from_account_id, to_account_id, amount, status, approved_by, approved_at) VALUES (''2026-03-01'', 1, 3, 1, ''APPROVED'', 2, now())',
    'RROKA_TRANSFER_TRANSITION');
SELECT pg_temp.expect_error('transfer: not to the same account',
    'INSERT INTO treasury_transfers (transfer_date, from_account_id, to_account_id, amount) VALUES (''2026-03-01'', 1, 1, 5)', 'check constraint');
SELECT pg_temp.expect_eq('custody: a draft issue is not held yet', fn_custody_balance(3), 0::numeric);
SELECT pg_temp.expect_ok('transfer: approve custody issue',
    'UPDATE treasury_transfers SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 1');
SELECT pg_temp.expect_error('transfer: approved transfer is final',
    'UPDATE treasury_transfers SET amount = 1 WHERE id = 1', 'RROKA_TRANSFER_LOCKED');
SELECT pg_temp.expect_ok('transfer: Daftra id can be linked after approval',
    'UPDATE treasury_transfers SET daftra_transfer_id = 77 WHERE id = 1');
INSERT INTO treasury_transfers (id, transfer_date, from_account_id, to_account_id, amount) VALUES (2, '2026-03-02', 2, 3, 300);
SELECT pg_temp.expect_error('custody: cannot hold more than its limit (800 + 300 > 1000)',
    'UPDATE treasury_transfers SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 2', 'RROKA_CUSTODY_LIMIT');
UPDATE expenses SET status = 'APPROVED', approved_by = 2, approved_at = now() WHERE id = 4;
SELECT pg_temp.expect_eq('custody: balance = 800 issued − 345 spent (with VAT)', fn_custody_balance(3), 455.00::numeric);
INSERT INTO treasury_transfers (id, transfer_date, from_account_id, to_account_id, amount) VALUES (3, '2026-03-05', 3, 1, 500);
SELECT pg_temp.expect_error('custody: cannot return more than it holds',
    'UPDATE treasury_transfers SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 3', 'RROKA_CUSTODY_INSUFFICIENT');
SELECT pg_temp.expect_error('custody: not closed while money is outstanding',
    'UPDATE payment_accounts SET is_active = false WHERE id = 3', 'RROKA_CUSTODY_NOT_SETTLED');
INSERT INTO treasury_transfers (id, transfer_date, from_account_id, to_account_id, amount) VALUES (4, '2026-03-05', 3, 1, 455);
SELECT pg_temp.expect_ok('custody: return the remainder',
    'UPDATE treasury_transfers SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 4');
SELECT pg_temp.expect_eq('custody: summary view agrees with the balance',
    (SELECT custody_balance FROM v_payment_account_summary WHERE account_id = 3), 0::numeric);
SELECT pg_temp.expect_eq('cash box: no balance is computed here (collections are in Daftra)',
    (SELECT custody_balance FROM v_payment_account_summary WHERE account_id = 1), NULL::numeric);
SELECT pg_temp.expect_ok('custody: closed once settled',
    'UPDATE payment_accounts SET is_active = false WHERE id = 3');
SELECT pg_temp.expect_error('transfer: not to a closed account',
    'UPDATE treasury_transfers SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 2', 'RROKA_PAYMENT_ACCOUNT_INACTIVE');
INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method, payment_account_id)
    VALUES (5, '2026-03-06', 1, 'x', 'TEST late receipt', 20, 0, 'PETTY_CASH', 3);
SELECT pg_temp.expect_error('expense: not approved against a closed account',
    'UPDATE expenses SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 5', 'RROKA_PAYMENT_ACCOUNT_INACTIVE');

-- ---------------------------------------------------------------------
-- Costing engine phase 1: cost centres, cost cards, rates, overhead pools
-- ---------------------------------------------------------------------
INSERT INTO cost_centers (id, code, name, driver) VALUES (1, 'CARP', 'TEST carpentry', 'LABOR_HOURS'), (2, 'CNC', 'TEST CNC', 'MACHINE_HOURS');
INSERT INTO workers (id, name, hire_date) VALUES (20, 'TEST carpenter', '2025-01-01'), (21, 'TEST supervisor', '2025-01-01');

-- Employee card: 4,000 a month over 160 practical hours = 25 an hour (spec example).
SELECT pg_temp.expect_error('cost card: practical hours must remain after the itemised deductions',
    'INSERT INTO employee_cost_cards (employee_id, effective_from, basic_salary, housing, transportation, insurance, government_fees, allowances, other_costs, theoretical_hours, break_hours, cleaning_hours, maintenance_hours, setup_hours, meeting_hours, downtime_hours, waiting_hours, other_nonproductive_hours, source) VALUES (20, ''2026-01-01'', 1, 0, 0, 0, 0, 0, 0, 10, 10, 0, 0, 0, 0, 0, 0, 0, ''x'')',
    'check constraint');
SELECT pg_temp.expect_error('cost card: starts as draft',
    'INSERT INTO employee_cost_cards (employee_id, effective_from, basic_salary, housing, transportation, insurance, government_fees, allowances, other_costs, theoretical_hours, break_hours, cleaning_hours, maintenance_hours, setup_hours, meeting_hours, downtime_hours, waiting_hours, other_nonproductive_hours, source, status, approved_by, approved_at) VALUES (20, ''2026-01-01'', 1, 0, 0, 0, 0, 0, 0, 10, 0, 0, 0, 0, 0, 0, 0, 0, ''x'', ''APPROVED'', 2, now())',
    'RROKA_COST_RECORD_TRANSITION');
INSERT INTO employee_cost_cards (id, employee_id, effective_from, basic_salary, housing, transportation, insurance, government_fees, allowances, other_costs,
    theoretical_hours, break_hours, cleaning_hours, maintenance_hours, setup_hours, meeting_hours, downtime_hours, waiting_hours, other_nonproductive_hours, source)
    VALUES (1, 20, '2026-01-01', 2800, 700, 250, 100, 100, 50, 0, 208, 22, 6, 4, 6, 2, 4, 4, 0, 'TEST contract + GOSI statement');
SELECT setval('employee_cost_cards_id_seq', 10);
SELECT pg_temp.expect_eq('cost card: monthly cost = sum of components (4,000)', (SELECT monthly_cost FROM employee_cost_cards WHERE id = 1), 4000.00::numeric);
SELECT pg_temp.expect_eq('cost card: practical hours = 208 − 48 itemised = 160', (SELECT practical_hours FROM employee_cost_cards WHERE id = 1), 160.00::numeric);
SELECT pg_temp.expect_eq('cost card: productive hour rate = 4,000 ÷ 160 = 25', (SELECT hourly_rate FROM employee_cost_cards WHERE id = 1), 25.0000::numeric);
SELECT pg_temp.expect_eq('cost card: version 1 of the series', (SELECT version FROM employee_cost_cards WHERE id = 1), 1);
SELECT pg_temp.expect_error('cost card: approval needs cost-centre shares totalling 100%',
    'UPDATE employee_cost_cards SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 1', 'RROKA_COST_SHARES_INCOMPLETE');
INSERT INTO employee_cost_card_shares (card_id, cost_center_id, share_pct) VALUES (1, 1, 60);
SELECT pg_temp.expect_error('cost card: 60% is not all of the hours',
    'UPDATE employee_cost_cards SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 1', 'RROKA_COST_SHARES_INCOMPLETE');
INSERT INTO employee_cost_card_shares (card_id, cost_center_id, share_pct) VALUES (1, 2, 40);
SELECT pg_temp.expect_ok('cost card: approve with shares 60 + 40',
    'UPDATE employee_cost_cards SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 1');
SELECT pg_temp.expect_eq('cost card: approval writes the worker hour rate, traceable to the card',
    (SELECT hourly_cost FROM worker_rates WHERE cost_card_id = 1 AND worker_id = 20 AND effective_from = '2026-01-01'), 25.0000::numeric);
SELECT pg_temp.expect_error('cost card: approved card is final',
    'UPDATE employee_cost_cards SET basic_salary = 1 WHERE id = 1', 'RROKA_COST_RECORD_LOCKED');
SELECT pg_temp.expect_error('cost card: shares of an approved card are final',
    'UPDATE employee_cost_card_shares SET share_pct = 50 WHERE card_id = 1', 'RROKA_COST_RECORD_LOCKED');
INSERT INTO employee_cost_cards (id, employee_id, effective_from, basic_salary, housing, transportation, insurance, government_fees, allowances, other_costs,
    theoretical_hours, break_hours, cleaning_hours, maintenance_hours, setup_hours, meeting_hours, downtime_hours, waiting_hours, other_nonproductive_hours, source)
    VALUES (2, 20, '2025-12-01', 3000, 0, 0, 0, 0, 0, 0, 160, 0, 0, 0, 0, 0, 0, 0, 0, 'TEST');
INSERT INTO employee_cost_card_shares (card_id, cost_center_id, share_pct) VALUES (2, 1, 100);
SELECT pg_temp.expect_eq('cost card: next card is version 2', (SELECT version FROM employee_cost_cards WHERE id = 2), 2);
SELECT pg_temp.expect_error('cost card: a new version cannot start before the approved one (no silent re-pricing)',
    'UPDATE employee_cost_cards SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 2', 'RROKA_RATE_BACKDATED');

-- Electricity and machine card: 120,000 − 20,000 over 10 years and 1,600 h = 6.25;
-- 10 kW × 0.5 × 0.18 = 0.90; maintenance 3,200 ÷ 1,600 = 2; spare parts 1; other 0 → 10.15 an hour.
INSERT INTO machines (id, code, name, cost_center_id) VALUES (50, 'TEST-CNC2', 'TEST router', 2);
INSERT INTO machine_cost_cards (id, machine_id, effective_from, acquisition_cost, residual_value, useful_life_years, theoretical_annual_hours, practical_annual_hours,
    power_kw, load_factor, annual_maintenance, annual_spare_parts, annual_other, source)
    VALUES (1, 50, '2026-01-01', 120000, 20000, 10, 2000, 1600, 10, 0.5, 3200, 1600, 0, 'TEST invoice + manual');
SELECT setval('machine_cost_cards_id_seq', 10);
SELECT pg_temp.expect_eq('machine card: no electricity price yet → hour rate unknown, not zero', (SELECT hourly_rate FROM machine_cost_cards WHERE id = 1), NULL::numeric);
SELECT pg_temp.expect_error('machine card: cannot be approved without an electricity price',
    'UPDATE machine_cost_cards SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 1', 'RROKA_ENERGY_RATE_MISSING');
INSERT INTO energy_rates (id, effective_from, rate_per_kwh, source) VALUES (1, '2025-12-01', 0.18, 'TEST electricity bill');
SELECT pg_temp.expect_eq('energy rate: a draft rate is not used', (fn_energy_rate_on('2026-01-01')).rate_per_kwh, NULL::numeric);
UPDATE energy_rates SET status = 'APPROVED', approved_by = 2, approved_at = now() WHERE id = 1;
UPDATE machine_cost_cards SET notes = 'TEST refresh' WHERE id = 1;
SELECT pg_temp.expect_eq('machine card: depreciation per hour = 6.25', (SELECT depreciation_per_hour FROM machine_cost_cards WHERE id = 1), 6.2500::numeric);
SELECT pg_temp.expect_eq('machine card: electricity per hour = kW × load × price = 0.90', (SELECT electricity_per_hour FROM machine_cost_cards WHERE id = 1), 0.9000::numeric);
SELECT pg_temp.expect_eq('machine card: hour rate = 6.25 + 0.90 + 2 + 1 + 0 = 10.15', (SELECT hourly_rate FROM machine_cost_cards WHERE id = 1), 10.1500::numeric);
SELECT pg_temp.expect_error('machine card: residual value cannot exceed cost',
    'INSERT INTO machine_cost_cards (machine_id, effective_from, acquisition_cost, residual_value, useful_life_years, theoretical_annual_hours, practical_annual_hours, power_kw, load_factor, annual_maintenance, annual_spare_parts, annual_other, source) VALUES (50, ''2026-05-01'', 10, 20, 1, 10, 10, 0, 0, 0, 0, 0, ''x'')',
    'check constraint');
SELECT pg_temp.expect_ok('machine card: approve',
    'UPDATE machine_cost_cards SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 1');
SELECT pg_temp.expect_eq('machine card: approval writes the machine hour rate',
    (SELECT hourly_cost FROM machine_rates WHERE cost_card_id = 1), 10.1500::numeric);
SELECT pg_temp.expect_error('energy rate: an approved rate is final',
    'UPDATE energy_rates SET rate_per_kwh = 0.2 WHERE id = 1', 'RROKA_COST_RECORD_LOCKED');

-- Standard prices and waste: none until approved; the material's own waste wins over its category.
INSERT INTO raw_materials (id, code, name, category, uom) VALUES (60, 'TEST-FAB', 'TEST fabric', 'Fabric', 'm'), (61, 'TEST-FAB2', 'TEST velvet', 'Fabric', 'm');
INSERT INTO material_standard_prices (id, material_id, effective_from, unit_price, price_basis, source) VALUES (1, 60, '2026-01-01', 45, 'LAST_PURCHASE', 'TEST invoice');
SELECT pg_temp.expect_eq('standard price: a draft is not used', fn_standard_price(60, '2026-02-01'), NULL::numeric);
UPDATE material_standard_prices SET status = 'APPROVED', approved_by = 2, approved_at = now() WHERE id = 1;
SELECT pg_temp.expect_eq('standard price: approved price in force', fn_standard_price(60, '2026-02-01'), 45.0000::numeric);
SELECT pg_temp.expect_eq('standard price: nothing before it starts', fn_standard_price(60, '2025-12-31'), NULL::numeric);
INSERT INTO waste_defaults (id, category, effective_from, waste_pct, source) VALUES (1, 'fabric', '2026-01-01', 12, 'TEST cutting records');
INSERT INTO waste_defaults (id, material_id, effective_from, waste_pct, source) VALUES (2, 61, '2026-01-01', 18, 'TEST velvet pattern matching');
UPDATE waste_defaults SET status = 'APPROVED', approved_by = 2, approved_at = now() WHERE id IN (1, 2);
SELECT pg_temp.expect_eq('waste: material without its own rate takes its category rate', fn_standard_waste_pct(60, '2026-02-01'), 12.00::numeric);
SELECT pg_temp.expect_eq('waste: the material''s own rate wins', fn_standard_waste_pct(61, '2026-02-01'), 18.00::numeric);
SELECT pg_temp.expect_error('waste: a material or a category, not both',
    'INSERT INTO waste_defaults (material_id, category, effective_from, waste_pct, source) VALUES (60, ''Fabric'', ''2026-03-01'', 5, ''x'')', 'check constraint');

-- Overhead pools: driver-based rate at practical capacity, with double-count rules.
INSERT INTO overhead_pools (id, kind, effective_from, period_to, driver, practical_capacity, source)
    VALUES (1, 'MANUFACTURING', '2026-01-01', '2026-12-31', 'LABOR_HOURS', 8000, 'TEST 2026 budget');
SELECT setval('overhead_pools_id_seq', 10);
INSERT INTO overhead_pool_lines (pool_id, category, description, amount) VALUES (1, 'RENT', 'TEST factory rent', 60000), (1, 'GENERAL_ELECTRICITY', 'TEST electricity bill', 20000);
SELECT pg_temp.expect_error('pool: selling costs are not manufacturing overhead',
    'INSERT INTO overhead_pool_lines (pool_id, category, description, amount) VALUES (1, ''SELLING'', ''x'', 1)', 'RROKA_POOL_CATEGORY');
SELECT pg_temp.expect_error('pool: a direct worker is not indirect labour too',
    'INSERT INTO overhead_pool_lines (pool_id, category, description, amount, employee_id) VALUES (1, ''INDIRECT_LABOR'', ''x'', 1, 20)', 'RROKA_DOUBLE_COUNT_LABOR');
SELECT pg_temp.expect_ok('pool: a supervisor without a direct-labour card is overhead',
    'INSERT INTO overhead_pool_lines (pool_id, category, description, amount, employee_id) VALUES (1, ''SUPERVISION'', ''TEST supervisor'', 0, 21)');
SELECT pg_temp.expect_error('pool: a machine with an hour rate is not depreciated again',
    'INSERT INTO overhead_pool_lines (pool_id, category, description, amount, machine_id) VALUES (1, ''DEPRECIATION'', ''x'', 1, 50)', 'RROKA_DOUBLE_COUNT_MACHINE');
INSERT INTO overhead_pools (id, kind, cost_center_id, effective_from, period_to, driver, practical_capacity, source)
    VALUES (2, 'MANUFACTURING', 2, '2026-01-01', '2026-12-31', 'LABOR_HOURS', 1600, 'TEST');
SELECT pg_temp.expect_eq('pool: a cost centre pool uses the centre''s driver', (SELECT driver FROM overhead_pools WHERE id = 2), 'MACHINE_HOURS'::text);
SELECT pg_temp.expect_error('pool: factory electricity only in the whole-factory pool',
    'INSERT INTO overhead_pool_lines (pool_id, category, description, amount) VALUES (2, ''GENERAL_ELECTRICITY'', ''x'', 1)', 'RROKA_POOL_CATEGORY');
SELECT pg_temp.expect_error('pool: cannot approve an empty pool',
    'UPDATE overhead_pools SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 2', 'RROKA_POOL_EMPTY');
SELECT pg_temp.expect_ok('pool: approve the factory pool',
    'UPDATE overhead_pools SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 1');
SELECT pg_temp.expect_eq('pool: machine electricity (10 × 0.5 × 0.18 × 1,600 = 1,440) is taken out of the bill',
    (SELECT machine_energy_deduction FROM overhead_pools WHERE id = 1), 1440.00::numeric);
SELECT pg_temp.expect_eq('pool: rate = (80,000 − 1,440) ÷ 8,000 labour hours = 9.82',
    (SELECT rate FROM overhead_pools WHERE id = 1), 9.8200::numeric);
SELECT pg_temp.expect_error('pool: approved lines are final',
    'UPDATE overhead_pool_lines SET amount = 1 WHERE pool_id = 1', 'RROKA_COST_RECORD_LOCKED');
INSERT INTO overhead_pools (id, kind, effective_from, period_to, driver, practical_capacity, source)
    VALUES (3, 'MANUFACTURING', '2026-07-01', '2027-06-30', 'LABOR_HOURS', 8000, 'TEST');
INSERT INTO overhead_pool_lines (pool_id, category, description, amount) VALUES (3, 'RENT', 'TEST', 1);
SELECT pg_temp.expect_error('pool: no two approved pools for the same period',
    'UPDATE overhead_pools SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 3', 'RROKA_POOL_OVERLAP');
INSERT INTO overhead_pools (id, kind, effective_from, period_to, driver, practical_capacity, source)
    VALUES (4, 'MANUFACTURING', '2027-01-01', '2027-12-31', 'LABOR_HOURS', 8000, 'TEST');
INSERT INTO overhead_pool_lines (pool_id, category, description, amount) VALUES (4, 'GENERAL_ELECTRICITY', 'TEST', 100);
SELECT pg_temp.expect_error('pool: a bill below the machines'' own electricity means double counting',
    'UPDATE overhead_pools SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 4', 'RROKA_DOUBLE_COUNT_ELECTRICITY');
INSERT INTO overhead_pools (id, kind, effective_from, period_to, driver, budgeted_manufacturing_cost, source)
    VALUES (5, 'SELLING_ADMIN', '2026-01-01', '2026-12-31', 'PCT_OF_MANUFACTURING_COST', 400000, 'TEST');
SELECT pg_temp.expect_error('pool: rent belongs to manufacturing, not selling & admin',
    'INSERT INTO overhead_pool_lines (pool_id, category, description, amount) VALUES (5, ''RENT'', ''x'', 1)', 'RROKA_POOL_CATEGORY');
INSERT INTO overhead_pool_lines (pool_id, category, description, amount) VALUES (5, 'SELLING', 'TEST marketing', 30000), (5, 'ADMINISTRATIVE', 'TEST office', 50000);
UPDATE overhead_pools SET status = 'APPROVED', approved_by = 2, approved_at = now() WHERE id = 5;
SELECT pg_temp.expect_eq('selling & admin: 80,000 ÷ 400,000 manufacturing cost = 20%', (SELECT rate FROM overhead_pools WHERE id = 5), 20.0000::numeric);
SELECT pg_temp.expect_eq('validity: a version runs until the next one starts',
    (SELECT effective_to FROM v_cost_rate_periods WHERE table_name = 'energy_rates' AND id = 1), NULL::date);

-- ---------------------------------------------------------------------
-- Costing engine phase 2: standard cost, pricing, VAT, approval gate, snapshot
-- (uses the phase 1 fixtures: CARP/CNC centres, worker 20 at 25/h, machine 50 at
-- 10.15/h, factory pool 9.82/labour h, selling & admin 20%, fabric 45 + 12% waste)
-- ---------------------------------------------------------------------
INSERT INTO overhead_pools (id, kind, cost_center_id, effective_from, period_to, driver, practical_capacity, source)
    VALUES (6, 'MANUFACTURING', 1, '2026-01-01', '2026-12-31', 'LABOR_HOURS', 2000, 'TEST');
INSERT INTO overhead_pool_lines (pool_id, category, description, amount) VALUES (6, 'MAINTENANCE', 'TEST carpentry tools', 10000);
INSERT INTO overhead_pool_lines (pool_id, category, description, amount) VALUES (2, 'MAINTENANCE', 'TEST CNC upkeep', 3200);
UPDATE overhead_pools SET status = 'APPROVED', approved_by = 2, approved_at = now() WHERE id = 2;   -- CNC: 3,200 ÷ 1,600 = 2 per machine hour

INSERT INTO vat_rates (id, name, rate_pct) VALUES (1, 'TEST VAT 15', 15), (2, 'TEST old', 5);
UPDATE vat_rates SET is_active = false WHERE id = 2;
SELECT pg_temp.expect_error('vat: a rate cannot be changed', 'UPDATE vat_rates SET rate_pct = 10 WHERE id = 1', 'RROKA_VAT_RATE_LOCKED');

INSERT INTO quotations (id, client_id, issue_date) VALUES (100, 1, '2026-06-01');
SELECT pg_temp.expect_eq('costing: new quotations require costing', (SELECT requires_costing FROM quotations WHERE id = 100), true);
INSERT INTO quotation_lines (quotation_id, line_no, description, quantity, unit_price) VALUES (100, 1, 'TEST sofa', 2, 3000), (100, 2, 'TEST side table', 1, 1000);
UPDATE quotations SET discount_amount = 700 WHERE id = 100;
SELECT pg_temp.expect_error('vat: an inactive rate cannot be chosen', 'UPDATE quotations SET vat_rate_id = 2 WHERE id = 100', 'RROKA_VAT_RATE_INACTIVE');
SELECT pg_temp.expect_eq('vat: no rate chosen = no VAT', (SELECT vat_amount FROM v_quotation_totals WHERE quotation_id = 100), 0.00::numeric);
UPDATE quotations SET vat_rate_id = 1 WHERE id = 100;
SELECT pg_temp.expect_eq('vat: 15% of the net after discount (6,300) = 945', (SELECT vat_amount FROM v_quotation_totals WHERE quotation_id = 100), 945.00::numeric);
SELECT pg_temp.expect_eq('vat: total including VAT = 7,245', (SELECT total_incl_vat FROM v_quotation_totals WHERE quotation_id = 100), 7245.00::numeric);

SELECT pg_temp.expect_error('estimate: only for an existing line',
    'INSERT INTO cost_estimates (quotation_id, line_no, pricing_method, target_pct) VALUES (100, 9, ''MARGIN'', 30)', 'RROKA_ESTIMATE_NO_LINE');
SELECT pg_temp.expect_error('estimate: a margin target must be below 100%',
    'INSERT INTO cost_estimates (quotation_id, line_no, pricing_method, target_pct) VALUES (100, 1, ''MARGIN'', 100)', 'check constraint');
INSERT INTO cost_estimates (id, quotation_id, line_no, pricing_method, target_pct) VALUES (1, 100, 1, 'MARGIN', 30);
INSERT INTO cost_estimates (id, quotation_id, line_no, pricing_method, target_pct, min_margin_pct) VALUES (2, 100, 2, 'MARKUP', 25, 40);
SELECT setval('cost_estimates_id_seq', 10);
SELECT pg_temp.expect_eq('estimate: an empty estimate is incomplete', (SELECT 'EMPTY_ESTIMATE' = ANY(missing) FROM v_estimate_costs WHERE estimate_id = 1), true);

-- Line 1 (2 units): fabric 10 m/unit, standard 12% waste, standard price 45 → 10 × 1.12 × 2 × 45 = 1,008.00;
-- velvet 2 m/unit, its own 18% waste, quoted price 80 → 2 × 1.18 × 2 × 80 = 377.60.
SELECT pg_temp.expect_error('estimate: an overriding price names its source',
    'INSERT INTO cost_estimate_materials (estimate_id, material_id, quantity, unit_price) VALUES (1, 61, 2, 80)', 'check constraint');
INSERT INTO cost_estimate_materials (estimate_id, material_id, quantity) VALUES (1, 60, 10);
INSERT INTO cost_estimate_materials (estimate_id, material_id, quantity, unit_price, price_source) VALUES (1, 61, 2, 80, 'TEST supplier quote');
SELECT pg_temp.expect_eq('materials: adjusted quantity = 10 × (1 + 12%) = 11.2', (SELECT adjusted_qty_per_unit FROM v_estimate_material_lines WHERE estimate_id = 1 AND material_id = 60), 11.2000::numeric);
SELECT pg_temp.expect_eq('materials: fabric = 11.2 × 2 units × 45 = 1,008', (SELECT total_cost FROM v_estimate_material_lines WHERE estimate_id = 1 AND material_id = 60), 1008.00::numeric);
SELECT pg_temp.expect_eq('materials: velvet uses its own 18% waste', (SELECT waste_source || ' ' || waste_pct FROM v_estimate_material_lines WHERE estimate_id = 1 AND material_id = 61), 'STANDARD 18.00'::text);
-- Operations: CNC cut 0.5 h/unit + 1 h setup (labour), 1 machine h/unit + 0.5 setup;
-- carpentry by worker 20, 4 h/unit.
SELECT pg_temp.expect_error('operation: machine hours need a machine',
    'INSERT INTO cost_estimate_operations (estimate_id, seq, operation, cost_center_id, labor_hours, setup_hours, machine_hours) VALUES (1, 9, ''x'', 2, 0, 0, 1)', 'check constraint');
INSERT INTO cost_estimate_operations (estimate_id, seq, operation, cost_center_id, labor_hours, setup_hours, machine_id, machine_hours, machine_setup_hours)
    VALUES (1, 1, 'TEST cutting', 2, 0.5, 1, 50, 1, 0.5);
INSERT INTO cost_estimate_operations (estimate_id, seq, operation, cost_center_id, employee_id, labor_hours, setup_hours)
    VALUES (1, 2, 'TEST carpentry', 1, 20, 4, 0);
SELECT pg_temp.expect_eq('batch: setup counted once — labour hours 0.5 × 2 + 1 = 2', (SELECT total_labor_hours FROM v_estimate_operation_lines WHERE estimate_id = 1 AND seq = 1), 2.000::numeric);
SELECT pg_temp.expect_eq('labour: centre rate blended from approved cards = 25/h', (SELECT labor_rate FROM v_estimate_operation_lines WHERE estimate_id = 1 AND seq = 1), 25.0000::numeric);
SELECT pg_temp.expect_eq('machine: (1 × 2 + 0.5) h × 10.15 = 25.38', (SELECT machine_cost FROM v_estimate_operation_lines WHERE estimate_id = 1 AND seq = 1), 25.38::numeric);
SELECT pg_temp.expect_eq('overhead: CNC driver is machine hours: 2.5 × 2 = 5', (SELECT overhead_cost FROM v_estimate_operation_lines WHERE estimate_id = 1 AND seq = 1), 5.00::numeric);
SELECT pg_temp.expect_eq('overhead: a centre without an approved pool is missing, not zero',
    (SELECT 'OVERHEAD_POOL_MISSING' = ANY(missing) FROM v_estimate_costs WHERE estimate_id = 1), true);
INSERT INTO cost_estimate_direct_costs (estimate_id, cost_type, description, amount, basis) VALUES
    (1, 'INSTALLATION', 'TEST install crew', 150, 'ONE_TIME'), (1, 'COMMISSION', 'TEST commission', 20, 'PER_UNIT');
INSERT INTO cost_estimate_direct_costs (estimate_id, cost_type, description, amount, basis) VALUES (2, 'EXTERNAL_MANUFACTURING', 'TEST bought-in table', 500, 'ONE_TIME');

UPDATE quotations SET status = 'SENT' WHERE id = 100;
SELECT pg_temp.expect_error('approval: refused while a line''s cost estimate is incomplete',
    'UPDATE quotations SET status = ''APPROVED'', approved_at = now(), approved_by = 2 WHERE id = 100', 'RROKA_QUOTATION_COSTING_INCOMPLETE');
UPDATE overhead_pools SET status = 'APPROVED', approved_by = 2, approved_at = now() WHERE id = 6;   -- carpentry: 10,000 ÷ 2,000 = 5 per labour hour

SELECT pg_temp.expect_eq('estimate: complete once every rate exists', (SELECT cardinality(missing) FROM v_estimate_costs WHERE estimate_id = 1), 0);
SELECT pg_temp.expect_eq('cost: materials 1,008 + 377.60 = 1,385.60', (SELECT materials_cost FROM v_estimate_costs WHERE estimate_id = 1), 1385.60::numeric);
SELECT pg_temp.expect_eq('cost: labour (2 + 8 h) × 25 = 250', (SELECT labor_cost FROM v_estimate_costs WHERE estimate_id = 1), 250.0000::numeric);
SELECT pg_temp.expect_eq('cost: other direct = 150 once + 20 × 2 = 190', (SELECT direct_other_cost FROM v_estimate_costs WHERE estimate_id = 1), 190.00::numeric);
SELECT pg_temp.expect_eq('cost: direct cost = 1,385.60 + 250 + 25.38 + 190 = 1,850.98', (SELECT direct_cost FROM v_estimate_costs WHERE estimate_id = 1), 1850.98::numeric);
SELECT pg_temp.expect_eq('overhead: centres 5 + 40, factory 10 labour h × 9.82 = 98.20 → 143.20', (SELECT overhead_cost FROM v_estimate_costs WHERE estimate_id = 1), 143.20::numeric);
SELECT pg_temp.expect_eq('cost: manufacturing cost = 1,994.18', (SELECT manufacturing_cost FROM v_estimate_costs WHERE estimate_id = 1), 1994.18::numeric);
SELECT pg_temp.expect_eq('cost: fully loaded = 1,994.18 × 1.20 = 2,393.02', (SELECT fully_loaded_cost FROM v_estimate_costs WHERE estimate_id = 1), 2393.02::numeric);
SELECT pg_temp.expect_eq('pricing: margin method = cost ÷ (1 − 30%) = 3,418.60 (not cost × 1.30)', (SELECT recommended_price FROM v_estimate_costs WHERE estimate_id = 1), 3418.60::numeric);
SELECT pg_temp.expect_eq('discount: lowers the price, not the cost — line share 700 × 6,000 ÷ 7,000 = 600', (SELECT net_price FROM v_estimate_costs WHERE estimate_id = 1), 5400.00::numeric);
SELECT pg_temp.expect_eq('profit: gross margin = (5,400 − 1,994.18) ÷ 5,400 = 63.07%', (SELECT gross_margin_pct FROM v_estimate_costs WHERE estimate_id = 1), 63.07::numeric);
SELECT pg_temp.expect_eq('profit: markup = profit ÷ cost = 170.79%', (SELECT markup_pct FROM v_estimate_costs WHERE estimate_id = 1), 170.79::numeric);
SELECT pg_temp.expect_eq('pricing: markup method = cost × (1 + 25%): 500 × 1.20 × 1.25 = 750', (SELECT recommended_price FROM v_estimate_costs WHERE estimate_id = 2), 750.00::numeric);
SELECT pg_temp.expect_eq('pricing: 900 after discount leaves 33% after overheads — below the 40% minimum, warned not changed',
    (SELECT warnings FROM v_estimate_costs WHERE estimate_id = 2), ARRAY['BELOW_MIN_MARGIN']::text[]);
SELECT pg_temp.expect_eq('vat: never part of price or profit', (SELECT net_price FROM v_estimate_costs WHERE estimate_id = 2), 900.00::numeric);

SELECT pg_temp.expect_ok('approval: complete estimates → approved',
    'UPDATE quotations SET status = ''APPROVED'', approved_at = now(), approved_by = 2 WHERE id = 100');
SELECT pg_temp.expect_eq('snapshot: one frozen cost sheet per line', (SELECT count(*) FROM cost_estimate_snapshots WHERE quotation_id = 100), 2::bigint);
SELECT pg_temp.expect_eq('snapshot: keeps the manufacturing cost', (SELECT manufacturing_cost FROM cost_estimate_snapshots WHERE estimate_id = 1), 1994.18::numeric);
SELECT pg_temp.expect_eq('snapshot: traces the standard price version used',
    (SELECT (detail -> 'materials' -> 0 ->> 'standard_price_id')::bigint FROM cost_estimate_snapshots WHERE estimate_id = 1), 1::bigint);
SELECT pg_temp.expect_error('snapshot: frozen for good', 'UPDATE cost_estimate_snapshots SET manufacturing_cost = 1 WHERE estimate_id = 1', 'RROKA_SNAPSHOT_IMMUTABLE');
SELECT pg_temp.expect_error('estimate: locked once the quotation is approved',
    'INSERT INTO cost_estimate_materials (estimate_id, material_id, quantity) VALUES (1, 60, 1)', 'RROKA_ESTIMATE_LOCKED');
SELECT pg_temp.expect_error('estimate: its figures cannot be edited after approval',
    'UPDATE cost_estimates SET target_pct = 10 WHERE id = 1', 'RROKA_ESTIMATE_LOCKED');
SELECT pg_temp.expect_eq('vat: chosen percentage frozen with the quotation', (SELECT vat_pct FROM quotations WHERE id = 100), 15.00::numeric);

-- ---------------------------------------------------------------------
-- Accounting, phase 1: chart, journal, periods
-- ---------------------------------------------------------------------
INSERT INTO accounts (id, code, name, account_type, is_postable) VALUES
    (900, '1', 'TEST assets', 'ASSET', false), (901, '5', 'TEST expenses', 'EXPENSE', false), (902, '3', 'TEST equity', 'EQUITY', false);
INSERT INTO accounts (id, code, name, account_type, parent_id, system_role, detail_type) VALUES
    (910, '1102', 'TEST bank', 'ASSET', 900, 'BANK', 'BANK_CASH'), (911, '5101', 'TEST rent', 'EXPENSE', 901, NULL, 'EXPENSES'),
    (912, '3201', 'TEST owner current', 'EQUITY', 902, 'OWNER_CURRENT', 'EQUITY');
SELECT setval('accounts_id_seq', 1000);

SELECT pg_temp.expect_error('chart: a child must have the type of its parent',
    'INSERT INTO accounts (code, name, account_type, parent_id, is_postable) VALUES (''5999'', ''TEST'', ''ASSET'', 901, false)', 'RROKA_ACCOUNT_PARENT');
SELECT pg_temp.expect_error('chart: a postable account cannot have children',
    'INSERT INTO accounts (code, name, account_type, parent_id, detail_type) VALUES (''51011'', ''TEST'', ''EXPENSE'', 911, ''EXPENSES'')', 'RROKA_ACCOUNT_PARENT');
SELECT pg_temp.expect_error('chart: a group with children cannot become postable',
    'UPDATE accounts SET is_postable = true WHERE id = 901', 'RROKA_ACCOUNT_PARENT');
INSERT INTO accounts (id, code, name, account_type, parent_id, is_postable) VALUES (903, '51', 'TEST sub-group', 'EXPENSE', 901, false);
SELECT pg_temp.expect_error('chart: no cycles', 'UPDATE accounts SET parent_id = 903 WHERE id = 901', 'RROKA_ACCOUNT_PARENT');
SELECT pg_temp.expect_error('chart: a system role needs a postable account',
    'UPDATE accounts SET system_role = ''WIP'' WHERE id = 903', 'RROKA_ACCOUNT_ROLE');
SELECT pg_temp.expect_error('chart: one account per system role',
    'INSERT INTO accounts (code, name, account_type, parent_id, system_role, detail_type) VALUES (''1103'', ''TEST'', ''ASSET'', 900, ''BANK'', ''BANK_CASH'')', 'accounts_system_role_key');
SELECT pg_temp.expect_error('chart: account codes are digits',
    'INSERT INTO accounts (code, name, account_type, detail_type) VALUES (''A1'', ''TEST'', ''ASSET'', ''BANK_CASH'')', 'accounts_code_check');
SELECT pg_temp.expect_error('chart: a postable account needs its detailed type',
    'INSERT INTO accounts (code, name, account_type, parent_id) VALUES (''1199'', ''TEST'', ''ASSET'', 900)', 'RROKA_ACCOUNT_DETAIL_TYPE');
SELECT pg_temp.expect_error('chart: a group carries no detailed type',
    'INSERT INTO accounts (code, name, account_type, is_postable, detail_type) VALUES (''7'', ''TEST'', ''ASSET'', false, ''BANK_CASH'')', 'accounts_group_no_detail_type');
INSERT INTO accounts (id, code, name, account_type, parent_id, detail_type) VALUES (913, '1110', 'TEST customers', 'EXPENSE', 900, 'RECEIVABLE');
SELECT pg_temp.expect_eq('chart: the detailed type decides the class (receivable → asset)', (SELECT account_type FROM accounts WHERE id = 913), 'ASSET');
SELECT pg_temp.expect_eq('chart: a receivable always allows reconciliation', (SELECT reconcile FROM accounts WHERE id = 913), true);
SELECT pg_temp.expect_error('chart: a liability type cannot sit under an asset group',
    'INSERT INTO accounts (code, name, account_type, parent_id, detail_type) VALUES (''1198'', ''TEST'', ''ASSET'', 900, ''PAYABLE'')', 'RROKA_ACCOUNT_PARENT');
INSERT INTO accounts (code, name, account_type, parent_id, detail_type) VALUES ('3302', 'TEST current year earnings', 'EQUITY', 902, 'CURRENT_YEAR_EARNINGS');
SELECT pg_temp.expect_error('chart: only one current-year-earnings account',
    'INSERT INTO accounts (code, name, account_type, parent_id, detail_type) VALUES (''3303'', ''TEST'', ''EQUITY'', 902, ''CURRENT_YEAR_EARNINGS'')', 'ux_accounts_current_year_earnings');

-- Entry 1: rent 1,000 paid from the bank.
INSERT INTO journal_entries (id, entry_date, description, created_by) VALUES (1, '2026-02-10', 'TEST rent February', 1);
INSERT INTO journal_lines (entry_id, account_id, debit) VALUES (1, 911, 1000);
SELECT pg_temp.expect_error('journal: a line on a group account is refused',
    'INSERT INTO journal_lines (entry_id, account_id, credit) VALUES (1, 900, 1000)', 'RROKA_ACCOUNT_NOT_POSTABLE');
SELECT pg_temp.expect_error('journal: a line is debit or credit, not both',
    'INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (1, 910, 5, 5)', 'journal_lines_check');
SELECT pg_temp.expect_error('journal: an unbalanced entry is not posted',
    'UPDATE journal_entries SET status = ''POSTED'', posted_by = 2 WHERE id = 1', 'RROKA_JOURNAL_UNBALANCED');
INSERT INTO journal_lines (entry_id, account_id, credit) VALUES (1, 910, 999);
SELECT pg_temp.expect_error('journal: off by 1 riyal is still unbalanced',
    'UPDATE journal_entries SET status = ''POSTED'', posted_by = 2 WHERE id = 1', 'RROKA_JOURNAL_UNBALANCED');
UPDATE journal_lines SET credit = 1000 WHERE entry_id = 1 AND account_id = 910;
SELECT pg_temp.expect_error('journal: an entry cannot be created posted',
    'INSERT INTO journal_entries (entry_date, description, created_by, status, posted_by) VALUES (''2026-02-10'', ''TEST'', 1, ''POSTED'', 1)', 'RROKA_JOURNAL_UNBALANCED');
SELECT pg_temp.expect_error('journal: nothing posts before the books start (2026-01-01)',
    'UPDATE journal_entries SET entry_date = ''2025-12-31'', status = ''POSTED'', posted_by = 2 WHERE id = 1', 'RROKA_BOOKS_NOT_STARTED');
SELECT pg_temp.expect_ok('journal: a balanced entry posts',
    'UPDATE journal_entries SET status = ''POSTED'', posted_by = 2 WHERE id = 1');
SELECT pg_temp.expect_eq('journal: number given at posting', (SELECT entry_no FROM journal_entries WHERE id = 1), 'JV-2026-00001');
SELECT pg_temp.expect_eq('journal: posting opens the month', (SELECT status FROM fiscal_periods WHERE period_start = '2026-02-01'), 'OPEN');
SELECT pg_temp.expect_error('journal: a posted entry never changes',
    'UPDATE journal_entries SET description = ''x'' WHERE id = 1', 'RROKA_JOURNAL_LOCKED');
SELECT pg_temp.expect_error('journal: a posted entry cannot be deleted', 'DELETE FROM journal_entries WHERE id = 1', 'RROKA_JOURNAL_LOCKED');
SELECT pg_temp.expect_error('journal: lines of a posted entry cannot change',
    'UPDATE journal_lines SET debit = 1 WHERE entry_id = 1 AND debit > 0', 'RROKA_JOURNAL_LOCKED');
SELECT pg_temp.expect_error('journal: no line can be added to a posted entry',
    'INSERT INTO journal_lines (entry_id, account_id, debit) VALUES (1, 911, 1)', 'RROKA_JOURNAL_LOCKED');
SELECT pg_temp.expect_error('chart: an account with entries keeps its type',
    'UPDATE accounts SET parent_id = NULL, detail_type = ''BANK_CASH'' WHERE id = 911', 'RROKA_ACCOUNT_USED');

-- A rolled-back posting does not consume a number (gap-free).
INSERT INTO journal_entries (id, entry_date, description, created_by) VALUES (2, '2026-02-12', 'TEST owner deposit', 1);
INSERT INTO journal_lines (entry_id, account_id, debit) VALUES (2, 910, 5000);
INSERT INTO journal_lines (entry_id, account_id, credit) VALUES (2, 912, 5000);
SELECT pg_temp.expect_error('journal: a failed posting rolls back',
    $q$DO $b$ BEGIN UPDATE journal_entries SET status = 'POSTED', posted_by = 2 WHERE id = 2; RAISE EXCEPTION 'TEST rollback'; END $b$$q$, 'TEST rollback');
SELECT pg_temp.expect_ok('journal: draft deleted with its lines', 'DELETE FROM journal_entries WHERE id = 2');
INSERT INTO journal_entries (id, entry_date, description, created_by) VALUES (3, '2026-02-12', 'TEST owner deposit', 1);
INSERT INTO journal_lines (entry_id, account_id, debit) VALUES (3, 910, 5000);
INSERT INTO journal_lines (entry_id, account_id, credit) VALUES (3, 912, 5000);
UPDATE journal_entries SET status = 'POSTED', posted_by = 1 WHERE id = 3;
SELECT pg_temp.expect_eq('journal: numbering stays gap-free', (SELECT entry_no FROM journal_entries WHERE id = 3), 'JV-2026-00002');

-- Reversal must mirror the original.
INSERT INTO journal_entries (id, entry_date, description, created_by, source_type, reverses_id) VALUES (4, '2026-02-20', 'TEST reverse rent', 1, 'REVERSAL', 1);
INSERT INTO journal_lines (entry_id, account_id, credit) VALUES (4, 911, 900);
INSERT INTO journal_lines (entry_id, account_id, debit) VALUES (4, 910, 900);
SELECT pg_temp.expect_error('reversal: must mirror the original exactly',
    'UPDATE journal_entries SET status = ''POSTED'', posted_by = 2 WHERE id = 4', 'RROKA_JOURNAL_REVERSAL');
UPDATE journal_lines SET credit = 1000 WHERE entry_id = 4 AND account_id = 911;
UPDATE journal_lines SET debit = 1000 WHERE entry_id = 4 AND account_id = 910;
SELECT pg_temp.expect_ok('reversal: the mirror posts', 'UPDATE journal_entries SET status = ''POSTED'', posted_by = 2 WHERE id = 4');
SELECT pg_temp.expect_error('reversal: an entry is reversed once',
    'INSERT INTO journal_entries (entry_date, description, created_by, source_type, reverses_id) VALUES (''2026-02-21'', ''TEST'', 1, ''REVERSAL'', 1)', 'journal_entries_reverses_id_key');
SELECT pg_temp.expect_error('reversal: a draft cannot be reversed',
    $q$INSERT INTO journal_entries (id, entry_date, description, created_by) VALUES (5, '2026-02-21', 'TEST draft', 1);
       INSERT INTO journal_entries (entry_date, description, created_by, source_type, reverses_id) VALUES ('2026-02-21', 'TEST', 1, 'REVERSAL', 5)$q$, 'RROKA_JOURNAL_REVERSAL');
SELECT pg_temp.expect_eq('ledger: rent nets to zero after the reversal',
    (SELECT sum(net) FROM v_ledger_lines WHERE account_id = 911), 0.00::numeric);
SELECT pg_temp.expect_eq('ledger: bank = owner deposit 5,000', (SELECT sum(net) FROM v_ledger_lines WHERE account_id = 910), 5000.00::numeric);
SELECT pg_temp.expect_eq('ledger: trial balance balances', (SELECT sum(debit) - sum(credit) FROM v_ledger_lines), 0.00::numeric);

-- Periods.
INSERT INTO journal_entries (id, entry_date, description, created_by) VALUES (6, '2026-03-05', 'TEST March draft', 1);
INSERT INTO fiscal_periods (period_start) VALUES ('2026-03-01');
SELECT pg_temp.expect_error('period: a draft dated in the month blocks closing',
    'UPDATE fiscal_periods SET status = ''CLOSED'', closed_by = 2, closed_at = now() WHERE period_start = ''2026-03-01''', 'RROKA_PERIOD_DRAFTS');
DELETE FROM journal_entries WHERE id = 6;
SELECT pg_temp.expect_error('period: earlier months close first',
    'UPDATE fiscal_periods SET status = ''CLOSED'', closed_by = 2, closed_at = now() WHERE period_start = ''2026-03-01''', 'RROKA_PERIOD_ORDER');
DELETE FROM journal_entries WHERE id = 5;
SELECT pg_temp.expect_ok('period: February closes',
    'UPDATE fiscal_periods SET status = ''CLOSED'', closed_by = 2, closed_at = now() WHERE period_start = ''2026-02-01''');
INSERT INTO journal_entries (id, entry_date, description, created_by) VALUES (7, '2026-02-25', 'TEST late February', 1);
INSERT INTO journal_lines (entry_id, account_id, debit) VALUES (7, 911, 10);
INSERT INTO journal_lines (entry_id, account_id, credit) VALUES (7, 910, 10);
SELECT pg_temp.expect_error('period: nothing posts into a closed month',
    'UPDATE journal_entries SET status = ''POSTED'', posted_by = 2 WHERE id = 7', 'RROKA_PERIOD_CLOSED');
SELECT pg_temp.expect_error('period: nothing posts into a month before a closed one',
    'UPDATE journal_entries SET entry_date = ''2026-01-15'', status = ''POSTED'', posted_by = 2 WHERE id = 7', 'RROKA_PERIOD_CLOSED');
SELECT pg_temp.expect_error('period: reopening needs a written reason',
    'UPDATE fiscal_periods SET status = ''OPEN'' WHERE period_start = ''2026-02-01''', 'RROKA_PERIOD_REOPEN');
SELECT pg_temp.expect_ok('period: reopened with a reason',
    'UPDATE fiscal_periods SET status = ''OPEN'', reopen_reason = ''TEST supplier invoice found'' WHERE period_start = ''2026-02-01''');
SELECT pg_temp.expect_ok('period: posts again once reopened',
    'UPDATE journal_entries SET status = ''POSTED'', posted_by = 2 WHERE id = 7');
SELECT pg_temp.expect_error('period: cannot be deleted', 'DELETE FROM fiscal_periods WHERE period_start = ''2026-02-01''', 'RROKA_PERIOD_CLOSED');

-- ---------------------------------------------------------------------
-- Accounting: automatic posting from documents (step أ)
-- ---------------------------------------------------------------------
SELECT setval('journal_entries_id_seq', 100);
SELECT pg_temp.expect_eq('backlog: approved documents without entries are listed',
    (SELECT count(*) FROM v_posting_backlog WHERE source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER')
        AND (source_type, source_id) IN (('EXPENSE', 1), ('EXPENSE', 4), ('PURCHASE', 1), ('TRANSFER', 1), ('TRANSFER', 4))), 5::bigint);
SELECT pg_temp.expect_eq('backlog: drafts and cancelled documents are not listed',
    (SELECT count(*) FROM v_posting_backlog WHERE (source_type, source_id) IN (('EXPENSE', 2), ('EXPENSE', 5), ('TRANSFER', 2))), 0::bigint);
SELECT pg_temp.expect_error('auto-posting: not switched on while mappings are missing',
    'UPDATE accounting_settings SET auto_posting = true', 'RROKA_POSTING_NOT_READY');
SELECT pg_temp.expect_error('posting: a missing role refuses the posting',
    'SELECT fn_post_document(''PURCHASE'', 1, 1)', 'RROKA_POSTING_MAPPING');

INSERT INTO accounts (id, code, name, account_type, is_postable) VALUES (904, '2', 'TEST liabilities', 'LIABILITY', false);
INSERT INTO accounts (id, code, name, account_type, parent_id, system_role, detail_type) VALUES
    (920, '1120', 'TEST inventory', 'ASSET', 900, 'INVENTORY', 'CURRENT_ASSETS'),
    (921, '1130', 'TEST WIP', 'ASSET', 900, 'WIP', 'CURRENT_ASSETS'),
    (922, '1140', 'TEST input VAT', 'ASSET', 900, 'INPUT_VAT', 'CURRENT_ASSETS'),
    (923, '2101', 'TEST payables', 'LIABILITY', 904, 'PAYABLE', 'PAYABLE'),
    (924, '1101', 'TEST cash', 'ASSET', 900, NULL, 'BANK_CASH'),
    (925, '1103', 'TEST custody', 'ASSET', 900, NULL, 'CURRENT_ASSETS'),
    (927, '5309', 'TEST transport', 'EXPENSE', 901, NULL, 'EXPENSES');
SELECT pg_temp.expect_error('mapping: a payment account maps to a postable account only',
    'UPDATE payment_accounts SET account_id = 900 WHERE id = 1', 'RROKA_ACCOUNT_NOT_POSTABLE');
SELECT pg_temp.expect_error('mapping: a payment account does not map to an expense account',
    'UPDATE payment_accounts SET account_id = 911 WHERE id = 1', 'RROKA_POSTING_MAP_TYPE');
SELECT pg_temp.expect_error('mapping: an expense category does not map to equity',
    'UPDATE expense_categories SET account_id = 912 WHERE id = 1', 'RROKA_POSTING_MAP_TYPE');
UPDATE payment_accounts SET account_id = 924 WHERE id = 1;
UPDATE payment_accounts SET account_id = 910 WHERE id = 2;
UPDATE payment_accounts SET account_id = 925 WHERE id = 3;
UPDATE expense_categories SET account_id = 927 WHERE id = 1;
UPDATE expense_categories SET account_id = 911 WHERE id = 2;
SELECT pg_temp.expect_eq('gaps: only the stock-adjustment role is missing',
    (SELECT string_agg(gap_type || ':' || label, ',') FROM fn_posting_gaps()), 'ROLE:INVENTORY_ADJUSTMENT');

SELECT pg_temp.expect_error('journal: an entry of a document is not written by hand',
    'INSERT INTO journal_entries (entry_date, description, created_by, source_type, source_id) VALUES (''2026-03-02'', ''TEST'', 1, ''EXPENSE'', 1)', 'RROKA_JOURNAL_AUTO');
SELECT pg_temp.expect_error('journal: a document entry names its document',
    $q$DO $b$ BEGIN PERFORM set_config('rroka.auto_posting', 'on', true);
       INSERT INTO journal_entries (entry_date, description, created_by, source_type) VALUES ('2026-03-02', 'TEST', 1, 'EXPENSE'); END $b$$q$,
    'journal_entries_document_source');

-- Backlog posting (auto-posting still off): the project expense goes to WIP.
SELECT pg_temp.expect_ok('backlog: post the project expense', 'SELECT fn_post_document(''EXPENSE'', 1, 1)');
SELECT pg_temp.expect_eq('expense entry: WIP 250 for project 1',
    (SELECT sum(l.debit) FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'EXPENSE' AND j.source_id = 1 AND l.account_id = 921 AND l.project_id = 1), 250.00::numeric);
SELECT pg_temp.expect_eq('expense entry: input VAT 37.50',
    (SELECT sum(l.debit) FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'EXPENSE' AND j.source_id = 1 AND l.account_id = 922), 37.50::numeric);
SELECT pg_temp.expect_eq('expense entry: cash box credited 287.50',
    (SELECT sum(l.credit) FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'EXPENSE' AND j.source_id = 1 AND l.account_id = 924), 287.50::numeric);
SELECT pg_temp.expect_eq('expense entry: posted with a number',
    (SELECT status || ':' || (entry_no IS NOT NULL) FROM journal_entries WHERE source_type = 'EXPENSE' AND source_id = 1), 'POSTED:true');
SELECT pg_temp.expect_error('posting: a document posts once',
    'SELECT fn_post_document(''EXPENSE'', 1, 1)', 'RROKA_POSTING_DUPLICATE');
SELECT pg_temp.expect_error('posting: a draft document does not post',
    'SELECT fn_post_document(''TRANSFER'', 2, 1)', 'RROKA_POSTING_NOT_APPROVED');
SELECT pg_temp.expect_ok('backlog: post the custody expense', 'SELECT fn_post_document(''EXPENSE'', 4, 1)');
SELECT pg_temp.expect_eq('custody expense: credited to the custodian (employee 11)',
    (SELECT l.partner_type || ':' || l.partner_id FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'EXPENSE' AND j.source_id = 4 AND l.account_id = 925), 'EMPLOYEE:11');
SELECT pg_temp.expect_eq('custody expense: not a project → its category account 300',
    (SELECT sum(l.debit) FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'EXPENSE' AND j.source_id = 4 AND l.account_id = 927), 300.00::numeric);
SELECT pg_temp.expect_ok('backlog: post the purchase invoice', 'SELECT fn_post_document(''PURCHASE'', 1, 1)');
SELECT pg_temp.expect_eq('purchase entry: inventory 970 = stock received',
    (SELECT sum(l.debit) FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'PURCHASE' AND j.source_id = 1 AND l.account_id = 920), 970.00::numeric);
SELECT pg_temp.expect_eq('purchase entry: supplier 1 owed 1,120',
    (SELECT l.credit || ':' || l.partner_type || ':' || l.partner_id FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'PURCHASE' AND j.source_id = 1 AND l.account_id = 923), '1120.00:SUPPLIER:1');
SELECT pg_temp.expect_ok('backlog: post the custody issue', 'SELECT fn_post_document(''TRANSFER'', 1, 1)');
SELECT pg_temp.expect_eq('transfer entry: custody debited, cash credited 800',
    (SELECT string_agg(account_id || ':' || debit || ':' || credit, ',' ORDER BY account_id) FROM journal_lines
      WHERE entry_id = (SELECT id FROM journal_entries WHERE source_type = 'TRANSFER' AND source_id = 1)), '924:0.00:800.00,925:800.00:0.00');
SELECT pg_temp.expect_ok('exclusion: a document left out of the books with a reason',
    'INSERT INTO posting_exclusions (source_type, source_id, reason, created_by) VALUES (''TRANSFER'', 4, ''TEST in the opening entry'', 1)');
SELECT pg_temp.expect_error('exclusion: needs a reason',
    'INSERT INTO posting_exclusions (source_type, source_id, reason, created_by) VALUES (''EXPENSE'', 99, '' '', 1)', 'check constraint');
SELECT pg_temp.expect_error('exclusion: a posted document cannot be excluded',
    'INSERT INTO posting_exclusions (source_type, source_id, reason, created_by) VALUES (''EXPENSE'', 1, ''TEST'', 1)', 'RROKA_POSTING_DUPLICATE');
SELECT pg_temp.expect_error('exclusion: an excluded document does not post',
    'SELECT fn_post_document(''TRANSFER'', 4, 1)', 'RROKA_POSTING_EXCLUDED');
SELECT pg_temp.expect_eq('backlog: posted and excluded documents leave it',
    (SELECT count(*) FROM v_posting_backlog WHERE source_type IN ('EXPENSE', 'PURCHASE', 'TRANSFER')), 0::bigint);
SELECT pg_temp.expect_error('journal: the entry of a document is not reversed by hand',
    format('INSERT INTO journal_entries (entry_date, description, created_by, source_type, reverses_id) VALUES (''2026-03-20'', ''TEST'', 1, ''REVERSAL'', %s)',
           (SELECT id FROM journal_entries WHERE source_type = 'EXPENSE' AND source_id = 1)), 'RROKA_JOURNAL_AUTO');
SELECT pg_temp.expect_error('journal: the entry of a document cannot be deleted',
    'DELETE FROM journal_entries WHERE source_type = ''EXPENSE''', 'RROKA_JOURNAL_AUTO');

-- Switch on auto-posting.
INSERT INTO accounts (id, code, name, account_type, parent_id, system_role, detail_type) VALUES
    (926, '5207', 'TEST stock count differences', 'EXPENSE', 901, 'INVENTORY_ADJUSTMENT', 'EXPENSES');
SELECT pg_temp.expect_ok('auto-posting: switched on once everything is mapped', 'UPDATE accounting_settings SET auto_posting = true');
SELECT pg_temp.expect_eq('auto-posting: the switch time is recorded', (SELECT auto_posting_since IS NOT NULL FROM accounting_settings), true);
SELECT pg_temp.expect_error('mapping: not removed while auto-posting is on',
    'UPDATE expense_categories SET account_id = NULL WHERE id = 1', 'RROKA_POSTING_MAPPING');

INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method, payment_account_id)
    VALUES (6, '2026-03-10', 2, 'TEST landlord', 'TEST March rent', 100, 15, 'CASH', 1);
SELECT pg_temp.expect_ok('auto-posting: approving an expense posts it',
    'UPDATE expenses SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 6');
SELECT pg_temp.expect_eq('auto-posting: rent expense 100 to its category account, posted by the approver',
    (SELECT l.debit || ':' || j.posted_by FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'EXPENSE' AND j.source_id = 6 AND l.account_id = 911), '100.00:2');

INSERT INTO expense_categories (id, name, is_overhead) VALUES (3, 'TEST unmapped', false);
INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method, payment_account_id)
    VALUES (7, '2026-03-10', 3, 'x', 'TEST unmapped', 10, 0, 'CASH', 1);
SELECT pg_temp.expect_error('auto-posting: an unmapped category refuses the approval',
    'UPDATE expenses SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 7', 'RROKA_POSTING_MAPPING');
SELECT pg_temp.expect_eq('auto-posting: the refused expense stays a draft', (SELECT status FROM expenses WHERE id = 7), 'DRAFT');
SELECT pg_temp.expect_ok('auto-posting: a project expense needs no category account (it goes to WIP)',
    'UPDATE expenses SET project_id = 1, status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 7');

INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method, payment_account_id)
    VALUES (8, '2025-12-20', 2, 'x', 'TEST before the books', 10, 0, 'CASH', 1);
SELECT pg_temp.expect_ok('auto-posting: a document before the books start approves',
    'UPDATE expenses SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 8');
SELECT pg_temp.expect_eq('auto-posting: … and belongs to the opening balances (no entry, not in the backlog)',
    (SELECT count(*) FROM journal_entries WHERE source_type = 'EXPENSE' AND source_id = 8)
  + (SELECT count(*) FROM v_posting_backlog WHERE source_type = 'EXPENSE' AND source_id = 8), 0::bigint);

UPDATE fiscal_periods SET status = 'CLOSED', closed_by = 2, closed_at = now() WHERE period_start = '2026-02-01';
INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method, payment_account_id)
    VALUES (9, '2026-02-15', 2, 'x', 'TEST February receipt', 10, 0, 'CASH', 1);
SELECT pg_temp.expect_error('auto-posting: no approval into a closed month',
    'UPDATE expenses SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 9', 'RROKA_PERIOD_CLOSED');

INSERT INTO treasury_transfers (id, transfer_date, from_account_id, to_account_id, amount) VALUES (5, '2026-03-11', 1, 2, 50);
SELECT pg_temp.expect_ok('auto-posting: approving a transfer posts it',
    'UPDATE treasury_transfers SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 5');
SELECT pg_temp.expect_eq('auto-posting: bank debited 50',
    (SELECT sum(l.debit) FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'TRANSFER' AND j.source_id = 5 AND l.account_id = 910), 50.00::numeric);

-- Stock: issue to a project, return, count difference; reservations post nothing.
INSERT INTO stock_movements (id, material_id, movement_type, quantity, project_id, created_by) VALUES (9001, 5, 'RESERVE', 1, 1, 1);
SELECT pg_temp.expect_eq('stock: a reservation posts nothing', (SELECT count(*) FROM journal_entries WHERE source_type = 'STOCK' AND source_id = 9001), 0::bigint);
INSERT INTO stock_movements (id, material_id, movement_type, quantity, project_id, created_by) VALUES (9002, 5, 'ISSUE', 2, 1, 1);
SELECT pg_temp.expect_eq('stock: issue 2 × 87.30 → WIP of project 1',
    (SELECT l.debit || ':' || l.project_id FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'STOCK' AND j.source_id = 9002 AND l.account_id = 921), '174.60:1');
INSERT INTO stock_movements (id, material_id, movement_type, quantity, project_id, created_by) VALUES (9003, 5, 'RETURN', 1, 1, 1);
SELECT pg_temp.expect_eq('stock: return credits WIP of project 1',
    (SELECT l.credit || ':' || l.project_id FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'STOCK' AND j.source_id = 9003 AND l.account_id = 921), '87.30:1');
INSERT INTO stock_movements (id, material_id, movement_type, quantity, reason, created_by) VALUES (9004, 6, 'ADJUST_OUT', 1, 'TEST count', 1);
SELECT pg_temp.expect_eq('stock: a count shortage goes to the adjustment account',
    (SELECT sum(l.debit) FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id
      WHERE j.source_type = 'STOCK' AND j.source_id = 9004 AND l.account_id = 926), 19.40::numeric);
SELECT pg_temp.expect_error('stock: a receipt outside a purchase invoice is not auto-posted',
    'INSERT INTO stock_movements (material_id, movement_type, quantity, unit_cost, created_by) VALUES (5, ''RECEIPT'', 1, 10, 1)', 'RROKA_POSTING_MANUAL_ONLY');

INSERT INTO purchase_invoices (id, supplier_id, supplier_invoice_no, invoice_date, discount_amount, vat_amount) VALUES (21, 1, 'INV-79', '2026-03-15', 0, 15);
INSERT INTO purchase_invoice_lines (purchase_invoice_id, line_no, material_id, quantity, unit_price) VALUES (21, 1, 6, 5, 20);
SELECT pg_temp.expect_ok('auto-posting: approving a purchase invoice posts it',
    'UPDATE purchase_invoices SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 21');
SELECT pg_temp.expect_eq('auto-posting: one entry for the invoice, none for its receipts',
    (SELECT count(*) FROM journal_entries WHERE (source_type = 'PURCHASE' AND source_id = 21)
        OR (source_type = 'STOCK' AND source_id IN (SELECT m.id FROM stock_movements m JOIN purchase_invoice_lines l ON l.id = m.purchase_invoice_line_id WHERE l.purchase_invoice_id = 21))), 1::bigint);
SELECT pg_temp.expect_eq('ledger: inventory = 970 + 100 − 174.60 + 87.30 − 19.40',
    (SELECT sum(net) FROM v_ledger_lines WHERE account_id = 920), 963.30::numeric);
SELECT pg_temp.expect_eq('ledger: still balances with automatic entries', (SELECT sum(debit) - sum(credit) FROM v_ledger_lines), 0.00::numeric);
UPDATE accounting_settings SET auto_posting = false;
SELECT pg_temp.expect_eq('auto-posting: switched off clears the time', (SELECT auto_posting_since FROM accounting_settings), NULL::timestamptz);

-- Demo data never reaches the books (it must stay removable).
INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method, payment_account_id)
    VALUES (10, '2026-03-12', 2, 'x', 'TEST demo expense', 10, 0, 'CASH', 1);
INSERT INTO demo_records (table_name, row_id) VALUES ('expenses', 10);
UPDATE expenses SET status = 'APPROVED', approved_by = 2, approved_at = now() WHERE id = 10;
SELECT pg_temp.expect_eq('demo: an approved demo document is not in the backlog',
    (SELECT count(*) FROM v_posting_backlog WHERE source_type = 'EXPENSE' AND source_id = 10), 0::bigint);
SELECT pg_temp.expect_error('demo: a demo document is never posted',
    'SELECT fn_post_document(''EXPENSE'', 10, 1)', 'RROKA_POSTING_DEMO');
INSERT INTO expense_categories (id, name, is_overhead) VALUES (4, 'TEST demo category', false);
INSERT INTO demo_records (table_name, row_id) VALUES ('expense_categories', 4);
UPDATE expense_categories SET account_id = 927 WHERE id = 3;
SELECT pg_temp.expect_ok('demo: unmapped demo categories do not block auto-posting', 'UPDATE accounting_settings SET auto_posting = true');
INSERT INTO expenses (id, expense_date, category_id, payee, description, amount, vat_amount, payment_method, payment_account_id)
    VALUES (11, '2026-03-12', 4, 'x', 'TEST demo expense 2', 10, 0, 'CASH', 1);
INSERT INTO demo_records (table_name, row_id) VALUES ('expenses', 11);
SELECT pg_temp.expect_ok('demo: approving a demo document with auto-posting on posts nothing',
    'UPDATE expenses SET status = ''APPROVED'', approved_by = 2, approved_at = now() WHERE id = 11');
SELECT pg_temp.expect_eq('demo: … no entry', (SELECT count(*) FROM journal_entries WHERE source_type = 'EXPENSE' AND source_id = 11), 0::bigint);
SELECT pg_temp.expect_ok('demo: stock moved while demo data loads posts nothing',
    $q$DO $b$ BEGIN PERFORM set_config('rroka.demo', 'on', true);
       INSERT INTO stock_movements (id, material_id, movement_type, quantity, reason, created_by) VALUES (9005, 6, 'ADJUST_OUT', 1, 'TEST demo', 1); END $b$$q$);
SELECT pg_temp.expect_eq('demo: … no stock entry', (SELECT count(*) FROM journal_entries WHERE source_type = 'STOCK' AND source_id = 9005), 0::bigint);
UPDATE accounting_settings SET auto_posting = false;

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
