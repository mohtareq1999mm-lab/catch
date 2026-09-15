# COUPON PHASE 2 — FINAL CLOSURE VERIFICATION

**Date:** 2026-09-15  
**Repository:** Catch (D:\work\meem)  
**Audit Mode:** STRICT INDEPENDENT VERIFICATION  
**Status:** ⚠️ **BLOCKED — CRITICAL DEFECTS FOUND**

---

## EXECUTIVE SUMMARY

All four Phase 2 fixes were implemented and tests pass locally. However, **independent verification reveals critical architectural defects** that would cause production failures:

1. **Eligibility Engine Rule Mismatch** — NOT_CLAIMED/CLAIMED rules don't respect claim lifecycle states
2. **Inconsistent Eligibility Logic** — Different logic in CouponClaimService vs EligibilityEngine
3. **Ambiguous Business Semantics** — One-use-per-user rule conflicts with re-claim-after-redemption behavior
4. **Lifecycle Validation Gap** — Expired claims still block new claims in eligibility evaluation

**Tests pass because they bypass eligibility rules.** Production coupons with NOT_CLAIMED rules would prevent re-claiming even after redemption.

---

## 1. CRITICAL ISSUE — ELIGIBILITY ENGINE RULE DEFECT

### The Problem

**File:** `app/Services/Coupon/Eligibility/EligibilityEngine.php` (lines 354-386)

The NOT_CLAIMED and CLAIMED rules check for ANY claim history:

```php
private function evalNotClaimed(Coupon $coupon, User $user): array
{
    $hasClaim = CouponClaim::query()
        ->where('coupon_id', $coupon->getKey())
        ->where('user_id', $user->getKey())
        ->exists();  // ❌ NO status filter, NO expiry check
    
    $passed = !$hasClaim;  // Blocks if ANY claim exists
}

private function evalClaimed(Coupon $coupon, User $user): array
{
    $hasClaim = CouponClaim::query()
        ->where('coupon_id', $coupon->getKey())
        ->where('user_id', $user->getKey())
        ->exists();  // ❌ NO status filter, NO expiry check
    
    $passed = $hasClaim;  // Passes if ANY claim exists
}
```

**Comparison: CouponClaimService uses different logic**

**File:** `app/Services/Coupon/CouponClaimService.php` (lines 56-65)

```php
$existingActiveClaim = CouponClaim::query()
    ->where('coupon_id', $coupon->getKey())
    ->where('user_id', $user->getKey())
    ->where('status', CouponClaimStatus::ACTIVE)  // ✅ Filters by status
    ->where(function ($q) {
        $q->whereNull('expires_at')
          ->orWhere('expires_at', '>', now());  // ✅ Filters by expiry
    })
    ->exists();
```

### Evidence

```
File: app/Services/Coupon/Eligibility/EligibilityEngine.php
Lines 356-359: hasClaim = CouponClaim::query()
                          ->where('coupon_id', ...)
                          ->where('user_id', ...)
                          ->exists()

Result: ❌ DEFECT CONFIRMED
- No status filter → counts ACTIVE, EXPIRED, REDEEMED
- No expiry check → counts logically expired claims
- Inconsistent with CouponClaimService logic
```

### Impact

**Scenario: User tries to claim twice**

```
Coupon with rule: NOT_CLAIMED

T1: User claims → ACTIVE claim created
T2: User redeems → status = REDEEMED
T3: User tries to claim again
    - CouponClaimService: ✅ Allows (checks only ACTIVE)
    - EligibilityEngine: ❌ Rejects (ANY claim exists, including REDEEMED)
```

**Result:** Eligibility engine fails the NOT_CLAIMED rule even though Phase 2 allows re-claiming after redemption.

**Classification:** ❌ **IMPLEMENTATION DEFECT** — Breaks Phase 2 re-claim semantics

---

## 2. FIRST-N SEMANTICS — VERIFIED CORRECT

### The Decision

From `COUPON_PHASE2_FINAL_IMPLEMENTATION_PLAN.md` (lines 148-150):

```text
Phase 2: First N active + redeemed claims (expired claims release capacity)

Business Decision: Expired claims RELEASE capacity

Count Formula: active + redeemed (NOT expired)
```

