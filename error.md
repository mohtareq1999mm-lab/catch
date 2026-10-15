# Problem
- `catch` live database was wiped by running `php artisan test --filter=AuthenticationTest`, which substring-matched `CategoryAuthenticationTest` using `RefreshDatabase` (migrate:fresh) against live `catch`.

# Evidence
- BEFORE (VERIFIED): users=8, orders=13, products=4824, flows=2, statuses=22, warehouses=1, 126 tables.
- AFTER (VERIFIED): users=0, orders=0, products=0, flows=2 (re-seeded), 126 tables.
- Unsafe file: `tests/Feature/Categories/CategoryAuthenticationTest.php:7,20` (`use RefreshDatabase`).
- Command: `php artisan test --filter=AuthenticationTest` → `4 failed, 41 passed` (INVALID numbers, DB migrating mid-run).
- Full detail: `error/DATA-LOSS-INCIDENT.md`.

# Impact
- All 13 orders / 4824 products / 8 users in `catch` are gone. Schema intact (126 tables).
- Every write/integration/concurrency test is BLOCKED until recovery is decided.
- Read-only audit (routes, code, schema, translations, security review) remains valid and can continue.

# Blocked decisions
1. How to recover `catch` (authoritative backup? re-import? accept empty? point-in-time?) — requires user approval + compatible dump. `meemmarket_meem.sql` is NOT a verified catch backup (different DB name/server, data differs). No restore attempted.
2. Continue read-only vs pause until recovery.
3. Quarantine RefreshDatabase tests (mandatory follow-up).

# Safe next step
- Continue ONLY read-only audit (no test runs, no writes, no migrations, no restores) until user decides recovery. Ask via question tool now.
