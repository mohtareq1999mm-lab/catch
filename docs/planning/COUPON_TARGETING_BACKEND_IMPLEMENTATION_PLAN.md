# COUPON TARGETING & ELIGIBILITY ENGINE
## BACKEND IMPLEMENTATION PLAN

**Version:** 1.0.0  
**Date:** 2026-09-08  
**Audience:** Backend Developers

---

## IMPLEMENTATION PHASES

This document provides the complete implementation sequence with dependencies, affected components, and testing requirements for each phase.

---

## PHASE 0: PREPARATION (READ-ONLY)

**Duration:** Complete (this document)

**Activities:**
- ✅ Repository discovery
- ✅ Architecture validation
- ✅ Business contract finalization
- ✅ API contract definition
- ✅ Frontend contract definition

**Output:** Architecture documents (this file + related contracts)

---

## PHASE 1: DATABASE SCHEMA

**Duration:** 2 days

**Dependencies:** None (can start immediately after approval)

**Files to Create:**

### Migration 1: `coupon_targetings` table

```
database/migrations/2026_09_09_000001_create_coupon_targetings_table.php
```

**Schema:**
```php
Schema::create('coupon_targetings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('coupon_id')->constrained('coupons')->onDelete('cascade');
    $table->enum('targeting_mode', ['none', 'assigned_only', 'dynamic_rules', 'hybrid'])
        ->default('none');
    $table->json('rules')->nullable();
    $table->unsignedInteger('max_claims')->nullable();
    $table->timestamps();
    
    $table->unique('coupon_id');
    $table->index('targeting_mode');
});
```

### Migration 2: `coupon_claims` table

```
database/migrations/2026_09_09_000002_create_coupon_claims_table.php
```

**Schema:**
```php
Schema::create('coupon_claims', function (Blueprint $table) {
    $table->id();
    $table->foreignId('coupon_id')->constrained('coupons')->onDelete('cascade');
    $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
    $table->timestamp('claimed_at');
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
    
    $table->unique(['coupon_id', 'user_id']);
    $table->index(['coupon_id', 'claimed_at']);
    $table->index('expires_at');
});
```

### Migration 3: `customer_metrics` table

```
database/migrations/2026_09_09_000003_create_customer_metrics_table.php
```

**Schema:**
```php
Schema::create('customer_metrics', function (Blueprint $table) {
    $table->foreignId('user_id')->primary()->constrained('users')->onDelete('cascade');
    $table->decimal('gross_spend', 10, 2)->default(0);
    $table->decimal('total_paid', 10, 2)->default(0);
    $table->decimal('total_refunded', 10, 2)->default(0);
    $table->decimal('net_spend', 10, 2)->default(0);
    $table->unsignedInteger('completed_orders')->default(0);
    $table->unsignedInteger('successful_payments')->default(0);
    $table->timestamp('first_order_at')->nullable();
    $table->timestamp('last_order_at')->nullable();
    $table->unsignedInteger('total_coupons_used')->default(0);
    $table->decimal('total_refund_amount', 10, 2)->default(0);
    $table->timestamps();
    
    $table->index('net_spend');
    $table->index('completed_orders');
    $table->index('successful_payments');
    $table->index('updated_at');
});
```

**Testing:**
- Run migrations on dev/staging
- Verify foreign key constraints work
- Verify indexes created
- Test rollback

**Output:** Empty tables ready for data

---

## PHASE 2: DOMAIN MODELS

**Duration:** 1 day

**Dependencies:** Phase 1 complete

**Files to Create:**

### 1. `app/Models/CouponTargeting.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Coupon;

class CouponTargeting extends Model
{
    protected $fillable = [
        'coupon_id',
        'targeting_mode',
        'rules',
        'max_claims',
    ];
    
    protected $casts = [
        'rules' => 'array',
        'max_claims' => 'integer',
    ];
    
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
```

### 2. `app/Models/CouponClaim.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\User;

class CouponClaim extends Model
{
    protected $fillable = [
        'coupon_id',
        'user_id',
        'claimed_at',
        'expires_at',
    ];
    
