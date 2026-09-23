# COUPON DISTRIBUTION — DISCOVERY (READ-ONLY)

> Phase 1 read-only audit. No code, migration, schema, test, or frontend change was made.
> Repository root: `D:\work\meem`. Runtime-authoritative customer layer: `app/`. Vendored kernel: `packages/marvel/`.

## 1. Coupon domain — verified current implementation

### 1.1 Coupon model (`packages/marvel/src/Database/Models/Coupon.php`)

- Table `coupons`. Fillable: `code, slug, name, discount_type, discount, max_discount_amount, start_date, end_date, limiter, used, status, border_color, borderless`.
- `used` is system-controlled only (repository whitelist; runtime only via `OrderService::recordCouponUsage` `increment('used')`; admin capacity is `limiter`). Kept in `$fillable` for internal/test/seed only.
- Code canonicalization: `CouponCode::normalize` on `creating`+`saving`; auto-generates `COUPON_XXXXXXX` when empty; case-insensitive duplicate guard via `scopeByCode` (DB unique is collation-dependent).
- `scopeValid`: `status=true AND (limiter IS NULL OR used < limiter) AND (start_date IS NULL OR start_date <= today) AND (end_date IS NULL OR end_date >= today)`.
- `scopeByCode($code)`: canonical case-insensitive/trimmed match. Historical `orders.coupon` snapshots are matched the same way, never rewritten.
- `isPublic()` = `!assignments()->exists()`. Public = single-use per customer (enforced via `coupon_usages`). Multi-use per user REQUIRES assignment flow (`max_uses > 1`).
- Relations: `products` (coupon_product), `orders` (by `code`), `users` (coupon_usages pivot `order_id,used_at`), `couponUsages`, `assignments`, `targeting` (`CouponTargeting`), claims (via `CouponClaim`).
- Validation on `saving`: `validateCouponConfiguration` throws fail-closed (discount numeric ≥0, percentage ≤100, discount_type whitelist, max_discount_amount ≥0, limiter int ≥0, end ≥ start, status bool).

### 1.2 Targeting (`packages/marvel/src/Database/Models/CouponTargeting.php`, table `coupon_targetings`)

- Columns: `coupon_id` unique FK cascade, `mode` ENUM(`assignment`,`dynamic`,`assignment_and_dynamic`,`assignment_or_dynamic`), `require_claim` bool, `max_claims` int nullable, `claim_ttl_hours` int nullable, `rule_tree` JSON nullable. Casts: `require_claim` bool, `max_claims`/`claim_ttl_hours` int, `rule_tree` array.
- `max_claims` = first-N capacity across ALL users counting `ACTIVE(unexpired) + REDEEMED` only. `EXPIRED`/time-expired releases capacity.
- `require_claim` = customer must hold ACTIVE unexpired claim before apply/checkout (else `claim_required`).
- `claim_ttl_hours` null = no expiry; else `expires_at = now()+TTL`.

### 1.3 Claim (`packages/marvel/src/Database/Models/CouponClaim.php`, table `coupon_claims`)

- Columns: `coupon_id FK, user_id FK, status` (cast `App\Enums\CouponClaimStatus`: `ACTIVE|REDEEMED|EXPIRED`), `claimed_at, expires_at, redeemed_at, eligibility_snapshot JSON`.
- Lifecycle enforced in `app/Services/Coupon/CouponClaimService.php`:
  - `claim()`: `DB::transaction(...,3)` → `CouponTargeting FOR UPDATE` parent-row lock → `noTargeting` if missing → `claimNotRequired` if `require_claim=false` → reject if ACTIVE(unexpired) exists → reject if REDEEMED exists → `max_claims` occupied-slot count → `EligibilityEngine::evaluate` → create `ACTIVE` with `eligibility_snapshot {passed_rules, evaluated_metrics, evaluated_at}`.
  - DB `unique(coupon_id,user_id)` was dropped; duplicate-ACTIVE protection is app-level under parent lock + `coupons:reconcile duplicate_active_claims` detector.
  - `hasClaimed/getClaim`: ACTIVE + (`expires_at IS NULL OR > now`) only.
  - `markRedeemed`: `ACTIVE → REDEEMED + redeemed_at` (throws `cannotRedeemNonActiveClaim` otherwise).
  - `expireExpiredClaims`: `ACTIVE → EXPIRED` where `expires_at <= now` (command `coupons:expire-claims` hourly).
- Lock order (F-13): `CouponTargeting → CouponClaim → eligibility reads`; global: `Transaction → Order → Cart → Coupon → Targeting → Assignment → Reservation → Claim → Usage`.

