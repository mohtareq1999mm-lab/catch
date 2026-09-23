# Coupon Notification Queue — Discovery Report (source-read, 2026-09-26)

> Scope: Laravel queue routing for the four coupon notifications. No code changed during discovery.
> Verdict scale: PASS / NEEDS_CHANGE / RISK / NOT_APPLICABLE / UNKNOWN.

## 1. Current queue configuration — PASS
`config/queue.php:34-37` is the single authoritative map (unchanged, pre-existing):
```php
'queues' => [
    'high' => env('QUEUE_HIGH', 'high'),
    'medium' => env('QUEUE_MEDIUM', 'medium'),
],
```
With documented contract (`queue.php:18-32`): app references semantic roles only; neutral fallbacks apply when undeployed. `App\Enums\QueueName` mirrors it: `high()/medium()/resolved()` → `config('queue.queues.*')` with enum-value fallback; docblock explicitly bans hard-coded physical names. No second competing system exists.

## 2. Current notification queue assignment — PASS
All four use `config('queue.queues.high')` in constructor AND `toBroadcast()->onQueue(...)`:
- `UserCouponAssignedNotification:18,60` — HIGH ✓
- `UserCouponAvailableNotification:18,51` — HIGH ✓
- `UserCouponEligibleNotification:31,65` — HIGH ✓
- `UserCouponUsedNotification:23,58` — HIGH ✓
Listeners (`SendUserCoupon{Assigned,Available,Used}Notification`, `Start(Cancel/User)CouponDistribution`, `MarkCouponClaimRedeemed`) return `QueueName::high()` from `viaQueue()`. Eligible-notification dispatch goes through the RabbitMQ consumer `NotificationRequestHandler` → `$user->notify()` → the notification's own HIGH queue. `via()` channels (`database,fcm,broadcast`, no mail) untouched.

## 3. Current hard-coded queue names — PASS
Repo-wide PHP scan (`app/`, `packages/marvel/src`): zero `meem-high/meem-medium/catch-high/catch-medium` literals in business code. Single hit is a docblock example in `QueueName.php:18`. Zero `onQueue(env(` and zero `onQueue('literal')` in `app/`. Static enforcement exists: `tests/Unit/QueueStandardizationStaticTest.php` (fails any ShouldQueue class not resolving via approved patterns).

## 4. Existing ENV variables — PASS (local) / UNKNOWN (production)
`.env.example:36-40`: `QUEUE_CONNECTION=database`, `QUEUE_HIGH=high`, `QUEUE_MEDIUM=medium` (+ comment documenting per-project override pattern). Real `.env`/production values NOT inspected — no production access. Never modified.

## 5. Existing worker configuration — PASS
`deploy/supervisor/laravel-worker-catch-high.conf:13`: `--queue="${QUEUE_HIGH:-high}"`; medium: `--queue="${QUEUE_MEDIUM:-medium}"`. Workers consume the SAME env vars with the SAME neutral defaults — app/worker alignment holds for any `QUEUE_HIGH` value without code changes. Worker mechanism untouched.

## 6. Existing Laravel config — PASS
`queue.php` (above) + `config/frontend.php:20` (`env('FRONTEND_WEBHOOK_QUEUE', env('QUEUE_HIGH','high'))` — env-in-config-file is allowed) + `packages/marvel/config/scout.php:46` (`env('SCOUT_QUEUE_NAME', env('QUEUE_HIGH','high'))` — stale `meem-high` from the old audit already fixed). Default database-connection queue uses `QUEUE_MEDIUM` (unrouted-job fallback, by design).

## 7. Existing RabbitMQ queues — PASS (separate, untouched)
`config/rabbitmq.php:44-50`: own connection + own env vars (`RABBITMQ_QUEUE_DISTRIBUTION/EVALUATION/NOTIFICATIONS` → `coupon.distribution/evaluation/notifications`, exchanges `coupon.events`/`coupon.dlx`). Zero references to `QUEUE_HIGH`. Distribution/evaluation backbone stays independent of Laravel notification queues.

## 8. Existing Pusher flow — PASS (separate, untouched)
`toBroadcast()->onQueue(config high)` → `BroadcastNotificationCreated` → `PusherBroadcaster` → Pusher API → `private-users.{id}`. Broadcast driver is env config (`BROADCAST_DRIVER`; `log` in tests). No changes.

## 9. Files that require changes — NONE
Architecture is ALREADY CORRECT. Only gap-fillers: (a) a coupon-specific queue-resolution test (Eligible notification is absent from `NotificationQueueTest`; no test asserts a custom `QUEUE_HIGH` value flows into all four notifications); (b) the two required reports + a short architecture doc.

## 10. Files that must NOT be changed
RabbitMQ transport/consumers/outbox, Pusher/broadcasting, FCM channel/job, notification payloads (`toDatabase`), `routes/channels.php`, supervisor workers, coupon business logic, `Coupon::isPublic()`, eligibility engine, email (stays out), production `.env`.

## 11. Risks
- RISK (minor): stale `meem-*`/`catch-*` references in `api-desc/brand-import/*.md` (docs only, unrelated module — left untouched per no-unrelated-refactoring).
- RISK (process): a concurrent actor is staging files in this tree (`git add` observed); do not commit; verify `git status` before/after.
- NOT_APPLICABLE: mail/SMTP (out of scope, absent from `via()`).

## 12. Proposed minimal change
No production-code change. Add `tests/Feature/Coupon/CouponNotificationQueueTest.php` (custom-name resolution ×4 notifications + listeners + jobs-table routing proof + matching existing conventions) and docs (`COUPON_NOTIFICATION_QUEUE_FINAL_REPORT.md`, `docs/coupons/COUPON_QUEUE_ARCHITECTURE.md`). Then: run new + existing queue tests, `config:cache` safety check, worker-compat read-off (done), production = BLOCKED (no access).

## Overall: ALREADY CORRECT (to be proven by tests, not by edits)
