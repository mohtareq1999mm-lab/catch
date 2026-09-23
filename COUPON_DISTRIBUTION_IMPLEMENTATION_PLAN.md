# COUPON DISTRIBUTION — IMPLEMENTATION PLAN (PHASED, NO CODE YET)

> Read-only phase: files/classes below are LIKELY affected (verified to exist unless marked NEW). No file has been modified.
> Hard rules for all phases: reuse Engine/Orchestrator/ClaimService/channels/queues; never auto-assign; never expose targeting internals; claim path remains the only capacity writer.

## P0 — Architecture / contract sign-off (no code)

- Inputs: `COUPON_DISTRIBUTION_DISCOVERY.md`, `COUPON_DISTRIBUTION_ARCHITECTURE.md` (this pack).
- Decisions REQUIRED before P1: (1) Model B-default + opt-in grants confirmed; (2) `coupon_code` in per-user eligible notification (RECOMMEND include, like `/mine`); strip from global fan-out; (3) Giza→Alexandria / re-enable / capacity-race copy defaults accepted; (4) high-vs-medium split for chunk dispatch; (5) run retention window.
- Exit: written sign-off; then P1–P12 in order (P9 security + P11 tests run inside every phase, not only at the end).

## P1 — Distribution persistence (migrations + models)

- Files: NEW `database/migrations/*_create_coupon_distribution_runs_table.php`, `*_create_coupon_distribution_recipients_table.php`; NEW `app/Models/CouponDistributionRun.php`, `CouponDistributionRecipient.php` (or Marvel models if project prefers kernel ownership — DECIDE: recommend `app/` since distribution is new application behavior, Marvel owns storage/CRUD primitives only); supporting indexes migration for `customer_metrics` + `notifications`.
- Behavior: exact schema from Architecture §7; `dedupe_key` unique; recipient double-unique; status ENUMs; FK cascades.
- Idempotency: migration-guarded (`Schema::hasTable` pattern already used in Orchestrator); unique violations = skip, never 500.
- Failure: migration rollback safe (drop new tables only; no touch to assignments/claims/usages).
- Tests: migration fresh/rollback; unique-violation mapping; FK cascade delete coupon/user.

## P2 — Audience selection (query service, no jobs yet)

- Files: NEW `app/Services/Coupon/Distribution/CandidateSelector.php` (+ `RuleFamilyExtractor.php` unit); reuse `CustomerMetrics`, `Address`, `Governorate`, `CouponClaim`, `CouponAssignment`, `User`.
- Behavior: `candidatesForCoupon(Coupon): Builder` per Architecture §3 (rule-indexed; AND-intersect/OR-union; fallback broad+engine); `couponsForUserEvent(User, family): Collection` for address/register/order runs; hard cap + `chunkById(500–1000)` cursor contract; NEVER `User::all()`.
- Idempotency/failure: pure queries (no writes); log candidate counts with `coupon_id+tree_hash`.
- Tests: per-rule candidate correctness (area/registered/orders/spend/email), AND/OR intersection/union, empty-audience, huge-audience pagination stability (no skipped/duplicated ids under concurrent inserts — `chunkById`).

## P3 — Queue jobs (orchestrator + chunk)

- Files: NEW `app/Jobs/StartCouponDistributionJob.php`, `EvaluateCouponChunkJob.php`; reuse `QueueName::high()/medium()`, `config/queue.php`, `failed_jobs`, `HandleFailedQueueJob`.
- Behavior: Start re-checks `Coupon::valid()` + targeting active → creates run (dedupe_key) → dispatches chunks; Chunk loops ids → `getMetrics` (no rebuild storm) → `EligibilityEngine::evaluate` → transition check → `notify` eligible. Declare `$tries/$backoff/$timeout` + `ShouldBeUnique` + persisted cursor; `failed()` marks run/recipient + logs.
- Idempotency: `ShouldBeUnique(run+cursor)`; recipient upsert; redelivery resumes cursor.
- Failure: per-user try/catch (one bad user never poisons chunk); coupon death mid-run → cancel remaining chunks.
- Tests: retry-resume, duplicate-dispatch single-run, worker-crash resume, coupon-disabled-mid-run cancel, per-user failure isolation.

## P4 — Eligibility integration (thin glue, NO engine changes)

- Files: NEW `app/Services/Coupon/Distribution/CouponAudienceDistributor.php` (orchestrates P2+P3+P5); TOUCH NOTHING in `EligibilityEngine/RuleTreeValidator/Orchestrator/ClaimService` (verified read-only reuse).
- Behavior: `distributeForCoupon(Coupon, trigger)` + `evaluateUserForCoupons(User, family)`; cheap `Coupon::valid()` pre-check; engine called per user with server-derived identity only.
- Tests: engine still authoritative (fan-out eligible → claim succeeds; fan-out ineligible never notified; rule change mid-run handled by tree_hash).

## P5 — Notification integration (new class, existing channels)

