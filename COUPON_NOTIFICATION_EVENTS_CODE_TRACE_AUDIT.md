# COUPON NOTIFICATION EVENTS — FULL CODE-LEVEL TRACE AUDIT

> READ-ONLY audit. No code, migration, route, test, config, or doc modified.
> Rule applied throughout: **CODE WINS** over docs, comments, and names.
> Stack: Laravel **10.30.1**, PHP 8.0|8.1 · Queue default driver **database** · Broadcast default driver **pusher**.
> Note: `MarkCouponClaimRedeemed` carries an explicit comment that Laravel 10.30 ignores per-listener `$afterCommit` on queued listeners.

---

## 1. Executive Summary

أربع إشعارات كوبون، أربع قصص مختلفة — ولا واحد منها يرسل RabbitMQ إلى Pusher مباشرة:

| Event | Real meaning (code-proven) | Who gets it | RabbitMQ? | Final delivery |
|---|---|---|---|---|
| `coupon.assigned` | A grant row was created for YOU specifically | the grantee (`assignment.user_id`) | NO | Laravel queue → DB + FCM + Pusher |
| `coupon.eligible` | You newly satisfy a dynamic-family targeting tree | each newly-eligible user (type=user only) | YES (work transport only) | worker calls `$user->notify()` → Laravel queue → DB + FCM + Pusher |
| `coupon.used` | YOUR assigned quota was consumed by order completion | the order owner, assigned-path only | NO | Laravel queue → DB + FCM + Pusher |
| `coupon.available` | A coupon stayed public (no rows + no targeting) past a 15-min grace window | EVERY user (global fan-out, deduped) | NO | Laravel queue → DB + FCM + Pusher |

The single most important correction: **RabbitMQ transports internal distribution work envelopes (ids + hashes only) between backend workers. Pusher payloads are built by Laravel Notification classes (`toDatabase()`) and delivered by Laravel's broadcast channel via the `database`-driver queue.** The only bridge is `NotificationRequestHandler:97` (`$user->notify(new UserCouponEligibleNotification(...))`).

Second most important correction: the Pusher payload is **NOT byte-identical** to `toDatabase()` as earlier docs claimed. The framework (`BroadcastNotificationCreated::broadcastWith`, vendor-verified) appends **`id`** (notification UUID) and **`type`** (the broadcast type string). None of the four notification classes override `broadcastWith()`, so every Pusher object = `toDatabase()` keys + `id` + `type`.

---

## 2. System Flow (verified, only components that exist)

```text
ASSIGNED path (no RabbitMQ):
POST /api/v1/coupons/{coupon}/assignments
 → CouponAssignmentController@store → CouponAssignmentRequest
 → CouponAssignmentRepository::assignCoupon (TX, UNIQUE arbiter)
 → event(new CouponAssigned($assignment)) [plain Laravel event, sync dispatch]
 → SendUserCouponAssignedNotification (ShouldQueue, queue high)
 → $user->notify(new UserCouponAssignedNotification($assignment))
 → queued SendQueuedNotifications job (database queue)
 → database channel (notifications table) + FcmChannel (SendFcmNotificationJob, tries 3)
   + broadcast channel → BroadcastNotificationCreated → Pusher private-users.{id}, event coupon.assigned

ELIGIBLE path (RabbitMQ as worker transport):
PUT/DELETE targeting (or activation / user triggers)
 → CouponTargetingChanged (ShouldDispatchAfterCommit) → StartCouponDistribution (queue high)
 → DistributionService::startDistribution → coupon_targetings read, TreeHash, run dedupe
 → outbox INSERT + RabbitMqCouponEventTransport::publish(envelope JSON → exchange coupon.events)
 → [coupon.distribution queue] DistributionStartHandler → chunk rows + coupon.distribution.chunk × N
 → [coupon.distribution queue] DistributionChunkHandler → coupon.user.evaluate × users
 → [coupon.evaluation queue] UserEvaluateHandler → CouponLiveCheck + EligibilityEngine
 → [newly eligible only] coupon.user.became_eligible (observation) + coupon.notification.requested
 → [coupon.notifications queue] NotificationRequestHandler (3 guards)
 → $user->notify(new UserCouponEligibleNotification($coupon, $runId, $treeHash))
 → queued job → database + FCM + broadcast → Pusher private-users.{id}, event coupon.eligible

USED path (no RabbitMQ):
order → changeOrderStatus(completed) [callback/COD/cashier]
 → recordCouponUsage() [locks, idempotent receipts] → event(new AssignedCouponConsumed(...)) [assigned path ONLY]
 → SendUserCouponUsedNotification (queue high) → $user->notify(new UserCouponUsedNotification(...))
 → queued job → database + FCM + broadcast → Pusher private-users.{id}, event coupon.used
(parallel: PaymentSucceeded → MarkCouponClaimRedeemed → claim ACTIVE→REDEEMED; public path: no event, no notification)

AVAILABLE path (no RabbitMQ):
CouponObserver::created → event(new CouponCreated) → SendUserCouponAvailableNotification::handle
 → sendIfMaturePublic(): assignments? targeting? age<15min? → all must be NO → else return false (defer)
 → sweep `coupons:detect-public` every 15 min → same gate + alreadyAnnounced() LIKE-check on notifications.data
 → per-user chunkById(500): $user->notify(new UserCouponAvailableNotification($coupon)) for ALL type=user
 → queued jobs → database + FCM + broadcast → Pusher private-users.{id}, event coupon.available
```

