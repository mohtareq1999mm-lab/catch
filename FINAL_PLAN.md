# COUPON PHASE 2 — FINAL ARCHITECTURE AUDIT & IMPLEMENTATION PLAN

**Audit Date**: 2026-09-13  
**Repository**: Catch (D:\work\meem)  
**Auditor**: AI Senior Architect  
**Status**: PHASE 1 COMPLETE — PHASE 2 ARCHITECTURE CORRECTIONS REQUIRED

---

## 1. EXECUTIVE SUMMARY

### What Is Already Correct

**Phase 1 Implementation (COMPLETE)**:
- ✅ CouponClaim model with UNIQUE(coupon_id, user_id) constraint
- ✅ CouponTargeting model with mode and require_claim flags
- ✅ EligibilityEngine with 13 whitelisted rule types (fail-closed)
- ✅ CouponClaimService with FOR UPDATE locking on CouponTargeting parent row
- ✅ CustomerMetrics table with order-based behavioral data
- ✅ CustomerMetricsService for metrics computation
- ✅ CouponAssignment model for user-specific quotas
- ✅ CouponReservation model for checkout-time reservation
- ✅ HTTP endpoint: POST /api/v1/general/coupons/{id}/claim
- ✅ Concurrency tests (MultiConnectionLockTest with independent PDO connections)
- ✅ Functional tests (CouponClaimTest with 13 test scenarios)
- ✅ Database migration: max_claims_per_user → max_claims (correct semantics)

**Existing Architecture Quality**:
- Clean domain separation (Assignment, Targeting, Claim, Reservation, Redemption)
- Proper transaction boundaries
- Parent-row serialization pattern for First-N enforcement
- Fail-closed security model (unknown rules rejected)
- Backward compatibility preserved (applyCoupon endpoint unchanged)

### What Must Change

**CRITICAL ARCHITECTURE GAPS** (Phase 2 Scope):

1. **Assignment + Targeting Composition (MISSING)**
   - Current: Only `mode: assignment` OR `mode: dynamic`
   - Required: `assignment AND dynamic`, `assignment OR dynamic`
   - Gap: No compositional logic in EligibilityEngine

2. **Claim Lifecycle State Machine (INCOMPLETE)**
   - Current: Claims are lifetime records (no status field)
   - Required: `active`, `expired`, `redeemed` states
   - Gap: Re-claim after expiry not supported
   - Gap: One-active-claim-per-user not enforced

3. **Snapshot Audience Materialization (MISSING)**
   - Required: Generate frozen audience from targeting rules
   - Gap: No snapshot generation logic
   - Gap: No snapshot regeneration workflow
   - Gap: No snapshot export (CSV/Excel)
   - Gap: No snapshot versioning

4. **Dynamic Eligibility Notifications (MISSING)**
   - Required: Notify users when they become eligible
   - Gap: No event-driven eligibility evaluation
   - Gap: No notification idempotency mechanism
   - Gap: No dependency mapping (which coupons depend on order_completed)

5. **Purchase History Targeting (MISSING)**
   - Required: purchased_product, purchased_category, purchased_brand rules
   - Gap: No purchase history abstraction
   - Gap: No rule types for product/category/brand

6. **Demographic Targeting (DEFERRED)**
   - Required: country, language, gender, registration_date rules
   - Current: User/Profile models exist but no rule types
   - Decision: Defer to Phase 2 implementation (low business priority per requirements)

7. **AOV Calculation (MISSING)**
   - Required: Average Order Value eligibility rule
   - Gap: No AOV computation in CustomerMetrics
   - Gap: No generated column / cached value

8. **Date Semantics (AMBIGUOUS)**
   - Current: first_order_after / first_order_before use datetime comparison
   - Required: DATE-ONLY semantics with explicit inclusive/exclusive boundaries
   - Gap: registration_date targeting not implemented

### What Must NOT Change

- ✅ Existing public coupon flow (no assignments, single-use)
- ✅ Existing assigned coupon flow (CouponAssignment quotas)
- ✅ Existing reservation flow (CouponReservationService)
- ✅ POST /api/v1/general/coupons/apply endpoint contract
- ✅ CouponValidator, CouponOrchestrator, CouponCalculator (Phase 1 services)
- ✅ FOR UPDATE locking strategy (proven correct in concurrency tests)
- ✅ UNIQUE(coupon_id, user_id) constraint (atomic guard)
- ✅ CustomerMetrics table structure (already correct for Phase 1)

---

## 2. CURRENT ARCHITECTURE (AS-IS)

### Database Schema

```
coupons (existing Phase 0)
├── id
├── code (unique)
├── slug
├── name (translatable JSON)
├── discount_type (percentage, fixed_rate)
├── discount
├── max_discount_amount
├── start_date
├── end_date
├── limiter (global capacity)
├── used (global counter)
├── status
└── timestamps

coupon_targetings (Phase 1)
├── id
├── coupon_id (FK, unique)
├── mode (enum: 'assignment', 'dynamic')
├── require_claim (boolean)
├── max_claims (integer, nullable) — renamed from max_claims_per_user
├── rule_tree (JSON)
└── timestamps

coupon_claims (Phase 1)
├── id
├── coupon_id (FK)
├── user_id (FK)
├── claimed_at (timestamp)
├── eligibility_snapshot (JSON)
├── timestamps
└── UNIQUE(coupon_id, user_id) — CRITICAL CONCURRENCY GUARD

coupon_assignments (existing Phase 0)
├── id
├── coupon_id (FK)
├── user_id (FK)
├── max_uses (default 1)
├── used (default 0)
├── assigned_at
├── expires_at (nullable)
├── timestamps
└── UNIQUE(coupon_id, user_id)

coupon_assignment_usages (existing Phase 0)
├── id
├── coupon_assignment_id (FK)
├── order_id (FK, NOT NULL)
├── used_at
└── timestamps

coupon_reservations (existing Phase 0)
├── id
├── coupon_id (FK)
├── user_id (FK)
├── order_id (FK, unique) — one reservation per order
├── reserved_at
├── expires_at (30min TTL)
└── timestamps

customer_metrics (Phase 1)
├── id
├── user_id (FK, unique)
├── completed_orders (unsigned int)
├── total_qualifying_order_value (decimal 15,2)
├── first_order_at (timestamp nullable)
├── last_order_at (timestamp nullable)
├── coupons_used (unsigned int)
├── computed_at (timestamp)
└── timestamps
```

### Domain Model (Phase 1)

```
┌─────────────────────────────────────────────────────────────┐
│ ASSIGNMENT                                                  │
│ - User explicitly granted access to coupon                  │
│ - Quota: max_uses per user                                  │
│ - Expiry: optional expires_at                               │
│ - Model: CouponAssignment                                   │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ TARGETING                                                   │
│ - Dynamic rule-based audience definition                    │
│ - Mode: assignment | dynamic                                │
│ - Rule tree: AND/OR combinator + 13 whitelisted rules      │
│ - Model: CouponTargeting                                    │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ ELIGIBILITY                                                 │
│ - Policy evaluation: Assignment + Targeting → Eligible?     │
│ - Service: EligibilityEngine                                │
│ - Security: Fail-closed (unknown rules rejected)            │
│ - Output: EligibilityResult DTO                             │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ CLAIM (Phase 1 — LIFETIME SLOT RESERVATION)                │
│ - User declares intent to use coupon                        │
│ - Persistent, NOT temporary                                 │
│ - Concurrency: FOR UPDATE on coupon_targetings parent row   │
│ - Atomic guard: UNIQUE(coupon_id, user_id)                 │
│ - First-N enforcement: max_claims check                     │
│ - Lifecycle: One claim forever (Phase 1 limitation)         │
│ - Model: CouponClaim                                        │
│ - Service: CouponClaimService                               │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ RESERVATION (existing Phase 0)                              │
│ - Temporary checkout-time slot reservation                  │
│ - TTL: 30 minutes                                           │
│ - Released on payment failure                               │
│ - Consumed on payment success                               │
│ - Model: CouponReservation                                  │
│ - Service: CouponReservationService                         │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│ REDEMPTION (existing Phase 0)                               │
│ - Payment success → coupon applied to order                 │
│ - Assignment: increment used counter                        │
│ - Public coupon: record in coupon_usages                    │
│ - Reservation: consumed (deleted)                           │
└─────────────────────────────────────────────────────────────┘
```

