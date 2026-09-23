# COUPON NOTIFICATION + CLAIM REQUIREMENT + DISTRIBUTION — FINAL REPORT

> Status: IMPLEMENTED + TESTED + RUNTIME VERIFIED (test env) + PAYLOAD
> VERIFIED (database JSON + recorded Pusher payload) + REGRESSION VERIFIED
> (neighbor suites; pre-existing failures proven identical on baseline).
> Labels: **VERIFIED** / **INFERRED** / **UNVERIFIED** throughout.

---

# Executive Summary

Three production failures were diagnosed; two required code changes, one
required no source change (operational fix):

1. **Distribution (`CouponEventTransport not instantiable`)** — binding,
   provider, and registration are all correct in source (VERIFIED, unchanged
   since commit `79aed38`); the failing production workers booted a stale
   container. No code change is the architecture-correct fix; deploy ritual
   (rebuild caches + restart workers) documented below.
2. **Assignment notification missing for targeting-less coupons** — no
   targeting gate exists anywhere in the assignment path (VERIFIED). Added
   `requires_claim` to all four coupon notifications, plus an observability
   log on the listener's intentional non-user skip.
3. **Admin alert `Invalid UTF-8 codepoint escape sequence`** — two stacked
   defects found and fixed: a malformed `\u{0627` escape (missing `}`), AND a
   `Queueable::$queue` property collision that fatals the alert class at load
   (the deeper, load-bearing bug). Plus UTF-8 normalization of the
   alert-bound exception copy.

`requires_claim` ships as a genuine JSON boolean from the single source of
truth `coupon_targetings.require_claim` (no targeting row ⇒ `false`).

---

# Original Production Failures

- **A:** `Target [CouponEventTransport] is not instantiable while building
  [WorkCommand, CallQueuedListener, DistributionService,
  DistributionRunService, CouponOutboxService]`, trigger `coupon_activated`,
  coupons 7/8/11/12 (earlier 18).
- **B:** Admin assigns targeting-less coupon → user gets no `coupon.assigned`.
- **C:** `Failed to deliver queue-failure admin alert` /
  `Invalid UTF-8 codepoint escape sequence`.

---

# Root Cause — CouponEventTransport

- Declaration: **interface**,
  `app/Services/Coupon/Distribution/Messaging/CouponEventTransport.php`
  (5 methods) [VERIFIED].
- Implementations both present: `RabbitMqCouponEventTransport` (production,
  constructor-less, lazy AMQP connection) and `FakeCouponEventTransport`
  (tests) [VERIFIED].
- Binding present and unconditional (only branch: unit-test fake):
  `CouponDistributionServiceProvider::register()`;
  provider registered in `config/app.php:176`; local
  `bootstrap/cache/services.php` contains it [VERIFIED].
- All introduced atomically in `79aed38`; `bootstrap/cache/*` is git-ignored
  (deploy-generated) [VERIFIED].
- Conclusion: the production **worker processes booted a stale container**
  (stale provider manifest and/or workers not restarted after deploy). The
  throw starts at `WorkCommand`, i.e. inside a long-lived worker [INFERRED,
  high confidence — production runtime not observable from here].
- RabbitMQ credentials/config are NOT implicated: resolution fails before
  any connection, and the transport constructor takes no config [VERIFIED].
- **No source change made** — the binding is already the smallest
  architecture-correct form. Fix is operational (see Production Validation).

---

# Root Cause — Assignment Notification

Full chain traced route → controller → request → repository → event →
listener → notification → database/FCM/broadcast [VERIFIED, file:line map in
the companion audit `COUPON_DISTRIBUTION_AND_ASSIGNMENT_NOTIFICATION_FAILURE_AUDIT.md`]:

- `POST coupons/{coupon}/assignments` → `CouponAssignmentController@store` →
  `CouponAssignmentRequest` (no targeting rules) →
  `CouponAssignmentRepository::assignCoupon` → `event(new
  CouponAssigned($assignment))` AFTER commit, duplicates 409 with no event →
  `SendUserCouponAssignedNotification` (ShouldQueue, high) → guard
  `type !== 'user'` → `$user->notify(new UserCouponAssignedNotification)` →
  via database+fcm+broadcast → `private-users.{id}`.
- **Zero `targeting|distribution|eligible` references** in the repository,
  request, event, listener, and notification [VERIFIED]. `coupon.assigned`
  is code-independent of targeting, exactly per the business rule.
- Ranked suspects for the observed symptom: (1) silent user-type guard skip
  [INFERRED prime], (2) queued listener stalled with the same worker
  incident [INFERRED], (3) push-only perception gap (no device tokens /
  Pusher subscription) [INFERRED]. Prod DB/`failed_jobs` needed to confirm
  [UNVERIFIED].
