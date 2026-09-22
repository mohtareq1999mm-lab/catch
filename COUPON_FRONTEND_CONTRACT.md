# COUPON FRONTEND CONTRACT

> REST ONLY. Base prefix: `/api/v1/general` (customer) and `/api/v1` + `/api/v1/admin` (admin).
> Standard envelope: `{success, message, data, meta?}` / errors `{success:false, message, errors?}`.
> NEVER expose: coupon codes in public listing, `failed_rules`, `used/limiter`
> counters, other users' assignments, reservation internals.

## Endpoints

| # | Method | URL | Auth | Request | Success | Errors |
| - | ------ | --- | ---- | ------- | ------- | ------ |
| 1 | GET | `/api/v1/general/coupons` | no | — | 200 list (NO `code` field) | — |
| 2 | POST | `/api/v1/general/coupons/{id}/claim` | sanctum | — | 201 `{id, coupon_id, claimed_at, expires_at}` | 401 / 404 (`COUPON_NOT_FOUND`) / 409 `{reason}` |
| 3 | GET | `/api/v1/general/coupons/mine` (NEW) | sanctum | — | 200 `{assignments:[...], claims:[...]}` (owner `code` included) | 401 |
| 4 | POST | `/api/v1/general/coupons/apply` | sanctum | `{code}` | 200 applied preview / 200 `{already_applied:true}` | 400 invalid/limit |
| 5 | POST | `/api/v1/general/checkout` | sanctum | checkout payload | order + payment redirect | 400/409 coupon reasons |
| 6 | POST | `/api/v1/general/fast-shipping/checkout` | sanctum | same | same | same |
| 7 | GET/POST | `/api/v1/general/checkout/callback` | public+throttle | gateway payload | order completion | mismatch → failed |
| 8 | GET/POST | `/api/v1/general/checkout/error-callback` | public+throttle | gateway payload | failure recorded | never 500 on coupon |
| 9 | POST | `/api/v1/general/checkout/cod/{orderId}/mark-paid` | `update-order-status` | — | completion via single path | 403 |
| 10 | POST | `/api/v1/general/checkout/cashier/{orderId}/mark-paid` | `update-order-status` | — | same | 403 |

## My Coupons behavior (endpoint 3)

```json
{
  "assignments": [
    {"id":1,"coupon_id":5,"code":"SAVE10","max_uses":2,"used":1,
     "remaining":1,"expired":false,"expires_at":"...","assigned_at":"..."}
  ],
  "claims": [
    {"id":9,"coupon_id":5,"code":"SAVE10","status":"ACTIVE",
     "claimed_at":"...","expires_at":"...","redeemed_at":null}
  ]
}
```

Flow: View (1) → Claim (2, needs `require_claim`) → My Coupons (3) →
Apply (4, paste `code`) → Checkout (5/6).

## Claim behavior

- `require_claim=false` → claim returns 409 `claim_not_required`; Apply directly.
- ACTIVE → re-claim 409 `already_claimed`. REDEEMED → 409 `already_claimed`.
  EXPIRED → may claim again. `max_claims` full → 409 `max_claims_reached`.
  Ineligible → 409 `not_eligible` (reason ONLY, no rule details).
- `expires_at` null = no TTL; else re-claim after expiry.

## Assignment behavior

- Assigned coupon visible ONLY in (3) + notifications, never in (1) with code.
- `remaining=0` or `expired=true` → Apply/Checkout reject
  (`quota_exhausted` / `not_assigned`-family reasons).
- Third use after 2/2 → checkout fails, order stays pending, no 500.

## Checkout behavior

- Apply is preview-only; checkout revalidates everything — always handle
  late 400/409 after an Apply 200.
- Success → order `completed`, usage recorded, claim `REDEEMED`.
- Failure → reservation released, NO usage; safe to retry checkout.

## States & reason codes (map 1:1 to UI strings)

`claim_required` · `already_claimed` · `already_used` · `already_redeemed`
(implied by `already_claimed` on REDEEMED) · `claim_expired` (→ re-claim allowed) ·
`coupon_unavailable` (`coupon_missing`) · `reservation_unavailable`
(`no_capacity`) · `assignment_expired` / `assignment_exhausted`
(`quota_exhausted`, `not_assigned`) · `coupon_expired` · `coupon_inactive` ·
`not_eligible` · `max_claims_reached` · `claim_not_required` · `no_targeting`.

## Notifications / realtime

| Trigger | Event | In-app (database) | Pusher (broadcast) | Firebase (fcm) | Email (mail) |
| ------- | ----- | ----------------- | ------------------ | -------------- | ------------ |
| Assigned | `coupon.assigned` | YES `coupon_assignment_id, coupon_id, code, max_uses, expires_at` | YES type `coupon.assigned` | YES | YES (if user has email) |
| New public coupon | `coupon.available` | YES | YES | YES | — |
| Consumed | `coupon.used` | YES `order_id, remaining_uses` | YES type `coupon.used` | YES | — |
| Order/payment | order+payment events | YES | YES | YES | per order contract |

Payloads carry localized `title.{en,ar}`, `message.{en,ar}`, `icon:tag`,
`resource_type:coupon`, `resource_id`, `action_url:/coupons/{id}`.
Frontend: subscribe user channel for `coupon.assigned`/`coupon.used` to
refresh My Coupons (3) live; register FCM via device-tokens endpoints.

## Errors never 500 on coupon paths

Claim/apply/checkout/payment callbacks return 400/404/409 with reason codes
on coupon failures; 500 only for genuine infra faults (which bubble, F-03).