- Files: NEW `app/Notifications/UserCouponEligibleNotification.php` (type `coupon.eligible`); MODIFY `SendUserCouponAvailableNotification` ONLY to skip targeted coupons (one guard clause; preserves global path for true-public); reuse `FcmChannel`, `SendFcmNotificationJob`, `BroadcastNotificationCreated`, `NotificationController` (no changes).
- Behavior: `via=[database,fcm,broadcast]`; payload per Architecture §6 (localized, no internals; code policy per P0); `onQueue(high)`.
- Idempotency: pre-notify dedup check (notifications `type+coupon_id+tree_hash` + recipients row).
- Failure: DB-first (Pusher/FCM exceptions caught per channel, never roll back DB); invalid FCM tokens cleaned by existing job.
- Tests: database row content (no code leak globally / code present per-user per policy), broadcast on `private-users.{id}`, FCM scoped to recipient tokens, invalid-token cleanup, cross-user isolation (A never gets B's).

## P6 — Event triggers (thin after-commit dispatchers only)

- Files: `app/Observers/CouponObserver.php` (+ targeting observer NEW if targeting edits must trigger — currently audit-only), `app/Observers/UserObserver.php` or `Registered` listener addition, Address observer NEW (or model events), `OrderService::recordCouponUsage` afterCommit hook (metrics-rebuilt point), admin manual dispatch in targeting/coupon admin controllers. All dispatch via `DB::afterCommit` / `ShouldDispatchAfterCommit`, never synchronously in request.
- Behavior per Discovery §7 (A coupon-activation run; B/C/D single-user scoped runs; E suppression). Each dispatcher does NOTHING but enqueue Start/evaluate with ids.
- Tests: registration → only that user evaluated; address save → only `area_in` coupons; order completed → only order-family coupons post-metrics; coupon activation → run created; disabled coupon → no run; double-fire → single run.

## P7 — API / discovery (`available` + admin runs)

- Files: NEW `app/Http/Controllers/Api/General/CouponAvailableController.php` (or method on `CouponController`), NEW `CouponAvailableResource`; NEW admin `CouponDistributionController`; `routes/api.php` additions; reuse `CouponResource` shell conventions.
- Behavior: full contract in `COUPON_DISTRIBUTION_API_CONTRACT.md`. Existing 4 endpoints byte-identical.
- Tests: auth, pagination, no-code-leak, eligibility correctness vs engine, cache invalidation, admin permission gates, run status shape.

## P8 — Frontend contract (docs only, no frontend code)

- Files: docs only (API contract report §Frontend). Next.js needs: `coupon.eligible` Pusher handler, notification-click → `/coupons/{id}` → claim → `/mine` refresh, badge via `notifications/unread`, FCM registration already exists.
- Tests (backend-side): payload shape stability test guarding frontend fields (`resource_type/resource_id/action_url/coupon_id`).

## P9 — Security hardening (inside every phase)

- Check: Sanctum owner scoping, `private-users.{id}` strict match, FCM per-user tokens (null-id never broadcast), server-derived `customer_id`, admin `permission:*coupon*` on triggers/runs, no targeting internals in any response/log, rate-limit fan-out triggers per coupon/user.
- Tests: spoofed user_id, cross-channel leakage, unowned notification `findOrFail` 404, FCM token of user B never receives A's, admin-without-permission 403.

## P10 — Observability + ops

- Files: run counters on `runs` row; structured logs; extend `coupons:reconcile` with distribution detectors (orphan recipients, run stuck `running`, notified-count drift); scheduler entries for `expire-claims` already exist — ADD optional nightly `distributions:reconcile` (read-only) + retention prune (e.g. keep runs 90d — policy decision).
- Tests: counter accuracy under partial failure; stuck-run detector; prune preserves audit for notified users.

## P11 — Tests (full matrix in TEST_PLAN report)

- Order: unit (extractor/transition/dedup) → integration (jobs/queues/notifications with `Queue::fake` + real-channel E2E) → MySQL concurrency (parent-lock + chunk uniqueness) → security → lifecycle/limits. sqlite for logic; MySQL 8.4.3 (`phpunit.mysql.xml`, 127.0.0.1:3307) for FU/lock/unique semantics. No full-suite claim without MySQL evidence (sqlite ignores FU).

## P12 — Final validation + rollout

- Gates: Definition-of-Done checklist (scope/requirements/ownership/tests/security/review/regression/diff/docs/unverified). Rollout: feature-flagged triggers (address/order hooks OFF first), manual admin runs for first campaigns, audience-size confirmation for >10k candidates, worker capacity check (high vs medium split), reconcile TOTAL 0, then enable event triggers one family at a time.
- Rollback: triggers are additive (disable flag = instant stop); in-flight chunks drain with coupon-valid re-check; no data migration to reverse (runs/recipients are audit, safe to retain).

## Files likely affected (summary)

NEW: 2 migrations + support-index migration, 2 models, `Distribution/*` (distributor, selector, extractor), 2 jobs, 1 notification, 2 controllers (+1 resource), 1 observer (address/targeting), docs.
TOUCH (minimal, guarded): `CouponObserver` (dispatch run), `UserObserver/Registered` (dispatch), Address hook, `OrderService` afterCommit (dispatch), `SendUserCouponAvailableNotification` (skip targeted), `routes/api.php`, `Kernel.php` (optional reconcile schedule), `coupons:reconcile` (detectors).
NEVER TOUCH: `EligibilityEngine`, `RuleTreeValidator`, `CouponOrchestrator`, `CouponValidator`, `CouponClaimService` core, assignment/claim/usage semantics, `CouponResource` public shell, FCM/broadcast channel internals, frontend code.
