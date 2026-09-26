# COUPON REAL PUSHER — FINAL PROOF REPORT

## Final Status

```text
BLOCKED
```

Real Pusher delivery to a real client cannot be executed from this
environment (no credentials, broker, workers, or client harness exist
here). What WAS completed: the harness root cause was proven from
source, fixed with a 9-line test-only change, and channel authorization
(owner ALLOW / non-owner DENY) now passes through the real framework
auth path. No Coupon business logic was modified.

## 1. Harness Root Cause

```text
HARNESS ROOT CAUSE = VERIFIED
```

`Broadcast::channel()` resolves via `BroadcastManager::__call` into
`$this->driver()->channel(...)`, so patterns live on the broadcaster
**instance** (`Broadcaster::$channels`), proven in
`vendor/.../Broadcasting/BroadcastManager.php:477-480`. phpunit boots
with `BROADCAST_DRIVER=log`, so `routes/channels.php` patterns land on
the log broadcaster; the E2E base then switches to pusher +
`forgetDrivers()` (which wipes `$this->drivers`), leaving the fresh
PusherBroadcaster pattern-less → `verifyUserCanAccessChannel` falls
through to 403. The prior "no registration exists" claim was wrong:
`routes/channels.php:22` registers `users.{id}` owner-only, loaded by the
registered `BroadcastServiceProvider`. Production (pusher from boot) was
never affected by this mechanism.

## 2. Harness Fix

`tests/Feature/Notifications/NotificationE2ETestCase.php` only
(+9 lines: explanatory comment + `require base_path('routes/channels.php')`
after the driver swap, binding patterns to the pusher driver). No routes,
no authorization semantics, no channel naming, no notification logic
touched. This task's entire diff is that file (other tree diffs are prior
Stage-B work, verified untouched by this session via `git diff`).

## 3. Channel Authorization

Through the real `PusherBroadcaster::auth()` path (not direct callbacks):

```text
Owner (users.{id} own)      = PASS (auth array returned)
Non-owner                   = PASS (403 denied)
Admin channel owner/deny    = PASS / PASS
HTTP /broadcasting/auth owner = PASS (200 + auth signature)
HTTP admin channel          = PASS
```

One residual error is NOT a regression: `order.created.{id}` owner-auth
errors because **no such channel exists anywhere in the application**
(no event broadcasts on it, no registration) — the test asserts a
contract the app never defined. Fixing it would mean inventing production
surface; correctly left failing with this explanation (test bug, not app
bug, not harness bug).

## 4. Environment

Local checkout only: Laravel 10.30.1 / PHP 8.2.30 / APP_ENV local;
broadcast `pusher`-by-config but credential-less here; queue database;
cache file. `PUSHER_*`/`RABBITMQ_*`/FCM/client: absent.

```text
REAL PUSHER LOCAL TEST = BLOCKED
```

## 5. Real Pusher

```text
Pusher publish = UNVERIFIED (no credentialed broker reachable)
```

## 6. Real Client

```text
WebSocket / subscription / receipt = UNVERIFIED (no client harness)
```

## 7. Payload

Contract re-verified in code + green suites (`CouponNotificationE2ETest`,
`RequiresClaim`, `NotificationPipeline`): `coupon.eligible` carries
id/type/title/message/icon/resource_type/resource_id/action_url/
coupon_id/tree_hash/run_id/requires_claim(bool), never `coupon_code`.
No live payload exists to sanitize.

## 8. requires_claim

`true`/`false` as native booleans VERIFIED on persisted rows
(type-strict). Live-client `typeof`: UNVERIFIED.

## 9. Security

Owner/non-owner channel decisions now proven through the framework path;
DB-level isolation and no-code payloads green. Live cross-subscription
denial: UNVERIFIED.

## 10. Idempotency

Same-tree suppression, new-tree re-notification, redelivery no-dup:
VERIFIED in distribution suites. Live duplicate observation: UNVERIFIED.

## 11. coupon.assigned

Payload contract + targeting-independence VERIFIED in code/suites. Live
`coupon.assigned` Pusher receipt: UNVERIFIED.

## 12. Production runbook (minimal, per Phase 11)

1. Deploy Stage-B revision. 2. Restart workers/consumer. 3. Resolve
`CouponEventTransport` in prod console. 4. Confirm Pusher runtime config
(presence only, never print secrets). 5. Run corrected channel-auth
suite. 6–8. Connect A (subscribe `private-users.A`), B (deny on A's
channel). 9–10. Trigger targeted coupon; trace outbox→RabbitMQ→consumer→
notification. 11–12. Observe Pusher publish + real client receipt.
13–17. requires_claim ×2, dedupe, new tree, `coupon.assigned`, cleanup.
