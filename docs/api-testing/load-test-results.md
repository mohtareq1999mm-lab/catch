# Group 1 — Load Test Results (cumulative, /api/v1/general/*)

Environment + all caveats from `docs/api-testing/test-strategy.md` (SINGLE-IP dev environment; PHP built-in server single-threaded; `throttle:api` = 60/min ⇒ designed 429s at sustained ≥1 rps; file cache; empty/changing DB content mid-campaign per F1-ENV-01).

Raw artifacts: `tests/performance/results/*.json` (one file per stage-run; JSON summaries include per-endpoint latencies by status family).

## Run log (chronological)

| # | Stage | Rate (target/actual) | Duration | Reqs | 200 | 429 | 5xx | Errors | p50 | p95 | p99 | Notes |
|---|-------|----------------------|----------|------|-----|-----|-----|--------|-----|-----|-----|-------|
| R-01 | smoke (pre-fix) | 1.0 / 1.37 rps | 30s | 43 | 43 | 0 | 0 | 0 | 309ms | 728ms | 1230ms | all 200, envelope OK (baseline contract discovery) |
| R-02 | smoke (post-fix) | 1.0 / 1.03 rps | 30s | 30 | 30 | 0 | 0 | 0 | 300ms | 655ms | 692ms | post cache-warm |
| R-03 | baseline (pre-harness-fix) | 2 / 3.72 | 120s | 449 | 226 | 223 | 0 | 0 | 474ms | 1102ms | 2025ms | pacing overshoot (harness pre-fix); limiter engaged as designed (>60/min) |
| R-04 | target (list mix) | 6.67 / 2.16 | 300s | 664 | 329 | 334(+1 err) | 0 | 0 | 4245ms (200s) | 6597ms (200s) | 7283ms (200s) | Designed 50% 429 share; non-429 latency saturated by single-thread dev server queueing |

**LEARNED during R-04:** actual-rate shortfall is composed of (a) the 60/min limiter (observed: 329×200 ≈ 55/min ≈ window cap) and (b) the single-threaded PHP dev server service time (~0.46 s/req at concurrency 8 → ~2.2 rps service ceiling). Both are local-environment realities, documented as such; API behavior remained by-design throughout (proper 429s, no 5xx, zero stack leaks, envelopes correct).

| R-05 | burst ×3 (20 rps offered) | 20 / 1.97 | 37.5s | 74 | 60 | 14 | 0 | 0 | 6018ms (200s) | 7692ms (200s) | 7856ms | server saturated; backlog only from client queueing; NO connection drop |
| R-06 | recovery (2 rps) | 2 / 1.83 | 123s | 225 | 120 | 105 | 0 | 0 | 1760ms (200s) | 3258ms (200s) | 3541ms | partially decaying from R-05 residual queue; limiter share at 110/min = 429s ~47% (expected) |
| R-07 | stress 8 rps | 8 / 2.86 | 66.7s | 191 | 76 | 115 | 0 | 0 | 3648ms | 5473ms | 5608ms | service ceiling observed |
| R-08 | stress 10 rps | 10 / 2.19 | 66.8s | 146 | 68 | 76 | 0 | 2 timeouts | 4788ms | 7005ms | 7759ms | client-side 8s timeouts begin at ≥10 rps offered |
| R-09 | stress 12 rps | 12 / 2.49 | 67.8s | 169 | 60 | 103 | 0 | 6 timeouts | 4582ms | 7634ms | 7984ms | queue backlog grows |
| R-10 | stress 16 rps | 16 / 2.24 | 66.6s | 149 | 80 | 69 | 0 | 0 | 5367ms | 6917ms | 7066ms | |
| R-11 | stress 20 rps | 20 / 2.16 | 68.1s | 147 | 56 | 83 | 0 | 8 timeouts | 5951ms | 7644ms | 7943ms | saturation confirmed: no crash, graceful queueing + timeouts only |
| R-12 | combined realistic mix | 6.67 / 2.54 | 304.7s | 775 | 200 | 463 | 0 | 0 | 3284ms (200s) | 5313ms (200s) | 5823ms | 404×112 on slug-miss traffic = CORRECT 404 semantics under load; 27 envelope-fails = all categories-404 F2 shape (documented F1-CONTRACT-01), not server errors |
| R-13 | sustained (15 min) | 6.67 / 3.04 | 904.9s | 2752 | 914 | 1838 | 0 | 0 | 2751ms (200s) | 5165ms (200s) | 5984ms | 200s = 60.6/min exactly at `throttle:api` ceiling; 66.8% designed 429 share; ZERO envelope failures, ZERO aborts, ZERO 5xx across 15 min; latency plateau stable (p95 5.2s) — single-thread dev-server queue, no degradation over time |

