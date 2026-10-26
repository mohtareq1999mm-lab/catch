# Group 1 — Security & Correctness Findings (cumulative)

Labels: VERIFIED=code/route inspected; TESTED=executed with evidence; INFERRED; UNKNOWN; RISK.
Environment context: local dev (`APP_ENV=local`, APP_DEBUG=true), MySQL `catch` (Docker), app on PHP built-in server (`php artisan serve`), file cache.

## Severity model
P0 critical / P1 high / P2 material / P3 minor.

---

## A. Findings

### F1-SEC-01 — `code` never exposed on the guest `/coupons` listing  (TESTED, invariant HOLDS — no vulnerability)
- Initially flagged as P0 candidate after union-of-keys analysis showed a `code` key in guest listing items.
- Detailed audit (tests/performance/coupons-audit.mjs): all 20 visible items = `vis=public claim=false elig=true code=false`, `codeLen=0` → `code` key exists but value is `null` per `CustomerCouponResource` policy (`can_expose_code ? $coupon->code : null`; resource comment: "Homepage and other anonymous surfaces keep using CouponResource (never any code) — this serves customer discovery only").
- Additional check: bad bearer → 401 (no degradation to guest shape; message does not echo token). Codes never returned to guests.
- Verdict: PASS. `code: null` key in the payload is cosmetic noise (P3 improvement: omit key rather than null).

### F1-FUNC-01 — FIXED: invalid `order` on `/tags` produced 500 (was P2 RESPONSE_CONTRACT/FUNCTIONAL_BUG)
- Evidence (TESTED): `/tags?order=abc%27--` → `500 {"message":"Order direction must be \"asc\" or \"desc\".","status":false}` — the framework-level `InvalidArgumentException` (vendor/laravel/framework/.../Database/Query/Builder.php:2319) surfaced via the catch-all handler; request body validation absent in `TagController::index`.
- Fix (VERIFIED live): `$request->validate(['order' => 'sometimes|string|in:asc,desc'])` → `422 {"message":"The given data was invalid.","status":false,"errors":{"order":["The selected order is invalid."]}}` — exactly the documented Handler `ValidationException` contract (app/Exceptions/Handler.php ValidationException branch).
- Files changed: `app/Http/Controllers/Api/General/TagController.php` (validation + comment).
- Regression harness: `tests/performance/functional.mjs` (V-tags-orderbad / V-tags-orderdesc). PHPUnit feature test intentionally NOT added/ran: repo's phpunit.xml maps tests to the live `catch` DB with many `RefreshDatabase` suites (documented data-loss incident); running PHPUnit this phase is forbidden (see F1-ENV-02). Regression coverage lives in the executable HTTP harness until a quarantined test DB exists.

### F1-CONTRACT-01 — Error-envelope inconsistency → **FIXED (superset, 2026-10-24)** (was P2)

- VERIFIED two families of error JSON under `/api/v1/general/*`:
  - Trait path (`Marvel\Traits\ApiResponse::apiResponse`): `{status:<int>, message, success}` — brands/banners/sliders/tags/promotions 404s.
  - Handler path (`app/Exceptions/Handler::handleJsonResponse`): historically `{message, status:false[, errors]}` with NO `success` key — categories 404, coupons/mine 401, 402s/409/422/429/500.
- **Fix shipped (user-approved)**: `app/Exceptions/Handler.php` error builder now always emits `success:false` → Handler errors are `{message, status:false, success:false[, errors]}`. Every error response across the whole API now carries the ONE reliable `success` signal.
- **Design decision — superset, not key removal:** the legacy boolean `status:false` is KEPT because (a) encoded repo test contracts assert it (`tests/Feature/Wms/WmsApiFoundationTest.php:86,96`, `WmsFulfillmentTest.php:375,417` → `assertJson(['status' => false])`), and (b) unknown external consumers may read it. Removing it would be a breaking change to *differently-shaped* payloads that cannot be verified here (phpunit prohibited on live DB).
- **Verified live post-fix**: handlers' 404 → `{"message":"Resource Not Found","status":false,"success":false}`; 422 → `{..."success":false,"errors":{"order":[...]}}`; 401 → `{..."status":false,"success":false}`; 429 default → `{"message":"Too Many Attempts.","status":false,"success":false}`. Suite 37/37 PASS. Message texts and HTTP codes unchanged.
- **Left as future approved task**: retire `status:false` from Handler errors (with the 4 WMS assertions updated + quarantined test DB to run them).