**Model Selected: Active Capacity (Model B)**
- ACTIVE claims count toward capacity
- REDEEMED claims count toward capacity (historical winners)
- EXPIRED claims release capacity (can be reclaimed)

### Verification

**File:** `app/Services/Coupon/CouponClaimService.php` (lines 73-81)

```php
if ($targeting->max_claims !== null) {
    $occupiedSlots = CouponClaim::query()
        ->where('coupon_id', $coupon->getKey())
        ->whereIn('status', [
            CouponClaimStatus::ACTIVE,
            CouponClaimStatus::REDEEMED,
        ])
        ->count();

    if ($occupiedSlots >= $targeting->max_claims) {
        throw CouponClaimException::maxClaimsReached(...);
    }
}
```

**Result:** ✅ **VERIFIED CORRECT**
- Counts ACTIVE + REDEEMED only
- EXPIRED excluded, releasing capacity
- Matches specification

### Test Evidence

**File:** `tests/Feature/Coupon/CouponClaimLifecycleTest.php`

- Line 276-298: `expired_claims_release_capacity` — ✅ PASSES
- Line 234-251: `capacity_respects_first_n_semantics_active_plus_redeemed` — ✅ PASSES
- Line 253-273: `capacity_respects_redeemed_as_occupied` — ✅ PASSES

---

## 3. ONE-ACTIVE-CLAIM INVARIANT — VERIFIED SAFE

### Transaction Safety

**File:** `app/Services/Coupon/CouponClaimService.php` (lines 36-45)

```php
return DB::transaction(function () use ($coupon, $user) {
    // CRITICAL: Acquire parent-row lock on CouponTargeting
    $targeting = CouponTargeting::query()
        ->where('coupon_id', $coupon->getKey())
        ->lockForUpdate()  // ✅ Parent-row lock
        ->first();

    if (!$targeting) {
        throw CouponClaimException::noTargeting($coupon->getKey());
    }
```

**Serialization Proof**

All claim attempts for the same coupon serialize on the CouponTargeting parent row lock:

```text
T1: claim(coupon_A, user_X)
    → lockForUpdate(coupon_A)
    → check existing active claim
    → check capacity
    → create claim
    → commit

T2: claim(coupon_A, user_X)  [concurrent]
    → lockForUpdate(coupon_A)  [WAITS for T1 to release]
    → after T1 commits, check existing active claim
    → finds claim from T1
    → throws alreadyClaimed
```

**Result:** ✅ **VERIFIED CORRECT**
- One-active-claim invariant enforced via serialization
- No race condition risk
- Proven in Phase 1 (MultiConnectionLockTest)

### One-Active Claim Check

**File:** `app/Services/Coupon/CouponClaimService.php` (lines 57-65)

```php
$existingActiveClaim = CouponClaim::query()
    ->where('coupon_id', $coupon->getKey())
    ->where('user_id', $user->getKey())
    ->where('status', CouponClaimStatus::ACTIVE)
    ->where(function ($q) {
        $q->whereNull('expires_at')
          ->orWhere('expires_at', '>', now());
    })
    ->exists();

if ($existingActiveClaim) {
    throw CouponClaimException::alreadyClaimed(...);
}
```

**Result:** ✅ **VERIFIED CORRECT**
- Checks status = ACTIVE
- Checks expires_at not expired
- Allows re-claim after EXPIRED or REDEEMED

---

## 4. EXPIRY SEMANTICS — VERIFIED CORRECT

### Canonical Definition

An ACTIVE claim is usable if:
- `status = ACTIVE`
- `expires_at IS NULL` (unlimited TTL) OR `expires_at > now()`

**Evidence**

**File:** `app/Services/Coupon/CouponClaimService.php`

1. **hasClaimed() — line 131-140:**
```php
->where('status', CouponClaimStatus::ACTIVE)
->where(function ($q) {
    $q->whereNull('expires_at')
      ->orWhere('expires_at', '>', now());
})
```

2. **getClaim() — line 149-158:**
```php
->where('status', CouponClaimStatus::ACTIVE)
->where(function ($q) {
    $q->whereNull('expires_at')
      ->orWhere('expires_at', '>', now());
})
```

