# COUPON DISTRIBUTION — API CONTRACT (PROPOSAL, NO CODE)

> REST ONLY. Base: `/api/v1/general` (customer), `/api/v1` + `/api/v1/admin` (admin). Envelope unchanged: `{success,message,data,meta?}` / errors `{success:false,message,errors?}`.
> Existing 4 coupon endpoints are BYTE-IDENTICAL (no request/response change). This contract only ADDS.

## 1. Customer — available coupons for me (NEW, REQUIRED)

### `GET /api/v1/general/coupons/available`

- Auth: `auth:sanctum` (401 unauthenticated). Throttle: `public-api` customer tier (same as `GET general/coupons`).
- Purpose: the missing "available to me" discovery — eligibility-filtered, paginated personal list. Replaces blind browsing for targeted users.
- Query: `page, limit(≤50 default 15), search?` (name search, same `CouponService` semantics). NO `code`, NO rule input, NO user_id input (identity from token).
- Server behavior: candidate coupons = `Coupon::valid()` + has `coupon_targetings` with `mode IN (dynamic, assignment_or_dynamic [dynamic-branch], assignment_and_dynamic [only if assignment also held])` — then per-coupon `EligibilityEngine::evaluate(auth_user)` + `require_claim`/claim-state affordance. Pure-dynamic coupons dominate; `assignment*` modes only surface when the assignment branch is independently satisfied (never leak assignment existence of others). Result cached per-user short TTL (e.g. 60s, key `available:{userId}:{pageHash}`) with invalidation on claim/assignment/order/address events for that user (exact TTL in P10 with load evidence).
- Success 200 `data[]` item (public shell + affordance, NEVER internals):

```json
{
  "id": 5,
  "name": {"en": "...", "ar": "..."},
  "slug": "...",
  "image": {"desktop": "...", "mobile": "..."},
  "claim_status": "claimable | claimed_active | redeemed | max_claims_reached | claim_not_required",
  "requires_claim": true,
  "claim_id": 9,
  "expires_at": "2026-…",
  "action": {"claim_url": "/api/v1/general/coupons/5/claim", "apply_hint": "code revealed after claim in /mine"}
}
```

- Code policy: NO `code` in this list (even for owner) — code is revealed post-claim in `/mine` (existing owner-code rule preserved). Rationale: keeps `available` cacheable + prevents shoulder-surfing bulk harvest; claim is one tap away.
- Errors: 401; 422 validation; 500 infra only (eligibility fail-closed NEVER 500s as ineligible — item simply excluded).
- Pagination: Laravel paginator `{data, current_page, last_page, per_page, total}` (same style as assignment index).

### Unchanged endpoints (normative — do NOT alter)

- `GET /general/coupons` (public, no code, no eligibility) — stays as browse/homepage feed.
- `POST /general/coupons/{id}/claim` (201/409 reason-only/404) — stays the ONLY claim writer.
- `GET /general/coupons/mine` (owner assignments+claims+codes) — stays the code-reveal surface.
- `POST /general/coupons/apply {code}` (preview only) — stays `{code}`-only.

## 2. Customer — notifications (EXISTING, reused; one new type)

- Existing owner-scoped endpoints unchanged: `GET notifications`, `GET notifications/unread`, `GET notifications/{id}`, `PATCH notifications/{id}/read`, `POST notifications/read-all`, `DELETE notifications/{id}`.
- New `type` value: `coupon.eligible` (alongside existing `coupon.assigned`, `coupon.available`, `coupon.used`). List item shape (via `formatNotification`): `{id, type:coupon.eligible, title:{en,ar}, message:{en,ar}, icon:tag, resource_type:coupon, resource_id, action_url:/coupons/{id}, created_at, read_at}` (+ `coupon_id` in `data`).
- Realtime: existing `private-users.{id}` broadcast `coupon.eligible` (same auth). Push: existing FCM (per-user tokens).
- Frontend MUST NOT parse anything beyond these fields (no rules/snapshot/counters ever sent).

## 3. Admin — distribution runs (NEW, permission-gated)

All under `/api/v1/admin` (or `/api/v1/coupons` canonical — follow existing dual-route convention: canonical + legacy alias, static `rules`-style ordering before `apiResource`):

| Method | URL | Permission | Body | Success |
|---|---|---|---|---|
| POST | `/coupons/{id}/distribute` | `update-coupon` (or dedicated `distribute-coupon` if business wants separation — DECIDE in P0) | `{trigger?: manual, audience_cap?: int}` | 202 `{run_id, status:pending, dedupe_key}` (409 `already_running` if live run same `tree_hash`) |
| GET | `/coupons/{id}/distributions` | `view-coupons` | `?page&status` | paginated runs `{run_id, trigger_type, tree_hash, status, candidate/eligible/notified/failed/duplicate counts, started/finished_at}` |
| GET | `/coupons/distributions/{runId}` | `view-coupons` | — | single run + counters (no per-user PII beyond counts; per-recipient listing admin-only with `view-coupon-assignments`-grade permission if needed) |

- Errors: 401/403 (no permission), 404 (coupon), 409 (duplicate/live run), 422 (validation), 429 (trigger rate-limit per coupon).

## 4. Error catalog additions (machine-readable, envelope unchanged)

- `already_running` (409, admin double-trigger) · `run_cancelled_coupon_inactive` (410/409 informational for status poll) · `audience_empty` (202 with `candidate_count:0`, not an error) — all `{reason, code:COUPON_<REASON>}` style like apply.
- Customer `available` never leaks `failed_rules/tree_hash/run_id/user lists`.

## 5. Versioning / compatibility / limits

- No breaking change: additive endpoints + additive notification `type` only. Old clients ignore `coupon.eligible` gracefully (still visible in `notifications` list + `available` is opt-in).
- Rate limits: admin distribute ≤5/min/coupon (config); `available` under existing customer throttle; chunk dispatch internal (no public rate surface).
- Caching: `available` per-user short TTL; `general/coupons` full-URL cache untouched; notification list never cached server-side (existing paginated live query).