Transport legend: Laravel Event = in-process dispatch; Laravel Queue = `database` driver jobs table; RabbitMQ = coupon envelopes only; Pusher = broadcast channel POST; FCM = `SendFcmNotificationJob`; Database Notification = `notifications` table row.

---

## 3. `coupon.assigned` — full trace

- **Definition**: no dedicated broadcast event class. The string `coupon.assigned` is `UserCouponAssignedNotification::broadcastType()` (`app/Notifications/UserCouponAssignedNotification.php:79`), returned by `broadcastAs()`/`databaseType()`.
- **Trigger (exact)**: `CouponAssignmentRepository::assignCoupon` (`packages/marvel/src/Database/Repositories/CouponAssignmentRepository.php:61-98`) — AFTER the `DB::transaction` commits the INSERT, line 95: `event(new CouponAssigned($assignment))`. Condition = assignment row successfully created (fresh coupon `findOrFail`, no existing `(coupon,user)` row, UNIQUE arbiter maps races to 409 instead of creating). Update (`updateAssignment`) and delete (`removeAssignment`) fire NO event — VERIFIED (no `event()` call in either method).
- **Call sites of `new CouponAssigned`**: exactly ONE (the repository line above). No other producer exists.
- **Event class**: `App\Events\CouponAssigned` — plain carrier (`Dispatchable`, NOT `ShouldDispatchAfterCommit`, NOT `ShouldBroadcast`), holds `CouponAssignment $assignment`.
- **Listener**: `SendUserCouponAssignedNotification` (`EventServiceProvider:199-200`), `ShouldQueue`, `viaQueue = high`. `handle`: `$user = $event->assignment->user` (lazy BelongsTo → `users` row); **skip unless `$user->type === 'user'`** — admins/others assigned a coupon get NO notification (VERIFIED).
- **Recipient**: exactly `assignment.user_id` — one user, the grantee. Never the admin, never all users. Proven by E2E test (`assertNoDatabaseNotification($other)`, `assertNoDatabaseNotification($admin)`).
- **Notification**: `UserCouponAssignedNotification($assignment)`; `via = ['database','fcm','broadcast']` (mail dormant, never dispatched). `toDatabase` sources, field by field:
  - `title.{en,ar}` ← `resources/lang/{en,ar}/notifications.php: coupon.assigned.title` ("Coupon assigned to you")
  - `message.{en,ar}` ← same file body with `:coupon_code` ← `$assignment->coupon->code` (lazy load)
  - `icon='tag'` literal; `resource_type='coupon'` literal; `resource_id` ← `$coupon?->id`; `action_url="/coupons/{id}"` (frontend-relative; must prefix `APP_URL_FRONTEND`)
  - `coupon_assignment_id` ← `$assignment->id`; `coupon_id` ← coupon id; `coupon_code` ← coupon code (owner-only exposure, needed for Apply)
  - `max_uses` ← `$assignment->max_uses`; `expires_at` ← `$assignment->expires_at?->toIso8601String()` (null = never)
- **Queue**: YES Laravel queue (notification `ShouldQueue` + `onQueue(high)` → `database` driver job). RabbitMQ: **NO** (zero references in this chain).
- **Channel**: notifiable `receivesBroadcastNotificationsOn()` (`packages/marvel/src/Database/Models/User.php:360`) → grantee is type=user → `'users.'.$id` → framework wraps in `PrivateChannel` → Pusher `private-users.{id}`. Auth: `routes/channels.php:22` → `(int)$user->id === (int)$id` (user 55 cannot subscribe to 56 — enforced by Laravel+Pusher auth handshake).
- **Pusher object** = §payload + framework-added `id` (notification UUID), `type: 'coupon.assigned'`. Event name = `broadcastAs()` = `coupon.assigned`.

## 4. `coupon.eligible` — full trace (most important)