### F1-THROTTLE-01 — Rate limiting VERIFIED, by-design ceiling blocks 400 req/min/IP (TESTED)
- Stack: `api` group = `throttle:api` 60/min/user-or-IP (RouteServiceProvider.php:60) and route-level `throttle:public-api` 120/min/IP (AppServiceProvider.php:109). Measured: headers `x-ratelimit-limit: 60, x-ratelimit-remaining: …`; limiter burst 70 demanda → mixture of 200s and 429s exactly at window boundary (15×200 + 55×429 when window partly consumed).
- 429 JSON shape VERIFIED: `{success:false,message:"<localized>","data":null}` (no `status` key — different from F1 family!) with 429 status code (TESTED).
- The campaign's 400 req/min target from a single IP therefore reaches the limiter at ≥60/min; sustained target stage produces ~50–90% 429s from Test IP — measured as designed behavior (see load results). NOT a defect. (晋级級 P3: undocumented in repo docs? — lives only in code; recommendation: add to project API docs. Not created — docs mode OFF per AGENTS.md.)
- 429 responses are counted separately from envelope failures (they conform to their own verified contract).

### F1-REL-01 — `HasCache` stale content → **root cause FIXED (2026-10-24); offline-SQL caveat remains** (was P2)

- Original evidence (TESTED): after offline DB mutations, lists served stale content up to the 4h TTL because `flushTag()` swallowed `BadMethodCallException` and returned `true` WITHOUT invalidating anything on tag-less stores (file/database/array) — i.e., even **normal Eloquent writes through observers/controllers did NOT clear cached lists locally**. (Earlier campaign evidence: 10 brand rows served while DB held 0; semantic API/DB mismatch until `cache:clear`.)
- **Fix shipped (user-approved)** — `app/Traits/HasCache.php` now keeps a per-tag key registry (`cache-tags:<tag>`) written in the tag-less fallback path of `remember()`; `flushTag()` drops every tracked key instead of no-op. Redis/memcached paths untouched (`Cache::tags` used as before).
- **Selftest PASS** on the app's real `file` store (`tests/performance/hascache-selftest.php`): remember → flushTag → key gone; re-remember → re-flush → gone (self-healing registry). Applies app-wide: all 19 app controllers, 21 Marvel controllers, 10 observers use this single trait (canonical owner verified).
- **Remaining caveat (cannot be fixed from application code)**: writes bypassing the app (SQL, seeders, restores) still do not invalidate caches; bounded by TTL (4h trait / 120s HomeService keys). Mitigation: run `php artisan cache:clear` in DB-restore/seeding runbooks. (Not added to `DatabaseSeeder.php` — that file has external uncommitted modifications.)

### F1-ENV-01 — MySQL availability churn (TESTED, ENVIRONMENT_FAILURE, unresolved ops issue)
- MySQL 8.4.3 `catch` served at 127.0.0.1:3306 was running 2026-10-14 (per `error/SEED-RUN-2026-10-14.md`) but was DOWN at campaign start (SQLSTATE 2002 "actively refused"), causing the first observed **409 DB-down contract** on all catalog GETs: `{"message":"Database error occurred. Please check your request and try again.","status":false}` (QUERY-EXCEPTION handler mapping, VERIFIED code).
- During the session the DB repeatedly changed snapshot/migration state (0→95→128 tables; 2→152 users) — an external process is populating it (per user confirmation, DB data was restored mid-campaign).
- Tri-state staleness of cache documented under F1-REL-01 during the churn (DB sets with hot cached values).
- Recommendation (P3): pin a Docker `restart: unless-stopped` and healthcheck; align `php artisan cache:clear` into DB-restore workflow.

