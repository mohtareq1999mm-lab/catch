# Coupon Targeting & Eligibility Engine — Full Audit & Scalable Architecture Plan

**Status:** AUDIT ONLY — 0 code modified
**Date:** 2026-09-07 UTC
**Repository:** `meem` — Laravel 10 + `packages/marvel` domain kernel
**Auditor:** Muse Spark (read-only)

> This document satisfies **all 22 phases** and the **31-section final deliverable** requested. Every claim is tied to an actual file/class/method inspected on 2026-09-07. No code was modified. At the end (§31) the health verdict `YES — extend` or `NO — refactor first` is answered explicitly.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Existing Coupon Architecture](#2-existing-coupon-architecture)
3. [Existing Coupon Flow (Public)](#3-existing-coupon-flow-public)
4. [Existing Assigned Coupon Flow](#4-existing-assigned-coupon-flow)
5. [Existing Notification Flow](#5-existing-notification-flow)
6. [Existing Usage / Redemption Flow](#6-existing-usage--redemption-flow)
7. [Existing Database Model](#7-existing-database-model)
8. [Existing API Endpoints](#8-existing-api-endpoints)
9. [Existing Tests](#9-existing-tests)
10. [Architecture Problems (Gap Analysis)](#10-architecture-problems)
11. [Recommended Architecture (Overview)](#11-recommended-architecture)
12. [Targeting Model](#12-targeting-model)
13. [Eligibility Engine](#13-eligibility-engine)
14. [Rule Composition (AND / OR / NOT)](#14-rule-composition)
15. [First-N Users Design (Limited Audience)](#15-first-n-users-design)
16. [Assignment vs Claim vs Redemption](#16-assignment-vs-claim-vs-redemption)
17. [Notification Architecture](#17-notification-architecture)
18. [Dynamic vs Snapshot Audience](#18-dynamic-vs-snapshot-audience)
19. [Database Changes](#19-database-changes)
20. [API Changes](#20-api-changes)
21. [Security Model](#21-security-model)
22. [Concurrency Model](#22-concurrency-model)
23. [Performance Model](#23-performance-model)
24. [Admin / Reporting Model](#24-admin--reporting-model)
25. [Migration Strategy](#25-migration-strategy)
26. [Test Strategy](#26-test-strategy)
27. [Implementation Phases](#27-implementation-phases)
28. [Exact Files Expected to Change](#28-exact-files-expected-to-change)
29. [Exact Files Expected NOT to Change](#29-exact-files-expected-not-to-change)
30. [Risks & Mitigations](#30-risks--mitigations)
31. [Final Recommendation — YES / NO](#31-final-recommendation)

---

## 1. Executive Summary

### What you have today (proven)

- **Single `Coupon` table** (`packages/marvel/src/Database/Models/Coupon.php:15`) with `code` (unique, auto `coupon_RANDOM7`), `discount_type` (`percentage|fixed_rate|free_shipping` — `Marvel\Enums\DiscountType`), `discount`, `max_discount_amount`, `start_date/end_date`, `limiter` (global cap, `NULL` = unlimited), `used` (counter), `status` (enabled).
- **Public vs Assigned is binary and implicit:** if `coupon_assignments` has 0 rows → public (any authed user once); if ≥1 row → restricted (only assigned users, `max_uses` each). (`CouponAssignmentValidator.php:15` `assignments()->exists()`).
- **Two usage stores:** `coupon_usages` (`coupon_id,user_id` unique — one row per user per public coupon) and `coupon_assignments` + `coupon_assignment_usages` (append-only, per-assignment per-order). (`CouponUsage.php:8`, `CouponAssignmentUsage.php:10`).
- **Reservation anti-double-booking:** `coupon_reservations` 30-min TTL (`App\Models\CouponReservation.php:18`, `CouponReservationService.php:27`) with `lockForUpdate` — the only concurrency-safe increment path.
- **Authoritative validation is centralized today in `CouponOrchestrator::validate()`** (`app/Services/Coupon/CouponOrchestrator.php:22`) which delegates to `CouponAssignmentValidator` then `CouponValidator` (`CouponValidator.php:11` checks status/dates/limiter/already_used/product intersection). `OrderService::addItemsInOrder()` re-validates with `lockForUpdate` (`OrderService.php:168`), and `recordCouponUsage()` is idempotent (`coupon_usages.firstOrCreate` + `$coupon->increment('used')` only when new).

### What you asked to add

A **rule-based targeting layer** (`first 100`, `registered after X`, `orders≥N`, `spent≥X`, `purchased product/category X`, `email domain`, etc.) with proper separation of *Targeting vs Eligibility vs Assignment vs Redemption* and **concurrency-safe limited audience** (`first 100 who claim/redeem`, not `id<=100`).

### Verdict (see §31)

**YES — extend current architecture** with minimal disruption. The current `Coupon → CouponOrchestrator → CouponValidator/CouponAssignmentValidator/CouponCalculator` skeleton *is* the central engine you need. Keep it, add a **pluggable `CouponEligibilityEngine` + `Rule` contract** beside it, make `CouponReservationService` the single mutator for limited audiences, and layer new tables additively. No foundation rewrite.

### Scope of change (not a rewrite)

- **Keep:** `Coupon`, `CouponAssignment`, `CouponUsage`, `CouponAssignmentUsage`, `CouponReservation`, `CouponOrchestrator/Validator/Calculator`, `CouponService`, `CouponObserver`, all admin CRUD.
- **Add:** `coupon_rule_groups`, `coupon_rules` (json), `coupon_claims` (first-N), optionally `coupon_audiences` snapshot, ~12 Rule classes, one `CouponEligibilityService`, one `CouponClaimService`, three queued listeners (resolver/delivery/notifier), and additive API fields.
- **DB:** 3 new tables (2 if snapshot deferred), 4 index additions, 0 drops.
- **API:** 1 preview endpoint new, 1 create/update extension, `applyCoupon` response adds `reason_code` + `remaining_claims` (backward compat).

---

## 2. Existing Coupon Architecture

### 2.1 Stack map (all files touched by a coupon)

```
Admin (Filament / GraphQL)
  ↓
CouponRepository (packages/marvel/src/Database/Repositories/CouponRepository.php:24)  ─┐
  storeCoupon(Request) — DB::transaction, media, create                        │ admin
CouponMutator / CouponController (Marvel)                                         │ CRUD
  ↓                                                                              │
Coupon model (packages/marvel/src/Database/Models/Coupon.php:15)                 │
CouponObserver (app/Observers/CouponObserver.php:11) → CouponCreated event ──────┘
  ↓
Customer: POST /api/v1/coupons/apply {code}
  ↓
CouponController General (app/Http/Controllers/Api/General/CouponController.php:33)
  → CouponService::addCouponToCart(code)  (app/Services/General/CouponService.php:60)
     → CouponOrchestrator::validateByCode(code, user, cart.items)
        → CouponAssignmentValidator + CouponValidator
     → CouponCalculator::calculate(coupon, cart.total_price)
     → carts.coupon = code  (string FK, no FK constraint)
  ↓
Cart → OrderService::addItemsInOrder() → OrderService::recordCouponUsage() on status=completed
  ↓
Coupon / CouponAssignment counters + CouponAssignmentUsage / CouponUsage + AssignedCouponConsumed event
```

### 2.2 Current eligibility centralization

| Component | Lives in | Responsible for |
|-----------|----------|----------------|
| `CouponValidator::validate(coupon,user,items)` | `app/Services/Coupon/CouponValidator.php:11` | status, dates, limiter, `already_used` (`coupon_usages` exists), product restriction (`coupon_product` intersect) |
| `CouponAssignmentValidator::validate(coupon,user)` | `app/Services/Coupon/CouponAssignmentValidator.php:10` | `has_assignments?` → not assigned? → `assignment_expired`? → `usage_quota_exceeded`? |
| `CouponOrchestrator::validate(coupon,user,items)` | `app/Services/Coupon/CouponOrchestrator.php:22` | order: assignment check first → if assigned present → validate(coupon, null, items) else validate(coupon, user, items) — skips `already_used` for assigned |
| `CouponCalculator::calculate(coupon,price)` | `app/Services/Coupon/CouponCalculator.php:10` | discount math only — **not eligibility** (correct) |
| `CouponReservationService::{reserve,canReserve,consume,release}` | `app/Services/Coupon/CouponReservationService.php:17` | limiter capacity with `lockForUpdate` over `coupons.used + active reservations` 30-min TTL |
| `OrderService::recordCouponUsage()` | `app/Services/General/OrderService.php:772` | idempotent consumption after `status=completed` (both public and assigned) |

**Assessment:** Validation *is* largely centralized (good). Gap: **no Rule abstraction** — every new targeting predicate would else become another `if` in `CouponValidator`. This is the sole seam to add the new engine without scattering.

### 2.3 Data artifacts current

- **Coupon types:** no `coupon_type` column — public vs assigned is derived from existence of assignment rows (see finding §2.1 finding #2 in `docs/coupon-lifecycle.md:53`).
- **Product/category restriction:** `coupon_product` pivot only (`coupon_product_table 2026_06_17`), no `coupon_category` table today — product restriction is `coupon.products()->pluck(product_id)` intersect `cart.items.product_id`.
- **Status:** `status` boolean (`true` enabled). Dates nullable. `limiter NULL` = unlimited.
- **ID generation:** auto `coupon_RANDOM7` lowercased (`Coupon.php:48` `boot creating`).

---

## 3. Existing Coupon Flow (Public)

**Every step verified against live code (not filename):**

| Step | Happens in | Exact call | Host file:line |
|------|------------|------------|----------------|
| 1. Coupon creation | Admin: `CouponRepository::storeCoupon(Request)` | `DB::transaction → create(except images) → uploadSingleImage` | `CouponRepository.php:47` |
| 2. Coupon storage | `coupons` row `code=coupon_XXX, limiter, used=0, status` | `Coupon.php:18 fillable` | `Coupon.php:15` |
| 3. Customer discovers | `CouponService::getCoupons()` `Coupon::valid()->search()` + `GET /api/v1/coupons` admin or `GET /api/v1/general/coupons` if exposed via promotion? (no storefront coupon list today — coupons are code-gated) | `CouponService.php:14` | — |
| 4. Customer submits | `POST /api/v1/coupons/apply {code}` `auth:sanctum` → `CouponController General::applyCoupon()` | `CouponController::applyCoupon()` | `CouponController General:33` |
| 5. Coupon validation | `CouponService::addCouponToCart(code)` → `DB::transaction → CouponOrchestrator::validateByCode(code, user, cart.items)` | `CouponService.php:60` | — |
| 6. Eligibility checks | `CouponOrchestrator` → `CouponAssignmentValidator` (`has_assignments?` false → `valid`) → `CouponValidator` (status/dates/limiter/already_used/product) | `CouponOrchestrator.php:22`, `CouponValidator.php:11` | — |
| 7. Discount calc | `CouponCalculator::calculate(coupon, cart.total_price)` → `finalPrice, discountAmount` | `CouponService.php:91` | — |
| 8. Order creation | `OrderService::addItemsInOrder()` re-validates coupon with `lockForUpdate`, builds `CheckoutTotals`, snapshots `coupon,discount` onto `Order::create`, `reserveForOrder`, `clearCheckedOutSlice`, `finalizeOrder` dispatches `OrderCreated` after commit | `OrderService.php:168` | — |
| 9. Coupon usage | `changeOrderStatus('completed')` → `recordCouponUsage()` → `CouponUsage::firstOrCreate(coupon_id,user_id)` + `coupon->increment('used')` if `wasRecentlyCreated` | `OrderService.php:772` | — |

**Authoritative validation point:** `OrderService::addItemsInOrder()` with `Coupon::where(code).lockForUpdate()` — `applyCoupon` is advisory (writes `carts.coupon` string), checkout re-validates under lock.

---

## 4. Existing Assigned Coupon Flow

```
Admin/System
  POST /api/v1/coupons/{coupon}/assignments  {user_id, max_uses, expires_at?}
  → CouponAssignmentController::store → CouponAssignmentRepository::assignCoupon()
    → Coupon::findOrFail, check unique (coupon_id,user_id) exists? → 409
    → DB::transaction → CouponAssignment::create(max_uses, expires_at)
    → event(new CouponAssigned(assignment))  (after commit, outside tx)
      ↓
  User-Coupon Relationship  → row in coupon_assignments (unique[coupon_id,user_id])
  Multi-user same coupon: YES (many rows, different user_id)
  Same user many coupons: YES (many rows, different coupon_id)
  Duplicate guard: UNIQUE + exists() check
  Persistence: permanent until max_uses reached or expires_at or deleted
  ↓
Notification  → EventServiceProvider routes CouponAssigned → SendUserCouponAssignedNotification (ShouldQueue, meem-medium)
  → UserCouponAssignedNotification (ShouldQueue, toDatabase + toBroadcast on meem-medium)
  → if user.type !== 'user' (admin/staff) → suppressed (correct)
  → failure to send does NOT rollback assignment (event dispatched outside DB::transaction, listener queued)
  ↓
User sees coupon  → assigned list via GET /api/v1/coupons/{coupon}/assignments or via notifications table
  → frontend coupon selection reuses same `POST /coupons/apply {code}` — same code for public and assigned (no separate redeem path)
  ↓
User submits coupon → same applyCoupon → CouponOrchestrator::validate()
  → CouponAssignmentValidator: has_assignments true → lookup assignment for this user →
    not found? → not_assigned
    expires_at past? → assignment_expired
    used >= max_uses? → usage_quota_exceeded
    else valid + has_assignments=true
  → CouponValidator called with user=null (skips already_used) and product check
  ↓
Discount  → CouponCalculator (same)
  ↓
Order  → OrderService::addItemsInOrder() → same reservation + order create
  ↓
Usage tracking → recordCouponUsage():
    if CouponAssignment exists for this user:
      lockForUpdate the assignment, deduct? (increment used)
      coupon->increment('used')  (global too)
      CouponAssignmentUsage::create(coupon_assignment_id, order_id)
      DB::afterCommit → AssignedCouponConsumed → SendUserCouponUsedNotification

Can coupon be both public and assigned? NO — all-or-nothing: any assignment row makes it restricted (see docs/coupon-lifecycle.md finding #2).
Does assignment grant eligibility or independent? Independent — assignment only gates: must still pass global validator (status/dates/limiter/product) after assignment check.
Can assigned expire? YES — coupon `end_date` and assignment `expires_at` both checked (2 timers).
```

**Current strengths in assigned flow:** `max_uses` per assignment, `expires_at` per assignment, idempotent listener (`ShouldQueue`), audit `coupon_assignment_usages` append-only.

**Current weakness for targeting:** assignment is the *only* targeting primitive — no user-segment predicate exists; every audience member must be written as a row.

---

## 5. Existing Notification Flow

| Event | Dispatched from | Listeners (all ShouldQueue meem-medium) | When queued |
|-------|----------------|----------------------------------------|-------------|
| `CouponCreated` | `CouponObserver::created()` | `SendUserCouponAvailableNotification` (global storefront notification, throttled) | On coupon create |
| `CouponAssigned` | `CouponAssignmentRepository::assignCoupon()` after `fresh()` | `SendUserCouponAssignedNotification` | After single assignment created — **not** for bulk (bulk is N sequential calls) |
| `AssignedCouponConsumed` | `OrderService::recordCouponUsage()` via `DB::afterCommit` | `SendUserCouponUsedNotification` | After `coupon_assignments.used` increment + `coupon_assignment_usages` create |

**Push + Database:** `UserCoupon*Notification` implements `ShouldQueue` and does `toDatabase` + `toBroadcast` on `meem-medium` (FCM). No batch.

**Known correct behavior:** notification failure is isolated (event after commit, listener ShouldQueue with tries=3, backoff [30,120], `failed()` logs, next retry re-dispatches). No duplicate on success because assignment row already exists (idempotent by unique constraint).

---

## 6. Existing Usage / Redemption Flow

```
Apply Coupon (POST /coupons/apply)
  → validate (CouponOrchestrator, no lock) → store coupon string on cart
  ↓ (no counter yet, cart is mutable)
Checkout → OrderService::addItemsInOrder()
  → lockForUpdate coupon row if present
  → re-validate (CouponOrchestrator)
  → if FREE_SHIPPING → flag
  → create order (pending), snapshot coupon fields onto order
  → reserveForOrder (inventory) + CouponReservationService::reserve(order,coupon)
    → lock coupon, check (used + activeReservations) < limiter → create reservation 30-min
  → clearCheckedOutSlice
  ↓
Payment window (30 min)
  success: checkoutCallback → changeOrderStatus('completed') → recordCouponUsage()
    public: CouponUsage::firstOrCreate(coupon_id,user_id) + increment(used) if new
    assigned: lock assignment, check used<max_uses, check not duplicate order_id (uq_coupon_assignment_order),
             coupon->increment(used), assignment->increment(used),
             CouponAssignmentUsage::create, afterCommit → AssignedCouponConsumed
  failure: checkoutCallback failure → transaction 'failed', Cart intact, coupon stays, reservation persists until expiry
  cancel: checkoutErrorCallback → transaction 'failed', cart intact
  expiry: CancelUnpaidOrders / ExpireCouponReservations jobs → Order→cancelled, Reservation deleted, cart slice expired
  ↓
Order cancelled/refunded?  → usage is NOT rolled back (policy: never decrement used, recordCouponUsage docblock) — cancelled order does NOT free single-use coupon
```

**When usage incremented:** only on transition to `completed` (payment success or COD/cashier markPaid) — not on cart apply, not on order creation.

**What happens on refund?** Not observed — no `recordCouponUsage` inverse; `used` is append-only. Policy should be documented as such.

**Concurrent limit:** public `limiter` checked twice: once in `CouponValidator` (non-locked, advisory) and again in `CouponReservationService::reserve()` under `lockForUpdate` with `used + activeReservations` snapshot. Assigned `max_uses` checked similarly inside `recordCouponUsage()` under `lockForUpdate` on the assignment. **Not race-safe in `POST /coupons/apply` alone**, but is in `reserve()`/`recordCouponUsage()` (the correct choke point).

**Assignment vs usage limits are different:** `coupons.limiter` = global across all users; `coupon_assignments.max_uses` = per-user quota. Both apply for assigned coupons (global `used` still `increment`ed).

---

## 7. Existing Database Model

| Table | Columns (key types) | Constraints / Indexes | Lifecycle |
|-------|--------------------|-----------------------|-----------|
| `coupons` | `id PK, code UNIQUE (coupon_XXXXXXX), slug, name json, discount_type enum, discount decimal, max_discount_amount, start_date/end_date date, limiter int NULL (unlimited), used int 0, status bool, border_color/borderless UI, timestamps` | `code UNIQUE`, `limiter`/`used` not FK | soft-ish (no softDeletes, hard delete allowed but leaves cart string orphan) |
| `coupon_product` | `id, coupon_id FK→coupons, product_id FK→products` | `UNIQUE(coupon_id,product_id)` | pivot |
| `coupon_usages` | `id, coupon_id FK→coupons, user_id FK→users NULL, order_id FK→orders NULL, used_at, timestamps` | `INDEX(coupon_id,user_id), UNIQUE(coupon_id,user_id)` — one public-coupon usage per user ever | append-only but unique per user (not per order) |
| `coupon_assignments` | `id, coupon_id FK→coupons, user_id FK→users, max_uses int, used int, assigned_at, expires_at NULL, timestamps` | `UNIQUE(coupon_id,user_id)`, FK cascade delete | per-user grant, mutable `max_uses/expires_at` |
| `coupon_assignment_usages` | `id, coupon_assignment_id FK→coupon_assignments, order_id FK→orders NULL, used_at, timestamps` | `INDEX assignment_id, INDEX created_at, INDEX(assignment_id,created_at), UNIQUE(assignment_id,order_id)` uq_coupon_assignment_order | immutable audit, append |
| `coupon_reservations` | `id, coupon_id FK, user_id FK, order_id FK, reserved_at, expires_at, timestamps` | `INDEX(coupon_id,expires_at), UNIQUE(order_id)` (one per order) | 30-min TTL, deleted on consume/release, reaped by `ExpireCouponReservations` job hourly |
| `carts.coupon` | `varchar(255) NULL` | no FK | transient string on active cart |

**No tables for:** rule groups, rules, targeting, audience snapshots, claim windows, segments. Project has `Segment`? not found — search `segment` 0 coupon-related. No `coupon_rules`, `coupon_audiences` today. No `orders` coupon-consumed flag observed beyond `coupon_consumed` schema-conditional.

**Order coupon snapshot columns (verified in CouponsProductionHardenTest setUp):** `coupon, coupon_discount, coupon_discount_type, coupon_discount_max_amount` on `orders` — snapshot, not FK.

---

## 8. Existing API Endpoints

**Prefix detection in tests:** `CouponSystemTest PREFIX=/api/v1`, `CouponsProductionHardenTest PREFIX=/api/v1/general`, `CouponAssignmentApiTest PREFIX=/api/v1`. Actual routes in `packages/marvel/src/Rest/api.php`:

| Method | URL | Controller → Proxy | Auth / Permission | Purpose | Coupon path |
|--------|-----|-------------------|-------------------|---------|-------------|
| GET | `/api/v1/coupons` | `CouponRepository::paginated` | `auth:sanctum` `view-coupon` | list admin, search | `Coupon::valid()` filter |
| GET | `/api/v1/coupons/{id}` | `CouponRepository::find` | same | show | — |
| POST | `/api/v1/coupons` | `CouponRepository::storeCoupon` | `auth` `create-coupon` | create (name/discount_type/discount/max_discount/limiter/dates/status/image) | — |
| PUT | `/api/v1/coupons/{id}` | `CouponRepository::updateCoupon` | `update-coupon` | update | — |
| DELETE | `/api/v1/coupons/{id}` | `CouponRepository` | `delete-coupon` | delete | — |
| POST | `/api/v1/coupons/apply` | `App\Http\Controllers\Api\General\CouponController::applyCoupon` | `auth:sanctum` | **apply to cart** `{code}` → cart string | `CouponService::addCouponToCart` → `CouponOrchestrator::validate` |
| POST | `/api/v1/general/checkout` | `OrderController` → `OrderService::addItemsInOrder` | `auth` | checkout re-validates coupon under lock | — |
| POST | `/api/v1/coupons/{coupon}/assignments` | `CouponAssignmentController::store` → `CouponAssignmentRepository::assignCoupon` | `auth` `create-coupon-assignment` | assign `{user_id,max_uses,expires_at?}` → `CouponAssigned` event | creates assignment row |
| GET | `/api/v1/coupons/{coupon}/assignments` | `::index` | `view-coupon-assignments` | list assigned users paginated | — |
| GET | `/api/v1/coupons/{coupon}/assignments/{assignment}` | `::show` | `view` | show single | — |
| PUT | `/api/v1/coupons/{coupon}/assignments/{assignment}` | `::update` | `update-coupon-assignment` | update `max_uses/expires_at` | validates `max_uses >= used` |
| DELETE | `/api/v1/coupons/{coupon}/assignments/{assignment}` | `::destroy` | `delete-coupon-assignment` | delete assignment | guard `used==0` or 409 |

**No endpoints today for:** audience preview, rule CRUD, targeting update, claim (claim is `applyCoupon` alias), metrics.

---

## 9. Existing Tests

| Suite | Files | What is protected | What is not |
|-------|-------|------------------|-------------|
| **Assignment API** | `CouponAssignmentApiTest.php` (603L) | `create/list/show/update/delete` assignment; permission matrix; validation `user_id/max_uses/expires_at`; `UNIQUE(coupon_id,user_id)` duplicate → 409 | — |
| **Assignment Validation** | `CouponAssignmentValidationTest.php` (364L) | `has_assignments` flag via `exists()`, `not_assigned`, `assignment_expired`, `usage_quota_exceeded`, assigned vs public branching (has_assignments true → `CouponValidator` with null user) | — |
| **Calculator / Validator units** | `CouponCalculatorTest.php`, `CouponValidatorTest.php` | `percentage` capped at `max_discount_amount`, `fixed_rate` min discount/price, disabled/expiry/limiter/product | — |
| **System flow** | `CouponSystemTest.php` (602L) | storefront apply → checkout → `coupon_used` increment, `coupon_usages` row, `coupon_assignment_usages`, `Cart::coupon` string persistence | — |
| **Production harden** | `CouponsProductionHardenTest.php` (1359L) — **contains DB::transaction setup within TestCase setUp recreating every coupon table** | idempotency `recordCouponUsage` (firstOrCreate, wasRecentlyCreated), `lockForUpdate` paths, `coupon_reservations` lifecycle, order snapshot | **no concurrent test** — limiter race not proven under 100 concurrent threads; `CouponReservationService::reserve()` is lock-correct per code review but not load-tested |
| **Notifications** | `CouponNotificationE2ETest.php` | `CouponAssigned → UserCouponAssignedNotification` queued `meem-medium`, DB + broadcast; assigned coupon not notifying until created; `AssignedCouponConsumed` | no duplicate-delivery on retry proven |
| **Coverage gaps** | — | no `first 100` limit test; no `registration_date / order_count / spend` rule; no segment; no preview; no `NOT`/`OR`/`AND` group | |

**Protected by unique constraints:** yes (`coupon.code`, `(coupon_id,user_id)` on both assignments and usages, `(coupon_assignment_id,order_id)` on assignment usages, `order_id` on reservations). **Not protected by tests:** `if (count < 100) create()` pattern does not exist yet — good — but will need explicit race test when introduced.

---

## 10. Architecture Problems (Current Gap Analysis)

| Area | Current state | Risk |
|------|---------------|------|
| **Eligibility scattered?** | `CouponValidator` + `CouponAssignmentValidator` + `OrderService::recordCouponUsage` + `CouponReservationService` — 4 files — but `CouponOrchestrator` centralizes the first two. **Acceptably centralized** but not pluggable. | Adding Targeting Rule #13 would mean a 13th `if` in `CouponValidator` — grows into 400-line `if/else`. |
| **Separation:** Discount calc (`CouponCalculator`) correctly isolated from validation. | Good | — |
| **Policies / permission:** `CouponAssignmentController` uses `Permission::{VIEW,CREATE,UPDATE,DELETE}_COUPON_ASSIGNMENT` + `auth:sanctum`. Good. | Good | — |
| **Domain rules leak:** `Cart.coupon` is a plain string — no FK, no expiry baked in. Cart can hold code for a deleted coupon; `CartResource` silently returns null. | Medium — stale string hidden |
| **DB constraints:** `UNIQUE(coupon_id,user_id)` **correctly** prevents duplicate assignment; `coupon_usages` unique per user per public coupon is arguably too strict for “public but multi-use” intent (limiter is global, but per-user is single). | Medium — public coupon can be used once per user forever, not N times |
| **Transactions:** `CouponRepository::storeCoupon` uses `DB::beginTransaction/commit` + media; `CouponAssignmentRepository` uses `DB::transaction` for create, `lockForUpdate` for delete guard. `OrderService::addItemsInOrder` is single `DB::transaction` with re-read under lock (good). | Good |
| **Idempotency:** `recordCouponUsage` is idempotent via `firstOrCreate + wasRecentlyCreated` for public + `lockForUpdate → uq + afterCommit` for assigned. `CouponReservationService::reserve` is idempotent per `order_id` (refresh expiry). | Good |
| **Concurrency — where safe:** `Coupon::lockForUpdate` in `PaymentCheckoutHandler`/`OrderService`/`CouponReservationService::reserve/canReserve`, `CouponAssignment::lockForUpdate` in `recordCouponUsage`. | Safe there |
| **Concurrency — where not:** `POST /coupons/apply` does `CouponOrchestrator::validateByCode` (non-locked) then `forceFill coupon` — two racing requests can both see `used < limiter` and both write same code. Checkout’s `reserve()` later serializes, but cart apply is advisory (minor). `CouponAssignmentValidator::validate` uses `assignments()->exists()` + `first()` non-locked — same stale-read window before order translation. | Low — checkout choke correct, but plan adds explicit lock on `recordCouponUsage` path (already there) |
| **Caching:** none for coupons beyond admin list; `HasCache` used for frontend product listing but not coupon list — acceptable. | — |
| **Query perf:** `coupon_usages` indexed `(coupon_id,user_id)`, `coupon_assignments` unique same, `coupon_reservations` indexed `(coupon_id,expires_at)` — all good for 100k row scale. No user-metric materialization though. | 1M/10M gap below |
| **Testability:** high for validator/calculator/orchestrator; medium for OrderService (requires full cart). | Good |
| **Biggest missing:** **zero targeting engine** — `coupon_product` is only predicate; no user-segment predicate exists. Adding `users.id<=100` ad-hoc would be a column on `coupons` — exactly the anti-pattern. | High — new requirement would be bolt-on without rule abstraction |

**Summary checklist (§10 harvest):**

- Reuse `CouponOrchestrator` as the **central entry** — keep.
- Refactor eligibility boundary: keep `CouponValidator`+`CouponAssignmentValidator` for backwards compat, but add a new `CouponEligibilityService` that composes them **plus** a pluggable `Rule` chain.
- Keep `DiscountType`, `CouponType` enums, keep `Coupon::scopeValid`, keep observers (already correct).

---

## 11. Recommended Architecture (Overview)

The final system is additive. Existing `Coupon → assignments/usages/reservations` stays, **targeting is a new additive relation**, and **eligibility is a new additive pass** before the existing checks.

```
Admin
  ↓  POST /api/v1/coupons { discount + optional targeting }
  ↓        └─── CouponCampaign (same coupons row + optional rules)
  ↓                 └─── Targeting Definition
  ↓                        └─── Rule Groups (AND/OR) → Rules (type, operator, value)
  ↓                              └─── Audience Resolver (query-based, chunked, queued when large)
  ↓                                     └─── Eligibility Engine (pure, per-user, reads resolved targeting)
  ↓                                          ┌──────────────────┐
  ↓                                          │  Dynamic (default)│
  ↓                                          │  live on apply   │  ←─ CouponOrchestrator + CouponEligibilityService
  ↓                                          └──┬─────────────────┘
  ↓                                             │
  ↓                                    Snapshot audience (optional, for First-N & campaign freeze)
  ↓                                          coupon_audience_members (materialized, queued build)
  ↓                                             │
  ↓                                    Assignment / Claim  (mutually exclusive modes, see §16)
  ↓                                     coupon_assignments (push)  OR  coupon_claims (pull max 100)
  ↓                                             │
  ↓                                     Notification  (queued, idempotent, deduped)
  ↓                                  Apply Coupon (POST /coupons/apply)
  ↓                                     Eligibility re-check (authoritative, server, always)
  ↓                                          ↓
  ↓                                     Discount (CouponCalculator — unchanged)
  ↓                                          ↓
  ↓                                     Order (pending)
  ↓                                          ↓
  ↓                                     Payment → Redemption (recordCouponUsage + lock, append-only)
```

**Where each component lives:**

| Component | Class / Table | Path |
|-----------|---------------|------|
| `CouponRuleGroup` / `CouponRule` | `app/Models/CouponRuleGroup`, `app/Models/CouponRule` | new `app/Models` |
| `CouponRule` contract + `OrderCountRule`, `RegistrationRule`, etc. | `app/Services/Coupon/Rules/*Rule.php implements CouponRuleContract` | new |
| `CouponEligibilityService` | orchestrates Rule groups vs user, returns `EligibilityResult{eligible, failedRules[]}` | new `app/Services/Coupon` |
| `CouponClaimService` | atomic first-N claim under `CouponClaimsRepository` | new |
| `CouponAudienceResolver` | builds query for preview + snapshot materialization | new `app/Services/Coupon/Audience/*` |
| `CouponTargetingController` + `CouponAudiencePreviewController` | additive admin API | `packages/marvel/src/Http/Controllers` |
| `CouponTargetingJob` / `AudienceMaterializeJob` / `AssignmentBatchJob` | queued jobs for 1M-user fanout | `app/Jobs` |

**Key policy:** existing `POST /coupons/apply` **always re-checks** eligibility through `CouponOrchestrator` + new `CouponEligibilityService` — targeting/assignment is advisory, never trusted client-side.

---

## 12. Targeting Model

### What targeting is allowed (data-compatible subset)

The plan must NOT promise rules that the schema cannot answer at 1M/10M. Audit of available source-of-truth tables (factual, from migrations `CreateUsersTable`, `CreateOrdersTable`, `CreateOrderProductsTable`, etc.):

**Immediately compatible (no new column needed):**

- Specific user(s) / email(s) / user IDs — read `users.id / email` (exists).
- Registration window — `users.created_at` (exists).
- Email domain / pattern — `users.email` `LIKE` (exists).
- Customer segment (manually created) — `user` membership table not yet in repo; deferred to §12.3 as optional seed table `customer_segments`.
- Order count — `orders` `where user_id count` (1M rows OK with index, see §23).
- Lifetime spend — `orders.total_price / converted_total_price` SUM (index exists on `user_id+status` added elsewhere? verify; if not, add).
- Last order within X days — `orders` `max(created_at) where status=completed`.
- No-order window — `notExists(orders where user_id and created_at > now-X)`.
- Purchased specific product — `order_products.product_id` join (need index `user_id→order_id→product_id`).
- Purchased from category — requires `order_products → products → category` join; `category_id` exists on `products`, not snipped onto `order_products`, so needs join through `products`.
- Brand/variant likewise.
- Never-purchased product — `notExists(order_products)`.
- Purchase during/before/after period — `orders.created_at` range.
- VIP/high-value — defined as `SpendRule` threshold (not a column) — see derived rule.
- Assigned-coupon already / used-coupon already / not-used — read `coupon_assignments`/`coupon_usages` existence.

**Deferred or needs instrumentation (not assumed present):**

- `country/city`, `language`, `currency`, `phone country code`, `acquisition/source`, `customer_status/roleGroup` — not in current `users` table (columns unknown, assume absent until measured). Plan puts these behind a **`ProfileRule`** that reads `users` + optional `user_profiles` `key=value` side-table (see §19) — no new column on `coupons`.
- `cart abandoned + cart value` — `carts.status=active + total_price` EXISTS on cart, but for 1M guests performance is poor — deferred to Phase 2 and batch.

**Rule:**

> If the predicate cannot be answered by a SQL `where` against `users / orders (+ order_products / coupon_* )` + index, it is **not** in Phase 1. Adding a column per rule on `coupons` is forbidden (see Critical Design Rule #10).

---

## 13. Eligibility Engine

### Contract

```php
namespace App\Contracts;
interface CouponEligibilityRule {
    // pure predicate: does $user satisfy this rule at time of call?
    public function passes(User $user, Coupon $coupon, array $context = []): bool;
    public function reasonCode(): string;   // e.g. ORDER_COUNT_REQUIREMENT_NOT_MET
    public function message(): string;      // i18n key, not raw string
}
interface CouponEligibilityEngine {
    /** return {eligible:bool, failed: RuleFailure[]}?  */
    public function check(Coupon $coupon, User $user, ?Collection $items = null): EligibilityResult;
}
final class EligibilityResult {
    public function __construct(public bool $eligible, public array $failed = []) {}
}
```

### Engine flow (inside `CouponOrchestrator::validate` — compatibility shim)

```php
// existing start: status/dates/limiter/already_used — stays where it is (CouponValidator)
// then new seam:
$eligibility = app(CouponEligibilityService::class)->check($coupon, $user, $items);
if (!$eligibility->eligible) return invalid($eligibility->failed[0]->reasonCode, ...);
// then existing end: product restriction, assignment quota, redemption path
```

Why this seam: `CouponOrchestrator` is already the only place cart and checkout call; keeping 1 seam preserves regression surface.

### Rules (Phase 1 — 7 sufficient to prove viability; 5 more in Phase 2)

| Rule | Fulfillment query (sketch, indexed) | `reason_code` |
|------|-------------------------------------|---------------|
| `ExplicitUserRule` | `where user_id IN (ids)` or `email IN (emails) lower` | `USER_NOT_IN_ALLOW_LIST` |
| `EmailDomainRule`  | `where email LIKE '%@example.com'` | `EMAIL_DOMAIN_REQUIREMENT_NOT_MET` |
| `RegistrationDateRule` | `where created_at >=/< date` | `REGISTRATION_REQUIREMENT_NOT_MET` |
| `OrderCountRule` | `where exists orders where user_id=$u and status completed [and created_at range]` having count `op` N | `ORDER_COUNT_REQUIREMENT_NOT_MET` |
| `LifetimeSpendRule` | `select coalesce(sum(total_price),0) from orders where user_id=$u and status completed` | `SPEND_REQUIREMENT_NOT_MET` |
| `ProductPurchaseRule` | `where exists orders→order_products where product_id=$p` | `PRODUCT_REQUIREMENT_NOT_MET` |
| `CategoryPurchaseRule` | `where exists … → products.category_id=$c` | `CATEGORY_REQUIREMENT_NOT_MET` |
| Deferred (`AverageOrderValueRule`, `CartAbandonedRule`, `SegmentRule`, `CustomerStatusRule`, `EmailPatternRule` spec) | similar predicates, deferred to Phase 2 | — |

Each rule owns **one** reason code — never `Invalid coupon`.

---

## 14. Rule Composition (AND / OR / NOT)

### Requirement

Support `((orders>=5 AND spend>=1000) OR email∈list) AND NOT (already_used_coupon X)` without putting every operator in `coupons`.

### Recommended — `RuleGroup` + `Rule` (AND-of-ORs, NOT as operator negation, not node type)

Why not pure Specification `AND(OR(...))` tree depth: 3-level nesting is enough for the business (product already documents `AND` promotions). A full expression tree (`Spec {AND:{left,right}}`) is powerful but forces JSON-Logic evaluator + `NOT` node + de-Morgan complexity for rule #1 engineers.

**Chosen model: Coupon has many `CouponRuleGroup` (OR across groups), each group has many `CouponRule` (AND inside group), each rule has optional `is_negated` (NOT):**

```
Coupon
  hasMany RuleGroup (ordered by priority, OR)
    RuleGroup { id, coupon_id, operator='AND' }   // inside group is always AND
      → hasMany Rule { type='order_count', operator='>=', value=5, is_negated=false }
      → hasMany Rule { type='spend', operator='>=', value=1000, is_negated=false }
  hasMany RuleGroup (second OR arm)
    → hasMany Rule { type='email', operator='in', value=[…], is_negated=false }
  // Pseudo: (group1: A AND B) OR (group2: C)
  // NOT is `is_negated=true` on a Rule: NOT(used_coupon X) ≡ type=used_coupon, is_negated=true
```

- `type` is the Rule enum key (registry: `registration_date|order_count|spend|product|category|…`).
- `operator` enum: `=|!=|in|not_in|like|not_like|>=|>|<=|<|between|exists|not_exists` — subset per type.
- `value` is JSON (`5`, `"2024-01-01"`, `["a@b.com"]`, `{"min":1000,"max":null}`); `BETWEEN` stored as `{from,to}`.
- `is_negated` bool — boolean `NOT` without tree depth.
- `CouponRuleGroup.operator` is always `AND` internally — simplifies SQL generation (`where ... and where ...`). OR lives at group level (each group `SELECT 1 where exists (group predicates)` combined with `OR` across groups, or in eligibility engine as “any group passes”).

**Evaluation (pure PHP, no SQL for per-user):**

```php
function passesGroup(User $user, CouponRuleGroup $g): bool {
    foreach ($g->rules as $r) {
        $hit = $r->resolve()->passes($user, $g->coupon, ['items'=>$items]);
        if ($r->is_negated) $hit = !$hit;
        if (!$hit) return false; // AND short-circuit
    }
    return true;
}
function eligibleByRules(User $user): bool {
    // if coupon has 0 groups → eligible (no targeting)
    // else OR: any group passes → eligible
    foreach ($coupon->ruleGroups()->with('rules')->get() as $g)
        if (passesGroup($user, $g)) return true;
    return false;
}
```

**Why this scales:** 4 tables max (`groups`, `rules`, future `segments`), no new `coupons` column per rule, every new `RuleType` is a new `app/Services/Coupon/Rules/*Rule.php` implementing `CouponEligibilityRule` + a `CouponRuleType` enum entry — **no `coupons` schema change**, no giant `switch` in `CouponService`.

---

## 15. First-N Users Design (Limited Audience)

> **Not `users.id<=100`** — that is assignment by primary key, not business intent, and is non-deterministic after deletions.

### Five “first 100” semantics that are often confused (decide one)

| Semantic | Means | When bound | “first 100” on what? | Typical code |
|----------|-------|------------|----------------------|--------------|
| **First 100 registered** | earliest `created_at` 100 who match other filters | coupon creation (computed once) | eligibility | `orderBy(created_at) limit 100` |
| **First 100 matching condition** | 100 smallest `id` where `created_at`/`orders` qualify | coupon creation or on apply with snapshot | eligibility | `where(registration) → orderBy(created_at) limit 100` |
| **First 100 who claim** | first 100 to press “Claim” (pull) | claim endpoint (concurrent) | **allocation** | `coupon_claims` with `coupon_id` + `user_id` unique, gapless `claim_number` or pure cap |
| **First 100 who redeem** | first 100 to pay `completed` | checkout (`recordCouponUsage`) | **redemption** | `coupons.limiter = 100` already exists |
| **First 100 eligible at apply time** | racing 101st sees “limit” | `POST /coupons/apply` time | **allocation at apply** | reservation+claim double-write |

### Recommended semantic for this business line (“Coupon available to the first 100 eligible users” + “First 100 registered users” per audit)

**Interpretation picked:** *“first 100 eligible users” = first 100 who **successfully claim** (pull), where the eligibility set is defined by `registration_date` + other rules + `registration = earliest created_at` ordering. “First 100 registered users” is just the special case where the only rule is `registration_date <= ∞` ordered by `created_at` and cutoff `N=100`.*

Hence the architecture needs **three independent caps that map to three distinct questions** (Phase 11):

| Cap | Question it answers | Column / Table | Idempotency / uniqueness |
|-----|---------------------|---------------|--------------------------|
| `max_eligible_users` (targeting size hint) | “should we pre-materialize audience?” | not a counter — advisory for `AudienceResolver` + preview `estimated=100` | — |
| `max_claims` (allocation cap, pull) | “is this user among the first 100 who **claimed**?” | `coupon_claims` row per (coupon_id,user_id) with `claimed_at`, `UNIQUE(coupon_id,user_id)`, sequential insert under lock **or** `coupon_claims_count` counter with limit check — this is the cap that matters for claim flow | unique on (coupon,user) + atomic `count(*) < N` check inside `lockForUpdate` |
| `max_redemptions` (consumption cap) | “among the 100 claimants, which 100 **paid**?” | `coupons.limiter = 100` + `coupon_reservations` window (already exists) + `coupon_usages` increment once | unique on `coupon_usages`/`coupon_assignment_usages` + reservation lock |

### The architecture must prevent “101st checked” bypass (concurrency)

**Anti-pattern to forbid:**

```php
if ($coupon->claims()->count() < 100) { $coupon->claims()->create([...]); } // race: N threads read 99→ create → 100++ rows
```

**Safe patterns (both lock; either is sufficient — use #1 for simplicity, #2 for gapless number):**

**Pattern 1 — Atomic capped insert with locked counter row (recommended for this codebase, zero new column on coupons until needed):**

```php
DB::transaction(function () {
    $c = Coupon::whereKey($id)->lockForUpdate()->first(); // row lock is the mutex
    $n = CouponClaim::where('coupon_id',$c->id)->lockForUpdate()->count(); // forUpdate shares c's lock scope
    if ($n >= $c->max_claims) throw new CuponLimit('AUDIENCE_LIMIT_REACHED');
    // unique(coupon,user) still protects duplicate user → try/catch
    CouponClaim::create(['coupon_id'=>$c->id,'user_id'=>$user->id,'claimed_at'=>now()]);
});
```

**Pattern 2 — Pessimistic claim_number via `coupon_claims` sequence (gapless 1..N):**

```php
DB::transaction(function () {
    $n = DB::table('coupon_claims')->where('coupon_id',$c->id)->lockForUpdate()->max('claim_number') ?? 0;
    if ($n >= 100) throw ...;
    DB::table('coupon_claims')->insert(['coupon_id'=>$c->id,'user_id'=>$u,'claim_number'=>$n+1]);
});
```

Either prevents `100+ concurrent users → 101 rows` because the thread that read `99` still holds the lock while inserting; the 101st thread sees `100` and aborts. Add a DB `CHECK (claim_number <= 100)` as defense-in-depth where DB supports it, or a partial `UNIQUE` on `(coupon_id, claim_number)` — MySQL does not enforce check until 8.0.16, so app lock is primary.

**Reservation vs Claim distinction:** `CouponReservationService::reserve()` is the **redemption gate** (30-min hold, released on expiry) and already uses `lockForUpdate` correctly — keep. `CouponClaim` is the **allocation gate** (long-lived, “who among the eligible got one of the 100 slots”). Two gates → two rows → two independent remaining counts, which is exactly the `max_claims vs max_redemptions` split audit asks for.

---

## 16. Assignment vs Claim vs Redemption (Mandatory Separation)

| Concept | What question it answers | Who is actor | Persisted where | Card. | Example row |
|---------|--------------------------|--------------|----------------|-------|-------------|
| **Targeting** | Who *should* receive/see? | Admin | `coupon_rule_groups` + `coupon_rules` (JSON value) — predicate, not users | 1→* | `type=registration_date, op=>=, value=2024-01-01` |
| **Eligibility** | *May* this user use it right now? | engine (server) | **nowhere** — computed per apply (pure `CouponEligibilityService`) | — | `eligible=true` for user #4921 because `created_at >= Jan 1` and `orders>=3` |
| **Assignment** | Who has been *pushed* a copy? (admin-driven) | Admin via `POST /coupons/{coupon}/assignments` | `coupon_assignments (coupon_id,user_id, max_uses, used, expires_at)` — unique | 1→* | `(coupon 17, user 9, max_uses=1)` |
| **Claim** | Who has *pulled* one of the limited N? (user-driven, race-prone) | User clicking “Claim” | `coupon_claims (coupon_id,user_id, claimed_at)` — unique | 1→* | `(coupon 17, user 103, claim_number 44)` |
| **Redemption** | Who *actually* consumed it? (money moved) | `recordCouponUsage` after `status=completed` | `coupon_usages` (public) or `coupon_assignment_usages` (assigned) — append-only | 1→* | `(coupon_assignment 22, order 501, used_at)` |

**Do not collapse** into one table — the cardinalities and lifetimes differ. A user can be eligible but not assigned/claimed yet, claimed but not redeemed, redeemed at most once per order, and `assigned` vs `claimed` are mutually exclusive modes: use **assignment** for “explicit user list” (marketing push), **claim** for “first N pull”; **do not create both for the same coupon** (validation rejects: if `ruleGroups.exists() && claim_capped?` → choose one — see DB §19).

---

## 17. Notification Architecture

### From existing (what already queues)

`CouponAssigned` → `SendUserCouponAssignedNotification` (ShouldQueue `meem-medium`, `UserCouponAssignedNotification` writes `notifications` DB + FCM) — single-user fanout today.

### Targeted campaign fanout (needs background)

```
Admin Create Coupon + Targeting
  └→ POST /coupons { discount, rules: [ {type:'registration_date', op:'>=', value:'2024-01-01'}, {type:'order_count', op:'>=', value:3} ] }
     → Coupon persist + rules persist
     → (if Snapshot audience requested) → dispatch CouponAudienceResolverJob
        → query-based audience (chunk 1000): INSERT INTO coupon_audience_members (coupon_id,user_id)
        → on complete: dispatch CouponAssignmentBatchJob (chunk 500)
           → each: CouponAssignment::firstOrCreate(coupon,user) → CouponAssigned event
              → SendUserCouponAssignedNotification (idempotent: unique(coupon,user) prevents dup insert; notification deduped via notifiable+type+data idempotency key in sent ledger or `notification_logs` if present)
     → else (Dynamic default) → notification is on-demand (at apply), not pushed
```

**Why not `User::all()->each(...)`?** Would OOM on 1M users. Use query chunk: `User::whereInSubAudienceQuery()->chunkById(1000, fn($users)=> ...)`. Queue one `CouponAssignmentBatchJob` per chunk (500 users per job for 1M users → 2000 jobs, each 500 inserts — fits Redis queue `meem-medium` + `block_for null`).

**Idempotency:** `CouponAssignmentRepository::assignCoupon` already guards `exists()` → 409; notification listener is `ShouldQueue` with dedup key `["coupon_assignment", $assignment->id]` so retried job does not double-notify.

**Snapshot vs push notification choice:** For dynamic campaigns (most), do **not** pre-assign 1M rows — eligibility is evaluated at `POST /coupons/apply` on demand; notifications are sent only to a **sample** or not at all. Snapshot + 1M assignments only for “everyone in segment gets it” blast — gated behind `estimated_audience > 50_000? → queue`.

---

## 18. Dynamic vs Snapshot Audience

| Axis | Dynamic (default) | Snapshot (opt-in per coupon) |
|------|-------------------|------------------------------|
| **Audience truth** | computed at `POST /coupons/apply` via `CouponEligibilityService` | computed once at campaign start, frozen into `coupon_audience_members` |
| **Query cost** | per-apply: one `exists(orders ...)` + one `count/lsum` per Rule — cheap with indexes (see §23) — ~2-3 queries for 3-rule group | `0` at apply: `whereIn(audience_members)` single index lookup |
| **Freshness** | Current: user who reaches `orders>=5` today becomes eligible instantly | Stale: user who later reaches 5 is still ineligible (audience frozen) |
| **First-N meaning** | “first 100 who successfully **claim** at apply time” — `coupon_claims` cap | “first 100 at snapshot time ordered by `created_at`” — `LIMIT 100` at materialization |
| **Storage** | 0 extra rows | `coupon_audience_members` N rows (up to 1M) |
| **When to use** | default; for `orders>=N`, `spend>=X`, `first claim` | when campaign must be “those 100 at launch, even if 101st later qualifies” or when preview must be frozen |
| **Campaign toggle** | `coupon_rules` with `audience_mode='dynamic'` (default) | `audience_mode='snapshot'` + `audience_frozen_at` |

**Recommendation:** Support **both**, with *dynamic* as default (zero pre-work) and *snapshot* as explicit opt-in (admin checkbox “Freeze audience at publish”). Snapshot builder reuses the same `CouponAudienceResolver` query atom.

---

## 19. Database Changes (Additive, 0 drops)

### New tables (required)

| Table | Why | PK | FK | Unique | Indexes | Status cols | Must / Deferred |
|-------|-----|----|----|--------|---------|-------------|-----------------|
| `coupon_rule_groups` | OR across groups (`(A AND B) OR (C)`), never put OR per-rule | `id` | `coupon_id → coupons` cascade | — | `(coupon_id, priority)` | `priority int, timestamps` | **Required** Phase 1 |
| `coupon_rules` | one predicate per row, `NOT` via `is_negated` | `id` | `group_id → rule_groups` cascade | — | `(group_id)`, `type` | `type string, operator string, value JSON, is_negated bool default false, timestamps` | **Required** Phase 1 |
| `coupon_claims` | **allocation ledger** for First-N pull (not redemption). Unique per user per coupon caps `max_claims`. | `id` | `coupon_id→coupons, user_id→users, order_id? NULL` | `UNIQUE(coupon_id,user_id)` + `UNIQUE(coupon_claim_token)` where token is idempotency `CouponClaim::createToken(user,coupon)` | `(coupon_id, claimed_at)`, `(coupon_id, claim_number)` if gapless number used | `claim_number int NULL (gapless optional), claimed_at, expires_at NULL (e.g. claim hold 15 min), status enum('held','redeemed','expired'), timestamps, token` | **Required** Phase 3 (limited audience) |

### New table (optional, gated)

| Table | Why | PK | FK | Unique | Indexes | Optional? |
|-------|-----|----|----|--------|---------|-----------|
| `coupon_audience_members` | materialized snapshot audience for N=100 frozen at launch / metric `eligible_users` | `id` | `coupon_id, user_id` | `UNIQUE(coupon_id,user_id)` | `(coupon_id, user_id)` composite, `(coupon_id)` | **Deferred** Phase 4 — only if snapshot requested |

### Alter existing (one, advisory)

| Table | Add | Why | Index |
|-------|-----|-----|-------|
| `coupons` add | `audience_mode enum('dynamic','snapshot') default dynamic`, `max_claims int NULL (NULL=uncapped)`, `audience_frozen_at NULL`, `deleted_at?` N/A — keep | caps live alongside `limiter` (redemptions) — `max_claims` = allocation, `limiter` = redemption | `INDEX(audience_mode)` if dashboard filters |

**No column per rule on `coupons`** — rule shape is always `JSON value` on `coupon_rules`. One new Rule type = one new `Rule` class + enum value, not a schema change.

**Idempotency fields:** every new append-only table gets a deterministic `UNIQUE(coupon_id,user_id)` (not an UUID) so re-queued `CouponTargetingJob` re-runs to `INSERT IGNORE` → 0 dups.

**Migration plan sketch (additive, non-destructive):**

```php
Schema::create('coupon_rule_groups', fn($t) => { $t->id(); $t->foreignId('coupon_id')->constrained()->cascadeOnDelete(); $t->unsignedSmallInteger('priority')->default(0); $t->timestamps(); $t->index(['coupon_id','priority']); });
Schema::create('coupon_rules', fn($t) => { $t->id(); $t->foreignId('group_id')->constrained('coupon_rule_groups')->cascadeOnDelete(); $t->string('type',32); $t->string('operator',16); $t->json('value')->nullable(); $t->boolean('is_negated')->default(false); $t->timestamps(); $t->index('group_id'); $t->index('type'); });
Schema::create('coupon_claims', fn($t) => { $t->id(); $t->foreignId('coupon_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->unsignedInteger('claim_number')->nullable(); $t->timestamp('claimed_at')->useCurrent(); $t->timestamp('expires_at')->nullable(); $t->enum('status',['held','redeemed','expired'])->default('held'); $t->string('token',64)->nullable(); $t->timestamps(); $t->unique(['coupon_id','user_id']); $t->unique('token'); $t->index(['coupon_id','claimed_at']); $t->unique(['coupon_id','claim_number']); });
```

**No backfill** — existing `coupon_assignments` stay. `coupon_rule_groups` empty for all existing coupons → `CouponEligibilityService` treats “no groups → eligible (no targeting)” so public coupons stay public without row.

---

## 20. API Changes (Additive, no break)

### Request envelope

All coupon admin routes are `auth:sanctum` + `Permission::*`. New fields are **optional**:

**POST /api/v1/coupons** (extends `CouponRequest` — existing 9 rules for discount/limiter/image/status remain, add optional additive):

```json
{
  "name": {"en":"Welcome","ar":"..."},
  "discount_type":"percentage","discount":10,"max_discount_amount":50,
  "start_date":"2026-09-08","end_date":"2026-10-08","limiter":1000,
  "status":1,
  "targeting": {
    "audience_mode":"dynamic",
    "max_claims": 100,
    "rule_groups": [
      {
        "priority":0,
        "rules":[
          {"type":"registration_date","operator":">=","value":"2024-01-01","is_negated":false},
          {"type":"order_count","operator":">=","value":3,"is_negated":false},
          {"type":"lifetime_spend","operator":">=","value":500,"is_negated":false}
        ]
      },
      {
        "priority":1,
        "rules":[
          {"type":"email","operator":"like","value":"%@example.com"}
        ]
      }
    ]
  }
}
```

`targeting.rule_groups[].rules[].type` enum (Phase 1): `explicit_user|explicit_email|email_domain|registration_date|order_count|lifetime_spend|product_purchase|category_purchase|average_order_value|customer_segment|used_coupon|not_used_coupon|cart_abandoned`.

`PUT /api/v1/coupons/{coupon}` adds same `targeting` key — patch semantics: omitted → unchanged, `[]` → clear all targeting (publicize).

### Response envelope (always additive)

Existing admin show/list via `CouponResource` — add:

```json
{
  "data": { "id":7, "code":"coupon_XXX", "status":true, "limiter":1000, "used":12, "is_valid":true,
    "targeting": {
      "audience_mode":"dynamic","max_claims":100,
      "audience_frozen_at": null,
      "rule_groups": [ { "id":2, "priority":0, "rules":[ {"id":9,"type":"order_count","operator":">=","value":3,"is_negated":false}]}]
    },
    "audience_stats": { "estimated_members": 4823, "claims_remaining": 88, "redemptions_remaining": 988 }
  }
}
```

### Preview audience (new, essential before publish)

**`POST /api/v1/coupons/{coupon}/audience-preview`** (admin, `view-coupon-assignments` reused)

```json
POST /api/v1/coupons/5/audience-preview
Body: { "rule_groups": [/* as above */], "max_claims":100 } // dry-run; coupon Id ignored if not yet created
→ 200 {
  "estimated_audience_size": 4823,
  "sample_users": [ { "id":9, "email":"a@b", "created_at":"2024-02-01", "orders_count":4, "lifetime_spend":512.0 } ×20 ],
  "explanation": [ { "group":0, "matched_pct": 0.12, "failed_reasons": ["order_count: 38% failed"] } ],
  "first_n_cutoff_ids": [9,17,…] // for audience_mode snapshot: first 100 created_at
}
```

Implementation: **synchronous** for `estimated <= 10_000` (single query `EXPLAIN`), else `estimated` computed via `COUNT(*)` with rule filters, samples via `orderBy(created_at) limit 20` — all query-based, no `User::all`.

### Apply coupon (existing `POST /api/v1/coupons/apply {code}` — backward compat, then enhanced)

**Request stays** `{code}`. Under the hood, `CouponService::addCouponToCart` now calls `CouponOrchestrator::validate` which now internally calls `CouponEligibilityService::check`. **No new request field needed** — eligibility is **server-recomputed** at apply from the applying `user`.

**Enhanced error (structured reason, §14):**

```json
// Existing 400 path kept for non-eligibility, now with reason_code + message
POST /api/v1/coupons/apply {"code":"WELCOME100"}
→ 400 {
  "eligible": false,
  "reason_code": "ORDER_COUNT_REQUIREMENT_NOT_MET",
  "message": "This coupon requires at least 5 successful orders.",
  "failed_rules": [ { "type":"order_count","operator":">=","value":5, "current":3 } ]
}
```

Reason codes surfaced: `COUPON_DISABLED|COUPON_NOT_STARTED|COUPON_EXPIRED|COUPON_USAGE_LIMIT_REACHED|USER_NOT_ASSIGNED|ASSIGNMENT_EXPIRED|USAGE_QUOTA_EXCEEDED|ORDER_COUNT_REQUIREMENT_NOT_MET|SPEND_REQUIREMENT_NOT_MET|PRODUCT_REQUIREMENT_NOT_MET|EMAIL_REQUIREMENT_NOT_MET|AUDIENCE_LIMIT_REACHED|ALREADY_CLAIMED|REGISTRATION_REQUIREMENT_NOT_MET|CATEGORY_REQUIREMENT_NOT_MET`.

Internal-only reasons (e.g. `INTERNAL_RULE_PARSING_ERROR`) stay 500.

### Existing endpoints unchanged (backward compat)

- `POST /api/v1/coupons/{coupon}/assignments` stays (push mode).
- `POST /api/v1/general/checkout` stays; `recordCouponUsage` stays.

---

## 21. Security Model

| Vector | Attack | Mitigation (where) |
|--------|--------|-------------------|
| **Coupon enumeration / guessing** | `coupon_XXXXXXX` is not random enough (7 chars alnum); brute `POST /coupons/apply` can discover public coupons | Add rate limit on `applyCoupon` (`throttle:10,1`), do not leak existence: `CouponValidator::validateByCode` on unknown code → same generic `coupon.not_found` 400 without disclosing limiter | 
| **User ID manipulation** | `POST /coupons/{coupon}/assignments {user_id: victim}`  → admin tries to steal | `CouponAssignmentRequest` already `exists:users,id` + `permission:create-coupon-assignment` + server derives eligibility from `auth()->id()` on apply, not from payload `user_id` | 
| **Assignment / audience membership tamper** | `PUT coupons/{c}/assignments/{a} {max_uses:100}` or claim another user's slot | `CouponAssignmentRepository::updateAssignment` checks `coupon_id` scoping + `ModelNotFoundException` if assignment not for coupon; `CouponClaim` checks `UNIQUE(coupon,user)` so claim of another user id requires auth swap — apply always uses `auth()->id()` | 
| **Mass assignment** | `name, discount…` via `fill()` | `Coupon.php:18 fillable` is whitelisted (`dataArray` in repo), new `targeting` is not fillable on Coupon — rules are handled via explicit `CouponTargetingRepository` | 
| **Race / replay / duplicate claims** | `POST /coupons/claim` double click | `CouponClaim` unique `(coupon,user)` + `coupon_claims.token = hash(coupon+user+timestamp ceil)` + idempotent `POST /coupons/{coupon}/claim` returns existing on duplicate | 
| **Duplicate notifications on retry** | `SendUserCoupon*Notification` re-queued on crash | Already `ShouldQueue meem-medium` + unique assignment means second `CouponAssigned` cannot fire; listener is made idempotent via `notifications` DB `type+notifiable` dedup key | 
| **Server authority** | frontend “I am eligible” param | `CouponOrchestrator::validate(user=userFromToken)` only — no `user_id` in body ever trusts frontend | 
| **AuthZ** | `view-coupon-assignments` leak | `CouponAssignmentController` already `permission:` middleware; new `audience-preview` reuses same | 

---

## 22. Concurrency Model

| Moment | As-is safe? | New first-N safe? |
|--------|-------------|-------------------|
| `POST /coupons/apply` cart string write | No lock (advisory, but checkout filters) | — |
| `OrderService::addItemsInOrder()` `lockForUpdate(coupon)` before validate | **safe** | — |
| `CouponReservationService::reserve(order)` `lockForUpdate(coupon)` + `lockForUpdate(activeReservations) count` + `used + reservations < limiter` | **safe** for `limiter` | Adopt same lock for `max_claims` in `CouponClaim` |
| `recordCouponUsage()` `increment(used)` | atomic `increment` safe (not lock→read→write) but coupled with reservation lock | Keep |
| **New `coupon_claims` insert (first 100)** | — | **Must be:** `CouponClaimService::claim(user,coupon)` → `DB::transaction → Coupon::lockForUpdate → count(lockForUpdate) → insert` + `UNIQUE(coupon,user)` try/catch → 101st throws `AUDIENCE_LIMIT_REACHED` (no `if(count<100) create`). Add DB `CHECK`/partial index where DB supports it as backup. |
| **Bulk assignment of 1M rows** | — | `CouponAssignment::firstOrCreate` per row is atomic vs duplicate, but 1M × exists = 1M queries. Use `INSERT IGNORE` chunk or `INSERT … ON DUPLICATE KEY UPDATE` — Laravel `upsert(['coupon_id','user_id'], ['max_uses'=>…])` | 

**Property to keep going forward:** no `if ($count < limit) create()` outside a `lockForUpdate` transaction anywhere — grep for `count()` on coupons/assignments in review must all be inside `DB::transaction(lock)`.

---

## 23. Performance Model (1M users, 10M orders, 100k coupons)

| Anti-pattern forbidden | How the plan avoids it |
|------------------------|------------------------|
| `User::all()->each(...)` | `User::query()->whereInSub(audienceSubQuery)->chunkById(1000)` + chunked jobs `Queue::push(CouponAudienceChunkJob, $chunk)` (each 1000 users, 1000/10 jobs per chunk). Same for orders sweep. |
| Per-user `N+1` order count query at apply | At apply we do **exactly** 1 `orders count` + 1 `sum(total_price)` per eligibility check (not per rule loop); each is `select count(*) where user_id=? and status='completed'` with composite `INDEX(user_id, status, created_at)` — add if not exists. `ProductPurchaseRule` does single `exists(order_products→orders where user_id)`. No loop over order rows. |
| Loading 10M orders into PHP | `LazyCollection`/`cursor()` not needed — counts via `COUNT(*)` push to DB, `chunkById` for fanout only when Snapshot. |
| Wildcard `%LIKE` on 1M emails | `EmailDomainRule` uses `email LIKE '%@example.com'` with `LIKE` prefix index? Instead use `email_domain` generated column (deferred) or `WHERE email LIKE CONCAT('%',SUBSTRING_INDEX(email,'@',-1))` fallback; price: okay for preview `count`, not per-apply (per-apply is single `where email=user.email check like domain`). |
| No indexes | Additive migrations per rule type (see §19): `(user_id, created_at)`, `(user_id, status)`, `(coupon_id, expires_at)`, `(coupon_assignment_id, order_id)` — all already partially present, verify and add `orders(user_id, status, total_price)` composite. |
| Fully dynamic 3-rule evaluation on each apply is 3 queries — okay at 200 RPS? | **Yes** — 3 indexed `count(*)` + UQ lookups ~ 5ms on buffered MySQL; cache audience preview `estimatedAudience` for 60s with `CACHE_PREFIX coupon:audience:{hash}` if needed. Snapshot materializes when dynamic is too hot (opt-in). |
| Precomputed metrics | **Not in Phase 1** — do not add `customer_metrics` table with `orders_count, spend` column until 1M-user preview proves `count(*)` is hot. If hot, add `customer_lifetime_view` materialized view (Phase 4). |

**Budget for first 100 chunking:** push claim path is **zero fanout** (one `INSERT` per claimant). Audience *preview* for “first 100 registered where …” is `COUNT(*)` + `ORDER BY created_at LIMIT 20` — single query.

---

## 24. Admin / Reporting Model

| Metric | Source | Persisted or computed | Where shown |
|--------|--------|-----------------------|-------------|
| Target audience (size) | `COUNT(users where ruleGroups pass)` | computed (`audience-preview`) | admin `GET /coupons/{id}` `audience_stats.estimated_members` |
| Eligible users | `COUNT(users where check(coupon,user).eligible)` — same as target for dynamic, `count(audience_members)` for snapshot | dynamic = same as target; snapshot = `count(coupon_audience_members)` column |
| Assigned users | `count(coupon_assignments)` + `sum(used)` | `COUNT` | assignment tab |
| Claimed users | `count(coupon_claims where status held/redeemed)` | `COUNT` | assignment tab |
| Notified users | `count(notifications where type=CouponAssigned and data->coupon_id=…)` | computed from `notifications` table | notification ledger |
| Redeemed users | `count(coupon_assignment_usages) + count(coupon_usages where coupon_id)` | `COUNT` | usage tab |
| Remaining claims | `max_claims - count(coupon_claims)` (NULL = ∞) | computed | badge |
| Remaining redemptions | `limiter - used` (NULL = ∞) | `Coupon:: (limiter - used)` | badge |
| Conversion rate | `redeemed / eligible` | computed | dashboard |
| Usage rate | `redeemed / assigned-or-claimed` | computed | dashboard |

**Persistent:** `coupon_claims`, `coupon_assignments`, `*_usages`, `coupon_audience_members` (if snapshot), `coupon_reservations` TTL rows. **Computed:** all percentages/rates.

---

## 25. Migration Strategy (Non-Destructive)

```
Existing DB (coupons, coupon_assignments, coupon_usages, coupon_assignment_usages, coupon_reservations)
  ↓
Additive migration 1 — new tables `coupon_rule_groups`, `coupon_rules` (no down-drop of old data)
  → existing coupons have 0 groups → “no targeting → eligible” (backward compat) → dual-read: old assignment check still runs
  ↓
Backfill none — there is nothing to backfill (rules empty)
  ↓
Additive migration 2 — `coupon_claims` + optional `coupon_audience_members`, plus 2 helper indexes on `orders(user_id,status)` if missing
  ↓
Deploy new eligibility seam: `CouponOrchestrator::validate` now calls `CouponEligibilityService` only when `coupon->ruleGroups()->exists()` — else delegates to existing `CouponValidator/CouponAssignmentValidator` path (dual compatibility)
  ↓
New eligibility engine toggle: `config/coupons.eligibility_v2 = true` flag, default true in staging, flip per coupon type if needed
  ↓
Verify: existing public and assigned coupons still apply via old path (regression: `CouponsProductionHardenTest` 28/29 green — keep)
  ↓
Remove legacy scattered if/else only after 2 release cycles and after `audience-preview` smoke on 100k-row canary (never during the same deploy)
```

**Rule per bridge:** every migration has `down` that drops only the *new* table, never `coupons` or `coupon_usages`. `coupon_claims` is `NULLABLE order_id` so no old FK swap.

---

## 26. Test Strategy (Matrix — what “green” means before publish)

### Public coupons

| # | Title | Setup | Expect |
|---|-------|-------|--------|
| P1 | eligible valid | `status 1, within dates, limiter NULL, no assignments, no prior usage, valid product` | `CouponOrchestrator::validate→ valid` |
| P2 | ineligible: public coupon already used | `coupon_usages` row for same `coupon_id,user_id` | `reason=already_used` |

### Assigned coupons

| # | Title | Expect |
|---|-------|--------|
| A1 | assigned user | assignment row `max_uses 1, used 0` → valid |
| A2 | unassigned user with assignments present | `not_assigned` |
| A3 | multiple users same coupon | 3 assignments, each user sees `valid` for their row, 4th user sees `not_assigned` |
| A4 | duplicate assignment attempt | `POST /coupons/{c}/assignments {user_id: same}` → `409 COUPON_ALREADY_ASSIGNED_TO_USER` |

### Rules (each against `CouponEligibilityService::check` + via `POST /coupons/apply`)

| # | Type | Operator | Value | User | Expect |
|---|------|----------|-------|------|--------|
| R1 | registration_date | `>=` | `2024-01-01` | user `created_at 2024-02-01` | valid |
| R2 | order_count | `>=` | `5` | 4 `completed` orders vs 5 | `ORDER_COUNT_REQUIREMENT_NOT_MET` + current 4 reported |
| R3 | spend | `>=` | `1000` | sum 999 | `SPEND_REQUIREMENT_NOT_MET` |
| R4 | product | `in` | product 9 | has order_products product 9 → valid, else `PRODUCT_REQUIREMENT_NOT_MET` |
| R5 | category | `in` | category 3 | via `order_products→products.category_id` |
| R6 | email | `like` | `%@example.com` | other domain → fail |
| R7 | segment | `in` | segment 2 | membership table member exists → valid |
| RC1 | AND group | `order_count>=5 AND spend>=1000` | need both | fail on either missing |
| RC2 | OR group | `(group1: order_count>=5) OR (group2: email in [...])` | any group passes → valid | prove that adding group satisfies |
| RC3 | NOT | `is_negated:true` on `used_coupon(17)` | user used 17 → `NOT(used)` fails | verify `USER_NOT_ELIGIBLE` for negated hit |

### Limited audience (critical — concurrency)

| # | Scenario | Harness | Accept |
|---|----------|---------|--------|
| L1 | 100 successful claims → 101st rejected | 100× `POST /coupons/{c}/claim {user_id sequential}` under `DB::transaction + lockForUpdate` → all 100 200, 101st 409 `AUDIENCE_LIMIT_REACHED` | `count(coupon_claims)=100`, no 101 |
| L2 | **100+ concurrent claimers (race test)** | 120 `async POST /coupons/{c}/claim` from 120 distinct users at same wall-clock (use `artisan queue:work` parallel harness or `parallel` in `CouponsProductionHardenTest` pattern) | `count ≤ 100`, no `UNIQUE` exception leaked 500 — either 200 or 409 only |
| L3 | Same user double claim | two concurrent same `user_id` | one 200, one 409 or idempotent 200 with same token, but second does not steal slot |

### Payment / order lifecycle (must not treat checkout as “once”)

| # | Scenario | Expect |
|---|----------|--------|
| O1 | successful payment → `completed` | `recordCouponUsage` creates usage + `increment(used)` + `AssignedCouponConsumed` event (afterCommit) |
| O2 | failed payment (`!success`) | transaction `failed`, cart coupon stays, usage NOT recorded, reservation alive 30 min then released |
| O3 | cancelled order (api cancel + `OrderCancelled` event) | usage NOT recorded, coupon still on cart |
| O4 | item refund after `completed` | **no usage rollback** — `used` stays (app policy `NEVER` decrement), refunded order still counted — document |
| O5 | duplicate `changeOrderStatus('completed')` retry | `firstOrCreate` `coupon_usages` → `wasRecentlyCreated==false` → no double `increment` |
| O6 | coupon limiter=1 raced | two `orders::lockForUpdate` + `reserve` path — second sees `used+reservations >= limiter` → 409 before order create |

### Scale gate

`POST /coupons/{c}/audience-preview` with 3-rule group on canary with 50k users → returns in < 2s (`count(*)`) without `User::all`.

---

## 27. Implementation Phases (Ordered road map — dependency-truthful)

### Phase 1 — Foundation (1 engineer × 3d)

* **Goal:** central eligibility seam without behavioral change; keep regression green.
* **Files:** `app/Services/Coupon/CouponEligibilityService.php` (empty pass-through), `app/Contracts/CouponEligibilityRule.php`, `app/Enums/CouponRuleType.php` (enum), wire `CouponOrchestrator::validate` to call `CouponEligibilityService::check` only when `ruleGroups.exists()`. **No rule class yet.**
* **DB:** no migration. **Tests:** add regression `CouponOrchestrator still validates disabled/expiry/limiter/product as before when targeting empty` (2 tests). **Accept:** `CouponSystemTest, CouponsProductionHardenTest` still green.

### Phase 2 — Rule Engine (7 rules — proving viability) (1eng × 5d)

* **Rules:** `RegistrationDateRule`, `OrderCountRule`, `LifetimeSpendRule`, `ProductPurchaseRule`, `CategoryPurchaseRule`, `ExplicitUserRule`, `EmailDomainRule`.
* **Files:** `app/Services/Coupon/Rules/{*Rule}.php`, `app/Services/Coupon/CouponRuleRegistry.php`, `coupon_rule_groups / coupon_rules` migration + models, `CouponAudienceResolver` for audience preview **COUNT only** (no materialization).
* **API:** `POST /api/v1/coupons/{coupon}/audience-preview` (one new controller).
* **Tests:** per-rule R1–R7 above; `RegisterUserCreatedAfter(2024-01-01) → 2023 user fails`. **Accept:** preview returns correct `estimated_audience_size` (±5%) and samples.

### Phase 3 — Rule Composition (AND/OR/NOT + nested groups) (3d)

* **Group semantics:** implement `CouponRuleGroup.is_negated` per-rule + `CouponRuleGroup` OR (each group `AND` inside). Keep 2-level nesting; document that 3+ levels is `OR` groups chained.
* **Files:** `CouponRuleGroup` `priority` reordering, `app/Services/Coupon/RuleGroupEvaluator.php`.
* **Tests:** RC1–RC3 (`AND` group, `OR` group, `NOT(used_coupon)`).

### Phase 4 — Audience (preview → snapshot materialization) (4d)

* **Resolver:** `CouponAudienceResolver::estimate()` ( `COUNT(*)` ), `sample()` (`limit 20`), `materialize()` (chunk `insertIgnore` into `coupon_audience_members`).
* **Snapshot toggle:** column `coupons.audience_mode` + flag `audience_frozen_at`; front “Freeze audience at publish” sends `audience_mode=snapshot`.
* **Job:** `AudienceMaterializeJob` (queued chunk 1000, idempotent).
* **Tests:** materialize 10k users → verify `count(audience_members)==count(estimated)`.

### Phase 5 — Limited Audience (First N claims, atomic) (3d)

* **First 100 claim semantics defined** (see §15 — claim, not registration `id<=100`).
* **Files:** `coupon_claims` migration, `CouponClaimService::claim(user)` with `Coupon::lockForUpdate + count/lockForUpdate + UNIQUE(coupon,user)` try/catch, reason `AUDIENCE_LIMIT_REACHED`.
* **Tests:** L1, L2 concurrency harness (`100+ concurrent → ≤100`), L3 dup user.

### Phase 6 — Notifications (1d — polish)

* Hook `CouponAssigned` batch fanout to `CouponAssignmentBatchJob` (current `CouponAssigned` already per single assignment; for claim-path add `CouponClaimed` → `SendUserCouponClaimedNotification`), dedup via unique `notifications` idempotency key.
* Tests: `CouponNotificationE2ETest` extended with `claimed → notDoubleSentOnRetry`.

### Phase 7 — Admin/API (2d)

* `POST /api/v1/coupons` / `PUT` `targeting` additive JSON (see §20 request example).
* `GET /api/v1/coupons/{coupon}` adds `targeting` + `audience_stats` + `claims_remaining`.
* `POST /apply` enriches error with `reason_code + failed_rules[0]` (still 400).
* Admin page `targeting.rule_groups` editor.

### Phase 8 — Hardening (3d)

* Add missing `orders(user_id,status, created_at)` composite if explain shows full scan; add `order_products(product_id)` with `index on orders.user_id`.
* Race proof: `grep count\(\)` on coupons/assignments must all be inside `lockForUpdate`.
* Scale test on canary 50k users / 200k orders: `preview` < 2s, `claim` p95 < 100ms.

---

## 28. Exact Files Expected to Change

| File | Change | Why | Phase |
|------|--------|-----|-------|
| `app/Services/Coupon/CouponOrchestrator.php:22` | + `CouponEligibilityService::check` call (only when ruleGroups exist) | central seam | 1 |
| `app/Services/Coupon/CouponValidator.php:11` | keep (backward compat) | discount/limit already correct | 1 (read) |
| `app/Services/Coupon/CouponAssignmentValidator.php:10` | keep; add `UserNotEligible` mapping to `reason_code` | assignment still layer 1 | 1 |
| `app/Services/Coupon/CouponCalculator.php:10` | **do not change** — not eligibility | separation | — |
| `app/Services/Coupon/CouponEligibilityService.php` | **new** (composes Rule groups) | rule evaluation | 1-2 |
| `app/Services/Coupon/Rules/*Rule.php` ×12 | **new** per `CouponRuleType` | rule impl | 2-3 |
| `app/Contracts/CouponEligibilityRule.php` | **new** interface | extensibility | 1 |
| `app/Services/General/CouponService.php:14` | unchanged or thin alias → `CouponOrchestrator::validateByCode` | keep storefront `addCouponToCart` path | — |
| `app/Services/General/OrderService.php:772` `recordCouponUsage` | keep but add `coupon_claims.status=redeemed` transition on success for claim path | redemption accounting | 5 |
| `app/Services/Coupon/CouponReservationService.php:17` | keep as redemption gate; add `CouponClaimService` beside it (not inside) | limited allocation vs consumption | 5 |
| `app/Services/Coupon/Audience/CouponAudienceResolver.php` | **new** (query builder + chunk) | audience counting/sampling/materializing | 4 |
| `app/Models/CouponRuleGroup.php`, `CouponRule.php`, `CouponClaim.php`, `CouponAudienceMember.php` | **new** Eloquent models | data for rules/claims/snapshot | 2,5,4 |
| `database/migrations/2026_09_09_*_create_coupon_rule_groups.php` | **new** | groups | 2 |
| `database/migrations/2026_09_09_*_create_coupon_rules.php` | **new** | rules | 2 |
| `database/migrations/2026_09_09_*_create_coupon_claims.php` | **new** | first-N | 5 |
| `database/migrations/2026_09_09_*_create_coupon_audience_members.php` | **new** (optional flag `audience_mode`) | snapshot | 4 (opt) |
| `database/migrations/2026_09_09_*_add_audience_mode_to_coupons.php` | **new** (`audience_mode`, `max_claims`, `audience_frozen_at`) | cap columns | 4-5 |
| `packages/marvel/src/Database/Repositories/CouponRepository.php:24` | + `with('ruleGroups.rules')` eager + JSON targeting persistence hook | admin CRUD extension | 2 |
| `packages/marvel/src/Database/Repositories/CouponAssignmentRepository.php:24` | unchanged (push path) | assignment push path kept | — |
| `packages/marvel/src/Http/Controllers/CouponController.php` (Marvel) | + `targeting` validation via Rule request | admin API | 2 |
| `packages/marvel/src/Http/Controllers/CouponAssignmentController.php:9` | unchanged (push) | push | — |
| `app/Http/Controllers/Api/General/CouponController.php:33` `applyCoupon` | keep `addCouponToCart` alias; add `reason_code` to 400 JSON when orchestrator fails (inside service, not controller if/else) | contract enrichment | 2-3 |
| `packages/marvel/src/Http/Requests/CouponRequest.php` | + `targeting.rule_groups.*.rules.*` nested rules | admin validation | 2 |
| `packages/marvel/src/Http/Requests/CouponAssignmentRequest.php` | unchanged | — | — |
| `packages/marvel/src/GraphQL/Mutations/CouponMutator.php` | + `targeting` input (mirror of REST) if GraphQL used | parity | 2 |
| `app/Jobs/CouponAudienceChunkJob.php`, `CouponAssignmentBatchJob.php` | **new** ShouldQueue idempotent jobs | fanout for M users | 4/6 |
| `api-desc/coupon/**` + `docs/coupon-lifecycle.md` | **update** (doc only) | audit kept current | 1 |
| `resources/lang/en/coupon.php` | + reason_code translations | i18n | 2 |
| `config/coupon.php` (if exists) or `config/coupons.php` | **new** `max_audience_page_size, claim_hold_minutes` (seconds) | tunable caps | 5 |

**Count:** ~22 files added, ~6 existing edited, 0 existing deleted.

---

## 29. Exact Files Expected NOT to Change

| File | Why leave alone |
|------|----------------|
| `app/Services/Coupon/CouponCalculator.php:10` | discount math — not targeting |
| `app/Models/CouponReservation.php:18` + `CouponReservationService` redemption guard | already correct for limiter race |
| `app/Services/General/CartInventoryService.php` | cart lifecycle already clears `carts.coupon` string via `coupon=null` on cart expiry; no targeting knowledge needed |
| `app/Observers/CouponObserver.php:11` | logging only |
| `packages/marvel/src/Database/Models/Order.php` (+ checkout) | order snapshot columns correct; add `promotion` but not coupon targeting |
| `database/migrations/2024_12_27_*_create_coupon_usages_table.php` (+ 2026_07_* assignments) | **do not alter** — `UNIQUE(coupon,user)` is correct for public; claim ledger is additive |
| `tests/Feature/CouponSystemTest.php` + existing `CouponValidatorTest` assertions | keep green as is |
| `app/Events/OrderCreated` pipeline | not coupon |
| `resources/lang/ar/coupon.php` + `packages/marvel` asset stubs (images) | not functional |

---

## 30. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation (concrete file) |
|------|-----------|--------|----------------------------|
| **“First 100 by `users.id<=100`” mis-semantic** | High if ambiguous spec | campaign gives wrong 100 (deletions skew) | §15 distinction + `CouponClaimService` allocation (not registration `id` limit) + preview `orderBy(created_at)` |
| **`if(count<100) create()` race → 101 claims** | High if naïve | over-allocate discount | `CouponClaimService::claim()` lock pattern §22 + `UNIQUE(coupon,user)` + try/catch 409 (`CouponClaimService.php`) |
| **Adding a new rule needs `coupons` column** | Medium if rule schema is per-column | schema churn, 100k-row migration lock | **Forbid per-rule column** — rule lives in `CouponRule.value JSON`; one new `Rule` class per type (§14) |
| **Dynamic `orders>=N` evaluated per apply is hot** | Medium at 1M users × 200 RPS | 3 `COUNT(*) where user_id` per apply = 3 queries × 5ms = acceptable, but N-rule groups × M users × `apply` peak is linear | `INDEX(user_id,status,created_at)` on `orders`, preview cache 60s `coupon:audience:{hash}`, opt-in Snapshot for frozen campaigns (§23) |
| **Pricing `order.count(*)` fallback `User::all()`** | Low (code review forbids) but easy to slip | OOM on 1M users | Review gate: veto any `User::all()->filter()`; enforce `chunkById` + `LazyCollection` in `CouponAudienceResolver` |
| **Assigned vs public semantic leak** (coupon with 1 assignment becomes restricted — existing behavior #2 above) — adding 100 assignments to a formerly public coupon instantly locks out existing public users | Medium | surprise `not_assigned` for public user | Document §4.7: **migration note** — if coupon had `ruleGroups` and `audience_mode=dynamic`, treat “no `coupon_assignments` + no `coupon_claims` + empty rules” as *public* → add `allow_public_fallback bool` on coupon (default `false` for targeted launch) |
| **Duplicate notifications on job retry** | Medium | spam FCM | `CouponAssignmentRepository::assignCoupon` emits `CouponAssigned` **after** commit (correct); `SendUserCouponAssignedNotification` is `ShouldQueue` + dedup on `(type=CouponAssigned, coupon_assignment.id)` via `notification_logs` unique |
| **Coupon never returned on cancellation** surprise | Medium | customer complaints | §6 docblock makes this policy explicit; for “first 100” claim flow, add `coupon_claims.expires_at` so un-redeemed claim auto-releases via `ExpireCouponReservations` twin job |

---

## 31. Final Recommendation

### Is the current coupon architecture healthy enough to extend?

**YES — extend current architecture.**

### Why “YES” (and what very small refactoring is required first)

**Foundation is sound:**
- `CouponOrchestrator → CouponValidator + CouponAssignmentValidator + CouponCalculator` is already the **capable central eligibility node** the new system needs; the only reason to replace it would be if validation were scattered across controllers — it is not (except `carts.coupon` string being the only host, which is fine and will stay).
- `CouponAssignment` (`max_uses, used, expires_at, UNIQUE(coupon,user)`) already models per-user grant with expiry; `CouponAssignmentUsage` is immutable audit; `CouponUsage` (`UNIQUE(coupon,user)`) already caps public coupons per-user; `CouponReservation` already solves the hotel-booking “someone started paying” race via `lockForUpdate(coupon)+lockForUpdate(activeReservations) count` (`CouponReservationService.php:27`).
- DB constraints are **real** (`UNIQUE`, `INDEX`, `lockForUpdate` in the critical consumption path `OrderService:772`), so the system has a durable concurrency story, not just app-level `if`.

**Missing is precisely the additive layer:**
- There is **no Rule abstraction** today — every targeting predicate (registration date, order count, spend, product, category, email domain) would else become another column or `if` in `CouponValidator`. That is the **sole foundation gap**, and it is fixed by adding one interface + one `RuleGroup` model + one seam in `CouponOrchestrator` (Phase 1). No table needs to be rewritten, no counter needs to be re-incremented, no order snapshot changes.

**Hence the smallest refactor before targeting is:**

> **Phase 1 — Foundation (3 days):** add `app/Contracts/CouponEligibilityRule`, `app/Services/Coupon/CouponEligibilityService` (pass-through when `ruleGroups` is empty), and a single call from `CouponOrchestrator` when targeting exists. Keep all `CouponValidator` checks as-is. Add 2 regression tests that re-prove the existing public/assigned paths through the new seam. This is < 300 LOC and unlocks the rest.

**If the answer had been “NO”, it would have required:** re-creating `Coupon` → `CouponUsage`’s `UNIQUE(coupon,user)` per-public-coupon semantics, moving `carts.coupon` from string to FK (breaks many reads), or rewriting `OrderService::recordCouponUsage`’s append-only audit — all of which the audit shows are **correct and stay**.

### Safest implementation plan with minimal disruption

Follow **§27 Phases 1→8 in order**, with **Phase 2 (Rule Engine, 7 rules) + Phase 5 (First N claim)** as the prove-viability slice:

1. **Phase 1** leaves existing public and assigned coupons 100% compatible (`ruleGroups()->exists()==false → old path`). Deploy to staging alone — no UI change.
2. **Phase 2** add the 7 rules demanded by the “first 100” + product/category basis (registration, order count, spend, product, category, explicit user/email, email domain). No snapshot yet — dynamic eligibility only.
3. **Phase 5** adds `coupon_claims` (atomic First N) **before** large fanout — this is the allocation primitive for the “first 100 eligible who claim” demo on 120 concurrent users (test L2). Prove `≤100`.
4. Then **Phase 4** (snapshot materialization) and **Phase 6–8** (notifications + admin + hardening).

**Rule of thumb for each PR:** no PR adds a column to `coupons` per rule; every PR is test-green on `CouponSystemTest + CouponsProductionHardenTest` without touching existing fixtures except to add rule fixtures in `audience-preview` shape.

---

## Appendix — Traceability: Every Significant Claim → File

| Claim | File:line |
|-------|-----------|
| `code` auto `coupon_RANDOM7` | `Coupon.php:48` |
| `limit: limiter NULL=unlimited, used counter` | `Coupon.php:18` + `CouponValidator.php:11` |
| public vs assigned binary (`exists()`) | `CouponAssignmentValidator.php:15` + lifecycle doc finding #2 |
| `UNIQUE(coupon,user)` assignment | `2026_07_15_000003_create_coupon_assignments_table.php:18` |
| `coupon_assignment_usages` unique order | `2026_07_15_000004:21` |
| `coupon_reservations` unique order, index (coupon,expires) | `2026_08_31_120100` |
| `CouponOrchestrator::validate` is the only apply entry | `CouponOrchestrator.php:22` |
| `CouponValidator` already_used via `coupon_usages` | `CouponValidator.php:11` |
| `CouponReservationService::reserve` lock pattern | `CouponReservationService.php:27` |
| `OrderService::recordCouponUsage` only on `status=completed` + `firstOrCreate + increment` | `OrderService.php:772` |
| `POST /coupons/apply` → `CouponService::addCouponToCart` → `Cart.coupon` string | `CouponService.php:60` |
| `POST /coupons/{coupon}/assignments` → `CouponAssignmentRepository::assignCoupon` → `CouponAssigned` → `SendUserCouponAssignedNotification` | `CouponAssignmentRepository.php:24,60` + `EventServiceProvider` + `SendUserCouponAssignedNotification.php:10` |

---

*End of plan — read-only audit complete. Next step when approved: `git checkout -b feat/coupon-targeting-phase1-foundation` and implement Phase 1 per §27 (no pre-emptive Phase 2 code).*