- Change made: the intentional type-guard skip now logs
  `coupon.assigned.skipped_non_user` (coupon/assignment/user ids) instead of
  vanishing silently. No delivery semantics changed [VERIFIED by suite].

---

# Root Cause — UTF-8 Admin Alert

Two stacked defects, both fixed:

1. **Malformed escape (reported symptom):**
   `AdminQueueJobFailedNotification.php:42` contained `\u{0627\u{0626}`
   (`\u{0627` missing `}`). PHP evaluates `\u{…}` in double-quoted strings
   at runtime → `Invalid UTF-8 codepoint escape sequence` from `toDatabase()`
   for EVERY failed job's alert [VERIFIED]. Fixed with the missing `}`.
2. **Property collision (deeper, load-bearing — found via test run):**
   the class declared `public string $queue` while `Queueable` defines
   `$queue` → **fatal at class load**, and `onQueue()` would have clobbered
   the failed job's queue with our own dispatch queue. The alert could never
   have worked in this tree. Renamed to `$failedQueue`; payload key stays
   `'queue'` (wire-compatible) [VERIFIED by test].
3. **Hardening:** `HandleFailedQueueJob` now passes an
   `mb_convert_encoding($message, 'UTF-8', 'UTF-8')` copy to the alert;
   the original stays intact in logs/`failed_jobs`, so nothing meaningful is
   discarded [VERIFIED].
4. Scope: the listener is wired globally (`JobFailed` → all jobs), so the
   fix restores alerting for transport failures and everything else
   [VERIFIED]. Original exceptions were always preserved in logs +
   `failed_jobs` — only the inbox copy was lost [VERIFIED design].

---

# Coupon Notification Architecture

| Type | Class | Channels | Key routing |
|------|-------|----------|-------------|
| `coupon.assigned` | `UserCouponAssignedNotification` (assignment model) | database+fcm+broadcast | listener `SendUserCouponAssignedNotification`, high queue, user-type guard |
| `coupon.eligible` | `UserCouponEligibleNotification` (coupon + run/tree) | database+fcm+broadcast | distribution consumer; **never carries `coupon_code`** (confidentiality) |
| `coupon.used` | `UserCouponUsedNotification` (coupon+assignment+user+order+remaining) | database+fcm+broadcast | `AssignedCouponConsumed` listener |
| `coupon.available` | `UserCouponAvailableNotification` (coupon) | database+fcm+broadcast | `CouponCreated` fan-out, mature-public only, skips assigned coupons |
| admin alert | `AdminQueueJobFailedNotification` | database | `HandleFailedQueueJob` on global `JobFailed` |

`CouponEventTransport`/RabbitMQ/outbox participate ONLY in the distribution
plane (`coupon.eligible` path). `coupon.assigned` shares no code with it
(zero cross-references [VERIFIED]); both planes share only the `high`-queue
worker fleet operationally.

---

# requires_claim Source of Truth

**`coupon_targetings.require_claim`** (boolean-cast column on
`Marvel\Database\Models\CouponTargeting`) [VERIFIED]:

- `CouponClaimService::claim()`: no targeting row → `noTargeting`;
  `require_claim=false` → `claimNotRequired`; otherwise ACTIVE-claim +
  eligibility + capacity checks.
- `CouponOrchestrator::validate()`: gate fires only `if ($targeting &&
  $targeting->require_claim)` (ACTIVE unexpired claim required; REDEEMED →
  `already_used` fail-closed). Targeting-less coupons behave as pure
  assignment with no claim gate.
- Precedent: `AvailableCouponsService::present()` already exposes
  `'requires_claim' => (bool) ($targeting?->require_claim)`.
- Single exposure point (no second truth):
  `App\Services\Coupon\CouponClaimRequirement::forCoupon($coupon)` →
  `(bool) ($coupon?->targeting?->require_claim ?? false)`. Not derived from
  mode, assignment existence, availability, or notification type [VERIFIED].

# requires_claim Semantics

- `true` = the user must perform Claim (`POST /coupons/{id}/claim`) before
  the coupon can be used through the applicable flow.