- **Definition**: `UserCouponEligibleNotification::broadcastType() = 'coupon.eligible'`. No `CouponEligible` event class exists (VERIFIED by search — do not confuse with the notification).
- **Chain verification (every arrow)**:
  1. `CouponTargetingController@upsert/@destroy` → `event(new CouponTargetingChanged($coupon->fresh()))` — `ShouldDispatchAfterCommit` (committed state only). Same for `CouponActivated` (observer: created-active, disabled→enabled).
  2. `StartCouponDistribution` (queue high) → `DistributionService::startDistribution($coupon, TARGETING_CHANGED, 'activation')`. Throws `NonDistributableCouponException` when targeting null OR mode ∉ {dynamic, assignment_and_dynamic, assignment_or_dynamic} → logged skip, no message. **Therefore `assignment`-mode coupons can NEVER generate `coupon.eligible`.** VERIFIED (`DistributionService.php:40`).
  3. `TreeHash::forRuleTree(rule_tree, mode)` → run `startOrJoin` (dedupe `coupon:tree_hash:trigger:scope`) → outbox `recordAndDispatch(coupon.distribution.start {run_id, coupon_id, tree_hash, trigger, trigger_scope, audience_cap})`.
  4. `RabbitMqCouponEventTransport::publish`: `AMQPMessage($envelope->toJson())` → exchange `coupon.events`, routing key = event type. (Fake transport in tests.)
  5. `DistributionStartHandler` (queue `coupon.distribution`): re-reads targeting, **refuses tree drift** (`$treeHash !== $run->tree_hash` → throw, no fan-out); candidate query = `CouponCandidateSelector::queryFor` (**`users.type = 'user'` filter VERIFIED**, line 33) ∩ cap; single-user scopes (`user:{id}`) narrow to one user; writes recipient rows + `coupon.distribution.chunk {run_id, coupon_id, tree_hash, user_ids[], chunk_index}` per chunk (≤500).
  6. `DistributionChunkHandler`: requires `run_id, coupon_id, user_ids`; terminal-run → silent 0; else per-user `coupon.user.evaluate {run_id, recipient_id, coupon_id, user_id, tree_hash}` (recorded, batched) + `chunk.completed` observation.
  7. `UserEvaluateHandler`: requires `run_id, recipient_id, coupon_id`; terminal-run → duplicate; user deleted → permanent-fail; `CouponLiveCheck::isLive` false (disabled/expired mid-flight) → NOT_ELIGIBLE, silent; else `EligibilityTransitionService::evaluate` = `EligibilityEngine::evaluate` + state machine:
     - new tree_hash → state reset to NOT_ELIGIBLE (re-opens evaluation — new version re-notifies).
     - not eligible → recipient NOT_ELIGIBLE. **No notification. Ever.** Only eligible users proceed.
     - eligible + state ∈ {NOTIFIED, NOTIFY_PENDING} for THIS tree_hash → DUPLICATE_SKIPPED, counters only.
     - eligible + fresh → recipient ELIGIBLE, state NOTIFY_PENDING, emit `became_eligible` observation + `coupon.notification.requested {run_id, recipient_id, coupon_id, user_id, tree_hash}`.
     - then `evaluation.completed {run_id, recipient_id, outcome}` observation.
  8. `NotificationRequestHandler` (queue `coupon.notifications`): terminal-run → silent + release wedged NOTIFY_PENDING; user gone → permanent-fail; **Guard 1**: user-state NOTIFIED for this tree_hash → duplicate; **Guard 2**: `coupon.eligible` notification row already exists for this coupon+tree (LIKE on `notifications.data`) → duplicate+converge; else line 97: **`$user->notify(new UserCouponEligibleNotification($coupon, $run->getKey(), $treeHash))`** → recipient NOTIFIED, state NOTIFIED, `notification.sent {run_id, recipient_id}` observation.
- **Critical condition**: notify happens ONLY on (eligible NOW) ∧ (no NOTIFIED/NOTIFY_PENDING for this tree_hash) ∧ (no eligible-row for this version). NOT every evaluation. Repeat runs with unchanged tree → duplicates suppressed at two layers (transition + handler). New tree version → everyone re-evaluated, may notify again (by design).
- **Modes that can generate it**: dynamic, assignment_and_dynamic, assignment_or_dynamic (distributable filter). `assignment` mode and untargeted coupons: never.
- **RabbitMQ**: YES — 4 work types only (`distribution.start`, `distribution.chunk` → `coupon.distribution`; `user.evaluate` → `coupon.evaluation`; `notification.requested` → `coupon.notifications`); exchange `coupon.events`, DLX `coupon.dlx`, retry `<q>.retry`, DLQ `<q>.dlq`, maxAttempts 5/consumer, outbox hold 25 attempts. **RabbitMQ → backend workers ONLY. It never touches Pusher.**
- **Recipient**: each newly-eligible `type=user` user, owner-scoped. Admins excluded at candidate selection.
- **Payload** (`toDatabase`): title/message ← `notifications.coupon.eligible.*` with `:coupon_name` ← `$coupon?->name`; `icon/resource_type/resource_id/action_url/coupon_id` as usual; **`tree_hash`, `run_id`** (observability, safe: hashes/ids only). **NO `coupon_code`, NO rules, NO metrics** (class-docblock confidentiality rule, VERIFIED honored). Pusher adds `id` + `type: 'coupon.eligible'`.

## 5. `coupon.used` — full trace

