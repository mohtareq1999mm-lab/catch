# COUPON DISTRIBUTION — ARCHITECTURE (PROPOSAL, NO CODE)

> Preserves: EligibilityEngine as pure authority; assignment/claim/reservation/usage semantics; `Coupon::valid()` + Orchestrator gates; database+Pusher+FCM channels; private-channel + FCM-token + owner scoping; REST-only; high-queue topology.

## 0. Layering principle (non-negotiable)

```text
EligibilityEngine:  "Is this user eligible for this coupon?" (pure, read-only, fail-closed)
Distributor:        "Which users to evaluate, when, how, once, and whether to notify"
Claim path:         the ONLY writer of capacity rows (locks, max_claims, snapshots)
Notification path:  advisory fan-out reusing existing channels (never authoritative)
```

Engine MUST NOT gain: user selection, pagination, dispatch, queue orchestration, scheduling, delivery, campaign state. All of that lives in the new `App\Services\Coupon\Distribution\*` namespace + jobs + two tables.

## 1. Proposed runtime

```text
EVENT (coupon activated | user registered | address saved | order completed+metrics rebuilt | manual admin run)
  ↓
COUPON AUDIENCE DISTRIBUTOR (service, sync decision only: build run row + candidate query + dispatch chunk jobs)
  ↓
CANDIDATE SELECTION (rule-indexed query, chunkById/cursor 500–1000, no User::all())
  ↓
DISTRIBUTION JOB (per chunk: carries run_id + coupon_id + user_ids; ShouldBeUnique; tries/backoff/timeout declared)
  ↓
ELIGIBILITY ENGINE (per user, read-only evaluate; cheap Coupon::valid() pre-check first)
  ↓
IDEMPOTENCY + TRANSITION CHECK (recipients row + notifications dedup: only NOT→ELIGIBLE notifies)
  ↓
NOTIFICATION JOB (per eligible user: $user->notify(new UserCouponEligibleNotification) → database + broadcast + fcm)
  ↓
┌──────────────────────┐
│ Database Notification│ (durable truth, owner-scoped APIs already exist)
│ Pusher private-users │ (realtime refresh)
│ FCM per-user tokens  │ (push, invalid-token cleanup already exists)
└──────────────────────┘
  ↓
CUSTOMER → GET /general/coupons/available → details → POST .../{id}/claim → /mine → apply → checkout (all revalidate)
```

Existing vs missing:

| Component | Status |
|---|---|
| `EligibilityEngine`, `RuleTreeValidator`, `CouponOrchestrator`, `ClaimService`, metrics rebuild, `Coupon::valid/byCode` | EXISTS — reuse untouched |
| `CouponAssigned`→per-user notify, `CouponCreated`→global fan-out, DB/Pusher/FCM channels, token cleanup, private channels, queue topology | EXISTS — reuse; FIX global fan-out to skip targeted coupons (or scope it) |
| Candidate selection, distribution service/jobs, runs/recipients tables, transition tracking, dedup, `available` endpoint, eligible-scoped notification class, run observability | MISSING — to build (plan report) |
| `Registered`/address/order/coupon-update hooks for distribution | MISSING — to add as thin after-commit dispatchers only |

## 2. Model decision (final): Hybrid = Model B default + opt-in grant path

- Default for ALL dynamic distribution: **notify + make discoverable + let user claim** (no assignment row, no claim row at fan-out).
- Assignment is created ONLY by explicit admin grant campaign (existing `assignCoupon` + `CouponAssigned` path with quota). Distribution service MUST NOT call `assignCoupon`.
- Why (compat): preserves `isPublic()` (no accidental flip to restricted), `max_uses/used` quota, `/mine` meaning, `claim_required`, `max_claims` capacity, `has_assignment`/`assignment_*` gates, single-use-public invariant, and notification honesty (`coupon.assigned` only when a row exists).

## 3. Candidate selection per rule family

General: parse tree → extract family thresholds → coupon pre-filter (`rule_tree` contains family) → candidate query → engine verifies (pre-filter is advisory, engine is authoritative).

- `area_in[ids]`: `addresses JOIN users(type=user) JOIN governorates(status=true) WHERE governorate_id IN ids → DISTINCT customer_id`, `chunkById`. Coupon-activation run derives `ids` from tree; address run inverts (that user + coupons containing `area_in`).
- `registered_after/before`: `users WHERE type=user AND created_at >/< value` (exclusive, UTC). Registration run: that user only. Activation run: range scan.
- `min/max_completed_orders`, `min/max_total_spend`, `min/max_coupons_used`: `customer_metrics WHERE metric </>/=/range` (requires NEW indexes §7). Order-completion run: that user only (metrics just rebuilt). Activation run: metric range scan.
- `first/last_order_after/before`: `customer_metrics WHERE first/last_order_at >/< value` (nulls excluded — engine already fails null).
- `has_email`: `users WHERE type=user AND email NULL/NOT NULL (+ strict RFC check in engine)`.
- `claimed/not_claimed/has_assignment/*assignment_*`: used for SUPPRESSION (`already claimed/redeemed → skip`; `assignment*` → require/allow assignment join), never as fan-out driver alone.
- AND-trees: intersect candidate sets (or evaluate broadest + engine filter). OR-trees: union. Depth>3 or unparseable: fall back to `customer_metrics`-bounded scan + engine filter (correctness over precision).