### F1-ENV-02 — PHPUnit cannot be used safely in this environment (VERIFIED constraint)
- phpunit.xml pins `DB_DATABASE=catch` (live DB). `RefreshDatabase` appears in many Feature suites → unconditional `php artisan test` risk = data wipe (documented incident `error/DATA-LOSS-INCIDENT.md`). Campaign uses HTTP-level verification harnesses (`tests/performance/*`) and read-only tinker probes; ZERO phpunit runs performed.

### F1-SEC-02 — No stack traces / secrets in error bodies under debug-mode rendering (TESTED)
- All observed 4xx/5xx/409/429 bodies returned JSON messages only. `V-tags-orderbad` (before fix) returned the framework message (because APP_DEBUG=true, per Handler's intentional debug behavior) but NO SQL/stack fragment. Load-stage assertion tested for stack fragments across 400+ responses — zero leaks.
- Risk noted: `APP_DEBUG=true` on local is configuration reality; in production the Handler falls back to localized generic (VERIFIED code path). Guard de-staging: no action needed.

### F1-SEC-03 — Authorization boundaries on protected sub-endpoints (TESTED, holding)
- `/api/v1/general/coupons/mine` unauthenticated → 401 (translated message) — middleware contract, no data leak.
- `/api/v1/general/coupons/apply` (POST) guest → 401 invariant holds.
- Guest coupon listing does not leak codes (see F1-SEC-01).
- No admin auth on the catalog read paths (by design, public endpoints).

### F1-SEC-04 — Mass-assignment / parameter injection probes on GET list paths (TESTED, no failure)
- Input fuzz probes (oversized slug 250 chars, Unicode slug, `order=abc'--`, `limit=-3/0/999`, `tagsIds=1,2,99999999`, `slugs=a,b,c`): all safely bounded → 200/404/422; no SQL-visible side-effects; no 5xx post-fix.

### F1-PERF-01 — Dev server fidelity caveat (VERIFIED environment constraint)
- PHP built-in server (artisan serve) is single-threaded → p95s observed here are NOT comparable to production (Render deployment uses Docker image + supervisor + opcache). All numbers documented as LOCAL DEV only. Production-accuracy gate documented as UNVERIFIED in final audit.

### G3-F-01 — Flash-sale listing on HOME advertised fast-shipping products that the detail route 404s → **FIXED (2026-10-24, P2)**
- Root cause (code-verified, `app/Services/General/ProductService.php:321-345`): `getFlashSalesAndHereProductsByQtySet` eagerly loaded its products without the channel-home filter that all 11 sibling strategy/config paths apply (fallback :94, end-week :378, discounts :447, brands :475, arrivals :507, detail :256 — `app/Traits/HasChannelFilter.php`).
- Impact: clients navigating the HOME channel could receive `flash_sales_product` items (e.g. id 1, `is_fast_shipping_available=1`) whose `GET /products/{slug}` returned 404 under the same channel — dead links / inconsistent catalog.
- Live POC pre-fix: `type=flash_sales_product` listing contained id=1 (fast=1) while `/products/1` → 404 (52 bytes, F1 shape). DB reference: 56 flash-sale products in valid sales — 30 fast=1 / 26 fast=0.
- Fix: `$query->active()->tap(fn ($qq) => $this->applyChannelHomeFilter($qq))` inside the eager products closure (established sibling pattern `getBrandsProductsByQtySet:475`). Channels remain separately-coherent: under `X-Channel: fast-shipping`, both listing and detail serve fast products (id=1 → 200; guard `P-detail-fast-200`).
- Verified: post-fix home flash listing = [29,11,23,16,36] (all fast=0, ⊆ 26-item home-visible set); regression guard `P-flash-home-consistency` (flash listing ⊆ home-visible set) in `functional.mjs`.

### G3-F-02 — Product listing cache key was channel-blind → **FIXED (2026-10-24, P1)**
- Root cause (code-verified, `app/Http/Controllers/Api/General/ProductController.php` `currencyAwareCacheKey`): key = `md5(fullUrl + '|currency:<code>')` only; the `X-Channel` header resolved by `ChannelMiddleware` → `ChannelContext` was NOT part of the key — while the listing CONTENT is channel-dependent (`applyChannelHomeFilter`).
- Impact: the first request to build a given URL+currency cached whatever channel happened to be resolved first; subsequent requests from the OTHER channel on the same URL got the poisoned entry — cross-channel contamination with a straightforward reproduction. HIGH correctness impact for the /products surface (cache TTL 4h).
- Live POC pre-fix: `cache:clear` → request with `X-Channel: fast-shipping` (fresh MISS build → ids [1,3,8,9,10,12], fast-shipping products) → immediate no-header (HOME) request for the SAME URL served the same [1,3,8,9,10,12] from the channel-blind entry.
- Fix: append the resolved channel value: `md5($request->fullUrl() . '|currency:' . $code . '|channel:' . app(ChannelContext::class)->getChannel()->value)`.
- Verified: same URL — FAST firstIds=[1,3,8,9,10,12] vs HOME firstIds=[2,4,5,6,7,11] (separate entries; guard `P-channel-split-key` asserts they differ).
- Scope note: same key-blind classes could exist in other cached controllers IF their content were channel-dependent; content/static pages and the G1 catalog controllers were inspected for channel-conditional logic (no `applyChannelHomeFilter` usage outside product flows) → no further affected surface found in the general prefix.

### G3-SEC-01 — Product filter layer injection/avoidance review (TESTED, no failure)
- `ProductFilter::apply` is whitelist-architected: only enumerated keys produce conditions; unknown query params are silently ignored (live-proven via `derp=1` → identical default response); regex/dimension columns come from a hardcoded allowlist; every user value is a bound parameter; `whereRaw('1 = 0')` is a constant; `rating` cast to float before `havingRaw` binding; `FIELD()` ordering only over integer-filtered Scout ids.
- Boundary writes (POST/PUT review routes) correctly reject guests with the unified 401 envelope; ownership check lives in `ProductService::updateProductReview` (code-verified), runtime ownership behavior NOT exercised (no tokens in this read-only campaign).

### G4-F-01 — Flash-sale ending-week/today queries were channel-blind → **FIXED (2026-10-24, P2, live-proven)**
- Root cause (code-verified, `app/Services/General/FlashSaleService.php` — `getFlashSaleProductsEndingThisWeek` old :94-117 and `getFlashSaleProductsEndingToday` old :125-148): product queries built with `Product::query()->active()` + `whereExists(flash_sale junction)` but WITHOUT `applyChannelHomeFilter` — while the ProductService twins of the very same strategies apply it (ProductService.php:382/:422) and the qty-set sibling is channel-tapped (FlashSaleService:75). `FlashSaleService` already `use HasChannelFilter`. Third instance of the same defect class discovered by sibling audit (first two: G3-F-01/QtySet, twins at 382/422 already filtering).
- Impact: HOME responses to `flash-sale-products-ending-this-week/today` included 6 fast-shipping products (ids 1,9,25,57,69,165) that the endpoint family's own channel semantics hide — same list/detail disagreement class as G3-F-01 (dead links on the HOME channel); FAST-SHIPPING responses served only the home-visible 8 (fast products missing).
- Fix: split to `$query = …` + `$this->applyChannelHomeFilter($query);` before `orderBy/limit` — exact mirror of the ProductService twins.
- Verified post-fix (cache cleared, live): `X-Channel: fast-shipping` → exactly [165,69,57,25,9,1] (6) vs `X-Channel: home` → exactly [169,149,109,105,77,53,41,29] (8) — disjoint, zero leaks; DB join-count reference fast=6/home=8 matches EXACTLY; default (no header) = home by ChannelMiddleware. Regression guards: `F4-week-home-nofast` (home list has no `is_fast_shipping_available=true` item), `F4-week-fast-disjoint`, `F4-week-default-eq-home`.

### G4-F-03 — Seven controllers ran their DB queries BEFORE `HasCache::remember` (eager-before-remember) → **FIXED (2026-10-24, P3, structural)**
- Root cause (code-verified): `SettingController`, `FAQController`, `GovernorateController`, `CountryController`, `CityController`, `SiteReviewController`, `CurrencyController` computed the value first (`Settings::first()`, `getfaqs()`, `allActive()`, `all()`, `getApprovedReviews()`, `Currency::…->get()` + resource build) and then handed the ALREADY-BUILT value to `remember()`; `HasCache::remember` wraps non-closures as `fn() => $data` (HasCache.php:30) — so the query executed on EVERY request including cache HITS. The cache only avoided the (already-burned) work's serialization; the `Cache::avoid work by NOT doing it` intent was defeated. Same class as the G2-F-01 ContentPageController defect (fixed there); G4 controllers were the remaining siblings.
- Fix: all seven converted to the campaign-canonical lazy closure (`fn () => …` query + build inside `remember()`). Response shapes byte-identical; SettingController's documented null-guard preserved (a null settings row is re-queried per request and `SettingResource::make(null)` keeps contract).
- Verified: php -l ×8 clean; live miss/hit sweep after the clean g4-04 stage (`bench-g4-cache.mjs`, limiter-safe protocol: 1 fresh request each right after `cache:clear` = miss, then 10 warm requests each at 1.05s pacing = hit; ALL responses 200 + valid envelope, envBad=0): miss 170–445ms vs hit p50 143–228ms — faqs (largest dataset, 15 rows) miss 445 vs hit 188 (−257ms); settings/site-reviews/currencies/governorates deltas 0–40ms inside the ~150–250ms dev-boot instrument floor (1-row/6-row tables). Structural claim proven by closure semantics; measurable saving scales with dataset size and widens under DB pressure. (First bench attempt was invalidated by 120ms pacing filling the 60/min limiter mid-phase — re-run per protocol above; addresses F1-ENV-02 server decline + designed limiter behavior.)

### G4-SEC-01 — `POST /general/site-reviews` auth boundary (RESOLVED as already-secured; earlier session-note corrected)
- Earlier mid-session notes suspected the route was public (`throttle:public-api`, no auth) with `SiteReviewService::createReview(User $customer, …)` non-nullable → predicted guest 500. VERIFIED FALSE (evidence): route:list `-v` shows the POST sits in the AUTH group (routes/api.php:142–210 = `['api','auth:sanctum','throttle:authenticated']`) — the earlier truncated read attributed lines 196–203 to the PUBLIC group; live probes settle it: guest POST with empty payload → **401 unified envelope**; guest POST with VALID payload → **401** (auth runs pre-validation, before FormRequest) — no 500, no write, `createReview` never reached for guests.
- Boundary contract (TESTED, guard `F4-sr-post-401`): unauthenticated POST rejected with `{message:"غير مصرح. يرجى تسجيل الدخول.","status":false,"success":false}` ×401; moderation pipeline (`status=pending`, approve/reject transitions) code-verified in SiteReviewService; public index exposes approved only (DB: approved=6 = response count; pending=2 never listed; reviewer `status` column not exposed in the resource).
- Runtime authenticated-write behavior remains NOT exercised (read-only campaign policy, no tokens).

### G4-SEC-02 — `POST /general/currencies/select` guest behavior (VERIFIED safe, no fix needed)
- Route is PUBLIC + `throttle:public-api` by design (route:list). Validation runs first: missing code → 422 (`errors.currency_code`), unknown code → 422 via `Rule::exists('currencies','code')->where('is_active',true)` (SelectCurrencyRequest). Guest: `$user` resolves null → persistence explicitly skipped (`if ($user)` guard in controller) → returns 200 "acknowledged" (`Currency updated successfully`) WITHOUT writing any preference (guests transport currency via the `X-Currency` header; `UserCurrencyPreferenceService` is user-table-only). No data capture, no enumeration beyond the public currencies list, no session state mutated (`forgetEffectiveCode` only resets in-memory memoization on a per-request service instance).
- Authenticated path (code-verified only): `setUserPreference` updateOrCreate on user_id — NOT exercised (no tokens; write-only path).

### G4-NOT-DEFECT — City controller `all()` vs siblings' `allActive()` (note only)
- `cities` table has NO `status` column (schema probe: `id,governorate_id,name,created_at,updated_at`) — `Governorate`/`Country` status filters have no city counterpart; `CityController::index` `all()` is schema-consistent, and no city is attached to an inactive parent (probe: 0 rows). No fix applied (would be dead code).

### G4-REL-01 — `PickupLocationController::show` catches ALL throwables → uniform 404 (accepted note)
- `try { active()->findOrFail($id) } catch (\Throwable)` → 404 envelope. A transient DB error would surface as 404 instead of the platform's 409 DB-down contract on this one endpoint. Accepted as-is (pre-existing, read-only, low harm); flagged for future cleanup; NOT changed to avoid scope creep.

### G4 channel-key note — flash-sales index cache key has no `|channel:` suffix (VERIFIED correct)
- `paginateFlashSales` content is NOT channel-dependent: the sale list contains sale metadata only (no product hydration in the cached closure; `FlashSaleResource` maps sale fields + `whenLoaded('products')` which the index query never loads). The campaign rule "channel-dependent content ⇒ channel in key" therefore does not apply. Detail/qty-set/week/today routes are UNCACHED (no key at all → no contamination possible). Guard `F4-week-default-eq-home` pins the default-channel semantics for the uncached family.

---

## B. Verified response-contract matrix (live, TESTED)

| Endpoint | 200 envelope | 404 | Invalid params | Empty DB | Auth state |
|----------|--------------|-----|--------------------------|----------|------------|
| nav-data | F1 data=array | – | non-positive/abc level → 200 default depth | 200 data=[] | public |
| categories | F1 paginated/array | service 404 via Handler F2 `{message,status:false}` | `?slug=missing`→404 | 200 `[]` | public |
| categories/{slug} | – | 404 F2 (see F1-CONTRACT-01) | unicode/250-char | – | public |
| brands | F1 array (ids desc) | 404 F1 trait | `?slug=`→by-slug 404 | ok | public |
| brands/{slug} | F1 resource | 404 F1 | – | – | public |
| brands-products | F1 array | – | ok | ok | public |
| banners | F1 array (8 active) | 404 F1 | `with_products` bool ok | ok | public |
| banners/{slug} | F1 resource | 404 F1 | – | – | public |
| sliders | F1 array (10 active) | 404 F1 | – | ok | public |
| sliders/{slug} | F1 resource | 404 F1 | – | – | public |
| tags | F1 paginated (data array, limit clamped 1..100) | 404 F1 | order invalid→**422 (fixed)**, limit clamps | ok | public |
| tags/{slug} | F1 resource | 404 F1 | – | – | public |
| promotions | F1 array/paginator | 404 F1 | slug delegation, with_product | ok (0 rows) | public |
| promotions/{slug} | F1 resource | 404 F1 | with_product takes 1 | – | public |
| coupons | F1 array (limit=10 default; keys incl. `code:null`) | – | limits 1..100 | ok | public (401 when unresolvable bearer!) |
| coupons/mine | – | – | – | – | 401 (Handler F2 Arabic locale) |

*(F1 = `{status:<int>,message,success[,data]}` trait family; F2 = Handler catch-all `{message,status:false,success:false[,errors]}` — post F1-CONTRACT-01 fix every error carries `success:false`)