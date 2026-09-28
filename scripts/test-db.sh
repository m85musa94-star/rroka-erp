#!/usr/bin/env bash
# Creates a throwaway database, loads the schema, runs the business-rule tests, drops it.
# Connection: standard libpq env vars (PGHOST, PGPORT, PGUSER, PGPASSWORD).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB="rroka_schema_test_$$"

cleanup() { psql -d postgres -qc "DROP DATABASE IF EXISTS $DB" >/dev/null 2>&1 || true; }
trap cleanup EXIT

psql -d postgres -qc "CREATE DATABASE $DB"
psql -d "$DB" -q -v ON_ERROR_STOP=1 -f "$ROOT/database/schema/rroka_schema.sql"
psql -d "$DB" -q -v ON_ERROR_STOP=1 -f "$ROOT/database/sql-tests/schema_rules_test.sql"