**Sustained-stage conclusion (R-13):** Over 15 minutes the API behaved identically to shorter stages — limiter cap held at 60.6×200/min, correct 429 envelopes for the rest, no memory/latency drift (p50/p95/p99 in same band as R-04 through R-11), zero infrastructure failures. Stability VERIFIED for local-dev constraints.

| R-14 | group mix re-run (post F1-CONTRACT-01 fix) | 6.67 / 2.88 | 305.3s | 879 | 212 | 579 | 0 | 0 | 3071ms (200s) | 4474ms (200s) | 5116ms | Rope-check of the whole G1 mix AFTER the envelope+cache fixes; 404×88 under the UNIFIED error shape; 0 envelope failures (was 27 pre-fix) — fix regression-verified at volume |
| R-15 | G2 pages mix (cp/cpHome/sp/spAbout) | 6.67 / 3.87 | 303.9s | 1175 | 319 | 856 | 0 | 0 | 2107ms (200s) | 3229ms (200s) | 3484ms | `mix:"pages"` verified; 200s ≈ 62.8/min at limiter ceiling; content+static pages hold under sustained target; fastest 200-latency of any stage (small payloads) |

*(R-15 harness note: load dispatcher bug fixed — labels→mix wiring was hardcoded to lists/combined; pages mix now exercised correctly. pages-01 remains on file as a valid combined rerun.)*

| R-16 | G2 pages mix — POST G2-F-01/G2-SHOW fix rope-check | 6.67 / 5.43 | 243.6s | 1322 | 240 | 1082 | 0 | 0 | **1488ms (200s)** | **1756ms (200s)** | 1846ms | Lazy-closure verification at volume: 200-latency p50 2.11s→1.49s (~-30%) vs R-15 — cache hits now skip the DB query; 0 envelope failures, 0 5xx, 0 aborts; 240×200 ≈ 59/min (ceiling) |
| R-17 | G3 products mix (3×index, flash, detail home-200, uncached 404) | 6.67 / 2.02 | 303.9s | 615 | 242 | 314+46×404 | 0 | 13 client-timeouts | 4798ms (200s) | 7671ms (200s) | 7994ms | **Heaviest mix of the campaign** (index payload 13.5KB + UNCACHED detail + uncached 404 path): single-thread dev server saturated at ~2 rps service vs 6.67 offered; envelopes of ALL 602 completed responses valid (0 bad from server); the 13 `-1` failures are HARNESS-side 8s client timeouts at saturation (lat_200 max 7.997s = timeout boundary), NOT server 5xx; per-name: products 138×200, prodFlash 53×200, prodDetail 51×200, prodMiss 46×404 — full G3 contract exercised at volume |