### Eligibility Policy (Phase 1)

**Current Logic**:

```php
if ($targeting->mode === 'assignment') {
    return user has CouponAssignment?
}

if ($targeting->mode === 'dynamic') {
    return evaluate rule_tree?
}
```

**Supported Rule Types** (13 whitelisted):
1. `min_completed_orders`
2. `max_completed_orders`
3. `min_total_spend`
4. `max_total_spend`
5. `first_order_after`
6. `first_order_before`
7. `last_order_after`
8. `last_order_before`
9. `min_coupons_used`
10. `max_coupons_used`
11. `not_claimed` (this coupon)
12. `claimed` (this coupon)
13. `has_assignment` (this coupon)

**Rule Tree Structure**:
```json
{
  "operator": "AND",
  "rules": [
    {"type": "min_completed_orders", "value": 5},
    {"type": "min_total_spend", "value": 10000}
  ]
}
```

**Missing**:
- Assignment AND Dynamic composition
- Assignment OR Dynamic composition
- NOT operator
- Nested rule trees
- Purchase history rules (product, category, brand)
- Demographic rules (country, language, gender, registration_date)
- AOV rule

---

## 3. CORRECTED TARGET ARCHITECTURE (TO-BE)

### 3.1 Assignment + Targeting Composition

**NEW MODES**:

```php
enum TargetingMode: string
{
    case ASSIGNMENT_ONLY = 'assignment';
    case DYNAMIC_ONLY = 'dynamic';
    case ASSIGNMENT_AND_DYNAMIC = 'assignment_and_dynamic';  // NEW
    case ASSIGNMENT_OR_DYNAMIC = 'assignment_or_dynamic';    // NEW
}
```

**Eligibility Logic**:

```php
// ASSIGNMENT_ONLY
if (mode === 'assignment') {
    return has_assignment;
}

// DYNAMIC_ONLY
if (mode === 'dynamic') {
    return evaluate_rule_tree;
}

// ASSIGNMENT_AND_DYNAMIC
if (mode === 'assignment_and_dynamic') {
    return has_assignment AND evaluate_rule_tree;
}

// ASSIGNMENT_OR_DYNAMIC
if (mode === 'assignment_or_dynamic') {
    return has_assignment OR evaluate_rule_tree;
}
```

**Database Change**:
```sql
ALTER TABLE coupon_targetings 
MODIFY COLUMN mode ENUM(
    'assignment', 
    'dynamic', 
    'assignment_and_dynamic', 
    'assignment_or_dynamic'
) DEFAULT 'assignment';
```

### 3.2 Claim Lifecycle State Machine

**NEW SCHEMA**:

```sql
ALTER TABLE coupon_claims ADD COLUMN status ENUM(
    'active',
    'expired',
    'redeemed'
) DEFAULT 'active' AFTER claimed_at;

ALTER TABLE coupon_claims ADD COLUMN expires_at TIMESTAMP NULL AFTER claimed_at;
ALTER TABLE coupon_claims ADD COLUMN redeemed_at TIMESTAMP NULL AFTER expires_at;

-- Index for active claims queries
CREATE INDEX idx_coupon_claims_status_expires ON coupon_claims(status, expires_at);

-- Index for user active claims
CREATE INDEX idx_coupon_claims_user_status ON coupon_claims(user_id, status);
```

**Lifecycle Transitions**:

```
ACTIVE (initial state)
   ├─→ EXPIRED (expires_at reached, manual expiry, or expiry job)
   └─→ REDEEMED (payment success via OrderService)

EXPIRED
   └─→ (terminal state, user may claim again if still eligible)

REDEEMED
   └─→ (terminal state, no refund = no return to pool)
```

**Enforcement**:

```sql
-- Remove UNIQUE(coupon_id, user_id) — allows historical claims
-- Add UNIQUE partial index for active claims only (MySQL 8.0.13+)

CREATE UNIQUE INDEX idx_coupon_claims_active_unique 
ON coupon_claims(coupon_id, user_id) 
WHERE status = 'active';

-- CRITICAL: TiDB compatibility check required
-- If TiDB does not support filtered unique indexes, use application-level enforcement:
-- 1. Transaction: SELECT ... FOR UPDATE on coupon_targetings
-- 2. Check: COUNT(*) WHERE coupon_id AND user_id AND status='active' = 0
-- 3. Insert with status='active'
```

**Business Rules**:
1. User may have ONE ACTIVE claim per coupon
2. User may have unlimited EXPIRED/REDEEMED historical claims
3. Expired claim does NOT prevent new claim (if user still eligible)
4. Redeemed claim is permanent (refund does NOT return coupon)
5. Payment failure does NOT change claim status (claim remains ACTIVE for retry)
6. Claim expiry is optional (expires_at nullable)

### 3.3 First-N Semantics (CORRECTED)

**Current Implementation** (Phase 1):
```php
// Check total claims across all users
$totalClaims = CouponClaim::where('coupon_id', $couponId)->count();

if ($totalClaims >= $targeting->max_claims) {
    throw CouponClaimException::maxClaimsReached();
}
```

**Phase 2 Correction** (account for claim status):
```php
// Count ONLY active + redeemed claims (NOT expired)
// Expired claims release capacity for re-claim
$countingClaims = CouponClaim::query()
    ->where('coupon_id', $couponId)
    ->whereIn('status', ['active', 'redeemed'])
    ->count();

if ($countingClaims >= $targeting->max_claims) {
    throw CouponClaimException::maxClaimsReached();
}
```