- **Definition**: `UserCouponUsedNotification::broadcastType() = 'coupon.used'`. No `CouponUsed` event class (VERIFIED).
- **Exact trigger (code line)**: `OrderService::recordCouponUsage` (`app/Services/General/OrderService.php:1019`), called ONLY from `changeOrderStatus()` line 842 inside `if ($status === 'completed')`. Callers of completed: online gateway callback (`OrderController:457`, `emitPaymentSuccess=false`, owns `PaymentSucceeded` itself), COD/cashier `markCodAsPaid/markCashierPaid` → `changeOrderStatus(completed)` (which then emits `PaymentSucceeded` at line 930-932). So the trigger is **order completion** (post-payment-success state transition), NOT application/checkout/reservation/authorization. Pending→completed is also the invoice/metrics moment; same transaction.
- **Assigned vs public path** (VERIFIED decisive): the `event(new AssignedCouponConsumed(...))` at line 1108 fires ONLY in the `$assignment` branch (order user holds a grant row). The public-path `else` branch (lines 1117-1155: `CouponUsage::firstOrCreate` + `coupons.used+1`) fires **NO event and NO notification**. Consequences: dynamic-mode unassigned users consuming via public path get NO `coupon.used`; assignment-family unassigned users throw `NOT_ASSIGNED` instead of consuming.
- **Idempotency (VERIFIED, layered)**: entry guard `if (!$order->coupon || $order->coupon_consumed) return`; coupon row `lockForUpdate`; assignment row `lockForUpdate` + quota re-check; `CouponAssignmentUsage` existence check under lock → idempotent repeat returns after ensuring flag; public path `firstOrCreate` + `wasRecentlyCreated` check throws `ALREADY_USED` on repeats; `coupon_consumed` flag set at end. Duplicate payment callbacks CANNOT duplicate usage or notification (second run exits at the entry guard).
- **Event class**: `App\Events\AssignedCouponConsumed` — plain carrier (coupon, couponAssignment, user=`$order->user`, order, remainingUses, consumedAt). Single producer (line 1108), inside `DB::afterCommit` (line 1106) — fires only if the consumption transaction commits.
- **Listener**: `SendUserCouponUsedNotification` (`EventServiceProvider:196-197`), queue high; skips non-`user` types. **Recipient = `$order->user`** (the buyer), one user.
- **Payload** (`toDatabase`): title/message ← `notifications.coupon.used.*` + `:coupon_code`; standard shell; `coupon_code` ← coupon code; **`order_id`** ← order id; **`remaining_uses`** ← `max(0, max_uses - fresh used)` computed post-increment (line 1107); **`consumed_at`** ← `now()` at dispatch. Pusher adds `id` + `type: 'coupon.used'`.
- **Parallel flow (NOT this event)**: `PaymentSucceeded` → `MarkCouponClaimRedeemed` (queue high, afterCommit) → ACTIVE-unexpired claim → REDEEMED (locked, lost-race safe no-op). Claim redemption and usage recording are separate listeners; both idempotent.
- **Queue**: Laravel queue yes; RabbitMQ no.

## 6. `coupon.available` — full independent trace

- **Definition**: `UserCouponAvailableNotification::broadcastType() = 'coupon.available'`. No `CouponAvailable` event class (VERIFIED).
- **Meaning (code-proven, NOT eligible/assigned)**: "a coupon is mature-public" = older than grace window AND has zero assignments AND has zero targeting rows. It is a **global discovery broadcast**, not a per-user eligibility signal.
- **Trigger (exact, two producers of the same send path)**:
  1. `CouponObserver::created` → `event(new CouponCreated($coupon))` → `SendUserCouponAvailableNotification::handle` → `sendIfMaturePublic()` — returns `false` for (a) any assignment row, (b) any targeting row (ANY mode), (c) age < `coupon-distribution.public_grace_minutes` (default 15). A fresh coupon therefore NEVER broadcasts at creation (targeting is added after creation in the admin flow — fail-closed against leaking codes for soon-to-be-targeted coupons).
  2. Sweep `coupons:detect-public` (cron `everyFifteenMinutes`, `Kernel.php:82`) → coupons `created_at < cutoff ∧ no assignments ∧ no targeting` → skip `alreadyAnnounced()` (LIKE `%"resource_id":{id},%` on `notifications.data` where `type=coupon.available`) → `sendIfMaturePublic()` → fan-out.
- **Recipient**: EVERY `type=user` row (`chunkById(500)`), no eligibility check, no opt-in check (VERIFIED — none exists in the send path). Admins excluded by the `type=user` where clause (E2E asserts `assertNoDatabaseNotification($admin)`).
- **Dedupe**: sweep-level `alreadyAnnounced` LIKE-check only. No per-tree versioning (no tree exists). Re-running the sweep after a broadcast → skipped. Caveat: LIKE on TEXT `data` — prefix-anchored with trailing comma to avoid id collisions (comment-acknowledged fragility, works).
- **`coupon_type` investigation (concluded)**: `toDatabase` sets `'coupon_type' => $this->coupon->type ?? null`. `coupons` table/model has NO `type` attribute (fillable: code/slug/name/discount*/limiter/used/status/dates/border*; VERIFIED in model + migrations). Eloquent returns null for unknown attributes → `?? null` → **always null**. Classification: **STALE FIELD** (dead mapping, harmless: key present, value null). Not intentional (nothing reads it), not legacy-compat (no consumer found), not broken-mapping-that-errors (null-coalesced).
- **Payload**: title/message ← `notifications.coupon.available.*` + `:coupon_code` (= real code — public by definition); standard shell; `coupon_id`, `coupon_code`, `coupon_type: null`. Pusher adds `id` + `type: 'coupon.available'`.
- **Active vs dead verdict**: ACTIVE (observer + cron + E2E + fanout regression tests all exercise it). Not a duplicate of `eligible` (different trigger, different audience, carries the code).
- **Queue**: Laravel queue yes (per-user `notify`, each `ShouldQueue`); RabbitMQ no.