3. **expireExpiredClaims() — line 185-191:**
```php
->where('status', CouponClaimStatus::ACTIVE)
->where('expires_at', '<=', now())
->whereNotNull('expires_at')  // ✅ Preserves NULL = unlimited
->update(['status' => CouponClaimStatus::EXPIRED])
```

**Test Evidence**

- Line 213-227: `expireExpiredClaims_ignores_null_expires_at` — ✅ PASSES
- Line 176-187: `hasClaimed_returns_false_for_expired_claims` — ✅ PASSES
- Line 159-173: `expired_claims_do_not_block_new_claims` — ✅ PASSES

**Result:** ✅ **VERIFIED CORRECT**
- NULL expires_at = unlimited TTL (never expires)
- Expired claims don't block new claims
- Consistent across all methods

---

## 5. SCHEDULER VERIFICATION

### Command Existence

**File:** `app/Console/Commands/ExpireCouponClaims.php`

```php
class ExpireCouponClaims extends Command
{
    protected $signature = 'coupons:expire-claims';
    protected $description = 'Expire coupon claims past their TTL';

    public function handle(CouponClaimService $service): int
    {
        $count = $service->expireExpiredClaims();
        $this->info("Expired $count claims");
        return self::SUCCESS;
    }
}
```

**Result:** ✅ **Command exists and correctly wired**

### Scheduling

**File:** `app/Console/Kernel.php` (line 22)

```php
$schedule->command('coupons:expire-claims')->hourly()->withoutOverlapping();
```

**Result:** ✅ **Scheduled hourly with overlap prevention**

### Scheduler Not Required for Correctness

The scheduler is NOT required for logical expiry correctness:

- Expired claims don't block new claims (checked in `CouponClaimService::claim()` at line 63)
- hasClaimed() returns false for expired claims (checked at line 137)
- getClaim() returns null for expired claims (checked at line 155)

**Result:** ✅ **Scheduler is optimization only, not correctness requirement**

---

## 6. PAYMENT → REDEMPTION INTEGRATION

### Event Timing

**File:** `app/Events/PaymentSucceeded.php` (line 16)

```php
class PaymentSucceeded implements ShouldDispatchAfterCommit
```

**Evidence:** Event fires AFTER transaction commits

### Dispatch Point

**File:** `app/Http/Controllers/Api/General/OrderController.php` (line 404)

```php
DB::transaction(function () use ($lockedOrder, ...) {
    $this->orderReservationService->commit($lockedOrder);
    $this->orderService->finalizePromotionUsageAfterPayment($lockedOrder);
    $this->orderService->changeOrderStatus($lockedTransaction->invoice_id, 'completed', null, false);
    $processed = true;
});

if ($processed) {
    try {
        event(new PaymentSucceeded($order->fresh()));  // ✅ AFTER commit
    } catch (\Throwable $e) {
        report($e);
    }
}
```

**Result:** ✅ **Event fires after transaction commits**

### Listener

**File:** `app/Listeners/Coupon/MarkCouponClaimRedeemed.php`

```php
class MarkCouponClaimRedeemed implements ShouldQueue
{
    public $afterCommit = true;  // ✅ Forward-compatible

    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();  // ✅ High priority queue
    }

    public function handle(PaymentSucceeded $event): void
    {
        $order = $event->order;

        if (!$order->coupon_id) {
            return;  // ✅ Handles missing coupon gracefully
        }

        $activeClaim = CouponClaim::query()
            ->where('coupon_id', $order->coupon_id)
            ->where('user_id', $order->user_id)
            ->where('status', \App\Enums\CouponClaimStatus::ACTIVE)
            ->first();

        if (!$activeClaim) {
            // ✅ Logs if not found, doesn't fail
            Log::info('No active coupon claim found for completed order', [...]);
            return;
        }

        try {
            $this->claimService->markRedeemed($activeClaim);
        } catch (\Exception $e) {
            // ✅ Logs but doesn't fail the listener
            Log::error('Failed to mark coupon claim as redeemed', [...]);
        }
    }
}
```

### Registration

**File:** `app/Providers/EventServiceProvider.php` (line 106)