### 1.4 Assignment (`packages/marvel/src/Database/Models/CouponAssignment.php`, table `coupon_assignments`)

- Columns: `coupon_id, user_id, max_uses int, used int, assigned_at datetime, expires_at datetime nullable`. Unique `(coupon_id,user_id)` is the arbiter.
- Semantics: one row = one user's grant for that coupon up to `max_uses`. Usable iff `!expired AND used < max_uses` (checked identically in `EligibilityEngine::evaluateAssignmentMode` and `CouponAssignmentValidator::validate`).
- Admin path only: `Marvel\Http\Controllers\CouponAssignmentController::store` (permission `create-coupon-assignment`) → `CouponAssignmentRepository::assignCoupon`:
  - `Coupon::findOrFail` → pre-check `exists()` → 409 `COUPON_ALREADY_ASSIGNED_TO_USER` → `DB::transaction(create+fresh)` → map unique violation (SQLState 23000 / unique/duplicate) to 409 → **`event(new CouponAssigned($assignment))` AFTER commit (line 95)** → return.
  - Known gap (F-08/N-03, still open): pre-check TOCTOU; unique constraint is the real guard.
  - `updateAssignment`: only `max_uses` (must stay ≥ `used`) + `expires_at`. `removeAssignment`: `lockForUpdate`, refuse if `used > 0`.
- No customer self-assignment path exists. No bulk/distribution assignment path exists.

### 1.5 Usage + reservation (authoritative consumption)

- `coupon_usages` (`CouponUsage.php`): `coupon_id, user_id, order_id, used_at`. Unique guards + `orders.coupon_consumed` flag make consumption idempotent.
- `CouponValidator::validate`: static gates (disabled/not_active/expired/limiter `used>=limiter`/already_used via `coupon_usages.used_at NOT NULL`/product gate). No targeting here.
- `CouponAssignmentValidator::validate`: `!has_assignments → valid`; else must hold usable assignment.
- `CouponOrchestrator::validate/validateByCode` (`app/Services/Coupon/CouponOrchestrator.php`): canonical `byCode` lookup → `require_claim` gate (`REDEEMED→already_used`, no ACTIVE→`claim_required`) → per-mode:
  - `dynamic`: `CouponValidator` static + `EligibilityEngine`.
  - `assignment_or_dynamic`: assignment counts ONLY if coupon actually grants assignments AND user holds valid one; else eligibility tree; `rejectIfPubliclyUsed` guards assigned bypass.
  - `assignment`/`assignment_and_dynamic`/legacy: strict assignment gate (+ AND eligibility for AND mode).
  - Anonymous (`$user=null`): `CouponValidator` only.
  - Infra failures bubble to 500 (F-03); only missing `coupon_targetings` table is skippable.
- `OrderService::recordCouponUsage` (on `changeOrderStatus(completed)`): coupon `FOR UPDATE` lock → revalidate reservation + Orchestrator on order items → consume `used`, write `coupon_usages` + `coupon_assignment_usages`, set `coupon_consumed`. Metrics rebuild deferred `DB::afterCommit` ONLY when `status=completed AND payment_status=payment-success`.
- `MarkCouponClaimRedeemed` (`app/Listeners/Coupon/MarkCouponClaimRedeemed.php`, on `PaymentSucceeded` which is `ShouldDispatchAfterCommit`, queued, `afterCommit=true`, `viaQueue high`): resolves coupon from authoritative `order.coupon` snapshot via `byCode` → locks exact `ACTIVE unexpired` claim for (coupon,user) → `markRedeemed`; missing claim = safe no-op; lost race (`cannotRedeem`) = no-op; transient error = rethrow for retry; reconciler flags if retries exhaust.

### 1.6 CustomerMetrics (`packages/marvel/src/Database/Models/CustomerMetrics.php` + `app/Services/Customer/CustomerMetricsService.php`)

- Table `customer_metrics`: `user_id unique, completed_orders int, total_qualifying_order_value decimal:2, first_order_at, last_order_at, coupons_used int, computed_at`.
- `rebuildForUser`: qualifies `orders WHERE user_id AND status=completed AND payment_status=payment-success`; `LEGACY_CURRENCY_UNRESOLVED` rows excluded from spend SUM only (counts/dates still include); `coupons_used` = COUNT of qualifying orders with non-empty `coupon` (per-order count, not distinct codes); `updateOrCreate` — deterministic idempotent.
- `getMetrics`: lazy rebuild if missing. `rebuildAll`: loops distinct order `user_id`s with `User::find` — NOT large-audience safe.
- This is the ONLY precomputed aggregate available for candidate pre-filtering.

## 2. Eligibility — exact rule semantics (17 rules)

