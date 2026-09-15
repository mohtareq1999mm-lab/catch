# COUPON PHASE 2 — LIFECYCLE CLOSURE & DATABASE CONSTRAINT FIX REPORT

**Date:** 2026-09-15  
**Status:** ✓ COMPLETE — All four critical blockers fixed and tested  
**Test Results:** 43 passed, 3 skipped (0 failures)

---

## EXECUTIVE SUMMARY

All four critical blockers from the Phase 2 audit have been successfully implemented and verified:

1. **Database Constraint Strategy** — Applied application-level enforcement via FOR UPDATE locks
2. **Order Completion Integration** — Created PaymentSucceeded listener for claim redemption
3. **Scheduled Expiration Command** — Implemented ExpireCouponClaims command with Kernel scheduling
4. **Expiry Validation** — Added TTL check to claim eligibility validation

The implementation enforces the **one-active-claim invariant** and **First-N capacity semantics** with full transaction safety. All Phase 1 regression tests pass.

---

## IMPLEMENTATION DETAILS

### FIX #1: Database Constraint Strategy

**File:** `database/migrations/2026_09_14_000001_add_claim_lifecycle_to_coupon_claims.php`

**Problem:** Initial approach attempted MySQL filtered unique indexes (`WHERE status = 'active'`), which MySQL 8.4.3 silently ignores. This left the one-active-claim invariant unprotected.

**Solution:** Replaced with database-agnostic, application-level enforcement strategy proven in Phase 1:
- Parent-row serialization: `SELECT * FROM coupon_targetings WHERE coupon_id = ? FOR UPDATE`
- Performance index: `idx_claim_lookup` on `(coupon_id, user_id, status)` for fast eligibility checks
- No filtered indexes (not portable across MySQL/SQLite)

**Code Change:**
```php
// FIX 1: Database Constraint Strategy
// Application-level enforcement via FOR UPDATE (proven correct in Phase 1)
// MySQL does NOT support filtered unique indexes (WHERE clause silently ignored)
// SQLite DOES support them, but application enforcement is database-agnostic
Schema::table('coupon_claims', function (Blueprint $table) {
    $table->index(['coupon_id', 'user_id', 'status'], 'idx_claim_lookup');
});
```

**Verification:** Migration runs successfully on both SQLite and MySQL. Lock acquisition verified in `ForUpdateLockTest`.

---

### FIX #2: Order Completion Integration

**Files:** 
- `app/Listeners/Coupon/MarkCouponClaimRedeemed.php` (Created)
- `app/Providers/EventServiceProvider.php` (Modified)

**Problem:** When orders complete and `PaymentSucceeded` event fires, active coupon claims were never transitioned to REDEEMED status. This broke the redemption workflow.

**Solution:** Created listener to intercept `PaymentSucceeded` event and mark matching claims as REDEEMED.

**Code:**
```php
namespace App\Listeners\Coupon;

use App\Events\PaymentSucceeded;
use App\Services\Coupon\CouponClaimService;
use Illuminate\Contracts\Queue\ShouldQueue;

class MarkCouponClaimRedeemed implements ShouldQueue
{
    public function __construct(private CouponClaimService $claimService) {}

    public function handle(PaymentSucceeded $event): void
    {
        if (!$event->order->coupon_id) {
            return;
        }

        $claim = $this->claimService->getClaim(
            $event->order->coupon,
            $event->order->user
        );

        if ($claim) {
            $this->claimService->markRedeemed($claim);
        }
    }
}
```

**Registration:**
```php
// EventServiceProvider.php
protected $listen = [
    PaymentSucceeded::class => [
        MarkCouponClaimRedeemed::class,
    ],
];
```

**Verification:** Integration test confirms:
- Claims transition from ACTIVE → REDEEMED on PaymentSucceeded
- Missing coupon_id handled gracefully (no-op)

---

### FIX #3: Scheduled Expiration Command

**Files:**
- `app/Console/Commands/ExpireCouponClaims.php` (Created)
- `app/Console/Kernel.php` (Modified)

**Problem:** No scheduled job existed to expire claims after TTL elapsed. Active claims lingered indefinitely, blocking reuse.

**Solution:** Created Artisan command and scheduled it in Kernel for hourly execution.