```php
PaymentSucceeded::class => [
    SendPaymentSucceededNotification::class,
    GenerateInvoiceListener::class,
    SendUserPaymentSucceededNotification::class,
    FulfillDigitalProducts::class,
    MarkCouponClaimRedeemed::class,  // ✅ Registered
],
```

**Result:** ✅ **Event listener properly wired, queued, safe**

### Durable State Guarantee

Payment succeeds → transaction commits → event dispatched → listener queued.

If listener fails or crashes:
- Payment is already finalized (no rollback)
- Claim remains ACTIVE (can be retried)
- Listener is retried by queue system

**Result:** ✅ **No indefinite state risk**

---

## 7. ONE-USE-PER-USER — AMBIGUOUS BUSINESS RULE

### The Question

**Can a user claim the same coupon multiple times?**

### Evidence: YES (Allowed)

**File:** `tests/Feature/Coupon/CouponClaimLifecycleTest.php` (line 120-131)

```php
public function user_can_claim_again_after_first_claim_redeemed()
{
    $claim1 = $this->claimService->claim($this->coupon, $this->user);
    $this->claimService->markRedeemed($claim1);

    // Should be able to claim again (if max_claims allows)
    $claim2 = $this->claimService->claim($this->coupon, $this->user);

    $this->assertNotNull($claim2);
    $this->assertEquals(CouponClaimStatus::ACTIVE, $claim2->status);
    $this->assertNotEquals($claim1->id, $claim2->id);
}
```

**Result:** ✅ **Test explicitly allows re-claiming after redemption**

### Contradiction: NOT_CLAIMED Rule

**File:** `app/Services/Coupon/Eligibility/EligibilityEngine.php` (line 354-369)

```php
private function evalNotClaimed(Coupon $coupon, User $user): array
{
    $hasClaim = CouponClaim::query()
        ->where('coupon_id', $coupon->getKey())
        ->where('user_id', $user->getKey())
        ->exists();  // ❌ Blocks if ANY historical claim exists

    $passed = !$hasClaim;
}
```

**Scenario:**

```
Coupon has rule: NOT_CLAIMED

User A:
  T1: Claims → ACTIVE
  T2: Redeems → REDEEMED
  T3: Tries to claim again
      - CouponClaimService: ✅ Allows (only checks ACTIVE)
      - EligibilityEngine::NOT_CLAIMED: ❌ BLOCKS (ANY historical claim exists)
```

**Result:** ❌ **BUSINESS SEMANTICS CONFLICT**

Public coupons are always one-use-per-user (line 40 in CouponConfigurationController).
But targeted coupons with NO NOT_CLAIMED rule should allow re-claiming after redemption.

The current implementation breaks this for coupons with NOT_CLAIMED rules.

---

## 8. DATABASE COMPATIBILITY

### Schema Changes

**File:** `database/migrations/2026_09_14_000001_add_claim_lifecycle_to_coupon_claims.php`

**Changes:**
1. ✅ Added enum column: `status` (active, expired, redeemed)
2. ✅ Added timestamp: `expires_at` (nullable)
3. ✅ Added timestamp: `redeemed_at` (nullable)
4. ✅ Dropped old UNIQUE(coupon_id, user_id) constraint
5. ✅ Added index: idx_claim_lookup(coupon_id, user_id, status)

**Application-Level Enforcement**

No filtered unique index — uses application-level check within FOR UPDATE transaction.

**Compatibility:**
- ✅ MySQL 8.x
- ✅ SQLite (development)
- ⚠️ **TiDB: RUNTIME VERIFICATION PENDING**

FOR UPDATE behavior with lifecycle states not verified on TiDB.

**Result:** ⚠️ **RUNTIME CERTIFICATION PENDING**

---

## 9. CONCURRENCY TEST

### Scenario: Two transactions, same user, same coupon

```
max_claims = 1

User A (T1):
  → lockForUpdate(coupon)
  → check active claim: none
  → check capacity: 0 < 1
  → create claim
  → commit

User B (T2):
  → lockForUpdate(coupon)  [WAITS for T1]
  → after T1: check active claim: USER_A's claim
  → create claim [capacity check passes, but would be 2nd ACTIVE]
  → commits? ❌ SHOULD FAIL

Actually: Max_claims check counts ACTIVE + REDEEMED = 1
  → capacity full
  → throws maxClaimsReached
  → correct ✅
```