## 7. Recipient Matrix (code-only)

| Event | Trigger (exact) | Sender/Producer | Recipient | Channel | RabbitMQ | Pusher | Notification class |
|---|---|---|---|---|---|---|---|
| coupon.assigned | assignment row INSERT commits (`CouponAssignmentRepository:95`) | admin API call → repository | grantee `assignment.user_id` (type=user only) | `users.{id}` → `private-users.{id}` | NO | YES, event `coupon.assigned` | `UserCouponAssignedNotification` |
| coupon.eligible | newly eligible under a dynamic-family tree version (transition service) | targeting change / activation / user triggers → distribution workers | each newly-eligible type=user user | `users.{id}` → `private-users.{id}` | YES (4 work msgs, worker transport) | YES, event `coupon.eligible` | `UserCouponEligibleNotification` |
| coupon.used | order → completed, assigned-path consumption commits (`OrderService:1108`, afterCommit) | payment completion (callback/COD/cashier) | order owner (type=user only) | `users.{id}` → `private-users.{id}` | NO | YES, event `coupon.used` | `UserCouponUsedNotification` |
| coupon.available | coupon mature-public: age>15min ∧ no assignments ∧ no targeting (observer defers; sweep delivers) | coupon creation + `coupons:detect-public` cron | ALL type=user users | `users.{id}` each → `private-users.{id}` | NO | YES, event `coupon.available` | `UserCouponAvailableNotification` |

## 8. Business Semantics Matrix (code-derived)

| Event | What actually happened? | User already owns coupon? | User merely eligible? | Code exposed? | Frontend action |
|---|---|---|---|---|---|
| assigned | a personal grant (`max_uses` quota) was created for you | YES (grant exists) | n/a | YES (needed for Apply) | show grant + Claim (if required) → Apply |
| eligible | you newly pass a dynamic targeting tree | NO (no grant needed) | YES | NO (by design) | nudge to coupon page → Claim → code appears in /mine |
| used | your grant quota was consumed by a completed order | YES (quota −1) | n/a | YES (receipt context) | show receipt + remaining_uses |
| available | a coupon is public for everyone | NO (nobody owns it) | EVERYONE (no check) | YES (public by definition) | show in public list; Apply directly |

## 9. Pusher Payload Validation (documented vs actual)

Correction to `COUPON_PUSHER_PAYLOADS.md` / Appendix A: every object additionally carries framework-added **`id`** (notification UUID string) and **`type`** (== event name). Verified in vendor `BroadcastNotificationCreated::broadcastWith` (data + id + type; no `broadcastWith()` override in any of the 4 classes).

Validated Pusher objects (actual = below; documented files miss only `id`/`type`):

- `coupon.assigned`: all 11 documented keys MATCH sources (§3); `max_uses` int, `expires_at` ISO|null, `action_url` relative. No MISSING/EXTRA/WRONG-TYPE. `coupon_code` correctly present (owner-only).
- `coupon.eligible`: documented keys MATCH; `tree_hash`/`run_id` present as documented; code correctly ABSENT. `message` interpolates `:coupon_name` ← `$coupon?->name` (translatable attribute; exact rendered form for JSON-cast names UNVERIFIED — interpolation input type edge, display-only).
- `coupon.used`: `order_id` int MATCH, `remaining_uses` int MATCH (post-increment `max(0,max−used)`), `consumed_at` ISO MATCH.
- `coupon.available`: `coupon_code` MATCH (public); **`coupon_type` STALE** — always null (no `type` column). Recommendation: document as deprecated-null; frontend must not branch on it.
- Channel validation: E2E asserts `private-users.{id}` for available/assigned/used; `users.{id}` auth callback requires self (`(int)$user->id === (int)$id`); `receivesBroadcastNotificationsOn` routes admins to `admin.notifications` (coupon listeners skip non-users, so admin channel never receives coupon.* — VERIFIED by listener guards + `assertNoDatabaseNotification($admin)`).

## 10. RabbitMQ Role (separate trace, definitive)