## 4. Queue architecture (reuse topology, fix anti-patterns)

- Connection `database`; roles via `QueueName::high()/medium()` only. Orchestrator chunk-dispatch on **medium** (bulk, interruptible); per-user evaluation+notify + claim-redemption + FCM on **high** (latency-sensitive). Exact split validated under load in P10.
- Jobs (new, all `ShouldQueue` + explicit `$tries/$backoff/$timeout` + `ShouldBeUnique` where noted):
  - `StartCouponDistributionJob` (per run: re-check `Coupon::valid()` + targeting active, build candidate paginator, dispatch chunk jobs; `tries=3, backoff=[60,300], timeout=1200`).
  - `EvaluateCouponChunkJob` (per 500–1000 ids: loop users, `getMetrics` (no rebuild storm — metrics already fresh for event runs), `EligibilityEngine::evaluate`, transition check, `notify` eligible; `ShouldBeUnique(run_id+chunk_cursor)`, `tries=5, backoff=[30,120,600], timeout=600`, chunk-level cursor persisted so retry resumes).
  - Reuse (no new infra): `SendFcmNotificationJob` (tries=3/backoff=[30,120] exists), broadcast `onQueue(high)` exists, `failed_jobs` + `HandleFailedQueueJob` exists.
- Replaces: inline `chunkById{notify}` in `SendUserCouponAvailableNotification` (must become per-user queued notifies or be bypassed for targeted coupons).

## 5. Idempotency + transition (mandatory design)

- `coupon_distribution_runs`: one row per trigger `(coupon_id, trigger_type, trigger_id, tree_hash, dedupe_key UNIQUE, status, candidate/eligible/notified/failed/duplicate counts, timestamps)`. Duplicate trigger (double observer fire, admin double-click, worker retry) → same `dedupe_key` → single run.
- `coupon_distribution_recipients`: `(run_id, coupon_id, user_id, status[pending|eligible_notified|not_eligible|duplicate_skipped|failed], notified_at, UNIQUE(user_id,coupon_id,run_id))`. Chunk retry replays safely.
- Cross-run dedup: before `notify`, check `notifications WHERE notifiable_id=user AND type=coupon.eligible AND data->coupon_id=coupon AND data->tree_hash=current` (or recipients `eligible_notified` for same `tree_hash`) → `duplicate_skipped`. Notify ONLY on `NOT ELIGIBLE → ELIGIBLE` (or first-seen eligible for that `tree_hash`).
- Job-level: `ShouldBeUnique` + DB unique violations mapped to skip (never 500).

## 6. Notification content (new class, existing channels)

- New `UserCouponEligibleNotification` (clone of assigned/available shape, new identity): `via=[database,fcm,broadcast]`; `toDatabase` = localized `title/message (en/ar, NO coupon_code in message unless owner-scoped policy allows — RECOMMEND include code per-user like /mine since recipient IS the owner; global broadcast MUST NOT)`, `icon:tag, resource_type:coupon, resource_id, action_url:/coupons/{id}, coupon_id, tree_hash, run_id`; `broadcastType/databaseType=coupon.eligible` (NEW — never `coupon.assigned`/`coupon.available`); `toBroadcast` same payload `onQueue(high)`; FCM via existing `FcmChannel` (single-source `toDatabase`).
- FIX required in existing `UserCouponAvailableNotification`: today it fans `coupon_code` to ALL users globally. Either (a) restrict `CouponCreated` fan-out to coupons with NO targeting / `mode=assignment AND no assignments` (true public), or (b) strip `coupon_code` from global payload. Targeted coupons MUST NOT use the global path.

## 7. Database design (proposal only — DO NOT create yet)