**Result:** ✅ **Concurrency handled correctly by FOR UPDATE lock**

---

## 10. TEST SUITE VERIFICATION

### Lifecycle Tests: 18 Total

**File:** `tests/Feature/Coupon/CouponClaimLifecycleTest.php`

All 18 tests pass:

✅ Lifecycle Transitions (4 tests)
- user_can_claim_coupon_and_enter_active_state
- user_cannot_claim_twice_if_first_claim_still_active
- user_can_claim_again_after_first_claim_expires
- user_can_claim_again_after_first_claim_redeemed

✅ State Transitions (2 tests)
- mark_redeemed_transitions_active_to_redeemed
- mark_redeemed_throws_if_claim_not_active

✅ Expiry Validation (2 tests)
- expired_claims_do_not_block_new_claims
- hasClaimed_returns_false_for_expired_claims

✅ Scheduled Expiration (2 tests)
- expireExpiredClaims_transitions_expired_claims
- expireExpiredClaims_ignores_null_expires_at

✅ First-N Semantics (4 tests)
- capacity_respects_first_n_semantics_active_plus_redeemed
- capacity_respects_redeemed_as_occupied
- expired_claims_release_capacity
- unlimited_claims_when_max_claims_null

✅ Helper Methods (4 tests)
- has_claimed_returns_true_for_active_claims
- has_claimed_returns_false_for_redeemed_claims
- get_claim_returns_active_claim
- get_claim_returns_null_for_redeemed_claims

### Why Tests Pass (But Production May Fail)

Tests create coupons with `mode: 'dynamic'` and `require_claim: true`, but **bypass eligibility rules**.

Production coupons with NOT_CLAIMED or other eligibility rules would hit the defective EligibilityEngine methods.

---

## 11. API BACKWARD COMPATIBILITY

**File:** `app/Http/Controllers/Api/General/OrderController.php`

No changes to coupon apply endpoint.

**Result:** ✅ **API unchanged, backward compatible**

---

## 12. COUPON CREATION PATHS — VERIFIED SAFE

### Production Paths

Only one production path creates CouponClaim:

**File:** `app/Services/Coupon/CouponClaimService.php:107`
```php
$claim = CouponClaim::create([...]);
```

Inside `DB::transaction` with `lockForUpdate`.

### Test Creation Paths

Tests use `CouponClaim::create()` in setUp only (not during test execution).

**Result:** ✅ **No unauthorized creation paths found**

---

## FINAL CLASSIFICATION

### ✅ VERIFIED CORRECT

| Component | Status | Evidence |
|-----------|--------|----------|
| First-N Semantics | ✅ | Active Capacity model correctly implemented |
| One-Active-Claim | ✅ | FOR UPDATE lock serializes correctly |
| Expiry Validation | ✅ | NULL and past dates handled correctly |
| Scheduler | ✅ | Command registered, scheduled hourly |
| Payment Integration | ✅ | Event fires after commit, listener queued |
| Concurrency | ✅ | Parent-row locking prevents races |
| API Compatibility | ✅ | No changes, backward compatible |

### ❌ DEFECTS FOUND

| Issue | Severity | Impact | Fix Required |
|-------|----------|--------|--------------|
| EligibilityEngine NOT_CLAIMED rule doesn't check status/expiry | **CRITICAL** | Blocks re-claiming after REDEEMED | YES |
| EligibilityEngine CLAIMED rule doesn't check status/expiry | **CRITICAL** | Fails for expired/redeemed claims | YES |
| Inconsistent eligibility logic vs CouponClaimService | **HIGH** | Different behavior in different code paths | YES |
| One-use-per-user semantics conflict with NOT_CLAIMED rule | **HIGH** | Business rule ambiguity | Clarify spec |

### ⚠️ RUNTIME VERIFICATION PENDING

| Issue | Impact | Risk |
|-------|--------|------|
| TiDB FOR UPDATE behavior with lifecycle | Production compatibility | Can't certify without TiDB access |
| Production database compatibility | Deployment blocker | Medium |

---

## REMAINING RISKS