Authority: `app/Services/Coupon/Eligibility/EligibilityEngine.php::evaluate($coupon,$user,$context=[])` — pure read-only, fail-closed. Grammar enforced identically at write time by `app/Services/Coupon/RuleTreeValidator.php` (`MAX_DEPTH=10` public). Metadata catalog: `app/Services/Coupon/CouponRuleMetadata.php` (admin-only endpoint).

| Rule | Value shape | Evaluation (verified) |
|---|---|---|
| `min_completed_orders` | numeric ≥0 | `metrics.completed_orders >= (int)value` |
| `max_completed_orders` | numeric ≥0 | `metrics.completed_orders <= (int)value` |
| `min_total_spend` | numeric ≥0 | `bccomp(moneyString(actual),moneyString(value),2) >= 0`; non-numeric fails closed |
| `max_total_spend` | numeric ≥0 | `bccomp(...) <= 0`; non-numeric fails closed |
| `first_order_after` | parseable datetime | `metrics.first_order_at?.isAfter(value)`; null (no orders) = fail |
| `first_order_before` | parseable datetime | `isBefore`; null = fail |
| `last_order_after` | parseable datetime | `isAfter`; null = fail |
| `last_order_before` | parseable datetime | `isBefore`; null = fail |
| `min_coupons_used` | numeric ≥0 | `metrics.coupons_used >= (int)value` |
| `max_coupons_used` | numeric ≥0 | `metrics.coupons_used <= (int)value` |
| `not_claimed` | ignored | passes iff NO `ACTIVE(unexpired)` AND NO `REDEEMED` for (coupon,user) |
| `claimed` | ignored | passes iff `ACTIVE(unexpired)` OR `REDEEMED` for (coupon,user) |
| `has_assignment` | ignored | passes iff usable assignment (`!expired AND used<max_uses`) |
| `area_in` | positive int id or non-empty list (strict int/all-digit string; floats/decimal strings rejected) | **saved-address ANY-match**: authenticated user's `addresses WHERE customer_id=user` with `governorate_id ∈ allowed` AND governorate `status=true`. NULL/no-match fails closed. No backfill. `$context['governorate_id']` accepted but IGNORED (legacy checkout key). Guarded by `Schema::hasColumn` for rolling deploys. |
| `has_email` | bool|null (null/true=require email, false=require no email) | strict trimmed RFC-valid check on user email; verification NOT required |
| `registered_after` | parseable datetime | `users.created_at isAfter(value)` exclusive |
| `registered_before` | parseable datetime | `isBefore` exclusive |
| Groups | `{operator:AND\|OR, rules:[node...]}` non-empty, nested to depth 10 | AND=all children pass; OR=any child passes (child-outcome based, no partial leak); unknown operator/empty/malformed/depth>10/unknown type = fail closed |

Modes: no targeting row = eligible (`no_targeting`); `dynamic` = tree only (empty tree = `no_rules` eligible); `assignment` = usable-assignment only; `assignment_and_dynamic` = both; `assignment_or_dynamic` = either. Unknown mode fails closed.

## 3. Current customer discovery flow (answers §3 Q1–Q10)

Routes (verified via `CouponController`, `routes/api.php`, frontend contract):

| Endpoint | Auth | Current behavior |
|---|---|---|
| `GET /api/v1/general/coupons` → `CouponController@index` → `CouponService::getCoupons` | none (throttle `public-api`) | `Coupon::valid()` + optional `search/start_date/end_date/couponsId/limit≤100/order`. **NO EligibilityEngine call. NO user scoping. NO targeting filter.** Returns `CouponResource` (id/name/slug/image/border ONLY — **never `code`**, never targeting/rules/counters). Cached by full URL (`FrontendResource::COUPONS`). |
| `GET /api/v1/general/coupons/mine` → `myCoupons` | `auth:sanctum` | Owner-scoped `coupon_assignments WHERE user_id` + `coupon_claims WHERE user_id` with `coupon` eager load; exposes owner `code` + quota/remaining/expired/status. **Only assigned/claimed coupons. No dynamic-availability section.** |
| `POST /api/v1/general/coupons/{id}/claim` → `claim` (`ClaimCouponRequest`, `whereNumber` static route ordered before claim) | `auth:sanctum` | `Coupon::findOrFail(id)` → `CouponClaimService::claim` → 201 `CouponClaimResource` (`id,coupon_id,code,status,claimed_at,expires_at`) or 409 `{reason}` only (failed_rules stay in logs) / 404. |
| `POST /api/v1/general/coupons/apply` → `applyCoupon` | `auth:sanctum` | Validates `{code}` only → `addCouponToCart(code)` (needs `auth()->user()->cart`) → preview discount, writes `cart.coupon`. 400 `{reason, code:COUPON_<REASON>}` on invalid; `already_applied` short-circuit. |