- Involved in exactly ONE of four events: `coupon.eligible` — and only as backend worker transport.
- Publisher: `CouponOutboxService::recordAndDispatch` → `RabbitMqCouponEventTransport` (`AMQPMessage($envelope->toJson())`, exchange `coupon.events`, routing key = event type). Outbox pattern: business rows + outbox row commit atomically; `PublishCouponOutboxJob` (tries 5 + backoff) sweeps; broker-down → rows stay pending (no loss), publishers throw after `outbox_max_attempts` (25).
- Queues/consumers: `coupon.distribution` ← start+chunk (DistributionStartHandler, DistributionChunkHandler); `coupon.evaluation` ← user.evaluate (UserEvaluateHandler); `coupon.notifications` ← notification.requested (NotificationRequestHandler). Retry `<q>.retry` (TTL per `retryDelayFor`), DLQ `<q>.dlq` + DLX `coupon.dlx`, maxAttempts 5/consumer, poison (schema-invalid) → DLQ, never business processing.
- Message content: envelope (ids/tracing/version) + minimal identifier payloads; NO codes/rules/metrics/PII (class-docblock rule, honored by all 4 producers — VERIFIED key-by-key).
- Transport classification per event: assigned = Laravel Event→Listener→Queue→Notification→Pusher(+DB+FCM); eligible = Laravel Event→Queue→Outbox→RabbitMQ→workers→Laravel Queue→Notification→Pusher(+DB+FCM); used = Order TX→Laravel Event→Listener→Queue→Notification→Pusher(+DB+FCM); available = Eloquent observer/cron→direct `notify()`→Queue→Notification→Pusher(+DB+FCM).

## 11. Notification Layer (all four)

| Class | Ctor | via | toDatabase | toBroadcast | broadcastType/As | mail |
|---|---|---|---|---|---|---|
| UserCouponAssignedNotification | ($assignment), queue high | database,fcm,broadcast | 11 keys (§3) | `BroadcastMessage(toDatabase)` VERIFIED identical pre-framework | coupon.assigned | dormant only |
| UserCouponEligibleNotification | ($coupon, $runId?, $treeHash?), queue high | same | 8 keys, no code (§4) | identical pre-framework | coupon.eligible | none |
| UserCouponUsedNotification | ($coupon,$assignment,$user,$order,$remainingUses,$consumedAt), queue high | same | 10 keys (§5) | identical pre-framework | coupon.used | none |
| UserCouponAvailableNotification | ($coupon), queue high | same | 9 keys incl. stale coupon_type (§6) | identical pre-framework | coupon.available | none |

"Payload = toDatabase() verbatim" is TRUE for the `toBroadcast()` step and FALSE for the final Pusher wire (framework appends `id`+`type`). FCM path reuses `toDatabase()` too (`FcmChannel`: title/message resolved to locale strings, remainder → `SendFcmNotificationJob(title, body, data-minus-title/message, ownerId)`, tries 3, backoff 30/120, owner-token scope). Channels unconditional in all four (`via()` has no branches).

## 12. Duplicate Notification Analysis (execution-proven)

- One assignment → exactly ONE `coupon.assigned` (single producer; update/delete emit nothing). Assignment does NOT emit `eligible` (assignment-mode non-distributable; even dynamic-mode coupons with assignments notify `eligible` only via tree evaluation, not via the grant).
- Targeting change → `eligible` only to newly-eligible-per-version users (double guards); NEVER `available` (any targeting row excludes the sweep) and never `assigned`.
- Payment success → `used` ONLY on assigned-path consumption; public-path consumption is silent (no event). No `available` (assignment row exists → sweep excludes). Claim redemption is a separate silent transition (no notification class exists for it — VERIFIED: no `coupon.redeemed/claimed` notification).
- Coupon creation → `available` ONLY IF still public after 15 min (else nothing); targeted/assigned coupons route to `assigned`/`eligible` planes instead. Sweep dedupe prevents re-announce.
- Cross-event duplicates for one business action: none found. Within-event duplicates: suppressed by (eligible: NOTIFIED/NOTIFY_PENDING + row-LIKE guards; available: announced-LIKE guard; used: `coupon_consumed` + unique receipts; assigned: UNIQUE grant + single producer).

## 13. Failure / Retry Analysis

- **Pusher fails** (broadcast driver exception inside queued job): job fails → Laravel worker retry per queue config (notification classes define no `$tries`; retries = worker `--tries` setting — deployment-dependent, UNVERIFIED value here). Database row for that notification is written by the SAME job (`database` channel runs in-job), so a broadcast failure can delay/retry the DB row too — channels are not isolated. FCM is isolated (separate `SendFcmNotificationJob`, tries 3, backoff 30/120).
- **RabbitMQ down**: outbox rows accumulate (business TXs keep succeeding); `PublishCouponOutboxJob` retries (tries 5 + backoff); `eligible` notifications DELAYED, not lost, until broker recovers. Other three events unaffected (no RabbitMQ).
- **Worker crash mid-pipeline**: `record` (not dispatch) for chunk/evaluate/completed/became_eligible rows → redelivery via outbox sweep; `recordAndDispatch` for start/notification.requested → immediate + sweep backup. Stuck NOTIFY_PENDING released on terminal runs (re-evaluated later, not skipped silently).
- **DB rollback**: `CouponAssigned`/`AssignedCouponConsumed` dispatched AFTER commit paths (repository TX returns before `event()`; consumption event inside `DB::afterCommit`; `CouponTargetingChanged`/`PaymentSucceeded` are `ShouldDispatchAfterCommit`) → rolled-back writes never notify. `CouponCreated` observer fires on created (in-TX if part of one — Eloquent observer timing; creation is its own write here, standard path).
- **Duplicate payment callbacks**: `coupon_consumed` entry guard + unique receipts → single usage + single `used` notification. Second callback exits before any event.
- **Replayed RabbitMQ messages**: event_id idempotency (consumer skips completed rows), state-row locks, cross-run NOTIFIED checks → converge, no duplicate Pusher delivery (plus Guard 2 row-check).

