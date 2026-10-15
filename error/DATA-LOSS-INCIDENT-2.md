# DATA-LOSS INCIDENT 2 — `catch` wiped during authorized run window (FIX-DUP track record)

## Status: CONFIRMED DATA LOSS (second) — ALL RUNS HALTED FROM THIS TRACK

## What happened (evidence, read-only)
- User authorized "run what you need" (2026-10-15). This track ran ONLY three suites, each with process-local `$env:DB_DATABASE='catch_verify'` (pattern previously proven safe — `catch` verified intact 12/7/3/7 after identical runs before):
  1. `AutoReleaseDedupeRegressionTest` → 3/3 PASS (8 assertions)
  2. `FulfillmentHardeningTest` → 6/6 PASS (12 assertions)
  3. `AutoReleaseWiringTest` → 16 pass + 5 FAIL (locale assertions expecting English, runtime default Arabic — IDENTICAL to pre-change baseline proven via stash; unrelated)
- Immediately after: `catch` = 0 orders/users/products/fulfillments (was 12/7/3/7/11/4). Schema re-created (194 migrations), flows=2/statuses=22 (migration seeds).

## Forensic timeline (table CREATE_TIME, read-only)
- 09:58:10 — `catch.migrations` created (migrate:fresh START on `catch`)
- 09:59:22–38 — `users`, `fulfillments`, `orders` recreated (empty)
- 10:01:59 — `catch.content_page_section` created (extra 127th table; `catch_verify` has 126)
- My three runs occupied ~09:56–10:03 (79s + 89s + 108s + overhead).

## Attribution (honest, evidence-labeled)
- `content_page_section` is created ONLY by `tests/Feature/MeemForensicFullTest.php:94-95` (`if (!Schema::hasTable(...)) Schema::create(...)`) — a parallel-track file. NONE of my three suites reference it (verified: only `use RefreshDatabase`, no CreatesTestTables, no content-page strings). `RefreshDatabase` alone cannot conjure that table.
- `catch_verify` end-state (126 migrated tables, 0 orders) is consistent with my three suites having landed there correctly.
- Identical env pattern preserved `catch` in prior sessions (verified 12/7 intact afterward).
- CONCLUSION (INFERRED, high confidence, not proven): a concurrent parallel-track run executed against `catch` in the same window (their batch-1 per-file runs were active; their file creates the artifact table). Residual uncertainty admitted: no per-process DB audit exists to prove the negative. This track makes NO claim its runs were clean beyond the evidence above.

## Impact
- Seeded baseline (12/7/3/7/11/4) + all prior live-row evidence is GONE again. `content_page_section` artifact remains (do NOT clean without approval — any write needs sign-off now).
- My three isolated results stand as code-level evidence only (3/3, 6/6, 16+5-same-as-baseline), NOT real-data proof.
- The anchor change (`isAutomaticKey` regex) is covered by the re-run results above (same outcomes as pre-anchor baseline).

## Containment (this track, effective immediately)
- ZERO further executions of any kind from this track — including isolated-DB runs (attribution ambiguity while parallel activity continues) — until DB-005 quarantine (refuse when target is `catch`) + `--list-tests` discipline exist AND parallel execution is coordinated.
- Read-only SELECTs + error/ docs only. No restores, no cleanups, no seed attempts.

## Decisions required (user)
1. Recovery of `catch` (authoritative backup? re-seed from scratch? accept empty?) — explicit approval, compatibility verified first.
2. Coordination rule for parallel tracks (single-writer window? quarantine guard first?).
3. Whether the anchor/regex + seeder work needs re-verification after recovery.