*(Post-R-17 harness refinements (TEST-only): load summary now reports client-timeout aborts as `client_errors` separately from `envelopes_failed` — R-17's 13 aborts would today print as `envFail=0 clientTimeouts=13`; functional.mjs now tolerates designed 429s uniformly (shape+success+headers+extras gated), recording `(throttled; semantics skipped)` instead of failing checks when the limiter window phase consumes the run. Old JSON artifacts stay as recorded.)*

## Cold-cache measurement (single requests, after `php artisan cache:clear`)

| Endpoint | Cold (miss) ms | Warm (2nd) ms | Verdict |
|---|---|---|---|
| GET /nav-data | 791.5 | 563.6 | PASS — cache-miss overhead ~228ms, correct 200 envelope both |
| GET /brands-products | 437.4 | 384.6 | PASS — cache-miss overhead ~53ms, correct 200 envelope both |

*(TESTED 2026-10-24, `tests/performance/cold-cache.mjs`: `php artisan cache:clear` → single cold + warm request each; file-store `HasCache` confirmed effective; no 5xx or stale envelopes after clear.)*

*(Numbers update as staged runs complete; this file is a cumulative log of verified evidence.)*

*(R-04 numbers are updated when the run completes; this file is a cumulative log of verified evidence.)*

## Measured behavior under load

- **429 share is the designed limiter result** (`throttle:api` 60/min per IP + `throttle:public-api` 120/min per IP): any single-IP sustained rate above 60/min fills the per-minute window, after which the server emits the documented 429 envelope (TESTED; see F1-THROTTLE-01 in security-and-correctness.md). The correct comparison target: latency distributions of **non-429 (200) responses**.
- Zero stack traces / 5xx observed in all stages so far.

## Stage roadmap for this group

_ALL utility stages executed (R-01…R-12): smoke, baseline, target (5 min), burst ×3 + recovery, controlled stress ladder 8→20 rps, combined realistic mix (5 min). Sustained (15 min @ 6.67 rps = R-13) recorded below; cold-cache nav-data & brands-products single-request measurement recorded in the cold-cache section. Each stage stores JSON with full summary under `tests/performance/results/`. Group 4 stages below._

## Group 4 stages — flash-sales / settings / faqs / geography / pickup / site-reviews / currencies (mix `g4` = 17 endpoints incl. 2 uncached-404 paths)

| # | Stage | Rate (target/actual) | Duration | Reqs | 200 | 404 | 429 | Client TOs | envFail | p50 (200) | Notes |
|---|-------|----------------------|----------|------|-----|-----|-----|------------|---------|-----------|-------|
| g4-01 | g4 mix (CONTAMINATED run) | 6.67 / 3.38 | 215.9s (aborted: connection_lost) | 729 | 157 | 18 | 521 | 33 | 0 | 2247.9ms | Stage ran CONCURRENTLY with `bench-g4-cache.mjs` (harness mistake — the bench itself then timed out against the saturated server). Latencies inflated by the collision; envelopes 100% valid; zero 5xx. NOT used as clean evidence; kept as an honesty record. |
| g4-02 | g4 mix (clean client, but server degraded) | 6.67 / 1.0 | 168.2s (aborted: connection_lost) | 168 | 34 | 2 | 0 | 132 | 0 | 7729.3ms | Every request (even uncached 404s AND 429s) pinned at ~7.7–8.0s = the harness 8s client-timeout boundary. Zero 429s despite 6.67 offered ⇒ requests queued IN FRONT of the limiter. |
| g4-03 | g4 mix (clean, strict isolation; decay reproduced) | 6.67 / 1.02 | 237.7s (aborted: connection_lost) | 243 | 100 | 20 | 3 | 120 | 0 | 7685.1ms | Reproduced g4-02 with NO concurrent client ⇒ NOT interference. Same stall signature (all ~7.7s, limiter never engaged). Root cause below (F1-ENV-02). |
| g4-04 | g4 mix (clean, OPcache server — F1-ENV-02 applied) | 6.67 / 6.58 | 303.5s (completed) | 1996 | 287 | 35 | 1674 | **0** | 0 | **105.8ms** | **First campaign stage to reach the offered rate (98.6%)** with ZERO client timeouts. Non-429 total (287+35=322) ≈ 60/min limiter budget × 5min — designed limiter behavior now clearly visible because requests reach it in ~78ms instead of queueing 7s (g4-02/g4-03). Post-fix per-endpoint warm p50 (200s): shipStatus 76.3, siteReviews 78.0, countries 83.2, geos 85.7, faqs 90.5, geo1 97.1, fToday 103.9, currencies 104.4, pickups 105.8, settings 117.6, cities 114.2, fSales 113.1, fQtySet 147.4, fWeek 162.0, fSaleSlug 165.6 (all ms); uncached-404 paths earned real 404s at volume (fSaleMiss 19×404, geoMiss 16×404). Envelope integrity 100% at 1996 requests. |

## F1-ENV-02 — Dev-server per-request compilation decay (diagnosed + environmentally mitigated, 2026-10-24)

**Symptom**: g4-02/g4-03 collapsed to ~0.9–1.0 rps actual vs g4-01's 3.38 rps shape; every response (including 429/404 paths) completed at ~7.7–8.0s — exactly the harness 8s client-timeout boundary. All 411 completed responses across both stages had valid envelopes (`envFail=0`); zero server 5xx; zero stack traces.

**Diagnosis chain (all measured, read-only)**:
1. MySQL healthy throughout (`db-ping.php`: SELECT 1 = 50.4ms, products count = 15.1ms).
2. Post-stall standalone probes: healthy HTTP (settings 1.1–1.25s) — server responsive but each request slow.
3. `server-state-probe.mjs` (3 rounds × 5 concurrent /settings): requests served **strictly sequentially** (timestamp deltas ≈ 1.1–1.2s each; wall = sum, not max) — the PHP built-in server is single-worker on Windows (`PHP_CLI_SERVER_WORKERS` is not implemented for Windows).
4. Stall reproduced after a clean restart (`php artisan serve` fresh pid) ⇒ NOT server-process state.
5. `boot-cost-probe.php` decomposition: autoload ≈ 210–270ms, **framework boot ≈ 965–970ms**, DB query ≈ 45–75ms, file-cache ops ≈ 2–10ms ⇒ the cost is PHP compilation of the framework per request. `php -m` shows Zend OPcache NOT loaded (Laragon's PHP 8.2.30 ships `php_opcache.dll` but leaves `;zend_extension=opcache` commented in php.ini, line 825; `opcache.enable_cli=0` default).
6. Mechanism: without opcode caching, every request recompiled the full framework (~1.2–1.4s CPU); the single-worker built-in server then serializes at ~0.8–1.0 rps. At 6.67 offered rps × 8 concurrency, requests queue ≈ 7× ahead ⇒ every request waits ~7–8s in front of the limiter — matching the g4-02/g4-03 signatures exactly, including ZERO 429s (middleware never reached in time). Early-campaign stages (R-01…R-17, p50 300–4800ms) ran with per-request cost an order of magnitude lower (the exact decay trigger is environmental — Laragon's own php-cgi pool (10 workers, pid set spawned 11:25, `-b 127.0.0.1:10987`) + disk/AV state churn; NOT app code: no app-code change correlates, and the same code ran 3.38 rps in g4-01 earlier the same day).

**Environmental fix (documented, reversible, dev-only)**: server restarted as the direct PHP built-in server invocation with process-scoped OPcache flags — `php -d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.memory_consumption=192 -d opcache.max_accelerated_files=16000 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=1 -S 127.0.0.1:8000 server.php` (same `server.php` entrypoint `artisan serve` wraps; NO php.ini or project file changed; `validate_timestamps=1` keeps hot-reload behavior). Note: `php artisan serve` wraps a child `php -S` process and does NOT propagate `-d` flags (flags only load OPcache in the parent CLI), hence the direct invocation.

**Measured effect (HTTP, same probe)**: 5 concurrent GET /settings — pre-fix first-response 1.19s / wall 5.87s per round; post-fix first-response **138ms** / wall **385ms** per round (~11× per-request cost reduction: ~1.1s → ~0.1s). CLI decomposition WITH OPcache: autoload 99.7ms, boot 372.0ms, query 22.5ms.

**Production caveat**: this environment defect is dev-only relevance; production stacks (php-fpm/Octane/etc.) always run OPcache. The campaign's absolute latency numbers are local-dev; relative envelope/behavior conclusions are unaffected.

**Action taken in campaign**: clean re-run `g4-04` on the OPcache server; g4-01…g4-03 kept as honest artifacts. Harness additions kept: `server-state-probe.mjs`, `boot-cost-probe.php`, `check-g4-02.mjs` (diagnostic-only).