## 14. Legacy / Dead Code

- `packages/marvel`: NO coupon notification code (only generic order/review/payment listeners; no `coupon.*` broadcast types, no `->notify(new UserCoupon*)` outside `app/`). Single implementation confirmed — no Marvel/app duplication for these four events.
- `UserCouponAssignedNotification::toMail()`: DORMANT by documented business decision (PART 4: no email for coupons); kept so legacy callers don't fatal. Dead-but-intentional.
- `coupon_type` in available payload: STALE (always null). Dead field, harmless.
- No `CouponEligible/CouponUsed/CouponAvailable` event classes, no dead producers, no unreachable listeners found. `TestPusherEvent` (Marvel) is unrelated test scaffolding.
- `claim`/`redeem` have NO user-facing notification (only `used` on the assigned path) — absence verified, not an oversight to fix here (flagged INFO in findings).

## 15. Documentation vs Actual Code

| # | Prior doc claim | Actual code | Disposition |
|---|---|---|---|
| 1 | "Pusher payload is byte-identical to toDatabase" (PUSHER file, App. A, §30) | Framework appends `id` + `type` (`BroadcastNotificationCreated::broadcastWith`; no overrides) | DOC BUG — correct in §9 above; prior files need the 2-key addendum |
| 2 | "Distribution emits eligible to newly eligible" (guides) | Confirmed, with the precise triple condition + assignment-mode exclusion | MATCH (this audit sharpens the condition) |
| 3 | "`coupon.available` sent on coupon creation" (E2E naming suggests immediacy) | Creation-time send returns false unless already mature-public; real delivery is the 15-min sweep | DOC NUANCE — creation only arms it; sweep fires it |
| 4 | Task brief suspicion "RabbitMQ → Pusher" | RabbitMQ → workers → `notify()` → queue → Pusher; 3 of 4 events never touch RabbitMQ | ASSUMPTION REFUTED with file:line evidence |
| 5 | `coupon_type` documented as "always null (stale)" | Confirmed (`$coupon->type ?? null`, no column) | MATCH |
| 6 | `action_url` "prefix with APP_URL_FRONTEND" | Confirmed relative `/coupons/{id}` in all four | MATCH |

## 16. Findings

- **P2 — `coupon.available` global code broadcast has no per-user suppression**: every `type=user` gets every public coupon's REAL code via DB+FCM+Pusher with no opt-out/preference check in the send path. Impact: notification spam at scale + codes pushed to dormant accounts. Evidence: `sendIfMaturePublic` chunk-notify loop (no preference query). Not a leak (public by definition) but a product-scale risk.
- **P2 — `alreadyAnnounced` LIKE-scan on TEXT `data`**: full-table `LIKE '%"resource_id":N,%'` per candidate coupon per sweep run. Impact: sweep cost grows with `notifications` table size; no index usable. Evidence: `DetectPublicCouponsCommand:53-60`.
- **P3 — DB + broadcast fate-sharing**: one queued job performs database-write AND Pusher POST; broadcast outage delays the durable DB row (retry together). Impact: in-app inbox gaps during Pusher incidents. Evidence: `via()` + single `SendQueuedNotifications` job (framework behavior), no channel isolation in any of the 4 classes. FCM is correctly isolated.
- **P3 — eligible `message` interpolates translatable `name`**: `:coupon_name` ← `$coupon?->name` (HasTranslations). Impact: UNVERIFIED rendering for JSON-cast names (string vs array interpolation) — display-only edge, needs one runtime check with a JSON-name coupon.
- **INFO — public-path consumption is silent**: dynamic/OR unassigned users get no `used` receipt notification (no event in `else` branch). Consistent with "grant receipts for grants" but frontend should not expect `coupon.used` for public redemptions.
- **INFO — no claim/claim-redeemed user notification exists**: users aren't told when a claim expires or is redeemed (only `used` on assigned path). Gap only if product expects it.
- **INFO — prior-file correction**: §9's `id`+`type` addendum should be back-ported to `COUPON_PUSHER_PAYLOADS.md`, Appendix A, and §30.

## 17. Final Verdict

- `coupon.assigned` = **VERIFIED** — single producer (`CouponAssignmentRepository:95`), owner-only recipient with type guard, exact payload sources, queue+Pusher delivery, E2E channel assertions. No gaps.
- `coupon.eligible` = **VERIFIED** — all 8 chain arrows traced to file:line, newly-eligible-only triple condition with double dedupe, mode exclusion (`assignment` never), RabbitMQ role bounded to worker transport, confidentiality honored. Only UNVERIFIED item: rendered `:coupon_name` string form for JSON-cast names (display edge).
- `coupon.used` = **VERIFIED** — trigger pinned to `changeOrderStatus(completed)` → `recordCouponUsage:1108` (assigned path only, afterCommit), recipient = order owner, idempotency layered (entry flag + locks + unique receipts), public-path silence proven by absence in `else` branch.
- `coupon.available` = **VERIFIED** — meaning pinned to mature-public (age + no rows + no targeting), dual trigger (observer defer + 15-min sweep with LIKE dedupe), global type=user audience, `coupon_type` proven stale (no column). Active, not dead, not a duplicate.

