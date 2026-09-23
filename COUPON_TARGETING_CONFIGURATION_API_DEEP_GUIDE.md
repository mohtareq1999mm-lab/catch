# COUPON TARGETING & CONFIGURATION APIs — DEEP CODE-TO-CONTRACT GUIDE

> Source of truth: **implementation**, not planning docs.
> Scope: 7 admin endpoints. Customer Claim/Apply/Checkout are covered only as downstream consumers.
> Code changed: **NO**. Database changed: **NO**. Read-only audit.

---

# 1. Executive Summary

Seven admin-only endpoints configure and inspect coupon eligibility:

| # | Method + URL (canonical) | Legacy alias | Controller | State change |
|---|---|---|---|---|
| 1 | `GET /api/v1/coupons/{id}/targeting` | `GET /api/v1/admin/coupons/{id}/targeting` | `CouponTargetingController::show` | none (read) |
| 2 | `PUT /api/v1/coupons/{id}/targeting` | `PUT /api/v1/admin/coupons/{id}/targeting` | `CouponTargetingController::upsert` | **upsert** `coupon_targetings` row + fires `CouponTargetingChanged` |
| 3 | `DELETE /api/v1/coupons/{id}/targeting` | `DELETE /api/v1/admin/coupons/{id}/targeting` | `CouponTargetingController::destroy` | **delete** row + fires `CouponTargetingChanged` |
| 4 | `GET /api/v1/coupons/rules` | `GET /api/v1/admin/coupons/rules` | `CouponRulesController::show` | none (static) |
| 5 | `POST /api/v1/coupons/validate-configuration` | `POST /api/v1/admin/coupons/validate-configuration` | `CouponConfigurationController::validateConfiguration` | none (stateless check) |
| 6 | `GET /api/v1/coupons/{id}/usage-info` | `GET /api/v1/admin/coupons/{id}/usage-info` | `CouponConfigurationController::getUsageInfo` | none (read + compute) |
| 7 | `POST /api/v1/coupons/{id}/suggest-fix` | `POST /api/v1/admin/coupons/{id}/suggest-fix` | `CouponConfigurationController::suggestFix` | none (read-only diagnostic) |

Key facts a frontend developer must internalize:

- **All 7 are admin-only** (Sanctum + coupon permissions). There is no customer variant of any of them by design.
- **Targeting is one row per coupon** (`coupon_targetings`, `UNIQUE(coupon_id)`). PUT is `updateOrCreate` (upsert/replace, never merge). DELETE returns the coupon to **always-eligible** (widens access immediately).
- **Actual PUT body fields** are `mode, require_claim, max_claims, claim_ttl_hours, rule_tree` — NOT `mode + rules` at top level. `rule_tree` is the nested tree (`{operator, rules[]}` or leaf or `null`).
- **`assignment_and_dynamic` means BOTH must pass**: usable assignment **AND** dynamic tree. Verified in `EligibilityEngine::evaluateCombinedMode` (AND gate, fail-closed on either branch error).
- **`area_in` reads the authenticated user's own saved addresses only** (`address.customer_id = user AND governorate_id IN (active allowed)`). Checkout `governorate_id` / shipping destination is **intentionally irrelevant** and never read.
- **No endpoint here consumes quota.** PUT/DELETE/validate/usage/suggest never touch `used`, assignments, claims, reservations, or usages. Consumption happens only in Claim → Apply → Checkout → Reservation → Payment-success → Usage.
- **PUT and DELETE fire `CouponTargetingChanged`** (after-commit) → queued `StartCouponDistribution` → full-audience distribution run via transactional outbox + RabbitMQ. The other 5 endpoints emit nothing.
- **Envelope is `{status, message, success, data?}`**, `data` omitted when empty. `message` is translated (`lang/en|ar/message.php`).

---

# 2. System Context

## 2.1 Route registration (VERIFIED)

Canonical routes — `packages/marvel/src/Rest/Routes.php`:

- Inside `Route::middleware(['auth:sanctum', 'throttle:admin', 'lang'])->group(...)` (line 118).
- Static catalog route `GET coupons/rules` is declared **before** `apiResource('coupons')` so it is not captured by `coupons/{coupon}` (comment lines 280–281). **VERIFIED**.
- Config helpers in a nested group (lines 285–292):
  `Route::prefix('coupons')->middleware(['api','auth:sanctum','throttle:admin'])->group(...)` with `POST validate-configuration`, `GET {id}/usage-info`, `POST {id}/suggest-fix`, `GET|PUT|DELETE {id}/targeting`, all `->whereNumber('id')`.
- Named `api.admin.coupons.rules`, `api.admin.coupons.validate-config`, `api.admin.coupons.usage-info`, `api.admin.coupons.suggest-fix`, `api.admin.coupons.targeting.{show,upsert,destroy}`.

Legacy aliases — `routes/api.php` lines 200–206:

- `Route::prefix('v1/admin/coupons')->middleware(['api','auth:sanctum','throttle:admin'])->group(...)` carrying **no route names** (comment: avoids collision). Same controllers, identical behavior. `whereNumber('id')` present.
- Note: legacy group lacks the `lang` middleware entry that the canonical group has; message translation still resolves via `ApiResponse::translateNotice` + `Accept-Language` handling elsewhere. Behavior difference: **NOT VERIFIED** beyond middleware list.

Resulting full paths (assuming `/api` prefix from `RouteServiceProvider` + `v1` segment as mounted): canonical `/api/v1/coupons/...`, legacy `/api/v1/admin/coupons/...`. Both serve; tests assert identical responses for rules (`test_legacy_alias_serves_identical_catalog`).

## 2.2 `{id}` parameter (VERIFIED)

- `whereNumber('id')` on every `{id}` route → **integer coupon primary key only**. UUID / code / slug → no route match → Laravel 404 (route not found), never controller logic.
- Controller signature `show(Request $request, int $id)`, lookup `Coupon::findOrFail($id)` → missing row throws `ModelNotFoundException` → framework renders 404 JSON. No custom 404 body from these controllers.

## 2.3 Middleware / guards (VERIFIED)

| Layer | Value |
|---|---|
| Auth guard | `auth:sanctum` (Bearer token). Constructor `$this->middleware(['auth:sanctum'])` in all three controllers, plus route-level `auth:sanctum`. |
| Throttle | `throttle:admin` on the route groups. Exact limits live in throttle config; **NOT VERIFIED** numerically here. `429` possible in principle; no endpoint-specific handling. |
| `api` | JSON middleware group. |
| `lang` | canonical group only (see 2.1). |
| Customer guard | none of these endpoints accept the customer flow; same Sanctum guard, permission-gated. |

## 2.4 Permission model (VERIFIED — in-controller, not route middleware)

No `permission:...` middleware on these 7 routes. Authorization is imperative inside each controller:

- **Read** (`show` targeting, `show` rules, `validateConfiguration`, `getUsageInfo`, `suggestFix`): passes if user has **any** of `view-coupons`, `update-coupon`, `create-coupon` (via `hasPermissionTo` OR `can`), else if legacy `type=admin`/`role=admin` without Spatie installed → allowed; if Spatie present but permission missing → `403 Forbidden. Missing required permission: view-coupons.` Unauthenticated → `401 Unauthenticated.`
- **Write** (`upsert`, `destroy`): passes only with `update-coupon` OR `create-coupon`. `view-coupons` alone is **insufficient** (explicit BLOCKER-fix comment in code). Legacy `type=admin` without explicit permission is **NOT sufficient for writes**. Failure → `403 Forbidden. Missing required permission: update-coupon.` Unauthenticated → `401`.

`UpsertTargetingRequest::authorize()` returns `$this->user() !== null` (second gate; unauthenticated PUT without token fails before validation with 401/redirect depending on `Accept` header — with `Accept: application/json` it is 401).

## 2.5 Common envelope (VERIFIED — `Marvel\Traits\ApiResponse::apiResponse`)

```json
{ "status": 200, "message": "...", "success": true, "data": { } }
```

- `status` mirrors the HTTP status code. `message` is passed through `translateNotice` (keys like `MESSAGE.FETCH_DATA_SUCCESSFULLY`, `ERROR.COUPON_NO_TARGETING` resolved via `lang/{en,ar}/message.php`). `success` boolean. `data` key **omitted entirely when empty** (`if (!empty($data))`).
- DELETE success returns no `data` key. Validation-error responses embed `data.errors`.
- This is NOT the `{success, message, data, meta}` shape quoted in generic project guidance; for these endpoints the implementation shape above is authoritative.

---

# 3. Targeting Concepts

- **Coupon** (`coupons`): discount definition + capacity (`limiter`, `used`, `status`, dates). Capacity configured via `limiter`; `used` is system-managed (incremented only in `OrderService::recordCouponUsage`; admin cannot write it through normal input — repository whitelist, F-04).
- **Targeting** (`coupon_targetings`, 1:1 with coupon): *who may qualify + must they claim first*. Fields: `coupon_id, mode, require_claim, max_claims, claim_ttl_hours, rule_tree`.
- **Assignment** (`coupon_assignments`, UNIQUE(coupon,user)): a per-user grant with `max_uses, used, expires_at`. Usable = exists AND not expired AND `used < max_uses`.
- **Claim** (`coupon_claims`): user activation. Lifecycle `ACTIVE (unexpired) → REDEEMED (permanent) | EXPIRED (releases capacity)`. Requires a targeting row with `require_claim=true`.
- **Apply**: cart preview (`POST general/coupons/apply {code}`), no persistence of quota.
- **Checkout**: authoritative revalidation + order snapshot.
- **Reservation** (`coupon_reservations`, 30-min TTL, per-order idempotent): temporary capacity hold between checkout and payment callback.
- **Usage** (`coupon_usages` + `coupons.used+1` + `assignments.used+1`): permanent consumption on payment success.