    protected $casts = [
        'claimed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
    
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
```

### 3. `app/Models/CustomerMetrics.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class CustomerMetrics extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    
    protected $fillable = [
        'user_id',
        'gross_spend',
        'total_paid',
        'total_refunded',
        'net_spend',
        'completed_orders',
        'successful_payments',
        'first_order_at',
        'last_order_at',
        'total_coupons_used',
        'total_refund_amount',
    ];
    
    protected $casts = [
        'gross_spend' => 'decimal:2',
        'total_paid' => 'decimal:2',
        'total_refunded' => 'decimal:2',
        'net_spend' => 'decimal:2',
        'completed_orders' => 'integer',
        'successful_payments' => 'integer',
        'total_coupons_used' => 'integer',
        'total_refund_amount' => 'decimal:2',
        'first_order_at' => 'datetime',
        'last_order_at' => 'datetime',
    ];
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

### 4. Add relationships to `Coupon` model

**File:** `packages/marvel/src/Database/Models/Coupon.php`

**Add methods:**
```php
public function targeting(): HasOne
{
    return $this->hasOne(\App\Models\CouponTargeting::class);
}

public function claims(): HasMany
{
    return $this->hasMany(\App\Models\CouponClaim::class);
}
```

### 5. Add relationship to `User` model

**File:** `packages/marvel/src/Database/Models/User.php`

**Add method:**
```php
public function metrics(): HasOne
{
    return $this->hasOne(\App\Models\CustomerMetrics::class, 'user_id');
}

public function couponClaims(): HasMany
{
    return $this->hasMany(\App\Models\CouponClaim::class);
}
```

**Testing:**
- Create test records
- Verify relationships work
- Test cascading deletes
- Verify casts work correctly

**Output:** Models with relationships

---

## PHASE 3: RULE ENGINE FOUNDATION

**Duration:** 3 days

**Dependencies:** Phase 2 complete

**Files to Create:**

### 1. Contracts

```
app/Services/Eligibility/Contracts/EligibilityRule.php
app/Services/Eligibility/Contracts/EligibilityEngine.php
```

### 2. Core Services

```
app/Services/Eligibility/EligibilityEngineService.php
app/Services/Eligibility/RuleEvaluator.php
app/Services/Eligibility/RuleRegistry.php
app/Services/Eligibility/EligibilityContext.php
app/Services/Eligibility/EligibilityResult.php
```

### 3. Validation

```
app/Services/Eligibility/RuleTreeValidator.php
```

**Testing:**
- Unit test RuleRegistry
- Unit test RuleTreeValidator (depth limits, count limits)
- Unit test boolean evaluation (all/any/not)
- Test empty rules
- Test invalid rules

**Output:** Working rule evaluation framework (no concrete rules yet)

---

## PHASE 4: CONCRETE RULES (Priority 1)

**Duration:** 3 days

**Dependencies:** Phase 3 complete

**Files to Create:**

```
app/Services/Eligibility/Rules/SpendThresholdRule.php
app/Services/Eligibility/Rules/OrderCountRule.php
app/Services/Eligibility/Rules/FirstOrderRule.php
app/Services/Eligibility/Rules/ReturningCustomerRule.php
app/Services/Eligibility/Rules/AccountAgeRule.php
app/Services/Eligibility/Rules/EmailVerifiedRule.php
```

**Testing (per rule):**
- Unit test with mock context
- Integration test with real CustomerMetrics
- Test all operators (>=, >, <, <=, =)
- Test edge cases (null values, zero values)

**Output:** 6 core rules working

---

## PHASE 5: CONCRETE RULES (Priority 2)

**Duration:** 3 days

**Dependencies:** Phase 4 complete

**Files to Create:**

```
app/Services/Eligibility/Rules/ProductPurchaseHistoryRule.php
app/Services/Eligibility/Rules/NeverPurchasedProductRule.php
app/Services/Eligibility/Rules/CurrentGovernorateRule.php
app/Services/Eligibility/Rules/OrderFromGovernorateRule.php
app/Services/Eligibility/Rules/CouponUsageCountRule.php
app/Services/Eligibility/Rules/NeverUsedCouponRule.php
app/Services/Eligibility/Rules/PaymentMethodHistoryRule.php
```

**Testing:** Same as Phase 4

**Output:** All 13 rules implemented

---

## PHASE 6: CUSTOMER METRICS PROJECTION

**Duration:** 2 days

**Dependencies:** Phase 2 complete (can run parallel to Phase 3-5)

**Files to Create:**

### 1. Job

```
app/Jobs/UpdateCustomerMetricsJob.php
```

**Logic:**
```php
public function handle(int $userId): void
{
    $metrics = CustomerMetrics::firstOrCreate(['user_id' => $userId]);
    
    // Recalculate from source of truth
    $completedOrders = Order::where('user_id', $userId)
        ->where('status', 'completed')
        ->where('payment_status', 'payment-success')
        ->get();
    
    $metrics->gross_spend = $completedOrders->sum('total_price');
    $metrics->total_paid = $completedOrders->sum('total_price');
    $metrics->completed_orders = $completedOrders->count();
    $metrics->successful_payments = $completedOrders->count();
    $metrics->first_order_at = $completedOrders->min('completed_at');
    $metrics->last_order_at = $completedOrders->max('completed_at');
    
    $approvedRefunds = Refund::where('customer_id', $userId)
        ->where('status', 'approved')
        ->get();
    
    $metrics->total_refunded = $approvedRefunds->sum('amount');
    $metrics->total_refund_amount = $approvedRefunds->sum('amount');
    $metrics->net_spend = $metrics->total_paid - $metrics->total_refunded;
    
    $metrics->total_coupons_used = CouponUsage::where('user_id', $userId)
        ->distinct('coupon_id')
        ->count();
    
    $metrics->save();
}
```

### 2. Event Listeners

```
app/Listeners/UpdateMetricsOnOrderCompleted.php
app/Listeners/UpdateMetricsOnRefundApproved.php
app/Listeners/UpdateMetricsOnCouponConsumed.php
```

### 3. Register Listeners

**File:** `app/Providers/EventServiceProvider.php`

```php
protected $listen = [
    OrderStatusChanged::class => [
        UpdateMetricsOnOrderCompleted::class,
    ],
    RefundApproved::class => [
        UpdateMetricsOnRefundApproved::class,
    ],
    AssignedCouponConsumed::class => [
        UpdateMetricsOnCouponConsumed::class,
    ],
];
```

### 4. Backfill Command

```
app/Console/Commands/BackfillCustomerMetrics.php
```

**Usage:**
```bash
php artisan customer-metrics:backfill --batch-size=1000 --sleep-ms=100
```

**Testing:**
- Test job with sample user data
- Test event listeners trigger jobs
- Test backfill command (small dataset)
- Verify metrics accuracy vs source of truth

**Output:** CustomerMetrics table populated and kept current

---

## PHASE 7: CLAIM SERVICE

**Duration:** 2 days

**Dependencies:** Phase 2 complete

**Files to Create:**

```
app/Services/Coupon/ClaimService.php
app/Exceptions/CouponClaimLimitReachedException.php
app/Exceptions/CouponNotClaimableException.php
```

**Logic:** See section 7.2 of architecture document

**Testing:**
- Test claim success
- Test claim limit reached
- Test duplicate claim (idempotent)
- Test concurrent claims (100 threads claiming last slot)
- Test expired claim filtering

**Output:** Working claim service with concurrency safety

---

## PHASE 8: ELIGIBILITY ENGINE INTEGRATION

**Duration:** 2 days

**Dependencies:** Phase 3, 4, 5 complete

**Files to Modify:**

### 1. `app/Services/Coupon/CouponOrchestrator.php`

**Add eligibility check BEFORE existing validation:**

```php
public static function validate(Coupon $coupon, ?User $user = null, ?Collection $items = null): array
{
    // NEW: Eligibility check
    if ($user) {
        $targeting = CouponTargeting::where('coupon_id', $coupon->id)->first();
        
        if ($targeting && $targeting->targeting_mode !== 'none') {
            $eligibilityEngine = app(EligibilityEngine::class);
            $metrics = CustomerMetrics::find($user->id);
            
            if (!$metrics) {
                // No metrics yet, user has no order history
                $metrics = new CustomerMetrics(['user_id' => $user->id]);
            }
            
            $context = new EligibilityContext($user, $metrics, $items);
            $result = $eligibilityEngine->evaluate($coupon, $user, $context);
            
            if (!$result->eligible) {
                return [
                    'valid' => false,
                    'reason' => $result->reason,
                    'message' => $result->message,
                    'coupon' => null,
                ];
            }
        }
    }
    
    // EXISTING: Assignment validation (unchanged)
    // ...
}
```

**Testing:**
- Test eligibility pass → proceeds to assignment check
- Test eligibility fail → returns error, skips assignment check
- Test no targeting → skips eligibility, existing flow
- Test guest user → no eligibility check (or guest-specific rules)

**Output:** Eligibility integrated into validation pipeline

---

## PHASE 9: CLAIM REQUIREMENT CHECK

**Duration:** 1 day

**Dependencies:** Phase 7, 8 complete

**Files to Modify:**

### 1. `app/Services/Coupon/CouponOrchestrator.php`

**Add claim check AFTER eligibility:**

```php
// After eligibility check, before assignment check:
if ($targeting && $targeting->max_claims) {
    $hasClaim = CouponClaim::where('coupon_id', $coupon->id)
        ->where('user_id', $user->id)
        ->where('expires_at', '>', now())
        ->exists();
    
    if (!$hasClaim) {
        return [
            'valid' => false,
            'reason' => 'not_claimed',
            'message' => __('coupon.must_claim_first'),
            'coupon' => null,
        ];
    }
}
```

**Testing:**
- Test limited coupon without claim → rejected
- Test limited coupon with claim → proceeds
- Test unlimited coupon → no claim check

**Output:** Claim requirement enforced

---

## PHASE 10: API ENDPOINTS

**Duration:** 3 days

**Dependencies:** Phase 8, 9 complete

**Files to Create/Modify:**

### 1. Claim Endpoint (NEW)

```
app/Http/Controllers/Api/General/CouponController.php::claim()
```

**Route:**
```php
Route::post('/general/coupons/{coupon}/claim', [CouponController::class, 'claim'])
    ->middleware('auth:sanctum');
```

**Controller:**
```php
public function claim(Coupon $coupon): JsonResponse
{
    $user = auth()->user();
    
    try {
        $claim = app(ClaimService::class)->claim($coupon, $user);
        
        return response()->json([
            'success' => true,
            'message' => __('coupon.claimed_successfully'),
            'data' => [
                'claim_id' => $claim->id,
                'coupon_id' => $claim->coupon_id,
                'claimed_at' => $claim->claimed_at,
                'expires_at' => $claim->expires_at,
            ],
        ]);
    } catch (CouponClaimLimitReachedException $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'errors' => ['coupon' => [$e->getMessage()]],
        ], 422);
    }
}
```

### 2. List Endpoint (MODIFY)

```
app/Http/Controllers/Api/General/CouponController.php::index()
```

**Add eligibility filtering:**

```php
public function index(Request $request): JsonResponse
{
    $user = auth()->user();
    
    $coupons = Coupon::with('targeting')->valid()->get();
    
    if ($user) {
        $metrics = CustomerMetrics::find($user->id);
        $context = new EligibilityContext($user, $metrics ?? new CustomerMetrics(['user_id' => $user->id]));
        $eligibilityEngine = app(EligibilityEngine::class);
        
        $coupons = $coupons->filter(function ($coupon) use ($user, $context, $eligibilityEngine) {
            if (!$coupon->targeting || $coupon->targeting->targeting_mode === 'none') {
                return true; // No targeting, show to all
            }
            
            $result = $eligibilityEngine->evaluate($coupon, $user, $context);
            return $result->eligible;
        });
    }
    
    return response()->json([
        'data' => CouponResource::collection($coupons),
    ]);
}
```

### 3. Resource (MODIFY)

```
app/Http/Resources/Coupons/CouponResource.php
```

**Add targeting fields:**

```php
public function toArray($request): array
{
    $user = auth()->user();
    
    return [
        // ... existing fields
        'targeting' => $this->when($this->targeting, function () use ($user) {
            $targeting = $this->targeting;
            
            $claimsRemaining = null;
            $userClaimed = false;
            
            if ($targeting->max_claims) {
                $totalClaims = CouponClaim::where('coupon_id', $this->id)
                    ->where('expires_at', '>', now())
                    ->count();
                    
                $claimsRemaining = max(0, $targeting->max_claims - $totalClaims);
                
                if ($user) {
                    $userClaimed = CouponClaim::where('coupon_id', $this->id)
                        ->where('user_id', $user->id)
                        ->where('expires_at', '>', now())
                        ->exists();
                }
            }
            
            return [
                'mode' => $targeting->targeting_mode,
                'max_claims' => $targeting->max_claims,
                'claims_remaining' => $claimsRemaining,
                'user_eligible' => true, // Already filtered in controller
                'user_claimed' => $userClaimed,
            ];
        }),
    ];
}
```

**Testing:**
- Test claim endpoint success
- Test claim endpoint errors
- Test list endpoint filtering
- Test resource fields
- Test auth requirements

**Output:** Working API endpoints

---

## PHASE 11: ADMIN API (Optional)

**Duration:** 2 days

**Dependencies:** Phase 10 complete

**Files to Create:**

```
packages/marvel/src/Http/Controllers/CouponTargetingController.php
packages/marvel/src/Http/Requests/CouponTargetingRequest.php
packages/marvel/src/Http/Resources/CouponTargetingResource.php
```

**Endpoints:**
```
POST /api/v1/coupons/{coupon}/targeting
PUT  /api/v1/coupons/{coupon}/targeting
GET  /api/v1/coupons/{coupon}/targeting
DELETE /api/v1/coupons/{coupon}/targeting
POST /api/v1/coupons/{coupon}/targeting/preview
```

**Testing:**
- Test CRUD operations
- Test rule validation
- Test preview endpoint

**Output:** Admin can configure targeting

---

## PHASE 12: COMPREHENSIVE TESTING

**Duration:** 3 days

**Dependencies:** All phases complete

**Test Categories:**

### 1. Unit Tests

```
tests/Unit/Eligibility/RuleRegistryTest.php
tests/Unit/Eligibility/RuleEvaluatorTest.php
tests/Unit/Eligibility/Rules/*Test.php (one per rule)
tests/Unit/Eligibility/RuleTreeValidatorTest.php
```

### 2. Integration Tests

```
tests/Feature/Coupon/EligibilityIntegrationTest.php
tests/Feature/Coupon/ClaimConcurrencyTest.php
tests/Feature/Coupon/TargetingApiTest.php
```

### 3. Regression Tests

**CRITICAL:** Run existing coupon test suite:

```
tests/Feature/CouponSystemTest.php
tests/Feature/CouponsProductionHardenTest.php
tests/Feature/AssignedCouponSystemTest.php
tests/Unit/CouponCalculatorTest.php
tests/Unit/CouponValidatorTest.php
```

**All must pass.**

**Output:** 100% test coverage, all regressions pass

---

## PHASE 13: ROLLOUT

**Duration:** 2 weeks

**Strategy:**

### Week 1: Internal Testing

- Deploy to staging
- Backfill CustomerMetrics (production data snapshot)
- Internal QA testing
- Performance testing

### Week 2: Gradual Rollout

**Day 1-2:** Feature flag OFF (shadow mode)
- Eligibility evaluated but not enforced
- Log results vs old behavior
- Monitor performance

**Day 3-4:** Feature flag 10%
- 10% of coupons use targeting
- Monitor errors, performance
- Compare eligibility decisions

**Day 5-6:** Feature flag 50%

**Day 7:** Feature flag 100%

**Monitoring:**
- Eligibility evaluation duration
- Rule evaluation failures
- Claim API errors
- CustomerMetrics lag

---

## ROLLBACK PLAN

**If issues detected:**

### Level 1: Disable Targeting (Feature Flag)

```php
// config/features.php
'coupon_targeting_enabled' => false,
```

**Effect:** All coupons treated as `targeting_mode='none'`, existing behavior restored.

### Level 2: Revert Code Deployment

Redeploy previous version.

### Level 3: Database Rollback (Last Resort)

```bash
php artisan migrate:rollback --step=3
```

**Effect:** Drops new tables, no data loss in existing tables.

---

## ESTIMATED TIMELINE

| Phase | Duration | Can Start After | Team Size |
|-------|----------|----------------|-----------|
| 0. Preparation | Complete | - | 1 |
| 1. Schema | 2 days | Approval | 1 |
| 2. Models | 1 day | Phase 1 | 1 |
| 3. Rule Engine | 3 days | Phase 2 | 2 |
| 4. Core Rules | 3 days | Phase 3 | 2 |
| 5. Advanced Rules | 3 days | Phase 4 | 2 |
| 6. Metrics (parallel) | 2 days | Phase 2 | 1 |
| 7. Claim Service | 2 days | Phase 2 | 1 |
| 8. Integration | 2 days | Phase 3-5 | 1 |
| 9. Claim Check | 1 day | Phase 7-8 | 1 |
| 10. API | 3 days | Phase 8-9 | 2 |
| 11. Admin API | 2 days | Phase 10 | 1 |
| 12. Testing | 3 days | Phase 11 | 3 |
| 13. Rollout | 2 weeks | Phase 12 | 2 |

**Total:** ~8 weeks (with 2-3 person team, some parallelization)

---

## SUCCESS CRITERIA

✅ All existing coupon tests pass  
✅ 100% test coverage for new code  
✅ CustomerMetrics accuracy validated  
✅ Claim concurrency test passes (100 threads)  
✅ Performance: Coupon list < 500ms (50 coupons)  
✅ Performance: Eligibility evaluation < 100ms  
✅ Zero production errors after 1 week at 100%  
✅ Frontend integration complete  
✅ Admin can configure targeting via UI  

---

## VERSION HISTORY

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2026-09-08 | Initial implementation plan |

