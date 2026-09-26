# COUPON REAL PUSHER E2E — FINAL REPORT

## Executive Summary

```text
BLOCKED — REAL PUSHER DELIVERY CANNOT BE VERIFIED
```

This environment is a local development checkout with no production
surface of any kind (see Environment). Per §4 I stopped before any live
step: no test data created, no code changed, no credentials touched.
Everything below separates what was proven in code from what requires a
credentialed production run.

A significant code-level finding WAS produced during contract
verification (Channel section): the failing channel-auth tests are a
test-harness artifact, not a production authorization defect — mechanism
proven from framework source.

## Environment

```text
Application access (local code) = YES — D:\work\meem, Laravel 10.30.1, PHP 8.2.30
Production shell = NO
Production DB = NO
RabbitMQ = NO (no broker reachable; none configured here)
Pusher (real, credentialed) = NO (no PUSHER_* values anywhere in this env)
Frontend/client harness = NO (docs only; no runnable client, no browser automation)
```

Local runtime: `APP_ENV=local`, `QUEUE_CONNECTION=database` (config;
`sync` under phpunit), `CACHE_STORE=file`, broadcast default `pusher`
(config; `log` under phpunit). No secrets printed; `.env` never read.

## Deployment Revision

Local HEAD: `f0bd8f5` + uncommitted Stage-B work (policy/resource/cache/
tests). **Deployed production revision: UNVERIFIED** (no prod access) —
the production run must record `git rev-parse HEAD` on the prod host and
confirm Stage-B files + transport binding are present before any live step.

## Broadcast Configuration

```text
connection = pusher (config default; env-overridable) — VERIFIED in code
key/secret/app_id/cluster = env-sourced — configured=yes/no UNVERIFIED here
(env carries no PUSHER_* values; production values never inspected)
```

`config/broadcasting.php` is correctly structured (TLS, cluster option).
Whether production actually populates credentials is a first-run check.

## Channel

- `User::receivesBroadcastNotificationsOn()` → `users.{id}` for customers
  (`admin.notifications` for admins) [VERIFIED source].
- `routes/channels.php:22` authorizes `users.{id}` to the same user id;
  loaded in `BroadcastServiceProvider::boot`, which is registered
  [VERIFIED source]. Owner-allow / non-owner-deny is therefore the coded
  contract.
- **Harness artifact (VERIFIED mechanism):** `Broadcast::channel()`
  registers patterns on the *current default driver instance*
  (`BroadcastManager::__call` → driver). phpunit boots with
  `BROADCAST_DRIVER=log` (registrations land on the log broadcaster);
  the E2E harness then switches to pusher + `forgetDrivers()`, orphaning
  every pattern — so `NotificationAuthorizationTest` 403s are a driver-swap
  artifact, NOT proof of broken production auth. The stale docblock claim
  ("no registration exists") is factually wrong; `routes/channels.php:22`
  exists.
- Consequence: NO automated test currently proves owner-channel auth
  works — a real coverage gap. Recommended (not implemented per §31):
  re-require `routes/channels.php` after the harness driver swap, then
  re-run the authorization suite green as pre-proof before the live run.

## Subscription

UNVERIFIED (no client). Production run must observe: A subscribes to
`private-users.A` (SUCCESS), B subscribes to `private-users.A` (403),
all over the normal `/broadcasting/auth` flow.

## Test Coupon IDs

None created (no live environment to create them in).

## Backend Timeline

T0–T8 proven locally end-to-end in prior tasks (distribution → outbox →
transport seam → consumers → evaluation → transition → notification.requested
→ database notification + FCM capture, with run COMPLETED and exact-once
semantics). T9 (Pusher publish) proven only to the RecordingPusher seam.
T10 (client receipt): UNVERIFIED.

## Pusher Evidence

None (no credentialed publish possible here). Intermediate seams only —
explicitly NOT claimed as delivery proof per §2.

## Client Evidence

None (no client). Required: WebSocket connected, private subscription
success, `coupon.eligible` received with sanitized payload (no tokens in
report).

## Payload Verification

Contract VERIFIED in code + green suites: `via = database/fcm/broadcast`,
`broadcastType = coupon.eligible`, payload carries id/type/title/message/
icon/resource_type/resource_id/action_url/coupon_id/tree_hash/run_id/
requires_claim (native bool both values), and provably excludes
`coupon_code` (leak-scan tests). Semantic DB↔broadcast consistency
VERIFIED at the `toBroadcast`/`toDatabase` level.

## requires_claim

`true`/`false` as JSON booleans VERIFIED on persisted rows; string/int
forms excluded by type-strict assertions. Live-client `typeof` check:
UNVERIFIED.

## Security

A/B isolation VERIFIED at code + DB level (owner-only notify, same-user
channel rule, no-code payloads). Live cross-subscription denial:
UNVERIFIED. Hard-stop items 3–9: none observed; live run must re-check
each.

## Idempotency

Same-tree suppression + new-tree re-notification VERIFIED locally
(transition + redelivery suites). Live duplicate observation: UNVERIFIED.

## Assignment Notification

`coupon.assigned` payload contract + targeting-independence VERIFIED in
code/suites. Live `coupon.assigned` Pusher receipt: UNVERIFIED.

## Failures

None encountered (no live steps executed). No code changed in this task
(§31 honored); no prod data touched.

## Final Acceptance (§33 table)

Real env / revision / Pusher config / channel auth (live) / client /
WebSocket / subscriptions / targeting trigger / distribution / outbox /
consumer / transition / request / DB notification / Pusher publish /
client receipt / user correctness / requires_claim ×2 / no code leak /
dedupe ×2 / reconnect / coupon.assigned / assignment-only silence /
leak-free / prod-data-safe: **all UNVERIFIED** (environmentally blocked).
Code-contract counterparts of channel, payload, types, dedupe, isolation:
**VERIFIED** as stated above.

```text
BLOCKED — REAL PUSHER DELIVERY CANNOT BE VERIFIED from here.
```

Production runbook: credentialed host → revision check → broadcast
config presence (no secret printing) → fix-then-green the channel-auth
suite → connect client A/B → baseline counts → PUT targeting → T0–T10
timeline with IDs → payload/typeof/security/dedupe/reconnect/assigned
checks → queue/DLQ observation → cleanup-or-mark E2E records. Any §30
trigger stops the run immediately.