Answers:

1. Anonymous sees: all `Coupon::valid()` coupons (public AND targeted-coupons' public shell: name/image, no code, no targeting signal). Cannot distinguish targeted vs public.
2. Authenticated sees: same public list + `/mine` (own assignments+claims). No personalized eligible list.
3. `GET /general/coupons` does NOT filter by EligibilityEngine — verified (`CouponService::getCoupons` has no engine call).
4. No. A dynamically eligible user without the code cannot discover the coupon: public list hides `code`, `/mine` excludes unclaimed dynamic coupons, claim needs `{id}` the user has no reason to try, apply needs `code`.
5. Yes, targeted coupons ARE exposed in the public endpoint (same shell), but without code/rules they are not actionable — worst of both: information without capability, plus targeted users get no signal.
6. Yes, internal targeting is hidden (no rules/counters/assignment data in `CouponResource`) — good, must be preserved.
7. `claim_required` (on `coupon_targetings.require_claim`) means the user must hold an ACTIVE unexpired claim before apply/checkout; enforced in Orchestrator BEFORE eligibility. `require_claim=false` → claim endpoint returns 409 `claim_not_required`, apply directly.
8. Today the user becomes aware via: (a) assigned coupons → `CouponAssigned` notification + `/mine`; (b) public coupons → browsing homepage/list + `CouponCreated` blind fan-out (see §5); (c) dynamic/targeted coupons → **accidentally or never** (no signal).
9. No `available-for-me` concept exists. `/mine` ≠ available; public list ≠ eligible.
10. Yes, `/mine` contains ONLY assignments+claims for that user.

## 4. Assignment vs claim vs eligibility (current semantics)

- **Assignment** = admin grant (`coupon_assignments`, quota `max_uses/used`, optional `expires_at`). Created only via admin API. Emits `CouponAssigned` → per-user notification. Makes coupon visible in `/mine` + satisfies `assignment*` modes. Does NOT consume `max_claims` or `limiter` by itself.
- **Claim** = customer activation (`coupon_claims ACTIVE`). Created only via customer `POST .../{id}/claim` under parent lock + eligibility + `max_claims` capacity. Consumes `max_claims` slot while ACTIVE/REDEEMED. Makes coupon applicable at checkout (when `require_claim`).
- **Reservation** = 30-min payment hold (`coupon_reservations`, unique `order_id`); enforces `used + active_reservations < limiter` under coupon `FOR UPDATE`.
- **Usage** = permanent consumption (`coupon_usages` + `coupons.used++` + `coupon_assignment_usages`) at order `completed` (+ claim `ACTIVE→REDEEMED`). Never reversed on cancel/refund (anti-farming policy).
- **Eligibility** = pure predicate. Today it **creates nothing**: no assignment, no claim, no visibility, no notification. It only **allows** claim/apply/checkout when the customer independently discovers the coupon. This is the core gap.

## 5. Notification system (verified, reusable — DO NOT duplicate)

### 5.1 Chain

```text
Admin creates assignment → CouponAssignmentRepository::assignCoupon (commit)
  → event(new CouponAssigned($assignment)) [AFTER commit]
  → SendUserCouponAssignedNotification (ShouldQueue, viaQueue high())
  → $user->notify(new UserCouponAssignedNotification($assignment))
  → [database, fcm, broadcast] (mail dormant, never in via())

Coupon::created → CouponObserver::created (audit + event(new CouponCreated($coupon)))
  → SendUserCouponAvailableNotification (ShouldQueue, viaQueue high())
  → if assignments()->exists() return; else User::where(type=user)->chunkById(500)
  → $user->notify(new UserCouponAvailableNotification($coupon))
  → [database, fcm, broadcast]

Order payment success → PaymentSucceeded (ShouldDispatchAfterCommit)
  → MarkCouponClaimRedeemed (queued, afterCommit, high) → ACTIVE→REDEEMED
  + AssignedCouponConsumed → SendUserCouponUsedNotification (coupon.used)
```

### 5.2 Payloads (reuse as template; do NOT mislabel)

- `UserCouponAssignedNotification::toDatabase`: `{title:{en,ar}, message:{en,ar with coupon_code}, icon:tag, resource_type:coupon, resource_id, action_url:/coupons/{id}, coupon_assignment_id, coupon_id, coupon_code, max_uses, expires_at}`; `broadcastType/databaseType = coupon.assigned`.
- `UserCouponAvailableNotification::toDatabase`: same localized shape with `{coupon_id, coupon_code, coupon_type}`; `broadcastType/databaseType = coupon.available`; `action_url /coupons/{id}`.
- Dynamic-discovery payload MUST use a NEW type (e.g. `coupon.eligible` / `coupon.available_for_you`), MUST NOT reuse `coupon.assigned` (implies a grant row that does not exist) and SHOULD NOT blindly reuse `coupon.available` (that type today means global broadcast). Code-inclusion policy must be decided (see Architecture §16): `/mine`-style owner-code is safe per-user; global broadcast must never carry codes (current `UserCouponAvailableNotification` DOES carry `coupon_code` to all users — flagged as leak to fix by either removing code from global fan-out or scoping that fan-out to eligible users only).
- Frontend route expectation today: `/coupons/{id}` (Next.js must map `action_url` → details → claim → my-coupons).

### 5.3 Delivery mechanics

- **Database**: `$user->notifications()` owner-scoped; `NotificationController` (Marvel) `index/unread/show/markAsRead/markAllAsRead/destroy` under `auth:sanctum`; `type` = business id via `databaseType()` (`DatabaseChannel::buildPayload`); locale resolved from `lang` header (`CheckLangMiddleware`) with `en` fallback.
- **Pusher/broadcast**: `User::receivesBroadcastNotificationsOn()` → `users.{id}` → on-wire `private-users.{id}` (`BroadcastNotificationCreated::channelName` + `PrivateChannel`). Auth: `routes/channels.php` `users.{id}` strict `(int)user.id === (int)id`; `admin.notifications` (admin only), `user.{id}.orders`, `order.{orderId}` (owner order exists). All coupon notifications also `->onQueue(high)` for broadcast.
- **FCM**: `FcmChannel::send` reuses `toDatabase` (single source; `toFcm` override if present) → resolve `{en,ar}` maps to plain string via `App::getLocale()` → `dispatch(new SendFcmNotificationJob(title,body,data,userId))` with **notifiable-scoped user id** (never table-wide broadcast; null id = skip + warn). `SendFcmNotificationJob`: `tries=3, backoff=[30,120]`, `onQueue(config frontend.queue ?? high)`; `handle` loads `DeviceToken WHERE user_id` chunk 500, groups by `client` (`client_a|client_b` validated server-side), `sendToClient`, deletes invalid tokens, logs. `failed()` logs permanently. Device tables: `device_tokens` (2026_08_23) + `user_device_tokens` (2026_09_12, distinct schema); `POST/DELETE device-tokens` client endpoints.
- **Queues**: `config/queue.php` default `database`; semantic `queues.high/medium` from `QUEUE_HIGH/QUEUE_MEDIUM` env (fallbacks `high/medium`); `QueueName::high()/medium()` is the ONLY allowed reference (no hard-coded strings); `database` connection `retry_after=1800 > worker 1300 > job 1200 > p99 ~600s`. All coupon listeners/notifications use **high**. No per-job `$tries/$backoff/timeout` on coupon listeners (worker defaults apply) — distribution jobs MUST declare them explicitly. `failed_jobs` (`database-uuids`) + `HandleFailedQueueJob` listener on `JobFailed`.
- **Retry/failure today**: assignment notification retry = queue retry (duplicate `notify` on redelivery is possible — no dedup key); FCM invalid tokens cleaned; FCM/Pusher failures do not roll back database notification (channels are independent; database is the durable truth). `SendUserCouponAvailableNotification` fan-out has no progress tracking, no idempotency, no eligibility filter, no failure isolation (one bad user row can poison a 500-chunk — must be fixed in new design by per-user jobs).

## 6. Events that can change eligibility (verified)

| Domain event | Exists? | Coupon hook today? | Rules it can flip |
|---|---|---|---|
| `Illuminate\Auth\Events\Registered` | yes | NO (only `SendEmailVerificationNotification`) | `registered_after/before`, `has_email` |
| Address created/updated (`Address` + `governorate_id` FK, `HasChannelFilter` scoping) | model+requests exist | NO listener | `area_in` |
| Order `completed` + `payment_status=payment-success` → `recordCouponUsage` + `DB::afterCommit` metrics rebuild + `PaymentSucceeded` → `MarkCouponClaimRedeemed` | yes | metrics only, NO distribution trigger | `min/max_completed_orders`, `min/max_total_spend`, `first/last_order_after/before`, `min/max_coupons_used` |
| `CouponClaim` created (claim) | implicit (no domain event; direct service call) | NO | `claimed` / `not_claimed` |
| Assignment created (`CouponAssigned` AFTER commit) | yes | per-user notification only, NO audience re-evaluation | `has_assignment`, `assignment_and/or_dynamic` |
| `CouponCreated` (`CouponObserver::created`) | yes | blind global fan-out (no eligibility) | all (coupon newly exists) |
| Coupon updated (status/dates/limiter/targeting `CouponObserver::updated` = audit only; targeting controller updates) | yes | NO distribution event | all (activation/expiry/rule change) |
| Claim `ACTIVE→REDEEMED` (`PaymentSucceeded`), `ACTIVE→EXPIRED` (`coupons:expire-claims` hourly) | yes | NO | `claimed/not_claimed`, capacity |
| Reservation expiry (`coupons:expire-reservations` 5min), `orders:cancel-unpaid` 5min, `coupons:reconcile` detectors (read-only, NOT scheduled — F-06 open) | yes | NO | capacity only (`max_claims/limiter`), not eligibility |

Missing: NO `CouponActivated/CouponTargetingChanged/AddressChanged/MetricsChanged` domain events; NO per-user evaluation trigger on any of the above.

## 7. Required distribution triggers (recommendation, no code)

- **A. Coupon activation/publication** (create, status false→true, dates enter validity, targeting created/changed to `dynamic*`): REQUIRED. A coupon can be born eligible for thousands of existing users. Trigger = explicit distribution run (admin action or observer-dispatched job), NEVER the blind `CouponCreated` fan-out for targeted coupons.
- **B. Customer state change (address)**: REQUIRED but SCOPED. `area_in` flips only on address create/update/delete. Evaluate ONLY coupons whose tree contains `area_in` (rule-indexed candidate coupons), for THAT user only.
- **C. Registration**: REQUIRED but SCOPED. Evaluate active `dynamic*` coupons containing `registered_after/before` (or `has_email`) for THAT user only, async after commit (user must exist + metrics row ensured).
- **D. Order completion / payment success**: REQUIRED but SCOPED. After `CustomerMetricsService::rebuildForUser` commits, evaluate active `dynamic*` coupons containing order/spend/usage rules for THAT user only.
- **E. Claim/assignment events**: REQUIRED for negative transitions + combined modes. `claimed/not_claimed/has_assignment` flip on claim/assignment; use for suppression (already claimed → skip) and for `assignment_or/and_dynamic` re-evaluation. No fan-out on these; they gate fan-out.
- Explicitly NOT recommended: polling all users on a timer; re-fanning entire audience on every order/address event; synchronous evaluation inside request lifecycle.

## 8. Critical model decision (no code changed; recommendation only)

- **Model A (auto-assign every eligible user)**: REJECTED as default. Assignment = admin grant with per-user quota (`max_uses/used`), visible in `/mine`, satisfies `assignment*` gates, counted in `has_assignment`. Mass-creating assignments corrupts quota semantics, explodes `coupon_assignments` (millions of rows for a global coupon), breaks `isPublic()` (coupon flips from public to assigned-restricted the moment one assignment exists — catastrophic for public coupons), bypasses `max_claims` intent, and mislabels notifications (`coupon.assigned` for a non-grant).
- **Model B (dynamic availability + claim)**: COMPATIBLE. No rows created at distribution time; notification + `available` discovery surface; customer `claim` creates the capacity-consuming row under existing locks. Preserves assignment/claim/usage semantics, `max_claims`, `require_claim`, single-use-public invariant.
- **Model C (hybrid)**: ADOPTED as plan = Model B + explicit opt-in assignment ONLY when business explicitly orders a grant campaign (separate admin action with quota, using existing `assignCoupon` path). Distribution NEVER auto-assigns.
- Therefore: dynamic eligibility creates NEITHER assignment NOR claim; it creates **discoverability** (notification + `available` list) and only **allows** claim/apply.

## 9. Audience selection (no `User::all()`)

- Reusable: `CustomerMetrics` (indexed `user_id`; needs added indexes on `completed_orders, total_qualifying_order_value, coupons_used, first/last_order_at` for range scans), `addresses(customer_id,governorate_id)` (index added 2026_09_28), `users.created_at`, `coupon_claims(coupon_id,user_id,status)`, `coupon_assignments(coupon_id,user_id)`.
- Strategy: **rule-indexed coupon pre-filter** (only coupons whose tree contains the flipped rule family) + **per-rule candidate derivation**:
  - `area_in[ids]` → `addresses WHERE governorate_id IN ids → DISTINCT customer_id` (join `users.type=user`, governorate `status=true`).
  - `registered_after/before` → `users WHERE type=user AND created_at >/< value`.
  - `min_completed_orders/min_total_spend/...` → `customer_metrics WHERE metric >=/< threshold` (threshold extracted from tree; AND-trees intersect, OR-trees union; nested trees fall back to broader scan + engine filter).
  - `claimed/not_claimed/has_assignment` → semi-join `coupon_claims/coupon_assignments` for suppression/inclusion, never as sole fan-out driver.
- Coupon-activation runs: candidate derivation from the coupon's OWN tree (above) + chunked evaluation; user-state runs: invert (that user's metrics/addresses + coupons containing the flipped family).

