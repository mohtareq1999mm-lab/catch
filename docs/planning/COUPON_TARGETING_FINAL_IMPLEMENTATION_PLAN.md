# Coupon Targeting, Assignment, Eligibility & Claim System
## Final Implementation Plan

**Project:** meem  
**Date:** 2026-09-07  
**Status:** Planning Phase — Implementation-Ready Architecture  
**Document Version:** 1.0

---

## TABLE OF CONTENTS

1. [Executive Summary](#1-executive-summary)
2. [Current Coupon Architecture](#2-current-coupon-architecture)
3. [Current Flow Analysis](#3-current-flow-analysis)
4. [Problems / Gaps](#4-problems--gaps)
5. [Final Business Rules](#5-final-business-rules)
6. [Final Domain Model](#6-final-domain-model)
7. [Assignment Model](#7-assignment-model)
8. [Targeting Model](#8-targeting-model)
9. [Rule Engine Architecture](#9-rule-engine-architecture)
10. [Eligibility Engine](#10-eligibility-engine)
11. [Dynamic Audience](#11-dynamic-audience)
12. [Snapshot Audience](#12-snapshot-audience)
13. [Claim Model](#13-claim-model)
14. [Claim State Machine](#14-claim-state-machine)
15. [Checkout / Payment Integration](#15-checkout--payment-integration)
16. [Redemption / Usage](#16-redemption--usage)
17. [Notification Architecture](#17-notification-architecture)
18. [API Contract](#18-api-contract)
19. [Database Schema](#19-database-schema)
20. [Indexes](#20-indexes)
21. [Concurrency Strategy](#21-concurrency-strategy)
22. [TiDB Compatibility](#22-tidb-compatibility)
23. [Performance Strategy](#23-performance-strategy)
24. [Security](#24-security)
25. [Backward Compatibility](#25-backward-compatibility)
26. [Testing Strategy](#26-testing-strategy)
27. [File-by-File Change Plan](#27-file-by-file-change-plan)

28. [Migration Plan](#28-migration-plan)
29. [Rollout Plan](#29-rollout-plan)
30. [Risks / Trade-offs](#30-risks--trade-offs)
31. [Final Recommended Architecture](#31-final-recommended-architecture)
32. [Implementation Phases](#32-implementation-phases)
33. [Critical Final Check](#33-critical-final-check)
34. [Requirement Conflicts / Decisions Required](#34-requirement-conflicts--decisions-required)

---

## 1. EXECUTIVE SUMMARY

### 1.1 Project Goal

Extend the existing Laravel 10 Coupon system to support:

- **Targeted Coupons** — rule-based eligibility (registration date, spending, order history, product/category purchase)
- **Assignment + Targeting combinations** — OR/AND relationships
- **Limited Claims** — first-N allocation with concurrency safety
- **Dynamic and Snapshot audiences**
- **Eligibility notifications** — triggered when users become eligible
- **Backward compatibility** — existing public/assigned coupons continue working unchanged

### 1.2 Current State

The repository already has a **production-grade Coupon system**:

**Models:**
- `Coupon` (packages/marvel/src/Database/Models/Coupon.php)
- `CouponAssignment` (packages/marvel/src/Database/Models/CouponAssignment.php)
- `CouponUsage` (packages/marvel/src/Database/Models/CouponUsage.php)
- `CouponAssignmentUsage` (packages/marvel/src/Database/Models/CouponAssignmentUsage.php)
- `CouponReservation` (app/Models/CouponReservation.php)

**Services:**
- `CouponOrchestrator` — validates coupon for user consumption
- `CouponValidator` — validates general coupon rules (status, dates, limits, usage)
- `CouponAssignmentValidator` — validates assignment-specific rules
- `CouponCalculator` — pure discount calculation (percentage, fixed_rate, free_shipping)
- `CouponReservationService` — 30-min payment window reservation with concurrency safety

**Tests:** ~3,174 lines across:
- `tests/Feature/CouponSystemTest.php`
- `tests/Feature/AssignedCouponSystemTest.php`
- `tests/Feature/CouponsProductionHardenTest.php`
- `tests/Unit/CouponCalculatorTest.php`
- `tests/Unit/CouponValidatorTest.php`

**Events:**
- `CouponAssigned` — fired when admin assigns coupon to user
- `AssignedCouponConsumed` — fired when assigned coupon is redeemed

**Notifications:**
- `UserCouponAssignedNotification` — notifies user of assignment
- `UserCouponUsedNotification` — notifies user after redemption

**Current Flow:**
1. User applies coupon code via `POST /api/v1/coupons/apply`
2. `CouponOrchestrator::validate()` orchestrates validation:
   - `CouponAssignmentValidator::validate()` — checks if coupon has assignments, validates assignment
   - `CouponValidator::validate()` — checks status, dates, limiter, product eligibility, user usage
3. Coupon added to cart
4. During checkout: `CouponReservationService::reserve()` creates 30-min reservation (prevents double-booking)
5. On payment success: `OrderService::recordCouponUsage()` consumes reservation, creates `CouponUsage` or `CouponAssignmentUsage`, increments counters
6. On payment failure/cancellation: `CouponReservationService::release()` frees reservation

### 1.3 What We're Adding

1. **Targeting Rules** — user attributes (registration date, country, language, gender, phone, status), order metrics (completed order count, lifetime spend, AOV), purchase history (product, category, brand)
2. **Rule Engine** — extensible registry-based system with boolean logic (AND/OR/NOT, nested groups)
3. **Eligibility Service** — orchestrates Assignment + Targeting with configurable relationship (OR/AND)
4. **Claim System** — limited first-N slots with strict concurrency safety (no overselling)
5. **Dynamic vs Snapshot audiences** — real-time eligibility vs frozen audience
6. **Eligibility Notifications** — event-driven notifications when user transitions NOT_ELIGIBLE → ELIGIBLE
7. **API enhancements** — structured reason codes, claim endpoints (optional preview endpoint)

### 1.4 Key Architectural Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| **Claim vs Reservation** | Separate concepts | Claim = long-lived user-owned allocation; Reservation = 30-min payment-window lock |
| **Claim vs Redemption** | Separate concepts | Claim = acquiring slot; Redemption = actually using in completed order |
| **Dynamic vs Snapshot** | Both supported | Dynamic = real-time; Snapshot = frozen for predictable campaigns |
| **Rule Engine** | Registry + Strategy | Extensible without schema changes per rule |
| **Assignment + Targeting** | Configurable OR/AND | Supports all required combinations |
| **Concurrency** | Transaction + `FOR UPDATE` + UNIQUE | TiDB-compatible, prevents overselling |
| **Backward Compat** | Zero breaking changes | Existing coupons work unchanged |
| **Notification Strategy** | Event-driven | React to order completion events |

---

## 2. CURRENT COUPON ARCHITECTURE

### 2.1 Existing Database Schema

#### `coupons` table
Location: `packages/marvel/database/migrations/*_create_coupons_table.php` (base schema)

```
id
code (unique, auto-generated if empty)
slug
name (translatable)
discount_type (fixed_rate, percentage, free_shipping)
discount
max_discount_amount (for percentage)
start_date
end_date
limiter (total usage cap, nullable)
used (counter)
status (boolean)
border_color
borderless
created_at, updated_at
```

**Relations:**
- `hasMany(CouponAssignment)` — assignments to users
- `hasMany(CouponUsage)` — usage records
- `belongsToMany(Product)` via `coupon_product` — product restrictions

#### `coupon_assignments` table
Location: `database/migrations/2026_07_15_000003_create_coupon_assignments_table.php`

```
id
coupon_id → coupons (cascade delete)
user_id → users (cascade delete)
max_uses (default 1)
used (default 0)
assigned_at (default now)
expires_at (nullable)
created_at, updated_at

UNIQUE(coupon_id, user_id)
```

**Business Logic:**
- Represents explicit admin assignment to specific user
- Each assignment has independent quota (`max_uses`, `used`)
- Assignment expiration is independent from coupon dates

#### `coupon_usages` table
Location: `packages/marvel/database/migrations/2024_12_27_000001_create_coupon_usages_table.php`

```
id
coupon_id → coupons (cascade delete)
user_id → users (null on delete)
order_id → orders (null on delete)
used_at (nullable timestamp)
created_at, updated_at

INDEX(coupon_id, user_id)
UNIQUE(coupon_id, user_id)
```

**Business Rule:** One usage per user per coupon (global, not assignment-specific)

#### `coupon_assignment_usages` table
Location: `database/migrations/2026_07_15_000004_create_coupon_assignment_usages_table.php`

```
id
coupon_assignment_id → coupon_assignments (cascade delete)
order_id → orders (null on delete)
used_at (default now)
created_at, updated_at

INDEX(coupon_assignment_id)
INDEX(created_at)
INDEX(coupon_assignment_id, created_at)
UNIQUE(coupon_assignment_id, order_id)
```

**Purpose:** Tracks each usage of an assigned coupon

#### `coupon_reservations` table
Location: `database/migrations/2026_08_31_120100_create_coupon_reservations_table.php`

```
id
coupon_id → coupons (cascade)
user_id → users (cascade)
order_id → orders (cascade)
reserved_at (timestamp)
expires_at (timestamp)
created_at, updated_at

INDEX(coupon_id, expires_at)
UNIQUE(order_id)
```

**Purpose:** Temporary 30-min reservation during payment window. Prevents double-booking of single-use coupons.

**Lifecycle:**
1. `CouponReservationService::reserve()` — created at checkout
2. On payment success: `consume()` deletes reservation
3. On payment failure: `release()` deletes reservation
4. `ExpireCouponReservations` command cleans expired reservations

#### `users` table (relevant fields)
Location: `database/migrations/2014_10_12_000000_create_users_table.php`

```
id
name
email (nullable after 2026_09_06 migration)
email_verified_at
password
type (default 'user')
is_active (default true)
phone_number (nullable, unique)
created_at, updated_at
deleted_at (soft deletes)
```

**Note:** User attributes like `country`, `language`, `gender` are NOT in `users` table. They may be in `user_profiles` table (referenced by `User::profile()` relation), but exact schema was not found during audit. **This is a critical gap for targeting implementation.**

#### `orders` table (relevant fields)
Locations: Various migrations in `database/migrations/2026_07_08_*` through `2026_08_31_*`

```
id
user_id
coupon (code string)
coupon_discount
coupon_discount_type
coupon_discount_max_amount
coupon_consumed (boolean)
promotion_id, promotion_consumed
status (pending, processing, completed, cancelled, delivered)
payment_status (pending, success, failed, refunded)
total_price
paid_at, completed_at, cancelled_at
created_at, updated_at
```

**Coupon Consumption:** `coupon_consumed` flag prevents duplicate consumption. Set to `true` in `OrderService::recordCouponUsage()` when order status changes to `completed`.

### 2.2 Existing Services

#### `CouponOrchestrator`
Location: `app/Services/Coupon/CouponOrchestrator.php`

**Responsibility:** Top-level validation orchestrator

**Methods:**
- `validateByCode(string $code, ?User $user, ?Collection $items): array`
- `validate(Coupon $coupon, ?User $user, ?Collection $items): array`

**Logic:**
```
IF user exists:
    assignmentResult = CouponAssignmentValidator::validate(coupon, user)
    IF !assignmentResult['valid']:
        RETURN invalid
    
    IF assignmentResult['has_assignments']:
        // Skip user-specific checks in CouponValidator (assignment already checked)
        validation = CouponValidator::validate(coupon, null, items)
    ELSE:
        validation = CouponValidator::validate(coupon, user, items)
ELSE:
    validation = CouponValidator::validate(coupon, null, items)

RETURN validation
```

#### `CouponValidator`
Location: `app/Services/Coupon/CouponValidator.php`

**Responsibility:** Validates general coupon rules (status, dates, limits, product eligibility, user usage)

**Checks:**
1. Status active (`status = true`)
2. Start date (`start_date <= today`)
3. End date (`end_date >= today`)
4. Global limiter (`used < limiter`)
5. User already used (checks `coupon_usages` for `coupon_id + user_id`)
6. Product eligibility (if `coupon_product` restrictions exist, checks cart items)

**Returns:** `['valid' => bool, 'reason' => string|null, 'message' => string|null, 'coupon' => Coupon|null]`

#### `CouponAssignmentValidator`
Location: `app/Services/Coupon/CouponAssignmentValidator.php`

**Responsibility:** Validates assignment-specific rules

**Logic:**
```
hasAssignments = coupon->assignments()->exists()

IF !hasAssignments:
    RETURN ['has_assignments' => false, 'valid' => true]

assignment = CouponAssignment WHERE coupon_id AND user_id

IF !assignment:
    RETURN invalid('not_assigned')

IF assignment.expires_at < now():
    RETURN invalid('assignment_expired')

IF assignment.used >= assignment.max_uses:
    RETURN invalid('usage_quota_exceeded')

RETURN ['has_assignments' => true, 'valid' => true, 'assignment' => assignment]
```

#### `CouponCalculator`
Location: `app/Services/Coupon/CouponCalculator.php`

**Responsibility:** Pure discount calculation (no validation, no side effects)

**Logic:**
```php
IF discount_type === 'percentage':
    discountAmount = price * (discount / 100)
    IF max_discount_amount !== null:
        discountAmount = min(discountAmount, max_discount_amount)
ELSE IF discount_type === 'fixed_rate':
    discountAmount = min(discount, price)

freeShipping = discount_type === 'free_shipping'
```

**Returns:** `['discountAmount', 'finalPrice', 'discountType', 'freeShipping']`

#### `CouponReservationService`
Location: `app/Services/Coupon/CouponReservationService.php`

**Responsibility:** Temporary 30-min reservation during payment window

**Key Methods:**

**`reserve(Order $order, Coupon $coupon): CouponReservation`**
```
DB::transaction:
    lockedCoupon = Coupon WHERE id FOR UPDATE
    
    existing = CouponReservation WHERE order_id FOR UPDATE
    IF existing:
        UPDATE expires_at = now + 30min
        RETURN existing
    
    activeReservations = COUNT(WHERE coupon_id AND expires_at > now FOR UPDATE)
    totalUsage = coupon.used + activeReservations
    
    IF coupon.limiter !== null AND totalUsage >= limiter:
        THROW 'usage_limit_reached'
    
    CREATE CouponReservation
```

**`consume(Order $order): void`** — deletes reservation (payment success)

**`release(Order $order): void`** — deletes reservation (payment failure/cancellation)

**`canReserve(Coupon $coupon): bool`** — checks if reservation possible

### 2.3 Existing Flow: Apply Coupon

**Endpoint:** `POST /api/v1/coupons/apply`

**Request:** `{ "code": "SAVE20" }`

**Controller:** `CouponController::applyCoupon()`
→ `CouponService::addCouponToCart($code)`

**Flow:**
```
1. Load user's cart
2. IF cart.coupon === code:
     RETURN 'already_applied'
3. validation = CouponOrchestrator::validateByCode(code, user, cart.items)
4. IF !validation['valid']:
     RETURN null (controller returns 400)
5. coupon = validation['coupon']
6. Calculate coupon discount via CouponCalculator
7. Update cart: SET coupon = code
8. RETURN success with discount info
```

### 2.4 Existing Flow: Checkout

**Service:** `OrderService::addItemsInOrder()`

**Flow:**
```
DB::transaction:
    1. Lock cart FOR UPDATE
    2. Refresh cart item prices
    3. IF cart.coupon:
         lockedCoupon = Coupon WHERE code FOR UPDATE
         validation = CouponOrchestrator::validate(lockedCoupon, user, cart.items)
         IF !valid: REMOVE coupon from cart
    4. Calculate checkout totals (promotion + coupon)
    5. Find or create pending order
    6. CouponReservationService::reserve(order, coupon) — 30-min lock
    7. Reserve inventory
    8. Clear cart items
    COMMIT

9. Fire OrderCreated event (after commit)
```

### 2.5 Existing Flow: Payment Success

**Service:** `OrderService::changeOrderStatus()` when status → `completed`

**Flow:**
```
DB::transaction:
    1. Update order status
    2. recordCouponUsage(order):
         IF !order.coupon OR order.coupon_consumed: RETURN
         
         coupon = Coupon WHERE code
         CouponReservationService::consume(order) — delete reservation
         
         IF coupon has assignments:
             assignment = CouponAssignment WHERE coupon_id, user_id FOR UPDATE
             IF assignment.used >= max_uses: RETURN
             
             IF CouponAssignmentUsage already exists: RETURN (idempotent)
             
             coupon.increment('used')
             assignment.increment('used')
             CREATE CouponAssignmentUsage
             
             Fire AssignedCouponConsumed event (after commit)
         ELSE:
             CouponUsage::firstOrCreate([coupon_id, user_id])
             IF wasRecentlyCreated:
                 coupon.increment('used')
         
         order.coupon_consumed = true
    COMMIT
```

### 2.6 Key Observations

**Strengths:**
✅ Clean separation of concerns (Orchestrator → Validator → Calculator)
✅ Concurrency-safe reservation with transaction + `FOR UPDATE`
✅ Assignment quota tracking works correctly
✅ Idempotent consumption (checks `coupon_consumed` flag)
✅ Event-driven notifications
✅ Comprehensive test coverage (~3,174 lines)

**Current Limitations:**
❌ No targeting/eligibility rules
❌ No claim system (limited first-N allocation)
❌ No Assignment + Targeting combinations
❌ No dynamic audience or snapshot support
❌ No eligibility transition notifications
❌ User profile attributes (country, language, gender) not accessible from `users` table

---

## 3. CURRENT FLOW ANALYSIS

### 3.1 Public Coupon (No Assignments)

```
User applies "SAVE20"
    ↓
CouponOrchestrator::validateByCode()
    ↓
CouponAssignmentValidator::validate()
    → hasAssignments = false → valid
    ↓
CouponValidator::validate(coupon, user, items)
    → Check: status, dates, limiter, user already used, product eligibility
    ↓
IF valid: Add to cart
    ↓
Checkout: CouponReservationService::reserve()
    → Creates 30-min reservation (prevents double-booking)
    ↓
Payment Success: recordCouponUsage()
    → CouponReservationService::consume() (delete reservation)
    → CouponUsage::firstOrCreate([coupon_id, user_id])
    → coupon.increment('used')
    → order.coupon_consumed = true
```

**Current Enforcement:**
- Global limiter: `coupon.used < coupon.limiter`
- One usage per user: `UNIQUE(coupon_id, user_id)` in `coupon_usages`
- Concurrency: Reservation prevents double-booking during payment window

### 3.2 Assigned Coupon

```
Admin assigns coupon to User A (max_uses = 3)
    ↓
Fire CouponAssigned event
    ↓
SendUserCouponAssignedNotification listener
    ↓
User A applies coupon
    ↓
CouponAssignmentValidator::validate()
    → hasAssignments = true
    → Load assignment for User A
    → Check: assignment exists, not expired, used < max_uses
    ↓
CouponValidator::validate(coupon, null, items)
    → Skips user-specific checks (assignment handles user quota)
    ↓
Checkout & Payment (same as above)
    ↓
recordCouponUsage():
    → Load assignment FOR UPDATE
    → CREATE CouponAssignmentUsage
    → assignment.increment('used')
    → coupon.increment('used')
    → Fire AssignedCouponConsumed event
```

**Current Enforcement:**
- Assignment required: User must have `CouponAssignment` row
- Per-user quota: `assignment.used < assignment.max_uses`
- Assignment expiration: `assignment.expires_at >= now()`
- One usage per assignment per order: `UNIQUE(coupon_assignment_id, order_id)`

### 3.3 Gaps for New Requirements

**What's Missing:**

1. **Targeting Rules** — no rule-based eligibility
2. **Assignment OR Targeting** — cannot have "assigned users OR users matching rules"
3. **Assignment AND Targeting** — cannot have "assigned users who also match rules"
4. **Limited Claims** — no first-N allocation separate from redemption
5. **Claim Ownership** — no user-owned claim slots
6. **Dynamic Eligibility** — no real-time eligibility checks during apply
7. **Snapshot Audience** — no frozen audience generation
8. **Eligibility Notifications** — no "you became eligible" notifications
9. **Structured API Errors** — generic 400 errors, no reason codes

---

## 4. PROBLEMS / GAPS

### 4.1 User Attribute Targeting

**Problem:** User attributes like `country`, `language`, `gender` are not in the `users` table.

**Current State:**
- `users` table has: `name`, `email`, `phone_number`, `type`, `is_active`, `created_at`
- `User` model has `profile()` relation pointing to `user_profiles` table
- `Profile` model exists at `packages/marvel/src/Database/Models/Profile.php`
- Profile schema was NOT found during audit

**Required Investigation:**
1. Inspect `user_profiles` table schema via database query or find migration
2. Determine which fields exist: `country`, `city`, `language`, `gender`, `bio`, etc.
3. If missing fields: decide whether to add columns or use alternative approach (e.g., enrich from order shipping data)

**Decision Point:** Cannot finalize targeting rules for user attributes without confirmed schema.

### 4.2 Lifetime Spend & Order Metrics

**Problem:** Need to define the exact calculation for "lifetime spend" and "average order value".

**Current Order Fields:**
- `total_price` — order total in order currency
- `converted_total_price` — order total in base currency
- `currency_code`, `base_currency_code`, `catalog_currency_code`
- `status` — pending, processing, completed, cancelled, delivered
- `payment_status` — pending, success, failed, refunded
- `completed_at`, `cancelled_at`

**Proposed Definitions:**

**Lifetime Net Spend:**
```sql
SUM(converted_total_price) 
WHERE status = 'completed' 
  AND payment_status = 'success'
  AND completed_at IS NOT NULL
```

**Completed Order Count:**
```sql
COUNT(*) 
WHERE status = 'completed' 
  AND payment_status = 'success'
  AND completed_at IS NOT NULL
```

**Average Order Value:**
```
Lifetime Net Spend / Completed Order Count
(handle division by zero: return 0 or null)
```

**Refund Handling:** Current approach does NOT subtract refunds from lifetime spend. If an order is later refunded, it remains counted. This is a business decision.

**Decision Point:** Confirm with business whether refunded orders should reduce lifetime spend.

### 4.3 Product/Category/Brand Purchase History

**Problem:** Need efficient queries to check "user purchased product X" / "category Y" / "brand Z".

**Current Schema:**
- `orders` table has `user_id`
- `order_products` table (OrderProduct model) has `order_id`, `product_id`, `product_variant_id`
- `products` table has `categories`, `brand_id` (assumed based on Laravel conventions)

**Proposed Query Pattern:**
```sql
SELECT 1 FROM orders o
JOIN order_products op ON o.id = op.order_id
JOIN products p ON op.product_id = p.id
WHERE o.user_id = ?
  AND o.status = 'completed'
  AND p.id IN (?)  -- product IDs
LIMIT 1
```

**Performance Concern:** Without proper indexes, these queries could be slow for large datasets.

**Decision Point:** Must add composite indexes on `orders(user_id, status)` and `order_products(order_id, product_id)`.

### 4.4 Claim vs Reservation Semantics

**Problem:** The requirement introduces "Claim" as a new concept. Must clearly separate:

| Concept | Purpose | Lifetime | Ownership |
|---------|---------|----------|-----------|
| **Claim** | User acquires one of limited slots | Until claim expires or coupon redeemed | Owned by specific user |
| **Reservation** | Prevents double-booking during payment | 30 minutes | Tied to order, not reusable |
| **Redemption** | Actual usage in completed order | Permanent | Historical record |

**Key Question:** What happens to a Claim when:
1. User adds coupon to cart but doesn't checkout?
2. Payment fails?
3. Claim expires?
4. User is still eligible after claim expiry?

**Proposed Semantics:**

**Claim Lifecycle:**
```
Eligible User
    ↓
Claims Slot (one of max_claims)
    ↓ (owns claim until expiry)
Adds to Cart (optional: could add later)
    ↓
Checkout (Reservation created, Claim still active)
    ↓
Payment Success → Claim consumed, Reservation deleted, Redemption recorded
Payment Failure → Reservation deleted, Claim remains owned by user (can retry)
```

**Claim Expiration:**
```
Claim expires_at < now()
    ↓
Claim marked 'expired'
    ↓
Slot becomes available again
    ↓
IF user still eligible: User can claim again (new claim)
```

**Decision Point:** Should Cart hold a reference to Claim, or is Claim independent of Cart lifecycle?

**Recommendation:** Claim is independent. User owns claim whether or not it's in cart. This allows retry after payment failure without losing the slot.

### 4.5 Claim vs Assignment

**Problem:** Assignment and Claim seem similar but serve different purposes.

**Clarification:**

**Assignment:**
- Admin explicitly assigns coupon to specific user(s)
- Assignment is durable (persists until admin removes or expires)
- Assignment has per-user quota (`max_uses`)
- Assignment makes user eligible (if no additional targeting rules)

**Claim:**
- User acquires one of limited campaign slots
- Claim is tied to limited-capacity campaigns (`max_claims`)
- Claim is user-owned allocation
- Claim can expire and be reclaimed

**Can both exist?** YES.

**Example Scenario:**
```
Coupon: FLASH100
Assignment: User A, User B, User C (max_uses = 1 each)
Targeting: Lifetime Spend >= 5000
Relationship: Assignment OR Targeting
Max Claims: 10

Flow:
1. User A (assigned) is eligible immediately → can claim slot 1
2. User D (not assigned, spend = 6000) is eligible via targeting → can claim slot 2
3. User E (not assigned, spend = 3000) is NOT eligible
4. Only 10 total users can claim (regardless of assignment vs targeting)
```

**Decision Point:** Confirm this is the desired business behavior.

---

## 5. FINAL BUSINESS RULES

### 5.1 Core Concepts

**Assignment:**
> Admin explicitly grants coupon to specific user(s). Each assignment has independent quota (`max_uses`, `used`).

**Targeting:**
> Rule-based eligibility. Users matching targeting rules are eligible without explicit assignment.

**Eligibility:**
> Determines if a user is allowed to use/claim the coupon. Depends on: Assignment + Targeting relationship, coupon status, dates, and other restrictions.

**Claim:**
> User-owned allocation of one limited campaign slot. First-N users who claim successfully own their slots.

**Redemption:**
> Actual usage of coupon in a completed order. Permanent and cannot be reversed (even on refund).

### 5.2 Assignment + Targeting Relationships

Coupons support four configurations:

| Configuration | Eligibility Logic |
|---------------|-------------------|
| **Assignment Only** | User must have `CouponAssignment` row |
| **Targeting Only** | User must match targeting rules |
| **Assignment OR Targeting** | User has assignment OR matches targeting |
| **Assignment AND Targeting** | User has assignment AND matches targeting |

**Storage:** Add `assignment_targeting_mode` enum column to `coupons`:
- `null` or `'assignment_only'` — backward compatible, no targeting
- `'targeting_only'` — targeting rules required, assignments ignored
- `'or'` — assignment OR targeting
- `'and'` — assignment AND targeting

### 5.3 Targeting Rules Supported

**User Attributes:**
- Registration date (before, after, between, on)
- User IDs (in list)
- Country (in list) — requires user profile data
- Language (in list) — requires user profile data
- Gender (in list) — requires user profile data
- Phone (pattern match or exists)
- Status (`is_active`, `type`)

**Order Metrics:**
- Completed order count (>, >=, =, <, <=, between)
- Lifetime net spend (>, >=, =, <, <=, between)
- Average order value (>, >=, =, <, <=, between)

**Purchase History:**
- Purchased product(s) (any/all in list)
- Purchased category(s) (any/all in list)
- Purchased brand(s) (any/all in list)

**Coupon History:**
- Has used coupon X
- Has NOT used coupon X

**Boolean Logic:**
- AND groups
- OR groups
- NOT (inverse)
- Nested groups supported

### 5.4 Dynamic vs Snapshot Audiences

**Dynamic:**
- Eligibility evaluated in real-time during apply/claim
- User who becomes eligible can immediately claim
- User who loses eligibility cannot claim (but keeps existing claim)

**Snapshot:**
- Admin generates frozen audience at a point in time
- Stored in `coupon_audience_snapshots` table
- New eligible users after snapshot cannot claim
- Snapshot can be regenerated (replaces previous snapshot)

**Storage:** Add `audience_mode` enum to `coupons`: `'dynamic'` (default), `'snapshot'`

### 5.5 Claim System Rules

**Max Claims:**
- Coupon has `max_claims` (nullable integer)
- If `null`: unlimited claims (claim system disabled)
- If set: exactly N users can hold active claims

**Claim Ownership:**
- Each claim belongs to one user
- Claim has `claim_number` (1, 2, 3, ..., max_claims)
- Claim has `expires_at` timestamp
- Claim persists through payment failure (user can retry)

**Claim Expiration:**
- Expiration timer starts at claim creation
- Configurable per coupon: `claim_expiry_minutes` (default: 1440 = 24 hours)
- Expired claims release their slot
- User can reclaim if still eligible

**Claim Lifecycle:**
```
Eligible User
    ↓ [Claim Action]
Claim Created (status: 'active', claim_number assigned)
    ↓
User adds to cart (optional timing)
    ↓
Checkout → Reservation created (Claim still 'active')
    ↓
Payment Success → Claim status: 'redeemed', Redemption recorded
Payment Failure → Claim remains 'active' (user can retry)
    ↓
Claim Expiry → Claim status: 'expired', slot available
```

**Concurrency:**
- Claim allocation uses transaction + `FOR UPDATE` + unique constraint
- No overselling: exactly `max_claims` active claims at any time

### 5.6 Claim vs Assignment Interaction

| Scenario | Assignment | Targeting | Claims | Eligibility |
|----------|-----------|-----------|--------|-------------|
| Assigned user, no targeting | Has assignment | N/A | Disabled | Always eligible via assignment |
| Assigned user, targeting OR mode | Has assignment | May/may not match | Optional | Eligible via assignment, claim optional |
| Assigned user, targeting AND mode | Has assignment | Must match | Optional | Must match targeting to use |
| Non-assigned, targeting match | None | Matches | Required if max_claims set | Eligible, must claim if limited |
| Non-assigned, no targeting match | None | No match | N/A | Not eligible |

**Key Rules:**
1. Assignment bypasses claim requirement in OR mode
2. Assignment must still satisfy targeting in AND mode
3. Claim limit applies only to targeting-based users in OR mode
4. Claim limit applies to everyone in targeting_only mode

### 5.7 Validation Priority Chain

**Order of checks during apply/claim:**

1. **Coupon exists and active**
2. **Date range valid** (start_date ≤ now ≤ end_date)
3. **Assignment check** (if assignment_targeting_mode requires it)
4. **Targeting check** (if assignment_targeting_mode requires it)
5. **Claim check** (if max_claims set and user eligible via targeting)
6. **Global usage limit** (if limiter set, check total used)
7. **User usage limit** (check coupon_usages for redemption)
8. **Product eligibility** (if product restrictions exist)
9. **Minimum subtotal** (if minimum_order_amount set)

### 5.8 Notification Rules

**Notification Events:**

| Event | Trigger | Audience | Timing |
|-------|---------|----------|--------|
| CouponBecameEligible | User enters eligibility (dynamic mode) | Individual user | Real-time |
| ClaimSucceeded | User successfully claims limited coupon | Individual user | Immediate |
| ClaimExpiringSoon | Claim expires in 2 hours | Claim owner | Scheduled |
| ClaimExpired | Claim expired and released | Claim owner | Immediate |
| CouponAssignedToUser | Admin assigns coupon | Individual user | Immediate |

**Notification Channels:**
- Database notification (`notifications` table)
- Optional: Email (configurable per shop)
- Optional: Push notification (future)

**Eligibility Notification Logic:**
- Only fire once per user per coupon (track in `coupon_eligibility_notifications` table)
- Check `notify_on_eligibility` flag on coupon
- For snapshot mode: fire when snapshot generated
- For dynamic mode: fire when targeting match first occurs

### 5.9 Refund & Reversal Policy

**Redemption is Final:**
- Once order payment succeeds and coupon redeemed → permanent
- Refunds do NOT reverse coupon usage
- Refunds do NOT increment `used` counter back
- Refunds do NOT release claim slot
- Refunds do NOT allow re-use of one-time coupon

**Rationale:**
- Prevents abuse (buy → get discount → refund → repeat)
- Matches industry standard coupon behavior
- Simplifies accounting and reporting

### 5.10 Lifetime Spend Calculation

**Definition:**
```
Lifetime Spend = SUM(orders.total WHERE status = 'completed' AND refund_status = 'none')
```

**Clarifications:**
- Only completed orders count
- Fully refunded orders excluded
- Partially refunded orders: count original total (not net after refund)
- Pending/cancelled orders excluded
- Currency: calculated in user's primary currency or shop base currency

**Performance:**
- Pre-computed in `user_profiles.lifetime_spend_cache` (updated on order completion)
- Targeting rule checks cached value
- Scheduled job reconciles cache daily

---

## 6. FINAL DOMAIN MODEL

### 6.1 Domain Entities

**Coupon** (existing, extended)
- Core identity: code, name, description
- Discount configuration: discount_type, discount, max_discount_amount
- Usage limits: limiter, used, max_claims
- Assignment/targeting: assignment_targeting_mode, audience_mode
- Targeting: targeting_rules (JSON)
- Claims: max_claims, claim_expiry_minutes
- Notifications: notify_on_eligibility
- Dates: start_date, end_date

**CouponAssignment** (existing)
- Links coupon to user (admin action)
- Quota: max_uses, used
- Expiration: expires_at

**CouponTargeting** (NEW)
- Stores targeting rules as structured JSON
- Supports boolean logic (AND/OR/NOT)
- Validation at save time

**CouponClaim** (NEW)
- Represents user ownership of limited campaign slot
- Fields: user_id, coupon_id, claim_number, claimed_at, expires_at, status
- Status: active, redeemed, expired
- One-to-one with redemption when redeemed

**CouponRedemption** (NEW, replaces CouponUsage)
- Immutable record of actual usage in order
- Fields: coupon_id, user_id, order_id, claim_id (nullable), assignment_id (nullable), redeemed_at
- Tracks redemption source (assignment vs claim vs generic)

**CouponReservation** (existing)
- Temporary 30-min payment window lock
- Prevents race conditions during checkout

**CouponAudienceSnapshot** (NEW)
- Frozen list of eligible user_ids at point in time
- For snapshot-mode coupons
- Fields: coupon_id, user_id, generated_at

**CouponEligibilityNotification** (NEW)
- Tracks sent notifications to prevent duplicates
- Fields: coupon_id, user_id, notified_at

### 6.2 Entity Relationships

```
Coupon 1----* CouponAssignment *----1 User
Coupon 1----* CouponClaim *----1 User
Coupon 1----* CouponRedemption *----1 User
Coupon 1----* CouponReservation *----1 User
Coupon 1----* CouponAudienceSnapshot *----1 User
Coupon 1----1 CouponTargeting (optional)
CouponClaim 1----0..1 CouponRedemption
CouponAssignment 1----* CouponRedemption (via assignment_id)
Order 1----0..1 CouponRedemption
```

### 6.3 Bounded Contexts

**Coupon Management Context:**
- Admin CRUD for coupons
- Admin assigns coupons to users
- Admin generates audience snapshots
- Admin views usage analytics

**Eligibility Context:**
- Rule engine evaluates targeting rules
- Eligibility service coordinates assignment + targeting
- Audience snapshot generation

**Claim Context:**
- User claims limited coupon
- Claim expiration handling
- Claim slot allocation (concurrency-safe)

**Redemption Context:**
- Apply coupon to cart
- Validate eligibility + claim ownership
- Reserve during checkout
- Consume on payment success
- Record immutable redemption

**Notification Context:**
- Send eligibility notifications
- Send claim expiry warnings
- Track notification delivery

---

## 7. ASSIGNMENT MODEL

### 7.1 Current Assignment Schema

```sql
CREATE TABLE coupon_assignments (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    max_uses INT UNSIGNED NOT NULL DEFAULT 1,
    used INT UNSIGNED NOT NULL DEFAULT 0,
    assigned_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    UNIQUE KEY unique_coupon_user (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 7.2 Required Changes

**Add column:**
```sql
ALTER TABLE coupon_assignments ADD COLUMN assigned_by_user_id BIGINT UNSIGNED NULL AFTER user_id;
ALTER TABLE coupon_assignments ADD FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE coupon_assignments ADD COLUMN notes TEXT NULL AFTER expires_at;
```

**Justification:**
- `assigned_by_user_id`: audit trail (which admin assigned)
- `notes`: admin can record assignment reason

### 7.3 Assignment Business Logic

**Creation:**
- Admin-only action
- Validates: coupon exists, user exists, no duplicate (UNIQUE constraint)
- Sets: assigned_at = now, max_uses (default 1), expires_at (optional)
- Fires event: `CouponAssigned`

**Validation:**
- Check assignment exists
- Check not expired (expires_at NULL or expires_at > now)
- Check quota (used < max_uses)

**Consumption:**
- Increment `used` counter (transaction-safe)
- Create `CouponRedemption` with assignment_id reference

**Expiration:**
- Expired assignments cannot be used
- Expired assignments NOT auto-deleted (audit trail)
- Scheduled job marks expired daily for reporting

### 7.4 Assignment API (No Changes Required)

Existing endpoints remain backward compatible:
- `POST /admin/coupons/{id}/assign` — assign to user(s)
- `GET /admin/coupons/{id}/assignments` — list assignments
- `DELETE /admin/coupons/{id}/assignments/{assignmentId}` — revoke

Add to existing payloads:
- Request: `assigned_by_user_id` (auto-filled from auth), `notes`
- Response: include new fields in resource

---

## 8. TARGETING MODEL

### 8.1 Targeting Schema

**New table:**
```sql
CREATE TABLE coupon_targetings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL UNIQUE,
    rules JSON NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
);
```

**Add to coupons table:**
```sql
ALTER TABLE coupons ADD COLUMN assignment_targeting_mode ENUM('assignment_only', 'targeting_only', 'or', 'and') NULL DEFAULT NULL AFTER status;
ALTER TABLE coupons ADD COLUMN audience_mode ENUM('dynamic', 'snapshot') NOT NULL DEFAULT 'dynamic' AFTER assignment_targeting_mode;
ALTER TABLE coupons ADD COLUMN notify_on_eligibility BOOLEAN NOT NULL DEFAULT FALSE AFTER audience_mode;
```

### 8.2 Targeting Rules JSON Schema

**Structure:**
```json
{
  "type": "group",
  "operator": "and",
  "rules": [
    {
      "type": "rule",
      "field": "user.country",
      "operator": "in",
      "value": ["US", "CA", "UK"]
    },
    {
      "type": "rule",
      "field": "order.completed_count",
      "operator": ">=",
      "value": 3
    },
    {
      "type": "group",
      "operator": "or",
      "rules": [
        {
          "type": "rule",
          "field": "purchase.product_id",
          "operator": "in",
          "value": [101, 102, 103]
        },
        {
          "type": "rule",
          "field": "purchase.category_id",
          "operator": "in",
          "value": [5]
        }
      ]
    }
  ]
}
```

**Supported Fields:**
- `user.id` (in, not_in)
- `user.created_at` (before, after, between)
- `user.country` (in, not_in)
- `user.language` (in, not_in)
- `user.gender` (in, not_in)
- `user.is_active` (equals)
- `order.completed_count` (>, >=, =, <, <=, between)
- `order.lifetime_spend` (>, >=, =, <, <=, between)
- `order.average_value` (>, >=, =, <, <=, between)
- `purchase.product_id` (in, not_in, any, all)
- `purchase.category_id` (in, not_in, any, all)
- `purchase.brand_id` (in, not_in, any, all)
- `coupon.used` (equals, has_used, has_not_used — coupon_id required)

**Operators:**
- Comparison: `>`, `>=`, `=`, `<`, `<=`, `between`
- Set: `in`, `not_in`, `any`, `all`
- Date: `before`, `after`, `between`
- Boolean: `equals`
- Special: `has_used`, `has_not_used`

### 8.3 Targeting Validation

**At Save Time:**
- JSON structure valid
- Field names recognized
- Operators valid for field type
- Value types match field (e.g., country = array of strings)
- Nested depth ≤ 5 levels

**Validation Service:**
`app/Services/Coupon/CouponTargetingValidator.php`

Methods:
- `validate(array $rules): bool` — throws ValidationException if invalid
- `getSupportedFields(): array`
- `getSupportedOperators(string $field): array`

---

## 9. RULE ENGINE ARCHITECTURE

### 9.1 Rule Engine Service

**New service:** `app/Services/Coupon/CouponRuleEngine.php`

**Responsibilities:**
- Parse targeting rules JSON
- Build database queries for rule evaluation
- Execute queries and return boolean result
- Cache query results per request

**Key Methods:**
```php
class CouponRuleEngine
{
    public function evaluate(array $rules, int $userId): bool;
    
    private function evaluateGroup(array $group, int $userId): bool;
    
    private function evaluateRule(array $rule, int $userId): bool;
    
    private function buildUserQuery(array $rule, int $userId): Builder;
    
    private function buildOrderQuery(array $rule, int $userId): Builder;
    
    private function buildPurchaseQuery(array $rule, int $userId): Builder;
    
    private function buildCouponUsageQuery(array $rule, int $userId): Builder;
}
```

### 9.2 Query Building Strategy

**User Attributes:**
```php
// user.country in ['US', 'CA']
User::where('id', $userId)
    ->whereIn('country', ['US', 'CA'])
    ->exists();
```

**Order Metrics:**
```php
// order.completed_count >= 3
Order::where('user_id', $userId)
    ->where('status', 'completed')
    ->count() >= 3;

// order.lifetime_spend >= 100
Order::where('user_id', $userId)
    ->where('status', 'completed')
    ->sum('total') >= 100;
```

**Purchase History:**
```php
// purchase.product_id in [101, 102]
OrderItem::whereHas('order', function($q) use ($userId) {
        $q->where('user_id', $userId)->where('status', 'completed');
    })
    ->whereIn('product_id', [101, 102])
    ->exists();
```

**Coupon Usage:**
```php
// coupon.used has_used coupon_id=5
CouponRedemption::where('user_id', $userId)
    ->where('coupon_id', 5)
    ->exists();
```

### 9.3 Performance Optimizations

**Query Caching:**
- Cache rule evaluation per user per coupon per request
- Key: `coupon_targeting:{coupon_id}:{user_id}`
- TTL: 60 seconds (dynamic mode), 3600 seconds (snapshot mode)

**Eager Loading:**
- Pre-load user profile data when evaluating multiple users
- Batch query order metrics when generating snapshots

**Indexes Required:**
- `orders(user_id, status, total)` — for spend calculations
- `order_items(product_id)` — for purchase history
- `order_items(order_id, product_id)` — for join optimization
- `coupon_redemptions(user_id, coupon_id)` — for usage checks

### 9.4 Boolean Logic Evaluation

**Group Evaluation:**
```php
private function evaluateGroup(array $group, int $userId): bool
{
    $operator = $group['operator']; // 'and' | 'or'
    $results = [];
    
    foreach ($group['rules'] as $rule) {
        if ($rule['type'] === 'group') {
            $results[] = $this->evaluateGroup($rule, $userId);
        } else {
            $results[] = $this->evaluateRule($rule, $userId);
        }
        
        // Short-circuit optimization
        if ($operator === 'and' && end($results) === false) {
            return false;
        }
        if ($operator === 'or' && end($results) === true) {
            return true;
        }
    }
    
    return $operator === 'and' 
        ? !in_array(false, $results, true)
        : in_array(true, $results, true);
}
```

---

## 10. ELIGIBILITY ENGINE

### 10.1 Eligibility Service

**New service:** `app/Services/Coupon/CouponEligibilityService.php`

**Responsibilities:**
- Coordinate assignment check + targeting check
- Interpret assignment_targeting_mode logic
- Return eligibility result with reason

**Key Methods:**
```php
class CouponEligibilityService
{
    public function __construct(
        private CouponRuleEngine $ruleEngine,
        private CouponAssignmentRepository $assignmentRepo
    ) {}
    
    public function isEligible(Coupon $coupon, int $userId): EligibilityResult;
    
    private function checkAssignment(Coupon $coupon, int $userId): bool;
    
    private function checkTargeting(Coupon $coupon, int $userId): bool;
}
```

**EligibilityResult DTO:**
```php
class EligibilityResult
{
    public bool $eligible;
    public string $reason; // 'assignment', 'targeting', 'both', 'none'
    public ?CouponAssignment $assignment;
    public bool $requiresClaim;
}
```

### 10.2 Eligibility Logic Matrix

**assignment_targeting_mode = NULL (backward compatible):**
```php
if ($assignment = $this->assignmentRepo->findActive($coupon->id, $userId)) {
    return new EligibilityResult(
        eligible: true,
        reason: 'assignment',
        assignment: $assignment,
        requiresClaim: false
    );
}
return new EligibilityResult(eligible: false, reason: 'no_assignment');
```

**assignment_targeting_mode = 'targeting_only':**
```php
if (!$coupon->couponTargeting) {
    throw new LogicException('Targeting rules required');
}
$matchesTargeting = $this->ruleEngine->evaluate(
    $coupon->couponTargeting->rules, 
    $userId
);
return new EligibilityResult(
    eligible: $matchesTargeting,
    reason: $matchesTargeting ? 'targeting' : 'no_match',
    assignment: null,
    requiresClaim: $matchesTargeting && $coupon->max_claims !== null
);
```

**assignment_targeting_mode = 'or':**
```php
$assignment = $this->assignmentRepo->findActive($coupon->id, $userId);
$matchesTargeting = $coupon->couponTargeting 
    ? $this->ruleEngine->evaluate($coupon->couponTargeting->rules, $userId)
    : false;

if ($assignment) {
    return new EligibilityResult(
        eligible: true,
        reason: 'assignment',
        assignment: $assignment,
        requiresClaim: false
    );
}
if ($matchesTargeting) {
    return new EligibilityResult(
        eligible: true,
        reason: 'targeting',
        assignment: null,
        requiresClaim: $coupon->max_claims !== null
    );
}
return new EligibilityResult(eligible: false, reason: 'neither');
```

**assignment_targeting_mode = 'and':**
```php
$assignment = $this->assignmentRepo->findActive($coupon->id, $userId);
$matchesTargeting = $coupon->couponTargeting
    ? $this->ruleEngine->evaluate($coupon->couponTargeting->rules, $userId)
    : true; // no targeting = always match

if ($assignment && $matchesTargeting) {
    return new EligibilityResult(
        eligible: true,
        reason: 'both',
        assignment: $assignment,
        requiresClaim: $coupon->max_claims !== null // even assigned users must claim if limited
    );
}
return new EligibilityResult(
    eligible: false, 
    reason: !$assignment ? 'no_assignment' : 'targeting_mismatch'
);
```

### 10.3 Integration with Existing Validators

**Modify CouponOrchestrator:**
```php
// Before (existing):
if ($assignment = $this->assignmentValidator->validate($coupon, $userId)) {
    // assignment path
} else {
    // public coupon path
}

// After (new):
$eligibility = $this->eligibilityService->isEligible($coupon, $userId);
if (!$eligibility->eligible) {
    throw new CouponNotEligibleException($eligibility->reason);
}
if ($eligibility->requiresClaim) {
    $this->claimService->ensureActiveClaim($coupon->id, $userId);
}
```

---

## 11. DYNAMIC AUDIENCE

### 11.1 Dynamic Mode Behavior

**audience_mode = 'dynamic':**
- Eligibility evaluated in real-time
- User becomes eligible → can claim immediately
- User loses eligibility → cannot claim (existing claims preserved)

**Use Cases:**
- "First 100 users who complete 3 orders"
- "VIP customers (dynamic segment)"
- "Users in US/CA with lifetime spend > $500"

### 11.2 Real-Time Eligibility Check

**Flow:**
```
User → Apply Coupon API
    ↓
CouponEligibilityService.isEligible()
    ↓
CouponRuleEngine.evaluate()
    ↓
Execute targeting queries against live data
    ↓
Return eligibility result
```

**Caching Strategy:**
- Cache eligibility result for 60 seconds per user per coupon
- Invalidate on user data change (order completion, profile update)
- Cache key: `eligibility:{coupon_id}:{user_id}`

### 11.3 Eligibility Change Detection

**Trigger Notification When:**
- User was not eligible (cached or last check)
- User becomes eligible (current check)
- Notification not already sent

**Implementation:**
```php
// In CouponEligibilityService
public function checkAndNotifyEligibility(Coupon $coupon, int $userId): void
{
    if (!$coupon->notify_on_eligibility) {
        return;
    }
    
    $alreadyNotified = CouponEligibilityNotification::where([
        'coupon_id' => $coupon->id,
        'user_id' => $userId
    ])->exists();
    
    if ($alreadyNotified) {
        return;
    }
    
    $eligible = $this->isEligible($coupon, $userId);
    
    if ($eligible->eligible) {
        event(new CouponBecameEligible($coupon, $userId));
        CouponEligibilityNotification::create([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'notified_at' => now()
        ]);
    }
}
```

**Trigger Points:**
- After order completion (user may now meet order count/spend threshold)
- After profile update (country/language change)
- Admin can manually trigger bulk check via command

---

## 12. SNAPSHOT AUDIENCE

### 12.1 Snapshot Mode Behavior

**audience_mode = 'snapshot':**
- Admin generates frozen audience list
- Only users in snapshot can claim
- New eligible users after snapshot cannot claim
- Snapshot can be regenerated (replaces previous)

**Use Cases:**
- "First 1000 users on email list as of Jan 1"
- "VIP customers locked at campaign start"
- "Birthday month coupons (static list)"

### 12.2 Snapshot Schema

```sql
CREATE TABLE coupon_audience_snapshots (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    generated_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    
    INDEX idx_coupon_user (coupon_id, user_id),
    INDEX idx_generated (coupon_id, generated_at),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 12.3 Snapshot Generation

**New service:** `app/Services/Coupon/CouponAudienceSnapshotService.php`

**Methods:**
```php
class CouponAudienceSnapshotService
{
    public function generate(Coupon $coupon): int; // returns user count
    
    public function isInSnapshot(int $couponId, int $userId): bool;
    
    private function fetchEligibleUsers(Coupon $coupon): Collection;
}
```

**Generation Logic:**
```php
public function generate(Coupon $coupon): int
{
    if ($coupon->audience_mode !== 'snapshot') {
        throw new InvalidArgumentException('Coupon must be in snapshot mode');
    }
    
    DB::transaction(function() use ($coupon) {
        // Delete old snapshot
        CouponAudienceSnapshot::where('coupon_id', $coupon->id)->delete();
        
        // Fetch all eligible users
        $eligibleUserIds = $this->fetchEligibleUsers($coupon);
        
        // Batch insert
        $now = now();
        $rows = $eligibleUserIds->map(fn($userId) => [
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'generated_at' => $now,
            'created_at' => $now
        ])->toArray();
        
        CouponAudienceSnapshot::insert($rows);
        
        // Send notifications
        foreach ($eligibleUserIds as $userId) {
            if ($coupon->notify_on_eligibility) {
                event(new CouponBecameEligible($coupon, $userId));
            }
        }
    });
    
    return $eligibleUserIds->count();
}
```

**Eligible User Query:**
```php
private function fetchEligibleUsers(Coupon $coupon): Collection
{
    if ($coupon->assignment_targeting_mode === 'targeting_only') {
        return $this->fetchTargetingMatches($coupon);
    }
    if ($coupon->assignment_targeting_mode === 'or') {
        $assigned = $this->fetchAssignedUsers($coupon);
        $targeted = $this->fetchTargetingMatches($coupon);
        return $assigned->merge($targeted)->unique();
    }
    if ($coupon->assignment_targeting_mode === 'and') {
        $assigned = $this->fetchAssignedUsers($coupon);
        return $assigned->filter(fn($userId) => 
            $this->ruleEngine->evaluate($coupon->couponTargeting->rules, $userId)
        );
    }
    // assignment_only
    return $this->fetchAssignedUsers($coupon);
}
```

### 12.4 Snapshot Eligibility Check

**Modify CouponEligibilityService:**
```php
public function isEligible(Coupon $coupon, int $userId): EligibilityResult
{
    // ... existing assignment/targeting logic ...
    
    // Add snapshot check
    if ($coupon->audience_mode === 'snapshot') {
        $inSnapshot = CouponAudienceSnapshot::where([
            'coupon_id' => $coupon->id,
            'user_id' => $userId
        ])->exists();
        
        if (!$inSnapshot) {
            return new EligibilityResult(
                eligible: false,
                reason: 'not_in_snapshot'
            );
        }
    }
    
    // ... continue with normal logic ...
}
```

---

## 13. CLAIM MODEL

### 13.1 Claim Schema

```sql
CREATE TABLE coupon_claims (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    claim_number INT UNSIGNED NOT NULL,
    status ENUM('active', 'redeemed', 'expired') NOT NULL DEFAULT 'active',
    claimed_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    redeemed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    UNIQUE KEY unique_coupon_user (coupon_id, user_id),
    UNIQUE KEY unique_claim_number (coupon_id, claim_number),
    INDEX idx_status_expiry (coupon_id, status, expires_at),
    INDEX idx_user (user_id, status),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

**Add to coupons table:**
```sql
ALTER TABLE coupons ADD COLUMN max_claims INT UNSIGNED NULL AFTER limiter;
ALTER TABLE coupons ADD COLUMN claim_expiry_minutes INT UNSIGNED NOT NULL DEFAULT 1440 AFTER max_claims;
```

### 13.2 Claim Fields

| Field | Type | Purpose |
|-------|------|---------|
| claim_number | INT | Sequential slot number (1, 2, ..., max_claims) |
| status | ENUM | active, redeemed, expired |
| claimed_at | TIMESTAMP | When user claimed |
| expires_at | TIMESTAMP | Auto-calculated: claimed_at + claim_expiry_minutes |
| redeemed_at | TIMESTAMP | When used in completed order |

### 13.3 Claim Business Rules

**Creation:**
- User must be eligible (assignment + targeting logic)
- max_claims must be set (not null)
- Available slot must exist (active + redeemed claims < max_claims)
- UNIQUE(coupon_id, user_id) — one claim per user
- Assign next claim_number atomically

**Expiration:**
- Scheduled job runs every 5 minutes
- Finds claims where status='active' AND expires_at < now
- Updates status='expired'
- Slot becomes available for others

**Redemption:**
- On order payment success
- Update claim: status='redeemed', redeemed_at=now
- Create CouponRedemption with claim_id reference

**Re-Claim:**
- If user's claim expired, they can claim again (if still eligible)
- New claim gets new claim_number and expires_at

---

## 14. CLAIM STATE MACHINE

### 14.1 State Transitions

```
[No Claim]
    ↓ User clicks "Claim Coupon"
    ↓ Eligibility check passes
    ↓ Available slot exists
[ACTIVE]
    ↓ (Timer: expires_at reached)
    ↓
[EXPIRED] ← User can re-claim if still eligible
    
[ACTIVE]
    ↓ User applies to cart
    ↓ Checkout → Payment Success
    ↓
[REDEEMED] ← Terminal state

[ACTIVE]
    ↓ User applies to cart
    ↓ Checkout → Payment Failed
    ↓
[ACTIVE] ← Claim preserved, user can retry
```

### 14.2 State Definitions

**ACTIVE:**
- User owns slot
- Can apply coupon to cart
- Not yet used in completed order
- Subject to expiration

**EXPIRED:**
- Claim expired (expires_at passed)
- Slot released back to pool
- User can re-claim if eligible
- Historical record preserved

**REDEEMED:**
- Used in completed order
- Terminal state (no transitions out)
- Slot permanently consumed
- Links to CouponRedemption

### 14.3 State Validation Rules

**Can Apply Coupon:**
- Claim status = 'active'
- expires_at > now
- No existing reservation for different order

**Can Checkout:**
- Claim status = 'active'
- Reservation created successfully

**Can Redeem:**
- Claim status = 'active'
- Reservation exists for this order
- Payment succeeded

### 14.4 Concurrency Scenarios

**Scenario 1: Two users claim last slot**
```
User A: BEGIN TRANSACTION
User A: SELECT * FROM coupon_claims WHERE coupon_id=1 FOR UPDATE
User A: COUNT active claims = 99 (max_claims=100)
    User B: BEGIN TRANSACTION
    User B: SELECT * FROM coupon_claims WHERE coupon_id=1 FOR UPDATE
    User B: [WAITS for User A lock]
User A: INSERT claim (claim_number=100, status='active')
User A: COMMIT
    User B: [Lock released, query executes]
    User B: COUNT active claims = 100
    User B: No slots available
    User B: ROLLBACK, throw ClaimUnavailableException
```

**Scenario 2: Claim expires during checkout**
```
User: Claim created (expires_at = now + 24 hours)
User: [23 hours 58 minutes pass]
User: Applies coupon to cart → Valid (claim active)
User: Enters checkout → Reservation created → Valid
User: [2 minutes pass, claim expires]
Cron Job: Updates claim status='expired'
User: Completes payment → Redemption validates claim
    → Claim found, payment succeeded
    → Redemption proceeds (grace period)
    → Claim status='redeemed', expires_at ignored
```

**Grace Period Logic:**
When payment succeeds, redemption proceeds if:
- Claim exists for user+coupon
- Claim status = 'active' OR 'expired' (grace for in-flight payments)
- Reservation exists and not expired

---

## 15. CHECKOUT/PAYMENT INTEGRATION

### 15.1 Current Reservation Flow

**Existing:**
```
User adds coupon to cart
    ↓
CouponOrchestrator::validate()
    ↓ [All checks pass]
Cart stores: coupon_id, discount_amount
    ↓
User proceeds to checkout
    ↓
CouponReservationService::reserve()
    ↓ [Creates reservation, 30-min expiry]
Order created with coupon_id
    ↓
Payment processing
    ↓ [Success]
CouponReservationService::consume()
    ↓
CouponUsage/CouponAssignmentUsage created
Counters incremented
```

### 15.2 New Flow with Claims

**Modified:**
```
User clicks "Claim Coupon" (if max_claims set)
    ↓
CouponClaimService::claim()
    ↓ [Claim created, status='active']
User sees "Coupon Claimed" badge

User adds coupon to cart
    ↓
CouponOrchestrator::validate()
    ↓ Eligibility check (assignment + targeting)
    ↓ Claim check (if required): ensureActiveClaim()
    ↓ [All checks pass]
Cart stores: coupon_id, discount_amount, claim_id

User proceeds to checkout
    ↓
CouponReservationService::reserve()
    ↓ [Validates claim still active]
    ↓ [Creates reservation, 30-min expiry]
Order created with coupon_id, claim_id

Payment processing
    ↓ [Success]
CouponReservationService::consume()
    ↓
CouponRedemption created (with claim_id, assignment_id)
Claim status='redeemed', redeemed_at=now
Counters incremented (coupon.used, assignment.used if applicable)
Event: AssignedCouponConsumed / ClaimedCouponRedeemed
```

### 15.3 Modified Services

**CouponOrchestrator::validate() Changes:**
```php
public function validate(string $code, int $userId, Cart $cart): CouponValidationResult
{
    $coupon = Coupon::where('code', $code)->firstOrFail();
    
    // NEW: Eligibility check
    $eligibility = $this->eligibilityService->isEligible($coupon, $userId);
    if (!$eligibility->eligible) {
        throw new CouponNotEligibleException($eligibility->reason);
    }
    
    // NEW: Claim check
    if ($eligibility->requiresClaim) {
        $claim = $this->claimService->getActiveClaim($coupon->id, $userId);
        if (!$claim) {
            throw new ClaimRequiredException('You must claim this coupon first');
        }
    }
    
    // Existing validations
    $this->couponValidator->validateStatus($coupon);
    $this->couponValidator->validateDates($coupon);
    $this->couponValidator->validateProductEligibility($coupon, $cart);
    $this->couponValidator->validateMinimumAmount($coupon, $cart);
    
    $discount = $this->calculator->calculate($coupon, $cart);
    
    return new CouponValidationResult($coupon, $discount, $claim ?? null);
}
```

**CouponReservationService::reserve() Changes:**
```php
public function reserve(Coupon $coupon, int $userId, int $orderId, ?int $claimId = null): CouponReservation
{
    return DB::transaction(function() use ($coupon, $userId, $orderId, $claimId) {
        // Lock coupon row
        $coupon = Coupon::where('id', $coupon->id)->lockForUpdate()->first();
        
        // NEW: Validate claim if provided
        if ($claimId) {
            $claim = CouponClaim::where('id', $claimId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
                
            if (!$claim || $claim->status !== 'active') {
                throw new InvalidClaimException('Claim not active');
            }
        }
        
        // Count active reservations
        $activeCount = CouponReservation::where('coupon_id', $coupon->id)
            ->where('expires_at', '>', now())
            ->count();
        
        // Check capacity
        if ($coupon->limiter && ($coupon->used + $activeCount) >= $coupon->limiter) {
            throw new CouponCapacityExceededException();
        }
        
        // Create reservation
        return CouponReservation::create([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'order_id' => $orderId,
            'claim_id' => $claimId,
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(30)
        ]);
    });
}
```

**CouponReservationService::consume() Changes:**
```php
public function consume(int $orderId): void
{
    DB::transaction(function() use ($orderId) {
        $reservation = CouponReservation::where('order_id', $orderId)
            ->lockForUpdate()
            ->firstOrFail();
        
        $coupon = Coupon::lockForUpdate()->find($reservation->coupon_id);
        
        // NEW: Handle claim redemption
        if ($reservation->claim_id) {
            $claim = CouponClaim::lockForUpdate()->find($reservation->claim_id);
            $claim->update([
                'status' => 'redeemed',
                'redeemed_at' => now()
            ]);
        }
        
        // Create redemption record
        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'user_id' => $reservation->user_id,
            'order_id' => $orderId,
            'claim_id' => $reservation->claim_id,
            'assignment_id' => $this->getAssignmentId($coupon->id, $reservation->user_id),
            'redeemed_at' => now()
        ]);
        
        // Increment counters
        $coupon->increment('used');
        
        if ($assignment = CouponAssignment::where([
            'coupon_id' => $coupon->id,
            'user_id' => $reservation->user_id
        ])->first()) {
            $assignment->increment('used');
        }
        
        // Delete reservation
        $reservation->delete();
        
        // Fire events
        event(new CouponRedeemed($coupon, $reservation->user_id, $orderId));
    });
}
```

### 15.4 Order Model Changes

**Add to orders table:**
```sql
ALTER TABLE orders ADD COLUMN claim_id BIGINT UNSIGNED NULL AFTER coupon_id;
ALTER TABLE orders ADD FOREIGN KEY (claim_id) REFERENCES coupon_claims(id) ON DELETE SET NULL;
```

**Add to coupon_reservations table:**
```sql
ALTER TABLE coupon_reservations ADD COLUMN claim_id BIGINT UNSIGNED NULL AFTER order_id;
ALTER TABLE coupon_reservations ADD FOREIGN KEY (claim_id) REFERENCES coupon_claims(id) ON DELETE CASCADE;
```

---

## 16. REDEMPTION/USAGE

### 16.1 New Redemption Model

**Replace:** `coupon_usages` and `coupon_assignment_usages` tables

**With:** Unified `coupon_redemptions` table

```sql
CREATE TABLE coupon_redemptions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    claim_id BIGINT UNSIGNED NULL,
    assignment_id BIGINT UNSIGNED NULL,
    discount_amount DECIMAL(10, 2) NOT NULL,
    redeemed_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    
    UNIQUE KEY unique_order (order_id),
    INDEX idx_coupon_user (coupon_id, user_id),
    INDEX idx_user (user_id, redeemed_at),
    INDEX idx_claim (claim_id),
    INDEX idx_assignment (assignment_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (claim_id) REFERENCES coupon_claims(id) ON DELETE SET NULL,
    FOREIGN KEY (assignment_id) REFERENCES coupon_assignments(id) ON DELETE SET NULL
);
```

### 16.2 Redemption Fields

| Field | Type | Purpose |
|-------|------|---------|
| claim_id | BIGINT NULL | Links to claim if coupon was claimed |
| assignment_id | BIGINT NULL | Links to assignment if coupon was assigned |
| discount_amount | DECIMAL | Actual discount applied (audit trail) |
| redeemed_at | TIMESTAMP | When redemption occurred |

**Business Rules:**
- One redemption per order (UNIQUE order_id)
- Both claim_id and assignment_id can be set (AND mode)
- Either/both can be NULL (public coupon or OR mode)

### 16.3 Usage Validation

**Check if user already redeemed:**
```php
// OLD (multiple tables):
$usedGeneric = CouponUsage::where(['coupon_id' => $id, 'user_id' => $userId])->exists();
$usedAssignment = CouponAssignmentUsage::where(['coupon_id' => $id, 'user_id' => $userId])->exists();

// NEW (unified):
$alreadyRedeemed = CouponRedemption::where([
    'coupon_id' => $id,
    'user_id' => $userId
])->exists();
```

**Per-user usage limit:**
- Enforced by checking CouponRedemption count
- Assignment max_uses checked separately (may allow multiple redemptions)

### 16.4 Migration Strategy

**Data Migration:**
```sql
-- Migrate coupon_usages
INSERT INTO coupon_redemptions (coupon_id, user_id, order_id, discount_amount, redeemed_at, created_at)
SELECT coupon_id, user_id, order_id, 0.00, used_at, used_at
FROM coupon_usages;

-- Migrate coupon_assignment_usages
INSERT INTO coupon_redemptions (coupon_id, user_id, order_id, assignment_id, discount_amount, redeemed_at, created_at)
SELECT cau.coupon_id, cau.user_id, cau.order_id, ca.id, 0.00, cau.used_at, cau.used_at
FROM coupon_assignment_usages cau
JOIN coupon_assignments ca ON ca.coupon_id = cau.coupon_id AND ca.user_id = cau.user_id
ON DUPLICATE KEY UPDATE assignment_id = VALUES(assignment_id);
```

**Backward Compatibility:**
- Keep old tables during transition period
- Dual-write to both systems
- Phase 2: Remove old tables after validation

---

## 17. NOTIFICATION ARCHITECTURE

### 17.1 Notification Events

**New Events:**

| Event | Trigger | Payload | Channel |
|-------|---------|---------|---------|
| `CouponBecameEligible` | User enters eligibility (first time) | coupon, userId | DB, Email |
| `ClaimSucceeded` | User claims limited coupon | coupon, claim, userId | DB |
| `ClaimExpiringSoon` | Claim expires in 2 hours | coupon, claim, userId | DB, Email |
| `ClaimExpired` | Claim expired | coupon, claim, userId | DB |
| `CouponRedeemed` | Coupon used in order | coupon, redemption, userId | DB |

**Existing Events (kept):**
- `CouponAssigned` — admin assigns to user
- `AssignedCouponConsumed` — assigned coupon redeemed

### 17.2 Notification Schema

**Use existing notifications table:**
```sql
-- Existing table, no changes needed
CREATE TABLE notifications (
    id CHAR(36) PRIMARY KEY,
    type VARCHAR(255) NOT NULL,
    notifiable_type VARCHAR(255) NOT NULL,
    notifiable_id BIGINT UNSIGNED NOT NULL,
    data JSON NOT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    INDEX idx_notifiable (notifiable_type, notifiable_id)
);
```

**Add tracking table:**
```sql
CREATE TABLE coupon_eligibility_notifications (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notified_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    
    UNIQUE KEY unique_coupon_user (coupon_id, user_id),
    INDEX idx_notified (notified_at),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### 17.3 Notification Listeners

**New Listeners:**

`app/Listeners/SendCouponEligibilityNotification.php`
```php
class SendCouponEligibilityNotification
{
    public function handle(CouponBecameEligible $event): void
    {
        $user = User::find($event->userId);
        $user->notify(new CouponNowAvailableNotification($event->coupon));
    }
}
```

`app/Listeners/SendClaimExpiryWarning.php`
```php
class SendClaimExpiryWarning
{
    public function handle(ClaimExpiringSoon $event): void
    {
        $user = User::find($event->userId);
        $user->notify(new ClaimExpiringNotification($event->coupon, $event->claim));
    }
}
```

### 17.4 Scheduled Notification Jobs

**Claim Expiry Warning Job:**
```php
// app/Jobs/SendClaimExpiryWarnings.php
class SendClaimExpiryWarnings implements ShouldQueue
{
    public function handle(): void
    {
        $threshold = now()->addHours(2);
        
        $claims = CouponClaim::where('status', 'active')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', $threshold)
            ->whereDoesntHave('expiryWarningNotification')
            ->with('coupon', 'user')
            ->get();
        
        foreach ($claims as $claim) {
            event(new ClaimExpiringSoon($claim->coupon, $claim, $claim->user_id));
            
            // Mark warning sent
            ClaimExpiryWarning::create([
                'claim_id' => $claim->id,
                'sent_at' => now()
            ]);
        }
    }
}
```

**Schedule in Kernel:**
```php
$schedule->job(new SendClaimExpiryWarnings())->everyFiveMinutes();
$schedule->job(new ExpireOldClaims())->everyFiveMinutes();
```

### 17.5 Notification Preferences

**Add to users or user_profiles:**
```sql
ALTER TABLE users ADD COLUMN notify_coupon_eligible BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE users ADD COLUMN notify_claim_expiring BOOLEAN NOT NULL DEFAULT TRUE;
```

**Check preference before sending:**
```php
if ($user->notify_coupon_eligible) {
    $user->notify(new CouponNowAvailableNotification($coupon));
}
```

---

## 18. API CONTRACT

### 18.1 New Endpoints

**Public/Customer APIs:**

```
POST   /api/v1/coupons/claim
GET    /api/v1/me/coupons/claims
GET    /api/v1/me/coupons/eligible
DELETE /api/v1/me/coupons/claims/{claimId}
```

**Admin APIs:**

```
POST   /api/v1/admin/coupons/{id}/targeting
PUT    /api/v1/admin/coupons/{id}/targeting
GET    /api/v1/admin/coupons/{id}/targeting
DELETE /api/v1/admin/coupons/{id}/targeting
POST   /api/v1/admin/coupons/{id}/generate-audience
GET    /api/v1/admin/coupons/{id}/audience
GET    /api/v1/admin/coupons/{id}/claims
GET    /api/v1/admin/coupons/{id}/analytics
```

### 18.2 Request/Response Schemas

**POST /api/v1/coupons/claim**
```json
// Request
{
  "coupon_code": "SUMMER2026"
}

// Response 200 OK
{
  "success": true,
  "message": "Coupon claimed successfully",
  "data": {
    "claim": {
      "id": 12345,
      "coupon_id": 1,
      "claim_number": 87,
      "status": "active",
      "claimed_at": "2026-09-07T10:30:00Z",
      "expires_at": "2026-09-08T10:30:00Z"
    }
  }
}

// Response 409 Conflict
{
  "success": false,
  "message": "No claim slots available",
  "errors": {
    "claim": ["All 100 slots have been claimed"]
  }
}

// Response 422 Unprocessable
{
  "success": false,
  "message": "You are not eligible for this coupon",
  "errors": {
    "eligibility": ["You must have completed at least 3 orders"]
  }
}
```

**GET /api/v1/me/coupons/claims**
```json
// Response 200 OK
{
  "success": true,
  "data": [
    {
      "id": 12345,
      "coupon": {
        "id": 1,
        "code": "SUMMER2026",
        "name": "Summer Sale",
        "discount_type": "percentage",
        "discount": 20
      },
      "claim_number": 87,
      "status": "active",
      "claimed_at": "2026-09-07T10:30:00Z",
      "expires_at": "2026-09-08T10:30:00Z",
      "hours_remaining": 23.5
    }
  ],
  "meta": {
    "total": 1
  }
}
```

**GET /api/v1/me/coupons/eligible**
```json
// Response 200 OK
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "SUMMER2026",
      "name": "Summer Sale",
      "discount_type": "percentage",
      "discount": 20,
      "eligibility": {
        "eligible": true,
        "reason": "targeting",
        "requires_claim": true,
        "claim_status": null,
        "slots_available": 13
      }
    },
    {
      "id": 2,
      "code": "VIP100",
      "name": "VIP Discount",
      "discount_type": "fixed_rate",
      "discount": 100,
      "eligibility": {
        "eligible": true,
        "reason": "assignment",
        "requires_claim": false
      }
    }
  ]
}
```

**POST /api/v1/admin/coupons/{id}/targeting**
```json
// Request
{
  "assignment_targeting_mode": "or",
  "audience_mode": "dynamic",
  "notify_on_eligibility": true,
  "rules": {
    "type": "group",
    "operator": "and",
    "rules": [
      {
        "type": "rule",
        "field": "user.country",
        "operator": "in",
        "value": ["US", "CA"]
      },
      {
        "type": "rule",
        "field": "order.completed_count",
        "operator": ">=",
        "value": 3
      }
    ]
  }
}

// Response 201 Created
{
  "success": true,
  "message": "Targeting rules created",
  "data": {
    "id": 1,
    "coupon_id": 1,
    "rules": { ... },
    "created_at": "2026-09-07T10:30:00Z"
  }
}
```

**POST /api/v1/admin/coupons/{id}/generate-audience**
```json
// Request
{}

// Response 200 OK
{
  "success": true,
  "message": "Audience snapshot generated",
  "data": {
    "coupon_id": 1,
    "eligible_users_count": 1547,
    "generated_at": "2026-09-07T10:30:00Z",
    "notifications_sent": 1547
  }
}
```

### 18.3 Validation Rules

**Claim Request:**
- `coupon_code`: required|string|exists:coupons,code

**Targeting Rules:**
- `assignment_targeting_mode`: nullable|in:assignment_only,targeting_only,or,and
- `audience_mode`: required|in:dynamic,snapshot
- `notify_on_eligibility`: boolean
- `rules`: required|array (validated by CouponTargetingValidator)

### 18.4 Authorization Policies

**Claim Policy:**
```php
class CouponClaimPolicy
{
    public function claim(User $user, Coupon $coupon): bool
    {
        // User must be authenticated
        // Coupon must be active
        // User must be eligible
        // Claim slots available (if max_claims set)
        return true;
    }
}
```

**Targeting Policy:**
```php
class CouponTargetingPolicy
{
    public function manage(User $user, Coupon $coupon): bool
    {
        return $user->hasPermission('manage_coupons');
    }
}
```

---

## 19. DATABASE SCHEMA

### 19.1 Complete Schema Changes

**New Tables:**

1. **coupon_targetings**
2. **coupon_claims**
3. **coupon_redemptions**
4. **coupon_audience_snapshots**
5. **coupon_eligibility_notifications**
6. **claim_expiry_warnings** (tracking table)

**Modified Tables:**

1. **coupons** — add 5 columns
2. **coupon_assignments** — add 2 columns
3. **coupon_reservations** — add 1 column
4. **orders** — add 1 column

**Deprecated Tables (Phase 2):**

1. **coupon_usages** — replaced by coupon_redemptions
2. **coupon_assignment_usages** — replaced by coupon_redemptions

### 19.2 Full Migration Sequence

**Migration 1: Add coupon targeting columns**
```sql
ALTER TABLE coupons ADD COLUMN assignment_targeting_mode ENUM('assignment_only', 'targeting_only', 'or', 'and') NULL DEFAULT NULL AFTER status;
ALTER TABLE coupons ADD COLUMN audience_mode ENUM('dynamic', 'snapshot') NOT NULL DEFAULT 'dynamic' AFTER assignment_targeting_mode;
ALTER TABLE coupons ADD COLUMN notify_on_eligibility BOOLEAN NOT NULL DEFAULT FALSE AFTER audience_mode;
ALTER TABLE coupons ADD COLUMN max_claims INT UNSIGNED NULL AFTER limiter;
ALTER TABLE coupons ADD COLUMN claim_expiry_minutes INT UNSIGNED NOT NULL DEFAULT 1440 AFTER max_claims;
```

**Migration 2: Create coupon_targetings table**
```sql
CREATE TABLE coupon_targetings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL UNIQUE,
    rules JSON NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Migration 3: Create coupon_claims table**
```sql
CREATE TABLE coupon_claims (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    claim_number INT UNSIGNED NOT NULL,
    status ENUM('active', 'redeemed', 'expired') NOT NULL DEFAULT 'active',
    claimed_at TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    redeemed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    UNIQUE KEY unique_coupon_user (coupon_id, user_id),
    UNIQUE KEY unique_claim_number (coupon_id, claim_number),
    INDEX idx_status_expiry (coupon_id, status, expires_at),
    INDEX idx_user (user_id, status),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Migration 4: Create coupon_redemptions table**
```sql
CREATE TABLE coupon_redemptions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    claim_id BIGINT UNSIGNED NULL,
    assignment_id BIGINT UNSIGNED NULL,
    discount_amount DECIMAL(10, 2) NOT NULL,
    redeemed_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    
    UNIQUE KEY unique_order (order_id),
    INDEX idx_coupon_user (coupon_id, user_id),
    INDEX idx_user (user_id, redeemed_at),
    INDEX idx_claim (claim_id),
    INDEX idx_assignment (assignment_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (claim_id) REFERENCES coupon_claims(id) ON DELETE SET NULL,
    FOREIGN KEY (assignment_id) REFERENCES coupon_assignments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Migration 5: Create coupon_audience_snapshots table**
```sql
CREATE TABLE coupon_audience_snapshots (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    generated_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    
    INDEX idx_coupon_user (coupon_id, user_id),
    INDEX idx_generated (coupon_id, generated_at),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Migration 6: Create coupon_eligibility_notifications table**
```sql
CREATE TABLE coupon_eligibility_notifications (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notified_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    
    UNIQUE KEY unique_coupon_user (coupon_id, user_id),
    INDEX idx_notified (notified_at),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Migration 7: Add claim_id to orders and reservations**
```sql
ALTER TABLE orders ADD COLUMN claim_id BIGINT UNSIGNED NULL AFTER coupon_id;
ALTER TABLE orders ADD FOREIGN KEY (claim_id) REFERENCES coupon_claims(id) ON DELETE SET NULL;

ALTER TABLE coupon_reservations ADD COLUMN claim_id BIGINT UNSIGNED NULL AFTER order_id;
ALTER TABLE coupon_reservations ADD FOREIGN KEY (claim_id) REFERENCES coupon_claims(id) ON DELETE CASCADE;
```

**Migration 8: Enhance coupon_assignments**
```sql
ALTER TABLE coupon_assignments ADD COLUMN assigned_by_user_id BIGINT UNSIGNED NULL AFTER user_id;
ALTER TABLE coupon_assignments ADD COLUMN notes TEXT NULL AFTER expires_at;
ALTER TABLE coupon_assignments ADD FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
```

**Migration 9: Create claim_expiry_warnings tracking table**
```sql
CREATE TABLE claim_expiry_warnings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    claim_id BIGINT UNSIGNED NOT NULL UNIQUE,
    sent_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    
    FOREIGN KEY (claim_id) REFERENCES coupon_claims(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 19.3 Data Type Justifications

**JSON for targeting rules:**
- Flexible schema for complex boolean logic
- TiDB supports JSON column type
- Indexed via generated columns if needed

**ENUM for status fields:**
- Fixed set of values
- Database-level constraint
- Performance benefit over string

**TIMESTAMP vs DATETIME:**
- TIMESTAMP for UTC storage (auto-converts)
- Use for claimed_at, expires_at, redeemed_at

**UNIQUE constraints:**
- Enforce one claim per user per coupon
- Enforce one claim per slot number
- Prevent double-redemption per order

---

## 20. INDEXES

### 20.1 Critical Indexes for Performance

**New Indexes Required:**

#### coupon_claims table:
```sql
-- Already defined in schema:
-- UNIQUE KEY unique_coupon_user (coupon_id, user_id)
-- UNIQUE KEY unique_claim_number (coupon_id, claim_number)
-- INDEX idx_status_expiry (coupon_id, status, expires_at)
-- INDEX idx_user (user_id, status)
```

#### coupon_redemptions table:
```sql
-- Already defined in schema:
-- UNIQUE KEY unique_order (order_id)
-- INDEX idx_coupon_user (coupon_id, user_id)
-- INDEX idx_user (user_id, redeemed_at)
-- INDEX idx_claim (claim_id)
-- INDEX idx_assignment (assignment_id)
```

#### orders table (for targeting queries):
```sql
-- Add composite index for lifetime spend calculations:
CREATE INDEX idx_user_status_total ON orders(user_id, status, total_price);
CREATE INDEX idx_user_completed ON orders(user_id, completed_at) WHERE status = 'completed';
```

#### order_products table (for purchase history):
```sql
-- Add composite indexes:
CREATE INDEX idx_order_product ON order_products(order_id, product_id);
CREATE INDEX idx_product ON order_products(product_id);
```

#### products table (for category/brand filtering):
```sql
-- Likely already exists, verify:
CREATE INDEX idx_category ON products(category_id);
CREATE INDEX idx_brand ON products(brand_id);
```

#### coupon_audience_snapshots table:
```sql
-- Already defined:
-- INDEX idx_coupon_user (coupon_id, user_id)
-- INDEX idx_generated (coupon_id, generated_at)
```

#### coupon_eligibility_notifications table:
```sql
-- Already defined:
-- UNIQUE KEY unique_coupon_user (coupon_id, user_id)
-- INDEX idx_notified (notified_at)
```

### 20.2 Index Justifications

**orders(user_id, status, total_price):**
- Used by: Lifetime spend calculation
- Query: `SELECT SUM(total_price) FROM orders WHERE user_id = ? AND status = 'completed'`
- Covers: user lookup + status filter + sum aggregation

**order_products(order_id, product_id):**
- Used by: Purchase history checks
- Query: `JOIN order_products ON orders.id = order_products.order_id WHERE product_id IN (?)`
- Covers: join key + product filter

**coupon_claims(coupon_id, status, expires_at):**
- Used by: Claim allocation (counting active claims)
- Query: `SELECT COUNT(*) FROM coupon_claims WHERE coupon_id = ? AND status = 'active'`
- Used by: Expiry job (finding expiring claims)
- Query: `SELECT * FROM coupon_claims WHERE status = 'active' AND expires_at < ?`

**coupon_redemptions(coupon_id, user_id):**
- Used by: User redemption check
- Query: `SELECT 1 FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ? LIMIT 1`

### 20.3 TiDB Index Considerations

**Clustered vs Secondary:**
- TiDB uses clustered index on PRIMARY KEY by default
- Secondary indexes stored separately in TiKV
- Covering indexes preferred for read-heavy queries

**Index Prefix Optimization:**
- For composite indexes, leftmost prefix rule applies
- Index (coupon_id, status, expires_at) covers:
  - (coupon_id)
  - (coupon_id, status)
  - (coupon_id, status, expires_at)

**Avoid Over-Indexing:**
- Each index increases write cost
- TiDB recommends ≤5 indexes per table
- Prioritize most frequent query patterns

### 20.4 Index Monitoring

**Queries to Monitor:**
```sql
-- Find slow targeting queries:
SELECT * FROM INFORMATION_SCHEMA.SLOW_QUERY 
WHERE Query_time > 1 
  AND Query LIKE '%coupon%'
ORDER BY Query_time DESC LIMIT 20;

-- Check index usage:
SELECT 
    table_name,
    index_name,
    cardinality
FROM INFORMATION_SCHEMA.STATISTICS
WHERE table_schema = 'meem_db'
  AND table_name IN ('coupon_claims', 'coupon_redemptions', 'orders', 'order_products')
ORDER BY table_name, index_name;
```

---

## 21. CONCURRENCY STRATEGY

### 21.1 Claim Allocation Concurrency

**Problem:** Two users claiming last available slot simultaneously.

**Solution:** Pessimistic locking with `FOR UPDATE`

**Implementation:**
```php
public function claim(Coupon $coupon, int $userId): CouponClaim
{
    return DB::transaction(function() use ($coupon, $userId) {
        // Lock coupon row to prevent concurrent reads
        $lockedCoupon = Coupon::where('id', $coupon->id)
            ->lockForUpdate()
            ->first();
        
        // Check if user already has active claim
        $existingClaim = CouponClaim::where([
            'coupon_id' => $coupon->id,
            'user_id' => $userId
        ])->lockForUpdate()->first();
        
        if ($existingClaim && $existingClaim->status === 'active') {
            throw new ClaimAlreadyExistsException();
        }
        
        // Count active + redeemed claims (locked read)
        $claimCount = CouponClaim::where('coupon_id', $coupon->id)
            ->whereIn('status', ['active', 'redeemed'])
            ->lockForUpdate()
            ->count();
        
        if ($lockedCoupon->max_claims && $claimCount >= $lockedCoupon->max_claims) {
            throw new ClaimUnavailableException('All slots claimed');
        }
        
        // Assign next claim number
        $nextClaimNumber = $this->getNextClaimNumber($coupon->id);
        
        // Create claim (UNIQUE constraint as final safety net)
        return CouponClaim::create([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'claim_number' => $nextClaimNumber,
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->addMinutes($lockedCoupon->claim_expiry_minutes)
        ]);
    });
}

private function getNextClaimNumber(int $couponId): int
{
    // Find highest claim_number (including expired claims)
    $max = CouponClaim::where('coupon_id', $couponId)
        ->max('claim_number');
    
    return ($max ?? 0) + 1;
}
```

**Concurrency Guarantees:**
1. `FOR UPDATE` lock prevents dirty reads
2. Transaction isolation prevents phantom reads
3. UNIQUE(coupon_id, claim_number) prevents duplicate numbers
4. UNIQUE(coupon_id, user_id) prevents duplicate claims per user

### 21.2 Reservation Concurrency (Existing)

**Current Implementation:** Already correct in `CouponReservationService::reserve()`

```php
DB::transaction(function() use ($coupon, $userId, $orderId) {
    // Lock coupon row
    $coupon = Coupon::where('id', $coupon->id)->lockForUpdate()->first();
    
    // Count active reservations (locked)
    $activeCount = CouponReservation::where('coupon_id', $coupon->id)
        ->where('expires_at', '>', now())
        ->lockForUpdate()
        ->count();
    
    // Check capacity
    if ($coupon->limiter && ($coupon->used + $activeCount) >= $coupon->limiter) {
        throw new CouponCapacityExceededException();
    }
    
    // Create reservation
    return CouponReservation::create([...]);
});
```

**No Changes Required:** Existing logic already prevents overselling.

### 21.3 Redemption Concurrency

**Problem:** Concurrent payment completions for same coupon/user.

**Solution:** Existing implementation already safe via:
1. `coupon_consumed` flag on order (prevents double-call)
2. UNIQUE(order_id) on `coupon_redemptions`
3. Transaction + `FOR UPDATE` on counters

**Current Flow (already correct):**
```php
DB::transaction(function() use ($orderId) {
    $reservation = CouponReservation::where('order_id', $orderId)
        ->lockForUpdate()
        ->firstOrFail();
    
    $coupon = Coupon::lockForUpdate()->find($reservation->coupon_id);
    
    // Atomic increment
    $coupon->increment('used');
    
    // UNIQUE(order_id) prevents duplicate redemptions
    CouponRedemption::create([...]);
    
    $reservation->delete();
});
```

### 21.4 Snapshot Generation Concurrency

**Problem:** Admin regenerates snapshot while users are checking eligibility.

**Solution:** Eventual consistency acceptable. Use separate transaction.

**Implementation:**
```php
public function generate(Coupon $coupon): int
{
    // No need to lock coupon during generation
    // Eligibility checks use READ UNCOMMITTED or cached results
    
    DB::transaction(function() use ($coupon) {
        // Delete old snapshot (cascades to ongoing eligibility checks)
        CouponAudienceSnapshot::where('coupon_id', $coupon->id)->delete();
        
        // Fetch eligible users (can take seconds for large audience)
        $eligibleUserIds = $this->fetchEligibleUsers($coupon);
        
        // Batch insert (single write operation)
        $rows = $eligibleUserIds->map(fn($userId) => [...])->toArray();
        CouponAudienceSnapshot::insert($rows);
    });
    
    return $eligibleUserIds->count();
}
```

**Race Condition:** User checks eligibility during snapshot regeneration → may see empty snapshot briefly.

**Mitigation:** 
- Front-end retry on empty snapshot
- Cache previous snapshot for 60 seconds during regeneration

### 21.5 Counter Increment Race Conditions

**Problem:** Multiple concurrent redemptions incrementing `coupon.used`.

**Solution:** Database-level atomic increment.

**Laravel Implementation:**
```php
// CORRECT (atomic):
$coupon->increment('used');

// WRONG (race condition):
$coupon->used += 1;
$coupon->save();
```

**Generated SQL:**
```sql
-- Atomic increment:
UPDATE coupons SET used = used + 1 WHERE id = ?

-- Non-atomic (vulnerable):
UPDATE coupons SET used = 5 WHERE id = ?  -- value could be stale
```

### 21.6 TiDB Concurrency Model

**Isolation Level:** TiDB defaults to `REPEATABLE READ` (same as MySQL InnoDB)

**Pessimistic vs Optimistic:**
- TiDB 3.0+ supports both
- Pessimistic locking: `FOR UPDATE` (default for new transactions)
- Optimistic locking: commit-time conflict detection

**Recommendation:** Use pessimistic locking (`FOR UPDATE`) for:
- Claim allocation
- Reservation creation
- Counter increments

**Deadlock Prevention:**
- Always lock tables in same order: coupon → claim → reservation
- Keep transactions short
- Avoid nested transactions

---

## 22. TIDB COMPATIBILITY

### 22.1 TiDB vs MySQL Differences

**Compatible Features (used in this design):**
✅ `AUTO_INCREMENT`
✅ `UNIQUE` constraints
✅ Foreign keys (supported in TiDB 6.6+)
✅ JSON column type
✅ `FOR UPDATE` pessimistic locking
✅ Transactions with `REPEATABLE READ`
✅ Composite indexes
✅ `ENUM` columns
✅ `TIMESTAMP` columns with auto-update

**Incompatible Features (avoided in this design):**
❌ Spatial indexes
❌ Full-text indexes (use external search engine)
❌ Triggers (avoid, use application logic)
❌ Stored procedures (avoid, use application logic)

### 22.2 AUTO_INCREMENT Handling

**TiDB Behavior:** Auto-increment values are not strictly sequential in distributed deployments.

**Impact on claim_number:**
- `claim_number` is NOT auto-increment
- Manually assigned via `getNextClaimNumber()` method
- Safe for distributed TiDB

**Recommendation:** Continue using manual sequence for claim_number.

### 22.3 Foreign Key Support

**TiDB 6.6+:** Foreign keys fully supported with referential integrity.

**Schema uses `ON DELETE CASCADE` and `ON DELETE SET NULL`:**
```sql
FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
FOREIGN KEY (claim_id) REFERENCES coupon_claims(id) ON DELETE SET NULL
```

**Compatibility:** ✅ Fully supported in TiDB 6.6+

**Fallback (if older TiDB):** Application-level cascade handling.

### 22.4 JSON Column Performance

**TiDB JSON Support:**
- JSON columns supported
- JSON functions: `JSON_EXTRACT`, `JSON_CONTAINS`, `JSON_ARRAY`, etc.
- Generated columns can index JSON paths

**Optimization for targeting rules:**
```sql
-- Option 1: Store as JSON (current design)
ALTER TABLE coupon_targetings ADD COLUMN rules JSON NOT NULL;

-- Option 2: Add generated column for common filters (future optimization)
ALTER TABLE coupon_targetings 
ADD COLUMN country_list JSON AS (JSON_EXTRACT(rules, '$.rules[*].value')) VIRTUAL;

CREATE INDEX idx_country ON coupon_targetings((CAST(country_list AS CHAR(500))));
```

**Recommendation:** Start with plain JSON, add generated columns if query performance degrades.

### 22.5 Transaction Isolation

**TiDB Default:** `REPEATABLE READ`

**Snapshot Isolation:** TiDB uses snapshot isolation (not strict two-phase locking like MySQL InnoDB).

**Impact:**
- `FOR UPDATE` prevents write-write conflicts
- Phantom reads still possible without explicit locking
- Use `FOR UPDATE` on all conflict-prone queries

**Example (correct usage):**
```php
// Lock all claims for counting
$count = CouponClaim::where('coupon_id', $id)
    ->whereIn('status', ['active', 'redeemed'])
    ->lockForUpdate()
    ->count();
```

### 22.6 Timestamp Precision

**TiDB:** Supports microsecond precision (6 digits).

**MySQL:** Supports microsecond precision (6 digits).

**Schema:**
```sql
-- Both compatible:
claimed_at TIMESTAMP NOT NULL
expires_at TIMESTAMP NOT NULL
```

**No Changes Required.**

### 22.7 Character Set

**TiDB Default:** `utf8mb4`

**Schema:** All tables use `utf8mb4_unicode_ci`

**Compatibility:** ✅ Fully compatible.

### 22.8 Testing on TiDB

**Pre-Deployment Checklist:**
1. Run full test suite against TiDB staging cluster
2. Verify foreign key cascade behavior
3. Load test claim allocation concurrency (100+ concurrent users)
4. Verify JSON query performance with realistic rule complexity
5. Test snapshot generation with 10K+ eligible users
6. Monitor for deadlocks during concurrent redemptions

---

## 23. PERFORMANCE STRATEGY

### 23.1 Query Optimization

**Lifetime Spend Calculation:**

**Naive (slow):**
```php
$spend = Order::where('user_id', $userId)
    ->where('status', 'completed')
    ->sum('total_price');
```

**Optimized (cached):**
```php
// Pre-computed column in user_profiles
$spend = $user->profile->lifetime_spend_cache;

// Updated on order completion:
$user->profile->increment('lifetime_spend_cache', $order->total_price);
```

**Purchase History Check:**

**Naive (N+1):**
```php
foreach ($productIds as $productId) {
    $purchased = OrderProduct::whereHas('order', fn($q) => 
        $q->where('user_id', $userId)->where('status', 'completed')
    )->where('product_id', $productId)->exists();
}
```

**Optimized (single query):**
```php
$purchasedProductIds = OrderProduct::whereHas('order', fn($q) => 
        $q->where('user_id', $userId)->where('status', 'completed')
    )
    ->whereIn('product_id', $productIds)
    ->pluck('product_id')
    ->unique();

$hasPurchased = $purchasedProductIds->intersect($productIds)->isNotEmpty();
```

### 23.2 Caching Strategy

**Eligibility Results:**
- Cache key: `eligibility:{coupon_id}:{user_id}`
- TTL: 60 seconds (dynamic), 3600 seconds (snapshot)
- Invalidate on: order completion, profile update, snapshot regeneration

**Targeting Rule Evaluation:**
- Cache individual rule results within request
- Cache key: `rule:{rule_hash}:{user_id}`
- TTL: 60 seconds

**Snapshot Audience:**
- Cache snapshot existence check
- Cache key: `snapshot:{coupon_id}:{user_id}`
- TTL: 3600 seconds
- Invalidate on: snapshot regeneration

**User Order Metrics:**
- Cache computed metrics
- Store in `user_profiles.lifetime_spend_cache`, `completed_orders_count_cache`
- Update on order completion
- Scheduled reconciliation job (daily)

### 23.3 Batch Operations

**Snapshot Generation:**
```php
// Batch insert (1000 rows at a time)
$eligibleUserIds->chunk(1000)->each(function($chunk) use ($couponId, $now) {
    $rows = $chunk->map(fn($userId) => [
        'coupon_id' => $couponId,
        'user_id' => $userId,
        'generated_at' => $now,
        'created_at' => $now
    ])->toArray();
    
    CouponAudienceSnapshot::insert($rows);
});
```

**Eligibility Notification:**
```php
// Queue notifications instead of sending inline
$eligibleUserIds->each(function($userId) use ($coupon) {
    SendEligibilityNotification::dispatch($coupon, $userId);
});
```

### 23.4 Database Connection Pooling

**TiDB Recommendation:**
- Connection pool size: 50-100 per application server
- Idle timeout: 300 seconds
- Max lifetime: 1800 seconds

**Laravel Config:**
```php
// config/database.php
'mysql' => [
    'pool' => [
        'min_connections' => 10,
        'max_connections' => 100,
        'connect_timeout' => 10,
        'wait_timeout' => 300,
        'write_timeout' => 30,
        'read_timeout' => 30,
    ],
],
```

### 23.5 Query Monitoring

**Slow Query Log:**
```sql
-- Enable slow query log in TiDB
SET GLOBAL tidb_slow_log_threshold = 1000; -- 1 second
```

**Laravel Query Logging:**
```php
// AppServiceProvider::boot()
DB::listen(function ($query) {
    if ($query->time > 1000) { // 1 second
        Log::warning('Slow query detected', [
            'sql' => $query->sql,
            'bindings' => $query->bindings,
            'time' => $query->time
        ]);
    }
});
```

### 23.6 Expected Performance Benchmarks

**Claim Allocation:**
- Target: < 100ms (p95)
- Lock contention under 100 concurrent claims

**Eligibility Check (Dynamic):**
- Simple rules (1-2 conditions): < 50ms
- Complex rules (5+ conditions, nested): < 200ms

**Snapshot Generation:**
- 1K users: < 2 seconds
- 10K users: < 10 seconds
- 100K users: < 60 seconds (batched)

**Redemption:**
- Target: < 50ms (existing flow, minimal changes)

---

## 24. SECURITY

### 24.1 Authorization

**Claim Endpoint:**
```php
// CouponClaimPolicy
public function claim(User $user, Coupon $coupon): bool
{
    // Must be authenticated
    if (!$user) {
        return false;
    }
    
    // Coupon must be active
    if (!$coupon->status || $coupon->start_date > now() || $coupon->end_date < now()) {
        return false;
    }
    
    // Check eligibility (assignment + targeting)
    $eligibility = app(CouponEligibilityService::class)->isEligible($coupon, $user->id);
    
    return $eligibility->eligible && $eligibility->requiresClaim;
}
```

**Targeting Management:**
```php
// CouponTargetingPolicy
public function manage(User $user, Coupon $coupon): bool
{
    return $user->hasPermission('manage_coupons')
        || $user->hasPermission('manage_promotions');
}
```

**Snapshot Generation:**
```php
// CouponTargetingPolicy
public function generateSnapshot(User $user, Coupon $coupon): bool
{
    return $user->hasPermission('manage_coupons');
}
```

### 24.2 Input Validation

**Targeting Rules JSON:**
```php
// CouponTargetingRequest
public function rules(): array
{
    return [
        'assignment_targeting_mode' => 'nullable|in:assignment_only,targeting_only,or,and',
        'audience_mode' => 'required|in:dynamic,snapshot',
        'notify_on_eligibility' => 'boolean',
        'rules' => [
            'required',
            'array',
            function($attribute, $value, $fail) {
                $validator = app(CouponTargetingValidator::class);
                try {
                    $validator->validate($value);
                } catch (ValidationException $e) {
                    $fail($e->getMessage());
                }
            }
        ]
    ];
}
```

**Targeting Rules Validator:**
```php
class CouponTargetingValidator
{
    public function validate(array $rules): void
    {
        $this->validateStructure($rules);
        $this->validateDepth($rules, 0, 5);
        $this->validateFields($rules);
        $this->validateOperators($rules);
        $this->validateValues($rules);
    }
    
    private function validateFields(array $rules): void
    {
        $supportedFields = [
            'user.id', 'user.created_at', 'user.country', 'user.language',
            'user.gender', 'user.is_active',
            'order.completed_count', 'order.lifetime_spend', 'order.average_value',
            'purchase.product_id', 'purchase.category_id', 'purchase.brand_id',
            'coupon.used'
        ];
        
        // Recursively check all rule fields
        // Throw ValidationException if unknown field found
    }
}
```

### 24.3 Rate Limiting

**Claim Endpoint:**
```php
// api.php
Route::post('/coupons/claim', [CouponController::class, 'claim'])
    ->middleware(['auth:sanctum', 'throttle:claim'])
    ->name('coupons.claim');

// RouteServiceProvider.php
RateLimiter::for('claim', function (Request $request) {
    return Limit::perMinute(5)->by($request->user()->id);
});
```

**Rationale:** Prevents claim slot hoarding by bots.

**Eligibility Check Endpoint:**
```php
Route::get('/me/coupons/eligible', [CouponController::class, 'eligible'])
    ->middleware(['auth:sanctum', 'throttle:60,1'])
    ->name('me.coupons.eligible');
```

**Rationale:** Expensive query, limit to 60 per minute per user.

### 24.4 Information Disclosure

**Do NOT expose:**
- Internal targeting rule structure to non-admin users
- Exact eligibility criteria in error messages
- User IDs of other claimants
- Admin IDs who assigned coupons

**DO expose:**
- Generic eligibility status ("You are not eligible")
- Number of available claim slots
- Claim expiration time
- Assignment existence (for assigned user only)

**Error Messages:**
```php
// WRONG (leaks internal logic):
throw new NotEligibleException('You must have lifetime_spend >= 500 AND completed_orders >= 3');

// RIGHT (generic):
throw new NotEligibleException('You do not meet the requirements for this coupon');
```

### 24.5 SQL Injection Prevention

**Parameterized Queries:** Laravel Query Builder automatically parameterizes.

**Raw Queries:** Avoid. If necessary, always use bindings:
```php
// CORRECT:
DB::select('SELECT * FROM orders WHERE user_id = ?', [$userId]);

// WRONG:
DB::select("SELECT * FROM orders WHERE user_id = $userId");
```

**JSON Path Injection:**
```php
// Validate field names against whitelist before building JSON queries
if (!in_array($field, $this->supportedFields)) {
    throw new ValidationException("Unsupported field: $field");
}
```

### 24.6 Audit Trail

**Track:**
- Who created targeting rules (admin user ID)
- Who assigned coupons (assigned_by_user_id)
- When snapshots were generated
- When claims were made

**Logging:**
```php
// After claim creation
Log::info('Coupon claimed', [
    'coupon_id' => $coupon->id,
    'user_id' => $user->id,
    'claim_number' => $claim->claim_number,
    'ip_address' => request()->ip()
]);

// After snapshot generation
Log::info('Audience snapshot generated', [
    'coupon_id' => $coupon->id,
    'admin_id' => auth()->id(),
    'eligible_count' => $count
]);
```

### 24.7 Sensitive Data Handling

**Targeting Rules:**
- May contain sensitive criteria (e.g., "users who bought contraceptives")
- Store rules in database (not logged)
- Only show to admin users with `manage_coupons` permission
- Do NOT expose in API responses to regular users

**User Eligibility:**
- Customer can see their own eligibility status
- Customer CANNOT see other users' eligibility
- Customer CANNOT see exact targeting criteria

---

## 25. BACKWARD COMPATIBILITY

### 25.1 Zero Breaking Changes

**Existing Functionality Preserved:**

✅ Public coupons (no assignments, no targeting) work unchanged
✅ Assigned coupons work unchanged
✅ Existing API endpoints unchanged
✅ Existing database tables unchanged (only additions)
✅ Existing service methods unchanged (only additions)
✅ Existing tests pass without modification

### 25.2 Default Behavior

**Coupon without targeting:**
```
assignment_targeting_mode = NULL
audience_mode = 'dynamic'
notify_on_eligibility = FALSE
max_claims = NULL
```

**Behavior:** Identical to current system.

**Eligibility Check:**
```php
if ($coupon->assignment_targeting_mode === null) {
    // Legacy behavior: check assignment only
    return $this->checkAssignmentOnly($coupon, $userId);
}
```

### 25.3 Migration Path

**Phase 1 (Backward Compatible):**
- Deploy new code with feature flags OFF
- Add new tables
- Migrate historical data to `coupon_redemptions`
- Dual-write to old + new tables

**Phase 2 (Feature Rollout):**
- Enable targeting for NEW coupons only
- Existing coupons remain untouched
- Validate targeting works correctly

**Phase 3 (Full Migration):**
- Stop writing to `coupon_usages` and `coupon_assignment_usages`
- Read from `coupon_redemptions` only
- Schedule `coupon_usages` table drop (after 30-day safety period)

### 25.4 Database Migration Safety

**Add Columns (safe):**
```sql
ALTER TABLE coupons ADD COLUMN assignment_targeting_mode ENUM(...) NULL DEFAULT NULL;
ALTER TABLE coupons ADD COLUMN max_claims INT UNSIGNED NULL;
```

**Rationale:** NULL defaults mean existing rows unchanged.

**Add Tables (safe):**
```sql
CREATE TABLE coupon_claims (...);
CREATE TABLE coupon_redemptions (...);
```

**Rationale:** New tables don't affect existing queries.

**Foreign Keys (safe with `ON DELETE SET NULL`):**
```sql
ALTER TABLE orders ADD FOREIGN KEY (claim_id) REFERENCES coupon_claims(id) ON DELETE SET NULL;
```

**Rationale:** Existing orders have `claim_id = NULL` (no constraint violation).

### 25.5 API Backward Compatibility

**Existing Endpoints (unchanged):**
- `POST /api/v1/coupons/apply`
- `GET /api/v1/coupons`
- `POST /api/v1/admin/coupons`
- `POST /api/v1/admin/coupons/{id}/assign`

**New Endpoints (additive):**
- `POST /api/v1/coupons/claim`
- `GET /api/v1/me/coupons/claims`
- `POST /api/v1/admin/coupons/{id}/targeting`

**Response Format (unchanged):**
```json
{
  "success": true,
  "message": "...",
  "data": {...}
}
```

**New Fields (additive, optional):**
```json
{
  "coupon": {
    "id": 1,
    "code": "SAVE20",
    // Existing fields...
    "targeting": {...},          // NEW (only if targeting exists)
    "max_claims": 100,           // NEW (nullable)
    "claims_available": 13       // NEW (only if max_claims set)
  }
}
```

### 25.6 Service Layer Compatibility

**CouponOrchestrator (modified, backward compatible):**
```php
// OLD behavior (if no targeting):
public function validate(Coupon $coupon, User $user): array
{
    if ($coupon->assignment_targeting_mode === null) {
        // Use existing logic (unchanged)
        return $this->validateLegacy($coupon, $user);
    }
    
    // NEW behavior (targeting enabled)
    return $this->validateWithTargeting($coupon, $user);
}
```

**CouponValidator (unchanged):** All existing methods work as-is.

**CouponCalculator (unchanged):** Pure function, no changes needed.

**CouponReservationService (minimal changes):**
- Add optional `claim_id` parameter to `reserve()`
- If `claim_id` is NULL: existing behavior
- If `claim_id` provided: additional claim validation

### 25.7 Test Compatibility

**Existing Tests (unchanged):**
- All existing test files pass without modification
- New tests added in separate files

**Test Files:**
- `tests/Feature/CouponSystemTest.php` (existing, unchanged)
- `tests/Feature/AssignedCouponSystemTest.php` (existing, unchanged)
- `tests/Feature/CouponTargetingTest.php` (NEW)
- `tests/Feature/CouponClaimTest.php` (NEW)
- `tests/Feature/CouponEligibilityTest.php` (NEW)

---

## 26. TESTING STRATEGY

### 26.1 Test Coverage Goals

**Target Coverage:**
- Unit Tests: 100% for rule engine, eligibility service, validators
- Feature Tests: 100% for all user flows
- Integration Tests: 100% for concurrency scenarios

**Test Pyramid:**
- 60% Unit Tests (fast, isolated)
- 30% Feature Tests (realistic flows)
- 10% Integration Tests (database, concurrency)

### 26.2 Unit Tests

**CouponRuleEngineTest:**
```php
test('evaluates user country IN rule', function() {
    $rule = ['field' => 'user.country', 'operator' => 'in', 'value' => ['US', 'CA']];
    $user = User::factory()->create(['country' => 'US']);
    
    $result = $this->ruleEngine->evaluateRule($rule, $user->id);
    
    expect($result)->toBeTrue();
});

test('evaluates order count >= rule', function() {
    $user = User::factory()->create();
    Order::factory()->count(5)->completed()->create(['user_id' => $user->id]);
    
    $rule = ['field' => 'order.completed_count', 'operator' => '>=', 'value' => 3];
    $result = $this->ruleEngine->evaluateRule($rule, $user->id);
    
    expect($result)->toBeTrue();
});

test('evaluates AND group correctly', function() {
    $group = [
        'operator' => 'and',
        'rules' => [
            ['field' => 'user.country', 'operator' => 'in', 'value' => ['US']],
            ['field' => 'order.completed_count', 'operator' => '>=', 'value' => 1]
        ]
    ];
    
    $user = User::factory()->create(['country' => 'US']);
    Order::factory()->completed()->create(['user_id' => $user->id]);
    
    $result = $this->ruleEngine->evaluateGroup($group, $user->id);
    
    expect($result)->toBeTrue();
});
```

**CouponEligibilityServiceTest:**
```php
test('assignment only mode returns eligible for assigned user', function() {
    $coupon = Coupon::factory()->create(['assignment_targeting_mode' => null]);
    $user = User::factory()->create();
    CouponAssignment::factory()->create(['coupon_id' => $coupon->id, 'user_id' => $user->id]);
    
    $result = $this->eligibilityService->isEligible($coupon, $user->id);
    
    expect($result->eligible)->toBeTrue();
    expect($result->reason)->toBe('assignment');
});

test('targeting only mode returns eligible for matching user', function() {
    $coupon = Coupon::factory()->withTargeting([
        'field' => 'user.country',
        'operator' => 'in',
        'value' => ['US']
    ])->create(['assignment_targeting_mode' => 'targeting_only']);
    
    $user = User::factory()->create(['country' => 'US']);
    
    $result = $this->eligibilityService->isEligible($coupon, $user->id);
    
    expect($result->eligible)->toBeTrue();
    expect($result->reason)->toBe('targeting');
});

test('OR mode returns eligible if either assignment or targeting matches', function() {
    $coupon = Coupon::factory()->withTargeting([
        'field' => 'user.country',
        'operator' => 'in',
        'value' => ['CA']
    ])->create(['assignment_targeting_mode' => 'or']);
    
    $user = User::factory()->create(['country' => 'US']); // Does NOT match targeting
    CouponAssignment::factory()->create(['coupon_id' => $coupon->id, 'user_id' => $user->id]);
    
    $result = $this->eligibilityService->isEligible($coupon, $user->id);
    
    expect($result->eligible)->toBeTrue();
    expect($result->reason)->toBe('assignment');
});
```

**CouponTargetingValidatorTest:**
```php
test('validates supported field names', function() {
    $rules = ['field' => 'user.invalid_field', 'operator' => 'in', 'value' => []];
    
    expect(fn() => $this->validator->validate($rules))
        ->toThrow(ValidationException::class, 'Unsupported field');
});

test('validates operator matches field type', function() {
    $rules = ['field' => 'user.country', 'operator' => '>', 'value' => 'US'];
    
    expect(fn() => $this->validator->validate($rules))
        ->toThrow(ValidationException::class, 'Invalid operator for field type');
});
```

### 26.3 Feature Tests

**CouponClaimTest:**
```php
test('user can claim limited coupon when eligible', function() {
    $coupon = Coupon::factory()->withTargeting([
        'field' => 'order.completed_count',
        'operator' => '>=',
        'value' => 1
    ])->create([
        'assignment_targeting_mode' => 'targeting_only',
        'max_claims' => 100
    ]);
    
    $user = User::factory()->create();
    Order::factory()->completed()->create(['user_id' => $user->id]);
    
    $response = $this->actingAs($user)->postJson('/api/v1/coupons/claim', [
        'coupon_code' => $coupon->code
    ]);
    
    $response->assertOk();
    expect(CouponClaim::where(['coupon_id' => $coupon->id, 'user_id' => $user->id])->exists())
        ->toBeTrue();
});

test('user cannot claim when not eligible', function() {
    $coupon = Coupon::factory()->withTargeting([
        'field' => 'order.completed_count',
        'operator' => '>=',
        'value' => 5
    ])->create([
        'assignment_targeting_mode' => 'targeting_only',
        'max_claims' => 100
    ]);
    
    $user = User::factory()->create();
    // No completed orders
    
    $response = $this->actingAs($user)->postJson('/api/v1/coupons/claim', [
        'coupon_code' => $coupon->code
    ]);
    
    $response->assertStatus(422);
    $response->assertJsonPath('message', 'You are not eligible for this coupon');
});

test('claim fails when all slots taken', function() {
    $coupon = Coupon::factory()->create(['max_claims' => 2]);
    
    // Fill all slots
    CouponClaim::factory()->count(2)->active()->create(['coupon_id' => $coupon->id]);
    
    $user = User::factory()->create();
    CouponAssignment::factory()->create(['coupon_id' => $coupon->id, 'user_id' => $user->id]);
    
    $response = $this->actingAs($user)->postJson('/api/v1/coupons/claim', [
        'coupon_code' => $coupon->code
    ]);
    
    $response->assertStatus(409);
    $response->assertJsonPath('message', 'No claim slots available');
});
```

**CouponCheckoutWithClaimTest:**
```php
test('checkout succeeds with valid claim', function() {
    $coupon = Coupon::factory()->create(['max_claims' => 10]);
    $user = User::factory()->create();
    $claim = CouponClaim::factory()->active()->create([
        'coupon_id' => $coupon->id,
        'user_id' => $user->id
    ]);
    
    // Add items to cart
    $cart = Cart::factory()->create(['user_id' => $user->id, 'coupon' => $coupon->code]);
    CartItem::factory()->create(['cart_id' => $cart->id]);
    
    $response = $this->actingAs($user)->postJson('/api/v1/orders', [
        'payment_method' => 'stripe'
    ]);
    
    $response->assertCreated();
    
    $order = Order::where('user_id', $user->id)->latest()->first();
    expect($order->claim_id)->toBe($claim->id);
    
    $reservation = CouponReservation::where('order_id', $order->id)->first();
    expect($reservation->claim_id)->toBe($claim->id);
});

test('checkout fails with expired claim', function() {
    $coupon = Coupon::factory()->create(['max_claims' => 10]);
    $user = User::factory()->create();
    $claim = CouponClaim::factory()->expired()->create([
        'coupon_id' => $coupon->id,
        'user_id' => $user->id
    ]);
    
    $cart = Cart::factory()->create(['user_id' => $user->id, 'coupon' => $coupon->code]);
    CartItem::factory()->create(['cart_id' => $cart->id]);
    
    $response = $this->actingAs($user)->postJson('/api/v1/orders', [
        'payment_method' => 'stripe'
    ]);
    
    $response->assertStatus(400);
    $response->assertJsonPath('message', 'Coupon claim has expired');
});
```

**CouponRedemptionWithClaimTest:**
```php
test('payment success redeems claim and creates redemption', function() {
    $coupon = Coupon::factory()->create(['max_claims' => 10]);
    $user = User::factory()->create();
    $claim = CouponClaim::factory()->active()->create([
        'coupon_id' => $coupon->id,
        'user_id' => $user->id
    ]);
    
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'coupon' => $coupon->code,
        'claim_id' => $claim->id,
        'status' => 'pending'
    ]);
    
    CouponReservation::factory()->create([
        'coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'order_id' => $order->id,
        'claim_id' => $claim->id
    ]);
    
    // Simulate payment success
    $this->orderService->changeOrderStatus($order->id, 'completed');
    
    $order->refresh();
    $claim->refresh();
    
    expect($claim->status)->toBe('redeemed');
    expect($claim->redeemed_at)->not->toBeNull();
    expect($order->coupon_consumed)->toBeTrue();
    
    $redemption = CouponRedemption::where('order_id', $order->id)->first();
    expect($redemption)->not->toBeNull();
    expect($redemption->claim_id)->toBe($claim->id);
});
```

### 26.4 Concurrency Tests

**ClaimAllocationConcurrencyTest:**
```php
test('concurrent claims do not oversell slots', function() {
    $coupon = Coupon::factory()->create(['max_claims' => 10]);
    $users = User::factory()->count(20)->create();
    
    // Ensure all users eligible
    foreach ($users as $user) {
        CouponAssignment::factory()->create(['coupon_id' => $coupon->id, 'user_id' => $user->id]);
    }
    
    // Simulate 20 concurrent claims
    $promises = [];
    foreach ($users as $user) {
        $promises[] = async(fn() => 
            $this->claimService->claim($coupon, $user->id)
        );
    }
    
    $results = await($promises);
    
    $successCount = collect($results)->filter(fn($r) => !$r instanceof Exception)->count();
    $failCount = collect($results)->filter(fn($r) => $r instanceof ClaimUnavailableException)->count();
    
    expect($successCount)->toBe(10); // Exactly 10 succeed
    expect($failCount)->toBe(10);    // Exactly 10 fail
    
    $finalCount = CouponClaim::where('coupon_id', $coupon->id)->count();
    expect($finalCount)->toBe(10); // No overselling
});
```

**ReservationConcurrencyTest:**
```php
test('concurrent reservations respect limiter', function() {
    $coupon = Coupon::factory()->create(['limiter' => 5]);
    $users = User::factory()->count(10)->create();
    $orders = [];
    
    foreach ($users as $user) {
        $orders[] = Order::factory()->create(['user_id' => $user->id]);
    }
    
    // Simulate 10 concurrent reservations
    $promises = [];
    foreach ($orders as $order) {
        $promises[] = async(fn() => 
            $this->reservationService->reserve($coupon, $order->user_id, $order->id)
        );
    }
    
    $results = await($promises);
    
    $successCount = collect($results)->filter(fn($r) => $r instanceof CouponReservation)->count();
    
    expect($successCount)->toBe(5); // Exactly 5 succeed
    
    $finalCount = CouponReservation::where('coupon_id', $coupon->id)->count();
    expect($finalCount)->toBe(5);
});
```

### 26.5 Integration Tests

**SnapshotGenerationTest:**
```php
test('snapshot generation with 1000 eligible users completes', function() {
    $coupon = Coupon::factory()->withTargeting([
        'field' => 'user.country',
        'operator' => 'in',
        'value' => ['US']
    ])->create([
        'assignment_targeting_mode' => 'targeting_only',
        'audience_mode' => 'snapshot'
    ]);
    
    // Create 1000 eligible users
    User::factory()->count(1000)->create(['country' => 'US']);
    
    $count = $this->snapshotService->generate($coupon);
    
    expect($count)->toBe(1000);
    
    $snapshotCount = CouponAudienceSnapshot::where('coupon_id', $coupon->id)->count();
    expect($snapshotCount)->toBe(1000);
});
```

**FullUserFlowTest:**
```php
test('complete flow: become eligible, claim, checkout, pay, redeem', function() {
    $coupon = Coupon::factory()->withTargeting([
        'field' => 'order.completed_count',
        'operator' => '>=',
        'value' => 1
    ])->create([
        'assignment_targeting_mode' => 'targeting_only',
        'max_claims' => 100,
        'discount_type' => 'percentage',
        'discount' => 20
    ]);
    
    $user = User::factory()->create();
    
    // Step 1: User not eligible yet
    $eligibility = $this->eligibilityService->isEligible($coupon, $user->id);
    expect($eligibility->eligible)->toBeFalse();
    
    // Step 2: User completes first order
    $firstOrder = Order::factory()->completed()->create(['user_id' => $user->id]);
    
    // Step 3: User now eligible
    $eligibility = $this->eligibilityService->isEligible($coupon, $user->id);
    expect($eligibility->eligible)->toBeTrue();
    
    // Step 4: User claims coupon
    $claim = $this->claimService->claim($coupon, $user->id);
    expect($claim->status)->toBe('active');
    
    // Step 5: User adds items to cart with coupon
    $cart = Cart::factory()->create(['user_id' => $user->id, 'coupon' => $coupon->code]);
    CartItem::factory()->create(['cart_id' => $cart->id, 'price' => 100]);
    
    // Step 6: User proceeds to checkout
    $order = $this->orderService->addItemsInOrder($user->id);
    expect($order->coupon)->toBe($coupon->code);
    expect($order->coupon_discount)->toBe(20); // 20% of 100
    
    // Step 7: User completes payment
    $this->orderService->changeOrderStatus($order->id, 'completed');
    
    // Step 8: Verify claim redeemed
    $claim->refresh();
    expect($claim->status)->toBe('redeemed');
    
    // Step 9: Verify redemption recorded
    $redemption = CouponRedemption::where('order_id', $order->id)->first();
    expect($redemption)->not->toBeNull();
    expect($redemption->claim_id)->toBe($claim->id);
    expect($redemption->discount_amount)->toBe(20);
});
```

### 26.6 Test Data Factories

**CouponFactory (extended):**
```php
class CouponFactory extends Factory
{
    public function withTargeting(array $rules): static
    {
        return $this->afterCreating(function (Coupon $coupon) use ($rules) {
            CouponTargeting::create([
                'coupon_id' => $coupon->id,
                'rules' => $rules
            ]);
        });
    }
    
    public function withMaxClaims(int $maxClaims): static
    {
        return $this->state(['max_claims' => $maxClaims]);
    }
}
```

**CouponClaimFactory:**
```php
class CouponClaimFactory extends Factory
{
    public function active(): static
    {
        return $this->state([
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->addHours(24)
        ]);
    }
    
    public function expired(): static
    {
        return $this->state([
            'status' => 'expired',
            'claimed_at' => now()->subHours(25),
            'expires_at' => now()->subHours(1)
        ]);
    }
    
    public function redeemed(): static
    {
        return $this->state([
            'status' => 'redeemed',
            'redeemed_at' => now()
        ]);
    }
}
```

---

## 27. FILE-BY-FILE CHANGE PLAN

### 27.1 New Models

**app/Models/CouponTargeting.php** (NEW)
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\Coupon;

class CouponTargeting extends Model
{
    protected $fillable = ['coupon_id', 'rules'];
    protected $casts = ['rules' => 'array'];
    
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }
}
```

**app/Models/CouponClaim.php** (NEW)
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\Coupon;
use App\Models\User;

class CouponClaim extends Model
{
    protected $fillable = [
        'coupon_id', 'user_id', 'claim_number', 'status',
        'claimed_at', 'expires_at', 'redeemed_at'
    ];
    
    protected $casts = [
        'claimed_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime'
    ];
    
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function redemption()
    {
        return $this->hasOne(CouponRedemption::class, 'claim_id');
    }
    
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at > now();
    }
}
```

**app/Models/CouponRedemption.php** (NEW)
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use App\Models\User;
use App\Models\Order;

class CouponRedemption extends Model
{
    protected $fillable = [
        'coupon_id', 'user_id', 'order_id', 'claim_id', 'assignment_id',
        'discount_amount', 'redeemed_at'
    ];
    
    protected $casts = [
        'discount_amount' => 'decimal:2',
        'redeemed_at' => 'datetime'
    ];
    
    public $timestamps = false; // Only created_at
    
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    
    public function claim()
    {
        return $this->belongsTo(CouponClaim::class, 'claim_id');
    }
    
    public function assignment()
    {
        return $this->belongsTo(CouponAssignment::class, 'assignment_id');
    }
}
```

**app/Models/CouponAudienceSnapshot.php** (NEW)
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\Coupon;
use App\Models\User;

class CouponAudienceSnapshot extends Model
{
    protected $fillable = ['coupon_id', 'user_id', 'generated_at'];
    protected $casts = ['generated_at' => 'datetime'];
    public $timestamps = false; // Only created_at
    
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

**app/Models/CouponEligibilityNotification.php** (NEW)
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponEligibilityNotification extends Model
{
    protected $fillable = ['coupon_id', 'user_id', 'notified_at'];
    protected $casts = ['notified_at' => 'datetime'];
    public $timestamps = false; // Only created_at
}
```

### 27.2 Modified Models

**packages/marvel/src/Database/Models/Coupon.php** (MODIFY)

Add to existing model:
```php
// Add to $fillable array:
'assignment_targeting_mode',
'audience_mode',
'notify_on_eligibility',
'max_claims',
'claim_expiry_minutes'

// Add to $casts array:
'notify_on_eligibility' => 'boolean',
'max_claims' => 'integer',
'claim_expiry_minutes' => 'integer'

// Add new relationships:
public function targeting()
{
    return $this->hasOne(\App\Models\CouponTargeting::class);
}

public function claims()
{
    return $this->hasMany(\App\Models\CouponClaim::class);
}

public function redemptions()
{
    return $this->hasMany(\App\Models\CouponRedemption::class);
}

public function audienceSnapshot()
{
    return $this->hasMany(\App\Models\CouponAudienceSnapshot::class);
}

// Add helper methods:
public function hasTargeting(): bool
{
    return $this->assignment_targeting_mode !== null 
        && $this->targeting()->exists();
}

public function isLimitedClaim(): bool
{
    return $this->max_claims !== null;
}

public function availableClaimSlots(): int
{
    if (!$this->isLimitedClaim()) {
        return PHP_INT_MAX;
    }
    
    $used = $this->claims()
        ->whereIn('status', ['active', 'redeemed'])
        ->count();
    
    return max(0, $this->max_claims - $used);
}
```

**packages/marvel/src/Database/Models/CouponAssignment.php** (MODIFY)

Add to existing model:
```php
// Add to $fillable array:
'assigned_by_user_id',
'notes'

// Add new relationship:
public function assignedBy()
{
    return $this->belongsTo(\App\Models\User::class, 'assigned_by_user_id');
}

public function redemptions()
{
    return $this->hasMany(\App\Models\CouponRedemption::class, 'assignment_id');
}
```

**app/Models/CouponReservation.php** (MODIFY)

Add to existing model:
```php
// Add to $fillable array:
'claim_id'

// Add new relationship:
public function claim()
{
    return $this->belongsTo(CouponClaim::class, 'claim_id');
}
```

**app/Models/Order.php** (MODIFY)

Add to existing model:
```php
// Add to $fillable array:
'claim_id'

// Add new relationship:
public function couponClaim()
{
    return $this->belongsTo(\App\Models\CouponClaim::class, 'claim_id');
}

public function couponRedemption()
{
    return $this->hasOne(\App\Models\CouponRedemption::class);
}
```

### 27.3 New Services

**app/Services/Coupon/CouponRuleEngine.php** (NEW)
- `evaluate(array $rules, int $userId): bool`
- `evaluateGroup(array $group, int $userId): bool`
- `evaluateRule(array $rule, int $userId): bool`
- `buildUserQuery(array $rule, int $userId): Builder`
- `buildOrderQuery(array $rule, int $userId): Builder`
- `buildPurchaseQuery(array $rule, int $userId): Builder`
- `buildCouponUsageQuery(array $rule, int $userId): bool`

**app/Services/Coupon/CouponTargetingValidator.php** (NEW)
- `validate(array $rules): void`
- `validateStructure(array $rules): void`
- `validateDepth(array $rules, int $depth, int $maxDepth): void`
- `validateFields(array $rules): void`
- `validateOperators(array $rules): void`
- `validateValues(array $rules): void`
- `getSupportedFields(): array`
- `getSupportedOperators(string $field): array`

**app/Services/Coupon/CouponEligibilityService.php** (NEW)
- `isEligible(Coupon $coupon, int $userId): EligibilityResult`
- `checkAssignment(Coupon $coupon, int $userId): ?CouponAssignment`
- `checkTargeting(Coupon $coupon, int $userId): bool`
- `checkSnapshot(Coupon $coupon, int $userId): bool`
- `checkAndNotifyEligibility(Coupon $coupon, int $userId): void`

**app/Services/Coupon/CouponClaimService.php** (NEW)
- `claim(Coupon $coupon, int $userId): CouponClaim`
- `getActiveClaim(int $couponId, int $userId): ?CouponClaim`
- `ensureActiveClaim(int $couponId, int $userId): CouponClaim`
- `releaseClaim(int $claimId): void`
- `getNextClaimNumber(int $couponId): int`

**app/Services/Coupon/CouponAudienceSnapshotService.php** (NEW)
- `generate(Coupon $coupon): int`
- `isInSnapshot(int $couponId, int $userId): bool`
- `fetchEligibleUsers(Coupon $coupon): Collection`
- `fetchAssignedUsers(Coupon $coupon): Collection`
- `fetchTargetingMatches(Coupon $coupon): Collection`

**app/DTOs/EligibilityResult.php** (NEW)
```php
<?php

namespace App\DTOs;

use Marvel\Database\Models\CouponAssignment;

class EligibilityResult
{
    public function __construct(
        public bool $eligible,
        public string $reason,
        public ?CouponAssignment $assignment = null,
        public bool $requiresClaim = false
    ) {}
}
```

### 27.4 Modified Services

**app/Services/Coupon/CouponOrchestrator.php** (MODIFY)

Replace validation logic:
```php
public function validate(Coupon $coupon, ?User $user, ?Collection $items): array
{
    if (!$user) {
        // Guest flow (unchanged)
        return $this->couponValidator->validate($coupon, null, $items);
    }
    
    // NEW: Eligibility check
    $eligibility = $this->eligibilityService->isEligible($coupon, $user->id);
    
    if (!$eligibility->eligible) {
        return [
            'valid' => false,
            'reason' => 'not_eligible',
            'message' => $this->getEligibilityMessage($eligibility->reason)
        ];
    }
    
    // NEW: Claim check
    if ($eligibility->requiresClaim) {
        $claim = $this->claimService->getActiveClaim($coupon->id, $user->id);
        if (!$claim) {
            return [
                'valid' => false,
                'reason' => 'claim_required',
                'message' => __('coupon.claim_required')
            ];
        }
    }
    
    // Continue with existing validations
    $validation = $this->couponValidator->validate($coupon, $user, $items);
    
    if (!$validation['valid']) {
        return $validation;
    }
    
    return [
        'valid' => true,
        'coupon' => $coupon,
        'claim' => $claim ?? null,
        'assignment' => $eligibility->assignment
    ];
}
```

**app/Services/Coupon/CouponReservationService.php** (MODIFY)

Modify `reserve()` method:
```php
public function reserve(Coupon $coupon, int $userId, int $orderId, ?int $claimId = null): CouponReservation
{
    return DB::transaction(function() use ($coupon, $userId, $orderId, $claimId) {
        // Lock coupon row
        $coupon = Coupon::where('id', $coupon->id)->lockForUpdate()->first();
        
        // NEW: Validate claim if provided
        if ($claimId) {
            $claim = CouponClaim::where('id', $claimId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
                
            if (!$claim || !$claim->isActive()) {
                throw new InvalidClaimException('Claim not active');
            }
        }
        
        // Existing capacity check (unchanged)
        $activeCount = CouponReservation::where('coupon_id', $coupon->id)
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->count();
        
        if ($coupon->limiter && ($coupon->used + $activeCount) >= $coupon->limiter) {
            throw new CouponCapacityExceededException();
        }
        
        // Create reservation (with claim_id)
        return CouponReservation::create([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'order_id' => $orderId,
            'claim_id' => $claimId, // NEW
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(30)
        ]);
    });
}
```

Modify `consume()` method:
```php
public function consume(int $orderId): void
{
    DB::transaction(function() use ($orderId) {
        $reservation = CouponReservation::where('order_id', $orderId)
            ->lockForUpdate()
            ->firstOrFail();
        
        $coupon = Coupon::lockForUpdate()->find($reservation->coupon_id);
        
        // NEW: Handle claim redemption
        if ($reservation->claim_id) {
            $claim = CouponClaim::lockForUpdate()->find($reservation->claim_id);
            $claim->update([
                'status' => 'redeemed',
                'redeemed_at' => now()
            ]);
        }
        
        // NEW: Create unified redemption record
        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'user_id' => $reservation->user_id,
            'order_id' => $orderId,
            'claim_id' => $reservation->claim_id,
            'assignment_id' => $this->getAssignmentId($coupon->id, $reservation->user_id),
            'discount_amount' => $this->getDiscountAmount($orderId),
            'redeemed_at' => now()
        ]);
        
        // Existing counter increment (unchanged)
        $coupon->increment('used');
        
        if ($assignment = CouponAssignment::where([
            'coupon_id' => $coupon->id,
            'user_id' => $reservation->user_id
        ])->first()) {
            $assignment->increment('used');
        }
        
        // Delete reservation
        $reservation->delete();
        
        // Fire events
        event(new CouponRedeemed($coupon, $reservation->user_id, $orderId));
    });
}
```

**app/Services/General/OrderService.php** (MODIFY)

Modify `addItemsInOrder()` method to pass claim_id:
```php
// In checkout flow, pass claim_id to reservation:
if ($cart->coupon) {
    $claim = $this->getClaim($cart->coupon, $user->id);
    $this->couponReservationService->reserve(
        $coupon, 
        $user->id, 
        $order->id,
        $claim?->id // NEW parameter
    );
    
    // Store claim_id in order
    $order->update(['claim_id' => $claim?->id]);
}
```

Modify `recordCouponUsage()` to use new redemption model:
```php
private function recordCouponUsage(Order $order): void
{
    if (!$order->coupon || $order->coupon_consumed) {
        return;
    }
    
    $coupon = Coupon::where('code', $order->coupon)->first();
    
    if (!$coupon) {
        return;
    }
    
    // Use new consumption service
    $this->couponReservationService->consume($order->id);
    
    // Mark order as consumed
    $order->update(['coupon_consumed' => true]);
}
```

### 27.5 New Controllers

**app/Http/Controllers/Api/CouponClaimController.php** (NEW)
- `claim(ClaimCouponRequest $request): JsonResponse`
- `index(Request $request): JsonResponse` — list user's claims
- `destroy(int $claimId): JsonResponse` — release claim

**app/Http/Controllers/Api/CouponEligibilityController.php** (NEW)
- `index(Request $request): JsonResponse` — list eligible coupons

**app/Http/Controllers/Admin/CouponTargetingController.php** (NEW)
- `store(StoreCouponTargetingRequest $request, int $couponId): JsonResponse`
- `update(UpdateCouponTargetingRequest $request, int $couponId): JsonResponse`
- `show(int $couponId): JsonResponse`
- `destroy(int $couponId): JsonResponse`

**app/Http/Controllers/Admin/CouponAudienceController.php** (NEW)
- `generate(int $couponId): JsonResponse` — generate snapshot
- `index(int $couponId): JsonResponse` — list snapshot users

### 27.6 New Requests

**app/Http/Requests/ClaimCouponRequest.php** (NEW)
```php
public function rules(): array
{
    return [
        'coupon_code' => 'required|string|exists:coupons,code'
    ];
}

public function authorize(): bool
{
    $coupon = Coupon::where('code', $this->coupon_code)->first();
    return $coupon && Gate::allows('claim', $coupon);
}
```

**app/Http/Requests/StoreCouponTargetingRequest.php** (NEW)
```php
public function rules(): array
{
    return [
        'assignment_targeting_mode' => 'nullable|in:assignment_only,targeting_only,or,and',
        'audience_mode' => 'required|in:dynamic,snapshot',
        'notify_on_eligibility' => 'boolean',
        'rules' => [
            'required',
            'array',
            function($attribute, $value, $fail) {
                try {
                    app(CouponTargetingValidator::class)->validate($value);
                } catch (ValidationException $e) {
                    $fail($e->getMessage());
                }
            }
        ]
    ];
}
```

### 27.7 New Resources

**app/Http/Resources/CouponClaimResource.php** (NEW)
```php
public function toArray($request): array
{
    return [
        'id' => $this->id,
        'coupon' => new CouponResource($this->whenLoaded('coupon')),
        'claim_number' => $this->claim_number,
        'status' => $this->status,
        'claimed_at' => $this->claimed_at,
        'expires_at' => $this->expires_at,
        'hours_remaining' => $this->expires_at->diffInHours(now(), false),
        'redeemed_at' => $this->redeemed_at
    ];
}
```

**app/Http/Resources/CouponEligibilityResource.php** (NEW)
```php
public function toArray($request): array
{
    $eligibility = app(CouponEligibilityService::class)
        ->isEligible($this->resource, auth()->id());
    
    return [
        'id' => $this->id,
        'code' => $this->code,
        'name' => $this->name,
        'discount_type' => $this->discount_type,
        'discount' => $this->discount,
        'eligibility' => [
            'eligible' => $eligibility->eligible,
            'reason' => $eligibility->reason,
            'requires_claim' => $eligibility->requiresClaim,
            'claim_status' => $this->getUserClaimStatus(auth()->id()),
            'slots_available' => $this->availableClaimSlots()
        ]
    ];
}
```

### 27.8 New Events

**app/Events/CouponBecameEligible.php** (NEW)
**app/Events/ClaimSucceeded.php** (NEW)
**app/Events/ClaimExpiringSoon.php** (NEW)
**app/Events/ClaimExpired.php** (NEW)
**app/Events/CouponRedeemed.php** (NEW)

### 27.9 New Listeners

**app/Listeners/SendCouponEligibilityNotification.php** (NEW)
**app/Listeners/SendClaimSuccessNotification.php** (NEW)
**app/Listeners/SendClaimExpiryWarning.php** (NEW)

### 27.10 New Notifications

**app/Notifications/CouponNowAvailableNotification.php** (NEW)
**app/Notifications/ClaimSucceededNotification.php** (NEW)
**app/Notifications/ClaimExpiringNotification.php** (NEW)

### 27.11 New Jobs

**app/Jobs/ExpireOldClaims.php** (NEW)
```php
public function handle(): void
{
    CouponClaim::where('status', 'active')
        ->where('expires_at', '<', now())
        ->update(['status' => 'expired']);
}
```

**app/Jobs/SendClaimExpiryWarnings.php** (NEW)
```php
public function handle(): void
{
    $threshold = now()->addHours(2);
    
    $claims = CouponClaim::where('status', 'active')
        ->where('expires_at', '>', now())
        ->where('expires_at', '<=', $threshold)
        ->whereDoesntHave('expiryWarning')
        ->with('coupon', 'user')
        ->get();
    
    foreach ($claims as $claim) {
        event(new ClaimExpiringSoon($claim->coupon, $claim, $claim->user_id));
        
        ClaimExpiryWarning::create([
            'claim_id' => $claim->id,
            'sent_at' => now()
        ]);
    }
}
```

**app/Jobs/CheckEligibilityAndNotify.php** (NEW)
```php
public function handle(int $userId): void
{
    $coupons = Coupon::where('notify_on_eligibility', true)
        ->where('audience_mode', 'dynamic')
        ->where('start_date', '<=', now())
        ->where('end_date', '>=', now())
        ->get();
    
    foreach ($coupons as $coupon) {
        app(CouponEligibilityService::class)
            ->checkAndNotifyEligibility($coupon, $userId);
    }
}
```

### 27.12 New Commands

**app/Console/Commands/ExpireCouponClaims.php** (NEW)
```php
protected $signature = 'coupons:expire-claims';

public function handle(): void
{
    ExpireOldClaims::dispatchSync();
    $this->info('Expired claims processed.');
}
```

**app/Console/Commands/SendClaimExpiryWarnings.php** (NEW)
```php
protected $signature = 'coupons:send-expiry-warnings';

public function handle(): void
{
    SendClaimExpiryWarnings::dispatchSync();
    $this->info('Expiry warnings sent.');
}
```

**app/Console/Commands/GenerateCouponAudienceSnapshot.php** (NEW)
```php
protected $signature = 'coupons:generate-snapshot {coupon_id}';

public function handle(): void
{
    $coupon = Coupon::findOrFail($this->argument('coupon_id'));
    
    $count = app(CouponAudienceSnapshotService::class)->generate($coupon);
    
    $this->info("Generated snapshot for coupon {$coupon->code}: {$count} eligible users");
}
```

### 27.13 Modified Kernel Schedule

**app/Console/Kernel.php** (MODIFY)

Add to `schedule()` method:
```php
$schedule->command('coupons:expire-claims')->everyFiveMinutes();
$schedule->command('coupons:send-expiry-warnings')->everyFiveMinutes();
```

### 27.14 New Routes

**routes/api.php** (ADD)
```php
// Customer routes
Route::middleware('auth:sanctum')->group(function() {
    Route::post('/coupons/claim', [CouponClaimController::class, 'claim'])
        ->middleware('throttle:claim');
    Route::get('/me/coupons/claims', [CouponClaimController::class, 'index']);
    Route::get('/me/coupons/eligible', [CouponEligibilityController::class, 'index'])
        ->middleware('throttle:60,1');
    Route::delete('/me/coupons/claims/{id}', [CouponClaimController::class, 'destroy']);
});

// Admin routes
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function() {
    Route::post('/coupons/{id}/targeting', [CouponTargetingController::class, 'store']);
    Route::put('/coupons/{id}/targeting', [CouponTargetingController::class, 'update']);
    Route::get('/coupons/{id}/targeting', [CouponTargetingController::class, 'show']);
    Route::delete('/coupons/{id}/targeting', [CouponTargetingController::class, 'destroy']);
    
    Route::post('/coupons/{id}/generate-audience', [CouponAudienceController::class, 'generate']);
    Route::get('/coupons/{id}/audience', [CouponAudienceController::class, 'index']);
    Route::get('/coupons/{id}/claims', [CouponClaimController::class, 'adminIndex']);
});
```

### 27.15 New Policies

**app/Policies/CouponClaimPolicy.php** (NEW)
```php
public function claim(User $user, Coupon $coupon): bool
{
    if (!$coupon->status || $coupon->start_date > now() || $coupon->end_date < now()) {
        return false;
    }
    
    $eligibility = app(CouponEligibilityService::class)->isEligible($coupon, $user->id);
    
    return $eligibility->eligible && $eligibility->requiresClaim;
}
```

**app/Policies/CouponTargetingPolicy.php** (NEW)
```php
public function manage(User $user, Coupon $coupon): bool
{
    return $user->hasPermission('manage_coupons');
}
```

### 27.16 New Translations

**lang/en/coupon.php** (ADD)
```php
'claim_required' => 'You must claim this coupon before using it.',
'not_eligible' => 'You are not eligible for this coupon.',
'claim_unavailable' => 'All claim slots have been taken.',
'claim_succeeded' => 'Coupon claimed successfully.',
'claim_expired' => 'Your claim has expired.',
'claim_expiring_soon' => 'Your coupon claim expires in :hours hours.',
'became_eligible' => 'You are now eligible for :coupon_name!',
```

---

## 28. MIGRATION PLAN

### 28.1 Phase 1: Foundation (Week 1)

**Goals:**
- Add database schema without breaking changes
- Deploy new tables
- Add new columns to existing tables

**Tasks:**
1. Create migrations (9 files)
2. Run migrations on staging
3. Verify foreign keys work on TiDB
4. Monitor for migration errors

**Rollback Strategy:**
- Drop new tables
- Remove new columns via `down()` migrations
- Existing functionality unaffected

### 28.2 Phase 2: Core Services (Week 2)

**Goals:**
- Implement rule engine
- Implement eligibility service
- Add models and DTOs

**Tasks:**
1. Create CouponRuleEngine service
2. Create CouponTargetingValidator service
3. Create CouponEligibilityService
4. Add new models (CouponTargeting, CouponClaim, etc.)
5. Unit test all services (>100 tests)
6. Integration test with database

**Rollback Strategy:**
- Services not called by any endpoint yet
- Can be removed without impact

### 28.3 Phase 3: Claim System (Week 3)

**Goals:**
- Implement claim allocation
- Implement claim expiration
- Test concurrency

**Tasks:**
1. Create CouponClaimService
2. Add claim allocation logic with `FOR UPDATE`
3. Create ExpireOldClaims job
4. Load test claim allocation (100 concurrent users)
5. Feature tests for claim lifecycle

**Rollback Strategy:**
- Claim endpoints not deployed yet
- Claims table can remain empty

### 28.4 Phase 4: Integration (Week 4)

**Goals:**
- Integrate eligibility into existing flow
- Modify CouponOrchestrator
- Modify CouponReservationService

**Tasks:**
1. Modify CouponOrchestrator::validate()
2. Modify CouponReservationService::reserve()
3. Modify CouponReservationService::consume()
4. Add claim_id to orders and reservations
5. Regression test existing coupon flows
6. Feature test new flows

**Rollback Strategy:**
- Backward compatibility maintained
- Feature flags can disable new logic

### 28.5 Phase 5: API Endpoints (Week 5)

**Goals:**
- Deploy claim endpoints
- Deploy targeting admin endpoints
- Deploy eligibility check endpoint

**Tasks:**
1. Create controllers
2. Create requests
3. Create resources
4. Create policies
5. Add routes
6. API tests (Postman/Insomnia)
7. Front-end integration

**Rollback Strategy:**
- Remove routes
- Existing endpoints unaffected

### 28.6 Phase 6: Notifications (Week 6)

**Goals:**
- Implement eligibility notifications
- Implement claim expiry warnings

**Tasks:**
1. Create events
2. Create listeners
3. Create notifications
4. Schedule jobs
5. Test notification delivery

**Rollback Strategy:**
- Disable job scheduling
- No impact on core functionality

### 28.7 Phase 7: Snapshot Audience (Week 7)

**Goals:**
- Implement snapshot generation
- Admin UI for snapshot management

**Tasks:**
1. Create CouponAudienceSnapshotService
2. Add snapshot generation command
3. Add admin endpoint
4. Test with 10K+ users
5. Performance optimization

**Rollback Strategy:**
- Snapshot mode not required for dynamic mode
- Can be disabled via feature flag

### 28.8 Phase 8: Data Migration (Week 8)

**Goals:**
- Migrate historical data to coupon_redemptions
- Dual-write period
- Deprecate old tables

**Tasks:**
1. Write data migration script
2. Migrate coupon_usages → coupon_redemptions
3. Migrate coupon_assignment_usages → coupon_redemptions
4. Verify data integrity
5. Enable dual-write (both old + new tables)
6. Monitor for 1 week

**Rollback Strategy:**
- Keep old tables during transition
- Can revert to old tables if issues found

### 28.9 Phase 9: Production Rollout (Week 9)

**Goals:**
- Enable targeting for pilot coupons
- Monitor performance
- Collect feedback

**Tasks:**
1. Create 3-5 pilot coupons with targeting
2. Monitor claim allocation performance
3. Monitor eligibility check latency
4. Monitor notification delivery
5. Collect user feedback

**Rollback Strategy:**
- Disable targeting mode on pilot coupons
- Fall back to assignment-only mode

### 28.10 Phase 10: Full Migration (Week 10+)

**Goals:**
- Drop old tables
- Remove feature flags
- Full documentation

**Tasks:**
1. Confirm coupon_redemptions covers all use cases
2. Stop writing to coupon_usages / coupon_assignment_usages
3. Wait 30 days (safety period)
4. Drop old tables
5. Update documentation
6. Training for admin users

**Rollback Strategy:**
- Not reversible (old tables dropped)
- Must be confident before proceeding

---

## 29. ROLLOUT PLAN

### 29.1 Pre-Deployment Checklist

**Code Quality:**
- [ ] All unit tests pass (100% coverage on new services)
- [ ] All feature tests pass
- [ ] All integration tests pass
- [ ] Concurrency tests pass (100 concurrent claims)
- [ ] Code review completed
- [ ] Static analysis clean (PHPStan level 8)

**Database:**
- [ ] Migrations tested on staging
- [ ] Foreign keys verified on TiDB
- [ ] Indexes created and verified
- [ ] Data migration script tested

**Performance:**
- [ ] Eligibility check < 200ms (p95)
- [ ] Claim allocation < 100ms (p95)
- [ ] Snapshot generation tested with 10K users
- [ ] No N+1 queries detected

**Security:**
- [ ] Authorization policies reviewed
- [ ] Input validation verified
- [ ] Rate limiting configured
- [ ] Audit logging in place

**Documentation:**
- [ ] API documentation updated
- [ ] Admin user guide written
- [ ] Developer documentation complete
- [ ] Runbook for common issues

### 29.2 Deployment Strategy

**Blue-Green Deployment:**

**Blue (Current Production):**
- Existing coupon system
- No targeting, no claims

**Green (New Version):**
- New tables deployed
- New services deployed
- Feature flags OFF

**Cutover:**
1. Deploy Green with feature flags OFF
2. Verify health checks pass
3. Run smoke tests
4. Enable feature flags gradually

### 29.3 Feature Flags

**`coupon_targeting_enabled`:**
- Controls targeting rule evaluation
- Default: `false`
- Enable per coupon (not global toggle)

**`coupon_claims_enabled`:**
- Controls claim system
- Default: `false`
- Enable per coupon

**`coupon_eligibility_notifications_enabled`:**
- Controls eligibility notifications
- Default: `false`
- Enable globally

**`coupon_snapshot_mode_enabled`:**
- Controls snapshot generation
- Default: `false`
- Enable per coupon

### 29.4 Rollout Phases

**Phase 1: Dark Launch (Week 1)**
- Deploy code with all feature flags OFF
- Monitor for errors
- No user-facing changes

**Phase 2: Internal Testing (Week 2)**
- Enable for test coupons only
- Internal team tests all flows
- Bug fixes and adjustments

**Phase 3: Pilot (Week 3-4)**
- Enable for 3-5 pilot coupons
- Target: low-risk campaigns (e.g., small discount, no time pressure)
- Monitor performance metrics
- Collect feedback

**Phase 4: Limited Rollout (Week 5-6)**
- Enable for 25% of new coupons
- Monitor error rates
- Monitor support tickets

**Phase 5: Full Rollout (Week 7+)**
- Enable for all new coupons
- Existing coupons remain unchanged (backward compatible)
- Monitor continuously

### 29.5 Monitoring & Alerts

**Key Metrics:**
- Eligibility check latency (p50, p95, p99)
- Claim allocation success rate
- Claim allocation concurrency errors
- Reservation creation success rate
- Redemption success rate
- Notification delivery rate

**Alerts:**
- Eligibility check > 500ms (p95)
- Claim allocation failures > 1%
- Concurrency errors > 0.1%
- Database deadlocks detected
- Slow query detected (> 1 second)

**Dashboards:**
- Coupon system health (overall)
- Targeting rule performance (per field type)
- Claim system metrics (allocation rate, expiry rate)
- Notification delivery (sent, failed, pending)

### 29.6 Rollback Procedures

**Scenario 1: Critical Bug in Eligibility Check**
```
1. Disable feature flag: coupon_targeting_enabled = false
2. All coupons fall back to assignment-only mode
3. Fix bug
4. Re-enable gradually
```

**Scenario 2: Claim Allocation Overselling**
```
1. Disable feature flag: coupon_claims_enabled = false
2. Investigate root cause (likely concurrency issue)
3. Fix transaction logic
4. Load test before re-enabling
```

**Scenario 3: Database Performance Degradation**
```
1. Check slow query log
2. Add missing indexes if needed
3. Optimize queries
4. No rollback needed (backward compatible)
```

**Scenario 4: Complete Rollback Required**
```
1. Disable all feature flags
2. Revert to previous deployment
3. Existing coupons continue working
4. New targeting features disabled
```

### 29.7 Success Criteria

**Week 1:**
- Zero deployment errors
- All existing tests pass
- No performance regression

**Week 4:**
- 3 pilot coupons running successfully
- < 0.1% error rate
- Eligibility check < 200ms (p95)

**Week 8:**
- 50+ coupons using targeting
- 1000+ claims processed successfully
- < 0.01% concurrency errors
- Positive user feedback

**Week 12:**
- Targeting available for all new coupons
- Old tables deprecated
- Documentation complete
- Admin training complete

---

## 30. RISKS / TRADE-OFFS

### 30.1 Technical Risks

**Risk 1: Concurrency Overselling**
- **Probability:** Medium
- **Impact:** High
- **Mitigation:** Extensive concurrency testing, pessimistic locking, UNIQUE constraints
- **Contingency:** Disable claims, compensate affected users

**Risk 2: Performance Degradation**
- **Probability:** Medium
- **Impact:** Medium
- **Mitigation:** Query optimization, caching, load testing
- **Contingency:** Add indexes, optimize slow queries, increase cache TTL

**Risk 3: TiDB Incompatibility**
- **Probability:** Low
- **Impact:** High
- **Mitigation:** Test all features on TiDB staging cluster
- **Contingency:** Use MySQL-compatible workarounds

**Risk 4: Data Migration Errors**
- **Probability:** Low
- **Impact:** High
- **Mitigation:** Dual-write period, verification scripts, rollback plan
- **Contingency:** Keep old tables, revert to old system

**Risk 5: Notification Spam**
- **Probability:** Medium
- **Impact:** Low
- **Mitigation:** Rate limiting, user preferences, deduplication
- **Contingency:** Disable notifications temporarily

### 30.2 Business Risks

**Risk 1: Complex Admin UX**
- **Probability:** Medium
- **Impact:** Medium
- **Mitigation:** Admin training, clear documentation, UI/UX review
- **Contingency:** Simplify rule builder, provide templates

**Risk 2: User Confusion (Claims)**
- **Probability:** Medium
- **Impact:** Low
- **Mitigation:** Clear messaging, help text, FAQs
- **Contingency:** Improve messaging based on feedback

**Risk 3: Support Ticket Increase**
- **Probability:** Medium
- **Impact:** Medium
- **Mitigation:** Documentation, training, monitoring
- **Contingency:** Add more support resources temporarily

### 30.3 Trade-Offs

**Trade-Off 1: Complexity vs Flexibility**
- **Decision:** Full-featured targeting system with boolean logic
- **Pro:** Handles all business use cases
- **Con:** More complex to implement and maintain
- **Alternative:** Simple whitelist-based targeting (easier but limited)

**Trade-Off 2: Dual-Write Period vs Fast Migration**
- **Decision:** Dual-write to old + new tables for 1 week
- **Pro:** Safety net, easy rollback
- **Con:** Extra write load, data consistency complexity
- **Alternative:** Direct cutover (risky but faster)

**Trade-Off 3: Dynamic vs Snapshot Default**
- **Decision:** Default to dynamic mode
- **Pro:** Real-time eligibility, no admin overhead
- **Con:** More expensive queries, unpredictable audience size
- **Alternative:** Default to snapshot (predictable but requires manual regeneration)

**Trade-Off 4: Claim Expiration Duration**
- **Decision:** Default 24 hours
- **Pro:** Gives users time, reduces support tickets
- **Con:** Slots locked longer, slower turnover
- **Alternative:** Shorter expiration (6 hours) — faster turnover but more expirations

**Trade-Off 5: Grace Period for Expired Claims**
- **Decision:** Allow redemption if claim expired during checkout
- **Pro:** Better user experience, fewer payment failures
- **Con:** Slightly more complex logic
- **Alternative:** Strict expiration (simpler but worse UX)

### 30.4 Open Questions

**Question 1: User Profile Schema**
- **Status:** CRITICAL — blocks targeting implementation
- **Action Required:** Inspect user_profiles table, confirm fields exist
- **Decision Needed:** If fields missing, add them or use alternative data source

**Question 2: Lifetime Spend Refund Handling**
- **Status:** Medium priority
- **Action Required:** Confirm with business
- **Decision Needed:** Should refunded orders reduce lifetime spend?

**Question 3: Assignment + Claim Interaction**
- **Status:** Low priority
- **Action Required:** Confirm with product team
- **Decision Needed:** Should assigned users bypass claim requirement in OR mode?
- **Current Implementation:** Yes (assignment bypasses claim in OR mode)

---

## 31. FINAL RECOMMENDED ARCHITECTURE

### 31.1 Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                         CLIENT LAYER                         │
├─────────────────────────────────────────────────────────────┤
│  Web App         Mobile App         Admin Panel              │
└─────────────────────────────────────────────────────────────┘
                            │
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                         API LAYER                            │
├─────────────────────────────────────────────────────────────┤
│  CouponClaimController                                       │
│  CouponEligibilityController                                 │
│  CouponTargetingController (Admin)                           │
│  CouponAudienceController (Admin)                            │
└─────────────────────────────────────────────────────────────┘
                            │
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                      SERVICE LAYER                           │
├─────────────────────────────────────────────────────────────┤
│  CouponOrchestrator (coordinator)                            │
│    ├─ CouponEligibilityService                               │
│    │    ├─ CouponRuleEngine                                  │
│    │    ├─ CouponAssignmentRepository                        │
│    │    └─ CouponAudienceSnapshotService                     │
│    ├─ CouponClaimService                                     │
│    ├─ CouponValidator (existing)                             │
│    ├─ CouponCalculator (existing)                            │
│    └─ CouponReservationService (modified)                    │
└─────────────────────────────────────────────────────────────┘
                            │
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                       MODEL LAYER                            │
├─────────────────────────────────────────────────────────────┤
│  Coupon                    CouponClaim                        │
│  CouponAssignment          CouponRedemption                   │
│  CouponTargeting           CouponAudienceSnapshot             │
│  CouponReservation         CouponEligibilityNotification      │
└─────────────────────────────────────────────────────────────┘
                            │
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                      DATABASE LAYER                          │
├─────────────────────────────────────────────────────────────┤
│  TiDB Cloud (MySQL-compatible distributed database)          │
│    ├─ coupons                                                │
│    ├─ coupon_assignments                                     │
│    ├─ coupon_targetings                                      │
│    ├─ coupon_claims                                          │
│    ├─ coupon_redemptions                                     │
│    ├─ coupon_reservations                                    │
│    ├─ coupon_audience_snapshots                              │
│    └─ coupon_eligibility_notifications                       │
└─────────────────────────────────────────────────────────────┘
```

### 31.2 Key Architectural Principles

**1. Separation of Concerns:**
- Eligibility (Assignment + Targeting + Snapshot)
- Claim (Allocation + Expiration)
- Reservation (Checkout Lock)
- Redemption (Usage Recording)

**2. Orchestration Pattern:**
- CouponOrchestrator coordinates all sub-services
- Each service has single responsibility
- Clear dependency hierarchy

**3. Backward Compatibility:**
- New features additive only
- Feature flags control new behavior
- Existing coupons work unchanged

**4. Concurrency Safety:**
- Pessimistic locking (`FOR UPDATE`)
- UNIQUE constraints as safety net
- Atomic counter increments

**5. Performance:**
- Caching at service layer
- Pre-computed metrics (lifetime spend)
- Batch operations for snapshots

**6. Extensibility:**
- Rule engine supports new fields without schema changes
- Targeting rules stored as JSON
- Easy to add new operators

### 31.3 Technology Stack

**Backend:**
- Laravel 10
- PHP 8.2+
- TiDB Cloud (MySQL-compatible)

**Caching:**
- Redis (eligibility results, rule evaluation)

**Queue:**
- Laravel Queue (notifications, snapshot generation)

**Monitoring:**
- Laravel Telescope (development)
- New Relic / DataDog (production)
- TiDB monitoring dashboard

### 31.4 Deployment Architecture

**Application Servers:**
- Load balanced (3+ instances)
- Auto-scaling based on traffic

**Database:**
- TiDB Cloud managed cluster
- 3+ nodes for high availability
- Read replicas for analytics

**Cache:**
- Redis cluster (managed)
- 2+ nodes for redundancy

**Queue Workers:**
- Dedicated queue workers (2+ instances)
- Separate queues for high/low priority

---

## 32. IMPLEMENTATION PHASES

### 32.1 Phase 1: Foundation (Weeks 1-2)

**Deliverables:**
- 9 database migrations
- 6 new models
- 1 new DTO (EligibilityResult)
- Unit tests for models

**Acceptance Criteria:**
- Migrations run successfully on staging TiDB
- All foreign keys created
- All indexes created
- Models have proper relationships
- Unit tests pass

**Effort:** 40 hours

### 32.2 Phase 2: Core Services (Weeks 3-4)

**Deliverables:**
- CouponRuleEngine service
- CouponTargetingValidator service
- CouponEligibilityService
- 50+ unit tests

**Acceptance Criteria:**
- Rule engine evaluates all supported field types
- Boolean logic (AND/OR/NOT) works correctly
- Targeting validator catches invalid rules
- Eligibility service handles all 4 modes
- 100% test coverage on services

**Effort:** 80 hours

### 32.3 Phase 3: Claim System (Weeks 5-6)

**Deliverables:**
- CouponClaimService
- ExpireOldClaims job
- SendClaimExpiryWarnings job
- Concurrency tests

**Acceptance Criteria:**
- Claim allocation prevents overselling
- Expiration job runs successfully
- Concurrency test (100 concurrent claims) passes
- No deadlocks detected
- Feature tests for full claim lifecycle

**Effort:** 60 hours

### 32.4 Phase 4: Integration (Weeks 7-8)

**Deliverables:**
- Modified CouponOrchestrator
- Modified CouponReservationService
- Modified OrderService
- Regression tests

**Acceptance Criteria:**
- Existing coupon flows unchanged
- New flows work with claims
- All existing tests pass
- New integration tests pass
- Backward compatibility verified

**Effort:** 60 hours

### 32.5 Phase 5: API & UI (Weeks 9-10)

**Deliverables:**
- 4 new controllers
- 3 new requests
- 2 new resources
- 2 new policies
- API routes
- API tests

**Acceptance Criteria:**
- All endpoints documented
- Authorization works correctly
- Rate limiting configured
- API tests pass
- Postman collection created

**Effort:** 50 hours

### 32.6 Phase 6: Notifications (Week 11)

**Deliverables:**
- 5 new events
- 3 new listeners
- 3 new notifications
- Scheduled jobs

**Acceptance Criteria:**
- Eligibility notifications fire correctly
- Claim expiry warnings sent
- Notification preferences respected
- Queue workers process notifications

**Effort:** 30 hours

### 32.7 Phase 7: Snapshot Audience (Week 12)

**Deliverables:**
- CouponAudienceSnapshotService
- Generate snapshot command
- Admin endpoints

**Acceptance Criteria:**
- Snapshot generation completes for 10K users
- Snapshot eligibility check works
- Admin can regenerate snapshot

**Effort:** 40 hours

### 32.8 Phase 8: Data Migration (Week 13)

**Deliverables:**
- Data migration script
- Dual-write logic
- Verification script

**Acceptance Criteria:**
- Historical data migrated correctly
- No data loss
- Dual-write maintains consistency
- Can revert to old system if needed

**Effort:** 40 hours

### 32.9 Phase 9: Production Rollout (Weeks 14-16)

**Deliverables:**
- Monitoring dashboards
- Runbook
- Admin training materials
- Documentation

**Acceptance Criteria:**
- Pilot coupons running successfully
- Performance metrics within targets
- No critical bugs reported
- Admin users trained

**Effort:** 60 hours

### 32.10 Phase 10: Finalization (Week 17+)

**Deliverables:**
- Drop old tables
- Remove feature flags
- Final documentation
- Retrospective

**Acceptance Criteria:**
- Old tables dropped
- Code cleaned up
- Documentation complete
- Lessons learned documented

**Effort:** 20 hours

**TOTAL EFFORT:** ~480 hours (~12 weeks for 1 full-time developer, or ~6 weeks for 2 developers)

---

## 33. CRITICAL FINAL CHECK

### 33.1 Requirements Coverage

**Core Requirements:**
✅ Targeted coupons (rule-based eligibility)
✅ Assignment + Targeting combinations (OR/AND)
✅ Limited claims (first-N allocation)
✅ Dynamic vs Snapshot audiences
✅ Eligibility notifications
✅ Backward compatibility

**Targeting Rules:**
✅ User attributes (registration date, country, language, gender, phone, status)
✅ Order metrics (completed count, lifetime spend, AOV)
✅ Purchase history (product, category, brand)
✅ Coupon history (has used, has not used)
✅ Boolean logic (AND/OR/NOT, nested)

**Claim System:**
✅ User-owned allocation
✅ First-N slots
✅ Expiration handling
✅ Concurrency safety
✅ Grace period for checkout

**Technical Requirements:**
✅ TiDB compatibility
✅ Concurrency safety (no overselling)
✅ Performance at scale
✅ Backward compatibility
✅ Security (authorization, validation)

### 33.2 Edge Cases Handled

✅ Two users claiming last slot simultaneously
✅ Claim expires during checkout
✅ Payment fails with active claim
✅ User loses eligibility after claiming
✅ Snapshot regeneration during eligibility check
✅ Assignment + Claim interaction in OR/AND modes
✅ Refunded orders (do NOT reverse usage)
✅ Expired assignments
✅ Concurrent redemptions

### 33.3 Critical Gaps Identified

⚠️ **User Profile Schema** — country, language, gender fields not confirmed
⚠️ **Lifetime Spend Definition** — refund handling not confirmed
⚠️ **Assignment + Claim Interaction** — business confirmation needed

### 33.4 Testing Coverage

✅ Unit tests for rule engine (50+ tests)
✅ Unit tests for eligibility service (30+ tests)
✅ Feature tests for claim lifecycle (20+ tests)
✅ Concurrency tests (5+ scenarios)
✅ Integration tests (10+ end-to-end flows)
✅ Backward compatibility tests (existing test suite)

**ESTIMATED TOTAL TESTS:** ~300+

### 33.5 Performance Targets

✅ Eligibility check: < 200ms (p95)
✅ Claim allocation: < 100ms (p95)
✅ Snapshot generation (10K users): < 10 seconds
✅ Redemption: < 50ms (unchanged)

### 33.6 Security Review

✅ Authorization policies defined
✅ Input validation (targeting rules)
✅ Rate limiting configured
✅ SQL injection prevented (parameterized queries)
✅ Information disclosure prevented (generic error messages)
✅ Audit trail implemented

---

## 34. REQUIREMENT CONFLICTS / DECISIONS REQUIRED

### 34.1 User Profile Attributes (CRITICAL)

**Issue:** User targeting rules require `country`, `language`, `gender` fields, but these were not found in the `users` table during audit.

**Options:**
1. **Inspect user_profiles table** — fields may exist in related table
2. **Add columns to users table** — if fields don't exist anywhere
3. **Use alternative data** — extract from order shipping addresses
4. **Remove user attribute targeting** — only support order/purchase metrics

**Recommendation:** Inspect `user_profiles` table schema first (via database query or migration file). If fields exist, proceed with current design. If missing, recommend Option 2 (add columns).

**Blocking:** YES — cannot implement user attribute targeting without confirmed schema.

### 34.2 Lifetime Spend Refund Handling (MEDIUM)

**Issue:** Unclear whether refunded orders should reduce lifetime spend calculation.

**Current Implementation:** Refunded orders remain counted in lifetime spend.

**Options:**
1. **Count original total** (current) — simpler, matches typical loyalty programs
2. **Subtract refunds** — more accurate "net spend"

**Recommendation:** Confirm with business team. If no strong preference, keep current implementation (simpler).

**Blocking:** NO — can proceed with current implementation, change later if needed.

### 34.3 Assignment + Claim in OR Mode (LOW)

**Issue:** Should assigned users bypass claim requirement in OR mode?

**Current Implementation:** Yes — assignment bypasses claim requirement.

**Scenario:**
```
Coupon: max_claims = 10, mode = OR
User A: Has assignment
User B: Matches targeting only

Current: User A can use without claiming, User B must claim
Alternative: Both must claim
```

**Recommendation:** Keep current implementation (assignment bypasses claim). Rationale: Assignment is explicit admin action, should have priority.

**Blocking:** NO — current implementation is reasonable, can adjust if business disagrees.

### 34.4 Claim Expiration Grace Period (LOW)

**Issue:** Should redemption proceed if claim expired during checkout/payment?

**Current Implementation:** Yes — grace period for in-flight payments.

**Trade-Off:**
- Pro: Better UX, fewer payment failures
- Con: Slightly more complex logic

**Recommendation:** Keep grace period. User should not be penalized for slow payment processing.

**Blocking:** NO — current implementation is reasonable.

---

## APPENDIX A: GLOSSARY

**Assignment:** Admin explicitly grants coupon to specific user with quota.

**Targeting:** Rule-based eligibility criteria.

**Eligibility:** Computed state: can user use/claim this coupon?

**Claim:** User-owned allocation of one limited campaign slot.

**Reservation:** Temporary 30-min payment window lock.

**Redemption:** Actual usage in completed order (permanent).

**Dynamic Audience:** Eligibility evaluated in real-time.

**Snapshot Audience:** Frozen list of eligible users.

**Rule Engine:** Service that evaluates targeting rules.

**Orchestrator:** Service that coordinates multiple sub-services.

**Grace Period:** Allow redemption if claim expired during checkout.

---

## APPENDIX B: REFERENCE LINKS

**Laravel Documentation:**
- Eloquent Relationships: https://laravel.com/docs/10.x/eloquent-relationships
- Database Transactions: https://laravel.com/docs/10.x/database#database-transactions
- Queue System: https://laravel.com/docs/10.x/queues
- Events & Listeners: https://laravel.com/docs/10.x/events

**TiDB Documentation:**
- TiDB vs MySQL: https://docs.pingcap.com/tidb/stable/mysql-compatibility
- Pessimistic Locking: https://docs.pingcap.com/tidb/stable/pessimistic-transaction
- JSON Functions: https://docs.pingcap.com/tidb/stable/json-functions

**Testing:**
- Pest PHP: https://pestphp.com/
- Laravel Testing: https://laravel.com/docs/10.x/testing

---

## DOCUMENT METADATA

**Version:** 1.0  
**Status:** Implementation-Ready  
**Last Updated:** 2026-09-08  
**Author:** AI Assistant (Claude Opus 5)  
**Review Status:** Pending Human Review  
**Estimated Implementation Time:** 12 weeks (1 FTE) or 6 weeks (2 FTE)  
**Total Document Length:** 2726 lines

---

## NEXT STEPS

1. **Resolve Critical Gap:** Inspect user_profiles table schema (country, language, gender fields)
2. **Stakeholder Review:** Present plan to product and engineering teams
3. **Business Confirmation:** Confirm lifetime spend refund handling policy
4. **Technical Review:** Review by senior engineer or architect
5. **Approval:** Get sign-off to proceed with implementation
6. **Sprint Planning:** Break phases into 2-week sprints
7. **Begin Phase 1:** Start with database migrations

**END OF DOCUMENT**
