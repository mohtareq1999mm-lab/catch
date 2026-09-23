# Coupon Distribution Admin API

> Source-of-truth documentation. Every statement below was verified against the
> implementation listed in
> [Source of Truth / Implementation References](#source-of-truth--implementation-references).
> Where code and comments/docs disagreed, this file documents **the code**.
> No behavior, field, status code, or permission was invented.

Canonical base (verified with `php artisan route:list`):

```text
POST /api/v1/coupons/{id}/distribute
GET  /api/v1/coupons/{id}/distributions
GET  /api/v1/coupons/{id}/distributions/{runId}
```

All responses use the shared envelope from `Marvel\Traits\ApiResponse`:

```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": {}
}
```

`data` is omitted when empty. `status` mirrors the HTTP status code.
`message` is passed through `translateNotice()` (`message.*` lang keys).

---

## PART 1 — What is Coupon Distribution?

### The one-paragraph version

A **Distribution Run** is one execution of the process that evaluates users
against a coupon's **dynamic targeting rules** and identifies users who have
**newly become eligible**. An admin (or an automatic trigger) starts the run;
workers then process it asynchronously; newly eligible users can receive a
`coupon.eligible` notification.

> Distribution Run = one execution of the process that evaluates users against
> the coupon targeting rules.

### What Distribution is NOT

Distribution does **not**:

- create a coupon;
- change targeting rules;
- assign a coupon directly to a user (no grant row, no quota change);
- apply a coupon to an order;
- redeem / consume a coupon;
- return `coupon.available`;
- return `coupon.used`.

It operates on the **distribution plane**:

```text
Coupon
   ↓
Targeting Rules (coupon_targetings row: mode + rule_tree)
   ↓
Distribution Run (coupon_distribution_runs row)
   ↓
User Evaluation (per-user, async, EligibilityEngine is the authority)
   ↓
Newly Eligible Users
   ↓
coupon.eligible notification (UserCouponEligibleNotification)
```

The three Admin APIs in this file operate **only** on this plane: start a run,
list runs, inspect one run. They expose **counters only, no per-user PII**.

---

## PART 2 — The three APIs in one table

| Method | Endpoint | What it does | When to use it |
| ------ | -------- | ------------ | -------------- |
| POST | `/api/v1/coupons/{id}/distribute` | Start a manual distribution run | When a manual re-run is needed |
| GET | `/api/v1/coupons/{id}/distributions` | List distribution runs | To see distribution history |
| GET | `/api/v1/coupons/{id}/distributions/{runId}` | Inspect one run | To investigate / monitor a specific run |

---

## PART 3 — Authentication & Authorization

Verified in:

- `CouponDistributionAdminController::__construct()` — `$this->middleware(['auth:sanctum'])`.
- `CouponDistributionAdminController::authorizeAdmin()` — in-controller check.
- `config/coupon-distribution.php` — `'admin_permission' => env('COUPON_DISTRIBUTION_ADMIN_PERMISSION', 'update-coupon')`.
- Route registration — `packages/marvel/src/Rest/Routes.php:285-296` inside the
  outer `['auth:sanctum', 'throttle:admin', 'lang']` group, inner
  `['api', 'auth:sanctum', 'throttle:admin']` group, served under the
  `api/v1` prefix by `RestApiServiceProvider`. `whereNumber('id')` /
  `whereNumber(['id', 'runId'])` apply.

What this means:

- **Authentication:** Laravel Sanctum. Every call requires a valid Sanctum
  token. No token (or an invalid token) is rejected before the controller runs.
- **Permission:** `update-coupon` by default (configurable via
  `coupon-distribution.admin_permission` / `COUPON_DISTRIBUTION_ADMIN_PERMISSION`).
  Being an admin does **not** automatically grant access — the user must hold
  this permission.
- **Where it is checked:** globally for these three endpoints, at the top of
  every controller method via `authorizeAdmin()`. There is **no**
  `permission:*` route middleware on these routes; the check is explicit and
  documented in code on purpose.
- **How it is checked:** `hasPermissionTo($permission)` first (Spatie), then
  `can($permission)`. Any thrown `HttpException` is re-thrown; any other
  exception is logged (`coupon.admin.auth_failed`) and the request fails
  closed.
- **Roles:** no role check. Only the permission matters.
- **401:** unauthenticated. Sanctum middleware rejects token-less requests;
  `authorizeAdmin()` also calls `abort(401, 'Unauthenticated.')` if
  `$request->user()` is null.
- **403:** authenticated but missing the permission —
  `abort(403, "Forbidden. Missing required permission: {permission}.")`.
- **Rate limiting / localization:** `throttle:admin` and `lang` middleware also
  apply via the route groups.

Non-numeric `{id}` / `{runId}` never reaches the controller: `whereNumber`
means Laravel returns a 404 route response.

> Route note: the controller docblock says
> `POST /api/v1/admin/coupons/{id}/distribute`. That comment is stale. Verified
> `route:list` output is `api/v1/coupons/{id}/distribute` (canonical) plus the
> two `GET` siblings. The test file `AdminDistributionApiTest` calls
> `/api/v1/admin/coupons/...`, which has **no registered route** for these
> actions in the current tree (that prefix only carries targeting/rules/config
> helpers) — see [Remaining uncertainty](#remaining-uncertainty).

---

## PART 4 — API #1 — `POST /api/v1/coupons/{id}/distribute`

Plain English:

> "Start a distribution run manually for this coupon."

### Purpose

This endpoint:

- starts **one** distribution run for the coupon's **current** targeting version;
- evaluates the **dynamic targeting plane** asynchronously;
- lets newly eligible users become candidates for `coupon.eligible`.

It does **not**:

- modify targeting rules;
- modify the coupon (status, dates, discounts, quotas, assignments, claims);
- fan out synchronously (no per-user loop in the request);
- touch assignments, claims, reservations, or capacity
  (`DistributionService` docblock states this explicitly);
- send `coupon.assigned`, `coupon.available`, or `coupon.used`.

### Path parameter

| Name | Type | Required | Meaning | Invalid / missing behavior |
| ---- | ---- | -------- | ------- | -------------------------- |
| `id` | integer (coupon id) | yes | The coupon to distribute | Non-numeric → route 404 (`whereNumber`). Numeric but not found → `404` envelope with `COUPON_NOT_FOUND`. |

The coupon is loaded as `Coupon::with('targeting')->findOrFail($id)`.

### Request body

There is **no dedicated Form Request**. Validation is inline in
`distribute()` via `$request->validate([...])`:

| Field | Type | Required | Validation | Default | Meaning |
| ----- | ---- | -------- | ---------- | ------- | ------- |
| `trigger` | string | no | `sometimes`, `string`, `in:manual` | `manual` (controller always passes `MANUAL` downstream) | Only accepted value is `"manual"`. Anything else fails validation. |
| `audience_cap` | integer | no | `sometimes`, `integer`, `min:1`, `max:<config max_audience_cap, default 100000>` | `<config default_audience_cap, default 10000>` | Cap on how many candidate users this run evaluates (see [audience_cap](#part-12--audience_cap)). |

Exact accepted values:

- `trigger`: `"manual"` or omitted. Nothing else.
- `audience_cap`: integer `>= 1` and `<= max_audience_cap`.

### Example request

Verified against the controller (both fields optional):

```json
{
  "trigger": "manual",
  "audience_cap": 5000
}
```

Minimal valid request:

```json
{}
```

or simply no body. The controller then uses `default_audience_cap` (10000
unless configured otherwise) and trigger `manual`.

### What happens internally

Verified sequence (`CouponDistributionAdminController::distribute` →
`DistributionService::startDistribution` →
`DistributionRunService::startOrJoin` → `CouponOutboxService::recordAndDispatch`
→ RabbitMQ → `DistributionStartHandler` → evaluation → notification):

```text
Admin request
    ↓
auth:sanctum / throttle:admin / lang middleware
    ↓
authorizeAdmin (update-coupon permission, 401/403 otherwise)
    ↓
Inline validation (trigger, audience_cap)
    ↓
Controller (CouponDistributionAdminController::distribute)
    ↓
DistributionService::startDistribution (MANUAL, scope "manual", triggerId "admin:{userId}", audienceCap)
    ↓
Distributability check (422 if not distributable)
    ↓
TreeHash::forRuleTree(rule_tree, mode) → targeting version
    ↓
DB transaction: DistributionRunService::startOrJoin (dedupe_key arbitration)
    ↓
If created: outbox recordAndDispatch(coupon.distribution.start)
    ↓
RabbitMQ (topic exchange; PublishCouponOutboxJob after commit + per-minute sweep safety net)
    ↓
DistributionStartHandler: live-check, tree-drift check, markRunning, candidate selection (capped)
    ↓
User evaluation (EligibilityEngine, async chunk/evaluate consumers)
    ↓
Eligibility transition (DISCOVERED → ELIGIBLE / NOT_ELIGIBLE / FAILED / DUPLICATE_SKIPPED)
    ↓
Notification request (coupon.eligible)
    ↓
coupon.eligible delivered (database + fcm + broadcast)
```

Only the steps up to (and including) the outbox write happen inside the HTTP
request transaction. Everything after RabbitMQ is asynchronous. That is why the
success status is `202`, not `200`.

---

## PART 5 — POST responses

Every response uses the shared envelope
`{status, message, success, data?}` with the HTTP code in both places.

Validation failures from `$request->validate()` use Laravel's framework
default `422` shape (`{message, errors: {trigger: [...], audience_cap: [...]}}`),
**not** the `apiResponse` envelope. All other errors below use the envelope.

### 202 Accepted — run accepted

Meaning:

> The server accepted the distribution request, but the distribution is
> asynchronous and may still be running.

The returned run is **not** necessarily complete. It is typically `pending`
(freshly created) — the worker later moves it to `running`, then to
`completed` / `failed` / `cancelled`. Read
[troubleshooting](#part-15--operational-troubleshooting) to follow it.

Structure (verified `presentRun()`):

```json
{
  "status": 202,
  "message": "Distribution run accepted.",
  "success": true,
  "data": {
    "run": {
      "id": 123,
      "coupon_id": 45,
      "trigger_type": "manual",
      "tree_hash": "abc123...",
      "status": "pending",
      "candidate_count": 0,
      "eligible_count": 0,
      "not_eligible_count": 0,
      "notified_count": 0,
      "failed_count": 0,
      "duplicate_skipped_count": 0,
      "started_at": "2026-09-23T10:00:00+00:00",
      "finished_at": null
    }
  }
}
```

Field notes (only fields that exist in `presentRun()` are listed):

- `id` — run id (`runId` for the detail endpoint).
- `coupon_id` — the coupon this run belongs to.
- `trigger_type` — enum value, here `"manual"`.
- `tree_hash` — targeting-version hash (`TreeHash::forRuleTree(rule_tree, mode)`).
- `status` — one of `pending`, `running`, `completed`, `failed`, `cancelled`.
- `candidate_count` — recipients created for this run (set by the start handler;
  `0` at accept time).
- `eligible_count` — reconciled as `eligible + notified`.
- `not_eligible_count` — reconciled `not_eligible` count.
- `notified_count` — reconciled `notified` count.
- `failed_count` — reconciled `failed_retryable + failed_permanent`.
- `duplicate_skipped_count` — reconciled `duplicate_skipped` count.
- `started_at` / `finished_at` — ISO-8601 strings or `null`. `finished_at` is
  `null` until the run reaches a terminal state.

Occurs when: coupon exists, is distributable, validation passes, permission
passes, and no live run exists for the same targeting version (or the previous
run is terminal and a follow-up scope is opened — manual re-runs after
`completed` return `202` with a **new** run id).

---

## PART 6 — 409 Conflict — already running

Exact behavior (verified `distribute()` + `DistributionService` +
`DistributionRunService`):

- Dedupe key = `coupon_id : tree_hash : trigger_type : trigger_scope`.
- Concurrent duplicate starts converge on the unique constraint; losers get the
  existing run instead of fanning out twice.
- If the joined run's status is `pending` or `running`, the controller returns
  `409` with `reason = already_running` **plus the live run**:

```json
{
  "status": 409,
  "message": "A distribution run is already in progress for this targeting version.",
  "success": false,
  "data": {
    "reason": "already_running",
    "run": {
      "id": 123,
      "coupon_id": 45,
      "trigger_type": "manual",
      "tree_hash": "abc123...",
      "status": "running",
      "candidate_count": 1200,
      "eligible_count": 0,
      "not_eligible_count": 0,
      "notified_count": 0,
      "failed_count": 0,
      "duplicate_skipped_count": 0,
      "started_at": "2026-09-23T10:00:00+00:00",
      "finished_at": null
    }
  }
}
```

Plain English:

> A distribution run for the same targeting version is already pending/running,
> so the system does not start another duplicate run.

This is double-submit protection: double-clicking "distribute", retrying after
a timeout, or two admins acting at once cannot launch a duplicate mass
distribution for the same tree hash. Use the returned `run.id` to monitor the
live run instead.

Terminal runs (`completed`, `failed`, `cancelled`) do **not** 409 for manual
calls: `reopenScope()` opens a follow-up run (`manual:<uuid>`) so an explicit
post-terminal manual call returns `202` with a new run.

---

## PART 7 — 422 Not distributable

Exact rule (verified `DistributionService::startDistribution`):

```php
if ($targeting === null
    || ! in_array($targeting->mode, ['dynamic', 'assignment_and_dynamic', 'assignment_or_dynamic'], true)
) {
    throw new NonDistributableCouponException(...);
}
```

So `422` happens when:

- the coupon has **no targeting row** (`targeting === null`), **OR**
- its targeting `mode` is not in the dynamic family.

Targeting modes (`coupon_targetings.mode`):

| Mode | Dynamic distribution run? | Meaning |
| ---- | ------------------------- | ------- |
| `assignment` | No — `422 not_distributable` | Assignment-only: users get the coupon via explicit grants, not discovery. |
| `dynamic` | Yes | Pure dynamic discovery. |
| `assignment_and_dynamic` | Yes | Both planes; the dynamic branch is distributable. |
| `assignment_or_dynamic` | Yes | Either plane; the dynamic branch is distributable. |

Exact response (verified controller catch block):

```json
{
  "status": 422,
  "message": "<translated ERROR.COUPON_NOT_ELIGIBLE>",
  "success": false,
  "data": {
    "reason": "not_distributable"
  }
}
```

`message` is the translation of `COUPON_NOT_ELIGIBLE` (`ERROR.COUPON_NOT_ELIGIBLE`).
Do not confuse this envelope `422` with the framework validation `422`
(`errors.audience_cap` / `errors.trigger`) — they are two different code paths.

---

## PART 8 — API #2 — `GET /api/v1/coupons/{id}/distributions`

Plain English:

> "Show me the distribution runs that happened for this coupon."

### Path parameter

| Name | Type | Required | Meaning |
| ---- | ---- | -------- | ------- |
| `id` | integer | yes | Coupon id. Missing coupon → `404 COUPON_NOT_FOUND` envelope. Non-numeric → route 404. |

### Query parameters

**None are supported.** Verified `index()`: it never reads `$request->input()`,
`query()`, filters, sorting, or ordering inputs. Pagination is hardcoded:

- order: `orderByDesc('id')` (newest first);
- page size: `paginate(15)` — always 15 per page;
- page selection: standard Laravel `?page=N` only (framework `paginate()`
  behavior, not custom code).

Do not send `status`, `sort`, `per_page`, or similar — they are ignored.

### Response

Verified structure:

```json
{
  "status": 200,
  "message": "<translated FETCH_DATA_SUCCESSFULLY>",
  "success": true,
  "data": {
    "data": [
      {
        "id": 123,
        "coupon_id": 45,
        "trigger_type": "manual",
        "tree_hash": "abc123...",
        "status": "completed",
        "candidate_count": 1000,
        "eligible_count": 120,
        "not_eligible_count": 880,
        "notified_count": 118,
        "failed_count": 2,
        "duplicate_skipped_count": 0,
        "started_at": "2026-09-23T10:00:00+00:00",
        "finished_at": "2026-09-23T10:04:11+00:00"
      }
    ],
    "meta": {
      "current_page": 1,
      "per_page": 15,
      "total": 1,
      "last_page": 1
    }
  }
}
```

Notes:

- Outer `data.data` is the run list (array of `presentRun()`), outer
  `data.meta` is pagination. So the path to the first run is
  `data.data[0]` in the JSON body (`response.json('data.data')` in tests).
- The list carries **counters only, no PII** (no email, name, user id). The
  test asserts no `@` appears in the payload.
- `message` is the translation of `FETCH_DATA_SUCCESSFULLY`.

### When to use it

- after changing targeting, to find the run created automatically;
- to see historical runs (newest first);
- to check whether a run completed (`status`, `finished_at`);
- to find the `runId` for deeper inspection via the detail endpoint;
- to compare counters across runs (same `tree_hash` = same targeting version).

---

## PART 9 — API #3 — `GET /api/v1/coupons/{id}/distributions/{runId}`

Plain English:

> "Show me the details of one specific distribution run."

### Path parameters

| Name | Type | Required | Meaning |
| ---- | ---- | -------- | ------- |
| `id` | integer | yes | Coupon id. |
| `runId` | integer | yes | Run id, **scoped to the coupon**. |

Scoping (verified `show()`):

```php
CouponDistributionRun::query()->where('coupon_id', $id)->findOrFail($runId);
```

If the run does not exist **or belongs to another coupon**, the result is the
same `404 COUPON_NOT_FOUND` envelope. There is no separate "run exists but
belongs elsewhere" signal.

### Response

Verified structure:

```json
{
  "status": 200,
  "message": "<translated FETCH_DATA_SUCCESSFULLY>",
  "success": true,
  "data": {
    "run": {
      "id": 123,
      "coupon_id": 45,
      "trigger_type": "manual",
      "tree_hash": "abc123...",
      "status": "completed",
      "candidate_count": 1000,
      "eligible_count": 120,
      "not_eligible_count": 880,
      "notified_count": 118,
      "failed_count": 2,
      "duplicate_skipped_count": 0,
      "started_at": "2026-09-23T10:00:00+00:00",
      "finished_at": "2026-09-23T10:04:11+00:00"
    },
    "recipient_breakdown": {
      "notified": 118,
      "eligible": 2,
      "not_eligible": 880
    }
  }
}
```

- `run` — same `presentRun()` shape as elsewhere.
- `recipient_breakdown` — `{status: count}` map from
  `recipients()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')`.
  Only statuses that have at least one row appear as keys. Values are integers.

### Recipient statuses

Verified `App\Enums\CouponDistributionRecipientStatus` (only these values exist):

| Status value | Meaning |
| ------------ | ------- |
| `discovered` | Candidate row created; evaluation not finished yet. If a run stays here, workers have not processed it. |
| `eligible` | User evaluated as eligible but the notification request may still be in flight. Counts as "open" work — the run cannot finish while rows are still `eligible`. |
| `notified` | `coupon.eligible` was requested/recorded for this user. Included in both `eligible_count` and `notified_count` after reconcile. |
| `not_eligible` | User evaluated and found not eligible. No notification. |
| `failed_retryable` | Evaluation/notification failed but may be retried. Counts as "open" work. Contributes to `failed_count` after reconcile. |
| `failed_permanent` | Failed and will not be retried. Contributes to `failed_count` after reconcile. |
| `duplicate_skipped` | Skipped as a duplicate (e.g. already notified for this coupon + tree hash in a previous run). Contributes to `duplicate_skipped_count`. |

Run statuses (`CouponDistributionRunStatus`): `pending`, `running`,
`completed`, `failed`, `cancelled`.

### GROUP BY behavior

> Instead of returning individual users, the API counts how many recipients are
> currently in each status.

This is an **observability endpoint, not a PII endpoint**: you see *how many*
are in each state, never *who*. Reconciled run counters (`eligible_count`,
etc.) are derived from the same recipient rows by
`DistributionRunService::reconcile()`.

---

## PART 10 — Complete admin workflow

### Normal automatic flow

No manual call is needed for the standard case:

```text
1. Admin creates/updates coupon targeting
     (PUT /api/v1/coupons/{id}/targeting, or coupon activation)
          ↓
2. Targeting change triggers automatic distribution
     (CouponTargetingChanged / CouponActivated event, ShouldDispatchAfterCommit)
          ↓
3. StartCouponDistribution listener (ShouldQueue, high queue)
     calls DistributionService::startDistribution(..., TARGETING_CHANGED or
     COUPON_ACTIVATED, scope "activation")
          ↓
4. Distribution Run row is created (dedupe_key arbitration)
          ↓
5. Outbox row + RabbitMQ publish (transactional outbox pattern)
          ↓
6. Workers process it asynchronously (candidate selection → evaluation)
          ↓
7. Users are evaluated (EligibilityEngine is the authority)
          ↓
8. Newly eligible users are identified
          ↓
9. coupon.eligible notifications are requested (database + fcm + broadcast)
          ↓
10. Notification is delivered
```

Non-distributable coupons (no targeting / `assignment`-only) are skipped here
with a `coupon.trigger.skipped_non_distributable` log — no run, no retry.

Then inspect:

```http
GET /api/v1/coupons/{id}/distributions
```

to find the run (newest first; automatic runs carry `trigger_type`
`targeting_changed` or `coupon_activated`), then:

```http
GET /api/v1/coupons/{id}/distributions/{runId}
```

for detailed monitoring (status, counters, `recipient_breakdown`).

---

## PART 11 — Manual distribution workflow

Use:

```http
POST /api/v1/coupons/{id}/distribute
```

only when a **new explicit run** is actually wanted. Scenarios consistent with
the implementation (the code supports them; it does not promise a specific
business fix):

- manually re-running distribution after relevant data changes (a post-terminal
  manual call opens a **new** run via `manual:<uuid>` scope);
- recovering operationally, e.g. a prior run was `cancelled`/`failed` and you
  want one more attempt against the same tree hash;
- testing with a small `audience_cap` before a full audience;
- re-evaluating late eligibility when the automatic triggers did not cover the
  case (manual scope is distinct from per-user `user:{id}` scopes).

What manual distribution does **not** do (code-proven):

- it does not repair targeting, coupon dates/status, quotas, or assignments;
- it does not bypass the `already_running` guard — a live run for the same
  tree hash returns `409`, it does not queue a second fan-out;
- it does not make an `assignment`-only or targeting-less coupon distributable
  (still `422`);
- it does not change which users are eligible — eligibility is still decided by
  the engine + current data, not by the fact that the run was manual.

Do not claim that a manual re-run fixes any specific failure (e.g. broker
outage, dead workers, bad rules) — the code only proves that it opens/joins a
run and emits the start event. If the run is stuck, investigate first (next
section).

---

## PART 12 — `audience_cap`

Example:

```json
{
  "audience_cap": 500
}
```

means:

> Limit the number of candidate users evaluated by this distribution run to 500.

Verified semantics:

- **Where it is set:** request field → `DistributionService` payload
  (`audience_cap` in the `coupon.distribution.start` envelope) → read in
  `DistributionStartHandler::handle()` as
  `$cap = max(1, (int) ($payload['audience_cap'] ?? default_audience_cap))`.
- **Where it is applied:** candidate selection. The handler builds
  `CandidateSelector::queryFor($coupon, $cap)` and iterates with
  `chunkById(chunk_size)`; the loop `break`s / stops once `$candidates >= $cap`.
  Only the first `$cap` users become `DISCOVERED` recipient rows.
- **Limits candidates, not notifications directly.** Fewer candidates means
  fewer evaluations and therefore fewer possible notifications, but the cap
  itself is enforced at selection time, not at notify time.
- **One run, one cap:** it limits the total users for that run, not one chunk.
  Chunk size (`coupon-distribution.chunk_size`, default 500) controls message
  granularity; the cap controls the total.
- **Counters:** `candidate_count` is updated to the capped number
  (`update(['candidate_count' => $candidates])`); downstream
  `eligible / not_eligible / notified / failed / duplicate_skipped` counts are
  bounded by it.
- **Minimum:** `1` (controller `min:1`; handler also `max(1, ...)`).
- **Maximum:** `max_audience_cap` (controller `max:` rule; default `100000` via
  `COUPON_DISTRIBUTION_MAX_AUDIENCE_CAP`).
- **Default:** `default_audience_cap` (default `10000` via
  `COUPON_DISTRIBUTION_AUDIENCE_CAP`) when the field is omitted.
- **Single-user triggers** (`user_registered`, `address_changed`,
  `order_completed` with scope `user:{id}`) additionally constrain the query to
  that user; manual runs have no such constraint.

---

## PART 13 — Important differences

Verified against `CouponTargetingController` (PUT targeting fires
`CouponTargetingChanged` → automatic run), `DistributionService`, and the
read-only nature of `index`/`show`:

| Operation | Changes targeting? | Starts distribution? | Evaluates users? | Sends `coupon.eligible`? |
| --------- | -----------------: | -------------------: | ---------------: | -----------------------: |
| PUT targeting (`PUT /api/v1/coupons/{id}/targeting`) | Yes | Yes, automatically (via `CouponTargetingChanged` → `StartCouponDistribution`), if the result is distributable | Yes, asynchronously via the auto-created run | Yes, for newly eligible users |
| POST distribute | No | Yes, one explicit manual run (202) or joins the live run (409) | Yes, asynchronously via that run | Yes, for newly eligible users found by that run |
| GET distributions | No | No | No | No |
| GET distribution/{runId} | No | No | No | No |

The two `GET` endpoints are pure observability: they read run rows and a
`GROUP BY status` count. They never create runs, evaluate users, or notify.

---

## PART 14 — Relationship with other coupon notifications

These APIs are specifically related to:

```text
coupon.eligible
```

`UserCouponEligibleNotification` (`app/Notifications/UserCouponEligibleNotification.php`):

- `via = ['database', 'fcm', 'broadcast']`;
- `broadcastType() / databaseType() = 'coupon.eligible'`;
- database payload: localized `title.{en,ar}`, `message.{en,ar}` (with
  `coupon_name`), `icon: tag`, `resource_type: coupon`, `resource_id`,
  `action_url: /coupons/{id}`, `coupon_id`, `tree_hash`, `run_id`;
- **never carries `coupon_code`**, rules, metrics, counters, or other-user data
  (confidentiality comment in code). The code appears only under the
  owner-scoped claimed-coupon surface after a successful claim.

Clearly distinguished:

| Type | Trigger | Meaning |
| ---- | ------- | ------- |
| `coupon.eligible` | Dynamic discovery (this feature) | "You newly match this coupon's targeting." No grant row is created. |
| `coupon.assigned` | Explicit admin grant (assignment plane) | "This coupon was granted to you." Exactly one per assignment; updates/deletes emit nothing; assignments never emit `eligible`. |
| `coupon.available` | Public / general availability path | "A coupon is available." Separate discovery flow from dynamic targeting. |
| `coupon.used` | Consumption (`AssignedCouponConsumed`) | "Your assigned coupon was consumed." Order/payment side effects included. |

Why `POST distribute` does not mean `assigned` / `available` / `used`:

- it creates no assignment row, so `coupon.assigned` is impossible;
- it does not publish to the public availability surface, so `coupon.available`
  is the wrong type (and reusing `assigned` for discovery is explicitly
  rejected in the design docs because it implies a grant);
- it does not consume anything, so `coupon.used` is unrelated.

If you need grants, use the assignment endpoints. If you need consumption
state, look at orders/claims. Distribution only ever leads to `coupon.eligible`.

---

## PART 15 — Operational troubleshooting

### If the run is not progressing

Investigate using only these APIs (no invented infra commands):

```text
1. GET distributions
        ↓
2. Find the run (newest first; match tree_hash / trigger_type / started_at)
        ↓
3. GET distributions/{runId}
        ↓
4. Check status (pending → running → completed / failed / cancelled)
        ↓
5. Check counters (candidate_count vs eligible/not_eligible/notified/failed)
        ↓
6. Check recipient_breakdown + failed / duplicate_skipped counts
        ↓
7. Determine whether a manual re-run is appropriate
```

How to read it (code-verified):

- `pending` with `candidate_count = 0`: start event accepted but the worker has
  not picked it up yet (or the broker/sweep is behind). Wait, then re-check.
- `running` with rows stuck in `discovered` / `eligible` / `failed_retryable`:
  work is still open — `maybeFinishRun()` deliberately does not finish while
  these states exist (`eligible` notifications may still be in flight).
- `cancelled` shortly after start: the start handler found the coupon not live
  (`CouponLiveCheck`: status/dates) or a stale/redelivered start for a terminal
  run. Fix coupon state before re-running.
- `failed`: at least one recipient ended `failed_retryable`/`failed_permanent`
  (reconciled `failed_count > 0`). Inspect `recipient_breakdown` for the split.
- Large `duplicate_skipped`: expected on re-runs — cross-run notification guard
  already notified these users for this coupon + tree hash. Not an error.
- `409 already_running` on manual retry: not an error — monitor the returned
  live run instead of forcing a duplicate.
- `422 not_distributable`: fix targeting (add a dynamic-family mode + rule
  tree) before retrying; re-running changes nothing.
- Counters all zero on a `completed` run: nobody matched (or the cap was tiny,
  or the tree drifted and a later run superseded it).

Only start a manual re-run when the previous run is terminal, or when you
explicitly want a new evaluation against current data. A live run never needs a
duplicate.

---

## PART 16 — Error matrix

All rows verified in code (`authorizeAdmin`, `$request->validate`,
`findOrFail` catches, `NonDistributableCouponException` catch, dedupe branch).
`message` values are translations unless noted.

| Endpoint | Status | Reason / shape | Meaning | Admin action |
| -------- | -----: | -------------- | ------- | ------------ |
| all three | 401 | Sanctum `Unauthenticated.` / framework unauthenticated | Missing / invalid Sanctum token | Authenticate; attach a valid Bearer token |
| all three | 403 | `Forbidden. Missing required permission: update-coupon.` | Authenticated but lacks `update-coupon` (or configured `admin_permission`) | Grant the permission; do not assume admin role is enough |
| all three | 404 | Envelope `COUPON_NOT_FOUND`, `success: false`, no `data` | Coupon `{id}` not found; for `show`, also when `runId` does not exist **or belongs to another coupon** | Check ids; for `show`, list runs for this coupon first |
| all three | 404 | Route 404 (framework) | Non-numeric `{id}` / `{runId}` (`whereNumber`) | Send integers only |
| POST distribute | 422 | Framework validation shape `{message, errors: {trigger?, audience_cap?}}` | `trigger` not `"manual"`, or `audience_cap` not an integer / `< 1` / `> max_audience_cap` | Fix the field; omit optional fields to use defaults |
| POST distribute | 422 | Envelope `reason: not_distributable`, message `ERROR.COUPON_NOT_ELIGIBLE` | Coupon has no targeting, or mode is `assignment` (not in `dynamic`, `assignment_and_dynamic`, `assignment_or_dynamic`) | Add/fix targeting with a dynamic-family mode; manual runs cannot bypass this |
| POST distribute | 409 | Envelope `reason: already_running` + live `run` | A `pending`/`running` run already exists for this targeting version (same dedupe key) | Monitor the returned run; do not resubmit |
| POST distribute | 202 | — (success) | Accepted; run may still be `pending`/`running` | Poll `GET distributions[/{runId}]` for completion |
| GET both | 200 | — (success) | Runs / run + breakdown returned | — |

No other status codes exist on these code paths. In particular there is no
`400`, `429`-custom, or `500`-envelope documented here; broker/worker failures
surface as stuck/failed runs and outbox retries, not as HTTP codes.

---

## PART 17 — Request/response quick reference

### Start manual distribution

```http
POST /api/v1/coupons/{id}/distribute
Authorization: Bearer <sanctum-token>
Content-Type: application/json
```

```json
{
  "trigger": "manual",
  "audience_cap": 5000
}
```

Minimal:

```json
{}
```

Success (`202`):

```json
{
  "status": 202,
  "message": "Distribution run accepted.",
  "success": true,
  "data": {
    "run": {
      "id": 123,
      "coupon_id": 45,
      "trigger_type": "manual",
      "tree_hash": "abc123...",
      "status": "pending",
      "candidate_count": 0,
      "eligible_count": 0,
      "not_eligible_count": 0,
      "notified_count": 0,
      "failed_count": 0,
      "duplicate_skipped_count": 0,
      "started_at": "2026-09-23T10:00:00+00:00",
      "finished_at": null
    }
  }
}
```

Duplicate (`409`): same envelope with `"success": false`,
`data.reason = "already_running"` and the live `data.run`.
Not distributable (`422`): `"success": false`,
`data.reason = "not_distributable"`.
Missing coupon (`404`): `"success": false`, message `ERROR.COUPON_NOT_FOUND`.

### List runs

```http
GET /api/v1/coupons/{id}/distributions?page=1
Authorization: Bearer <sanctum-token>
```

Response (`200`): envelope with `data.data[]` (runs, newest first) and
`data.meta {current_page, per_page: 15, total, last_page}`. No query params
besides `page` have any effect.

### Get run details

```http
GET /api/v1/coupons/{id}/distributions/{runId}
Authorization: Bearer <sanctum-token>
```

Response (`200`): envelope with `data.run` + `data.recipient_breakdown`
(`{status: count}`). Run from another coupon → `404 COUPON_NOT_FOUND`.

---

## Source of Truth / Implementation References

Controller:

- `app/Http/Controllers/Api/Admin/CouponDistributionAdminController.php`
  — `__construct` (sanctum), `authorizeAdmin`, `distribute`, `index`, `show`,
  `presentRun`

Services / domain:

- `app/Services/Coupon/Distribution/DistributionService.php`
  — `startDistribution`, `cancelRun`, `isTerminal`, `reopenScope`
- `app/Services/Coupon/Distribution/DistributionRunService.php`
  — `dedupeKey`, `startOrJoin`, `markRunning`, `incrementCounter`, `finish`,
  `maybeFinishRun`, `reconcile`
- `app/Services/Coupon/Distribution/NonDistributableCouponException.php`
- `app/Services/Coupon/Distribution/Consumers/DistributionStartHandler.php`
  — `handle` (live-check, tree-drift check, capped candidate fan-out)
- `app/Services/Coupon/Distribution/Outbox/CouponOutboxService.php`
  — `record`, `recordAndDispatch`, `publishOne`, `publishDue`
- `app/Listeners/Coupons/StartCouponDistribution.php` — `handle`
  (`CouponActivated` / `CouponTargetingChanged` → `startDistribution`)
- `app/Notifications/UserCouponEligibleNotification.php`
  — `via`, `toDatabase`, `broadcastType`/`databaseType` (`coupon.eligible`)

Models / enums / config:

- `app/Models/CouponDistributionRun.php` — fillable, casts, `recipients()`
- `app/Enums/CouponDistributionRunStatus.php`
  — `pending, running, completed, failed, cancelled`
- `app/Enums/CouponDistributionRecipientStatus.php`
  — `discovered, eligible, not_eligible, notified, failed_retryable, failed_permanent, duplicate_skipped`
- `app/Enums/CouponDistributionTriggerType.php`
  — `coupon_activated, targeting_changed, user_registered, address_changed, order_completed, manual`
- `config/coupon-distribution.php`
  — `chunk_size (500), default_audience_cap (10000), max_audience_cap (100000),
  admin_permission (update-coupon), outbox/retry/retention knobs`
- `packages/marvel/config/constants.php` — `COUPON_NOT_FOUND`, `COUPON_NOT_ELIGIBLE`
- `packages/marvel/src/Traits/ApiResponse.php` — `apiResponse` envelope

Routes / providers:

- `packages/marvel/src/Rest/Routes.php:285-296` (canonical coupon distribution
  routes, `whereNumber`)
- `packages/marvel/src/Providers/RestAPIServiceProvider.php` (`api/v1` prefix)
- `app/Providers/RouteServiceProvider.php` (`api` prefix for `routes/api.php`)
- `routes/api.php:200-209` (legacy `v1/admin/coupons` targeting/config helpers —
  no distribution routes here)

Tests (behavioral contracts consulted; code wins on any conflict):

- `tests/Feature/CouponDistribution/AdminDistributionApiTest.php`
  — 401/403/404/422/202/409 paths, counters-only / no-PII assertions
- `tests/Feature/CouponDistribution/DistributionRunServiceTest.php`
- `tests/Feature/CouponDistribution/DistributionPipelineTest.php`
- `tests/Feature/CouponDistribution/TransitionAndRunLifecycleTest.php`
  (`audience_cap` fan-out truncation)

### Remaining uncertainty

- `AdminDistributionApiTest` uses `/api/v1/admin/coupons/{id}/distribute` (and
  siblings), but verified `route:list` registers only
  `/api/v1/coupons/{id}/distribute` (+ siblings). Those test URLs currently
  have no matching route in the tree. This file documents the registered
  routes. If the `/api/v1/admin/coupons` aliases are re-added, the controller
  behavior documented here applies unchanged.
- Exact translated `message` strings depend on `resources/lang/*/message.php`
  and the request locale (`lang` middleware); only the keys
  (`ERROR.COUPON_NOT_FOUND`, `ERROR.COUPON_NOT_ELIGIBLE`,
  `FETCH_DATA_SUCCESSFULLY`, literal `Distribution run accepted.` /
  `A distribution run is already in progress for this targeting version.`) are
  pinned here.
- Downstream worker topology (exchanges, queues, retry delays, DLQ) was read in
  `CouponOutboxService` / `DistributionStartHandler` / config only to the depth
  needed for the "what happens internally" flow. Consumer-by-consumer retry
  semantics are out of scope for this admin-API surface.
