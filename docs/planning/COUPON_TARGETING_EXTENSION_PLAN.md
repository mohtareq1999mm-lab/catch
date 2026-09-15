# COUPON TARGETING, ASSIGNMENT, ELIGIBILITY & CLAIM SYSTEM
## EXTENSION PLAN — Phase 2

---

## CRITICAL DISCOVERY

**The core targeting and claim infrastructure already exists.**

This is NOT a greenfield implementation.

This is an **EXTENSION** of an existing, partially-deployed system.

---

# EXECUTIVE SUMMARY

## What Already Exists (Phase 1 — Deployed)

The Catch application already has:

### Database Schema
- ✅ `coupons` table (core coupon entity)
- ✅ `coupon_usages` table (public coupon redemption tracking, unique per user)
- ✅ `coupon_assignments` table (whitelist assignments with per-user quotas)
- ✅ `coupon_assignment_usages` table (assigned coupon redemption tracking)
- ✅ `coupon_reservations` table (30-minute payment-window capacity locks)
- ✅ `coupon_targetings` table (targeting configuration: mode, require_claim, max_claims, rule_tree)
- ✅ `coupon_claims` table (lifetime claim tracking, unique per user, with eligibility snapshot)
- ✅ `customer_metrics` table (materialized order/spend/coupon aggregates)

### Models
- ✅ `Coupon` with relationships to assignments, targeting, claims, usages
- ✅ `CouponAssignment` with per-user quota tracking
- ✅ `CouponUsage` for public coupon redemption
- ✅ `CouponAssignmentUsage` for assigned coupon redemption
- ✅ `CouponReservation` for payment-window locks
- ✅ `CouponTargeting` with mode (assignment/dynamic) and rule_tree (JSON)
- ✅ `CouponClaim` with eligibility_snapshot (JSON)
- ✅ `CustomerMetrics` with completed_orders, total_qualifying_order_value, first/last order dates, coupons_used

### Services
- ✅ `CouponOrchestrator` — validates coupons, integrates claim requirements
- ✅ `CouponValidator` — validates status, dates, usage limits, product restrictions
- ✅ `CouponAssignmentValidator` — validates assignment existence, expiry, quotas
- ✅ `CouponReservationService` — reserves/consumes/releases payment-window capacity
- ✅ `CouponCalculator` — calculates discount amounts
- ✅ `CouponClaimService` — handles lifetime claim acquisition with concurrency safety
- ✅ `EligibilityEngine` — evaluates rule trees against customer metrics
- ✅ `CustomerMetricsService` — rebuilds customer aggregates from orders
- ✅ `OrderService::recordCouponUsage()` — records redemption on payment success

### Eligibility Rules (13 Implemented)
- ✅ `min_completed_orders` / `max_completed_orders`
- ✅ `min_total_spend` / `max_total_spend`
- ✅ `first_order_after` / `first_order_before`
- ✅ `last_order_after` / `last_order_before`
- ✅ `min_coupons_used` / `max_coupons_used`
- ✅ `not_claimed` / `claimed`
- ✅ `has_assignment`

### API
- ✅ `POST /api/v1/coupons/apply` — apply coupon to cart (existing, compatible)
- ✅ `POST /api/v1/general/coupons/{id}/claim` — claim coupon (with eligibility check)

### Flows
- ✅ **Public Coupon Flow:** apply → validate → reserve → pay → redeem (CouponUsage)
- ✅ **Assigned Coupon Flow:** apply → validate assignment → reserve → pay → redeem (CouponAssignmentUsage)
- ✅ **Claim-Required Flow:** claim → validate eligibility → create CouponClaim → apply → reserve → pay → redeem

### Concurrency Safety
- ✅ CouponReservation: FOR UPDATE locks on coupon + reservation count
- ✅ CouponClaim: FOR UPDATE lock on CouponTargeting parent + UNIQUE(coupon_id, user_id)
- ✅ Redemption: FOR UPDATE locks on coupon, assignment, idempotency checks

### Rule Evaluation
- ✅ AND/OR operators in rule tree
- ✅ Nested rule groups
- ✅ Fail-closed security (unknown rule types rejected)
- ✅ Metrics cached in CustomerMetrics table

---

## What's Missing (Phase 2 — This Plan)

### 1. User Demographic Rules (NOT IMPLEMENTED)

**Gap:** EligibilityEngine does not support user attribute rules.

**Required Rules:**
- Registration date (before/after/between)
- User IDs (IN list)
- Country (IN list)
- Language (IN list)
- Gender (IN list)
- Phone (pattern match)
- Status (active/inactive)

**Current User Model Fields:**
```php
// Already exists in User model:
- id
- email
- name
- phone_number
- created_at (registration date)
- is_active
- type (UserType enum: CUSTOMER, ADMIN, VENDOR, etc.)
```

**Missing User Fields:**
- `country_id` (users do not have a country field)
- `language` (users do not have a language preference field)
- `gender` (users do not have a gender field)