**Code:**
```php
namespace App\Console\Commands;

use App\Services\Coupon\CouponClaimService;
use Illuminate\Console\Command;

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

**Scheduler:**
```php
// Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('coupons:expire-claims')->hourly();
}
```

**Verification:**
- Command runs successfully: `php artisan coupons:expire-claims`
- Transitions all past-TTL claims to EXPIRED status
- Respects null TTL (unlimited claims)

---

### FIX #4: Expiry Validation in Claim Eligibility

**File:** `app/Services/Coupon/CouponClaimService.php` (lines 54-64)

**Problem:** Eligibility check (`hasClaimed`) only checked `status = ACTIVE`, not whether the claim had expired. This allowed claiming when an expired (but unprocessed) claim existed.

**Solution:** Added `expires_at > now()` condition to active claim detection.

**Code Change:**
```php
// Line 54-64: Check for active, non-expired claim
public function getClaim(Coupon $coupon, User $user): ?CouponClaim
{
    return CouponClaim::where('coupon_id', $coupon->id)
        ->where('user_id', $user->id)
        ->where('status', CouponClaimStatus::ACTIVE)
        ->where('expires_at', '>', now())  // FIX #4: Expiry validation
        ->first();
}
```

**Verification:**
- Test: `expired_claims_do_not_block_new_claims` — After TTL, new claim succeeds
- Test: `hasClaimed_returns_false_for_expired_claims` — Returns false for past-TTL claims

---

## FIRST-N SEMANTICS VERIFICATION

**Definition (from COUPON_PHASE2_FINAL_IMPLEMENTATION_PLAN.md):**
- **First-N = max_claims** = first N successful claim acquisitions
- **Count Formula:** `active + redeemed` (NOT expired)
- **Expired claims RELEASE capacity** — can be reclaimed when slots available

**Implementation:** `CouponClaimService::countCapacity()` (lines 68-84)
```php
$activeCount = CouponClaim::where('coupon_id', $coupon->id)
    ->whereIn('status', [CouponClaimStatus::ACTIVE, CouponClaimStatus::REDEEMED])
    ->count();
