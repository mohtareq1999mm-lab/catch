# Group 1 — Final Audit (evidence-based status) — with Group 2/3/4 additions

> Status labels: VERIFIED / TESTED / INFERRED / UNKNOWN / RISK. Numbers local-dev only (PHP built-in server single-thread); production-fidelity claims are explicitly UNVERIFIED.

## Endpoint pass/fail status (Group 1: 15/15, Group 2: 4/4, Group 3: 4/4, Group 4: 17 GET + `currencies/select` + G4b reads/payment boundaries + 2 sanctum write boundaries)

Verdict basis: contract matrix verified live (`tests/performance/functional.mjs`, 37 checks — deterministic incl. limiter-window tolerance), semantic cross-check (HTTP vs read-only DB), ALL load stages incl. 15-min sustained and cold-cache (R-01…R-13 — see load-test-results.md), security probes (auth, limiter, code exposure, mass-assignment, injection).

| Group-1 endpoint | Correctness | Contract | Security | Load behavior | Overall |
|---|---|---|---|---|---|
| GET /nav-data | TESTED (empty categories state + level clamps) | PASS | PASS (public, no leaks) | TESTED | PASS w/ env caveat |
| GET /categories | TESTED | PASS | PASS | TESTED | PASS w/ env caveat |
| GET /categories/{slug} | TESTED (404, unicode, 250-char) | PASS | PASS | TESTED (404 mix) | PASS |
| GET /brands | TESTED (semantic cross-check, ids desc) | PASS | PASS | TESTED | PASS |
| GET /brands/{slug} | TESTED (semantic cross-check pampers→id30) | PASS | PASS | TESTED | PASS |
| GET /brands-products | TESTED (shape, ctx 10 items) | PASS | PASS | TESTED | PASS |
| GET /banners | TESTED (8 active = DB status=1) | PASS | PASS | TESTED | PASS |
| GET /banners/{slug} | TESTED 404 + with_products | PASS | PASS | TESTED | PASS |
| GET /sliders | TESTED (10 active) | PASS | PASS | TESTED | PASS |
| GET /sliders/{slug} | TESTED 404 | PASS | PASS | TESTED | PASS |
| GET /tags | TESTED + **FIXED 500→422 (order)** | PASS after fix | PASS (clamps) | TESTED | PASS after fix |
| GET /tags/{slug} | TESTED 404 | PASS | PASS | TESTED (limited) | PASS |
| GET /promotions | TESTED (shape; empty/seeded) | PASS | PASS | TESTED | PASS |
| GET /promotions/{slug} | TESTED 404 + with_product | PASS | PASS | TESTED (limited) | PASS |
| GET /coupons | TESTED (no code exposure; unresolvable bearer → 401) | PASS | PASS | TESTED | PASS |
| GET /content-pages | TESTED (semantic: sections=19 = DB active ✓) | PASS | PASS | TESTED (pages-02) | PASS (G2-F-01 FIXED: lazy closure) |
| GET /content-pages/{slug} | TESTED (home detail + 404 unified envelope) | PASS | PASS | TESTED (pages-02) | PASS (G2-SHOW FIXED: firstOrFail inside lazy closure) |
| GET /static-pages | TESTED (3 slugs exact vs DB; all active) | PASS | PASS | TESTED (pages-02) | PASS |
| GET /static-pages/{slug} | TESTED (about-us detail + 404) | PASS | PASS | TESTED (pages-02) | PASS |
| GET /products | TESTED (16 contract checks incl. strategy types, 422s, whitelist-filter, limit clamp ≤100) | PASS | PASS | TESTED (products-01) | PASS (G3-F-01 + G3-F-02 FIXED: channel filter + channel-aware cache key) |
| GET /products/{slug} | TESTED (home detail 200, unknown slug 404 F1, channel semantics: fast-shipping id 200/404 by channel) | PASS | PASS | TESTED (products-01) | PASS (BY DESIGN: uncached detail = freshness trade-off) |
| POST /products/{id}/reviews | TESTED (boundary only — guest → 401; no write performed) | PASS (auth boundary) | PASS | n/a (write not exercised) | PASS |
| PUT /products/reviews/{id} | TESTED (boundary only — guest → 401; no write performed) | PASS (auth boundary) | PASS | n/a (write not exercised) | PASS |
| GET /flash-sales (+?slug delegate) | TESTED (4 valid sales exact vs DB; order 422; slug-delegate) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /flash-sales/{slug} | TESTED (sale + products; unknown slug 404 F1) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /flash-sale-products | TESTED (qty-set, unique ids, channel tap present) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /flash-sale-products-ending-this-week | TESTED (**G4-F-01 FIXED**: fast=6/home=8 live, disjoint, zero leaks vs DB join-count) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /flash-sale-products-ending-today | TESTED (empty arrays both channels — DB has 0; envelope valid) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /settings | TESTED (**G4-F-03 FIXED**: lazy closure; null-guard preserved) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /faqs | TESTED (15 active = DB exact; **G4-F-03 FIXED**) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /governorates (+/{id}) | TESTED (35 = DB active exact; 404s incl. whereNumber F2) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /countries (+/{id}) | TESTED (2 active; 404s) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /cities (+/{id}) | TESTED (102 = DB total exact; `all()` is schema-consistent — no status column) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /pickup-locations (+/{id}) | TESTED (4 active; inactive id=5 → 404 via scope; missing → 404) | PASS | PASS | TESTED (g4-01) | PASS (note: show catch-all → uniform 404) |
| GET /fast-shipping/status | TESTED (200 object, service-delegated) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /site-reviews | TESTED (6 = approved count exactly; pending=2 never exposed; **G4-F-03 FIXED**) | PASS | PASS | TESTED (g4-01) | PASS |
| GET /currencies | TESTED (6 active, USD/KWD/…/GBP; **G4-F-03 FIXED**) | PASS | PASS | TESTED (g4-01) | PASS |
| POST /general/currencies/select | TESTED (guest-safe: 422-first validation incl. exists-rule; 200 acknowledged, no persistence for guests — designed X-Currency flow) | PASS | PASS | n/a (no write for guests) | PASS |
| POST /general/site-reviews | TESTED (boundary only — guest 401 pre-validation, unified envelope; route is in the sanctum auth group — verified route:list -v + live) | PASS (auth boundary) | PASS | n/a (write not exercised) | PASS |
| POST /general/fast-shipping/checkout | TESTED (boundary only — guest → 401 sanctum) | PASS (auth boundary) | PASS | n/a (write not exercised) | PASS |
| GET /general/payment-gateways | TESTED (sanitized snapshot {gateways,payment_methods,fast_shipping}; no secrets) | PASS | PASS (no credentials serialized) | TESTED (g4-04 mix n/a, live probe) | PASS |
| GET /general/order-flows/available (+by-shipping-type/{type}) | TESTED (sanitized flows, no internal ids; local+international 200; unsupported type → 422 in-list) | PASS | PASS (design-doc'd sanitization verified live) | live probe | PASS |
| POST /general/track-order (public) | TESTED (boundary only — guest empty → 422 validation-first; no write) | PASS | PASS | n/a | PASS |
| POST /general/checkout/webhooks/{stripe,paypal} | TESTED (boundary only — malformed payload → 400 pre-signature; no write; provider-signed verification out of read-only scope) | PASS (reject path) | PASS | n/a | PASS |
| GET|POST /general/checkout/callback (+error-callback) | TESTED (boundary only — missing params → 400 "Missing payment ID") | PASS (reject path) | PASS | n/a | PASS |

Legend: TESTED = executed and verified with recorded evidence in this campaign. "Load behavior" tested under smoke/baseline/target (+ burst/recovery; others as available). No endpoint can be marked FULLY VERIFIED for production capacity (see UNVERIFIED).
Review-write routes: only the unauthenticated boundary was exercised (read-only campaign policy forbids DB writes); authenticated write/ownership behavior is code-inspected (`addProductReview`/`updateProductReview` delegate to `ProductService::__invoke` review logic with silent null → trait 404) and remains NOT-EXERCISED at runtime.

## What passed (VERIFIED/TESTED)

1. All 15 endpoints exist under `/api/v1/general/` (route:list), public (no auth), inside `throttle:api`+`throttle:public-api`.
2. Response envelopes verified live; three error galleries documented exactly (trait family, Handler family, 429 default).
3. Functional matrix 37/37 contract checks pass after the `tags.order` fix (500→422) — final run 2026-10-24 (regression guard asserts 422 + `errors.order`; rate-limit-window-tolerant ordering checks made the suite deterministic).
4. Semantic data matches DB (read-only cross-check): tags, brands, brand-by-slug, banners, sliders, promotions counts, coupons counts and NO code leakage on guest listing.
5. Rate limiter verified: 60/min per-IP (throttle:api) engages; `x-ratelimit-*` headers present; 429 body `{message:"Too Many Attempts.",status:false}`.
6. Zero 5xx / connection-abort / stack leaks under ALL load stages including the 15-minute sustained run (R-13: 2752 reqs, 0 failures).
7. Fixed bug (verified live + regression guard in suite): `tags?order=<invalid>` 500 → 422 (TagController).
8. Cold-cache behavior measured (`tests/performance/cold-cache.mjs`): nav-data cold 791.5ms → warm 563.6ms; brands-products cold 437.4ms → warm 384.6ms — file-cache `HasCache` effective; no stale envelopes after `cache:clear`.

## Defects & risks (by severity)

- **P2 F1-CONTRACT-01 → FIXED (2026-10-24, user-approved)**: Handler error responses now always carry `success:false` — unified `{message, status:false, success:false[, errors]}` alongside the trait family which already had `success`. Verified live; suite 37/37 PASS. Legacy `status:false` key retained deliberately (WMS test contracts assert it; removal = future approved breaking change).
- **P2 F1-REL-01 → root cause FIXED (2026-10-24, user-approved)**: `HasCache::flushTag()` no longer silently no-ops on tag-less stores — a per-tag key registry (`cache-tags:*`) makes invalidation real for file/database/array stores (selftest PASS on the app's `file` store; covers all controllers/observers via the single trait). Offline SQL/seeder writes remain bounded by TTL + runbook `cache:clear`.
- **P3 F1-PERF-01**: dev-server throughput ceiling ≈ 2–3 rps (single thread). Production runtime (Docker+supervisor+opcache) unproven in this campaign.
- **P3**: `coupons` listing uses `code:null` key instead of omitting; `Accept-Language` default leads to Arabic message on coupons/mine 401 while other paths return English (CheckLangMiddleware precedence) — cosmetic consistency.
- **P0/P1 none observed** on Group 1 after the FIX of the tags contract bug.

## 400 req/min target — verified report

- Requested: 400 req/min aggregate. Achievable under the verified limiter + dev-server constraints: full 400/min NOT sustainable from a single test IP (limiter ceiling 60/min; by design). **Sustained stage (R-13, 15 min @ 6.67 rps offered) proves the ceiling empirically: served 914×200 = 60.6/min — exactly the `throttle:api` limit — plus 1838 correctly-formed 429s; zero 5xx, zero aborts, latency plateau p50 2.75s / p95 5.17s (single-thread dev-server queue).**
- **Recommendation**: benchmark 400/min against production-like runtime with distributed client IPs (or temporarily raise the limiter under that environment only after approval).

## Unverified / not in scope (explicit)

- phpunit suite NOT executed (live-DB RefreshDatabase hazard; documented incident).
- Memory/CPU/metrics of server process (host-level instrumentation unavailable in sandbox).
- Cross-tenant authorization for write endpoints (out of Group 1 — read-only scope).
- CDN/observability/production configuration.

## Reproduce commands

```powershell
# Boot stack
php D:\work\meem\artisan serve --host=127.0.0.1 --port=8000

# Contract + security suite (includes bounded limiter burst)
node tests/performance/functional.mjs

# Load stages (each writes JSON to tests/performance/results)
node tests/performance/load.mjs --stage smoke --seconds 30
node tests/performance/load.mjs --stage baseline --seconds 120
node tests/performance/load.mjs --stage target --rate 6.67 --seconds 300
node tests/performance/load.mjs --stage burst --seconds 30 --rate 20
node tests/performance/load.mjs --stage recovery --seconds 120
node tests/performance/load.mjs --stage stress
node tests/performance/load.mjs --stage combined --seconds 300
node tests/performance/load.mjs --stage sustained --seconds 900
node tests/performance/load.mjs --stage pages --seconds 300
node tests/performance/load.mjs --stage products --seconds 300
node tests/performance/load.mjs --stage g4 --seconds 300

# OPcache dev server (F1-ENV-02 remediation; same server.php as artisan serve, flags only):
php -d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.memory_consumption=192 -d opcache.max_accelerated_files=16000 -S 127.0.0.1:8000 server.php

# Cache miss/hit effect check (G4-F-03; limiter-safe pacing):
php artisan cache:clear; node tests/performance/bench-g4-cache.mjs

# Diagnostics (read-only)
node tests/performance/server-state-probe.mjs
php tests/performance/boot-cost-probe.php

# Group 4 live probes (channel split + 404 family + POST boundaries)
node tests/performance/probe-g4.mjs
node tests/performance/probe-g4-boundary.mjs

# Group 4 semantic cross-checks (read-only DB)
php tests/performance/db-probe-g4.php
php tests/performance/db-probe-g4-facts.php

# Semantic cross-checks (read-only DB)
php tests/performance/db-probe.php
php tests/performance/semantic-db.php
node tests/performance/semantic-api.mjs
node tests/performance/summary.mjs          # top-level stats of all stage JSONs

# HasCache fix selftest (cache-only, no DB)
php tests/performance/hascache-selftest.php
```

## Final statement

Group 1 (15 endpoints) + Group 2 (4 endpoints) + Group 3 (4 endpoints) + Group 4 (17 GETs + `currencies/select` + G4b reads (`payment-gateways`, `order-flows/available`, `order-flows/by-shipping-type`) + payment-surface boundaries (`track-order`, callback/webhook reject paths) + 2 sanctum write boundaries): **PASS for local-dev correctness, contract, and security.**

Resolved findings (all user-approved fixes, all regression-guarded in `functional.mjs`):
1. `tags?order` 500→422 fix (G1).
2. Unified error envelope (`success:false` on every error; legacy `status:false` retained for compatibility — 4 WMS test contracts and unknown clients stay intact).
3. Real cache-tag invalidation on tag-less stores via the `HasCache` key registry (selftest PASS, lazy-run-count=1).
4. G2-F-01 / G2-SHOW: `ContentPageController` lazy-closure pattern (DB query only on cache miss; byte-identical payloads).
5. **G3-F-01**: `getFlashSalesAndHereProductsByQtySet` now applies the channel-home filter like every sibling strategy — flash listing no longer advertises products that detail 404s (live POC pre/post fix recorded).
6. **G3-F-02**: product listing cache key now includes the resolved channel (`|channel:<value>`) — cross-channel cache contamination between HOME and FAST-SHIPPING eliminated (live POC pre/post fix recorded).
7. **G4-F-01**: `FlashSaleService::getFlashSaleProductsEndingThisWeek` + `getFlashSaleProductsEndingToday` now apply `applyChannelHomeFilter` (mirroring the ProductService twins) — home/ending lists are channel-correct: live POC fast=6/[165,69,57,25,9,1] vs home=8/[169,149,109,105,77,53,41,29], fully disjoint vs DB join-count. Guards: `F4-week-home-nofast` / `F4-week-fast-disjoint` / `F4-week-default-eq-home`.
8. **G4-F-03**: seven controllers (Setting, FAQ, Governorate, Country, City, SiteReview, Currency) converted from eager-before-remember to the canonical lazy closure — the DB query + resource build now runs only on cache miss (structural fix proven by design of `HasCache::remember`); shapes preserved; Setting's null-guard semantics kept — null is never cached, only re-queried. Measured miss/hit evidence recorded (faqs −257ms; other tables inside dev-boot floor; see load-test-results.md F1-ENV-02 section companion notes).
9. **F1-ENV-02 (environment, not app code)**: dev-server collapses (g4-02/g4-03) root-caused to per-request PHP compilation with no OPcache + single-worker built-in server on Windows; server restarted with process-scoped OPcache flags (same `server.php`, no php.ini/project changes); clean g4-04 then reached 98.6% of offered rate with zero timeouts. g4-01 (bench-collision contamination), g4-02/g4-03 (decay) kept as honest artifacts with their numbers.
10. **Independent review (post-implementation)**: verdict **PASS, zero MUST-FIX/SHOULD-FIX** in Group-4 scope (channel filter placement before LIMIT verified; `$weekEnd` binding intact; select lists untouched; closure-in-`remember()` never serialized against installed vendor `Repository`/`FileStore` source; null-settings contract behaviorally preserved — cached null is re-queried per request by design; no channel state baked into any cached G4 value; all 7 controllers envelope-identical). NICE-TO-HAVE notes: per-null-request cache-file write noise while settings row absent (transitional), FAQ/City now serve up-to-4h cached data (documented campaign intent). Reviewer's out-of-scope flags (existing uncommitted external edits, NOT campaign files): `packages/marvel/src/Rest/Routes.php` password-reset group deletion + commented barcode scan route, `DatabaseSeeder.php` uncommented seeders — flagged to the user for decision.

Cumulative evidence: **95/95** deterministic contract checks (37 G1 + 7 G2 + 16 G3 + 27 G4 + 6 G4b uncovered-surface checks + limiter-phase-tolerant burst), **21 stage artifacts** (R-01…R-17 + g4-01…g4-04; g4-01 kept as contaminated-run honesty record, g4-02/g4-03 as decay records — root-caused in F1-ENV-02), semantic DB cross-checks, cold-cache measurement. G4 stage g4-04 is the clean record: 1996 requests at 98.6% of offered rate, zero client timeouts, envelope integrity 100%. G4 specifics: channel-split verified against DB join-counts (exact fixtures), geography/index counts exact vs DB, admin-site-review moderation not exposed, `POST currencies/select` guest-safe (422-first, ack-only for guests), sanctum write boundaries verified (site-reviews, fast-shipping checkout). **F1-ENV-02**: dev-server per-request PHP compilation (~1s/request, no OPcache) caused late-stage collapses; server switched to the same `server.php` entrypoint with process-scoped OPcache flags (~11× per-request cost reduction, 5-concurrent round 5.87s → 0.385s) — dev-only relevance, no project file changed. Documented limits: single-IP dev environment (limiter caps proven rates by design — 322 non-429 ≈ 60/min × 5min). Authenticated site-review write/own behavior is code-inspected but NOT exercised (read-only campaign policy). Production capacity requires production-like environment + distributed IPs — out of scope.