**Design Decision**:
- **Expired claims DO release capacity** (user had chance but didn't use it)
- **Redeemed claims NEVER release capacity** (permanently consumed)
- **Active claims count toward capacity** (reserved but not yet used)

### 3.4 Snapshot Audience

**NEW SCHEMA**:

```sql
CREATE TABLE coupon_snapshots (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    coupon_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('generating', 'completed', 'failed') DEFAULT 'generating',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    member_count INT UNSIGNED DEFAULT 0,
    rule_tree_snapshot JSON NULL,  -- Rule tree used for this generation
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    INDEX idx_coupon_version (coupon_id, version),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupon_snapshot_members (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    eligibility_snapshot JSON NULL,  -- Why this user was included
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (snapshot_id) REFERENCES coupon_snapshots(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_snapshot_user (snapshot_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Generation Workflow**:

```
Admin clicks "Generate Snapshot"
    ↓
Job: GenerateCouponSnapshotJob (queued)
    ↓
1. Create coupon_snapshots record (status='generating', version++)
2. Query ALL users
3. For each user:
   - Evaluate eligibility against rule_tree
   - If eligible: INSERT into coupon_snapshot_members
4. Update snapshot (status='completed', completed_at, member_count)
5. Fire event: CouponSnapshotCompleted
6. Send notification to admin
```

**Regeneration**:
- Creates NEW snapshot record with incremented version
- Old snapshots retained for audit (unless explicitly deleted)
- Snapshot members reference snapshot_id (versioned)

**Export**:
- Job: ExportCouponSnapshotJob (queued)
- Chunk users (1000 per batch)
- Generate CSV: user_id, name, email, phone, eligibility_snapshot
- Store in storage/app/exports/
- Notify admin with download link

### 3.5 Dynamic Eligibility Notifications

**Event Dependency Mapping**:

```php
// Map: Event → Affected Metrics → Affected Coupons

ORDER_COMPLETED:
    - completed_orders ↑
    - total_qualifying_order_value ↑
    - first_order_at (if first)
    - last_order_at
    - purchased_product, purchased_category, purchased_brand
    
    → Evaluate coupons with rules:
        - min_completed_orders
        - max_completed_orders
        - min_total_spend
        - max_total_spend
        - first_order_after / before
        - last_order_after / before
        - purchased_product / category / brand

COUPON_REDEEMED:
    - coupons_used ↑
    
    → Evaluate coupons with rules:
        - min_coupons_used
        - max_coupons_used

USER_REGISTERED:
    - registration_date
    
    → Evaluate coupons with rules:
        - registration_after / before / between

USER_PROFILE_UPDATED:
    - country, language, gender, phone
    
    → Evaluate coupons with rules:
        - country_in
        - language_in
        - gender_is
```

**Notification Flow**:

```
OrderCompleted event fired
    ↓
Listener: EvaluateDynamicCouponEligibility
    ↓
1. Rebuild customer_metrics for user
2. Query coupons WHERE targeting.mode IN ('dynamic', 'assignment_or_dynamic')
   AND require_claim = true
   AND has dependency on order metrics
3. For each coupon:
   - Check previous eligibility (cache or DB)
   - Evaluate current eligibility
   - If transition: NOT_ELIGIBLE → ELIGIBLE:
       → Queue: SendEligibilityNotification job
```

**Idempotency**:

```sql
CREATE TABLE coupon_eligibility_notifications (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    coupon_id BIGINT UNSIGNED NOT NULL,
    notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_coupon (user_id, coupon_id),
    INDEX idx_notified_at (notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notification Logic**:
```php
// Only notify ONCE per user per coupon (lifetime)
if (CouponEligibilityNotification::where('user_id', $userId)
    ->where('coupon_id', $couponId)
    ->exists()) {
    return; // Already notified
}

// Send notification
$user->notify(new CouponNowEligible($coupon));

// Record notification
CouponEligibilityNotification::create([
    'user_id' => $userId,
    'coupon_id' => $couponId,
]);
```

### 3.6 Purchase History Targeting

**NEW RULE TYPES**:

```php
enum EligibilityRuleType: string
{
    // ... existing 13 rules ...
    
    // Purchase history rules (Phase 2)
    case PURCHASED_PRODUCT = 'purchased_product';
    case PURCHASED_CATEGORY = 'purchased_category';
    case PURCHASED_BRAND = 'purchased_brand';
    case NOT_PURCHASED_PRODUCT = 'not_purchased_product';
    case NOT_PURCHASED_CATEGORY = 'not_purchased_category';
    case NOT_PURCHASED_BRAND = 'not_purchased_brand';
}
```

**Rule Examples**:
```json
{
  "operator": "AND",
  "rules": [
    {"type": "purchased_category", "value": 15},
    {"type": "not_purchased_brand", "value": 42},
    {"type": "min_total_spend", "value": 5000}
  ]
}
```

**Evaluation Context** (prevent N+1):

```php
class PurchaseHistoryContext
{
    public function __construct(
        private readonly User $user,
        private readonly ?Collection $purchasedProducts = null,
        private readonly ?Collection $purchasedCategories = null,
        private readonly ?Collection $purchasedBrands = null,
    ) {}
    
    public static function load(User $user): self
    {
        // Single query to load all purchase history
        $history = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('category_product', 'products.id', '=', 'category_product.product_id')
            ->where('orders.user_id', $user->id)
            ->where('orders.status', 'completed')
            ->where('orders.payment_status', 'payment-success')
            ->select('products.id as product_id', 'products.brand_id', 'category_product.category_id')
            ->get();
        
        return new self(
            user: $user,
            purchasedProducts: $history->pluck('product_id')->unique(),
            purchasedCategories: $history->pluck('category_id')->filter()->unique(),
            purchasedBrands: $history->pluck('brand_id')->filter()->unique(),
        );
    }
    
    public function hasPurchasedProduct(int $productId): bool
    {
        return $this->purchasedProducts->contains($productId);
    }
    
    public function hasPurchasedCategory(int $categoryId): bool
    {
        return $this->purchasedCategories->contains($categoryId);
    }
    
    public function hasPurchasedBrand(int $brandId): bool
    {
        return $this->purchasedBrands->contains($brandId);
    }
}
```

**Required Indexes**:
```sql
-- Already exists: orders(user_id, status, payment_status)
-- Already exists: order_items(order_id, product_id)
-- Verify: products(brand_id)
-- Verify: category_product(product_id, category_id)
```

### 3.7 Demographic Targeting (DEFERRED TO IMPLEMENTATION)

**NEW RULE TYPES** (Phase 2, lower priority):

```php
enum EligibilityRuleType: string
{
    // ... existing + purchase history rules ...
    
    // Demographic rules (Phase 2, deferred)
    case COUNTRY_IN = 'country_in';
    case LANGUAGE_IN = 'language_in';
    case GENDER_IS = 'gender_is';
    case REGISTRATION_AFTER = 'registration_after';
    case REGISTRATION_BEFORE = 'registration_before';
    case REGISTRATION_BETWEEN = 'registration_between';
    case REGISTRATION_ON = 'registration_on';
}
```

**Data Source**:
- `users.created_at` → registration date
- `user_profiles.*` → demographic fields

**Implementation Note**: 
Phase 2 should verify actual `user_profiles` schema before implementing demographic rules. Current Profile model exists but schema not fully audited.

### 3.8 AOV Calculation

**Option 1: Runtime Calculation** (Recommended for Phase 2):

```php
// In EligibilityEngine
private function evalMinAOV(CustomerMetrics $metrics, $value): array
{
    if ($metrics->completed_orders == 0) {
        return ['passed' => false, 'reason' => 'No completed orders'];
    }
    
    $aov = $metrics->total_qualifying_order_value / $metrics->completed_orders;
    $passed = $aov >= (float) $value;
    
    return [
        'passed' => $passed,
        'type' => EligibilityRuleType::MIN_AOV->value,
        'value' => $value,
        'actual' => $aov,
        'reason' => $passed ? null : "AOV is {$aov}, needs {$value}",
    ];
}
```

**Option 2: Cached Column** (if performance requires):

```sql
ALTER TABLE customer_metrics 
ADD COLUMN avg_order_value DECIMAL(15,2) GENERATED ALWAYS AS (
    CASE 
        WHEN completed_orders > 0 
        THEN total_qualifying_order_value / completed_orders 
        ELSE 0 
    END
) STORED;

CREATE INDEX idx_customer_metrics_aov ON customer_metrics(avg_order_value);
```

**TiDB Compatibility**: Verify TiDB supports GENERATED ALWAYS AS ... STORED before using Option 2.

**Recommendation**: Start with Option 1 (runtime), add Option 2 only if benchmarks prove necessity.

### 3.9 Date Semantics

**Current Issue**:
- `first_order_after` uses `$metrics->first_order_at->isAfter($value)`
- `$value` is datetime string, but business requirement is DATE-ONLY

**Corrected Semantics**:

```php
// AFTER: first order date > target date (exclusive)
private function evalFirstOrderAfter(CustomerMetrics $metrics, string $value): array
{
    if (!$metrics->first_order_at) {
        return ['passed' => false, 'reason' => 'No orders'];
    }
    
    $targetDate = Carbon::parse($value)->startOfDay();
    $orderDate = $metrics->first_order_at->startOfDay();
    
    $passed = $orderDate->isAfter($targetDate);
    
    return [
        'passed' => $passed,
        'type' => EligibilityRuleType::FIRST_ORDER_AFTER->value,
        'value' => $value,
        'actual' => $orderDate->toDateString(),
        'reason' => $passed ? null : "Order on {$orderDate->toDateString()}, must be after {$targetDate->toDateString()}",
    ];
}

// BEFORE: first order date < target date (exclusive)
private function evalFirstOrderBefore(CustomerMetrics $metrics, string $value): array
{
    if (!$metrics->first_order_at) {
        return ['passed' => false, 'reason' => 'No orders'];
    }
    
    $targetDate = Carbon::parse($value)->startOfDay();
    $orderDate = $metrics->first_order_at->startOfDay();
    
    $passed = $orderDate->isBefore($targetDate);
    
    return [
        'passed' => $passed,
        'type' => EligibilityRuleType::FIRST_ORDER_BEFORE->value,
        'value' => $value,
        'actual' => $orderDate->toDateString(),
        'reason' => $passed ? null : "Order on {$orderDate->toDateString()}, must be before {$targetDate->toDateString()}",
    ];
}

// ON: first order date == target date (inclusive, same day)
private function evalFirstOrderOn(CustomerMetrics $metrics, string $value): array
{
    if (!$metrics->first_order_at) {
        return ['passed' => false, 'reason' => 'No orders'];
    }
    
    $targetDate = Carbon::parse($value)->startOfDay();
    $orderDate = $metrics->first_order_at->startOfDay();
    
    $passed = $orderDate->isSameDay($targetDate);
    
    return [
        'passed' => $passed,
        'type' => EligibilityRuleType::FIRST_ORDER_ON->value,
        'value' => $value,
        'actual' => $orderDate->toDateString(),
        'reason' => $passed ? null : "Order on {$orderDate->toDateString()}, must be on {$targetDate->toDateString()}",
    ];
}

// BETWEEN: start_date <= order_date <= end_date (inclusive on both ends)
private function evalFirstOrderBetween(CustomerMetrics $metrics, array $value): array
{
    if (!$metrics->first_order_at) {
        return ['passed' => false, 'reason' => 'No orders'];
    }
    
    [$start, $end] = $value;
    $startDate = Carbon::parse($start)->startOfDay();
    $endDate = Carbon::parse($end)->endOfDay();
    $orderDate = $metrics->first_order_at;
    
    $passed = $orderDate->between($startDate, $endDate);
    
    return [
        'passed' => $passed,
        'type' => EligibilityRuleType::FIRST_ORDER_BETWEEN->value,
        'value' => $value,
        'actual' => $orderDate->toDateString(),
        'reason' => $passed ? null : "Order on {$orderDate->toDateString()}, must be between {$startDate->toDateString()} and {$endDate->toDateString()}",
    ];
}
```

**NEW RULE TYPES**:
```php
case FIRST_ORDER_ON = 'first_order_on';
case FIRST_ORDER_BETWEEN = 'first_order_between';
case LAST_ORDER_ON = 'last_order_on';
case LAST_ORDER_BETWEEN = 'last_order_between';
```

---

## 4. DATABASE CHANGES

### Migration 1: Add Targeting Composition Modes

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE coupon_targetings 
            MODIFY COLUMN mode ENUM(
                'assignment', 
                'dynamic', 
                'assignment_and_dynamic', 
                'assignment_or_dynamic'
            ) DEFAULT 'assignment'
        ");
    }

    public function down(): void
    {
        // Cannot safely downgrade if new modes are in use
        // Force existing new modes to 'assignment' before dropping
        DB::statement("
            UPDATE coupon_targetings 
            SET mode = 'assignment' 
            WHERE mode IN ('assignment_and_dynamic', 'assignment_or_dynamic')
        ");
        
        DB::statement("
            ALTER TABLE coupon_targetings 
            MODIFY COLUMN mode ENUM('assignment', 'dynamic') DEFAULT 'assignment'
        ");
    }
};
```

### Migration 2: Add Claim Lifecycle States

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon_claims', function (Blueprint $table) {
            // Add status column (default 'active' for existing claims)
            $table->enum('status', ['active', 'expired', 'redeemed'])
                ->default('active')
                ->after('claimed_at');
            
            // Add expiry and redemption timestamps
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->timestamp('redeemed_at')->nullable()->after('expires_at');
            
            // Indexes for status-based queries
            $table->index(['status', 'expires_at'], 'idx_coupon_claims_status_expires');
            $table->index(['user_id', 'status'], 'idx_coupon_claims_user_status');
            $table->index(['coupon_id', 'status'], 'idx_coupon_claims_coupon_status');
        });
        
        // Drop UNIQUE(coupon_id, user_id) to allow historical claims
        Schema::table('coupon_claims', function (Blueprint $table) {
            $table->dropUnique(['coupon_id', 'user_id']);
        });
        
        // CRITICAL: MySQL 8.0.13+ filtered unique index
        // TiDB compatibility: Check if TiDB supports this syntax
        try {
            DB::statement("
                CREATE UNIQUE INDEX idx_coupon_claims_active_unique 
                ON coupon_claims(coupon_id, user_id) 
                WHERE status = 'active'
            ");
        } catch (\Exception $e) {
            // If filtered index not supported, document application-level enforcement
            \Log::warning('Filtered unique index not supported. Must enforce one-active-claim in application layer.');
        }
    }

    public function down(): void
    {
        // Drop filtered index
        try {
            DB::statement("DROP INDEX idx_coupon_claims_active_unique ON coupon_claims");
        } catch (\Exception $e) {
            // Ignore if not exists
        }
        
        Schema::table('coupon_claims', function (Blueprint $table) {
            // Restore UNIQUE constraint (will fail if duplicate historical claims exist)
            $table->unique(['coupon_id', 'user_id']);
            
            // Drop lifecycle columns
            $table->dropIndex('idx_coupon_claims_status_expires');
            $table->dropIndex('idx_coupon_claims_user_status');
            $table->dropIndex('idx_coupon_claims_coupon_status');
            $table->dropColumn(['status', 'expires_at', 'redeemed_at']);
        });
    }
};
```

### Migration 3: Create Snapshot Tables

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['generating', 'completed', 'failed'])->default('generating');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('member_count')->default(0);
            $table->json('rule_tree_snapshot')->nullable();
            $table->timestamps();
            
            $table->index(['coupon_id', 'version'], 'idx_coupon_version');
            $table->index('status');
        });
        
        Schema::create('coupon_snapshot_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('coupon_snapshots')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('eligibility_snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->unique(['snapshot_id', 'user_id'], 'unique_snapshot_user');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_snapshot_members');
        Schema::dropIfExists('coupon_snapshots');
    }
};
```

### Migration 4: Create Notification Idempotency Table

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_eligibility_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at')->useCurrent();
            
            $table->unique(['user_id', 'coupon_id'], 'unique_user_coupon');
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_eligibility_notifications');
    }
};
```