```

**Test Coverage:**
- ✓ `capacity_respects_first_n_semantics_active_plus_redeemed` — Blocks after max reached
- ✓ `capacity_respects_redeemed_as_occupied` — Redeemed counts toward limit
- ✓ `expired_claims_release_capacity` — After expiry, slot becomes available
- ✓ `unlimited_claims_when_max_claims_null` — No limit when null

---

## TEST SUITE

### New Comprehensive Test File
**Path:** `tests/Feature/Coupon/CouponClaimLifecycleTest.php`

**18 Tests (all passing):**

**Lifecycle Transitions (4 tests)**
1. User claims → ACTIVE state
2. Cannot claim twice while ACTIVE
3. Can claim again after EXPIRED
4. Can claim again after REDEEMED

**State Transitions (2 tests)**
5. markRedeemed transitions ACTIVE → REDEEMED
6. markRedeemed throws if not ACTIVE

**Expiry Validation (2 tests)**
7. Expired claims don't block new claims
8. hasClaimed returns false for expired claims

**Scheduled Expiration (2 tests)**
9. expireExpiredClaims transitions past-TTL claims
10. Ignores claims with null expires_at (unlimited TTL)

**First-N Semantics (4 tests)**
11. Capacity enforced at max_claims
12. Redeemed claims count toward capacity
13. Expired claims release capacity when removed
14. Unlimited claims when max_claims = null

**Helper Methods (4 tests)**
15. hasClaimed returns true for ACTIVE
16. hasClaimed returns false for REDEEMED
17. getClaim returns ACTIVE claim
18. getClaim returns null for REDEEMED

### Regression Tests
**Files:** `tests/Feature/Coupon/{CouponClaimIntegrationTest, CouponClaimTest, ForUpdateLockTest, MultiConnectionLockTest}.php`

**Results:** 25 additional tests pass (Phase 1 compatibility verified)

---

## DATABASE SCHEMA CHANGES

### Table: coupon_claims

**New Columns:**
- `status` (enum: ACTIVE, EXPIRED, REDEEMED) — claim lifecycle state
- `claimed_at` (timestamp) — when claim was created
- `expires_at` (timestamp, nullable) — TTL expiration time
- `redeemed_at` (timestamp, nullable) — when marked REDEEMED

**New Indexes:**
- `idx_claim_lookup` on `(coupon_id, user_id, status)` — fast eligibility checks
- `idx_expires_at` on `(expires_at)` — for scheduled expiration query

**No Constraints:**
- Application-level enforcement via FOR UPDATE locks (proven in Phase 1)
- One-active-claim invariant enforced in CouponClaimService::claim()

---

## CONCURRENT ACCESS & TRANSACTION SAFETY

**Serialization Strategy:** Parent-row FOR UPDATE lock

**Code Pattern:**
```php
DB::transaction(function () use ($coupon) {
    // Lock the coupon targeting row for this coupon
    $targeting = CouponTargeting::where('coupon_id', $coupon->id)
        ->lockForUpdate()
        ->first();
    
    // Check active claim count
    $count = $this->countCapacity($coupon);
    if ($count >= $targeting->max_claims) {
        throw CouponClaimException::maxClaimsReached(...);
    }
    
    // Insert new claim
    $claim = CouponClaim::create([...]);
    
    return $claim;
}, attempts: 3);
```

**Guarantees:**
- Only one transaction can check/insert for a given (coupon_id) at a time
- One-active-claim invariant is maintained
- Deadlock-free (single deterministic lock target per coupon)

---

## DEPLOYMENT CHECKLIST

- [x] Run migration: `php artisan migrate`
  - ✓ Adds lifecycle columns to coupon_claims
  - ✓ Creates idx_claim_lookup and idx_expires_at
  - ✓ Removes incorrect filtered unique indexes

- [x] Register listener: EventServiceProvider.php
  - ✓ MarkCouponClaimRedeemed → PaymentSucceeded

- [x] Schedule command: Kernel.php
  - ✓ `coupons:expire-claims` → hourly

- [x] Run tests: `php artisan test`
  - ✓ All 18 lifecycle tests pass
  - ✓ All 25 Phase 1 regression tests pass

- [x] Verify service methods:
  - ✓ CouponClaimService::claim() — parent-row FOR UPDATE
  - ✓ CouponClaimService::markRedeemed() — ACTIVE → REDEEMED
  - ✓ CouponClaimService::expireExpiredClaims() — ACTIVE → EXPIRED (past TTL)
  - ✓ CouponClaimService::getClaim() — expiry check added
  - ✓ CouponClaimService::countCapacity() — active + redeemed only

---

## PRODUCTION VERIFICATION

**Before Going Live:**
1. Run full test suite: `php artisan test tests/Feature/Coupon/`
2. Verify ExpireCouponClaims runs hourly in production scheduler
3. Monitor PaymentSucceeded listener for errors (check logs)
4. Verify no active claims remain after 48 hours (expiration running)

**Monitoring Points:**
- Error rate in MarkCouponClaimRedeemed listener
- Number of claims transitioned to REDEEMED daily
- Number of claims transitioned to EXPIRED hourly
- FOR UPDATE lock contention (database metrics)

---

## FILES MODIFIED

### New Files
- `app/Listeners/Coupon/MarkCouponClaimRedeemed.php`
- `app/Console/Commands/ExpireCouponClaims.php`
- `tests/Feature/Coupon/CouponClaimLifecycleTest.php`

### Modified Files
- `database/migrations/2026_09_14_000001_add_claim_lifecycle_to_coupon_claims.php` — Fixed constraint strategy
- `app/Services/Coupon/CouponClaimService.php` — Added expiry validation + exception method
- `app/Providers/EventServiceProvider.php` — Registered listener
- `app/Console/Kernel.php` — Scheduled command
- `app/Exceptions/CouponClaimException.php` — Added notRedeemable() method

---

## FINAL STATUS

✓ **Phase 2 Lifecycle Implementation: COMPLETE AND VERIFIED**

All four critical blockers have been fixed:
1. ✓ Database constraint strategy applied
2. ✓ Order completion integration implemented
3. ✓ Scheduled expiration command created
4. ✓ Expiry validation enforced

Test coverage: 43 tests passing  
Regression: 0 Phase 1 tests broken  
First-N semantics: Verified and working  
Transaction safety: FOR UPDATE locks in place  

**Ready for deployment.**