## Critical final question — answered per event

| Event | WHICH USER | WHY | WHO triggers (code) | RabbitMQ? | Pusher final? |
|---|---|---|---|---|---|
| coupon.assigned | grantee (`assignment.user_id`, type=user) | a personal quota grant was just created for them | admin API → `CouponAssignmentRepository::assignCoupon:95` → `CouponAssigned` → `SendUserCouponAssignedNotification` | NO | YES (`private-users.{id}`, `coupon.assigned`) |
| coupon.eligible | each newly-eligible type=user user for this tree version | they newly satisfy a dynamic-family tree | targeting/activation/user trigger → `DistributionService` → RabbitMQ chain → `NotificationRequestHandler:97` → `notify()` | YES (transport only) | YES (`private-users.{id}`, `coupon.eligible`) |
| coupon.used | order owner (type=user) | their assigned grant was consumed by THEIR completed order | payment completion → `changeOrderStatus(completed)` → `recordCouponUsage:1108` → `AssignedCouponConsumed` → `SendUserCouponUsedNotification` | NO | YES (`private-users.{id}`, `coupon.used`) |
| coupon.available | ALL type=user users | coupon is public and old enough for global discovery | `CouponObserver::created` (arms) + `coupons:detect-public` cron (fires) → `SendUserCouponAvailableNotification` | NO | YES (`private-users.{id}` each, `coupon.available`) |

---

## Evidence index (files:lines)

- Producers: `CouponAssignmentRepository.php:61-98`, `CouponObserver.php:13-32`, `OrderService.php:841-842,1019-1160`, `DistributionService.php:30-96`, `EligibilityTransitionService.php:112-140`, `DetectPublicCouponsCommand.php:24-50`, `OrderController.php:455-457,678`.
- Events: `app/Events/CouponAssigned.php`, `CouponCreated.php`, `AssignedCouponConsumed.php`, `Coupons/CouponLifecycleEvent.php`, `Coupons/CouponTargetingChanged.php`.
- Wiring: `EventServiceProvider.php:196-210` (+imports 16-18, 64-66), `routes/channels.php:18-24`, `Console/Kernel.php:82`.
- Listeners: `SendUserCouponAssignedNotification.php`, `SendUserCouponUsedNotification.php`, `SendUserCouponAvailableNotification.php:29-65`, `StartCouponDistribution.php`, `Coupon/MarkCouponClaimRedeemed.php`.
- Notifications: `Notifications/UserCoupon{Assigned,Eligible,Used,Available}Notification.php` (full), `Notifications/Channels/FcmChannel.php`, `Jobs/SendFcmNotificationJob.php:18-19`.
- RabbitMQ: `Distribution/{DistributionService,DistributionRunService,EligibilityTransitionService,CouponLiveCheck}.php`, `Distribution/Events/{CouponEventEnvelope,CouponDistributionEvents}.php`, `Distribution/Messaging/{RabbitMqTopology,RabbitMqCouponEventTransport,FakeCouponEventTransport}.php`, `Distribution/Consumers/{DistributionStartHandler,DistributionChunkHandler,UserEvaluateHandler,NotificationRequestHandler,AssertsCouponPayload}.php`, `Distribution/Selection/CouponCandidateSelector.php:33`, `Jobs/Coupons/PublishCouponOutboxJob.php:22-29`.
- Framework proof: `vendor/.../Notifications/Events/BroadcastNotificationCreated.php:100-135`, `User.php:360-367` (Marvel), `config/{queue.php:16,broadcasting.php:18}`.
- Lang: `resources/lang/{en,ar}/notifications.php:42-59`.
- Tests (behavioral corroboration, not re-run): `CouponNotificationE2ETest.php:40-115`, `UserNotificationTest.php:584-739`, `CouponDistribution/{DistributionPipeline,NotificationPipeline,TransitionAndRunLifecycle,GlobalFanoutFix,ProductionReadinessRegression,MySqlConcurrency}Test.php`, `Notifications/RealAuthenticatedUserNotificationE2ETest.php:438`, `Notifications/NotificationAuthorizationTest.php:17`.

## Unresolved ambiguities (UNVERIFIED)

1. Queue worker `--tries` for the `SendQueuedNotifications` jobs (no `$tries` on notification classes) — deployment config, not in repo code searched.
2. Rendered `:coupon_name` string when `coupons.name` is a JSON-cast array (eligible message edge).
3. `BROADCAST_DRIVER`/`QUEUE_CONNECTION` runtime env values (defaults: pusher/database; `.env` out of read scope by secret policy).
