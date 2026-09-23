# COUPON TARGETING + ASSIGNMENT — COMPLETE API & ARCHITECTURE DISCOVERY

> Read-only investigation. No code, migration, route, test, config, or doc modified.
> Implementation is authoritative; where docs/comments disagree, implementation wins (marked explicitly).

---

# PART 1 — SYSTEM MAP

## Actual structure (VERIFIED)

```text
coupons (1)
 ├── coupon_assignments (0..N) — per-user grants: max_uses, used, expires_at
 │     └── coupon_assignment_usages (0..N per assignment) — per-order consumption receipts
 ├── coupon_targetings (0..1, UNIQUE(coupon_id)) — mode + require_claim + max_claims + claim_ttl_hours + rule_tree(JSON)
 ├── coupon_claims (0..N) — activations: ACTIVE/EXPIRED/REDEEMED + eligibility_snapshot
 ├── coupon_reservations (0..N) — 30-min payment holds, UNIQUE(order_id)
 ├── coupon_usages (0..N, UNIQUE(coupon_id,user_id)) — permanent public-path consumption
 ├── coupon_product (M..N) — product-eligibility gate
 └── distribution plane (runs/recipients/user_states/outbox/event_logs) — async fan-out, not quota
```

| Concept | Stored where | Relation | Independent? |
|---|---|---|---|
| Assignment (grant + quota) | `coupon_assignments` | FK `coupon_id → coupons`, `user_id → users`; UNIQUE(coupon,user) | Independent data; evaluated by BOTH engine and orchestrator/validator |
| Targeting mode | `coupon_targetings.mode` enum `assignment/dynamic/assignment_and_dynamic/assignment_or_dynamic` | HasOne from coupon; UNIQUE(coupon_id) | Independent switch selecting which authority applies |
| Dynamic rules | `coupon_targetings.rule_tree` JSON (leaf/group, §6 of companion guide) | Same row as mode | Independent data; read only when mode includes dynamic |
| Claim policy | same row: `require_claim, max_claims, claim_ttl_hours` | Same row | Orthogonal to mode (any mode can require claim) |

Assignment and Targeting are **independent concepts that intersect at evaluation time**: assignments are the *grant store*; mode is the *gate selector*; rule_tree is the *segment predicate*. Neither contains the other. Overlap exists only in that `has_assignment` leaf rule and assignment-mode evaluation read the same table — deliberate parity (P2-1), not duplication of storage.

---

# PART 2 — ALL TARGETING MODES

Implementation: `EligibilityEngine::evaluate` (engine truth) + `CouponOrchestrator::validate` (checkout/apply truth, adds claim gate, static gates, assignment-validator interplay) + `OrderService::recordCouponUsage` (payment truth, consumption path). All three agree on the four modes (details below).

## assignment

- Meaning: only a holder of a **usable** assignment qualifies. `rule_tree` is stored but **ignored at runtime** (engine returns before reading it).
- Data required: assignment row for this user with `expires_at` null/future AND `used < max_uses`.
- Logic: `assigned_usable(user)` (exists ∧ ¬expired ∧ quota left).
- Missing rows: coupon with zero assignment rows + mode=assignment → NOBODY qualifies via engine (each user gets `No assignment found`); orchestrator assignment-validator returns invalid `not_assigned` for every user once ≥1 row exists. Coupon with no targeting row at all is a different state (always-eligible legacy) — see scenarios.

## dynamic

- Meaning: only a passer of the rule tree qualifies; assignments irrelevant (orchestrator **skips** the assignment gate entirely).
- Data required: `rule_tree`; `null` = vacuously eligible (`no_rules`).
- Logic: `rulesMatch(user)` with fail-closed errors.
- Missing tree: eligible. Malformed tree (DB drift): ineligible.

## assignment_and_dynamic

- Meaning: BOTH. Verified code: `if (assignmentEligible && dynamicEligible) eligible else ineligible(merge failed)`.
- Logic: `assigned_usable(user) AND rulesMatch(user)`.
- Missing assignment → fail. Missing tree (null) → dynamic branch passes → degenerates to assignment-only (allowed by design).

## assignment_or_dynamic

- Meaning: EITHER, with one guard: the assignment path counts only if the coupon actually grants assignments AND this user holds a valid one (`$assignmentOk = valid && has_assignments`). Prevents coupons with zero rows bypassing the tree.
- Logic: `(assigned_usable(user) ∧ coupon_has_assignments) OR rulesMatch(user)`.
- Orchestrator nuance: assignment-path completions run static validation in public-bypass form + `rejectIfPubliclyUsed`; dynamic-path completions run full per-user static validation.

## Truth table (VERIFIED; "Assigned" = usable assignment for this user)

| Assigned | Rules match | assignment | dynamic | assignment_and_dynamic | assignment_or_dynamic |
|---|---|---|---|---|---|
| YES | YES | ELIGIBLE | ELIGIBLE | ELIGIBLE | ELIGIBLE |
| YES | NO | ELIGIBLE | NOT ELIGIBLE | NOT ELIGIBLE | ELIGIBLE (assignment path) |
| NO | YES | NOT ELIGIBLE | ELIGIBLE | NOT ELIGIBLE | ELIGIBLE (dynamic path) |
| NO | NO | NOT ELIGIBLE | NOT ELIGIBLE | NOT ELIGIBLE | NOT ELIGIBLE |

Null-tree counts as "rules match". Runtime tree error counts as "rules NO" (fail-closed). Expired/exhausted assignment counts as "Assigned NO".

## Edge cases (VERIFIED)

- mode=assignment, zero rows on coupon → every user NOT ELIGIBLE (engine per-user miss). Distinct from no-targeting-row (eligible legacy).
- mode=dynamic, tree null → ELIGIBLE (all users, subject to static gates).
- AND + missing assignment → NOT ELIGIBLE. AND + null tree → assignment decides.
- OR + one side missing → other side decides (with the has_assignments guard on the assignment side).
- Both sides empty (OR + no rows + null tree): no rows → assignment path dead; null tree → dynamic passes → ELIGIBLE. AND + no rows + null tree → assignment dead → NOT ELIGIBLE.
- Unauthenticated user: `CouponOrchestrator` skips user gates (`validate(coupon,null,items)` static only); claim/apply endpoints themselves require auth (401 before logic). Engine never called without a user.
- Authenticated, unassigned, mode=assignment → `not_assigned`/`No assignment found`.
- Assigned-usable + rules fail + AND → NOT ELIGIBLE; + OR → ELIGIBLE.
- Rules pass + unassigned + AND → NOT ELIGIBLE; + OR → ELIGIBLE.
- Expired (`expires_at` past) / quota-exhausted (`used>=max_uses`) / soft-deleted (hard delete only; no soft-delete column) assignment → treated as NOT assigned everywhere (engine, leaf rule, validator agree). No "inactive" flag exists; expiry+quota are the only states. Deleted assignment → row absent.