### Migration 5: Add AOV to CustomerMetrics (Optional)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ONLY if TiDB supports GENERATED ALWAYS AS ... STORED
        // Otherwise, calculate AOV at runtime in EligibilityEngine
        
        try {
            DB::statement("
                ALTER TABLE customer_metrics 
                ADD COLUMN avg_order_value DECIMAL(15,2) GENERATED ALWAYS AS (
                    CASE 
                        WHEN completed_orders > 0 
                        THEN total_qualifying_order_value / completed_orders 
                        ELSE 0 
                    END
                ) STORED
            ");
            
            Schema::table('customer_metrics', function (Blueprint $table) {
                $table->index('avg_order_value');
            });
        } catch (\Exception $e) {
            \Log::warning('Generated column not supported. AOV will be calculated at runtime.');
        }
    }

    public function down(): void
    {
        try {
            Schema::table('customer_metrics', function (Blueprint $table) {
                $table->dropIndex(['avg_order_value']);
            });
            
            DB::statement("ALTER TABLE customer_metrics DROP COLUMN avg_order_value");
        } catch (\Exception $e) {
            // Column may not exist if generated column not supported
        }
    }
};
```

---

## 5. CONCURRENCY CORRECTNESS

### 5.1 Claim Concurrency (Phase 1 — CORRECT)

**Mechanism**: Parent-row serialization via `SELECT ... FOR UPDATE` on `coupon_targetings`.

```php
// CouponClaimService::claim()
DB::transaction(function () use ($coupon, $user) {
    // CRITICAL: Serialize all claim attempts for this coupon
    $targeting = CouponTargeting::query()
        ->where('coupon_id', $coupon->getKey())
        ->lockForUpdate()  // Blocks concurrent claims
        ->first();
    
    // Check existing claim
    $existingClaim = CouponClaim::query()
        ->where('coupon_id', $coupon->getKey())
        ->where('user_id', $user->getKey())
        ->first();
    
    if ($existingClaim) {
        throw CouponClaimException::alreadyClaimed();
    }
    
    // Check capacity
    if ($targeting->max_claims !== null) {
        $totalClaims = CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->count();
        
        if ($totalClaims >= $targeting->max_claims) {
            throw CouponClaimException::maxClaimsReached();
        }
    }
    
    // Evaluate eligibility
    $result = $this->eligibilityEngine->evaluate($coupon, $user);
    if (!$result->isEligible) {
        throw CouponClaimException::notEligible();
    }
    
    // Create claim (UNIQUE constraint = final guard)
    $claim = CouponClaim::create([...]);
    
    return $claim;
});
```

**Why This Works**:
1. `FOR UPDATE` on `coupon_targetings` parent row serializes ALL claim attempts for this coupon
2. Within transaction: check existing claim, check capacity, evaluate eligibility, insert
3. `UNIQUE(coupon_id, user_id)` constraint is atomic database-level guard
4. Transaction rollback on any exception = no orphan claims

**Proven Correct**: MultiConnectionLockTest with independent PDO connections verified blocking behavior.

### 5.2 Claim Concurrency with Lifecycle States (Phase 2 — REQUIRES UPDATE)

**New Logic**:

```php
DB::transaction(function () use ($coupon, $user) {
    $targeting = CouponTargeting::query()
        ->where('coupon_id', $coupon->getKey())
        ->lockForUpdate()
        ->first();
    
    // Check for ACTIVE claim only (not expired/redeemed)
    $activeClaim = CouponClaim::query()
        ->where('coupon_id', $coupon->getKey())
        ->where('user_id', $user->getKey())
        ->where('status', 'active')
        ->first();
    
    if ($activeClaim) {
        throw CouponClaimException::alreadyClaimed();
    }
    
    // Count ACTIVE + REDEEMED claims (NOT expired)
    if ($targeting->max_claims !== null) {
        $countingClaims = CouponClaim::query()
            ->where('coupon_id', $coupon->getKey())
            ->whereIn('status', ['active', 'redeemed'])
            ->count();
        
        if ($countingClaims >= $targeting->max_claims) {
            throw CouponClaimException::maxClaimsReached();
        }
    }
    
    // Eligibility evaluation
    $result = $this->eligibilityEngine->evaluate($coupon, $user);
    if (!$result->isEligible) {
        throw CouponClaimException::notEligible();
    }
    
    // Create claim with status='active'
    $claim = CouponClaim::create([
        'coupon_id' => $coupon->getKey(),
        'user_id' => $user->getKey(),
        'claimed_at' => now(),
        'status' => 'active',
        'expires_at' => $this->calculateExpiry($targeting),
        'eligibility_snapshot' => [...],
    ]);
    
    return $claim;
});
```

**Filtered Unique Index OR Application-Level Enforcement**:

If TiDB does NOT support filtered unique indexes:

```php
// BEFORE creating claim, explicitly check active claim count
$activeClaimCount = CouponClaim::query()
    ->where('coupon_id', $coupon->getKey())
    ->where('user_id', $user->getKey())
    ->where('status', 'active')
    ->count();