---

# 4. Assignment vs Dynamic Eligibility

- **Assignment eligibility** = "does this user hold a *usable* grant?" Checked in three places with identical semantics (parity comments P2-1): `EligibilityEngine::evaluateAssignmentMode`, `evalHasAssignment` leaf rule, `CouponAssignmentValidator::validate`. All enforce exists + `expires_at` not past + `used < max_uses`. No assignment rows on the coupon at all → assignment validator returns `valid:true, has_assignments:false` (public behavior); but the **engine's assignment mode** with no row for *this user* returns ineligible (`No assignment found`).
- **Dynamic eligibility** = "does this user satisfy the rule tree?" Evaluated by `EligibilityEngine::evaluateDynamicMode` → `evaluateNode` recursion over `rule_tree` using `CustomerMetricsService` metrics + claim/assignment/address/email/registration reads. `null`/missing tree → eligible (`no_rules`). Any branch error (unknown operator/rule, malformed, empty group, depth>10) → **ineligible (fail-closed)**, never fail-open.
- **Neither consumes anything.** `evaluate()` is read-only (docblock: "read-only; callers hold Targeting FOR UPDATE where needed"). Quota changes happen in claim/reservation/usage services, not here.

---

# 5. All Targeting Modes

## 5.1 Verified matrix (from `EligibilityEngine::evaluate` + `evaluateCombinedMode` + `CouponOrchestrator::validate`)

| Mode | Assignment required? | Dynamic rules required? | Assignment must be usable? | Dynamic tree must pass? | Can unassigned user qualify? | Claim required? |
|---|---|---|---|---|---|---|
| `assignment` | YES | NO (tree ignored at runtime) | YES (exists, unexpired, `used<max_uses`) | N/A | NO | only if `require_claim=true` (orthogonal flag) |
| `dynamic` | NO | YES (but `null` tree = eligible) | N/A | YES (fail-closed on error) | YES (if tree passes) | only if `require_claim=true` |
| `assignment_and_dynamic` | YES | YES | YES | YES | NO | only if `require_claim=true` |
| `assignment_or_dynamic` | EITHER | EITHER | YES **if** taking assignment path | YES **if** taking dynamic path | YES (via dynamic path) | only if `require_claim=true` |

Notes:

- `require_claim` is **orthogonal** to mode (a boolean column on the same row). Claim gating is enforced in `CouponOrchestrator` *before* mode logic: if `require_claim` and no ACTIVE-unexpired claim (and not REDEEMED, which short-circuits to `already_used`), result is `claim_required` regardless of mode. Claim itself requires passing `evaluate()` first, so claim never bypasses targeting.
- Legacy coupons with **no targeting row** behave as always-eligible in the engine (`passedRules:['no_targeting']`) and as `assignment` fallback in the orchestrator (`$mode = $targeting?->mode ?? 'assignment'` with assignment validator returning valid when the coupon has zero assignment rows). Net: legacy public coupon stays usable. **VERIFIED** in both files.
- Unknown `mode` string cannot be persisted (request `in:` rule) but if present via DB drift, engine returns ineligible `unknown_mode`. **VERIFIED**.

## 5.2 `assignment_and_dynamic` — exact decision tree (VERIFIED)

Code (`evaluateCombinedMode`):

```php
$assignmentResult = evaluateAssignmentMode(...);   // usable-assignment gate
$dynamicResult    = evaluateDynamicMode(...);      // null tree = eligible; error = ineligible
if ($mode === 'assignment_and_dynamic') {
    if ($assignmentResult->isEligible && $dynamicResult->isEligible) return eligible(...);
    return ineligible([], array_merge(failed assignment, failed dynamic), ...);
}
```

Confirmed tree:

```text
User has assignment row?
  NO  → NOT ELIGIBLE (failedRules: has_assignment/No assignment found)
  YES → Assignment usable? (unexpired AND used < max_uses)
    NO  → NOT ELIGIBLE (Assignment expired | Assignment usage quota exhausted)
    YES → Dynamic tree passes? (null tree counts as PASS; any error counts as FAIL)
      NO  → NOT ELIGIBLE
      YES → ELIGIBLE (passedRules = assignment pass + dynamic passes)
```

Answers to the required questions:

- Expired assignment → NOT ELIGIBLE even if tree passes.
- `max_uses` reached → NOT ELIGIBLE even if tree passes.
- Missing assignment → NOT ELIGIBLE even if tree passes.
- Assignment usable + tree passes → ELIGIBLE.
- Assignment usable + tree fails → NOT ELIGIBLE.
- Assignment usable + `rule_tree=null` → ELIGIBLE (dynamic branch returns `no_rules` eligible). Admin note: AND-mode with null tree degenerates to assignment-only; allowed by design ("Dynamic-family modes with null rule_tree mean no rules (eligible)").
- Malformed tree at runtime (should be unreachable after PUT validation, but possible via DB drift) → dynamic branch ineligible → AND ineligible (fail-closed).
- Claim status does NOT enter `evaluate()` directly, except via `claimed`/`not_claimed` leaf rules if the admin put them in the tree. `require_claim` is enforced by the *orchestrator/claim service*, not the engine.
- Claim is required only if `require_claim=true`. Claim does not change `evaluate()` except through claim-state leaf rules on subsequent evaluations.
- Nothing is consumed by evaluation: no assignment `used` increment, no usage row, no reservation. Claim creation happens only in `CouponClaimService::claim`; usage only on payment success.

## 5.3 Other modes in plain language

- **`assignment`**: usable grant or nothing. Tree stored but ignored at runtime. Unassigned user never qualifies. Use for VIP lists.
- **`dynamic`**: tree or nothing. Assignments irrelevant (orchestrator skips the assignment gate entirely for pure dynamic). Use for segment rules (orders/spend/area/email/registration/claim-state).
- **`assignment_or_dynamic`** (VERIFIED nuance): eligible if *either* branch passes, BUT the assignment path counts only when the coupon actually grants assignments AND this user holds a valid one (`$assignmentOk = valid && has_assignments`). This prevents a coupon with zero assignment rows from auto-passing everyone through a vacuous assignment branch. Unassigned user with passing tree → eligible. Assigned-usable user with failing tree → eligible (via assignment path, then public-use history still checked). Both fail → ineligible (assignment reason preferred when the coupon grants assignments).

Truth tables (VERIFIED):

**AND** (`A`=assignment usable, `D`=dynamic passes incl. null-tree):

| Has row | Usable | D | Result |
|---|---|---|---|
| No | — | Pass | NOT ELIGIBLE |
| Yes | No | Pass | NOT ELIGIBLE |
| Yes | Yes | Fail | NOT ELIGIBLE |
| Yes | Yes | Pass | ELIGIBLE |

**OR**:

| Has row | Usable | D | Result |
|---|---|---|---|
| No | — | Pass | ELIGIBLE (dynamic path) |
| Yes | No | Pass | ELIGIBLE (dynamic path) |
| Yes | Yes | Fail | ELIGIBLE (assignment path) |
| Yes | Yes | Pass | ELIGIBLE |
| No | — | Fail | NOT ELIGIBLE |

---

# 6. Targeting Rule Tree

## 6.1 Grammar (VERIFIED — `RuleTreeValidator` + `CouponRuleMetadata::grammar`, parity-tested)

- `null` → valid, means "no rules". In dynamic-family modes → eligible (`no_rules`). Stored as SQL NULL (`rule_tree` nullable).
- **Leaf**: `{"type": "<one of 17>", "value": <per-type>}`. Must NOT contain `operator`/`rules` (else invalid: "Leaf rule must not contain operator/rules").
- **Group**: `{"operator": "AND"|"OR", "rules": [<node>, ...]}`. Uppercase only; `and`/`or`/`And` → invalid ("Unknown operator"). `rules` must be a non-empty array; `[]` or missing → invalid ("Rule group must contain a non-empty rules array (fail-closed)").
- **Nesting**: groups may contain leaves or groups recursively. `MAX_DEPTH = 10` (public const, single source; metadata reports it). Validator counts depth from root `0`, child `depth+1`; engine mirrors (`$depth > 10` → `max_depth_exceeded` ineligible). Depth 11+ → 422 on PUT; if drifted into DB → fail-closed ineligible at runtime.
- Anything else (non-array node, node with neither `type` nor `operator`/`rules`, non-array child) → invalid / `malformed_rule`.
- Duplicates allowed (`duplicate_rules_allowed: true` in metadata; no dedupe in validator or engine).
- `value` key may be omitted for value-less rules (`claimed`, `not_claimed`, `has_assignment` — value ignored; `$rule['value'] ?? null`).

## 6.2 Evaluation semantics (VERIFIED — `evaluateNode`)

- Leaf → `evaluateRule` → `{passed, type, value, actual?, reason?}`. Passed leaves accumulate in `passedRules`; failed in `failedRules`.
- Group AND → passes iff **all** children pass (`!in_array(false, $childOutcomes, true)`). Group OR → passes iff **any** child passes. Decision uses boolean child outcomes, not leaf counts (comment: prevents nested partial passes leaking through OR).
- First branch **error** (unknown operator, empty group, malformed, depth) short-circuits the whole tree to ineligible with `{error, reason}` — siblings are not evaluated.
- Example evaluation:

```json
{ "operator": "AND", "rules": [
  { "type": "min_completed_orders", "value": 3 },
  { "type": "has_email", "value": true }
]}
```