```sql
coupon_distribution_runs(
  id BIGINT PK,
  coupon_id BIGINT FK coupons.id CASCADE,
  trigger_type ENUM('coupon_activated','coupon_created','targeting_changed','user_registered','address_changed','order_completed','manual') NOT NULL,
  trigger_id VARCHAR(191) NULL,            -- actor id (user/order/address) or admin id
  tree_hash CHAR(64) NOT NULL,             -- sha256(canonical rule_tree) for versioning
  dedupe_key VARCHAR(191) UNIQUE,          -- (coupon_id:tree_hash:trigger_type:trigger_id:date?) prevents double runs
  status ENUM('pending','running','completed','failed','cancelled') DEFAULT 'pending',
  candidate_count INT DEFAULT 0, eligible_count INT DEFAULT 0,
  not_eligible_count INT DEFAULT 0, notified_count INT DEFAULT 0,
  failed_count INT DEFAULT 0, duplicate_skipped_count INT DEFAULT 0,
  started_at DATETIME NULL, finished_at DATETIME NULL, timestamps,
  INDEX(coupon_id,status), INDEX(status,created_at)
);
coupon_distribution_recipients(
  id BIGINT PK,
  run_id BIGINT FK runs.id CASCADE,
  coupon_id BIGINT FK coupons.id CASCADE,
  user_id BIGINT FK users.id CASCADE,
  status ENUM('pending','eligible_notified','not_eligible','duplicate_skipped','failed') DEFAULT 'pending',
  notified_at DATETIME NULL, error VARCHAR(500) NULL, timestamps,
  UNIQUE(run_id,user_id), UNIQUE(user_id,coupon_id,run_id),
  INDEX(run_id,status), INDEX(coupon_id,user_id)
);
-- Supporting (migration additions, not new tables):
-- customer_metrics: INDEX(completed_orders), INDEX(total_qualifying_order_value), INDEX(coupons_used), INDEX(first_order_at), INDEX(last_order_at)
-- notifications: INDEX(notifiable_type,notifiable_id,type) + generated/functional index on data->coupon_id where engine allows (else application-level pre-check)
```

Why each column: `trigger_*` = audit + dedupe scope; `tree_hash` = version/transition key; `dedupe_key` = exactly-once runs; counters = observability §9; recipient `status/notified_at/error` = resume + retry + support triage. NO changes to assignments/claims/usages columns or semantics.

## 8. Lifecycle + limits interaction

- Fan-out pre-check: `Coupon::valid()` + `targeting` still `dynamic*` + dates/status live; else abort run (`cancelled`). Mid-run expiry/disable → chunk jobs re-check per chunk and abort gracefully.
- Capacity (`max_claims/limiter/reservations/assignment quota/product gate`) is NEVER a fan-out gate (except the cheap valid-check); claim/apply/checkout revalidate under locks. Over-notification under capacity race is ACCEPTED; UX copy must say "first-come, while available" and claim surfaces `max_claims_reached`.
- Rule change (Giza→Alexandria): new `tree_hash` → new run → Alexandria newly-eligible notified; Giza previously-notified get nothing (no revocation path; their next validation fails closed). Re-enable: same versioning. Expiry/disable: silent. THESE DEFAULTS REQUIRE BUSINESS SIGN-OFF (no existing rule; flagged as policy decision, not engineering fact).

## 9. Security / failure / observability (constraints)

- Security: per-user `$user->notify` only (never notify-by-id-spoof; server-derived ids); existing `private-users.{id}` + FCM per-user scoping + notification owner queries preserved; distribution triggers admin-only (`permission:*coupon*`); `notify` jobs authorize by `run.coupon_id` ownership, never client-supplied user lists.
- Failure: DB notification = durable truth (Pusher/FCM failures never roll back DB); chunk jobs retry independently; per-user notify failure marks recipient `failed` without blocking chunk; coupon death mid-run → `cancelled`; worker crash → `retry_after(1800)` redelivery resumes from persisted cursor; `failed()` logs + `failed_jobs` triage.
- Observability: run counters (`candidate/eligible/not_eligible/queued/db/pusher/fcm/failed/duplicate_skipped`) on run row + structured logs (`run_id,coupon_id,user_id,tree_hash`, never PII) + extend `coupons:reconcile` with distribution detectors (orphan recipients, notified-but-ineligible-drift is EXPECTED post-rule-change — detector must compare `tree_hash`).

## 10. API + frontend surface (summary; full contract in API report)

- ADD (auth): `GET /api/v1/general/coupons/available` — eligibility-filtered, paginated, public shell + `claim_status` affordance, NO global codes (owner code only when claimable — policy in API report). Existing 4 endpoints UNCHANGED.
- ADD (admin, permission-gated): `POST /api/v1/coupons/{id}/distribute` (start run), `GET .../distributions` + `.../distributions/{runId}` (status/counters).
- Frontend (Next.js): `coupon.eligible` Pusher event → refresh `available` + badge (`notifications/unread`); click `action_url /coupons/{id}` → details → claim → `/mine`; FCM via device-tokens already exists. No targeting internals ever reach the client.