if ($activeClaimCount > 0) {
    throw CouponClaimException::alreadyClaimed();
}

// Then proceed with insert
```

**Testing Required**: Verify TiDB behavior with partial unique indexes or implement application-level enforcement.

### 5.3 Snapshot Generation Concurrency

**Risk**: Two admin users click "Generate Snapshot" simultaneously.

**Solution**: Database-level lock on coupon_snapshots latest version.

```php
DB::transaction(function () use ($couponId) {
    // Lock latest snapshot for this coupon
    $latestSnapshot = CouponSnapshot::query()
        ->where('coupon_id', $couponId)
        ->orderByDesc('version')
        ->lockForUpdate()
        ->first();
    
    $newVersion = $latestSnapshot ? $latestSnapshot->version + 1 : 1;
    
    // Create new snapshot record
    $snapshot = CouponSnapshot::create([
        'coupon_id' => $couponId,
        'version' => $newVersion,
        'status' => 'generating',
        'started_at' => now(),
    ]);
    
    // Dispatch job
    GenerateCouponSnapshotJob::dispatch($snapshot->id);
    
    return $snapshot;
});
```

**Alternative**: Use unique constraint on (coupon_id, status='generating') to prevent duplicate generation jobs.

### 5.4 Reservation Concurrency (Phase 0 — ALREADY CORRECT)

**Existing Logic**: CouponReservationService uses transactions + unique(order_id) constraint.

**No changes required** for Phase 2.

---

## 6. BACKWARD COMPATIBILITY

### Preserved Behaviors

✅ **Public Coupons** (no assignments, no targeting):
- Still single-use per user via coupon_usages table
- applyCoupon endpoint unchanged

✅ **Assigned Coupons** (has assignments, no targeting):
- CouponAssignment quota enforcement unchanged
- multi-use per user still works

✅ **Claim-Required Coupons** (new Phase 1):
- Must claim before apply
- claim endpoint: POST /api/v1/general/coupons/{id}/claim

✅ **Existing Phase 1 Endpoints**:
- POST /api/v1/general/coupons/apply (unchanged)
- POST /api/v1/general/coupons/{id}/claim (unchanged request/response shape)

### API Contracts

**applyCoupon Endpoint** (UNCHANGED):

```http
POST /api/v1/general/coupons/apply
Content-Type: application/json