### 1. Production Coupons with NOT_CLAIMED Rule

Any coupon with `rule_tree: {type: 'not_claimed'}` would:
- ❌ Block users from re-claiming after REDEEMED
- ❌ Block users from re-claiming after EXPIRED
- ❌ Contradict Phase 2 specification

### 2. Production Coupons with CLAIMED Rule

Any coupon with `rule_tree: {type: 'claimed'}` would:
- ✅ Accept ANY historical claim (correct for "user has used before" semantics)
- ❌ But doesn't distinguish ACTIVE/EXPIRED/REDEEMED states

### 3. Test-Only Verification

Lifecycle tests pass because they:
- Create coupons without eligibility rules
- Don't invoke EligibilityEngine
- Only test CouponClaimService directly

Production coupons with rules would fail at eligibility evaluation.

---

## REQUIRED FIXES

### Fix 1: Update EligibilityEngine NOT_CLAIMED Rule

**File:** `app/Services/Coupon/Eligibility/EligibilityEngine.php` (line 354-369)

```php
private function evalNotClaimed(Coupon $coupon, User $user): array
{
    // Check for logically active claim (status + not expired)
    $hasClaim = CouponClaim::query()
        ->where('coupon_id', $coupon->getKey())
        ->where('user_id', $user->getKey())
        ->where('status', \App\Enums\CouponClaimStatus::ACTIVE)
        ->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        })
        ->exists();

    $passed = !$hasClaim;
    return [
        'passed' => $passed,
        'type' => EligibilityRuleType::NOT_CLAIMED->value,
        'value' => null,
        'actual' => $hasClaim,
        'reason' => $passed ? null : 'User has an active, unexpired claim',
    ];
}
```

### Fix 2: Clarify One-Use-Per-User Semantics

**Business Decision Required:**

1. **Option A: One-use-per-user (historical)**
   - NOT_CLAIMED blocks ANY historical claim
   - Users cannot re-claim after REDEEMED
   - Remove test: `user_can_claim_again_after_first_claim_redeemed`

2. **Option B: Multi-use with re-claim-after-redemption (Phase 2)**
   - NOT_CLAIMED checks only ACTIVE unexpired claims
   - Users CAN re-claim after REDEEMED
   - Implement Fix 1 above

**STOP here and resolve with product team.**

---

## FINAL VERDICT

```
❌ BLOCKED — IMPLEMENTATION DEFECTS MUST BE FIXED
```

**Rationale:**
- Eligibility engine doesn't respect claim lifecycle states
- Tests pass only because they bypass eligibility rules
- Production coupons with NOT_CLAIMED rules would fail at evaluation
- Business semantics (one-use vs multi-use) unresolved

**Do NOT proceed to Phase 2 targeting/snapshot/notification implementation.**

**Next Steps:**
1. Fix EligibilityEngine methods to check status + expiry
2. Clarify one-use-per-user business semantics with product team
3. Add integration tests that invoke eligibility rules
4. Re-verify test suite with eligibility rules enabled
5. Schedule TiDB runtime certification before production deployment

---

## DETAILED EVIDENCE REFERENCES

| Finding | File | Line | Evidence |
|---------|------|------|----------|
| Eligibility NOT_CLAIMED defect | EligibilityEngine.php | 356-359 | No status/expiry filter in query |
| Eligibility CLAIMED defect | EligibilityEngine.php | 373-376 | No status/expiry filter in query |
| CouponClaimService correct logic | CouponClaimService.php | 56-65 | Checks status + expiry correctly |
| First-N semantics correct | CouponClaimService.php | 73-81 | Counts ACTIVE + REDEEMED only |
| FOR UPDATE lock correct | CouponClaimService.php | 40-44 | Parent-row serialization in place |
| Scheduler registered | Kernel.php | 22 | Command scheduled hourly |
| Listener registered | EventServiceProvider.php | 106 | MarkCouponClaimRedeemed in PaymentSucceeded array |
| Test allows re-claim | CouponClaimLifecycleTest.php | 120-131 | user_can_claim_again_after_first_claim_redeemed passes |
| Test bypasses eligibility | CouponClaimLifecycleTest.php | 45-46 | No rule_tree in test coupon setup |
