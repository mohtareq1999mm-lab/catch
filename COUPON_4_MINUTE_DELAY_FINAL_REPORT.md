# Coupon Unified 4-Minute Delay — Final Report

## Executive Summary
```text
FINAL STATUS: PASS
```
Every intentional coupon business delay is now exactly 4 minutes via ONE
authoritative env-backed configuration. Immediate flows stayed immediate.
No unrelated mechanism was touched.

## Previous Timing
```text
Public availability: 15 minutes (public_grace_minutes, default 15)
Automatic distribution: 10 minutes (distribution_delay_seconds, default 600)
```

## New Timing
```text
Public availability: 4 minutes (COUPON_DELAY_MINUTES → public_grace_minutes)
Automatic distribution: 4 minutes (COUPON_DELAY_MINUTES → distribution_delay_seconds = 240)
```

## Immediate Flows (verified unchanged)
```text
Assignment (coupon.assigned): immediate, no outbox involvement
Manual distribution: immediate (delay 0 → recordAndDispatch)
Coupon used (coupon.used): immediate, no outbox involvement
Per-user triggers (registration/address/order): immediate (pre-existing path, untouched)
Detect-activations backstop: immediate dispatch (pre-existing, untouched)
```

## Files Changed
| File | Change | Old → New |
|---|---|---|
| `config/coupon-distribution.php` | Added `delay_minutes` (`COUPON_DELAY_MINUTES`, 4); `public_grace_minutes` default → derived from it; `distribution_delay_seconds` default → `*60` (240) | 15 / 600 → 4 / 240 (legacy env overrides still honored) |
| `app/Listeners/Coupons/StartCouponDistribution.php` | Missing-key fallback 600 → 240 + comment | fallback only; runtime reads config |
| `app/Console/Commands/Coupons/DetectPublicCouponsCommand.php` | Missing-key fallback 15 → 4 | fallback only |
| `app/Listeners/SendUserCouponAvailableNotification.php` | Missing-key fallback 15 → 4 + comment | fallback only |
| `.env.example` | Added `COUPON_DELAY_MINUTES=4`, `COUPON_DISTRIBUTION_DELAY_SECONDS=240`; grace example 15 → 4 | docs only |
| `tests/Feature/Coupon/CouponBusinessDelayTest.php` | NEW: 11 tests (§20 Test 1–10) | — |
| `docs/coupons/*` (5 files) | Timing references 600s/15min → 240s/4min | docs only |

## Configuration
```text
COUPON_DELAY_MINUTES=4 (default; THE authoritative knob)
  → config('coupon-distribution.delay_minutes')
  → public_grace_minutes (unless COUPON_PUBLIC_GRACE_MINUTES set)
  → distribution_delay_seconds = delay_minutes × 60 (unless COUPON_DISTRIBUTION_DELAY_SECONDS set)
Consumers: StartCouponDistribution, DetectPublicCouponsCommand,
SendUserCouponAvailableNotification::sendIfMaturePublic — all via config(), no env() outside config files.
```

## Discovered Delays (classification)
| Location | Value | Class | Changed? | Reason |
|---|---|---|---|---|
| `distribution_delay_seconds` default | 600s | A business | YES → 240 | the business delay |
| listener fallback | 600 | A business | YES → 240 | same, missing-key safety |
| `public_grace_minutes` default + example | 15min | A business | YES → 4 | the business delay |
| detect-public + available-listener fallbacks | 15 | A business | YES → 4 | same |
| outbox `backoffFor` 30/120/600/1800 | backoff | B retry | NO | failure backoff, not scheduling |
| `LEASE_SECONDS` 300 | lease | D lease | NO | crash-recovery claim |
| reservation TTL 30min | TTL | C | NO | §14 frozen |
| claim TTL (`claim_ttl_hours`) | TTL | C | NO | §14 frozen |
| scheduler cadences (1/5/15/60min) | frequency | E | NO | §9; sweep latency ≠ business delay |
| RabbitMQ `retry_delays`, prefetch, timeouts | retry/infra | B/F | NO | transport policy |
| queue `retry_after`, worker timeouts | infra | F | NO | worker contract |
| order SMS/Email `$backoff` 900 | backoff | B, unrelated module | NO | out of scope |
| assignment expiry | expiry | C | NO | §14 frozen |

## Tests
- `php artisan test tests/Feature/Coupon/CouponBusinessDelayTest.php` (sqlite) → **11 passed** — PASS
- Same file on MySQL 8.4.3 (`meem_coupon_e2e`) → **11 passed** — PASS
- `php artisan test tests/Feature/Coupon/` → 112 passed, 3 skipped, **1 failed (pre-existing CLAIMED-expired, clean-tree record, zero coupon-delay references)** — PASS (with noted pre-existing)
- `tests/Feature/Notifications/` coupon tests (fan-out, assigned, consumed) → PASS; 18 failures are order/refund/pusher-auth env issues with zero coupon references — pre-existing (count matches prior record exactly)
- `php -l` on all touched files → clean. No PHPStan/Pint configured in repo (nothing to run; static queue-policy test green).

## Runtime Verification
- Database (MySQL): persisted `available_at = created +240s` rows read back — VERIFIED
- Sweep gating: `publishDue` skips future rows, delivers after window — VERIFIED (sqlite + MySQL)
- Laravel Queue dispatch to configured queue — VERIFIED (prior task's test, unchanged path)
- RabbitMQ live publish / wall-clock 4-min wait — NOT VERIFIED (fake transport + Carbon travel used; broker reachable but live fan-out not exercised)
- FCM/Pusher live send — BLOCKED (no credentials; never claimed)

## Regression Search
Remaining `600/900/1800/15` hits classified above: all B/C/D/E/F or unrelated — SAFE. No `600`/`15` remains as a coupon business delay in `app/` code. `not_eligible_count: 600` in API docs is an example COUNTER, not a delay — SAFE.

## Final Architecture Flow
```text
ONE authoritative delay: COUPON_DELAY_MINUTES=4
        ↓
  ┌─────┴─────┐
  ↓           ↓
coupon.available   automatic distribution
+4 min grace        +240 s outbox
  ↓                   ↓
detect-public sweep  minutely sweep → RabbitMQ → reload+LiveCheck+drift guard
  ↓                   ↓
fan-out → notify    evaluate → coupon.eligible → notify
```
Immediate (no gate): assigned, manual, used, per-user triggers. Untouched: reservation 30min, claim TTL, backoff, lease, cadences, RabbitMQ topology, Pusher, FCM, payloads, channels, workers.

## Acceptance Criteria: 24/24 true (production-access items N/A by design — no access exists).