{
  "code": "SUMMER2026"
}
```

**Response** (UNCHANGED):

```json
{
  "success": true,
  "message": "Coupon applied successfully",
  "data": {
    "code": "SUMMER2026",
    "discount": 100,
    "discount_type": "fixed_rate"
  }
}
```

**claim Endpoint** (UNCHANGED):

```http
POST /api/v1/general/coupons/{id}/claim
Authorization: Bearer {token}
```

**Response** (UNCHANGED):

```json
{
  "success": true,
  "message": "Coupon claimed successfully",
  "data": {
    "id": 123,
    "coupon_id": 45,
    "user_id": 67,
    "claimed_at": "2026-09-13T10:30:00Z",
    "eligibility_snapshot": {...}
  }
}
```

### Internal Changes (No External Impact)

- CouponClaimService logic updated (lifecycle states)
- EligibilityEngine extended (new rule types)
- New admin endpoints for snapshot management
- New background jobs (snapshot generation, export, notifications)
- New models (CouponSnapshot, CouponSnapshotMember, CouponEligibilityNotification)

---

## 7. TESTING STRATEGY

### Unit Tests

**EligibilityEngine**:
- ✅ Existing: 13 Phase 1 rules
- 🔲 New: Assignment + Dynamic composition (4 modes)
- 🔲 New: Purchase history rules (6 types)
- 🔲 New: Demographic rules (6 types, deferred)
- 🔲 New: AOV rules
- 🔲 New: Date-only semantics (ON, BETWEEN variants)

**CouponClaimService**:
- ✅ Existing: Duplicate claim prevention
- ✅ Existing: First-N capacity enforcement
- 🔲 New: Active claim enforcement (lifecycle states)
- 🔲 New: Expired claim re-claim
- 🔲 New: Capacity counting (active + redeemed only)

### Integration Tests

**Claim Lifecycle**:
- 🔲 User claims coupon → status='active'
- 🔲 Claim expires → status='expired'
- 🔲 Payment success → status='redeemed'
- 🔲 User re-claims after expiry (if eligible) → new active claim created

**Snapshot Generation**:
- 🔲 Generate snapshot → coupon_snapshots + coupon_snapshot_members populated
- 🔲 Regenerate snapshot → new version created
- 🔲 Export snapshot → CSV file generated
- 🔲 Large audience (10,000+ users) → chunked processing

**Dynamic Eligibility Notifications**:
- 🔲 User completes order → becomes eligible → notification sent
- 🔲 Duplicate order event → no duplicate notification (idempotency)
- 🔲 User already notified → no second notification

### Concurrency Tests

**Claim Concurrency**:
- ✅ Existing: MultiConnectionLockTest (FOR UPDATE blocking)
- ✅ Existing: CouponClaimHttpConcurrencyTest (max_claims enforcement)
- 🔲 New: Re-claim after expiry (concurrent attempts)
- 🔲 New: Active claim enforcement (concurrent claims from same user)

**Snapshot Generation**:
- 🔲 Concurrent generation requests → second request waits or fails gracefully

### Runtime Verification (TiDB)

**CRITICAL**:
- 🔲 Verify FOR UPDATE blocking on TiDB with pessimistic transaction mode
- 🔲 Verify filtered unique index support (or implement application-level enforcement)
- 🔲 Verify GENERATED ALWAYS AS STORED support (or use runtime AOV)
- 🔲 Performance test: 100 concurrent claims with max_claims=10
- 🔲 Performance test: Snapshot generation for 10,000 users
- 🔲 Performance test: Purchase history rule evaluation (N+1 prevention)

### Regression Tests

**Preserve Phase 1 Behaviors**:
- ✅ Public coupon single-use enforcement
- ✅ Assignment quota enforcement
- ✅ Claim-required coupon rejection without claim
- ✅ Existing 13 eligibility rules

---

## 8. PERFORMANCE CONSIDERATIONS

### 8.1 Eligibility Evaluation

**Concern**: Evaluating eligibility for every user for every coupon on every order.

**Solution**: Event dependency mapping + selective evaluation.

```php
// Only evaluate coupons affected by this event
OrderCompleted::dispatch($order)
    ↓
EvaluateDynamicCouponEligibility listener
    ↓
$affectedCoupons = Coupon::query()
    ->join('coupon_targetings', 'coupons.id', '=', 'coupon_targetings.coupon_id')
    ->where('coupon_targetings.require_claim', true)
    ->whereIn('coupon_targetings.mode', ['dynamic', 'assignment_or_dynamic'])
    ->where(function ($q) {
        // Has rules that depend on order metrics
        $q->whereJsonContains('coupon_targetings.rule_tree->rules', ['type' => 'min_completed_orders'])
          ->orWhereJsonContains('coupon_targetings.rule_tree->rules', ['type' => 'min_total_spend'])
          // ... other order-dependent rules
    })
    ->get();

// Only evaluate these coupons for this user
foreach ($affectedCoupons as $coupon) {
    $this->evaluateAndNotify($user, $coupon);
}
```

**Estimated Complexity**: O(affected_coupons) per order, NOT O(all_coupons).

### 8.2 Purchase History Queries

**Concern**: N+1 queries for product/category/brand checks.

**Solution**: PurchaseHistoryContext loader (single query).

```php
// BAD: N+1
foreach ($rules as $rule) {
    if ($rule['type'] === 'purchased_product') {
        $hasPurchased = Order::join('order_items', ...)
            ->where('user_id', $userId)
            ->where('product_id', $rule['value'])
            ->exists(); // One query per rule
    }
}

// GOOD: Single query
$history = PurchaseHistoryContext::load($user); // One query
foreach ($rules as $rule) {
    if ($rule['type'] === 'purchased_product') {
        $passed = $history->hasPurchasedProduct($rule['value']); // In-memory check
    }
}
```

**Required Indexes**:
```sql
-- Already exists
CREATE INDEX idx_orders_user_status ON orders(user_id, status, payment_status);
CREATE INDEX idx_order_items_order ON order_items(order_id, product_id);