Engine: fetch metrics once → eval leaf1 (`completed_orders >= 3`) → eval leaf2 (strict email present) → AND both. Failures reported per-leaf with `actual` + human `reason`.

Nested:

```text
AND
 ├── min_completed_orders >= 3
 └── OR
      ├── has_email = true
      └── area_in = [1,2]
```

Passes iff orders≥3 AND (has email OR has saved address in governorates 1/2-active). OR failure fails the AND.

---

# 7. Supported Rule Types

Canonical list = `App\Enums\EligibilityRuleType` (17 cases). Metadata (`CouponRuleMetadata::all`) exposes exactly these 17; test asserts count + identifier parity + every exposed rule passes the validator. Fail-open/closed: **all fail-closed** (return `passed:false`, never grant on bad input).

| # | `type` | Meaning | Value type / allowed | Runtime source | Null/invalid behavior |
|---|---|---|---|---|---|
| 1 | `min_completed_orders` | `completed_orders >= value` | integer (numeric ≥0; `(int)` cast) | `customer_metrics.completed_orders` via `CustomerMetricsService` | non-numeric<0 → 422 on PUT (validator); runtime casts |
| 2 | `max_completed_orders` | `completed_orders <= value` | integer ≥0 | same | same |
| 3 | `min_total_spend` | qualifying spend ≥ value, **decimal-safe** (`bccomp` 2dp, cents fallback; never float-compare) | numeric ≥0 | `customer_metrics.total_qualifying_order_value` (SUM of `converted_total_price`, base currency) | non-numeric → 422 on PUT; runtime fail-closed (`Malformed ... fails closed`) |
| 4 | `max_total_spend` | spend ≤ value, decimal-safe | numeric ≥0 | same | same |
| 5 | `first_order_after` | first qualifying order strictly after UTC datetime | parseable datetime string (`strtotime`/Carbon UTC) | `customer_metrics.first_order_at` | no orders → fail; unparseable → PUT 422 / runtime fail |
| 6 | `first_order_before` | first qualifying order strictly before | datetime | same | same |
| 7 | `last_order_after` | most recent qualifying order strictly after | datetime | `customer_metrics.last_order_at` | same |
| 8 | `last_order_before` | most recent strictly before | datetime | same | same |
| 9 | `min_coupons_used` | COUNT(qualifying orders where a coupon was used) ≥ value (order count, not distinct codes) | integer ≥0 | `customer_metrics.coupons_used` | same as 1–2 |
| 10 | `max_coupons_used` | same count ≤ value | integer ≥0 | same | same |
| 11 | `claimed` | holds ACTIVE-unexpired claim OR REDEEMED claim for **this** coupon | none (value ignored) | `coupon_claims` (status + `expires_at>now`) | value ignored; expired-only → NOT claimed |
| 12 | `not_claimed` | holds NEITHER active-unexpired NOR redeemed | none (ignored) | same queries, inverted | same |
| 13 | `has_assignment` | holds **usable** assignment (exists, unexpired, `used<max_uses`) | none (ignored) | `coupon_assignments` | expired/exhausted → fail |
| 14 | `area_in` | ≥1 own saved address in allowed **active** governorates (ANY-match) | single id or non-empty array; **strict positive ints** (int>0 or all-digit string; floats/decimal strings/0/negatives rejected) | `address` (`customer_id=user`, `governorate_id`) ∩ `governorates` (`status=true`) | empty/malformed → 422 / runtime fail; NULL-governorate addresses never match; unknown/inactive ids can never create eligibility |
| 15 | `has_email` | strict email presence (trimmed + `FILTER_VALIDATE_EMAIL`; verification ignored) | `true`/`null` = require email; `false` = require NO email; anything else → 422 / runtime fail | `users.email` | non-bool non-null → reject |
| 16 | `registered_after` | `users.created_at` strictly after UTC cutoff | parseable datetime | `users.created_at` | null `created_at` → fail; unparseable → fail |
| 17 | `registered_before` | `users.created_at` strictly before | parseable datetime | same | same |

Boundary notes (VERIFIED): date comparisons are **exclusive** (`isAfter`/`isBefore`); equality fails. Money uses `bccomp(...,2)`. `min_total_spend` malformed threshold never treated as `0.00` floor at decision time (explicit fail-closed branch).

## 7.1 `area_in` precise semantics (VERIFIED — `evalAreaIn`, NOT the stale enum comment)

- Source: **authenticated user's own saved addresses**: `Address WHERE customer_id = user.id AND governorate_id IN (<active allowed>)`. One indexed `pluck`; ANY-match (non-empty = pass).
- Allowed set = admin ids ∩ `governorates WHERE id IN (...) AND status=true`. Unknown or inactive ids are silently dropped from the allowed set; if none remain → fail (`No active governorate in the allowed list`).
- `NULL` `governorate_id` rows never match (`whereIn` semantics; documented "no inference from free-form address JSON").
- **Checkout `governorate_id` / delivery / shipping destination is intentionally unrelated and never read.** Engine docblock: "`governorate_id` context key is accepted but ignored"; orchestrator: "any `governorate_id` key present is ignored"; metadata `evaluation.defers_without_context=false`, strict at claim/apply/checkout/fast-checkout. Deleted addresses simply absent (no rows → fail unless another address matches).
- Rolling-deploy guard: if `address.governorate_id` column missing (code ahead of migration) → fail-closed (`Address area data unavailable`).
- Stale comment risk: `EligibilityRuleType::AREA_IN` enum comment still says "checkout delivery governorate … orders.governorate_id". **This contradicts the implementation** and is documented in §26 as a documentation mismatch. Implementation (saved addresses) is authoritative.

---

# 8. Endpoint Map

Both URL families serve all 7. `{id}` = integer coupon PK (`whereNumber`). Auth = Sanctum bearer + `Accept: application/json`. Canonical group adds `lang`; legacy alias does not list it.

| Endpoint | Canonical | Legacy alias | Auth | Permission |
|---|---|---|---|---|
| GET targeting | `GET /api/v1/coupons/{id}/targeting` | `GET /api/v1/admin/coupons/{id}/targeting` | Sanctum | read: `view-coupons`/`update-coupon`/`create-coupon` |
| PUT targeting | `PUT /api/v1/coupons/{id}/targeting` | `PUT /api/v1/admin/coupons/{id}/targeting` | Sanctum | write: `update-coupon`/`create-coupon` |
| DELETE targeting | `DELETE /api/v1/coupons/{id}/targeting` | `DELETE /api/v1/admin/coupons/{id}/targeting` | Sanctum | write |
| GET rules | `GET /api/v1/coupons/rules` | `GET /api/v1/admin/coupons/rules` | Sanctum | read |
| POST validate-config | `POST /api/v1/coupons/validate-configuration` | `POST /api/v1/admin/coupons/validate-configuration` | Sanctum | read (`authorizeAdmin`) |
| GET usage-info | `GET /api/v1/coupons/{id}/usage-info` | `GET /api/v1/admin/coupons/{id}/usage-info` | Sanctum | read (`authorizeAdmin`) |
| POST suggest-fix | `POST /api/v1/coupons/{id}/suggest-fix` | `POST /api/v1/admin/coupons/{id}/suggest-fix` | Sanctum | read (`authorizeAdmin`) |

---

# 9. GET /coupons/{id}/targeting

## Purpose

Admin reads the coupon's targeting row to render the targeting builder (mode + claim policy + tree). Called by **admin frontend only**. No customer variant exists (customer endpoints deliberately hide targeting internals).

## Authentication

Sanctum; read permission (`view-coupons`|`update-coupon`|`create-coupon` or legacy admin type). 401 unauthenticated / 403 missing permission.

## Path / query params

- `{id}`: integer coupon id (`whereNumber`). Non-numeric → route miss (404, no controller).
- Query params: **none supported** (ignored if sent).

## Request

```http
GET /api/v1/coupons/123/targeting HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```

Body: NONE.

## Internal flow (VERIFIED)

`Route → auth:sanctum/throttle:admin(/lang) → CouponTargetingController::show → authorizeRead → Coupon::findOrFail(id) → $coupon->targeting (HasOne) → 404 if null → CouponTargetingResource → apiResponse`. Reads: `coupons` (1 row) + `coupon_targetings` (1 row). No writes, no events, no notifications, no outbox.

## Success response (exact shape from `CouponTargetingResource`)