## 10. Large-audience / queue posture (existing topology, must reuse)

- Connection `database`, semantic queues via `QueueName::high()/medium()` + `config('queue.queues.*')`; supervisor consumes same env values. Coupon plane stays on **high**; distribution orchestration (chunk dispatch) may use **medium** to avoid starving payment/claim redemption — decision deferred to implementation plan with load evidence.
- Pattern: `Audience Discovery (one job per run, paginates candidates chunkById/cursor 500–1000)` → `Distribution Jobs (one per chunk, carries coupon_id + user_id list + run_id)` → per-user `EligibilityEngine::evaluate` (read-only, no locks) → idempotency check → per-user `Notification::send` (which itself fans to database+broadcast+FCM via existing channels). Never `User::all()` synchronously; never evaluate inside HTTP request; never hold `FOR UPDATE` during fan-out (claim-time lock stays at claim).
- `SendUserCouponAvailableNotification`'s inline `chunkById(500){notify}` is the anti-pattern to replace: per-user queued notifications with isolated failures.

## 11. Idempotency / duplicates (mandatory; nothing sufficient exists today)

- Existing `notifications` table has NO `(user,coupon,event)` uniqueness; queue redelivery can double-`notify`; `CouponCreated` fan-out has no run concept. New design REQUIRES: `coupon_distribution_runs` (one row per trigger run, unique `dedupe_key`) + `coupon_distribution_recipients(user_id,coupon_id,run_id, status, notified_at, unique(user_id,coupon_id,run_id))` + `notifications` dedup guard (`unique(user_id,coupon_id,notification_key)` or pre-check `whereJsonContains(data->coupon_id) + type`) + job `uniqueId`/`withoutOverlapping` + `ShouldBeUnique` on chunk jobs.
- Transition rule (see §12): notify ONLY on `NOT ELIGIBLE → ELIGIBLE` (or coupon newly available to that user); `ALREADY ELIGIBLE` re-evaluations are recorded as `duplicate_skipped`, never notified.