- `false` = no Claim step is required (includes: targeting-less coupons,
  `require_claim=false` rows, and post-consumption rows which keep the
  coupon's canonical configuration value).
- Distinct from claim *state*: `requires_claim=true` + `claim_status=
  claimed` means "claimable in principle, already claimed by this user."
  No `claim_status` field was added — the API contract does not require it
  (claim state remains queryable via existing claim endpoints).

# Notification Payload Contracts

All four `toDatabase()` payloads now include `'requires_claim' =>
CouponClaimRequirement::forCoupon($coupon)` as a native PHP bool
(assigned resolves the coupon via `$this->assignment->coupon`):

- `coupon.assigned`: + `requires_claim` alongside assignment/code/quota keys.
- `coupon.eligible`: + `requires_claim`; still no `coupon_code` (verified by
  test asserting its absence).
- `coupon.used`: + `requires_claim` (canonical coupon configuration value;
  usage recording, quota, order completion untouched).
- `coupon.available`: + `requires_claim` alongside code/type keys.

# Assignment Without Targeting

E2E-proven: coupon with NO targeting row + type-`user` assignee →
`coupon_assignments` row → `CouponAssigned` → queued listener → database
notification with `requires_claim === false` + broadcast to
`private-users.{id}` with `false` [VERIFIED by
`test_assigned_without_targeting_carries_requires_claim_false`].

# Eligibility Flow

`UserCouponEligibleNotification` with `require_claim=true` targeting →
database + broadcast carry `true`, no code leak [VERIFIED]. Without
targeting → `false` [VERIFIED].

# Claim Flow

Unchanged. `claim()` still requires the targeting row and
`require_claim=true`; orchestrator gate unchanged. Claim suites green
(18+8+13 tests) [VERIFIED].

# Distribution Flow

Unchanged (no source edits). Outbox, RabbitMQ topology, dedupe, lease
claims, consumer retry/DLQ intact; `DistributionTriggerTest` (6) and
`OutboxServiceTest` (6) green [VERIFIED].

# Database Payload

`notifications.data` JSON stores a genuine boolean: test reads the raw row
and asserts `json_decode(...)['requires_claim'] === false` [VERIFIED].
`assertDatabaseNotification` decodes via the model's array cast; strict
`assertIsBool`/`assertSame` used everywhere (never truthy checks)
[VERIFIED].

# Pusher Payload

`toBroadcast()` reuses `toDatabase()`, so the `BroadcastMessage` carries the
same bool; the E2E `RecordingPusher` captures the real broadcaster output
and the test recursively locates `requires_claim` in the recorded payload
(array or JSON string) asserting `is_bool` + exact value for all four types
[VERIFIED]. `toDatabase()` and final broadcast payload verified
independently (not assumed identical).

# FCM Payload

`FcmChannel` reuses the database payload (no `toFcm` override on these
notifications), resolves localized title/body, and dispatches
`SendFcmNotificationJob` with remaining keys as data — `requires_claim`
travels as a bool exactly like the pre-existing int keys (`max_uses`,
`resource_id`, `coupon_id`), so no new FCM contract is introduced
[VERIFIED by code]. Transport-level note: if Firebase ever requires strict
string:string data, the frontend must parse accordingly — same caveat
already applied to the existing int keys; nothing in the current
`FcmService` (Kreait `withData`) rejects them. FCM can never break the
database write (channel order + job isolation) [VERIFIED].

---

# Idempotency

- Assignment: `unique(coupon_id,user_id)` → 409, no event, exactly-1
  notification per assignment (existing `assertSentToTimes … 1` tests still
  green) [VERIFIED].
- Distribution: dedupe key + outbox lease + `event_id` consumer idempotency
  untouched [VERIFIED by unchanged code + green suites].
- `requires_claim` is a pure function of current targeting state — no rows,
  no events, no duplicate surface [VERIFIED].
- Admin alert: unchanged delivery semantics (database channel to
  super-admins), now actually loadable [VERIFIED].

---

# Tests

New: `tests/Feature/Notifications/CouponNotificationRequiresClaimTest.php`
(9 tests, 58 assertions) — real-pipeline E2E (no mocks) covering the
required matrix:

| Scenario | Targeting | Assignment | requires_claim | Notification |
|----------|-----------|------------|----------------|--------------|
| Public coupon | none | no | `false` | available (db+broadcast) |
| Direct assignment | none | yes | `false` | assigned (db+broadcast+raw JSON) |
| Direct assignment | dynamic, claim=true | yes | `true` | assigned (db+broadcast) |
| Dynamic eligibility | dynamic, claim=true | no | `true`, no code | eligible (db+broadcast) |
| Eligibility w/o targeting | none | no | `false` | eligible payload unit |
| Assigned coupon used | dynamic, claim=true | yes | `true`, uses intact | used (db+broadcast) |
| Queue alert | — | — | — | admin `toDatabase` builds, UTF-8 clean, JSON-safe |
| Transport | — | — | — | interface resolves (fake branch), prod class implements contract |
| Helper | none/false/true | — | `false`/`false`/`true` | source-of-truth pin |

Regression runs (all green): new suite 9/9; `CouponNotificationE2ETest`
5/5; `UserNotificationTest` 31/31; `CouponFinalContractTest` 9/9;
`CouponAssignment` 43/43; `NotificationQueueTest` 4/4; claim lifecycle 18/18;
claim integration 8/8; claim 13/13; checkout revalidation 15/15;
distribution trigger 6/6; outbox 6/6. `php -l` clean on all touched files.
test-guard + clean-code-guard passes applied (unused imports removed; no
behavioral findings).

Pre-existing failures proven identical on stashed baseline (my changes
excluded): Notifications-dir order/auth/real-Pusher tests (9 errors +
17 failures — network/env-dependent) and
`CouponEligibilityLifecycleTest::claimed_rule_passes_when_claim_is_expired`
(1 failure — CLAIMED-rule-vs-expired semantics, untouched area). The full
`tests/Feature/Coupon` directory run (incl. lock/concurrency stress) was
abandoned mid-run as too slow for this loop; the claim/eligibility/checkout
files above were run individually instead.

---

# Production Validation

- [x] Container binding resolves (`app(CouponEventTransport::class)` in
      test container; production branch constructs `RabbitMq…` directly).
- [x] Coupon activation/manual distribution code paths unchanged and
      covered by green suites.
- [x] Assignment (targeting-less and targeting) E2E green.
- [x] Claim/eligibility/checkout suites green.
- [x] Database + Pusher payload booleans verified; FCM path code-verified.
- [x] Admin alert builds + JSON-serializes (test); transport-failure text
      used as the regression message.
- [ ] Production deploy ritual (UNVERIFIED — needs ops):
      `optimize:clear && optimize`, restart `queue:work` fleet AND
      `coupon:consume` supervisors, confirm
      `app(CouponEventTransport::class)` in prod console, re-fire coupons
      7/8/11/12 (dedupe-safe), force one staging job failure → admin alert
      arrives.
- [ ] Live Pusher/FCM delivery (UNVERIFIED — RecordingPusher + no-token
      no-op covered the backend contract only).

---

# Files Changed

- `app/Services/Coupon/CouponClaimRequirement.php` (NEW, 1 pure function)
- `app/Notifications/UserCouponAssignedNotification.php` (+bool + import)
- `app/Notifications/UserCouponEligibleNotification.php` (+bool + import)
- `app/Notifications/UserCouponUsedNotification.php` (+bool + import)
- `app/Notifications/UserCouponAvailableNotification.php` (+bool + import)
- `app/Notifications/AdminQueueJobFailedNotification.php` (UTF-8 brace fix;
  `$queue` → `$failedQueue` collision fix)
- `app/Listeners/HandleFailedQueueJob.php` (alert-bound UTF-8 normalization)
- `app/Listeners/SendUserCouponAssignedNotification.php` (skip-observability log)
- `tests/Feature/Notifications/CouponNotificationRequiresClaimTest.php` (NEW)

# Files Inspected

Transport/messaging/distribution/outbox/trigger/consumer/topology/config/
bootstrap/routes/assignment repository+controller+request/event/listener x4
notifications/FCM channel+service+job/channels/User model/lang en+ar/
claim service/orchestrator/available-coupons/targeting model+resource/
E2E harness/recording pusher/existing coupon suites (full list in the
companion audit).

---

# Final Verification

Acceptance criteria:

- **A. Distribution:** binding resolves; correct transports; activation and
  manual paths traced (same chain) and covered; outbox/RabbitMQ/idempotency
  untouched. Production worker restart remains an UNVERIFIED ops step.
- **B. Assignment:** works with NO targeting (E2E green); event/listener/
  queue/type-guard verified; FCM+broadcast dispatched; targeting NOT
  required (proven by absence + passing targeting-less test).
- **C. Claim:** all four notifications expose native-boolean
  `requires_claim` from `coupon_targetings.require_claim`; no second truth.
- **D. Payload:** database raw JSON + recorded Pusher payload asserted;
  FCM path code-verified with transport-limitation note.
- **E. UTF-8:** alert builds, UTF-8-clean, JSON-safe; deeper class-load
  fatal also fixed; failure reporting no longer hides the original.
- **F. Regression:** neighbor suites green; remaining failures proven
  pre-existing on baseline.

# Remaining Risks

1. Production workers still stale until the deploy ritual runs (ops action).
2. Failure-B's exact production trigger (user type vs stalled queue vs
   push perception) needs prod DB/`failed_jobs` confirmation; the new skip
   log makes the next occurrence self-diagnosing.
3. Additive `requires_claim` key: tolerant JSON consumers unaffected; any
   strict-equality consumer (none found) should be checked on rollout.
4. `toDatabase()` resolves targeting live per send (one indexed query);
   negligible, but high-volume fan-outs add one query per recipient.
5. Pre-existing failures (order/auth/real-Pusher suites, one eligibility
   semantics test, slow lock/concurrency files) are out of scope and
   unchanged.