-- Verify existence
CREATE INDEX idx_products_brand ON products(brand_id);
CREATE INDEX idx_category_product ON category_product(product_id, category_id);
```

### 8.3 Snapshot Generation

**Concern**: Generating snapshot for 100,000+ users.

**Solution**: Chunked queued job.

```php
// GenerateCouponSnapshotJob
public function handle()
{
    $snapshot = CouponSnapshot::find($this->snapshotId);
    $targeting = $snapshot->coupon->targeting;
    
    // Process in chunks
    User::query()
        ->chunk(1000, function ($users) use ($snapshot, $targeting) {
            $eligibleUsers = [];
            
            foreach ($users as $user) {
                $result = $this->eligibilityEngine->evaluate($snapshot->coupon, $user);
                
                if ($result->isEligible) {
                    $eligibleUsers[] = [
                        'snapshot_id' => $snapshot->id,
                        'user_id' => $user->id,
                        'eligibility_snapshot' => json_encode([
                            'passed_rules' => $result->passedRules,
                            'evaluated_metrics' => $result->evaluatedMetrics,
                        ]),
                        'created_at' => now(),
                    ];
                }
            }
            
            if (!empty($eligibleUsers)) {
                CouponSnapshotMember::insert($eligibleUsers);
            }
        });
    
    // Update snapshot status
    $memberCount = CouponSnapshotMember::where('snapshot_id', $snapshot->id)->count();
    $snapshot->update([
        'status' => 'completed',
        'completed_at' => now(),
        'member_count' => $memberCount,
    ]);
}
```

**Estimated Duration**: 100,000 users @ 1000/chunk = 100 chunks @ ~1s/chunk = ~2 minutes.

### 8.4 Notification Idempotency

**Concern**: Checking idempotency table on every eligibility transition.

**Solution**: Single query with INSERT IGNORE or upsert.

```php
try {
    CouponEligibilityNotification::create([
        'user_id' => $userId,
        'coupon_id' => $couponId,
        'notified_at' => now(),
    ]);
    
    // If insert succeeds, send notification
    $user->notify(new CouponNowEligible($coupon));
} catch (\Illuminate\Database\QueryException $e) {
    // UNIQUE constraint violation = already notified, skip
    if ($e->getCode() === '23000') {
        return;
    }
    throw $e;
}
```

**Alternative (MySQL 8+)**:
```php
DB::statement("
    INSERT INTO coupon_eligibility_notifications (user_id, coupon_id, notified_at)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE user_id = user_id
", [$userId, $couponId, now()]);

if (DB::affectedRows() > 0) {
    // First insert, send notification
    $user->notify(new CouponNowEligible($coupon));
}
```

---

## 9. ROLLOUT PLAN

### Phase 2.1: Database Migrations (Safe)

1. Deploy migrations (zero downtime):
   - Add targeting composition modes (backward compatible)
   - Add claim lifecycle columns (default 'active' for existing)
   - Create snapshot tables (new, no impact)
   - Create notification idempotency table (new, no impact)

2. Verify migrations on staging:
   - Run on TiDB staging instance
   - Verify filtered unique index support (or document fallback)
   - Verify generated column support (or skip AOV column)

### Phase 2.2: Core Services (Feature-Flagged)

1. Deploy updated services:
   - EligibilityEngine with new rule types (backward compatible)
   - CouponClaimService with lifecycle logic (backward compatible)
   - PurchaseHistoryContext loader
   - Snapshot generation jobs
   - Notification jobs

2. Feature flag: `enable_phase2_targeting` (default: false)
   - If disabled: Fall back to Phase 1 logic
   - If enabled: Use Phase 2 composition + lifecycle

3. Testing on staging:
   - Create test coupons with new modes
   - Verify eligibility evaluation
   - Verify claim lifecycle transitions
   - Generate test snapshots

### Phase 2.3: Admin UI (Low Risk)

1. Deploy admin endpoints:
   - Snapshot generation
   - Snapshot export
   - Notification logs
   - Eligibility debugger

2. Admin-only access (behind permissions)

### Phase 2.4: Customer-Facing (High Risk)

1. Enable feature flag for small percentage (1% traffic)
2. Monitor:
   - Claim concurrency correctness
   - First-N enforcement accuracy
   - Notification delivery rate
   - Performance metrics

3. Gradual rollout:
   - 1% → 10% → 50% → 100%

4. Rollback plan:
   - Disable feature flag
   - Fall back to Phase 1 logic
   - No data loss (lifecycle states preserved)

### Phase 2.5: Full Production

1. Enable feature flag globally
2. Deprecate Phase 1-only code paths
3. Monitor for 30 days before final cleanup

---

## 10. ROLLBACK PLAN

### Immediate Rollback (Feature Flag)

```php
// config/features.php
return [
    'enable_phase2_targeting' => env('ENABLE_PHASE2_TARGETING', false),
];

// CouponClaimService
if (config('features.enable_phase2_targeting')) {
    // Phase 2 logic (lifecycle states, composition)
} else {
    // Phase 1 logic (original)
}
```

**Impact**: 
- Claims created with status='active' remain in DB
- Future claims fall back to Phase 1 behavior
- No data corruption

### Database Rollback (Last Resort)

**ONLY if migrations cause critical production failure**:

1. Run down migrations in reverse order:
   - Drop notification table
   - Drop snapshot tables
   - Drop claim lifecycle columns (DANGEROUS: loses status data)
   - Restore targeting mode enum

2. Redeploy Phase 1 code

**Risk**: Historical claim data with status field will be lost if lifecycle migration is rolled back.

**Recommendation**: Do NOT rollback database migrations unless absolutely necessary. Use feature flag rollback instead.

---

## 11. RISKS & MITIGATIONS

### Risk 1: TiDB Filtered Unique Index Incompatibility

**Risk**: TiDB may not support `CREATE UNIQUE INDEX ... WHERE status = 'active'`.

**Mitigation**:
- Test on TiDB staging BEFORE production deployment
- If not supported: Use application-level enforcement (explicit COUNT check)
- Document in migration comments

**Fallback**:
```php
// Explicit check before insert
$activeClaimExists = CouponClaim::query()
    ->where('coupon_id', $couponId)
    ->where('user_id', $userId)
    ->where('status', 'active')
    ->exists();

if ($activeClaimExists) {
    throw CouponClaimException::alreadyClaimed();
}

// Proceed with insert (still within FOR UPDATE transaction)
```

### Risk 2: Snapshot Generation Performance

**Risk**: Generating snapshot for 1M+ users could timeout or exhaust memory.

**Mitigation**:
- Use chunked processing (1000 users/batch)
- Queue job with extended timeout
- Monitor job execution time on staging
- Set max_execution_time to 30 minutes

**Fallback**: If generation takes too long, implement incremental snapshots (cache previous evaluation results).

### Risk 3: Notification Flood

**Risk**: OrderCompleted event triggers evaluation for 1000 coupons → 1000 notifications sent.

**Mitigation**:
- Limit notification frequency (max 1 per user per day)
- Batch notifications (daily digest instead of real-time)
- Rate limit notification jobs

**Fallback**: Disable dynamic eligibility notifications via feature flag.

### Risk 4: Claim Lifecycle State Corruption

**Risk**: Manual DB updates or bugs cause invalid state transitions (active → active, redeemed → active).

**Mitigation**:
- Database check constraints (if supported)
- Application-level state machine validation
- Audit logging for state transitions

**Prevention**:
```php
// CouponClaim model
public function expire(): void
{
    if ($this->status !== 'active') {
        throw new \DomainException("Cannot expire claim with status: {$this->status}");
    }
    
    $this->update(['status' => 'expired']);
}

public function redeem(): void
{
    if ($this->status !== 'active') {
        throw new \DomainException("Cannot redeem claim with status: {$this->status}");
    }
    
    $this->update(['status' => 'redeemed', 'redeemed_at' => now()]);
}
```

### Risk 5: Purchase History N+1 Queries

**Risk**: Forgetting to use PurchaseHistoryContext → N+1 queries per eligibility evaluation.

**Mitigation**:
- Enforce PurchaseHistoryContext usage in EligibilityEngine
- Add query count assertions in tests
- Monitor query count in staging

**Detection**:
```php
// In test
DB::enableQueryLog();
$result = $eligibilityEngine->evaluate($coupon, $user);
$queryCount = count(DB::getQueryLog());
$this->assertLessThan(10, $queryCount, 'Eligibility evaluation triggered N+1 queries');
```

---

## 12. FINAL CLOSURE CHECKLIST

### Architecture

- [x] Assignment + Targeting composition defined
- [x] Claim lifecycle state machine designed
- [x] Snapshot audience architecture specified
- [x] Dynamic eligibility notification flow designed
- [x] Purchase history targeting specified
- [x] AOV calculation strategy defined
- [x] Date semantics clarified

### Database

- [x] Migration 1: Targeting composition modes
- [x] Migration 2: Claim lifecycle states
- [x] Migration 3: Snapshot tables
- [x] Migration 4: Notification idempotency
- [x] Migration 5: AOV column (optional)
- [ ] TiDB compatibility verified (BLOCKER: requires staging access)
- [ ] Filtered unique index tested on TiDB
- [ ] Generated column tested on TiDB

### Domain Model

- [x] EligibilityEngine extension designed
- [x] CouponClaimService lifecycle logic designed
- [x] PurchaseHistoryContext specified
- [x] Snapshot generation jobs designed
- [x] Notification jobs designed
- [x] Idempotency mechanism designed

### Concurrency

- [x] Claim concurrency with lifecycle states verified in design
- [x] Snapshot generation concurrency handled
- [x] First-N with lifecycle states corrected
- [ ] Runtime concurrency tests on TiDB (BLOCKER)
- [ ] 100-user stress test on TiDB (BLOCKER)

### Backward Compatibility

- [x] Public coupon flow preserved
- [x] Assigned coupon flow preserved
- [x] applyCoupon endpoint unchanged
- [x] claim endpoint unchanged
- [x] Phase 1 services preserved

### Testing

- [ ] Unit tests for new rule types
- [ ] Unit tests for composition modes
- [ ] Integration tests for claim lifecycle
- [ ] Integration tests for snapshot generation
- [ ] Integration tests for notifications
- [ ] Concurrency tests for lifecycle claims
- [ ] Regression tests for Phase 1 behaviors

### Performance

- [x] Eligibility evaluation optimization (event dependency mapping)
- [x] Purchase history N+1 prevention (PurchaseHistoryContext)
- [x] Snapshot generation chunking
- [x] Notification idempotency optimization
- [ ] Benchmark eligibility evaluation (PENDING)
- [ ] Benchmark snapshot generation for 100k users (PENDING)

### Rollout

- [x] Rollout plan defined
- [x] Feature flag strategy defined
- [x] Rollback plan defined
- [ ] Staging deployment (PENDING)
- [ ] 1% production rollout (PENDING)
- [ ] Full production deployment (PENDING)

---

## 13. BLOCKERS

### BLOCKER 1: TiDB Staging Access (CRITICAL)

**Status**: ⚠️ NO TIDB STAGING CREDENTIALS AVAILABLE

**Required Actions**:
1. Provision TiDB staging database
2. Provide connection credentials
3. Configure `DB_INIT_COMMAND="SET SESSION tidb_txn_mode = 'pessimistic'"`
4. Test filtered unique index support
5. Test generated column support
6. Run concurrency tests (MultiConnectionLockTest)
7. Run 100-user stress test

**Impact**: 
- Cannot verify TiDB compatibility
- Cannot certify production readiness
- Phase 2 implementation blocked until TiDB verified

**Mitigation**: 
- Assume MySQL 8.0.13+ compatibility for filtered indexes
- Document TiDB verification as manual gate before production deployment

### BLOCKER 2: Demographic Targeting Requirements Ambiguity

**Status**: ⚠️ DEFERRED TO IMPLEMENTATION PHASE

**Ambiguity**:
- User profile schema not fully audited
- Registration date field confirmed (users.created_at)
- Country/language/gender fields existence not verified

**Required Actions**:
1. Inspect user_profiles table schema
2. Verify demographic fields exist
3. Define rule types if needed
4. Add to Phase 2 if business priority

**Impact**: 
- Demographic targeting may be incomplete
- Phase 2 may need additional iteration

**Decision**: Implement Phase 2 without demographic rules first, add in Phase 2.1 if required.

---

## 14. IMPLEMENTATION_READY VERDICT

**IMPLEMENTATION_READY: NO**

### Reasons:

1. ⚠️ **TiDB Runtime Verification Blocked**
   - No TiDB staging credentials available
   - Cannot test filtered unique index support
   - Cannot test generated column support
   - Cannot run concurrency tests on TiDB
   - Cannot certify production readiness

2. ⚠️ **Critical Architecture Decisions Require Runtime Validation**
   - Filtered unique index fallback strategy needs testing
   - Generated column performance needs benchmarking
   - Claim lifecycle enforcement needs TiDB verification

3. ⚠️ **Purchase History Performance Unknown**
   - No benchmark data for product/category/brand targeting
   - PurchaseHistoryContext optimization needs validation

### What IS Ready:

✅ Architecture design is complete and sound  
✅ Database schema is correct and backward compatible  
✅ Domain model is clean and maintainable  
✅ Concurrency strategy is proven (Phase 1 tests pass on MySQL)  
✅ Backward compatibility is preserved  
✅ Rollout plan is safe and reversible  

### What Remains:

🔲 TiDB staging environment provisioning  
🔲 TiDB compatibility verification (filtered indexes, generated columns)  
🔲 Runtime concurrency tests on TiDB  
🔲 Performance benchmarks (snapshot generation, purchase history)  
🔲 Implementation of Phase 2 services (EligibilityEngine extension, lifecycle logic)  
🔲 Implementation of snapshot generation and export jobs  
🔲 Implementation of dynamic eligibility notification system  

---

## 15. NEXT STEPS

### Immediate (Pre-Implementation):

1. **Provision TiDB Staging Environment**
   - Obtain credentials from infrastructure team
   - Configure `DB_INIT_COMMAND="SET SESSION tidb_txn_mode = 'pessimistic'"`
   - Test basic connectivity

2. **Verify TiDB Compatibility**
   - Test filtered unique index: `CREATE UNIQUE INDEX ... WHERE status = 'active'`
   - Test generated column: `ALTER TABLE ... ADD COLUMN ... GENERATED ALWAYS AS ... STORED`
   - Document fallback strategies if unsupported

3. **Run Concurrency Tests on TiDB**
   - MultiConnectionLockTest against TiDB
   - Verify FOR UPDATE blocking behavior
   - Verify transaction isolation

4. **Decision Point: Implementation Ready?**
   - If TiDB tests pass → GREEN LIGHT for Phase 2 implementation
   - If TiDB tests fail → Implement fallback strategies, re-test

### Implementation Phase:

1. **Deploy Migrations** (safe, backward compatible)
2. **Implement Core Services** (feature-flagged)
3. **Write Tests** (unit, integration, concurrency)
4. **Staging Validation** (full E2E testing)
5. **Production Rollout** (gradual, feature-flagged)

### Post-Implementation:

1. **Monitor Production Metrics**
   - Claim concurrency correctness
   - First-N enforcement accuracy
   - Notification delivery rate
   - Performance benchmarks

2. **Iterate on Feedback**
   - Adjust notification frequency if needed
   - Optimize snapshot generation if slow
   - Add demographic targeting if business requires

---

## CONCLUSION

The Coupon Phase 2 architecture is **WELL-DESIGNED BUT NOT YET IMPLEMENTATION-READY** due to **external TiDB staging access blocker**.

The architecture audit identified **7 critical gaps** from the original Phase 2 proposal and provided **corrected solutions** for each:

1. ✅ Assignment + Targeting composition → 4-mode enum
2. ✅ Claim lifecycle → active/expired/redeemed state machine
3. ✅ Snapshot audience → versioned materialization with export
4. ✅ Dynamic eligibility notifications → event-driven + idempotent
5. ✅ Purchase history targeting → PurchaseHistoryContext optimization
6. ✅ AOV calculation → runtime or generated column
7. ✅ Date semantics → DATE-ONLY with explicit boundaries

All designs are **concurrency-safe**, **backward-compatible**, and **production-grade**.

The implementation is **blocked ONLY by TiDB staging environment provisioning**, which is an **external infrastructure dependency**, not an architecture issue.

**Recommendation**: Provision TiDB staging, verify compatibility, then proceed with implementation using this plan as the definitive specification.

---

**Document Version**: 1.0  
**Last Updated**: 2026-09-13  
**Status**: ARCHITECTURE AUDIT COMPLETE — AWAITING TIDB ACCESS FOR FINAL CERTIFICATION