## 12. Eligibility transition

- Required state: per `(user,coupon)` last-known `eligible bool + tree_hash + notified_at`. Notify when: first time eligible OR tree/cohort materially changed AND user still eligible AND not already notified for that version. Never notify on every evaluation, retry, address-noop-save, or unrelated order.
- Coupon rule change (`area_in` Giza→Alexandria): POLICY DECISION REQUIRED (no business rule exists). Recommended default: new `tree_hash` → new run; newly-eligible Alexandria users notified; previously-notified Giza users get NOTHING (no revocation notification; their claim path simply fails closed at next validation). Revocation/negative notifications are explicitly OUT of scope until business orders otherwise.

## 13. Rule-specific analysis (summary; full table in Architecture report)

All 17 rules evaluate SOLELY via `EligibilityEngine` (never bypass). Candidate pre-filter + trigger mapping is rule-family specific (see §9 + Architecture §13). Expensive rules (`area_in` address join, spend/order aggregates) are EXACTLY the ones `customer_metrics` + address indexes accelerate; real-time evaluation is required ONLY at claim/apply/checkout (authoritative), never for fan-out (fan-out is advisory; claim revalidates).

## 14. Lifecycle interaction (policies that need business sign-off)

- create/inactive → NO fan-out. becomes-active (status/dates/targeting) → NEW distribution run (opt-in, admin-confirmed for large audiences). expires/disabled → NO notification; in-flight jobs abort on re-check (`Coupon::valid()` + targeting still active); claims keep their TTL; consumption gates reject.
- re-enabled → NEW run (newly eligible notified; previously notified-but-unclaimed users are `duplicate_skipped` unless version bump policy says otherwise — decision required).
- targeting/max_claims/limiter changed → new `tree_hash`/version; capacity is checked at claim/apply, NEVER at fan-out (fan-out may over-notify if capacity fills mid-run — claim fails closed with `max_claims_reached`; this is ACCEPTED and must be messaged as "first-come").
- Giza→Alexandria: see §12 — no retroactive messaging without explicit business order.