HTTP 200:

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "coupon_id": 123,
    "mode": "assignment_and_dynamic",
    "require_claim": true,
    "max_claims": 100,
    "claim_ttl_hours": 24,
    "rule_tree": { "operator": "AND", "rules": [ { "type": "min_completed_orders", "value": 3 } ] },
    "created_at": "2026-09-22T10:00:00+00:00",
    "updated_at": "2026-09-22T10:00:00+00:00"
  }
}
```

Fields: `id` (targeting PK), `coupon_id`, `mode` (one of 4), `require_claim` (bool), `max_claims` (int|null), `claim_ttl_hours` (int|null), `rule_tree` (object|null — verbatim stored JSON), ISO-8601 timestamps. `rule_tree` is admin-only; never sent to customers.

## Errors

| HTTP | JSON | Meaning / frontend action |
|---|---|---|
| 401 | `{"message":"Unauthenticated."}` (framework) or controller abort | re-login; attach Bearer token |
| 403 | `403 Forbidden. Missing required permission: view-coupons.` | request `view-coupons` grant; do not retry as-is |
| 404 (no targeting) | `{"status":404,"message":"This coupon has no targeting configuration.","success":false}` (no `data`) | treat as "untargeted" → show empty builder / defaults; coupon is always-eligible at runtime |
| 404 (no coupon) | framework `ModelNotFound` JSON | coupon id wrong or deleted; refresh list |
| 429 | throttle | back off |

422/409/500: not produced by this handler (500 only on infra failure).

## Database

| Operation | Table | Columns | Why |
|---|---|---|---|
| READ | `coupons` | `id` (+ model) | locate coupon |
| READ | `coupon_targetings` | all | serialize row |

---

# 10. PUT /coupons/{id}/targeting

## Purpose

Admin creates-or-replaces the coupon's targeting configuration. Called by **admin frontend** after coupon creation, before assignments.

## Request

```http
PUT /api/v1/coupons/123/targeting HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{
  "mode": "assignment_and_dynamic",
  "require_claim": true,
  "max_claims": 100,
  "claim_ttl_hours": 24,
  "rule_tree": {
    "operator": "AND",
    "rules": [
      { "type": "min_completed_orders", "value": 3 },
      { "type": "area_in", "value": [1, 2] }
    ]
  }
}
```

## Body field matrix (VERIFIED — `UpsertTargetingRequest::rules` + `RuleTreeValidator`)

| Field | Required | Type | Allowed | Nullable | Default | Validation | Meaning |
|---|---|---|---|---|---|---|---|
| `mode` | YES | string | `assignment`, `dynamic`, `assignment_and_dynamic`, `assignment_or_dynamic` | no | none | `required\|string\|in:...` → 422 | which eligibility authority applies |
| `require_claim` | YES | boolean | `true`/`false` (1/0 accepted by Laravel boolean) | no | none | `required\|boolean` → 422 | whether user must claim (ACTIVE) before apply/checkout |
| `max_claims` | no | integer | 1–1,000,000 | YES | `null` (=unlimited first-N capacity) | `nullable\|integer\|min:1\|max:1000000` | max ACTIVE+REDEEMED claims across all users |
| `claim_ttl_hours` | no | integer | 1–8760 (1h–365d) | YES | `null` (=never expires) | `nullable\|integer\|min:1\|max:8760` | ACTIVE claim TTL; expiry → EXPIRED, capacity released, re-claim allowed |
| `rule_tree` | no | object/null | leaf/group grammar (§6), depth ≤10 | YES | `null` (=no rules) | `nullable\|array` + `RuleTreeValidator::validate` fail-closed → 422 with `data.errors[]` | dynamic eligibility tree; ignored at runtime in pure `assignment` mode |

Notes: there is NO top-level `rules`/`operator`/`type`/`value` — those live *inside* `rule_tree`. `max_claims`/`claim_ttl_hours` omitted → stored NULL. `rule_tree: null` or omitted → stored NULL.

## Behavior (VERIFIED)

1. `authorizeWrite` (403 unless `update-coupon`/`create-coupon`).
2. `Coupon::findOrFail` (404 if missing).
3. `$request->validated()` (422 standard Laravel envelope on `mode`/`require_claim`/range failures — NOT the `data.errors` shape; see §16).
4. `RuleTreeValidator::validate($data['rule_tree'] ?? null)`; invalid → **422** `Could not update the resource` with `data: {errors: [...]}` (see §16). Fail-closed: bad tree never persists.
5. `DB::transaction(fn: CouponTargeting::updateOrCreate(['coupon_id'=>id], [mode, require_claim cast bool, max_claims?, claim_ttl_hours?, rule_tree?]))`. **Upsert = replace entire row**, not merge. Old tree discarded. `UNIQUE(coupon_id)` guarantees one row.
6. `event(new CouponTargetingChanged($coupon->fresh()))` — `ShouldDispatchAfterCommit`, so listener observes committed state. Listener `StartCouponDistribution` (queue `high`) → `DistributionService::startDistribution(coupon, TARGETING_CHANGED, 'activation')` → full-audience run via outbox/RabbitMQ, or clean skip (`NonDistributableCouponException` → log `coupon.trigger.skipped_non_distributable`) when targeting removed or mode not dynamic-family.
7. Return 200 `Coupon updated successfully` + fresh `CouponTargetingResource`.

Transaction boundary: only the `updateOrCreate` is wrapped; event dispatches after commit. No `FOR UPDATE` lock here (locking lives in claim/reservation paths). No tree-hash column written (hash computed at distribution time via `TreeHash`; runs carry `dedupe_key = coupon:tree_hash:trigger:scope`). No version column. No claim/assignment/reservation/notification mutation: existing claims, assignments, reservations, notified states are **untouched**; previously notified users keep state, newly eligible users are picked up by the new run (same-hash dedupe converges double-fires; new hash re-opens evaluation per `EligibilityTransitionService`).

## Cases A–P

| Case | Request | Validation | DB | Events | Response |
|---|---|---|---|---|---|
| A valid `assignment` | mode=assignment, require_claim bool, tree anything/null | pass | upsert row | `targeting.changed` → distribution skipped (non-distributable) | 200 + resource |
| B valid `dynamic` | mode=dynamic + valid tree/null | pass | upsert | `targeting.changed` → full-audience run | 200 |
| C valid `assignment_and_dynamic` | both branches configured | pass | upsert | run (family-filtered fan-out at trigger time) | 200 |
| D valid `assignment_or_dynamic` | either branch | pass | upsert | run | 200 |
| E invalid mode | `mode:"all"` | 422 (FormRequest `in:`) | none | none | 422 Laravel envelope |
| F missing mode | omit | 422 `required` | none | none | 422 |
| G null rules | `rule_tree:null`/omit | valid ("no rules") | upsert NULL tree | run (dynamic-family eligible-vacuous) | 200 |
| H empty rules | `rule_tree:{"operator":"AND","rules":[]}` | 422 (`data.errors`: non-empty array fail-closed) | none | none | 422 custom shape |
| I malformed tree | leaf+operator mix, non-array node | 422 `data.errors` | none | none | 422 |
| J unknown rule type | `{"type":"vip_level"}` | 422 `Unknown rule type` | none | none | 422 |
| K invalid value | `area_in:[]`, `has_email:"yes"`, negative int, bad date | 422 per-type message | none | none | 422 |
| L invalid operator | `"operator":"XOR"` / lowercase `and` | 422 `Unknown operator` | none | none | 422 |
| M depth>10 | 11 nested groups | 422 `exceeds maximum depth of 10` | none | none | 422 |
| N coupon missing | id=999999 | `findOrFail` | none | none | 404 |
| O unauthorized | no token / view-only token on PUT | 401 / 403 | none | none | 401/403 |
| P concurrent PUT | two admins simultaneously | last-writer-wins (no optimistic lock); each transactional; two events → dedupe converges | one row survives | two `targeting.changed` (dedupe key converges) | 200 × 2 |

---

# 11. DELETE /coupons/{id}/targeting

```http
DELETE /api/v1/coupons/123/targeting HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```

Body: NONE. Write permission required.

**What gets deleted**: exactly one row in `coupon_targetings` (`$targeting->delete()`). Coupon row, assignments, claims, reservations, usages, distribution history: **untouched**. No `forceDelete`/cascade beyond the row itself (FK is coupon→targeting `cascadeOnDelete` in the other direction).

**After DELETE**: `EligibilityEngine::evaluate` sees `targeting=null` → **always-eligible** (`no_targeting`), and orchestrator falls back to assignment-validator behavior (valid when coupon has no assignments). Net effect: **coupon becomes public/widens access immediately**. This is NOT "assignment-only" — verify from code comment: "Removes targeting → coupon becomes always-eligible (backward compat)."

Before/after example: `mode=dynamic, rules={...}` → after DELETE any authenticated user passes targeting (still subject to status/dates/limiter/product gates and `CouponValidator`).

**Side effects**: fires the same `CouponTargetingChanged` event ("removing targeting returns the coupon to always-eligible; in-flight targeted runs stop harmlessly") → distribution listener runs but throws/skips `NonDistributableCouponException` (no targeting → nothing to distribute). No revocation of claims/assignments/reservations/notifications; previously notified users are not un-notified; newly eligible users gain access without any run (already eligible by default).

**Responses**: success 200 `Coupon deleted successfully`, **no `data` key**. No targeting → 404 `COUPON_NO_TARGETING`. No coupon → 404. 401/403 as §2.4.

**Frontend warning** (from contract doc, consistent with code): do NOT delete targeting on live coupons to "restrict" — it widens. To restrict, PUT `mode=assignment` + `require_claim` instead, with explicit confirm UI.

---

# 12. GET /coupons/rules

## Purpose

Admin targeting-builder metadata: authoritative rule identifiers + display labels + value constraints + tree grammar. **Admin frontend only**; no customer variant by design (customer endpoints hide targeting internals; "Do not add `GET /api/v1/general/coupons/rules`").

## Auth

Sanctum + read permission (same read model as GET targeting). Guest → 401; user without permission → 403.

## Request

```http
GET /api/v1/coupons/rules HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```

Body: NONE. Query: none.

## Response (VERIFIED — `CouponRuleMetadata::all` + `::grammar`, shape-tested)

HTTP 200 `Data fetched successfully`:

```json
{
  "status": 200, "message": "...", "success": true,
  "data": {
    "rules": [
      {
        "type": "min_completed_orders",
        "label": { "en": "Minimum completed orders", "ar": "الحد الأدنى من الطلبات المكتملة" },
        "description": { "en": "...", "ar": "..." },
        "value_type": "integer",
        "value_required": true,
        "value_example": 3,
        "min": 0, "max": null,
        "allowed_values": null,
        "date_format": null,
        "context": "customer_history",
        "evaluation": { "claim": true, "apply": true, "checkout": true, "fast_checkout": true, "defers_without_context": false }
      }
    ],
    "rule_tree": {
      "supported": true, "operators": ["AND","OR"], "max_depth": 10,
      "nested_groups_allowed": true, "empty_group_allowed": false,
      "null_allowed": true, "duplicate_rules_allowed": true,
      "unknown_rule_behavior": "reject_422", "malformed_node_behavior": "reject_422"
    }
  }
}
```

17 entries, one per `EligibilityRuleType`; `value_type` ∈ `integer|decimal|datetime|none|boolean_or_null|area_list`; `context` ∈ `customer_history|claim_state|assignment_state|customer_addresses|customer_profile`. Labels/descriptions are **display-only** (en/ar) — validation authority is `RuleTreeValidator`, never these strings.

## Frontend guidance

- Use this endpoint to **build the rule selector dynamically**; it is authoritative for identifiers/constraints (parity test guarantees every exposed rule passes the validator). Do NOT hard-code rule types — new types appear here first.
- Fully static (no customer reads, no DB): safe to **cache** for the admin session; no versioning header — re-fetch on builder open. `max_depth` and `operators` come from the single source (`RuleTreeValidator::MAX_DEPTH`), so respect them client-side to avoid predictable 422s.
- Response exposes no PII/secrets (sensitive-needle test asserts absence of `eligibility_snapshot, limiter, coupon_usages, assignments, user_id, password…`).

---

# 13. POST /coupons/validate-configuration

## Purpose

Pre-save **capacity-model sanity check** (public vs assigned multi-use). Stateless advisor: validates configuration only — creates/updates nothing, runs no per-customer eligibility, touches no targeting tree.

## Request

```http
POST /api/v1/coupons/validate-configuration HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{ "coupon_type": "public", "limiter": 100, "max_uses_per_user": 1 }
```

Fields (VERIFIED — inline `$request->validate`): `coupon_type* in:public,assigned`; `limiter nullable integer min:1`; `max_uses_per_user nullable integer min:1` (defaults to 1 when omitted). No other fields accepted (extra keys ignored, not validated).

## Logic (VERIFIED)

- `public` + `max_uses_per_user>1` → `errors[]`: `{field:max_uses_per_user, message:'Public coupons only support single use per customer.', explanation:'coupon_usages UNIQUE(coupon_id,user_id) prevents repeat use. For multi-use, switch to assigned.'}` + recommendation describing single-use-per-customer / global capacity. `valid=false`.
- `public` (valid) → recommendation `Public Coupon Behavior: Each customer can redeem exactly once. Global capacity: {limiter|unlimited}.`
- `assigned` + `max_uses_per_user>1` → recommendation `Multi-Use Configuration: ... create assignments with max_uses=N`.
- `assigned` + no `limiter` → warning `No global limiter set...`.
- `public` + `limiter>1000` → warning `High global limit...`.
- HTTP is always 200 (even when `valid:false`); `success` mirrors `empty($errors)`. Envelope: `data:{valid, errors[], warnings[], recommendations[{title,description}]}`.

## Distinction (must-read)

| Operation | What it checks | Per-user? | Mutates? |
|---|---|---|---|
| `validate-configuration` | capacity model (`coupon_type/limiter/max_uses_per_user`) | NO | NO |
| `CouponTargeting` validation (PUT) | tree grammar (fail-closed) | NO | persists tree on pass |
| `EligibilityEngine` | this user vs mode+tree | YES | NO (read-only) |
| Claim validation | targeting pass + require_claim + ACTIVE/REDEEMED + max_claims + lock | YES | creates ACTIVE claim |
| Apply validation | orchestrator: claim + mode + assignment + static gates + products | YES (cart preview) | NO |
| Checkout revalidation | orchestrator again, authoritative, on order items | YES | creates order + reservation |

---

# 14. GET /coupons/{id}/usage-info

## Purpose

Admin usage/capacity dashboard for one coupon. Admin only.

## Request

```http
GET /api/v1/coupons/123/usage-info HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```

Body: NONE.

## Flow (VERIFIED)

`authorizeAdmin (read) → Coupon::with(['assignments','couponUsages'])->findOrFail → isPublic()=assignments().exists()? → current_usage=(int)coupons.used → global_limit=coupons.limiter → assignment_info (assigned only) → apiResponse`. Read-only; no locks; numbers are point-in-time (concurrency: two redemptions between read and render can stale the display — documented finding, not a bug in a read endpoint).

## Response (exact keys)

```json
{
  "status": 200, "message": "Usage info retrieved.", "success": true,
  "data": {
    "coupon_code": "SAVE20",
    "coupon_type": "assigned",
    "usage_model": "Assigned coupon: Up to 2 uses per assigned customer",
    "current_usage": 3,
    "global_limit": 100,
    "remaining_capacity": 97,
    "is_multi_use_per_user": true,
    "assignment_info": {
      "total_assignments": 10,
      "assignments_with_usage": 2,
      "max_uses_per_user": 2,
      "total_possible_redemptions": 20
    },
    "public_usage_count": 0
  }
}
```

Metric semantics (VERIFIED):

- `current_usage` = `coupons.used` (permanent successful consumptions, incremented only on payment success). Excludes active reservations and ACTIVE claims.
- `global_limit` = `coupons.limiter` (null = unlimited).
- `remaining_capacity` = `max(0, limiter - used)` or `"unlimited"` (string) when limiter null.
- `assignment_info` = null for public coupons; else `total_assignments` (COUNT rows), `assignments_with_usage` (COUNT `used>0`), `max_uses_per_user` (MAX(max_uses) — coarse when assignments vary), `total_possible_redemptions` (total × max — upper bound, not reserved).
- `public_usage_count` = `coupon_usages` COUNT for public coupons, else 0 (assigned-coupon usage rows are not counted here — use `current_usage`).
- `coupon_type`: `public` = zero assignment rows; `assigned` = ≥1 row (even if all expired/exhausted).
- `is_multi_use_per_user` = exists assignment with `max_uses>1`.

## Four concepts (do not confuse)

- **Assignment**: grant (`max_uses`, `used` quota). Not consumption.
- **Claim**: activation (ACTIVE/REDEEMED/EXPIRED). ACTIVE counts toward `max_claims` capacity; expired releases it. Not consumption.
- **Reservation**: 30-min payment-window hold (`used + active_reservations < limiter`). Deleted on consume/release. Not consumption.
- **Usage**: permanent (`coupon_usages` row + `coupons.used+1` + `assignments.used+1` + claim→REDEEMED). Only this moves `current_usage`.

---

# 15. POST /coupons/{id}/suggest-fix

## Purpose

Read-only **diagnostic advisor** for single↔multi-use-per-user misconfiguration. Returns recommendations only — mutates nothing (no targeting/rule/assignment/coupon writes, no events).

## Request

```http
POST /api/v1/coupons/123/suggest-fix HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{ "desired_behavior": "multi_use_per_user" }
```

`desired_behavior` read via `$request->input()` (no FormRequest): must be `multi_use_per_user` or `single_use_per_user`; anything else (incl. missing) → **400** `Invalid desired_behavior...` with `success:false`.

## Responses (all HTTP 200 except invalid input)

- `multi_use_per_user` + public coupon → `{current_issue, recommended_action:'convert_to_assigned', steps:[3 assignment steps], example_code:"CouponAssignment::create(['coupon_id'=>ID,'user_id'=>$userId,'max_uses'=>5]);"}`.
- `multi_use_per_user` + assigned → `{current_config:"Assigned coupon with max_uses=N", recommended_action:'already_configured' (N>1, steps:[]) | 'update_assignments' (N≤1, steps:[update SQL-ish string])}`.
- `single_use_per_user` → `{current_config, recommended_action:'no_change_needed' (public) | 'remove_assignments_or_set_max_uses_1' (assigned), message}`.
- Coupon missing → 404. 401/403 per read model.

Coupon code is echoed in `usage-info` (`coupon_code`) but suggest-fix responses contain no codes/PII beyond counts and the illustrative `example_code` snippet.

---

# 16. Request/Response Matrix

| Endpoint | Req headers | Req body | Success | Success body | Errors |
|---|---|---|---|---|---|
| GET targeting | `Authorization: Bearer`, `Accept: application/json` | none | 200 | resource (§9) | 401, 403, 404 (×2 kinds) |
| PUT targeting | + `Content-Type: application/json` | `mode*, require_claim*, max_claims?, claim_ttl_hours?, rule_tree?` | 200 | resource (§10) | 401, 403, 404 (coupon), 422 (×2 shapes) |
| DELETE targeting | Bearer + Accept | none | 200, no `data` | `{"status":200,"message":"Coupon deleted successfully","success":true}` | 401, 403, 404 (×2) |
| GET rules | Bearer + Accept | none | 200 | `{rules[17], rule_tree grammar}` | 401, 403 |
| POST validate-config | + Content-Type | `coupon_type*, limiter?, max_uses_per_user?` | 200 always (valid flag inside) | `{valid, errors[], warnings[], recommendations[]}` | 401, 403, 422 (bad enum/range) |
| GET usage-info | Bearer + Accept | none | 200 | metrics (§14) | 401, 403, 404 (coupon) |
| POST suggest-fix | + Content-Type | `desired_behavior*` | 200 | variant shapes (§15) | 400 (bad behavior), 401, 403, 404 |

Two 422 shapes (VERIFY before coding the client):

1. FormRequest failures (`mode`, `require_claim`, ranges, `coupon_type`…): standard Laravel `{"message":"...","errors":{"field":[...]}}` HTTP 422 (thrown before controller).
2. Rule-tree semantic failures (PUT only): `{"status":422,"message":"Could not update the resource","success":false,"data":{"errors":["Unknown rule type: ...", ...]}}` (controller-built).

---

# 17. Error Matrix

| HTTP | Meaning | When here | Example | Frontend action |
|---|---|---|---|---|
| 200 | OK | all successes; validate-config even when `valid:false`; suggest-fix diagnostics | §§9–15 | render `data` |
| 400 | Bad input (controller-level) | suggest-fix bad `desired_behavior` | `{"status":400,"message":"Invalid desired_behavior...","success":false}` | fix `desired_behavior` value |
| 401 | Unauthenticated | missing/invalid Sanctum token on any endpoint | framework or `abort(401)` | re-login, attach token |
| 403 | Forbidden | missing `view-…` (reads) / `update-coupon` (writes) | `Forbidden. Missing required permission: update-coupon.` | request grant; view-only admins cannot PUT/DELETE |
| 404 | Not found (route miss) | non-numeric `{id}`; `GET rules` shadowed — N/A (ordering fixed) | framework HTML/JSON | check URL; ids are integers |
| 404 | Coupon missing | `findOrFail` | framework `ModelNotFound` JSON | refresh coupon list |
| 404 | Targeting missing | GET/DELETE with no row | `{"status":404,"message":"This coupon has no targeting configuration.","success":false}` | GET→show empty builder; DELETE→nothing to do |
| 409 | Conflict | **never** from these 7 (409s belong to assignment/claim endpoints) | — | — |
| 422 | Validation | FormRequest failures; invalid rule tree | two shapes (§16) | show field errors / tree errors |
| 429 | Throttled | `throttle:admin` exceeded | framework | back off |
| 500 | Infra failure | DB down etc. (orchestrator deliberately lets infra bubble, never masks as invalid) | framework | retry later; alert |

---

# 18. Frontend Integration Guide

- Always send `Authorization: Bearer <token>` + `Accept: application/json` (+ `Content-Type` on PUT/POST). Expect `{status,message,success,data?}` — never assume `data` present (DELETE has none; 404-targeting has none).
- Never assume field names from the task brief (`rules` top-level). PUT top-level is `rule_tree`; `rules`/`operator`/`type`/`value` live inside it.
- Never assume the customer sees targeting: `rule_tree`, assignments, claims, metrics are admin-only. Customer APIs return only code/discount/eligibility outcomes.
- Never assume PUT merges: it **replaces** the whole row. Always GET-then-edit-then-PUT the full object.
- Never assume DELETE restricts: it **widens to public**. Confirm destructively.
- Never assume `used`/`remaining_capacity` includes reservations/claims: it does not.
- Never assume checkout `governorate_id` affects `area_in`: it does not.
- Never assume `suggest-fix`/`validate-configuration`/`usage-info` change anything: they are read-only.
- Never assume mode strings beyond the 4: anything else is 422.

---

# 19. Complete Admin Flow

```text
Admin opens coupon
  → GET /api/v1/coupons/{id}/targeting
      200 → render mode/claim-policy/tree; 404 COUPON_NO_TARGETING → blank builder
  → GET /api/v1/coupons/rules → build rule selector (17 types + grammar)
  → Admin selects mode + require_claim + max_claims + TTL + builds rule_tree
  → (optional) POST /api/v1/coupons/validate-configuration {coupon_type, limiter, max_uses_per_user}
      → show errors/warnings/recommendations (capacity model only — NOT eligibility)
  → PUT /api/v1/coupons/{id}/targeting (full object)
      → 200 saved + targeting.changed run starts; 422 → fix highlighted nodes
  → GET /api/v1/coupons/{id}/targeting → verify persisted state
  → GET /api/v1/coupons/{id}/usage-info → monitor capacity
  → POST /api/v1/coupons/{id}/suggest-fix {desired_behavior} → follow steps manually