**Decision Required:**
> Should we add demographic fields to the `users` table, or should we derive them from other sources (e.g., user's primary address, browser locale)?

---

### 2. Purchase History Rules (NOT IMPLEMENTED)

**Gap:** EligibilityEngine does not support purchase history queries.

**Required Rules:**
- Has purchased product X
- Has purchased any product in category Y
- Has purchased any product from brand Z

**Complexity:**
- Requires joining: `orders` → `order_items` → `products` → `category_product` / `brand_product`
- Must filter by completed orders only
- Potentially expensive queries

**N+1 Risk:**
If evaluated naively, could result in one query per rule per user.

**Mitigation Strategy Required:**
- Preload purchase history into CustomerMetrics?
- Use query-based audience compilation?
- Accept runtime cost for dynamic evaluation?

---

### 3. Average Order Value (AOV) (NOT IMPLEMENTED)

**Gap:** CustomerMetrics does not store AOV.

**Business Definition:**
```
AOV = total_qualifying_order_value / completed_orders
```

**Decision:**
- Store as computed column in `customer_metrics`?
- Calculate on-the-fly during eligibility evaluation?
- What happens when `completed_orders = 0`?

---

### 4. Assignment AND/OR Targeting Modes (NOT IMPLEMENTED)

**Gap:** CouponTargeting has `mode` enum: `assignment` | `dynamic`.

This is **mutually exclusive**.

**Required:** Support combined modes:
- Assignment ONLY
- Targeting ONLY
- Assignment AND Targeting (both must pass)
- Assignment OR Targeting (either passes)

**Current Architecture:**
```php
if ($targeting->mode === 'assignment') {
    return $this->evaluateAssignmentMode($coupon, $user);
}
if ($targeting->mode === 'dynamic') {
    return $this->evaluateDynamicMode($coupon, $user, $targeting->rule_tree);
}
```

**Proposed Architecture:**
```php
// Option A: Change mode to support combined values
mode ENUM('assignment', 'dynamic', 'assignment_and_dynamic', 'assignment_or_dynamic')

// Option B: Add separate boolean flags
has_assignments BOOLEAN
has_dynamic_rules BOOLEAN
assignment_relationship ENUM('AND', 'OR')

// Option C: Treat assignment as a special rule type in rule_tree
// This is the cleanest but requires refactoring existing assignment logic
```

**Recommendation:** Option C (treat assignment as rule type) is architecturally cleanest but breaks backward compatibility unless carefully migrated.

---

### 5. Snapshot Audience (NOT IMPLEMENTED)

**Gap:** No snapshot generation mechanism exists.

**Current Behavior:**
- `dynamic` mode evaluates rules at claim/apply time
- No way to "freeze" an audience

**Required:**
- Admin can generate a snapshot of eligible users
- Snapshot is stored as a materialized list
- Changes to rules after snapshot do NOT affect snapshot members
- Users who become eligible after snapshot do NOT automatically enter

**Architecture Options:**

#### Option A: New `coupon_audience_snapshot` Table
```sql
CREATE TABLE coupon_audience_snapshots (
    id BIGINT PRIMARY KEY,
    coupon_id BIGINT,
    user_id BIGINT,
    snapshotted_at TIMESTAMP,
    eligibility_snapshot JSON,
    UNIQUE(coupon_id, user_id),
    INDEX(coupon_id)
);
```

Then change `CouponTargeting`:
```sql
ALTER TABLE coupon_targetings 
ADD COLUMN audience_mode ENUM('dynamic', 'snapshot') DEFAULT 'dynamic';
```

#### Option B: Reuse `coupon_claims` as Snapshot
- Mark claims as "snapshot_claim" vs "user_claim"
- Problem: Claims have `claimed_at` timestamp semantics
- Problem: Snapshot does NOT mean the user actually claimed

**Recommendation:** Option A (separate snapshot table) is clearer.

---

### 6. NOT Operator in Rule Tree (PARTIALLY IMPLEMENTED)

**Gap:** Rule tree supports AND/OR but NOT NOT.

**Current Schema:**
```json
{
    "operator": "AND",
    "rules": [
        {"type": "min_completed_orders", "value": 5},
        {"type": "min_total_spend", "value": 10000}
    ]
}
```

**Required:**
```json
{
    "operator": "AND",
    "rules": [
        {"type": "min_completed_orders", "value": 5},
        {
            "operator": "NOT",
            "rule": {"type": "claimed"}
        }
    ]
}
```

**Implementation:** Add NOT handling to `EligibilityEngine::evaluateDynamicMode()`.

---

### 7. Dynamic Eligibility Notifications (NOT IMPLEMENTED)

**Gap:** No mechanism to detect eligibility transitions.

**Business Requirement:**
> When a user transitions from NOT_ELIGIBLE → ELIGIBLE, send a notification.

**Challenges:**
- How to detect the transition?
- How to avoid duplicate notifications?
- How to avoid scanning all users continuously?

**Proposed Architecture:**

#### Trigger Points (Event-Driven)
- OrderStatusChanged → payment success → rebuild CustomerMetrics → check coupons
- CouponCreated → check all users? (too expensive)
- CouponTargeting updated → check existing users? (too expensive)

#### Idempotency
- New table: `coupon_eligibility_notifications`
```sql
CREATE TABLE coupon_eligibility_notifications (
    id BIGINT PRIMARY KEY,
    coupon_id BIGINT,
    user_id BIGINT,
    notified_at TIMESTAMP,
    UNIQUE(coupon_id, user_id)
);
```

#### Flow
1. User completes order
2. CustomerMetrics rebuilt
3. For each "dynamic targeting" coupon:
   - Evaluate eligibility
   - If eligible AND not previously notified:
     - Send notification
     - Record in `coupon_eligibility_notifications`

**Performance Concern:**
If there are 100 dynamic-targeting coupons, this adds 100 eligibility evaluations per order completion.

**Mitigation:**
- Only check coupons with `require_notification = true`
- Background job instead of inline
- Rate limit notifications per user

---

### 8. Audience Preview (NOT IMPLEMENTED)

**Gap:** No preview endpoint exists.

**Business Requirement (Clarification Needed):**
> The requirements say "Do NOT implement preview unless needed."

**Decision:** SKIP for Phase 2 unless explicitly requested.

---

## Summary: What Phase 2 Adds

| Feature | Status | Effort | Priority |
|---------|--------|--------|----------|
| User demographic rules | Missing | Medium | HIGH |
| Registration date rules | Missing | Low | HIGH |
| Purchase history rules | Missing | High | HIGH |
| AOV calculation | Missing | Low | MEDIUM |
| Assignment AND/OR modes | Missing | Medium | HIGH |
| Snapshot audience | Missing | High | HIGH |
| NOT operator | Partial | Low | MEDIUM |
| Dynamic notifications | Missing | High | LOW |
| Preview endpoint | Missing | Low | DEFER |

---

# CURRENT COUPON ARCHITECTURE (PHASE 1)

## Data Model

```
Coupon (1)
  ├─ hasMany CouponAssignment (N)
  │    └─ hasMany CouponAssignmentUsage (N)
  ├─ hasMany CouponUsage (N)
  ├─ hasMany CouponClaim (N)
  ├─ hasOne CouponTargeting (1)
  └─ belongsToMany Product (N-N via coupon_product)

CouponTargeting
  - mode: ENUM('assignment', 'dynamic')
  - require_claim: BOOLEAN
  - max_claims: INT (nullable)
  - rule_tree: JSON

CouponClaim
  - coupon_id, user_id (UNIQUE)
  - claimed_at
  - eligibility_snapshot: JSON

CouponReservation
  - coupon_id, user_id, order_id (UNIQUE order_id)
  - expires_at (30 minutes)

CustomerMetrics
  - user_id (UNIQUE)
  - completed_orders
  - total_qualifying_order_value
  - first_order_at, last_order_at
  - coupons_used
```

## Current Flows

### Flow 1: Public Coupon (No Assignment, No Targeting)
```
Customer applies code
    ↓
CouponValidator::validate()
    - status active?
    - dates valid?
    - limiter not exceeded?
    - user has not used (CouponUsage)?
    ↓
Valid → add to cart
    ↓
Checkout → Order created
    ↓
CouponReservationService::reserve()
    - Lock coupon FOR UPDATE
    - Count active reservations
    - Check capacity
    - Create reservation (expires 30min)
    ↓
Payment gateway
    ↓
Payment success
    ↓
OrderService::recordCouponUsage()
    - CouponUsage::firstOrCreate(coupon_id, user_id)
    - Increment coupon.used
    - Consume reservation
    - Mark order.coupon_consumed = true
```

### Flow 2: Assigned Coupon (Has Assignments, No Dynamic Targeting)
```
Customer applies code
    ↓
CouponOrchestrator::validate()
    ↓
CouponAssignmentValidator::validate()
    - Assignment exists for user?
    - Assignment not expired?
    - Assignment quota not exceeded?
    ↓
CouponValidator::validate(coupon, null, items)
    - Does NOT check user-level usage (assignment handles it)
    - Checks status, dates, global limiter, product restrictions
    ↓
Valid → add to cart
    ↓
Checkout → Order created
    ↓
Coupon ReservationService::reserve()
    ↓
Payment success
    ↓
OrderService::recordCouponUsage()
    - CouponAssignmentUsage::create(coupon_assignment_id, order_id)
    - Increment coupon.used
    - Increment assignment.used
    - Consume reservation
    - Fire AssignedCouponConsumed event
```

### Flow 3: Claim-Required Dynamic Targeting
```
Customer views coupon
    ↓
POST /api/v1/general/coupons/{id}/claim
    ↓
CouponClaimService::claim()
    - Lock CouponTargeting FOR UPDATE
    - Check targeting.require_claim = true
    - Check user has not already claimed
    - Check max_claims not exceeded
    - EligibilityEngine::evaluate()
        - Load CustomerMetrics
        - Evaluate rule tree
    - Create CouponClaim(coupon_id, user_id, eligibility_snapshot)
    ↓
Claim successful
    ↓
Customer applies code
    ↓
CouponOrchestrator::validate()
    - Check claim required?
    - Check user has claim?
    ↓
CouponValidator::validate()
    ↓
Valid → add to cart
    ↓
(Rest of flow same as Flow 1)
```

## Current Rule Evaluation (Phase 1)

### EligibilityEngine::evaluate()

```php
1. Load CouponTargeting
2. If mode = 'assignment':
     Check CouponAssignment exists
3. If mode = 'dynamic':
     Load CustomerMetrics
     Evaluate rule_tree
4. Rule tree structure:
     {
         "operator": "AND" | "OR",
         "rules": [
             {"type": "min_completed_orders", "value": 5},
             {
                 "operator": "AND",
                 "rules": [...]
             }
         ]
     }
5. For each rule:
     Dispatch to evalMinCompletedOrders() / evalMinTotalSpend() / etc.
6. Return EligibilityResult(isEligible, passedRules, failedRules, evaluatedMetrics)
```

### Supported Rule Types (Phase 1)

```php
enum EligibilityRuleType {
    MIN_COMPLETED_ORDERS
    MAX_COMPLETED_ORDERS
    MIN_TOTAL_SPEND
    MAX_TOTAL_SPEND
    FIRST_ORDER_AFTER
    FIRST_ORDER_BEFORE
    LAST_ORDER_AFTER
    LAST_ORDER_BEFORE
    MIN_COUPONS_USED
    MAX_COUPONS_USED
    NOT_CLAIMED
    CLAIMED
    HAS_ASSIGNMENT
}
```

---

# PHASE 2 REQUIREMENTS GAP ANALYSIS

## R1. User Demographic Rules

### R1.1 Registration Date

**Requirement:**
> Support registration date rules: before, after, between, on.

**Current User Model:**
- `created_at` (timestamp) — user registration timestamp

**Proposed Rules:**
- `registration_after`: User registered after date X
- `registration_before`: User registered before date X
- `registration_between`: User registered between date X and Y

**Timezone Handling:**
- Store dates as DATE (not DATETIME) in rule values
- Compare using DATE(users.created_at) in user's timezone?
- Or compare in UTC?

**Decision Required:**
> What timezone should registration date comparison use?
> - Option A: UTC (simplest, no ambiguity)
> - Option B: User's timezone (requires storing user timezone)
> - Option C: Application default timezone

**Recommendation:** Option A (UTC) for Phase 2.

### R1.2 User IDs

**Requirement:**
> Support targeting specific user IDs.

**Proposed Rule:**
- `user_id_in`: User ID IN [1, 2, 3, ...]

**Implementation:**
```php
private function evalUserIdIn(User $user, array $value): array
{
    $userIds = array_map('intval', $value);
    $pass = in_array($user->getKey(), $userIds, true);
    
    return [
        'passed' => $pass,
        'type' => 'user_id_in',
        'value' => $userIds,
        'actual' => $user->getKey(),
    ];
}
```

**Performance:**
- For small lists (< 100 users): acceptable
- For large lists: consider snapshot mode instead

### R1.3 Country

**Problem:** Users table does NOT have `country_id` column.

**Options:**

#### Option A: Add `country_id` to `users` table
```sql
ALTER TABLE users 
ADD COLUMN country_id BIGINT NULL,
ADD FOREIGN KEY (country_id) REFERENCES countries(id);
```

**Pros:**
- Clean, direct
- Fast eligibility checks

**Cons:**
- Requires migration of existing users
- Where does country come from? (Primary address? First order?)

#### Option B: Derive from user's primary address
```php
$address = $user->address()->where('is_primary', true)->first();
$countryId = $address?->governorate?->country_id;
```

**Pros:**
- No schema change

**Cons:**
- Slow (join required)
- What if user has no address?
- What if address country changes?

#### Option C: Add `country_id` to `customer_metrics`
```sql
ALTER TABLE customer_metrics
ADD COLUMN country_id BIGINT NULL;
```

Then populate from first order's shipping address or primary address.

**Pros:**
- No users table change
- Materialized for fast queries

**Cons:**
- What is the "source of truth" for country?

**Decision Required:**
> How should user country be determined?

**Recommendation:** Option C (add to customer_metrics, derive from primary address or first order).

### R1.4 Language

**Problem:** Users table does NOT have `language` column.

**Options:**

#### Option A: Add `preferred_language` to `users` table
```sql
ALTER TABLE users
ADD COLUMN preferred_language VARCHAR(5) NULL;
```

#### Option B: Derive from browser locale / API calls
- Not reliable
- Changes frequently

#### Option C: Add to `customer_metrics`
```sql
ALTER TABLE customer_metrics
ADD COLUMN preferred_language VARCHAR(5) NULL;
```

**Decision Required:**
> Should language targeting be supported in Phase 2, or deferred?

**Recommendation:** DEFER until product confirms language is a required targeting dimension.

### R1.5 Gender

**Problem:** Users table does NOT have `gender` column.

**Options:**

#### Option A: Add `gender` to `users` table
```sql
ALTER TABLE users
ADD COLUMN gender ENUM('male', 'female', 'other', 'prefer_not_to_say') NULL;
```

#### Option B: Do not collect gender
- Privacy considerations
- Not all markets require/allow

**Decision Required:**
> Should gender targeting be supported?

**Recommendation:** DEFER until product confirms gender is a required targeting dimension and legal/privacy review is complete.

### R1.6 Phone

**Requirement:**
> Support phone pattern matching.

**Current User Model:**
- `phone_number` (string)

**Proposed Rule:**
- `phone_starts_with`: Phone number starts with prefix (e.g., "+20" for Egypt)

**Implementation:**
```php
private function evalPhoneStartsWith(User $user, string $value): array
{
    $phone = $user->phone_number ?? '';
    $pass = str_starts_with($phone, $value);
    
    return [
        'passed' => $pass,
        'type' => 'phone_starts_with',
        'value' => $value,
        'actual' => $phone,
    ];
}
```

### R1.7 Status

**Current User Model:**
- `is_active` (boolean)
- `deleted_at` (soft delete)

**Proposed Rules:**
- `user_is_active`: User is active (is_active = true, deleted_at = null)
- `user_is_inactive`: User is inactive

**Implementation:**
```php
private function evalUserIsActive(User $user): array
{
    $pass = $user->is_active && !$user->trashed();
    
    return [
        'passed' => $pass,
        'type' => 'user_is_active',
    ];
}
```

---

## R2. Purchase History Rules

### R2.1 Has Purchased Product

**Requirement:**
> User has purchased product X.

**Query:**
```sql
EXISTS (
    SELECT 1
    FROM orders
    JOIN order_items ON order_items.order_id = orders.id
    WHERE orders.user_id = ?
      AND orders.status = 'completed'
      AND orders.payment_status = 'payment-success'
      AND order_items.product_id = ?
)
```

**Implementation:**
```php
private function evalHasPurchasedProduct(User $user, int $productId): array
{
    $hasPurchased = Order::query()
        ->where('user_id', $user->getKey())
        ->where('status', OrderStatus::COMPLETED)
        ->where('payment_status', 'payment-success')
        ->whereHas('items', fn($q) => $q->where('product_id', $productId))
        ->exists();
    
    return [
        'passed' => $hasPurchased,
        'type' => 'has_purchased_product',
        'value' => $productId,
    ];
}
```

**Performance:**
- One query per rule evaluation per user
- Acceptable for individual user checks
- Expensive for bulk audience generation

### R2.2 Has Purchased Category

**Requirement:**
> User has purchased any product in category X.

**Query:**
```sql
EXISTS (
    SELECT 1
    FROM orders
    JOIN order_items ON order_items.order_id = orders.id
    JOIN products ON products.id = order_items.product_id
    JOIN category_product ON category_product.product_id = products.id
    WHERE orders.user_id = ?
      AND orders.status = 'completed'
      AND orders.payment_status = 'payment-success'
      AND category_product.category_id = ?
)
```

**Implementation:**
```php
private function evalHasPurchasedCategory(User $user, int $categoryId): array
{
    $hasPurchased = Order::query()
        ->where('user_id', $user->getKey())
        ->where('status', OrderStatus::COMPLETED)
        ->where('payment_status', 'payment-success')
        ->whereHas('items.product.categories', fn($q) => $q->where('categories.id', $categoryId))
        ->exists();
    
    return [
        'passed' => $hasPurchased,
        'type' => 'has_purchased_category',
        'value' => $categoryId,
    ];
}
```

### R2.3 Has Purchased Brand

**Requirement:**
> User has purchased any product from brand X.

**Query:**
```sql
EXISTS (
    SELECT 1
    FROM orders
    JOIN order_items ON order_items.order_id = orders.id
    JOIN products ON products.id = order_items.product_id
    JOIN brand_product ON brand_product.product_id = products.id
    WHERE orders.user_id = ?
      AND orders.status = 'completed'
      AND orders.payment_status = 'payment-success'
      AND brand_product.brand_id = ?
)
```

**Performance Warning:**
- These queries involve 3-4 table joins
- Must be indexed properly
- Consider caching purchase history in CustomerMetrics?

### R2.4 Performance Optimization Strategy

**Option A: Real-time Queries (Current)**
- Query orders/order_items/products on every eligibility check
- Simple, always accurate
- Slow for large order histories

**Option B: Materialized Purchase History**
```sql
CREATE TABLE customer_purchase_history (
    id BIGINT PRIMARY KEY,
    user_id BIGINT,
    product_id BIGINT,
    category_id BIGINT,
    brand_id BIGINT,
    purchased_at TIMESTAMP,
    UNIQUE(user_id, product_id),
    INDEX(user_id, category_id),
    INDEX(user_id, brand_id)
);
```

Rebuild from orders on payment success.

**Pros:**
- Fast eligibility checks
- Indexed lookups

**Cons:**
- Additional table to maintain
- Rebuild logic required

**Option C: Hybrid**
- Use Option A for runtime eligibility checks (claim/apply)
- Use Option B for snapshot generation

**Recommendation:** Option A for Phase 2, Option C if performance becomes an issue.

---

## R3. Average Order Value (AOV)

### Current CustomerMetrics

```sql
completed_orders INT
total_qualifying_order_value DECIMAL(15,2)
```

### Proposed Addition

```sql
ALTER TABLE customer_metrics
ADD COLUMN average_order_value DECIMAL(10,2) GENERATED ALWAYS AS (
    CASE
        WHEN completed_orders > 0 
        THEN total_qualifying_order_value / completed_orders
        ELSE 0
    END
) STORED;
```

**Alternative: Compute On-the-Fly**
```php
$aov = $metrics->completed_orders > 0
    ? $metrics->total_qualifying_order_value / $metrics->completed_orders
    : 0;
```

**Recommendation:** Computed column (GENERATED ALWAYS AS) is cleanest.

### Proposed Rules

- `min_average_order_value`: AOV >= X
- `max_average_order_value`: AOV <= X

---

## R4. Assignment AND/OR Targeting Modes

### Current Architecture

```php
CouponTargeting:
    mode ENUM('assignment', 'dynamic')
```

This is **mutually exclusive**.

### Required Architecture

Support four combinations:
1. Assignment ONLY (mode = 'assignment', no rule_tree)
2. Targeting ONLY (mode = 'dynamic', rule_tree present)
3. Assignment AND Targeting (both must pass)
4. Assignment OR Targeting (either passes)

### Proposed Schema Changes

#### Option A: Expand Mode Enum
```sql
ALTER TABLE coupon_targetings
MODIFY COLUMN mode ENUM('assignment', 'dynamic', 'assignment_and_dynamic', 'assignment_or_dynamic');
```

**Pros:**
- Backward compatible (existing 'assignment' and 'dynamic' still work)
- Clear intent

**Cons:**
- Four enum values for two boolean dimensions

#### Option B: Separate Flags
```sql
ALTER TABLE coupon_targetings
DROP COLUMN mode,
ADD COLUMN has_assignments BOOLEAN DEFAULT FALSE,
ADD COLUMN has_dynamic_rules BOOLEAN DEFAULT FALSE,
ADD COLUMN assignment_relationship ENUM('AND', 'OR') DEFAULT 'OR';
```

**Pros:**
- Explicit
- Extensible

**Cons:**
- Breaking change (mode column removed)
- Requires data migration

#### Option C: Treat Assignment as Rule Type
```json
{
    "operator": "OR",
    "rules": [
        {"type": "has_assignment"},
        {"type": "min_completed_orders", "value": 5}
    ]
}
```

**Pros:**
- No schema change
- Unified rule evaluation
- Clearest semantics

**Cons:**
- Requires refactoring EligibilityEngine
- Existing assignment checks must be moved into rule evaluation

### Recommendation

**Phase 2A (Quick):** Option A (expand mode enum)

**Phase 2B (Refactor):** Option C (treat assignment as rule type)

---

## R5. Snapshot Audience

### Current Behavior

- `dynamic` mode: rules evaluated at claim/apply time
- Audience is "live" — changes as users' behavior changes

### Required Behavior

- Admin generates snapshot of eligible users at time T
- Snapshot is frozen
- Users who become eligible after T do NOT enter snapshot
- Rule changes after T do NOT affect snapshot

### Proposed Schema

```sql
CREATE TABLE coupon_audience_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    coupon_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    snapshotted_at TIMESTAMP NOT NULL,
    eligibility_snapshot JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_coupon_user (coupon_id, user_id),
    KEY idx_coupon (coupon_id),
    KEY idx_user (user_id),
    
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### Proposed CouponTargeting Changes

```sql
ALTER TABLE coupon_targetings
ADD COLUMN audience_mode ENUM('dynamic', 'snapshot') DEFAULT 'dynamic',
ADD COLUMN snapshot_generated_at TIMESTAMP NULL;
```

### Snapshot Generation Flow

```
Admin clicks "Generate Snapshot"
    ↓
POST /api/v1/admin/coupons/{id}/targeting/snapshot/generate
    ↓
Job: GenerateCouponAudienceSnapshot
    ↓
For each user:
        Evaluate eligibility
        If eligible:
            INSERT INTO coupon_audience_snapshots (coupon_id, user_id, snapshotted_at, eligibility_snapshot)
    ↓
Update coupon_targetings SET audience_mode = 'snapshot', snapshot_generated_at = NOW()
    ↓
Return count
```

### Eligibility Check (Snapshot Mode)

```php
if ($targeting->audience_mode === 'snapshot') {
    $inSnapshot = DB::table('coupon_audience_snapshots')
        ->where('coupon_id', $coupon->getKey())
        ->where('user_id', $user->getKey())
        ->exists();
    
    return $inSnapshot
        ? EligibilityResult::eligible(['in_snapshot'], [])
        : EligibilityResult::ineligible([], ['not_in_snapshot'], []);
}
```

### Performance Considerations

**For 1M users:**
- Generating snapshot could take minutes
- Must be queued job
- Provide progress tracking

**Optimization:**
- Batch insert (1000 rows at a time)
- Use chunk() to avoid loading all users into memory
- Consider pre-filtering users by basic criteria (e.g., is_active = true)

---

## R6. NOT Operator

### Current Rule Tree

```json
{
    "operator": "AND",
    "rules": [
        {"type": "min_completed_orders", "value": 5}
    ]
}
```

### Required Rule Tree

```json
{
    "operator": "AND",
    "rules": [
        {"type": "min_completed_orders", "value": 5},
        {
            "operator": "NOT",
            "rule": {"type": "claimed"}
        }
    ]
}
```

### Implementation

**Current Code:**
```php
private function evaluateDynamicMode(Coupon $coupon, User $user, ?array $ruleTree): EligibilityResult
{
    $operator = $ruleTree['operator'] ?? 'AND';
    $rules = $ruleTree['rules'] ?? [];
    
    foreach ($rules as $rule) {
        if (isset($rule['operator'])) {
            // Nested group
            $nestedResult = $this->evaluateDynamicMode($coupon, $user, $rule);
            // ...
        } else {
            // Leaf rule
            $ruleResult = $this->evaluateRule($rule, $metrics, $coupon, $user);
            // ...
        }
    }
}
```

**Add NOT Support:**
```php
foreach ($rules as $rule) {
    if (isset($rule['operator'])) {
        if ($rule['operator'] === 'NOT') {
            // NOT wraps a single rule
            $innerRule = $rule['rule'] ?? null;
            if (!$innerRule) {
                $failedRules[] = ['type' => 'invalid_not', 'reason' => 'NOT operator requires a rule'];
                continue;
            }
            
            // Evaluate inner rule
            if (isset($innerRule['operator'])) {
                $innerResult = $this->evaluateDynamicMode($coupon, $user, $innerRule);
            } else {
                $innerResult = $this->evaluateRule($innerRule, $metrics, $coupon, $user);
            }
            
            // Invert result
            $ruleResult = [
                'passed' => !$innerResult['passed'],
                'type' => 'not_' . $innerResult['type'],
                'original' => $innerResult,
            ];
        } else {
            // AND / OR
            $nestedResult = $this->evaluateDynamicMode($coupon, $user, $rule);
            // ...
        }
    }
}
```

**Complexity:** Low

**Risk:** Low (existing rule evaluation unchanged)

---

## R7. Dynamic Eligibility Notifications

### Requirement

> When a user transitions from NOT_ELIGIBLE → ELIGIBLE for a dynamic-targeting coupon, send a notification.

### Challenges

1. **Detection:** How to know when eligibility changes?
2. **Idempotency:** How to avoid duplicate notifications?
3. **Performance:** How to avoid checking all users for all coupons?

### Proposed Architecture

#### Trigger Points

**Event:** `OrderStatusChanged` → status = 'completed' AND payment_status = 'payment-success'

**Listener:** `CheckCouponEligibilityTransitions`

**Flow:**
```
Order completed
    ↓
Rebuild CustomerMetrics
    ↓
For each coupon WHERE targeting.mode = 'dynamic' AND targeting.notify_on_eligibility = TRUE:
        Previous eligibility = was eligible before metrics update?
        Current eligibility = is eligible after metrics update?
        
        If NOT previous AND current:
            If NOT already notified:
                Send notification
                Record in coupon_eligibility_notifications
```

#### Schema

```sql
CREATE TABLE coupon_eligibility_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    coupon_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    notified_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_coupon_user (coupon_id, user_id),
    KEY idx_coupon (coupon_id),
    
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

#### CouponTargeting Changes

```sql
ALTER TABLE coupon_targetings
ADD COLUMN notify_on_eligibility BOOLEAN DEFAULT FALSE;
```

#### Performance Optimization

**Problem:** If there are 100 dynamic coupons, we evaluate 100 eligibility checks per order completion.

**Mitigation:**
1. Only check coupons WHERE `notify_on_eligibility = TRUE`
2. Queue the check as a background job (non-blocking)
3. Rate limit: max N notifications per user per day

**Alternative:** Batch notification checks (e.g., daily cron job)

**Recommendation:** Event-driven (per order completion) for Phase 2, with `notify_on_eligibility` flag to limit scope.

---

# FINAL ARCHITECTURE (PHASE 2)

## Extended Rule Types

### Phase 1 (Existing — 13 rules)
- ✅ `min_completed_orders`
- ✅ `max_completed_orders`
- ✅ `min_total_spend`
- ✅ `max_total_spend`
- ✅ `first_order_after`
- ✅ `first_order_before`
- ✅ `last_order_after`
- ✅ `last_order_before`
- ✅ `min_coupons_used`
- ✅ `max_coupons_used`
- ✅ `not_claimed`
- ✅ `claimed`
- ✅ `has_assignment`

### Phase 2 (New — 11+ rules)
- 🆕 `registration_after`
- 🆕 `registration_before`
- 🆕 `registration_between`
- 🆕 `user_id_in`
- 🆕 `phone_starts_with`
- 🆕 `user_is_active`
- 🆕 `min_average_order_value`
- 🆕 `max_average_order_value`
- 🆕 `has_purchased_product`
- 🆕 `has_purchased_category`
- 🆕 `has_purchased_brand`

### Phase 2 (Optional — Deferred)
- ⏸️ `country_in` (requires user country field)
- ⏸️ `language_in` (requires user language field)
- ⏸️ `gender_in` (requires user gender field + legal review)

---

## Extended Database Schema

### customer_metrics (Changes)

```sql
ALTER TABLE customer_metrics
ADD COLUMN average_order_value DECIMAL(10,2) GENERATED ALWAYS AS (
    CASE
        WHEN completed_orders > 0 
        THEN total_qualifying_order_value / completed_orders
        ELSE 0
    END
) STORED,
ADD COLUMN country_id BIGINT NULL,
ADD COLUMN preferred_language VARCHAR(5) NULL,
ADD INDEX idx_country (country_id),
ADD INDEX idx_average_order_value (average_order_value);
```

### coupon_targetings (Changes)

```sql
ALTER TABLE coupon_targetings
MODIFY COLUMN mode ENUM(
    'assignment',
    'dynamic',
    'assignment_and_dynamic',
    'assignment_or_dynamic'
) DEFAULT 'assignment',
ADD COLUMN audience_mode ENUM('dynamic', 'snapshot') DEFAULT 'dynamic',
ADD COLUMN snapshot_generated_at TIMESTAMP NULL,
ADD COLUMN notify_on_eligibility BOOLEAN DEFAULT FALSE;
```

### coupon_audience_snapshots (New)

```sql
CREATE TABLE coupon_audience_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    coupon_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    snapshotted_at TIMESTAMP NOT NULL,
    eligibility_snapshot JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_coupon_user (coupon_id, user_id),
    KEY idx_coupon (coupon_id),
    KEY idx_user (user_id),
    KEY idx_snapshotted_at (snapshotted_at),
    
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### coupon_eligibility_notifications (New)

```sql
CREATE TABLE coupon_eligibility_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    coupon_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    notified_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_coupon_user (coupon_id, user_id),
    KEY idx_coupon (coupon_id),
    KEY idx_notified_at (notified_at),
    
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

## Extended Services

### EligibilityEngine (Additions)

**New Methods:**
```php
// Registration date
private function evalRegistrationAfter(User $user, string $value): array
private function evalRegistrationBefore(User $user, string $value): array
private function evalRegistrationBetween(User $user, array $value): array

// User attributes
private function evalUserIdIn(User $user, array $value): array
private function evalPhoneStartsWith(User $user, string $value): array
private function evalUserIsActive(User $user): array

// AOV
private function evalMinAverageOrderValue(CustomerMetrics $metrics, float $value): array
private function evalMaxAverageOrderValue(CustomerMetrics $metrics, float $value): array

// Purchase history
private function evalHasPurchasedProduct(User $user, int $value): array
private function evalHasPurchasedCategory(User $user, int $value): array
private function evalHasPurchasedBrand(User $user, int $value): array
```

**NOT Operator Support:**
```php
// In evaluateDynamicMode():
if ($rule['operator'] === 'NOT') {
    // Evaluate inner rule and invert result
}
```

### CouponAudienceSnapshotService (New)

```php
class CouponAudienceSnapshotService
{
    public function generate(Coupon $coupon): array
    {
        // 1. Validate targeting mode = 'dynamic'
        // 2. Clear existing snapshot
        // 3. Chunk through users
        // 4. Evaluate eligibility for each
        // 5. Insert into coupon_audience_snapshots
        // 6. Update coupon_targetings.audience_mode = 'snapshot'
        // 7. Return count
    }
    
    public function regenerate(Coupon $coupon): array
    {
        // Same as generate, but keeps existing snapshot_generated_at
    }
    
    public function clear(Coupon $coupon): void
    {
        // Delete snapshot
        // Update coupon_targetings.audience_mode = 'dynamic'
    }
}
```

### CouponEligibilityNotificationService (New)

```php
class CouponEligibilityNotificationService
{
    public function checkAndNotify(User $user): void
    {
        // 1. Get all coupons WHERE targeting.mode = 'dynamic' AND targeting.notify_on_eligibility = TRUE
        // 2. For each coupon:
        //      - Evaluate eligibility
        //      - Check if already notified
        //      - If newly eligible and not notified:
        //          - Send notification
        //          - Record in coupon_eligibility_notifications
    }
}
```

---

## Extended API

### Admin Endpoints (New)

```
POST   /api/v1/admin/coupons/{id}/targeting/snapshot/generate
POST   /api/v1/admin/coupons/{id}/targeting/snapshot/regenerate
DELETE /api/v1/admin/coupons/{id}/targeting/snapshot

GET    /api/v1/admin/coupons/{id}/audience/count
```

### Customer Endpoints (No Changes)

Existing endpoints remain unchanged:
```
POST /api/v1/coupons/apply
POST /api/v1/general/coupons/{id}/claim
```

---

# IMPLEMENTATION PHASES

## Phase 2.1: User Attribute Rules (Week 1-2)

### Goal
Add registration date, user ID, phone, status rules.

### Changes

#### Migrations
```
2026_XX_XX_000001_add_user_targeting_support_to_eligibility_rules.php
    - No schema changes (uses existing User fields)
```

#### Enums
```php
// app/Enums/EligibilityRuleType.php
case REGISTRATION_AFTER = 'registration_after';
case REGISTRATION_BEFORE = 'registration_before';
case REGISTRATION_BETWEEN = 'registration_between';
case USER_ID_IN = 'user_id_in';
case PHONE_STARTS_WITH = 'phone_starts_with';
case USER_IS_ACTIVE = 'user_is_active';
```

#### Services
```php
// app/Services/Coupon/Eligibility/EligibilityEngine.php
+ private function evalRegistrationAfter(User $user, string $value): array
+ private function evalRegistrationBefore(User $user, string $value): array
+ private function evalRegistrationBetween(User $user, array $value): array
+ private function evalUserIdIn(User $user, array $value): array
+ private function evalPhoneStartsWith(User $user, string $value): array
+ private function evalUserIsActive(User $user): array
```

#### Tests
```php
tests/Feature/Coupon/EligibilityEngine/RegistrationRulesTest.php
tests/Feature/Coupon/EligibilityEngine/UserAttributeRulesTest.php
```

### Acceptance Criteria
- [ ] Can create targeting rule: `registration_after: 2026-01-01`
- [ ] Can create targeting rule: `user_id_in: [1, 2, 3]`
- [ ] Can create targeting rule: `phone_starts_with: "+20"`
- [ ] Can create targeting rule: `user_is_active`
- [ ] All rules correctly evaluate in EligibilityEngine
- [ ] Tests pass

---

## Phase 2.2: AOV Rules (Week 2)

### Goal
Add average_order_value column and min/max AOV rules.

### Changes

#### Migrations
```
2026_XX_XX_000002_add_average_order_value_to_customer_metrics.php

ALTER TABLE customer_metrics
ADD COLUMN average_order_value DECIMAL(10,2) GENERATED ALWAYS AS (
    CASE
        WHEN completed_orders > 0 
        THEN total_qualifying_order_value / completed_orders
        ELSE 0
    END
) STORED,
ADD INDEX idx_average_order_value (average_order_value);
```

#### Enums
```php
case MIN_AVERAGE_ORDER_VALUE = 'min_average_order_value';
case MAX_AVERAGE_ORDER_VALUE = 'max_average_order_value';
```

#### Services
```php
// app/Services/Coupon/Eligibility/EligibilityEngine.php
+ private function evalMinAverageOrderValue(CustomerMetrics $metrics, float $value): array
+ private function evalMaxAverageOrderValue(CustomerMetrics $metrics, float $value): array
```

#### Tests
```php
tests/Feature/Coupon/EligibilityEngine/AovRulesTest.php
```

### Acceptance Criteria
- [ ] customer_metrics.average_order_value computed correctly
- [ ] Can create targeting rule: `min_average_order_value: 500`
- [ ] Can create targeting rule: `max_average_order_value: 2000`
- [ ] Tests pass

---

## Phase 2.3: Purchase History Rules (Week 3-4)

### Goal
Add product/category/brand purchase history rules.

### Changes

#### Enums
```php
case HAS_PURCHASED_PRODUCT = 'has_purchased_product';
case HAS_PURCHASED_CATEGORY = 'has_purchased_category';
case HAS_PURCHASED_BRAND = 'has_purchased_brand';
```

#### Services
```php
// app/Services/Coupon/Eligibility/EligibilityEngine.php
+ private function evalHasPurchasedProduct(User $user, int $value): array
+ private function evalHasPurchasedCategory(User $user, int $value): array
+ private function evalHasPurchasedBrand(User $user, int $value): array
```

#### Indexes (Performance)
```
2026_XX_XX_000003_add_purchase_history_indexes.php

// Ensure these indexes exist:
ALTER TABLE order_items ADD INDEX idx_product_id (product_id);
ALTER TABLE category_product ADD INDEX idx_category_product (category_id, product_id);
ALTER TABLE brand_product ADD INDEX idx_brand_product (brand_id, product_id);
```

#### Tests
```php
tests/Feature/Coupon/EligibilityEngine/PurchaseHistoryRulesTest.php
```

### Acceptance Criteria
- [ ] Can create targeting rule: `has_purchased_product: 123`
- [ ] Can create targeting rule: `has_purchased_category: 45`
- [ ] Can create targeting rule: `has_purchased_brand: 67`
- [ ] Rules correctly query orders/order_items/products
- [ ] Only completed+paid orders are considered
- [ ] Tests pass
- [ ] EXPLAIN shows indexed queries

---

## Phase 2.4: Assignment AND/OR Modes (Week 4-5)

### Goal
Support combined assignment + targeting modes.

### Changes

#### Migrations
```
2026_XX_XX_000004_extend_coupon_targeting_mode.php

ALTER TABLE coupon_targetings
MODIFY COLUMN mode ENUM(
    'assignment',
    'dynamic',
    'assignment_and_dynamic',
    'assignment_or_dynamic'
) DEFAULT 'assignment';
```

#### Services
```php
// app/Services/Coupon/Eligibility/EligibilityEngine.php
public function evaluate(Coupon $coupon, User $user): EligibilityResult
{
    $targeting = $coupon->targeting;
    
    if (!$targeting) {
        return EligibilityResult::eligible(['no_targeting'], []);
    }
    
    match ($targeting->mode) {
        'assignment' => $this->evaluateAssignmentMode($coupon, $user),
        'dynamic' => $this->evaluateDynamicMode($coupon, $user, $targeting->rule_tree),
        'assignment_and_dynamic' => $this->evaluateCombinedMode($coupon, $user, 'AND'),
        'assignment_or_dynamic' => $this->evaluateCombinedMode($coupon, $user, 'OR'),
        default => EligibilityResult::ineligible([], ['unknown_mode'], []),
    };
}

+ private function evaluateCombinedMode(Coupon $coupon, User $user, string $operator): EligibilityResult
```

#### Tests
```php
tests/Feature/Coupon/EligibilityEngine/CombinedModesTest.php
```

### Acceptance Criteria
- [ ] Mode `assignment_and_dynamic`: both assignment AND rules must pass
- [ ] Mode `assignment_or_dynamic`: either assignment OR rules must pass
- [ ] Backward compatibility: existing 'assignment' and 'dynamic' modes unchanged
- [ ] Tests pass

---

## Phase 2.5: NOT Operator (Week 5)

### Goal
Support NOT operator in rule trees.

### Changes

#### Services
```php
// app/Services/Coupon/Eligibility/EligibilityEngine.php
private function evaluateDynamicMode(Coupon $coupon, User $user, ?array $ruleTree): EligibilityResult
{
    // ...
    foreach ($rules as $rule) {
        if (isset($rule['operator'])) {
            if ($rule['operator'] === 'NOT') {
                // NEW: Handle NOT
                $innerRule = $rule['rule'] ?? null;
                if (!$innerRule) {
                    $failedRules[] = ['type' => 'invalid_not'];
                    continue;
                }
                
                $innerResult = /* evaluate inner rule */;
                $ruleResult = ['passed' => !$innerResult['passed'], ...];
            } else {
                // Existing AND/OR handling
            }
        }
    }
}
```

#### Tests
```php
tests/Feature/Coupon/EligibilityEngine/NotOperatorTest.php
```

### Acceptance Criteria
- [ ] Rule tree with NOT operator evaluates correctly
- [ ] Can express: `NOT claimed`
- [ ] Can express: `NOT (min_completed_orders: 5)`
- [ ] Tests pass

---

## Phase 2.6: Snapshot Audience (Week 6-7)

### Goal
Generate and use frozen audience snapshots.

### Changes

#### Migrations
```
2026_XX_XX_000005_create_coupon_audience_snapshots_table.php

CREATE TABLE coupon_audience_snapshots (...);

2026_XX_XX_000006_add_audience_mode_to_coupon_targetings.php

ALTER TABLE coupon_targetings
ADD COLUMN audience_mode ENUM('dynamic', 'snapshot') DEFAULT 'dynamic',
ADD COLUMN snapshot_generated_at TIMESTAMP NULL;
```

#### Models
```php
// packages/marvel/src/Database/Models/CouponAudienceSnapshot.php
class CouponAudienceSnapshot extends Model
{
    protected $fillable = [
        'coupon_id',
        'user_id',
        'snapshotted_at',
        'eligibility_snapshot',
    ];
    
    protected $casts = [
        'snapshotted_at' => 'datetime',
        'eligibility_snapshot' => 'array',
    ];
}
```

#### Services
```php
// app/Services/Coupon/CouponAudienceSnapshotService.php
class CouponAudienceSnapshotService
{
    public function generate(Coupon $coupon): array
    public function regenerate(Coupon $coupon): array
    public function clear(Coupon $coupon): void
}
```

#### Jobs
```php
// app/Jobs/Coupon/GenerateCouponAudienceSnapshotJob.php
class GenerateCouponAudienceSnapshotJob implements ShouldQueue
{
    public function handle(CouponAudienceSnapshotService $service)
    {
        $service->generate($this->coupon);
    }
}
```

#### Controllers
```php
// app/Http/Controllers/Api/Admin/CouponAudienceController.php
class CouponAudienceController extends Controller
{
    public function generateSnapshot(int $couponId)
    public function regenerateSnapshot(int $couponId)
    public function clearSnapshot(int $couponId)
    public function countAudience(int $couponId)
}
```

#### Routes
```php
// routes/api.php (admin)
Route::prefix('admin/coupons/{coupon}/audience')->group(function () {
    Route::post('snapshot/generate', [CouponAudienceController::class, 'generateSnapshot']);
    Route::post('snapshot/regenerate', [CouponAudienceController::class, 'regenerateSnapshot']);
    Route::delete('snapshot', [CouponAudienceController::class, 'clearSnapshot']);
    Route::get('count', [CouponAudienceController::class, 'countAudience']);
});
```

#### EligibilityEngine Changes
```php
public function evaluate(Coupon $coupon, User $user): EligibilityResult
{
    $targeting = $coupon->targeting;
    
    if (!$targeting) {
        return EligibilityResult::eligible(['no_targeting'], []);
    }
    
    // NEW: Check snapshot mode
    if ($targeting->audience_mode === 'snapshot') {
        $inSnapshot = CouponAudienceSnapshot::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
        
        return $inSnapshot
            ? EligibilityResult::eligible(['in_snapshot'], [])
            : EligibilityResult::ineligible([], ['not_in_snapshot'], []);
    }
    
    // Existing dynamic evaluation
    // ...
}
```

#### Tests
```php
tests/Feature/Coupon/CouponAudienceSnapshotTest.php
tests/Feature/Coupon/EligibilityEngine/SnapshotModeTest.php
```

### Acceptance Criteria
- [ ] Can generate snapshot for dynamic coupon
- [ ] Snapshot freezes eligible users at generation time
- [ ] New eligible users after snapshot are NOT in snapshot
- [ ] Can regenerate snapshot (replaces old)
- [ ] Can clear snapshot (returns to dynamic mode)
- [ ] Snapshot generation is queued for large audiences
- [ ] Tests pass

---

## Phase 2.7: Dynamic Eligibility Notifications (Week 8)

### Goal
Notify users when they become eligible for dynamic coupons.

### Changes

#### Migrations
```
2026_XX_XX_000007_create_coupon_eligibility_notifications_table.php

CREATE TABLE coupon_eligibility_notifications (...);

2026_XX_XX_000008_add_notify_on_eligibility_to_coupon_targetings.php

ALTER TABLE coupon_targetings
ADD COLUMN notify_on_eligibility BOOLEAN DEFAULT FALSE;
```

#### Models
```php
// packages/marvel/src/Database/Models/CouponEligibilityNotification.php
class CouponEligibilityNotification extends Model
{
    protected $fillable = [
        'coupon_id',
        'user_id',
        'notified_at',
    ];
    
    protected $casts = [
        'notified_at' => 'datetime',
    ];
}
```

#### Services
```php
// app/Services/Coupon/CouponEligibilityNotificationService.php
class CouponEligibilityNotificationService
{
    public function checkAndNotify(User $user): void
    {
        // For each coupon WHERE notify_on_eligibility = TRUE:
        //     Evaluate eligibility
        //     If newly eligible:
        //         Send notification
        //         Record in coupon_eligibility_notifications
    }
}
```

#### Events
```php
// app/Events/Coupon/UserBecameEligibleForCoupon.php
class UserBecameEligibleForCoupon
{
    public function __construct(
        public readonly Coupon $coupon,
        public readonly User $user,
        public readonly EligibilityResult $eligibilityResult,
    ) {}
}
```

#### Listeners
```php
// app/Listeners/CheckCouponEligibilityTransitions.php
class CheckCouponEligibilityTransitions
{
    public function handle(CustomerMetricsRebuilt $event): void
    {
        $this->notificationService->checkAndNotify($event->user);
    }
}
```

#### Tests
```php
tests/Feature/Coupon/DynamicEligibilityNotificationTest.php
```

### Acceptance Criteria
- [ ] When user completes order and becomes eligible, notification sent
- [ ] Duplicate notifications prevented by unique constraint
- [ ] Only coupons with `notify_on_eligibility = true` are checked
- [ ] Notification includes coupon details and claim link
- [ ] Tests pass

---

## Phase 2.8: Country Support (Optional — Week 9)

### Goal
Add country targeting (if product confirms requirement).

### Changes

#### Migrations
```
2026_XX_XX_000009_add_country_to_customer_metrics.php

ALTER TABLE customer_metrics
ADD COLUMN country_id BIGINT NULL,
ADD INDEX idx_country (country_id),
ADD FOREIGN KEY (country_id) REFERENCES countries(id);
```

#### Enums
```php
case COUNTRY_IN = 'country_in';
```

#### Services
```php
// app/Services/Customer/CustomerMetricsService.php
public function rebuildForUser(User $user): CustomerMetrics
{
    // ...
    $countryId = $this->deriveCountryId($user);
    
    $metrics = CustomerMetrics::updateOrCreate(
        ['user_id' => $user->getKey()],
        [
            // ...
            'country_id' => $countryId,
        ]
    );
}

private function deriveCountryId(User $user): ?int
{
    // Option A: From primary address
    $address = $user->address()->where('is_primary', true)->first();
    if ($address && $address->governorate) {
        return $address->governorate->country_id;
    }
    
    // Option B: From first order
    $firstOrder = $user->orders()->oldest()->first();
    if ($firstOrder && $firstOrder->shippingAddress) {
        return $firstOrder->shippingAddress->governorate?->country_id;
    }
    
    return null;
}

// app/Services/Coupon/Eligibility/EligibilityEngine.php
+ private function evalCountryIn(CustomerMetrics $metrics, array $value): array
```

#### Tests
```php
tests/Feature/Coupon/EligibilityEngine/CountryRulesTest.php
```

### Acceptance Criteria
- [ ] customer_metrics.country_id populated from user's primary address or first order
- [ ] Can create targeting rule: `country_in: [1, 2, 3]` (country IDs)
- [ ] Tests pass

---

# BACKWARD COMPATIBILITY

## Existing Coupons

All existing coupons continue working:

### Public Coupons (No Assignments, No Targeting)
- ✅ No targeting row → eligible by default
- ✅ Flow unchanged

### Assigned Coupons (Has Assignments, targeting.mode = 'assignment')
- ✅ Existing mode value 'assignment' unchanged
- ✅ Flow unchanged

### Dynamic Coupons (targeting.mode = 'dynamic', 13 Phase 1 rules)
- ✅ Existing rule types still work
- ✅ New rule types are additive

## API Compatibility

### Customer API
- ✅ `POST /api/v1/coupons/apply` — unchanged
- ✅ `POST /api/v1/general/coupons/{id}/claim` — unchanged

### Admin API
- ✅ Existing targeting CRUD — unchanged
- 🆕 New snapshot endpoints — additive

## Database Compatibility

### Schema Changes
All schema changes are **ADDITIVE**:
- ✅ New columns with DEFAULT values
- ✅ Enum expansions (backward compatible)
- ✅ New tables (no impact on existing)
- ✅ New indexes (performance only)

### Data Migration
- ✅ No existing data requires migration
- ✅ New columns nullable or have defaults
- ✅ Existing mode values ('assignment', 'dynamic') remain valid

---

# TESTING STRATEGY

## Unit Tests

### EligibilityEngine
- [ ] Each rule type (24 total)
- [ ] AND operator
- [ ] OR operator
- [ ] NOT operator
- [ ] Nested groups
- [ ] Invalid rule types (fail-closed)

### CouponAudienceSnapshotService
- [ ] Generate snapshot
- [ ] Regenerate snapshot
- [ ] Clear snapshot
- [ ] Large audience (chunking)

### CouponEligibilityNotificationService
- [ ] Detect eligibility transition
- [ ] Idempotency
- [ ] Rate limiting

## Integration Tests

### Claim Flow
- [ ] Dynamic mode + claim
- [ ] Snapshot mode + claim
- [ ] Assignment + dynamic (AND)
- [ ] Assignment + dynamic (OR)

### Apply Flow
- [ ] Claim required → must claim first
- [ ] Claim not required → apply directly

### Redemption Flow
- [ ] Public coupon redemption
- [ ] Assigned coupon redemption
- [ ] Claim-required coupon redemption

## Feature Tests

### Assignment Modes
- [ ] Assignment only
- [ ] Targeting only
- [ ] Assignment AND targeting
- [ ] Assignment OR targeting

### Purchase History Rules
- [ ] Has purchased product
- [ ] Has purchased category
- [ ] Has purchased brand
- [ ] Only completed orders counted

### Snapshot Generation
- [ ] Small audience (< 100 users)
- [ ] Medium audience (1,000 users)
- [ ] Large audience (10,000+ users)
- [ ] Regenerate replaces old

### Notifications
- [ ] User becomes eligible → notification sent
- [ ] User already notified → no duplicate
- [ ] notify_on_eligibility = false → no notification

## Performance Tests

### Eligibility Evaluation
- [ ] 1 rule: < 50ms
- [ ] 10 rules: < 200ms
- [ ] 50 rules: < 1s

### Snapshot Generation
- [ ] 1,000 users: < 10s
- [ ] 10,000 users: < 60s
- [ ] 100,000 users: queued job

### Purchase History Rules
- [ ] EXPLAIN shows indexed queries
- [ ] No N+1 queries

---

# RISKS & MITIGATION

## Risk 1: Purchase History Query Performance

**Risk:** Purchase history rules involve 3-4 table joins. Could be slow for users with large order histories.

**Impact:** High (affects eligibility evaluation speed)

**Probability:** Medium

**Mitigation:**
1. Add indexes on order_items.product_id, category_product, brand_product
2. Consider caching purchase history in separate table if performance degrades
3. Set query timeout (5s)
4. Monitor query performance in production

## Risk 2: Snapshot Generation Time

**Risk:** Generating snapshot for 100,000+ users could take minutes.

**Impact:** Medium (admin UX)

**Probability:** High

**Mitigation:**
1. Queue snapshot generation as background job
2. Provide progress tracking
3. Chunk users (1000 at a time)
4. Pre-filter by basic criteria (is_active = true)
5. Set realistic expectations (admin knows this is bulk operation)

## Risk 3: Notification Spam

**Risk:** User completes many orders in short time → receives many eligibility notifications.

**Impact:** Medium (user annoyance)

**Probability:** Low

**Mitigation:**
1. Rate limit: max 1 notification per coupon per user per day
2. Only notify for coupons with `notify_on_eligibility = true`
3. Admin must explicitly enable notifications

## Risk 4: Country/Language/Gender Data Quality

**Risk:** Users may not have country/language/gender populated.

**Impact:** Medium (targeting accuracy)

**Probability:** High (for gender)

**Mitigation:**
1. Make these fields nullable
2. Derive country from address/order where possible
3. Document data quality limitations to admin
4. Consider deferring gender targeting until legal/privacy review

## Risk 5: Assignment Mode Migration

**Risk:** Changing from simple 'assignment'/'dynamic' enum to combined modes could break existing admin UI.

**Impact:** Medium (admin UX)

**Probability:** Low

**Mitigation:**
1. Expand enum (backward compatible)
2. Existing values ('assignment', 'dynamic') continue working
3. Admin UI shows new options only for new coupons
4. Document migration path in admin docs

---

# ROLLOUT PLAN

## Phase 2.1: User Attribute Rules (Week 1-2)
- Deploy migrations (no schema changes, uses existing fields)
- Deploy EligibilityEngine changes
- Deploy tests
- **No feature flag required** (new rule types, backward compatible)

## Phase 2.2: AOV Rules (Week 2)
- Deploy migration (add average_order_value column)
- Rebuild existing customer_metrics (one-time job)
- Deploy EligibilityEngine changes
- Deploy tests
- **No feature flag required**

## Phase 2.3: Purchase History Rules (Week 3-4)
- Deploy indexes (performance)
- Deploy EligibilityEngine changes
- Deploy tests
- **No feature flag required**
- **Monitor query performance** in production

## Phase 2.4: Assignment AND/OR Modes (Week 4-5)
- Deploy migration (expand mode enum)
- Deploy EligibilityEngine changes
- Deploy tests
- **No feature flag required** (enum expansion backward compatible)
- Update admin UI to show new modes

## Phase 2.5: NOT Operator (Week 5)
- Deploy EligibilityEngine changes (rule tree parsing)
- Deploy tests
- **No feature flag required**
- Document NOT operator in admin docs

## Phase 2.6: Snapshot Audience (Week 6-7)
- Deploy migrations (coupon_audience_snapshots table, audience_mode column)
- Deploy CouponAudienceSnapshotService
- Deploy Jobs
- Deploy Admin API endpoints
- Deploy tests
- **Feature flag:** `coupon_audience_snapshot_enabled` = TRUE (default OFF)
- Enable for beta admin users first
- Monitor snapshot generation performance
- Enable for all admins after validation

## Phase 2.7: Dynamic Eligibility Notifications (Week 8)
- Deploy migrations (coupon_eligibility_notifications table, notify_on_eligibility column)
- Deploy CouponEligibilityNotificationService
- Deploy Events/Listeners
- Deploy tests
- **Feature flag:** `coupon_eligibility_notifications_enabled` = TRUE (default OFF)
- Enable for single test coupon first
- Monitor notification volume
- Tune rate limits
- Enable for all coupons after validation

## Phase 2.8: Country Support (Optional — Week 9)
- Deploy migration (add country_id to customer_metrics)
- Rebuild existing customer_metrics (one-time job)
- Deploy EligibilityEngine changes
- Deploy tests
- **No feature flag required**

---

# IMPLEMENTATION CHECKLIST

## Database

- [ ] Migration: Add average_order_value to customer_metrics
- [ ] Migration: Add country_id to customer_metrics
- [ ] Migration: Expand coupon_targetings.mode enum
- [ ] Migration: Add audience_mode, snapshot_generated_at, notify_on_eligibility to coupon_targetings
- [ ] Migration: Create coupon_audience_snapshots table
- [ ] Migration: Create coupon_eligibility_notifications table
- [ ] Migration: Add purchase history indexes

## Models

- [ ] CouponAudienceSnapshot model
- [ ] CouponEligibilityNotification model
- [ ] Update Coupon model (relationships)
- [ ] Update CouponTargeting model (new fields)

## Enums

- [ ] Add 11+ new EligibilityRuleType cases

## Services

- [ ] EligibilityEngine: Add 11 new rule evaluation methods
- [ ] EligibilityEngine: Add NOT operator support
- [ ] EligibilityEngine: Add combined mode evaluation
- [ ] EligibilityEngine: Add snapshot mode evaluation
- [ ] CouponAudienceSnapshotService (new)
- [ ] CouponEligibilityNotificationService (new)
- [ ] CustomerMetricsService: Add country derivation

## Jobs

- [ ] GenerateCouponAudienceSnapshotJob
- [ ] RegenerateCouponAudienceSnapshotJob

## Events

- [ ] UserBecameEligibleForCoupon

## Listeners

- [ ] CheckCouponEligibilityTransitions

## Controllers

- [ ] CouponAudienceController (admin, new)

## Routes

- [ ] POST /api/v1/admin/coupons/{id}/audience/snapshot/generate
- [ ] POST /api/v1/admin/coupons/{id}/audience/snapshot/regenerate
- [ ] DELETE /api/v1/admin/coupons/{id}/audience/snapshot
- [ ] GET /api/v1/admin/coupons/{id}/audience/count

## Tests

- [ ] EligibilityEngine: 24 rule types
- [ ] EligibilityEngine: NOT operator
- [ ] EligibilityEngine: Combined modes
- [ ] EligibilityEngine: Snapshot mode
- [ ] CouponAudienceSnapshotTest
- [ ] DynamicEligibilityNotificationTest
- [ ] PurchaseHistoryRulesTest (with EXPLAIN)
- [ ] CombinedModesTest
- [ ] BackwardCompatibilityTest

## Documentation

- [ ] Admin docs: New rule types
- [ ] Admin docs: Combined modes
- [ ] Admin docs: Snapshot generation
- [ ] Admin docs: Eligibility notifications
- [ ] API docs: New admin endpoints

---

# FINAL VERDICT

## What Already Works (Phase 1)

✅ **The core targeting and claim infrastructure is FULLY FUNCTIONAL.**

The system already has:
- Claim acquisition with concurrency safety
- Eligibility evaluation with 13 rules
- Assignment-based targeting
- Dynamic rule-based targeting
- Customer metrics materialization
- Reservation system for payment-window capacity
- Redemption tracking (public + assigned)
- Full separation of concerns

## What Phase 2 Adds

🆕 **Phase 2 extends the existing system with:**
- 11+ new rule types (user attributes, AOV, purchase history)
- Combined assignment + targeting modes (AND/OR)
- Snapshot audience generation
- NOT operator in rule trees
- Dynamic eligibility notifications

## Implementation Effort

**Phase 2 Total:** 8-9 weeks

**Phase 2 is an EXTENSION, not a rewrite.**

Most work is:
- Adding new rule evaluation methods (low risk)
- Adding new tables for snapshots/notifications (isolated)
- Expanding enum values (backward compatible)

## Recommendation

**APPROVE Phase 2 implementation in phases.**

Start with:
1. User attribute rules (Week 1-2)
2. AOV rules (Week 2)
3. Purchase history rules (Week 3-4)

Then evaluate performance and proceed with:
4. Assignment modes (Week 4-5)
5. NOT operator (Week 5)
6. Snapshot audience (Week 6-7)
7. Notifications (Week 8)

---

# APPENDIX A: RULE TYPE REFERENCE

## Phase 1 (Existing)

| Rule Type | Description | Value Type | Example |
|-----------|-------------|------------|---------|
| min_completed_orders | Minimum completed orders | int | 5 |
| max_completed_orders | Maximum completed orders | int | 10 |
| min_total_spend | Minimum total spend | float | 10000.00 |
| max_total_spend | Maximum total spend | float | 50000.00 |
| first_order_after | First order after date | date | "2026-01-01" |
| first_order_before | First order before date | date | "2025-12-31" |
| last_order_after | Last order after date | date | "2026-06-01" |
| last_order_before | Last order before date | date | "2026-05-31" |
| min_coupons_used | Minimum coupons used | int | 1 |
| max_coupons_used | Maximum coupons used | int | 5 |
| not_claimed | User has not claimed this coupon | null | null |
| claimed | User has claimed this coupon | null | null |
| has_assignment | User has assignment for this coupon | null | null |

## Phase 2 (New)

| Rule Type | Description | Value Type | Example |
|-----------|-------------|------------|---------|
| registration_after | Registered after date | date | "2026-01-01" |
| registration_before | Registered before date | date | "2025-12-31" |
| registration_between | Registered between dates | array | ["2026-01-01", "2026-06-30"] |
| user_id_in | User ID in list | array | [1, 2, 3, 123] |
| phone_starts_with | Phone starts with prefix | string | "+20" |
| user_is_active | User is active | null | null |
| min_average_order_value | Minimum AOV | float | 500.00 |
| max_average_order_value | Maximum AOV | float | 2000.00 |
| has_purchased_product | Purchased product ID | int | 123 |
| has_purchased_category | Purchased category ID | int | 45 |
| has_purchased_brand | Purchased brand ID | int | 67 |

## Phase 2 (Optional/Deferred)

| Rule Type | Description | Value Type | Example |
|-----------|-------------|------------|---------|
| country_in | User country in list | array | [1, 2, 3] |
| language_in | User language in list | array | ["en", "ar"] |
| gender_in | User gender in list | array | ["male", "female"] |

---

# APPENDIX B: RULE TREE EXAMPLES

## Example 1: Simple AND

```json
{
    "operator": "AND",
    "rules": [
        {"type": "min_completed_orders", "value": 5},
        {"type": "min_total_spend", "value": 10000}
    ]
}
```

**Semantics:** User must have completed >= 5 orders AND spent >= 10,000.

## Example 2: Simple OR

```json
{
    "operator": "OR",
    "rules": [
        {"type": "has_assignment"},
        {"type": "min_completed_orders", "value": 10}
    ]
}
```

**Semantics:** User must have assignment OR have completed >= 10 orders.

## Example 3: Nested Groups

```json
{
    "operator": "AND",
    "rules": [
        {
            "operator": "OR",
            "rules": [
                {"type": "min_completed_orders", "value": 5},
                {"type": "has_assignment"}
            ]
        },
        {"type": "min_total_spend", "value": 10000}
    ]
}
```

**Semantics:** (User has 5+ orders OR has assignment) AND (user spent 10,000+).

## Example 4: NOT Operator

```json
{
    "operator": "AND",
    "rules": [
        {"type": "min_completed_orders", "value": 5},
        {
            "operator": "NOT",
            "rule": {"type": "claimed"}
        }
    ]
}
```

**Semantics:** User has 5+ orders AND has NOT claimed this coupon.

## Example 5: Complex Targeting

```json
{
    "operator": "AND",
    "rules": [
        {"type": "registration_after", "value": "2026-01-01"},
        {
            "operator": "OR",
            "rules": [
                {"type": "has_purchased_category", "value": 45},
                {"type": "has_purchased_brand", "value": 67}
            ]
        },
        {"type": "min_average_order_value", "value": 500}
    ]
}
```

**Semantics:**
- User registered after 2026-01-01
- AND (purchased category 45 OR purchased brand 67)
- AND AOV >= 500

---

END OF PLAN