## 15. Limits safety (controls stay authoritative at claim/apply/checkout)

- `max_claims` (ACTIVE+REDEEMED count under parent lock), `limiter` (`used>=limiter` + reservation `used+active_reservations<limiter`), `coupon_usages` single-use-public, assignment `used<max_uses` + `rejectIfPubliclyUsed`, product gate, dates/status — ALL remain in `CouponValidator/Orchestrator/ClaimService/OrderService`. Distribution checks NONE of them as gates (except cheap `Coupon::valid()` pre-check to avoid fanning for dead coupons); every notification is advisory and every redemption path revalidates.

## 16. Notification content (constraints)

- Localized `title.{en,ar}/message.{en,ar}`, `icon:tag`, `resource_type:coupon`, `resource_id`, `action_url:/coupons/{id}`, `coupon_id`; include `coupon_code` ONLY for per-user eligible notifications (owner-scoped, like `/mine`); NEVER include rules/snapshots/counters/limiter/other users' ids. New `databaseType/broadcastType` (e.g. `coupon.eligible`) — never `coupon.assigned` without an assignment row.

## 17. Security (verified boundaries to preserve)

- Sanctum owner scoping (`myCoupons`, notifications `findOrFail` under `$user->notifications()`), Pusher `private-users.{id}` strict int match, FCM per-user token scoping (null-id never broadcasts), `customer_id` server-derived (never trusted from client), admin triggers behind `permission:*coupon*`, no tenant/store layer to cross (single-tenant verified — no channel scoping to add).

