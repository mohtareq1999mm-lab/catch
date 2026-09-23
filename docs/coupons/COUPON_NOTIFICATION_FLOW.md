# Coupon Notification Flow (all four notifications)

## Decision
Email is OUT OF SCOPE for coupon notifications. Required: Database (in-app) + Pusher realtime + FCM push. Assignment must succeed with no SMTP, no email, no mail config. `UserCouponAssignedNotification::via()` = `['database','fcm','broadcast']` only; `toMail()` dormant.

## Flow
`Admin POST assignments → CouponAssignmentRepository::assignCoupon → commit → event(new CouponAssigned($assignment)) → SendUserCouponAssignedNotification (queue high, ShouldQueue) → $user->notify(new UserCouponAssignedNotification($assignment)) → database + FcmChannel + broadcast`.

## Payload (single source: `toDatabase`)
`{title{en,ar}, message{en,ar}, icon:tag, resource_type:coupon, resource_id, action_url:/coupons/{id}, coupon_assignment_id, coupon_id, coupon_code, max_uses, expires_at}`. `broadcastType=databaseType=coupon.assigned`.
- Guards: listener drops non-`USER` types; FCM skips when title/body unresolvable (logs `FCM skipped`).
- Pusher: `toBroadcast` = same payload on queue high; channel `users.{userId}` (owner-only, `routes/channels.php:22-24`); admin channel `admin.notifications` unchanged.
- FCM: `FcmChannel` reuses DB payload, resolves localized title/body by app locale, dispatches `SendFcmNotificationJob(title, body, data-minus-title/message, ownerId)` — owner tokens only, invalid tokens removed, tries=3 backoff [30,120].
- Frontend: prefix `action_url` with `APP_URL_FRONTEND` (backend-relative; P1 email-host defect N/A now that mail is out of scope). Subscribe `users.{id}` event `coupon.assigned` → refresh `GET mine`.

## Isolation
User A never receives User B (channel auth + owner-scoped FCM + per-user notify). Verified: `channels.php` owner check, FCM `notifiable->getKey()` scoping, no global broadcast.

---

## All four notifications (extended coverage)

| Notification | Trigger | Recipient | Timing | Queue | Broadcast | Channel | Dedup |
|---|---|---|---|---|---|---|---|
| `UserCouponAssignedNotification` | `CouponAssigned` after grant commit | that user (customers only; admins skipped+logged) | immediate (queued high) | high | `coupon.assigned` | `users.{id}` | single-recipient event |
| `UserCouponAvailableNotification` | coupon proven mature-public (creation listener defers; `detect-public` sweep, grace 4min) | all customers, chunked 500 | ≥4min after creation | high | `coupon.available` | `users.{id}` | LIKE-on-notifications guard + grace |
| `UserCouponEligibleNotification` | transition NOT_ELIGIBLE→ELIGIBLE (new tree re-opens) | that user | after delayed run | high | `coupon.eligible` | `users.{id}` | `user_states` NOTIFIED per version |
| `UserCouponUsedNotification` | `AssignedCouponConsumed` after commit | order owner | after completion | high | `coupon.used` | `users.{id}` | one completion → one event |

Pusher path (backend): `BroadcastMessage->onQueue(high)` → queued `BroadcastNotificationCreated` → `PusherBroadcaster` → REST API (cluster `eu`, TLS) → RUNTIME-PROVEN publishes (500–780ms, zero failed; 5/5 assigned + used received by real client historically). Payloads: whitelisted IDs/codes/counts/timestamps only.

---

## How a coupon notification actually reaches the customer (§19)

```text
Coupon business event (CouponAssigned | CouponCreated | AssignedCouponConsumed | internal eligible-transition)
        ↓  [REST or INTERNAL — see per-event note]
Laravel Listener (ShouldQueue, QueueName::high())
  CouponAssigned → SendUserCouponAssignedNotification (EventServiceProvider:199)
  CouponCreated → SendUserCouponAvailableNotification (…:202, defers unless mature-public)
  AssignedCouponConsumed → SendUserCouponUsedNotification (…:196)
  eligible-transition → NotificationRequestHandler (RabbitMQ CONSUMER — internal, not a Laravel listener)
        ↓  $user->notify(new UserCouponXxxNotification(...)) on queue `high`
SendQueuedNotifications (Laravel Queue — database driver)
        ↓  three channels run INDEPENDENTLY; FCM/Pusher failure never deletes the DB row
database → `notifications` table row (durable truth; `databaseType` = `coupon.assigned|available|eligible|used`)
fcm → FcmChannel → SendFcmNotificationJob (owner tokens only)
broadcast → BroadcastMessage(onQueue high) → BroadcastNotificationCreated event
        ↓  PusherBroadcaster (BROADCAST_DRIVER=pusher)
Pusher API (app cluster `eu`, TLS, server-side secret — never exposed)
        ↓  private-users.{id}  (channel route `users.{id}`, owner-only: channels.php:22-24)
client subscription (frontend Echo: subscribe + auth via POST /broadcasting/auth sanctum → 200 owner / 403 others [PRIOR-RUNTIME matrix])
```

Event origins labeled: `CouponAssigned` = REST (`POST assignments`, AFTER commit [SRC]); `CouponCreated` = REST (`POST /coupons`, observer [SRC]); `AssignedCouponConsumed` = INTERNAL (payment completion chain [SRC]); eligible-transition = INTERNAL (RabbitMQ consumer after delayed run [SRC]).

## RabbitMQ is NOT the queue is NOT Pusher
- **RabbitMQ** (`coupon.events` backbone): carries DISTRIBUTION/EVALUATION work between backend stages — outbox rows → minutely sweep → broker messages → `DistributionStartHandler` / `NotificationRequestHandler` consumers. No user data fan-out, no browser contact. Proves evaluation happened; DLQs empty [PRIOR-RUNTIME].
- **Laravel Queue** (database driver; `high|medium|low`): carries NOTIFICATION/listener work — `SendUserCoupon*Notification`, `StartCouponDistribution` (event listener → delayed outbox), `MarkCouponClaimRedeemed`, `BroadcastNotificationCreated`. This is what "queued high" means in the matrix.
- **Pusher**: last-mile realtime socket to the browser ONLY. It never evaluates, never stores, never decides. Backend publishes `coupon.*` with whitelisted payloads; the client just renders and refreshes `GET mine`.
- **Email**: OUT OF SCOPE (business decision). `via()` has no `mail`; `toMail()` dormant.

## Subscribe authorization (who may listen)
`POST /broadcasting/auth` (sanctum): `users.{id}` → 200 iff `auth.id == {id}`; `admin.notifications` → 200 iff `type=admin`; otherwise 403 [PRIOR-RUNTIME]. So user A CANNOT subscribe to user B's `coupon.*` events even if they guess the channel name.

ببساطة: الإشعار بيقول للمستخدم "في كوبون ليك" لكنه مش تصريح استخدام — الأهلية بتتحسب من جديد عند الـ claim/apply/checkout. كل إشعار بيروح لصاحبه بس، ومرة واحدة لكل نسخة استهداف.