---

# PART 3 — ASSIGNMENT API

Base path (VERIFIED, `packages/marvel/src/Rest/Routes.php:273-279`, inside `auth:sanctum,throttle:admin,lang` group): `/api/v1/coupons/{coupon}/assignments` with route-model param `{coupon}` (no `whereNumber` constraint here — controller casts `(int)`).

Permissions are **route + constructor middleware** using `Marvel\Enums\Permission` constants (VERIFIED distinct from targeting's in-controller checks):

- list/show: `view-coupon-assignments`
- store: `create-coupon-assignment`
- update: `update-coupon-assignment`
- destroy: `delete-coupon-assignment`

## 3.1 `GET /api/v1/coupons/{coupon}/assignments` — list

- Controller: `Marvel\Http\Controllers\CouponAssignmentController::index`. Repo: `CouponAssignmentRepository::listByCoupon`.
- Auth: Sanctum + `view-coupon-assignments` (403 without).
- Query: `?limit=` (default 15). Request body: none.
- Success 200 envelope `{status:200,message,success:true,data:{data:[Assignment...],current_page,from,last_page,per_page,to,total}}` (paginated wrapper inside `data`).
- Errors: 401; 403; coupon-missing → 404 `NOT_FOUND` (caught Exception → 404; note: any repo exception maps to 404 here).
- DB: READ `coupon_assignments WHERE coupon_id` + `user` eager load. No writes/events.

## 3.2 `POST /api/v1/coupons/{coupon}/assignments` — grant

- Controller `store` ← `CouponAssignmentRequest` (validated) ← `CouponAssignmentRepository::assignCoupon` → `event(new CouponAssigned($assignment))` AFTER commit.
- Request (VERIFIED, no invented fields):

```json
{ "user_id": 55, "max_uses": 2, "expires_at": "2026-09-30T23:59:59Z" }
```

| Field | Req | Type | Rules | Meaning |
|---|---|---|---|---|
| `user_id` | YES | integer | `required\|integer\|exists:users,id` | grantee |
| `max_uses` | YES | integer | `required\|integer\|min:1` | per-user quota |
| `expires_at` | no | datetime | `nullable\|date\|after:now` | grant expiry; null = never |

- Success 201: `data = {id,coupon_id,user_id,user:{id,name,email},max_uses,used:0,remaining:max_uses,is_expired:false,assigned_at,expires_at}` (`CouponAssignmentResource`; `user` present because `load('user')`).
- Errors: 422 validation (`{message,errors}` via `failedValidation`); 404 coupon missing; **409** `COUPON_ALREADY_ASSIGNED_TO_USER` on duplicate (exists-check + UNIQUE(coupon,user) arbiter mapped from SQLSTATE 23000); 400 `SOMETHING_WENT_WRONG` catch-all; 401/403.
- DB: INSERT `coupon_assignments (coupon_id,user_id,max_uses,expires_at,used=0,assigned_at=now)`. Event: `CouponAssigned` → `SendUserCouponAssignedNotification` (DB+FCM+broadcast, queue high; email NOT sent — see companion guide §22/contract §6).

## 3.3 `GET /api/v1/coupons/{coupon}/assignments/{assignment}` — show

- `show` → `findAssignment(couponId,assignmentId)` (scoped `WHERE coupon_id AND id`, with user). 200 single resource; cross-coupon id → 404. DB read only.

## 3.4 `PUT /api/v1/coupons/{coupon}/assignments/{assignment}` — update quota/expiry

- `update` ← `UpdateCouponAssignmentRequest`: `max_uses: sometimes|integer|min:1`; `expires_at: nullable|date|after:now`. Only these two keys applied (`$allowed` whitelist).
- Business rule: `max_uses < used` → **422** `MAX_USES_BELOW_USED_COUNT`. Success 200 updated resource. Cross-coupon → 404. 409 never here (only 422 business code).
- DB: UPDATE row (`max_uses`/`expires_at`). No events, no quota consumption, no claim changes.

## 3.5 `DELETE /api/v1/coupons/{coupon}/assignments/{assignment}` — revoke

- `destroy` → `removeAssignment`: `lockForUpdate` row, if `used>0` → **409** `CANNOT_DELETE_ASSIGNMENT_WITH_USAGE`, else hard DELETE (no soft-delete column exists).
- Success 200 no `data`. 404 cross-coupon/missing. DB: DELETE row. No events, no cascade beyond FK-owned usages (assignment_usages FK behavior per migration — child receipts reference assignment; deleting a zero-use grant has no receipts by construction).

---

# PART 4 — TARGETING API

Covered in depth in `COUPON_TARGETING_CONFIGURATION_API_DEEP_GUIDE.md`; exact contract restated here without invention.

Canonical: `/api/v1/coupons/{id}/targeting` (`whereNumber`), legacy alias `/api/v1/admin/coupons/{id}/targeting`. Auth Sanctum; **read** needs `view-coupons|update-coupon|create-coupon` (in-controller), **write** needs `update-coupon|create-coupon`.

| Method | Controller@method | Request class | Body | Success | DB | Event |
|---|---|---|---|---|---|---|
| GET | `App\Http\Controllers\Api\Admin\CouponTargetingController@show` | none | none | 200 `CouponTargetingResource{id,coupon_id,mode,require_claim,max_claims,claim_ttl_hours,rule_tree,created_at,updated_at}`; 404 `COUPON_NO_TARGETING` if no row | R coupons+targetings | none |
| PUT | `...@upsert` | `App\Http\Requests\Coupon\UpsertTargetingRequest` | `mode*∈4; require_claim*bool; max_claims?1–1M|null; claim_ttl_hours?1–8760|null; rule_tree?null\|leaf\|group(depth≤10, fail-closed)` → 422 either Laravel shape (field) or `{data.errors[]}` (tree) | 200 updated resource | `updateOrCreate` REPLACE in TX | `CouponTargetingChanged` → distribution run / skip |
| DELETE | `...@destroy` | none | none | 200 no data; 404 if no row | DELETE row → always-eligible | same event (run skips as non-distributable) |

Related admin helpers (same permission family, read-only): `GET rules` (static 17-rule catalog + grammar), `POST validate-configuration` (`coupon_type/limiter/max_uses_per_user` capacity advisor), `GET {id}/usage-info` (used/limiter/assignment aggregates), `POST {id}/suggest-fix` (`desired_behavior` advisor). Full shapes in companion guide §§12–15.

---

# PART 5 — ASSIGNMENT VS MODE SCENARIOS (traced)

Setup: coupon created (`POST /api/v1/coupons`, Marvel `CouponController@store`), Ahmed assigned (`POST .../assignments {user_id:Ahmed,max_uses:N}`).

## A — Assign Ahmed, NO targeting row

- Engine: `targeting=null` → **eligible** (`no_targeting`) for everyone incl. Ahmed.
- Orchestrator: `$mode='assignment'` fallback BUT `CouponAssignmentValidator` sees rows exist → requires Ahmed's row: Ahmed (usable) passes; stranger gets `not_assigned`. So: Ahmed CAN use (assignment gate passes, static gates apply); unassigned users CANNOT (validator blocks despite engine-eligible). Claim: `claim()` throws `noTargeting` (no row) — claim impossible; `require_claim` unenforceable without a row. Net: assignment-restricted public-style coupon, no claim flow.

## B — + `mode=assignment`

- Engine: Ahmed usable → eligible; stranger → ineligible. Tree (if stored) ignored.
- Orchestrator: same validator gate → Ahmed passes; stranger `not_assigned`. Claim available iff `require_claim=true` (claim runs `evaluate()` → assignment check → ACTIVE created).
- Change vs A: targeting row now exists → claim flow possible, mode explicit, distribution skips (non-distributable), semantics otherwise identical for this population. The row is the **switch that names the authority**.

## C — + `mode=dynamic` (tree e.g. spend≥1000)

- Engine: assignments ignored; spend≥1000 decides for EVERYONE.
- Orchestrator: assignment gate **skipped**; Ahmed with spend 500 → `not_eligible`; stranger with spend 1500 → passes (then static gates). Ahmed's grant is inert.
- Assignments still affect capacity reporting (`isPublic()=false`, usage-info assigned view) and payment path choice (assignment row present → assigned-path consumption with `Assignment FOR UPDATE`), but NOT eligibility.

## D — + `mode=assignment_and_dynamic`, rule spend≥1000

- Ahmed+1500 → ELIGIBLE. Ahmed+500 → NOT (tree fails). Stranger+1500 → NOT (no usable assignment). Stranger+500 → NOT. Claim (if required) enforces the same AND before creating ACTIVE.

## E — + `mode=assignment_or_dynamic`, rule spend≥1000

- Ahmed+1500 → ELIGIBLE (both). Ahmed+500 → ELIGIBLE (assignment path). Stranger+1500 → ELIGIBLE (dynamic path). Stranger+500 → NOT. (Assignment path additionally requires coupon-has-rows, true here.)

---

# PART 6 — IS `assignment` MODE REDUNDANT?

## Evidence NOT redundant

1. Without a targeting row, `claim()` is impossible (`throw noTargeting`) and `require_claim/max_claims/TTL` cannot be expressed — the mode row carries the claim policy, not just the gate name. (`CouponClaimService::claim` lines 47–58.)
2. Engine's no-targeting branch returns eligible for everyone; the assignment restriction for strangers in scenario A comes from the orchestrator's validator, NOT the engine. Mode=assignment makes the ENGINE itself assignment-authoritative (consistent `no_targeting` vs `has_assignment` distinction, unit-tested).
3. Distribution plane keys off the row: `affectedCoupons`/candidate selection filter `whereHas('targeting', mode∈dynamic-family)`; assignment-mode coupons are deliberately non-distributable (skip path). No row / wrong mode changes fan-out.
4. Mode selects orchestrator branches with different static-validation paths (`validate(coupon,null,items)` vs `validate(coupon,user,items)` + `rejectIfPubliclyUsed`), i.e., mode affects more than the boolean outcome.

## Evidence IS redundant (narrowly)

1. For the pure "only assigned users" outcome with no claim policy, scenario A (rows, no row-mode) already blocks strangers at the orchestrator via `CouponAssignmentValidator`, so persisting `mode=assignment` changes the engine verdict but not the end-user outcome for that population.
2. `Coupon.isPublic()`/`getUsageDescription()`/`isMultiUsePerUser()` derive public/assigned SOLELY from assignment-row existence, ignoring mode entirely — two sources of truth for "is this coupon assigned".

## Consequences

- Rows exist + mode=dynamic → grants inert for eligibility (still visible in reporting + payment path).
- mode=assignment + zero rows → NOBODY eligible (strictest state); distinct from no-row (legacy eligible). A coupon can thus be saved in a state that can never execute successfully — flagged in Part 12.

## Classification

```text
NOT REDUNDANT
```

The mode is the gate selector + claim-policy carrier + distribution key; assignment rows are the grant store. Same-table reads (`has_assignment` parity) are deliberate consistency, not duplication. The narrow outcome-overlap in scenario A/B does not remove the mode's load-bearing roles (claim enablement, engine authority, distribution routing, orchestrator branching).

---

# PART 7 — COMPLETE ADMIN FLOW (only steps that exist)

1. **Create coupon** — `POST /api/v1/coupons` (Marvel `CouponController@store`, permission-gated) → INSERT `coupons` (code normalized/canonical, slug server-managed, CP-05 constraints fail-closed). Response: coupon resource (discount fields + limiter/used/status/dates).
2. **Configure targeting** — `PUT /api/v1/coupons/{id}/targeting {mode,require_claim,max_claims,claim_ttl_hours,rule_tree}` → UPSERT `coupon_targetings` + `CouponTargetingChanged` → distribution run/skip. Verify with GET targeting; catalog from GET rules.
3. **(Optional) capacity advice** — `POST validate-configuration`, `GET usage-info`, `POST suggest-fix` (read-only).
4. **Assign users** — `POST /api/v1/coupons/{coupon}/assignments {user_id,max_uses,expires_at}` per user → INSERT rows + `CouponAssigned` → DB+FCM+broadcast notification (no email). Manage via list/show/PUT/DELETE assignment endpoints (update quota/expiry; delete only when `used=0`).
5. **Activate** — coupon `status=true` (via coupon update endpoint; `CouponActivated` event → distribution). Dates/limiter enforced by `CouponValidator`/scopes thereafter.
6. No "Save draft/publish targeting separately" step exists beyond PUT; no assignment ↔ targeting linkage endpoint (order free: targeting before/after assignments both work; claim requires targeting row + require_claim at claim time).

---

# PART 8 — COMPLETE CUSTOMER FLOW (traced)

```text
Lookup (byCode canonical, case-insensitive/trimmed) → Orchestrator.validate
  ├─ claim gate (require_claim → ACTIVE-unexpired required; REDEEMED → already_used)
  ├─ mode gate (engine ± assignment validator, §2) → not_eligible/not_assigned/...
  └─ static gates (CouponValidator: status/dates/limiter/used/products) → disabled/expired/...
```

- **Eligibility** (`EligibilityEngine::evaluate`, read-only): mode+tree verdict. Used by claim/apply/checkout/discovery; never consumes.
- **Claim** (`POST /api/v1/general/coupons/{id}/claim`, auth, `ClaimCouponRequest`): `CouponClaimService::claim` — Targeting FOR UPDATE lock → require_claim? → ACTIVE?/REDEEMED? → max_claims (ACTIVE+REDEEMED count) → evaluate() → INSERT ACTIVE (+expires_at, eligibility_snapshot). 201 success (`CouponClaimResource`); 409 `already_claimed|not_eligible|claim_not_required|no_targeting|max_claims_reached`; 404 coupon. No quota consumed.
- **Apply** (`POST /api/v1/general/coupons/apply {code*}`): `CouponService::addCouponToCart` → `Orchestrator::validateByCode(code,user,cart.items)` → on valid, stamp `cart.coupon=code` + return `{total_price,coupon_discount,free_shipping}`; invalid → 400 `{reason,code:COUPON_<REASON>}`; no cart → 400 `no_cart`; repeat → 200 `already_applied`. No quota consumed. `governorate_id` NOT accepted (area from saved addresses).
- **Checkout** (`POST /api/v1/general/checkout` / `fast-shipping/checkout`, auth): orchestrator revalidation on order items (authoritative, "never trust earlier results") + product-active asserts + totals/currency snapshot → order row (coupon snapshot incl. `governorate_id` for SHIPPING, not eligibility) + `CouponReservationService::reserve` (Coupon FOR UPDATE + per-order idempotent row, 30-min TTL; 422 `COUPON_RESERVATION_FAILED` when `used+active_reservations>=limiter`).
- **Reservation**: hold only; `consume` (delete) on payment success, `release` (delete) on failure/cancel. `canReserve` pre-check exists.
- **Usage** (payment success → `OrderService::recordCouponUsage`, Coupon FOR UPDATE + Assignment FOR UPDATE + idempotent `coupon_assignment_usages` guard + `coupon_consumed` flag): assigned-path (row present) → `coupons.used+1`, `assignments.used+1`, `CouponAssignmentUsage` receipt, reservation consumed, claim→REDEEMED, `AssignedCouponConsumed` event; unassigned on dynamic/OR coupons → public single-use path (`CouponUsage` UNIQUE(coupon,user) blocks repeats). Quota-exhausted / already-used raise `CouponConsumptionException` (no silent success).

Discovery aids (advisory, revalidated later): `GET general/coupons` (public valid list, codes hidden), `GET general/coupons/mine` (own assignments+claims WITH codes), `GET general/coupons/available` (engine-eligible shells, never codes/rules/counters).

---

# PART 9 — REQUEST/RESPONSE CATALOG

Envelope everywhere (admin + customer): `{status,message,success,data?}` (`data` omitted when empty; assignment-validation failures use `{message,errors}` 422 shape instead).

## Assignment

### `GET /api/v1/coupons/{coupon}/assignments?limit=15`
Req: none. Success 200 paginated `data{data[],current_page,from,last_page,per_page,to,total}`. AuthZ 401/403 (`view-coupon-assignments`). 404 coupon/other failure.

### `POST /api/v1/coupons/{coupon}/assignments`
Req: `{"user_id":55*,"max_uses":2*,"expires_at":"2026-09-30T23:59:59Z"}`. Success 201 assignment resource (§3.2). Validation 422 `{message,errors}`. Business 409 `COUPON_ALREADY_ASSIGNED_TO_USER`. 404 coupon. 401/403 (`create-coupon-assignment`).

### `GET /api/v1/coupons/{coupon}/assignments/{assignment}`
Req: none. Success 200 resource. 404 cross-coupon/missing. 401/403 (view).

### `PUT /api/v1/coupons/{coupon}/assignments/{assignment}`
Req: `{"max_uses":3,"expires_at":null}` (both optional; only these applied). Success 200 resource. Validation 422. Business 422 `MAX_USES_BELOW_USED_COUNT`. 404. 401/403 (update).

### `DELETE /api/v1/coupons/{coupon}/assignments/{assignment}`
Req: none. Success 200 no data. Business 409 `CANNOT_DELETE_ASSIGNMENT_WITH_USAGE`. 404. 401/403 (delete).

## Targeting (+ helpers)

### `GET /api/v1/coupons/{id}/targeting`
Req: none. Success 200 targeting resource. 404 `COUPON_NO_TARGETING` / coupon-404. 401/403 (read).

### `PUT /api/v1/coupons/{id}/targeting`
Req: `{"mode"*,"require_claim"*,"max_claims"?,"claim_ttl_hours"?,"rule_tree"?}` (§4). Success 200 resource. Validation 422 (dual shapes). 401/403 write / 404 coupon.

### `DELETE /api/v1/coupons/{id}/targeting`
Req: none. Success 200 no data (widens to always-eligible). 404 variants. 401/403 write.

### `GET /api/v1/coupons/rules`
Req: none. Success 200 `{rules[17],rule_tree grammar}`. 401/403.

### `POST /api/v1/coupons/validate-configuration`
Req: `{"coupon_type"*,"limiter"?,"max_uses_per_user"?}`. Success always-200 `{valid,errors,warnings,recommendations}`. 422 bad enum/range. 401/403.

### `GET /api/v1/coupons/{id}/usage-info`
Req: none. Success 200 metrics object (§14 prior guide). 404 coupon. 401/403.

### `POST /api/v1/coupons/{id}/suggest-fix`
Req: `{"desired_behavior"*}`. Success 200 variant shapes. 400 bad behavior. 404 coupon. 401/403.

## Customer (runtime)

### `POST /api/v1/general/coupons/{id}/claim` (auth)
Req: none (id in path). Success 201 claim resource. Business 409 `{reason:already_claimed|not_eligible|claim_not_required|no_targeting|max_claims_reached}`. 404 coupon. 401.

### `POST /api/v1/general/coupons/apply` (auth)
Req: `{"code":"SAVE20"*}`. Success 200 `{total_price,coupon_discount,free_shipping}` or `already_applied`. Business 400 `{reason,code:COUPON_<REASON>}`. 401/422-validation.

### `POST /api/v1/general/checkout` (auth)
Req: cart/checkout fields incl. shipping `governorate_id` (shipping only). Coupon outcome observed via `order.coupon` null-vs-set (strip = silent rejection `COUPON_NO_LONGER_ELIGIBLE` client-side); reservation failure 422. Full field set OUT OF SCOPE here (checkout doc owns it).

---

# PART 10 — FRONTEND CONTRACT

| Task | Endpoint | Notes |
|---|---|---|
| Create assignment | `POST /api/v1/coupons/{coupon}/assignments` | one call per user; 409 = already granted |
| Remove assignment | `DELETE .../assignments/{assignment}` | only when `used=0` else 409 |
| Update quota/expiry | `PUT .../assignments/{assignment}` | `max_uses`/`expires_at` only; cannot lower below `used` |
| Configure targeting AND rules | `PUT /api/v1/coupons/{id}/targeting` | single endpoint for mode + claim policy + tree (no separate rules endpoint) |
| Retrieve configuration | `GET .../targeting` + `GET .../assignments` + `GET .../usage-info` | three reads; rules catalog from `GET .../rules` |
| Know if customer eligible | **no direct endpoint** — infer via `GET general/coupons/available` (advisory) or attempt Claim/Apply | engine is server-internal; never exposed per-user to admin UI |
| Apply coupon (customer) | `POST /api/v1/general/coupons/apply {code}` | preview only |
| Activate (customer) | `POST /api/v1/general/coupons/{id}/claim` | requires targeting+require_claim |
| Consume (customer) | checkout + payment callback | frontend never calls usage directly |

Admin vs customer split: targeting/assignment/config = admin permission-gated (codes + trees visible); customer sees codes only for owned grants (`mine`), never trees/counters.

---

# PART 11 — DATABASE MODEL

| Table | Key columns (type/null/default) | Meaning / constraints |
|---|---|---|
| `coupons` | `id PK; code UNIQUE(normalized); slug UNIQUE; discount, discount_type(percentage\|fixed_rate\|free_shipping), max_discount_amount; limiter NULL=unlimited; used DEFAULT 0 (system-only); status bool; start/end_date NULL; timestamps` | discount + capacity definition. `used` incremented only in `recordCouponUsage`. |
| `coupon_assignments` | `id PK; coupon_id FK→coupons CASCADE; user_id FK→users CASCADE; max_uses UINT DEFAULT 1; used UINT DEFAULT 0; assigned_at DEFAULT now; expires_at NULL; UNIQUE(coupon_id,user_id)` (migration `2026_07_15_000003`) | per-user grants. No soft-delete/inactive flag. |
| `coupon_assignment_usages` | `coupon_assignment_id FK; order_id FK NON-NULL (2026_09_11_000002); used_at; CHECK constraint (…_000003)` | per-order consumption receipts; idempotency guard. |
| `coupon_targetings` | `id PK; coupon_id FK→coupons CASCADE UNIQUE; mode ENUM(assignment,dynamic[,+combined 2026_09_14_000003]); require_claim BOOL DEFAULT false; max_claims NULL (renamed …_000004); claim_ttl_hours NULL (…_000002); rule_tree JSON NULL; timestamps; INDEX(require_claim)` | 0..1 gate+policy+predicate per coupon. |
| `coupon_claims` | `id PK; coupon_id FK CASCADE; user_id FK CASCADE; status ENUM(active,expired,redeemed) DEFAULT active (…_000001, backfilled); claimed_at DEFAULT now; expires_at NULL; redeemed_at NULL; eligibility_snapshot JSON NULL; INDEX(coupon,user,status); INDEX(user); INDEX(coupon,claimed_at)`; original UNIQUE(coupon,user) DROPPED in lifecycle migration (app-level FOR UPDATE enforcement + reconcile detector) | activations; ACTIVE+REDEEMED count toward max_claims. |
| `coupon_reservations` | `id PK; coupon_id/user_id/order_id FKs CASCADE; reserved_at; expires_at; UNIQUE(order_id); INDEX(coupon_id,expires_at)` (`2026_08_31_120100`) | 30-min holds; per-order idempotent. |
| `coupon_usages` | `coupon_id, user_id, order_id, used_at (NOT NULL = consumed); UNIQUE(coupon_id,user_id)` (enforced in code explanation; table predates targeting) | public-path permanent consumption (single-use/user). |
| `coupon_product` | `coupon_id, product_id` | product-eligibility gate (`CouponValidator`). |
| `users` | `id; email (has_email rule); created_at (registered_* rules)` | identity + profile predicates. |
| `address` | `customer_id→user; governorate_id NULL (area_in source)` | ANY-match pool. |
| `governorates` | `id; status bool (active filter)` | allowed-set intersect. |
| `customer_metrics` | `user; completed_orders; total_qualifying_order_value; first/last_order_at; coupons_used` | order/spend predicates via service. |
| `orders` | `coupon (snapshot code), coupon_discount, currency_*, governorate_id (shipping), coupon_consumed flag, user_id` | checkout snapshot + payment truth. |
| Distribution | `coupon_distribution_runs (tree_hash,dedupe_key)/_recipients/_user_states(tree_hash,state)/coupon_outbox/coupon_event_logs` | async fan-out; no quota authority. |

`targeting rules` are NOT a table — JSON inside `coupon_targetings.rule_tree`.

---

# PART 12 — INCONSISTENCIES & RISKS

1. **Two "is assigned" sources**: `isPublic()` (row existence) vs mode gate. Coupon with rows + mode=dynamic reports "assigned" in usage-info yet grants are eligibility-inert. Reporting vs enforcement divergence — document, don't merge.
2. **Savable-but-dead states**: mode=assignment + zero rows (nobody eligible); mode=dynamic + tree referencing inactive governorates only (area branch dead). No PUT-time cross-check against assignments/governorates (tree validated grammatically only). Fail-closed at runtime, but admin gets no warning.
3. **No-targeting vs assignment-mode asymmetry** (scenario A): engine says eligible, orchestrator says `not_assigned` for strangers — correct outcome via validator, but two layers disagree on the reason; log/debug confusion risk.
4. **Claim unique removed**: `coupon_claims` UNIQUE(coupon,user) dropped; safety now depends on Targeting FOR UPDATE + reconcile detector (`coupons:reconcile duplicate_active_claims`). Correct per comments, but a missed lock path would silently allow duplicates — highest-concurrency-sensitivity point (already covered by claim concurrency tests, INFERRED bodies).
5. **Assignment delete guard is `used>0` only** — expired-but-unused grants deletable (fine), exhausted grants blocked (correct), but no guard against deleting the LAST assignment of a live assignment-mode coupon (instantly strands all users until re-grant — operational footgun).
6. **No fail-open found**: unknown mode/rule/operator/malformed/empty/deep all fail-closed (validator 422 + engine ineligible). Infra exceptions bubble to 500, never masked as "invalid coupon" (F-03) — VERIFIED in orchestrator.
7. **Legacy paths**: dual URL families (canonical + `/admin` alias) both live; alias lacks `lang` entry (translation fallback NOT VERIFIED); `{coupon}` assignment param unconstricted (cast int) vs `{id}` targeting `whereNumber` — non-numeric assignment path reaches controller (404 via findOrFail) rather than route-miss. Minor, consistent outcomes.
8. **Checkout divergence risk (checked, consistent)**: apply/checkout/payment all funnel through `CouponOrchestrator::validate` + `CouponValidator`; payment adds reservation revalidation + assignment-locked consumption. No parallel eligibility copy found. `governorate_id` appears in checkout payload but provably unread by eligibility (three independent ignore-comments).
9. **Stale enum comment** (`AREA_IN` "checkout delivery governorate") contradicts engine/metadata/validator + three ignore-comments. Doc-only defect.
10. **Race coverage**: claim (parent-row lock+retry3), reservation (coupon+reservation locks+retry3), assignment create (UNIQUE arbiter), consumption (row locks + idempotent receipts). PUT/DELETE targeting intentionally lock-free (last-writer-wins + dedupe convergence) — acceptable, documented.

---

# PART 13 — SOURCE-OF-TRUTH MAP

| Concept | Source of truth | Defined where | Evaluated where |
|---|---|---|---|
| Assignment (grants+quota) | `coupon_assignments` rows | Assignment API + `CouponAssignment` model | Engine (usable gate) + `CouponAssignmentValidator` + `has_assignment` leaf + payment consumption |
| Targeting mode | `coupon_targetings.mode` | PUT targeting (`UpsertTargetingRequest`) | `EligibilityEngine::evaluate` + `CouponOrchestrator::validate` + `recordCouponUsage` mode branch |
| Dynamic rules | `coupon_targetings.rule_tree` JSON | PUT targeting + `RuleTreeValidator` | `EligibilityEngine::evaluateNode/evaluateRule` |
| Eligibility (verdict) | `EligibilityEngine` (+ orchestrator claim/static wrappers) | Engine + `CouponValidator` + `CouponAssignmentValidator` | claim/apply/checkout/available/discovery call sites |
| Claim (activation) | `coupon_claims` ACTIVE-unexpired | `CouponClaimService::claim` (locked) | orchestrator claim gate; `claimed/not_claimed` leaves |
| Reservation (hold) | `coupon_reservations` unexpired | `CouponReservationService::reserve` (locked) | checkout + payment revalidation |
| Usage (consumption) | `coupon_usages` + `coupons.used` + `assignments.used` + `coupon_assignment_usages` | `OrderService::recordCouponUsage` (locked, idempotent) | limiter checks + usage-info display |

---

# PART 14 — REAL EXAMPLES

Conventions: coupon id 123 (`SAVE20`), Ahmed id 55, Mohamed id 77. Admin token with `update-coupon + create-coupon-assignment` family. All bodies VERIFIED shapes.

## Example 1 — Assignment only

Admin:
1. `PUT /api/v1/coupons/123/targeting {"mode":"assignment","require_claim":false,"max_claims":null,"claim_ttl_hours":null,"rule_tree":null}` → 200.
2. `POST /api/v1/coupons/123/assignments {"user_id":55,"max_uses":2}` → 201 `{...,used:0,remaining:2}`.
DB: targetings(123,assignment); assignments(123,55,max2,used0).
Runtime: Ahmed → engine eligible; orchestrator validator passes; static gates apply. Mohamed → `not_assigned` everywhere (apply 400 `COUPON_NOT_ASSIGNED`, claim N/A since require_claim=false → `claim_not_required` if attempted).

## Example 2 — Dynamic only (spend ≥ 1000)

Admin: `PUT ... {"mode":"dynamic","require_claim":false,"rule_tree":{"operator":"AND","rules":[{"type":"min_total_spend","value":"1000.00"}]}}` → 200. No assignments.
DB: targetings(123,dynamic,tree); assignments none.
Runtime: spend 1500 (any user) → eligible; spend 500 → `not_eligible`. Decimal-safe `bccomp` at 2dp.

## Example 3 — AND (Ahmed assigned; rule spend≥1000)

Admin: PUT `{"mode":"assignment_and_dynamic","require_claim":false,"rule_tree":{...spend≥1000...}}` + assignment Ahmed max2.
| Case | Result (VERIFIED logic) |
|---|---|
| Ahmed + 1500 | ELIGIBLE |
| Ahmed + 500 | NOT (`not_eligible`, tree fails) |
| Mohamed + 1500 | NOT (`not_assigned` arm fails → AND fails) |
| Mohamed + 500 | NOT (both fail) |

## Example 4 — OR (same data)

| Case | Result |
|---|---|
| Ahmed + 1500 | ELIGIBLE (both) |
| Ahmed + 500 | ELIGIBLE (assignment path) |
| Mohamed + 1500 | ELIGIBLE (dynamic path) |
| Mohamed + 500 | NOT ELIGIBLE |

With `require_claim:true` added, each ELIGIBLE case must additionally POST claim first (201 ACTIVE) or apply/checkout returns `claim_required`; REDEEMED users get `already_used`.

---

# PART 15 — FINAL ANSWER

## CURRENT ARCHITECTURE SUMMARY

Coupons are discount+capacity rows. Two orthogonal admin planes attach to them: **grants** (assignment rows: who holds quota) and **gates** (one targeting row: which authority decides + must-users-claim + segment tree). At runtime the orchestrator enforces claim→mode→static order; the engine answers the mode question read-only; claim/reservation/usage mutate lifecycle state under row locks; payment completion is the sole consumption point. Distribution is an async notification fan-out keyed off targeting changes, never a quota authority.

## THE FOUR MODES

| Mode | Assignment used? | Dynamic rules used? | Logic |
|---|---|---|---|
| assignment | YES (sole authority; tree ignored) | NO | `assigned_usable(user)` |
| dynamic | NO (gate skipped) | YES (`null`=pass) | `rulesMatch(user)` |
| assignment_and_dynamic | YES | YES | `assigned_usable(user) AND rulesMatch(user)` |
| assignment_or_dynamic | YES (only if coupon has rows AND user holds usable one) | YES | `(assigned_usable(user) ∧ has_rows) OR rulesMatch(user)` |

## KEY FINDING

> Why does the system have a separate Assignment API AND an `assignment` Targeting Mode?

Because they are different architectural layers, proven by code: the **Assignment API manages the grant store** (`CouponAssignmentRepository::assignCoupon/update/remove`, UNIQUE(coupon,user), quota/expiry, `CouponAssigned` notifications) while **`mode=assignment` selects the enforcement authority and carries the claim policy** (`coupon_targetings` row gates the engine, enables `require_claim/max_claims/TTL` without which `claim()` throws `noTargeting`, routes distribution skip vs fan-out, and switches orchestrator branches). Rows without the mode cannot express claim-first flows; the mode without rows enforces "nobody eligible" (fail-closed). The `has_assignment` parity reads are the intentional bridge, not duplication.

## RECOMMENDATION (review before any implementation; no code change made)

1. Decide whether to add PUT-time cross-warnings (mode=assignment + zero assignments; dynamic tree referencing only inactive governorates; AND + null tree degenerating to assignment-only) — currently savable-but-dead, runtime fail-closed, admin unwarned.
2. Decide whether `isPublic()`-family reporting should consider mode (currently row-existence only) to close the "assigned-labeled but dynamic-gated" reporting gap.
3. Keep claim/assignment lock discipline untouched; any future per-user targeting introspection endpoint must reuse `EligibilityEngine` (never duplicate its logic) and must not expose trees/counters to customers.
4. Fix the stale `AREA_IN` enum comment to saved-address semantics; confirm legacy-alias `lang` behavior if admin UI uses alias URLs in Arabic.
5. Add/confirm HTTP regression tests for PUT/DELETE targeting + usage-info + suggest-fix (code-verified here, test bodies not all inspected).

---

# EVIDENCE INDEX

- FILES INSPECTED: CouponTargetingController, CouponConfigurationController, CouponRulesController, UpsertTargetingRequest, CouponAssignmentController, CouponAssignmentRequest, UpdateCouponAssignmentRequest, CouponAssignmentRepository, RuleTreeValidator, CouponRuleMetadata, EligibilityRuleType, Eligibility/EligibilityEngine, CouponClaimService, CouponAssignmentValidator, CouponValidator, CouponOrchestrator, CouponReservationService, CouponService (General), CouponController (General), OrderService (recordCouponUsage excerpt), Coupon/CouponAssignment/CouponTargeting models, CouponAssignmentResource/CouponTargetingResource/CouponClaimResource(ref), DistributionTriggerService, DistributionService(excerpt), StartCouponDistribution, EventServiceProvider(excerpt), ApiResponse trait, CouponLifecycleEvent/CouponTargetingChanged, Routes (Marvel + api.php), COUPON_API_CONTRACT.md §§3–13, migrations listed in Part 11.
- ROUTES INSPECTED: Marvel Routes.php:273–292 (assignments CRUD + rules + apiResource coupons + config helpers), routes/api.php:122–127 (customer claim/apply/mine/available), :196–206 (legacy admin alias), :48–72 (public coupon list).
- CONTROLLERS INSPECTED: Admin CouponTargetingController/CouponConfigurationController/CouponRulesController; Marvel CouponAssignmentController + CouponController (store/show, referenced); General CouponController (apply/claim/mine/available).
- REQUESTS/VALIDATORS INSPECTED: UpsertTargetingRequest, CouponAssignmentRequest, UpdateCouponAssignmentRequest, ClaimCouponRequest (referenced via signature), RuleTreeValidator.
- SERVICES INSPECTED: EligibilityEngine, CouponOrchestrator, CouponClaimService, CouponAssignmentValidator, CouponValidator, CouponReservationService, CouponService, OrderService (usage path), DistributionService/TriggerService/Outbox (fan-out path), CustomerMetricsService (via engine ctor), AvailableCouponsService (referenced).
- REPOSITORIES INSPECTED: CouponAssignmentRepository (full), CouponRepository (referenced for F-04 whitelist).
- MODELS INSPECTED: Coupon, CouponAssignment, CouponAssignmentUsage (ref), CouponTargeting, CouponClaim (via service/migration), CouponUsage (via validator/orchestrator), CouponReservation (App\Models), Address, Governorate, User, CustomerMetrics, Order (ref).
- RESOURCES INSPECTED: CouponTargetingResource, CouponAssignmentResource (full bodies).
- DATABASE TABLES INSPECTED: coupons, coupon_assignments, coupon_assignment_usages, coupon_targetings, coupon_claims, coupon_reservations, coupon_usages, coupon_product, users, address, governorates, customer_metrics, orders, distribution runs/recipients/user_states/outbox/event_logs (via migrations + services).
- RUNTIME ELIGIBILITY PATHS INSPECTED: EligibilityEngine.evaluate/evaluateAssignmentMode/evaluateDynamicMode/evaluateCombinedMode/evaluateNode/evaluateRule (+17 eval fns, area_in excerpt verified); CouponOrchestrator.validate (claim/mode/static branches); CouponClaimService.claim (locked); CouponService.addCouponToCart; OrderService.recordCouponUsage (locked consumption + reservation revalidation).
- NOTIFICATIONS/PUSHER INSPECTED: UserCouponAssignedNotification, UserCouponEligibleNotification, UserCouponUsedNotification, UserCouponAvailableNotification (toDatabase/toBroadcast/broadcastType), SendUserCouponAssignedNotification (+Used/Available listeners), routes/channels.php.

---

# APPENDIX A — PUSHER (BROADCAST) PAYLOAD OBJECTS (VERIFIED)

All coupon notifications use channels `['database', 'fcm', 'broadcast']` (email excluded by business decision; `toMail()` dormant).
`toBroadcast()` returns `new BroadcastMessage($this->toDatabase(...))` — **the Pusher payload is byte-identical to the `toDatabase` payload**.
Private channel: `users.{userId}` (owner only, `routes/channels.php`: `(int)$user->id === (int)$id`). Pusher event name = `broadcastAs()` = `broadcastType()` below. Queue: `high`.

## A.1 `coupon.assigned` — `UserCouponAssignedNotification`

Fired from: `CouponAssignmentRepository::assignCoupon` → `event(new CouponAssigned)` → `SendUserCouponAssignedNotification` (skips non-`user` types) → `notify()`.
`databaseType = broadcastType = 'coupon.assigned'`.

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

Field notes (VERIFIED): `title`/`message` resolved via `notifications.coupon.assigned.*` lang keys (en+ar); `message` interpolates `coupon_code`; `action_url` is a frontend-relative path (frontend must prefix with `APP_URL_FRONTEND`, NOT backend host); `expires_at` null when grant never expires; `coupon_code` exposed ONLY to the owner (needed for Apply).

## A.2 `coupon.eligible` — `UserCouponEligibleNotification`

Fired from: distribution plane (`NotificationRequestHandler` for newly-eligible users after targeting changed / triggers). Confidentiality by design: **never carries `coupon_code`, rules, metrics, counters, or other-user data**.

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

Frontend: this is a "you may qualify, go claim" nudge — MUST still POST claim/apply (authoritative revalidation); never show a code from this payload (none present).

## A.3 `coupon.used` — `UserCouponUsedNotification`

Fired from: payment-success consumption (`AssignedCouponConsumed` event → `SendUserCouponUsedNotification`).

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

## A.4 `coupon.available` — `UserCouponAvailableNotification`

Fired from: `CouponCreated` event → `SendUserCouponAvailableNotification`.

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

Note: `coupon_type` reads `$coupon->type ?? null` — `coupons` table has NO `type` column (VERIFIED: fillable/discount fields only), so this is **always null** in practice (stale field, documented here, not fixed per read-only rule).

## A.5 Frontend Pusher wiring (VERIFIED)

- Subscribe: private channel `users.{myUserId}` (auth: owner only); event names `coupon.assigned`, `coupon.eligible`, `coupon.used`, `coupon.available`.
- Payload = the JSON above (no envelope wrapper at notification level; Pusher adds its own transport envelope).
- `action_url` values are relative (`/coupons/{id}`) — prefix with frontend host.
- `coupon.eligible` contains NO code by design; fetch the code afterwards via `GET general/coupons/mine` (owner-scoped) after a successful claim.

---

# APPENDIX B — WHAT RABBITMQ SENDS (VERIFIED)

> Correction to a common misunderstanding: **RabbitMQ never sends Pusher payloads.**
> RabbitMQ carries internal *distribution work envelopes* (identifiers only) between backend workers.
> The Pusher object (Appendix A) is built at the very end by `NotificationRequestHandler` line 97
> (`$user->notify(new UserCouponEligibleNotification(...))`) — after the RabbitMQ journey finishes.

## B.1 Topology (VERIFIED — `RabbitMqTopology`, the single source of truth)

- Exchange: `coupon.events` (topic; configurable via `rabbitmq.exchange`). DLX: `coupon.dlx`.
- Work queues (3) + bindings (event type IS the routing key):
  - `coupon.distribution` ← `coupon.distribution.start`, `coupon.distribution.chunk`
  - `coupon.evaluation` ← `coupon.user.evaluate`
  - `coupon.notifications` ← `coupon.notification.requested`
- Each work queue has `<queue>.retry` (TTL) + `<queue>.dlq`. Other event types (observations like `became_eligible`, `notification.sent`) are published for observability/tracing — no queue bound.
- Retry: `maxAttempts` default 5 per consumer; backoff via `retryDelayFor` (first retry waits first configured delay, default 30s, last value repeats). Malformed messages → poison → DLQ, never business processing (`fromArray` strict validation: UUID event/correlation ids, known event type, version = 1).
- Transport: `RabbitMqCouponEventTransport` publishes `envelope->toJson()` to the exchange with the event type as routing key; outbox pattern (business rows + outbox row commit atomically, publisher relays afterwards, `outbox_max_attempts` default 25).

## B.2 The envelope — EVERY RabbitMQ message looks like this (VERIFIED — `CouponEventEnvelope::toArray`)

```json
{
  "event_id": "uuid (v4)",
  "event_type": "coupon.distribution.start",
  "version": 1,
  "occurred_at": "2026-09-23T10:00:00+00:00",
  "published_at": "2026-09-23T10:00:01+00:00",
  "correlation_id": "uuid (whole run shares one)",
  "causation_id": "uuid of parent event or null",
  "aggregate_type": "coupon",
  "aggregate_id": 123,
  "user_id": 55,
  "distribution_run_id": 42,
  "tree_hash": "sha of mode+rule_tree or null",
  "attempt": 1,
  "payload": { "...per-event object below..." }
}
```

Rules (VERIFIED): `event_id`/`correlation_id` must be UUIDs; `event_type` must be a known `CouponDistributionEvents` value; `version` must be 1; `payload` must be an object. **Confidentiality rule (class docblock): NO coupon codes, NO rules, NO metrics, NO PII in the payload — identifiers only; consumers load state from MySQL.** Child events inherit `correlation_id` and point `causation_id` at the parent (`derive()`), building a traceable graph.

## B.3 The four work payloads (exact keys each handler requires/sends)

### 1. `coupon.distribution.start` (→ `coupon.distribution` queue)

Sent by: `DistributionService::startDistribution` (after PUT/DELETE targeting → `CouponTargetingChanged` → `StartCouponDistribution`, or coupon activation / per-user triggers). Non-distributable coupons (no targeting / assignment-only mode) throw BEFORE any message.

```json
"payload": {
  "run_id": 42,
  "coupon_id": 123,
  "tree_hash": "abc123...",
  "trigger": "targeting_changed",
  "trigger_scope": "activation",
  "audience_cap": 10000
}
```

`trigger` ∈ `CouponDistributionTriggerType` values (`coupon_activated`, `targeting_changed`, `manual`, per-user triggers…); `trigger_scope` = `"activation"` for coupon-level or `"user:{id}"` for single-user fan-out; `audience_cap` default 10000. Run dedupe key = `coupon_id:tree_hash:trigger:scope`.

### 2. `coupon.distribution.chunk` (→ `coupon.distribution` queue)

Sent by: `DistributionStartHandler` (one per audience chunk, ≤500 users by `chunk_size`). Refuses stale runs (`tree_hash` drift → exception, no fan-out).

```json
"payload": {
  "run_id": 42,
  "coupon_id": 123,
  "tree_hash": "abc123...",
  "user_ids": [55, 77, 91],
  "chunk_index": 0
}
```

### 3. `coupon.user.evaluate` (→ `coupon.evaluation` queue)

Sent by: `DistributionChunkHandler` (one per user; requires `run_id, coupon_id, user_ids` in the chunk).

```json
"payload": {
  "run_id": 42,
  "recipient_id": 777,
  "coupon_id": 123,
  "user_id": 55,
  "tree_hash": "abc123..."
}
```

Handled by: `UserEvaluateHandler` → live-check (disabled/expired mid-flight stops silently) → `EligibilityEngine::evaluate` → `EligibilityTransitionService` → emits observations (`became_eligible` + `evaluation.completed`) and, for newly eligible users only, message 4. Duplicates converge via `NOTIFIED`/`NOTIFY_PENDING` state + same-hash dedupe.

### 4. `coupon.notification.requested` (→ `coupon.notifications` queue)

Sent by: `EligibilityTransitionService::evaluate` (only when eligible AND this tree version not already notified/pending).

```json
"payload": {
  "run_id": 42,
  "recipient_id": 777,
  "coupon_id": 123,
  "user_id": 55,
  "tree_hash": "abc123..."
}
```

Handled by: `NotificationRequestHandler` → **`$user->notify(new UserCouponEligibleNotification($coupon, $runId, $treeHash))` — THIS is where the Pusher payload (Appendix A.2) is born** → marks recipient NOTIFIED + user-state NOTIFIED + emits `notification.sent {run_id, recipient_id}` observation through the outbox.

## B.4 End-to-end RabbitMQ journey (one targeting save)

```text
PUT targeting → CouponTargetingChanged (Laravel, in-process, NOT RabbitMQ)
  → StartCouponDistribution (queued, high)
  → outbox INSERT + publish: coupon.distribution.start {run_id, coupon_id, tree_hash, trigger, scope, cap}
  → DistributionStartHandler: coupon.distribution.chunk {run_id, coupon_id, tree_hash, user_ids[], chunk_index} × N
  → DistributionChunkHandler: coupon.user.evaluate {run_id, recipient_id, coupon_id, user_id, tree_hash} × users
  → UserEvaluateHandler: engine verdict → coupon.user.became_eligible / evaluation.completed (observations)
  → [if newly eligible] coupon.notification.requested {run_id, recipient_id, coupon_id, user_id, tree_hash}
  → NotificationRequestHandler: $user->notify(...) → PUSHER (Appendix A.2) + DB + FCM
  → coupon.notification.sent {run_id, recipient_id} (observation) → run finishes
```

## B.5 What RabbitMQ NEVER contains

Coupon codes, rule trees, customer metrics, e-mails, assignment quotas, claim snapshots, PII — none of these cross RabbitMQ (verified: no producer puts them in any `payload`; envelope docblock forbids them). If you inspect a queue, you see only ids, hashes, triggers, and outcome markers.