```

Correct call order: read → catalog → (advise) → write → verify → monitor.

---

# 20. Targeting → Claim → Apply → Checkout → Reservation → Usage (VERIFIED)

```text
Targeting: "Can this customer qualify?" (EligibilityEngine, read-only)
  ↓ eligible
Claim (require_claim=true): "Customer activates." POST general/coupons/{id}/claim
  → parent-row lock CouponTargeting FOR UPDATE → checks ACTIVE/REDEEMED, max_claims (ACTIVE+REDEEMED count),
    evaluate() → create ACTIVE claim + eligibility_snapshot (+expires_at from claim_ttl_hours). Errors 409.
  → require_claim=false → claim() throws claim_not_required; orchestrator skips claim gate.
  ↓ has ACTIVE-unexpired claim (or no claim needed)
Apply: "Can it apply to this cart?" POST general/coupons/apply {code}
  → orchestrator: claim gate → mode gate (engine ± assignment validator) → CouponValidator (status/dates/limiter/used/products)
  → returns totals preview; no quota consumed.
  ↓ preview ok
Checkout: authoritative revalidation POST general/checkout (+ fast-shipping/checkout)
  → same orchestrator on ORDER items + assertCartProductsActive + totals/currency snapshot
  → creates order (coupon snapshot: coupon, coupon_discount, currency_*, governorate_id) + reservation (30 min, per-order idempotent)
  ↓ order + reservation