## 18–19. Failure + observability (verified gaps)

- Today: channels independent (DB = durable truth; Pusher/FCM failures never block DB); FCM has tries/backoff + invalid-token cleanup; coupon listeners have NO explicit retry policy (worker defaults); no distribution metrics exist. Required: per-run counters (`candidate/eligible/not_eligible/queued/db_sent/pusher_sent/fcm_sent/failed/duplicate_skipped`), `failed_jobs` + `HandleFailedQueueJob` reuse, reconciler detectors extended, structured logs with `run_id/coupon_id/user_id` (never PII beyond ids).

## 20–22. DB / API / frontend impact (preview; detailed in dedicated reports)

- New tables REQUIRED (`coupon_distribution_runs`, `coupon_distribution_recipients`); `notifications` dedup support; indexes on `customer_metrics`/notification data. NO changes to `coupon_assignments/claims/usages` semantics.
- New authenticated endpoint REQUIRED: `GET /api/v1/general/coupons/available` (eligibility-filtered, paginated, no codes unless owner-claimable — exact contract in API report). Existing four endpoints UNCHANGED. Admin trigger/status endpoints (permission-gated) required for runs.
- Frontend (Next.js, REST-only): notification click → coupon details → claim → my-coupons refresh; Pusher `private-users.{id}` events `coupon.eligible/*`; FCM via device-tokens; badge from `notifications/unread`. No frontend changes in this phase.

## Direct answers Q1–Q7 (yesterday → today)

1. `area_in` newly eligible → **no signal today** (address save has no listener; public list hides code; `/mine` excludes it).
2. `registered_after` newly eligible → **no signal today** (registration has no coupon hook).
3. `min_completed_orders` newly eligible → **no signal today** (order completion rebuilds metrics but triggers no evaluation/notification).
4. No auto-assignment exists (only admin manual grant).
5. No automatic targeted notification exists (`CouponCreated` fan-out is blind/global and SKIPS assigned coupons; targeted users are not distinguished).
6. No dynamic discovery exists (`available-for-me` concept absent).
7. Missing: audience distributor + candidate selection + per-user async evaluation + transition/idempotency store + `available` API + eligible-scoped notification path (all detailed in Architecture report).

---
*Sources: `app/Services/Coupon/Eligibility/EligibilityEngine.php`, `RuleTreeValidator.php`, `CouponOrchestrator.php`, `CouponClaimService.php`, `CouponValidator.php`, `CouponAssignmentValidator.php`, `CustomerMetricsService.php`, `General/CouponService.php`, `General/CouponController.php`, `Events/CouponAssigned.php`, `Events/CouponCreated.php`, `Listeners/SendUserCouponAssignedNotification.php`, `SendUserCouponAvailableNotification.php`, `Listeners/Coupon/MarkCouponClaimRedeemed.php`, `Notifications/UserCouponAssignedNotification.php`, `UserCouponAvailableNotification.php`, `Notifications/Channels/FcmChannel.php`, `Jobs/SendFcmNotificationJob.php`, `Providers/EventServiceProvider.php`, `Observers/CouponObserver.php`, `Models/Coupon.php`, `CouponTargeting.php`, `CouponAssignment.php`, `CouponClaim.php`, `CouponUsage.php`, `CustomerMetrics.php`, `Repositories/CouponAssignmentRepository.php`, `Resources/Coupons/CouponResource.php`, `routes/channels.php`, `config/queue.php`, `api-desc/notification/backend.md`, `COUPON_FRONTEND_CONTRACT.md`.*