Payment success (callback): consume reservation (delete) + coupon_usages row + coupons.used+1 +
  assignments.used+1 (under Assignment FOR UPDATE) + claim ACTIVE→REDEEMED + coupon_consumed flag. Idempotent via unique usage + flags + row locks.
Payment failure/error-callback | cancel/refund: release reservation (delete); NO usage; claim stays ACTIVE; coupon NOT returned on cancel/refund (contract §11).
```

Targeting affects each stage: engine is authoritative for every mode including a tree at claim/apply/checkout/payment-revalidation ("never trust earlier results"); `area_in` strict at every stage from saved addresses.

---

# 21. Database Behavior

| Endpoint | Table | R/W | Key columns | Why |
|---|---|---|---|---|
| GET targeting | `coupons` | R | `id` | findOrFail |
| | `coupon_targetings` | R | `id, coupon_id, mode, require_claim, max_claims, claim_ttl_hours, rule_tree` | serialize |
| PUT targeting | `coupons` | R | `id` | findOrFail |
| | `coupon_targetings` | **W (upsert)** | same + `UNIQUE(coupon_id)`, `index(require_claim)`; enum `mode` incl. combined modes (migration `2026_09_14_000003`); `claim_ttl_hours` (`..._000002`); `max_claims` renamed (`..._000004`) | `updateOrCreate` in transaction |
| DELETE targeting | `coupons` | R | `id` | findOrFail |
| | `coupon_targetings` | **W (delete)** | row removed | widens to always-eligible |
| GET rules | — | — | — | zero reads (static) |
| POST validate-config | — | — | — | zero reads/writes (pure function of 3 inputs) |
| GET usage-info | `coupons` | R | `code, limiter, used` | base metrics |
| | `coupon_assignments` | R | `coupon_id, user_id, max_uses, used` (+ COUNT/MAX) | assignment_info |
| | `coupon_usages` | R | `coupon_id, user_id, used_at` (COUNT) | public_usage_count |
| POST suggest-fix | `coupons` | R | `id` | findOrFail |
| | `coupon_assignments` | R | `max(max_uses)` | decide recommendation |
| Downstream (reference) | `coupon_claims` | R/W | `coupon_id, user_id, status, expires_at, eligibility_snapshot` | claim lifecycle |
| | `coupon_reservations` | R/W | `coupon_id, user_id, order_id, expires_at` | payment-window hold |
| | `customer_metrics` | R | `completed_orders, total_qualifying_order_value, first/last_order_at, coupons_used` | dynamic rules |
| | `address` | R | `customer_id, governorate_id` | area_in |
| | `governorates` | R | `id, status` | active-only filter |
| | `users` | R | `email, created_at` | has_email/registered_* |
| | `orders` | R | status/payment/coupon linkage (via metrics + validator) | history rules, product gate |
| | `coupon_outbox`, `coupon_event_logs`, `coupon_distribution_runs`, `..._recipients`, `..._user_states` | W (via distribution, not these controllers directly) | `tree_hash, dedupe_key, state` | async fan-out after PUT/DELETE |

Migrations evidencing schema: `2026_09_10_000001` (create, enum assignment|dynamic, UNIQUE(coupon_id)), `2026_09_10_000004` (rename max_claims_per_user→max_claims), `2026_09_14_000002` (claim_ttl_hours), `2026_09_14_000003` (extend enum with combined modes), `2026_09_26_000002` (SQLite parity repair).

---

# 22. Events / RabbitMQ / Outbox (VERIFIED)

- PUT/DELETE → `event(new CouponTargetingChanged($coupon->fresh()))`. Event class extends `CouponLifecycleEvent` (`ShouldDispatchAfterCommit`, carries `Coupon $coupon`, no logic).
- `EventServiceProvider`: `CouponTargetingChanged → StartCouponDistribution` (also `CouponActivated →` same listener).
- `StartCouponDistribution` (`ShouldQueue`, queue `high`): `DistributionService::startDistribution(coupon.fresh('targeting'), TARGETING_CHANGED, 'activation')`. `NonDistributableCouponException` (targeting removed / non-dynamic mode) → `Log::info('coupon.trigger.skipped_non_distributable')`, no retry. Other throwables → warn log + rethrow (queue retry).
- `DistributionService::startDistribution`: throws `NonDistributableCouponException` when not distributable; else computes `tree_hash` (`TreeHash`, in-run — no column on targetings), creates run (`dedupe_key = coupon:tree_hash:trigger:scope`), `outbox->recordAndDispatch(envelope)`.
- Outbox (`CouponOutboxService`): business rows + outbox row commit atomically; publisher relays to RabbitMQ topic exchange (`RabbitMqTopology`: exchange, per-work-type queues/bindings, retry/DLQ, `maxAttempts`, backoff). Event type `coupon.targeting.changed` (`CouponDistributionEvents::COUPON_TARGETING_CHANGED`). Envelope carries `tree_hash`, coupon id, trigger/scope, correlation/causation ids (see `CouponEventEnvelope`; exact key set **NOT exhaustively verified** here — do not quote beyond `tree_hash` + routing).
- Consumers: `DistributionStartHandler` (refuses stale tree drift) → `coupon.distribution.chunk` per chunk → `DistributionChunkHandler` → `coupon.user.evaluate` → `UserEvaluateHandler` (engine per user, `tree_hash`-scoped state) → eligible transitions recorded via outbox; `NotificationRequestHandler` sends newly-eligible notifications with cross-run NOTIFIED dedupe (`EligibilityTransitionService`: same-hash retries converge; new hash re-opens evaluation).
- The other 5 endpoints emit **no events, no outbox rows, no notifications, no RabbitMQ traffic**. Notifications for targeting changes go only through distribution runs (plus assignment-created notifications on the separate assignment flow — out of scope here).

---

# 23. Security

- Auth: Sanctum required everywhere; guest → 401 (rules test proves both URL families).
- AuthZ: read = view/update/create-coupon; write = update/create-coupon only (view-only cannot PUT/DELETE — tested pattern, explicit code comment). Missing → 403 with named permission. No IDOR beyond coupon id: any holder of the permission can read/mutate any coupon's targeting (no per-coupon ownership — by design for admin role; document as accepted model, not a finding).
- `user_id` is never accepted from request in these 7 (identity comes from token; `area_in` scopes to `customer_id = auth user`). No request-supplied-identity escalation path.
- Exposure: GET targeting/rules/usage-info/suggest-fix return admin internals (`rule_tree`, capacity numbers, `coupon_code`, assignment aggregates). All permission-gated; customer endpoints never include them (contract §2.A). Rules response needle-tested for leaks (`eligibility_snapshot, limiter, coupon_usages, assignments, user_id, password…` absent).
- PII: `area_in` evaluation uses governorate ids only; metrics snapshot in engine contains booleans/timestamps, no PII beyond what claim snapshots store server-side. Usage-info exposes `coupon_code` to permissioned admins only (accepted).
- Input: `rule_tree` strictly whitelisted + strict-int/date/bool checks (no coercion of `1.5→1`); money decimal-safe; e-mail validated RFC; mass assignment limited to 5 fillable targeting fields via `validated()` + `updateOrCreate`.

---

# 24. Concurrency

- PUT: `DB::transaction` around single `updateOrCreate`; no `FOR UPDATE`, no optimistic lock → **last-writer-wins**. Safe because full-row replace is idempotent; double-fire converges via run dedupe. Documented, not a defect.
- DELETE: single row delete, no lock; same convergence story.
- Read endpoints (GET targeting/rules/usage-info, validate, suggest): no locks; usage-info explicitly point-in-time (stale-read possible under concurrent redemption — display-only risk).
- Real locking lives downstream (VERIFIED, reference): claim serializes on `CouponTargeting FOR UPDATE` + bounded retry(3); reservation serializes on `Coupon FOR UPDATE` + reservation `FOR UPDATE` + retry(3); payment completion re-checks quota under `Assignment FOR UPDATE`. Targeting `evaluate()` itself is lock-free by design ("Lock ordering F-13: read-only").

---

# 25. Tests and Evidence

| Behavior | Test file | Test(s) | Status |
|---|---|---|---|
| No targeting → eligible | `tests/Unit/Services/Coupon/Eligibility/EligibilityEngineTest.php` | `test_no_targeting_returns_eligible` | VERIFIED (present) |
| Assignment mode ± row | same | `test_assignment_mode_with/without_assignment_returns_...` | VERIFIED |
| Order/spend/claim/AND/OR/unknown-rule/max rules/snapshot | same | `test_min_completed_orders[_fails]`, `test_min_total_spend_rule`, `test_not_claimed_rule_{passes,fails}`, `test_and/or_operator...`, `test_unknown_rule_type_fails_closed`, `test_max_completed_orders_rule`, `test_eligibility_snapshot_includes_metrics` | VERIFIED (names from grep) |
| Rules catalog shape/parity/auth/shape-leak/alias | `tests/Feature/Coupon/CouponRulesMetadataTest.php` | 6 tests incl. `test_admin_receives_full_catalog_with_stable_shape` (17 count), `test_every_exposed_rule_passes_rule_tree_validator`, `test_legacy_alias_serves_identical_catalog`, guest/customer 401/403 | VERIFIED (read in full) |
| Configuration validate/usage/suggest | `tests/Feature/CouponConfigurationTest.php` | (referenced by search; bodies NOT read — treat as INFERRED present) | INFERRED |
| Eligibility lifecycle incl. combined modes | `tests/Feature/Coupon/CouponEligibilityLifecycleTest.php` | (referenced; bodies NOT read) | INFERRED |
| Claim lifecycle/concurrency | `tests/Feature/Coupon/CouponClaim*Test.php`, `tests/Concurrency/CouponClaim*Test.php`, `tests/Feature/CouponConcurrencyProofTest.php` | (referenced; bodies NOT read) | INFERRED |
| Checkout revalidation | `tests/Feature/Coupon/CouponCheckoutRevalidationTest.php` | (referenced) | INFERRED |
| Final contract / remediation / security | `tests/Feature/CouponFinalContractTest.php`, `CouponRemediation*Test.php`, `SecurityRemediationTest.php`, `CouponsProductionHardenTest.php` | (referenced) | INFERRED |
| PUT/DELETE targeting HTTP-level | — | **NO DIRECT TEST FOUND** (no `CouponTargetingController` feature test located by search) | MISSING |
| `suggest-fix`/`usage-info` HTTP-level | — | **NO DIRECT TEST FOUND** (beyond config test above, bodies unverified) | MISSING (verify before relying) |

---

# 26. Documentation Mismatches

| # | Documented / assumed | Actual (code) | Risk | Correction |
|---|---|---|---|---|
| 1 | Task brief PUT body `{mode, rules}` with top-level `rules/operator/type/value` | Real fields: `mode, require_claim, max_claims, claim_ttl_hours, rule_tree` (`UpsertTargetingRequest`); tree nested inside `rule_tree` | Frontend builds wrong payload → systematic 422 | Use §10 matrix; top-level `rules` does not exist |
| 2 | Task brief endpoints at `/api/v1/coupons/...` only | Both `/api/v1/coupons/...` (canonical) AND `/api/v1/admin/coupons/...` (legacy alias) serve | Frontend hard-codes one family; tests use both | Either works; prefer canonical |
| 3 | `EligibilityRuleType::AREA_IN` enum comment: "checkout delivery governorate … orders.governorate_id" | Implementation: saved `address.governorate_id` ∩ active governorates; checkout input ignored | Builder copy + QA test the wrong source | Fix enum comment to saved-address rule; keep engine |
| 4 | Generic envelope `{success,message,data,meta}` | These endpoints: `{status,message,success,data?}` (no `meta`; `data` omitted when empty) | Client null-dereferences `data` on DELETE/404 | Follow §2.5 |
| 5 | Assumed `PUT` merges / versions / hashes | `updateOrCreate` replace; no version column; no stored tree hash (hash at distribution time) | Lost-update surprise; hash lookup in DB fails | GET-edit-PUT full object; read hash from runs/outbox |
| 6 | Assumed DELETE = "assignment-only" | DELETE = always-eligible (widens) | Accidental public exposure | Confirm UI + prefer mode-switch over delete |
| 7 | Assumed these endpoints consume quota / send notifications directly | They never touch quota; PUT/DELETE only start distribution runs (notifications via runs) | Double-counting in admin reports | Attribute consumption to claim/payment paths only |

---

# 27. Findings / Risks / Gaps

1. **DELETE widens access silently** (by design, backward-compat). No confirm gate server-side; relies on admin discipline + contract warning. Risk: live restricted coupon becomes public in one call. Mitigation (docs only, no code change per task): destructive-confirm UI + audit log review.
2. **PUT last-writer-wins** (no optimistic locking / ETag). Concurrent admin edits converge on one row + dedupe-converged runs — acceptable for low-frequency admin writes; note for audit completeness.
3. **Usage-info staleness**: point-in-time read without locks; concurrent redemptions can invalidate displayed `remaining_capacity` immediately. Display-only; checkout/reservation remain authoritative.
4. **`assignment_info.max_uses_per_user` uses MAX** across assignments — misleading when assignments vary per user; `total_possible_redemptions` is an upper bound. Report as-is; per-user truth lives on assignment rows.
5. **Stale `AREA_IN` enum comment** (§26.3) — only doc-level; engine + metadata + validator agree on saved-address semantics.
6. **No direct HTTP tests located for PUT/DELETE targeting, usage-info, suggest-fix** (bodies of config/lifecycle tests not verified here). Behavior above is code-verified but lacks a cited regression test — flag for QA follow-up.
7. **Legacy alias lacks `lang` middleware entry** while canonical has it — translation fallback path for legacy 404/validation messages NOT VERIFIED; recommend a check if admin frontend uses legacy URLs with non-English locale.

---

# 28. Final Verified Contract

## GET targeting — `GET /api/v1/coupons/{id}/targeting` 

- AUTH: Sanctum Bearer. PERMISSION: `view-coupons`|`update-coupon`|`create-coupon`.
- PATH: `{id}` integer (`whereNumber`). QUERY: none. HEADERS: `Authorization`, `Accept: application/json`. BODY: none.
- SUCCESS 200: `{"status":200,"message":"Data fetched successfully","success":true,"data":{id,coupon_id,mode,require_claim,max_claims,claim_ttl_hours,rule_tree,created_at,updated_at}}`.
- ERRORS: 401 unauth; 403 missing view permission; 404 no-targeting (`ERROR.COUPON_NO_TARGETING`, no `data`); 404 coupon-missing.
- DB: read `coupons` + `coupon_targetings`. EVENT: none. FRONTEND: render builder; 404 → blank builder.

## PUT targeting — `PUT /api/v1/coupons/{id}/targeting` (alias likewise)

- AUTH: Sanctum. PERMISSION: `update-coupon`|`create-coupon` (view-only → 403).
- PATH: `{id}` integer. HEADERS: `Authorization`, `Accept`, `Content-Type: application/json`.
- BODY: `{"mode":"assignment|dynamic|assignment_and_dynamic|assignment_or_dynamic"*,"require_claim":bool*,"max_claims":int1–1M|null,"claim_ttl_hours":int1–8760|null,"rule_tree":null|leaf|group}`.
- SUCCESS 200: `Coupon updated successfully` + fresh resource. ERRORS: 401/403/404 coupon/422 (two shapes, §16).
- DB: `updateOrCreate` replace in transaction. EVENT: `CouponTargetingChanged` → distribution run (or clean skip for assignment mode). FRONTEND: PUT full object; 422 → highlight `data.errors` nodes; re-GET to verify.

## DELETE targeting — `DELETE /api/v1/coupons/{id}/targeting` (alias likewise)

- AUTH/PERM: Sanctum + write (`update-coupon`/`create-coupon`). BODY: none.
- SUCCESS 200: `{"status":200,"message":"Coupon deleted successfully","success":true}` (no `data`). ERRORS: 401/403/404 (×2).
- DB: delete `coupon_targetings` row → coupon always-eligible. EVENT: `CouponTargetingChanged` (run skipped as non-distributable). FRONTEND: confirm destructively; never use to restrict.

## GET rules — `GET /api/v1/coupons/rules` (alias: `/api/v1/admin/coupons/rules`)

- AUTH: Sanctum + read permission. BODY: none.
- SUCCESS 200: `{rules:[17 × {type,label{en,ar},description{en,ar},value_type,value_required,value_example,min,max,allowed_values,date_format,context,evaluation{claim,apply,checkout,fast_checkout,defers_without_context}}], rule_tree:{supported,operators:[AND,OR],max_depth:10,nested_groups_allowed:true,empty_group_allowed:false,null_allowed:true,duplicate_rules_allowed:true,unknown_rule_behavior:reject_422,malformed_node_behavior:reject_422}}`.
- ERRORS: 401/403. DB: none. EVENT: none. FRONTEND: drive builder dynamically; cache per session.

## POST validate-configuration — `POST /api/v1/coupons/validate-configuration` (alias likewise)

- AUTH: Sanctum + read (`authorizeAdmin`). BODY: `{"coupon_type":"public|assigned"*,"limiter":int≥1|null,"max_uses_per_user":int≥1|null(=1)}`.
- SUCCESS: always HTTP 200, `{"valid":bool,"errors":[{field,message,explanation}],"warnings":[{field,message}],"recommendations":[{title,description}]}`; `success=valid`. ERRORS: 401/403/422 (bad enum/range).
- DB: none. EVENT: none. FRONTEND: pre-save advisor for capacity model only; `valid:false` blocks save.

## GET usage-info — `GET /api/v1/coupons/{id}/usage-info` (alias likewise)

- AUTH: Sanctum + read. BODY: none. PATH: integer id.
- SUCCESS 200: `{coupon_code,coupon_type:public|assigned,usage_model,current_usage(=coupons.used),global_limit(=limiter),remaining_capacity:number|"unlimited",is_multi_use_per_user,assignment_info:null|{total_assignments,assignments_with_usage,max_uses_per_user:MAX,total_possible_redemptions},public_usage_count}`.
- ERRORS: 401/403/404 coupon. DB: read coupons/assignments/usages. EVENT: none. FRONTEND: dashboard display; never treat as reservable stock (excludes reservations/claims).

## POST suggest-fix — `POST /api/v1/coupons/{id}/suggest-fix` (alias likewise)

- AUTH: Sanctum + read. BODY: `{"desired_behavior":"multi_use_per_user|single_use_per_user"*}`.
- SUCCESS 200 variants: `convert_to_assigned` (+steps+example_code) / `already_configured` / `update_assignments` / `no_change_needed` / `remove_assignments_or_set_max_uses_1` (§15). ERRORS: 400 bad behavior; 401/403/404.
- DB: read coupon + `MAX(assignments.max_uses)`. EVENT: none (recommendations only — admin applies manually). FRONTEND: render steps; never expect mutation.

---

# 29. Frontend Quick Reference

```text
Admin reads targeting:        GET    /api/v1/coupons/{id}/targeting
Admin sees rule types:        GET    /api/v1/coupons/rules
Admin validates capacity:     POST   /api/v1/coupons/validate-configuration
Admin saves targeting:        PUT    /api/v1/coupons/{id}/targeting
Admin removes targeting:      DELETE /api/v1/coupons/{id}/targeting   (widens to public — confirm!)
Admin sees usage stats:       GET    /api/v1/coupons/{id}/usage-info
Admin wants repair advice:    POST   /api/v1/coupons/{id}/suggest-fix
(Legacy /api/v1/admin/coupons/... aliases behave identically.)
```

Correct order: **read targeting → fetch rules → (validate-configuration advisor) → PUT → re-GET verify → monitor usage-info → suggest-fix as needed**. All calls need `Authorization: Bearer` + `Accept: application/json`; PUT/POST add `Content-Type: application/json`. Handle the two 422 shapes, the missing-`data` DELETE/404, the `remaining_capacity: "unlimited"` string, and the AND=BOTH / OR=EITHER mode truth tables from §5.

---

# 30. PUSHER (BROADCAST) PAYLOAD OBJECTS (VERIFIED)

All coupon notifications send via `['database', 'fcm', 'broadcast']` (email excluded; `toMail()` dormant).
`toBroadcast()` = `new BroadcastMessage($this->toDatabase(...))` — **Pusher payload is identical to the stored database payload**.
Private channel `users.{userId}` (owner only); Pusher event = `broadcastType()`; queue `high`.

## 30.1 `coupon.assigned` (`UserCouponAssignedNotification`)

Sent when an assignment is created (`CouponAssigned` event → `SendUserCouponAssignedNotification`; skipped for non-`user` types).

```json
{
  "title": { "en": "...", "ar": "..." },
  "message": { "en": "... SAVE20 ...", "ar": "... SAVE20 ..." },
  "icon": "tag",
  "resource_type": "coupon",
  "resource_id": 123,
  "action_url": "/coupons/123",
  "coupon_assignment_id": 456,
  "coupon_id": 123,
  "coupon_code": "SAVE20",
  "max_uses": 2,
  "expires_at": "2026-09-30T23:59:59+00:00"
}
```

`action_url` is frontend-relative (prefix with `APP_URL_FRONTEND`). `expires_at` null = never expires. Code visible only to the owner.

## 30.2 `coupon.eligible` (`UserCouponEligibleNotification`)

Sent by the distribution plane to newly-eligible users. By design contains **no `coupon_code`, rules, metrics, or counters**.

```json
{
  "title": { "en": "...", "ar": "..." },
  "message": { "en": "... <coupon_name> ...", "ar": "... <coupon_name> ..." },
  "icon": "tag",
  "resource_type": "coupon",
  "resource_id": 123,
  "action_url": "/coupons/123",
  "coupon_id": 123,
  "tree_hash": "abc123...",
  "run_id": 42
}
```

Frontend must still POST claim/apply (authoritative revalidation); fetch the code via `GET general/coupons/mine` after claiming.

## 30.3 `coupon.used` (`UserCouponUsedNotification`)

Sent on payment-success consumption (`AssignedCouponConsumed` event).

```json
{
  "title": { "en": "...", "ar": "..." },
  "message": { "en": "... SAVE20 ...", "ar": "... SAVE20 ..." },
  "icon": "tag",
  "resource_type": "coupon",
  "resource_id": 123,
  "action_url": "/coupons/123",
  "coupon_id": 123,
  "coupon_code": "SAVE20",
  "order_id": 987,
  "remaining_uses": 1,
  "consumed_at": "2026-09-23T10:00:00+00:00"
}
```

## 30.4 `coupon.available` (`UserCouponAvailableNotification`)

Sent on coupon creation (`CouponCreated` event).

```json
{
  "title": { "en": "...", "ar": "..." },
  "message": { "en": "... SAVE20 ...", "ar": "... SAVE20 ..." },
  "icon": "tag",
  "resource_type": "coupon",
  "resource_id": 123,
  "action_url": "/coupons/123",
  "coupon_id": 123,
  "coupon_code": "SAVE20",
  "coupon_type": null
}
```

Note: `coupon_type` reads `$coupon->type ?? null` but `coupons` has no `type` column, so it is always null (stale field, documented not fixed).
