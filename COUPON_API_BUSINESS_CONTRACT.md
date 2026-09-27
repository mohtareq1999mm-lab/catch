# Coupon API Business Contract

> **Source of truth:** the current codebase in `D:\work\meem` (routes, controllers, requests, resources, services, models, tests, existing `api-desc/`).
> Nothing below is invented. Anything not found is marked **NOT FOUND IN CURRENT CODEBASE**.
> Anything that could not be proven is marked **NOT VERIFIED FROM CURRENT SOURCE**.
>
> **Envelope:** every REST response below uses the same outer shape:
>
> ```json
> { "status": 200, "message": "...", "success": true, "data": { "..." } }
> ```
>
> **Money:** all discount math is rounded to 2 decimals by the backend.
> **Coupon codes** are case-insensitive and surrounding spaces are ignored (`save10`, ` SAVE10 ` and `SAVE10` are the same code).
> **REST vs GraphQL:** this document covers the REST API (the primary, actually-used surface). A Marvel GraphQL coupon schema (`coupon.graphql`, `CouponMutator`, `CouponQuery`) exists in the package but the storefront/admin flows in code and tests use REST.

---

## 1. Overview

A Coupon gives the customer a price reduction (percentage with an optional cap, fixed amount, or free shipping) or is attached to the customer's cart and then consumed when the order completes.

There are two API families:

| Family | Base path | Who uses it |
| ------ | --------- | ----------- |
| Customer (storefront) | `/api/v1/general/coupons/...` + checkout | Frontend, signed-in customer |
| Admin | `/api/v1/coupons/...` (+ legacy alias `/api/v1/admin/coupons/...` for helpers) | Admin panel, staff with coupon permissions |

Key business facts (all verified in code):

- The **backend calculates the discount**. The frontend never sends amounts, totals, currency, or usage counters.
- The frontend sends **either a coupon `code`** (apply) **or a coupon `id`** (claim). Everything else is decided server-side.
- There is **no dedicated "validate only" customer endpoint** — validation happens inside apply, claim, listing, and checkout.
- There is **no "remove coupon" customer endpoint** — applying a new code overwrites the old one; checkout automatically clears a coupon that became invalid.
- A coupon can be **public**, **assigned to specific customers**, **governed by targeting rules**, or any combination. Assignment-only coupons are **private**: they never appear in the public listing; the owner sees them under **My Coupons**.
- Some coupons must be **claimed** before use (first-come, limited slots, expiring holds). Without a live claim they cannot be applied.
- Usage is counted **when the order completes**, not when the code is typed.

---

## 2. Customer Coupon Endpoints

### Endpoint 1 — Browse coupons (public listing)

```text
GET /api/v1/general/coupons
```

#### Business Purpose

> This endpoint is used to **show the customer which coupons exist and what they can do next** (apply it, claim it first, or view it).

#### Request

```http
GET /api/v1/general/coupons?limit=10&search=summer&order=desc
Accept: application/json
```

No body. No authentication required (guests allowed). If a signed-in request carries an expired/revoked token, the API returns **401** instead of silently showing the guest view.

#### Request Fields

| Field | Required? | Example | Business Meaning |
| ----- | --------- | ------- | ---------------- |
| `limit` | No (default 10, max 100) | `10` | How many coupons to return |
| `search` | No | `summer` | Finds coupons by name |
| `start_date` | No | `2026-01-01` | Only coupons created on/after this date |
| `end_date` | No | `2026-12-31` | Only coupons created on/before this date |
| `couponsId` | No | `1,2,3` | Only these coupon ids |
| `order` | No (`asc`/`desc`, default `desc`) | `desc` | Sort direction (by coupon id) |

#### Request Variations

- **Guest:** sees only publicly discoverable + targeted coupons; the `code` field is always `null` (codes are hidden). Assignment-only private coupons are excluded.
- **Signed-in customer:** same list, but each row additionally carries personal state (`eligible`, `claim_status`, `action`) and the `code` is revealed **only** when the customer is actually allowed to use it now.

#### What the customer is allowed to send

- **Required:** nothing.
- **Optional:** the filter fields above.
- **Backend controlled:** everything about eligibility, codes, and discounts. The frontend only reads what the backend returns.

#### Success Response

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Summer 20% Off",
      "slug": "summer-20-off",
      "image": { "desktop": "https://.../desktop.jpg", "mobile": "https://.../mobile.jpg" },
      "borderColor": "#FF0000",
      "borderless": false,
      "visibility": "public",
      "requires_claim": false,
      "eligible": true,
      "claim_status": null,
      "action": "apply",
      "code": "SUMMER20"
    }
  ]
}
```

#### Business Meaning

> Coupons that are currently valid are listed. Each row tells the frontend what to do next via `action` (e.g. show an **Apply** button, a **Claim** button, or just display it). A `null` code means "do not show a code / do not offer apply".

#### Error Responses

| HTTP | Situation | Business Meaning |
| ---- | --------- | ---------------- |
| 401 | Invalid/expired token was sent | Signed-in session is dead; clear the token and ask the customer to log in again. Guests (no token at all) are unaffected |

---

### Endpoint 2 — My coupons (assigned + claimed)

```text
GET /api/v1/general/coupons/mine
Authorization: Bearer <customer token>
```

#### Business Purpose

> This endpoint is used to **show the signed-in customer the coupons that belong to them personally**: coupons assigned to them and coupons they already claimed (with codes visible so they can be applied).

#### Request

```http
GET /api/v1/general/coupons/mine
Authorization: Bearer <customer token>
```

No body, no query fields.

#### Success Response

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "assignments": [
      {
        "id": 11,
        "coupon_id": 5,
        "code": "VIP-ONLY-42",
        "max_uses": 3,
        "used": 1,
        "remaining": 2,
        "expired": false,
        "expires_at": "2026-09-01T10:00:00+00:00",
        "assigned_at": "2026-07-25T10:00:00+00:00"
      }
    ],
    "claims": [
      {
        "id": 7,
        "coupon_id": 9,
        "code": "EARLY-BIRD",
        "status": "active",
        "claimed_at": "2026-07-20T10:00:00+00:00",
        "expires_at": "2026-07-27T10:00:00+00:00",
        "redeemed_at": null
      }
    ]
  }
}
```

#### Business Meaning

> `assignments` = personal grants from the store (with remaining uses). `claims` = coupons the customer reserved for themselves (`active` = usable now, `redeemed` = already spent, `expired` = lapsed and may be claimed again). The frontend should show codes from here and offer **Apply**.

#### Error Responses

| HTTP | Situation | Business Meaning |
| ---- | --------- | ---------------- |
| 401 | Missing/invalid token | Customer must log in |

---

### Endpoint 3 — Coupons available to me (personalized, paginated)

```text
GET /api/v1/general/coupons/available?page=1&limit=15
Authorization: Bearer <customer token>
```

#### Business Purpose

> This endpoint is used to **show a short personalized "you can use these" shelf**. It is advisory: the real check still happens at apply/checkout.

#### Request Fields

| Field | Required? | Example | Business Meaning |
| ----- | --------- | ------- | ---------------- |
| `page` | No (min 1) | `1` | Which page of the shelf |
| `limit` | No (1–50) | `15` | Shelf size |

#### Success Response

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [ { "…coupon shell, never codes, rules, or counters…" } ],
    "meta": { "current_page": 1, "per_page": 15, "total": 3, "has_more_pages": true }
  }
}
```

(`total` counts the eligible items on this page, not the pre-filter total; `has_more_pages` tells the frontend whether to fetch page+1.)

#### Error Responses

| HTTP | Situation | Business Meaning |
| ---- | --------- | ---------------- |
| 401 | Missing/invalid token | Customer must log in |
| 422 | `page`/`limit` out of range | Fix the pagination values |

---

### Endpoint 4 — Apply coupon to cart

```text
POST /api/v1/general/coupons/apply
Authorization: Bearer <customer token>
Content-Type: application/json
```

#### Business Purpose

> This endpoint is used to **attach a coupon code to the customer's current cart** so checkout prices it with the discount.

```json
{ "code": "SUMMER20" }
```

#### Request Fields

| Field | Required? | Example | Business Meaning |
| ----- | --------- | ------- | ---------------- |
| `code` | **Yes** (string, max 191) | `SUMMER20` | The coupon code the customer typed |

No other field is accepted. There is no guest variation — a signed-in customer with an active cart is required. No governorate/address field is accepted or needed (area rules use the customer's saved addresses automatically).

#### What the customer is allowed to send

- **Required:** `code`.
- **Optional:** nothing.
- **Backend controlled:** discount amount, new cart total, free-shipping flag, usage counters, validity. The frontend must not compute or send any of these.

#### Success Response (applied)

```json
{
  "status": 200,
  "message": "Coupon applied successfully",
  "success": true,
  "data": {
    "total_price": 160.0,
    "coupon_discount": 40.0,
    "free_shipping": false
  }
}
```

#### Business Meaning

> The coupon was accepted. The system calculated the discount and stored the code on the cart. The frontend receives the new cart total and the discount amount and should re-render prices and continue to checkout.

#### Already-applied Response

```json
{
  "status": 200,
  "message": "Coupon already applied",
  "success": true,
  "data": { "already_applied": true }
}
```

> Same code is already on the cart. Frontend should do nothing (or reassure the customer).

#### Error Responses

| HTTP | Body (`data`) | Business Meaning |
| ---- | ------------- | ---------------- |
| 422 | validation errors | `code` missing / not a string / too long — frontend must send a code |
| 400 | `{ "reason": "no_cart", "code": "COUPON_NO_CART" }` | Customer has no active cart — nothing to attach the coupon to |
| 400 | `{ "reason": "<reason>", "code": "COUPON_<REASON>" }` | Coupon rejected. `<reason>` is one of: `not_found`, `disabled`, `not_active`, `expired`, `usage_limit_reached`, `already_used`, `product_not_eligible`, `claim_required`, `not_assigned`, `assignment_expired`, `usage_quota_exceeded`, `not_eligible` (see §23) |
| 401 | — | Customer must log in |

---

### Endpoint 5 — Claim a coupon

```text
POST /api/v1/general/coupons/{id}/claim
Authorization: Bearer <customer token>
```

(`{id}` is the numeric coupon id, e.g. `POST /api/v1/general/coupons/9/claim` with an empty body.)

#### Business Purpose

> This endpoint is used to **reserve one of the limited claim slots for this customer** when a coupon requires claiming. After a successful claim the customer may apply the coupon; without a live claim, apply is rejected with `claim_required`.

#### Request

Empty body. No fields.

#### Success Response (201)

```json
{
  "status": 201,
  "message": "Coupon claimed successfully",
  "success": true,
  "data": {
    "id": 7,
    "coupon_id": 9,
    "code": "EARLY-BIRD",
    "status": "active",
    "claimed_at": "2026-07-20T10:00:00+00:00",
    "expires_at": "2026-07-27T10:00:00+00:00",
    "redeemed_at": null
  }
}
```

#### Business Meaning

> The customer now holds a live claim (`active`, possibly with an expiry). The frontend should move the coupon to "ready to apply" and offer **Apply**. If `expires_at` passes unused, the slot is released and the customer may claim again.

#### Error Responses

| HTTP | `data.reason` | Business Meaning |
| ---- | ------------- | ---------------- |
| 409 | `already_claimed` | Customer already holds a live claim or already redeemed this coupon — cannot claim again |
| 409 | `not_eligible` | Customer does not satisfy the coupon's assignment/targeting rules |
| 409 | `claim_not_required` | This coupon needs no claim — just apply it |
| 409 | `no_targeting` | Coupon has no claim configuration at all |
| 409 | `max_claims_reached` | All claim slots are taken (first-come limit reached) |
| 404 | — | Coupon id does not exist |
| 401 | — | Customer must log in |
| 500 | — | Unexpected failure; safe to retry later |

---

### Checkout (how the applied coupon is consumed)

The coupon is **not** sent again at checkout. The checkout endpoint prices whatever coupon is already on the cart:

```text
POST /api/v1/general/checkout
Authorization: Bearer <customer token>
```

Business behavior (verified in `OrderService::addItemsInOrder` / `calculateCheckoutTotals` / `CheckoutRepository::verify`):

1. Checkout re-checks the cart's coupon from scratch (full eligibility + validity revalidation). A coupon that was fine at apply-time but expired since is **silently removed from the cart** and the order continues without it.
2. Promotion is calculated **first**; the coupon is then calculated **on the amount left after the promotion** (§5).
3. A `free_shipping` coupon zeroes the shipping charge instead of reducing the price.
4. When the order completes, usage is recorded (§6) and the temporary payment hold is consumed (§7).

---

## 3. Coupon Validation

There is **no dedicated customer validation endpoint** (`POST /coupons/validate` or similar): **NOT FOUND IN CURRENT CODEBASE**.

Instead, every entry point runs the same real check chain (verified in `CouponOrchestrator::validate` + `CouponValidator::validate` + `CouponAssignmentValidator` + eligibility engine), in this business order:

1. **Claim gate** — if the coupon requires a claim: already-redeemed customers are rejected as `already_used`; customers without a live `active` claim are rejected as `claim_required`.
2. **Eligibility gate** — depending on the coupon's targeting mode (§10): assignment check, targeting-rules check, or the required combination.
3. **Basic validity** — enabled flag, start/end dates, global usage limit, whether this customer already used it (public coupons), and whether the cart contains an allowed product.

Only the rules that actually exist are listed: status, start date, end date, global usage limit (`limiter`/`used`), per-customer prior use, per-assignment quota/expiry, claim requirement, targeting mode + rule tree, saved-address area rule, product restriction (`coupon_product`). There is **no minimum-order, maximum-order, or currency rule** in the current coupon validation: **NOT FOUND IN CURRENT CODEBASE**.

---

## 4. Coupon Apply / Checkout Flow

The real customer flow, as implemented:

```text
Customer opens checkout (must be signed in, cart with items)
        ↓
Customer enters Coupon code
        ↓
Frontend sends POST /api/v1/general/coupons/apply  { "code": "…" }
        ↓
Backend finds the coupon (case-insensitive) → 400 not_found if unknown
        ↓
Backend checks claim requirement (live claim? → else claim_required)
        ↓
Backend checks customer eligibility (assignment / targeting rules)
        ↓
Backend checks basic validity (dates, enabled, limits, prior use, products)
        ↓
Backend calculates the discount against the current cart total
        ↓
Backend stores the code on the cart, returns new total + discount
        ↓
Frontend shows the new total
        ↓
Customer places the order → POST /api/v1/general/checkout
        ↓
Backend REVALIDATES the coupon, prices promotion-first-then-coupon,
creates a 30-minute payment hold, completes the order,
records usage, consumes the hold
```

If the coupon needs claiming, one extra step comes first: `POST /api/v1/general/coupons/{id}/claim` → then apply.

---

## 5. Coupon Calculation

The backend computes everything. The coupon has a `discount_type` and a `discount` value:

| `discount_type` | Business Meaning | Calculation (verified) |
| --------------- | ---------------- | ---------------------- |
| `percentage` | "X% off" | `price × (discount ÷ 100)`, capped at `max_discount_amount` when set (e.g. 20% capped at 50 → never more than 50 off) |
| `fixed_rate` | "X off the total" | `min(discount, price)` — never reduces below zero |
| `free_shipping` | "Free delivery" | No price reduction; shipping charge becomes 0 at checkout |

Notes:

- Percentage above 100 is rejected when the coupon is saved.
- `max_discount_amount` is required when creating a `percentage` coupon.
- Results are rounded to 2 decimals; the final price never goes below 0.
- **Promotion → Coupon order (verified in `calculateCheckoutTotals`):** the selected promotion is applied first; the coupon is then calculated against the amount left after the promotion. Tax is computed afterward on the discounted lines; shipping is never taxed.

---

## 6. Coupon Usage

> How many times can the coupon be used? When is usage counted?

- **Global capacity:** `limiter`. `limiter = null` means **unlimited** (verified: null never blocks; it is not zero). Otherwise the coupon stops working once `used >= limiter`.
- **When usage is counted:** only when the order **completes** (`recordCouponUsage`). Typing, applying, previewing, or claiming a code never increments usage.
- **Public coupons:** one use per customer, enforced by a per-customer usage record. A second completed order with the same coupon is rejected as `already_used` — and a past public use also blocks later reuse through a personal assignment of the same coupon.
- **Assigned coupons:** each personal assignment carries `max_uses` / `used` / `remaining (= max_uses − used)`. Completing an order increments both the assignment's `used` and the coupon's global `used`, plus a per-order usage row (repeat completion of the same order is idempotent — counted once).
- **When the limit is reached:** validation returns `usage_limit_reached` (global) or `usage_quota_exceeded` (personal quota), and checkout completion throws instead of granting a free second discount.
- **Admin visibility:** `GET /api/v1/coupons/{id}/usage-info` reports current usage, global limit, remaining capacity (`unlimited` when no limiter), per-customer configuration, and assignment aggregates.

---

## 7. Coupon Reservation

There **is** a reservation mechanism, but it has **no customer-facing endpoint** — it is fully automatic and internal.

In business language:

- **Why it exists:** to stop two customers from paying with the last use of the same coupon at the same moment.
- **When it is created:** during checkout, a temporary hold is placed for that order (one hold per order; re-attempts refresh it, never double-count).
- **How long it lasts:** **30 minutes** (`expires_at`). Capacity checks count `used + live holds` against `limiter`.
- **Checkout succeeds:** the hold is consumed (deleted) and real usage is recorded instead.
- **Payment fails / order cancelled / hold expires:** the hold is released (deleted); the coupon becomes available to others again.
- **Stale or missing hold at completion:** the system re-checks eligibility and capacity and takes a fresh hold; completion never proceeds on a stale hold and never silently skips the coupon.

Frontend/QA consequence: there is nothing to call and nothing to display; a coupon that shows as available can still be rejected at the last moment if someone else took the last slot.

---

## 8. Coupon Assignment

> Which customer can use the coupon?

An **assignment** is a personal grant: "customer X may use coupon Y up to N times, optionally until date Z." Any coupon with at least one assignment row stops being purely public — a customer **without** an assignment is rejected as `not_assigned` (except in targeting modes that explicitly allow another path, §10). Creating the first assignment on a public coupon converts its behavior from public to assigned.

Assignment endpoints are **admin-only** (Sanctum + assignment permissions). There is no customer assignment endpoint; customers only *see* their assignments via `GET /api/v1/general/coupons/mine`.

| Method | URL | Purpose |
| ------ | --- | ------- |
| GET | `/api/v1/coupons/{coupon}/assignments?limit=15&page=1` | List who received this coupon (permission `view-coupon-assignments`) |
| POST | `/api/v1/coupons/{coupon}/assignments` | Give the coupon to a customer (permission `create-coupon-assignment`) |
| GET | `/api/v1/coupons/{coupon}/assignments/{assignment}` | Show one grant (permission `view-coupon-assignments`) |
| PUT | `/api/v1/coupons/{coupon}/assignments/{assignment}` | Change `max_uses` / `expires_at` (permission `update-coupon-assignment`) |
| DELETE | `/api/v1/coupons/{coupon}/assignments/{assignment}` | Take the grant back (permission `delete-coupon-assignment`; blocked when `used > 0`) |

**Create request:**

```json
{ "user_id": 2, "max_uses": 5, "expires_at": "2026-08-25T10:00:00.000000Z" }
```

| Field | Required? | Example | Business Meaning |
| ----- | --------- | ------- | ---------------- |
| `user_id` | **Yes** (must be a real user) | `2` | Which customer receives the coupon |
| `max_uses` | **Yes** (≥ 1) | `5` | How many times this customer may use it |
| `expires_at` | No (must be in the future) | `2026-08-…` | When this customer's grant lapses (absent = never) |

**Update request:** `{ "max_uses": 10, "expires_at": "…" }` — both optional; `max_uses` can never go below the already-`used` count (422); `expires_at` may be set to `null` to remove expiry; the customer and coupon can never be changed.

**Assignment responses** return `{ id, coupon_id, user_id, user: {id,name,email}|null, max_uses, used, remaining, is_expired, assigned_at, expires_at }` with `remaining = max(0, max_uses − used)`.

**Errors:** 201 created · 200 updated/deleted · 404 coupon/assignment not found (or assignment belongs to another coupon) · 409 duplicate grant for the same customer, or delete blocked by usage history (`used > 0`) · 422 validation / `max_uses` below `used` · 401/403 auth.

**Lifecycle answers:** an expired or fully-used personal grant fails with `assignment_expired` / `usage_quota_exceeded`; another customer trying to use the coupon fails with `not_assigned`; deleting a used grant is blocked (409) so history is never lost.

---

## 9. Coupon Targeting

Targeting = the rule configuration stored on a coupon (`coupon_targetings` row). It has **no customer endpoint**; it is evaluated silently inside claim/apply/checkout and managed through **admin-only** endpoints:

| Method | URL | Purpose |
| ------ | --- | ------- |
| GET | `/api/v1/coupons/{id}/targeting` (alias `/api/v1/admin/coupons/{id}/targeting`) | View the coupon's targeting config (read permission) |
| PUT | `/api/v1/coupons/{id}/targeting` (alias `/api/v1/admin/coupons/{id}/targeting`) | Create/replace the config (write permission) |
| DELETE | `/api/v1/coupons/{id}/targeting` (alias `/api/v1/admin/coupons/{id}/targeting`) | Remove targeting → coupon becomes always-eligible |
| GET | `/api/v1/coupons/rules` (alias `/api/v1/admin/coupons/rules`) | Read-only rule catalog for the admin targeting builder (read permission) |

**PUT request:**

```json
{
  "mode": "dynamic",
  "require_claim": true,
  "max_claims": 500,
  "claim_ttl_hours": 72,
  "rule_tree": { "…rule groups…" }
}
```

| Field | Required? | Example | Business Meaning |
| ----- | --------- | ------- | ---------------- |
| `mode` | **Yes**: `assignment` / `dynamic` / `assignment_and_dynamic` / `assignment_or_dynamic` | `dynamic` | How eligibility is decided (§10) |
| `require_claim` | **Yes** (boolean) | `true` | Whether customers must claim before use (§12) |
| `max_claims` | No (≥ 1) | `500` | Total claim slots across all customers (absent = unlimited) |
| `claim_ttl_hours` | No (1–8760) | `72` | How long one claim stays live (absent = never expires) |
| `rule_tree` | No (object; validated fail-closed) | `{…}` | The customer-matching rules (dynamic family); absent = no rules = eligible |

**Targeting responses** return `{ id, coupon_id, mode, require_claim, max_claims, claim_ttl_hours, rule_tree, created_at, updated_at }`.

**Errors:** 200 shown/saved/deleted · 404 coupon without targeting (GET/DELETE) · 422 malformed `rule_tree` or bad ranges · 401/403 auth (reads need a coupon-view permission; writes need a coupon-update permission).

---

## 10. Targeting Modes

Verified in the orchestrator + eligibility engine (evaluation only — never publicity):

| Mode | Business Meaning |
| ---- | ---------------- |
| `assignment` | The customer must hold a **live personal assignment** (not expired, quota left). Coupons with no targeting row behave this way. |
| `dynamic` | The customer must satisfy the **rule tree** (purchase history, saved-address area, account rules…). Assignments are ignored. A rule tree that is absent means "no rules" = eligible. |
| `assignment_and_dynamic` | The customer must satisfy **both**: a live assignment **and** the rule tree. |
| `assignment_or_dynamic` | Either path is enough: a live assignment (when the coupon grants assignments) **or** a passing rule tree. A coupon with assignment rows still rejects customers who satisfy neither. |

Unknown modes fail closed (rejected as `not_eligible`).

---

## 11. Coupon Audience

The `audience` object on every admin coupon response answers **"who is this coupon for?"**. It is computed from three independent facts (authoritative resolver, verified):

- `is_public` — the persisted public-discoverability flag on the coupon. Creating assignments never changes it.
- `has_assignments` — at least one personal-grant row exists.
- `has_targeting` — a targeting-config row exists (any mode).

```json
"audience": { "type": "PUBLIC_AND_ASSIGNED", "is_public": true, "has_assignments": true, "has_targeting": false }
```

| `type` | Business Meaning |
| ------ | ---------------- |
| `PUBLIC` | General coupon: no assignments, no targeting (or nothing configured at all — unconfigured coupons are publicly discoverable) |
| `ASSIGNED` | Private personal grants only (hidden from the public listing) |
| `TARGETED` | Rule-governed coupon, no personal grants |
| `PUBLIC_AND_ASSIGNED` | Publicly visible and also granted to specific customers |
| `PUBLIC_AND_TARGETED` | Publicly visible with claim/rules behavior |
| `ASSIGNED_AND_TARGETED` | Personal grants combined with rules |
| `PUBLIC_AND_ASSIGNED_AND_TARGETED` | All three mechanisms at once |

Legacy note (frozen meaning, still used by `usage-info`): `Coupon::isPublic()` = "has no assignment rows", which feeds `coupon_type: public|assigned` there — a different, older definition than the resolver above. The resource also echoes `is_assigned` (= has assignments), `audience_type` (= composite label), and `targeting_mode` (eligibility mode only).

---

## 12. Coupon Claim

Claim fields live on the targeting row and are visible to **admins** (targeting GET/PUT); customers only experience the behavior:

| Field | Meaning |
| ----- | ------- |
| `require_claim` | Whether the customer must claim before the coupon can be applied |
| `max_claims` | Total claim slots across all customers (`null` = unlimited); active + redeemed claims occupy slots, expired claims release them |
| `claim_ttl_hours` | Lifetime of one claim (`null` = never expires); expiry releases the slot and the customer may claim again |

Behavioral answers:

- **Coupon requires claiming:** apply/checkout without a live `active` claim fails as `claim_required`. Already-redeemed customers fail as `already_used` (they cannot reclaim).
- **How the customer claims:** `POST /api/v1/general/coupons/{id}/claim` (empty body) → 201 with the claim.
- **After claim:** the claim is `active` (possibly expiring); apply works.
- **Without claiming:** the coupon cannot be used while `require_claim` is on.
- **Claim limit reached:** claim fails with `max_claims_reached` (409); first-come, first-served.
- **Claim expires:** status becomes `expired`, the slot is freed, re-claim is allowed; an hourly reconciliation also expires overdue claims and heals duplicate/orphan records.

---

## 13. Admin Coupon Endpoints (CRUD)

All under `/api/v1/coupons` (Marvel REST provider), Sanctum + coupon permissions. List/show need `view-coupons`; create needs `create-coupon`; update needs `update-coupon`; delete needs `delete-coupon`.

### List — `GET /api/v1/coupons`

```http
GET /api/v1/coupons?limit=15&active=1&search=SUMMER&order=created_at&sortedBy=desc
Authorization: Bearer <admin token>
```

Paginated; response `data` holds `{ data: [Coupon…], page, current_page, from, to, last_page, path, per_page, total, next_page_url, prev_page_url, last_page_url, first_page_url }`.

### Create — `POST /api/v1/coupons` → 201

Multipart body (`CouponRequest`). The Admin creates a coupon that customers can later discover/apply/claim:

| Field | Required? | Example | Business Meaning |
| ----- | --------- | ------- | ---------------- |
| `name` | **Yes** (object of translations) | `{"en":"Summer Sale","ar":"تخفيضات الصيف"}` | Display name per language (unique) |
| `image-desktop` | **Yes** (image jpeg/png/jpg/webp/gif) | file | Banner for desktop surfaces |
| `image-mobile` | **Yes** (image) | file | Banner for mobile surfaces |
| `discount` | **Yes** (number ≥ 0; ≤ 100 for percentage) | `20` | How big the reduction is |
| `discount_type` | **Yes**: `fixed_rate` / `percentage` / `free_shipping` | `percentage` | What kind of reduction |
| `max_discount_amount` | Required when `percentage` (≥ 1) | `50` | Cap on a percentage reduction |
| `start_date` | **Yes** (`Y-m-d`) | `2026-07-01` | First valid day |
| `end_date` | **Yes** (`Y-m-d`, ≥ start) | `2026-08-31` | Last valid day |
| `limiter` | No (integer ≥ 0; absent = unlimited) | `1000` | Global redemption capacity |
| `status` | No (`1`/`0`) | `1` | Enabled / disabled |
| `is_public` | No (boolean, default false) | `true` | Publicly discoverable flag |
| `border_color` | No (string ≤ 50) | `#FF0000` | Display styling |
| `borderless` | No (`1`/`0`) | `0` | Display styling |

Backend controlled: `code` (auto-generated `COUPON_XXXXXXX`, normalized; duplicates rejected), `slug` (server-managed), `used` (system counter — never admin-writable; capacity is managed via `limiter`).

**Errors:** 201 created · 400 could not create · 422 validation · 401/403 auth.

### Show — `GET /api/v1/coupons/{id}` → 200 (404 if unknown; accepts numeric id **or** code string)

### Update — `PUT /api/v1/coupons/{id}` → 200

Same fields as create but all optional (`UpdateCouponRequest`); images replace existing ones when sent; product links are fully replaced when sent; `used`/`code` stay system-managed. **Errors:** 200 updated · 400 could not update · 404 · 422 · 401/403.

### Delete — `DELETE /api/v1/coupons/{id}` → 200 (404 if unknown)

Removes the coupon. What happens to historical orders, assignments, targeting, claims, and holds on delete: **NOT VERIFIED FROM CURRENT SOURCE**.

---

## 14. Admin Assignment Endpoints

Covered in §8 (table, request/response shapes, errors). Admin perspective: open the coupon, list its grants, create one grant per customer with an individual limit and optional expiry, adjust or revoke later. Deleting a grant that already has usage is blocked to protect history.

---

## 15. Admin Targeting Endpoints

Covered in §9–§10. Admin perspective: open the coupon's targeting, pick how eligibility is decided (assignment / rules / both / either), decide whether customers must claim first and with how many slots and what lifetime, and write the rule tree using the catalog from `GET /api/v1/coupons/rules`. Removing targeting returns the coupon to always-eligible.

---

## 16. Configuration Helpers (admin)

| Method | URL (+ legacy alias) | Purpose |
| ------ | -------------------- | ------- |
| POST | `/api/v1/coupons/validate-configuration` (`/api/v1/admin/coupons/validate-configuration`) | Pre-save sanity check for a planned configuration |
| GET | `/api/v1/coupons/{id}/usage-info` (`/api/v1/admin/coupons/{id}/usage-info`) | Plain-language usage report for one coupon |
| POST | `/api/v1/coupons/{id}/suggest-fix` (`/api/v1/admin/coupons/{id}/suggest-fix`) | Guidance for switching single/multi-use-per-customer behavior (changes nothing itself) |

**Validate request:** `{ "coupon_type": "public|assigned" (required), "limiter": 100 (optional ≥ 1), "max_uses_per_user": 1 (optional ≥ 1) }` → 200 `{ valid, errors[], warnings[], recommendations[] }`. Business rules: public coupons enforce single use per customer (`max_uses_per_user > 1` is an error); assigned coupons get multi-use guidance; missing global limiter on assigned coupons is a warning; very high public limits draw a warning.

**Usage-info response:** `{ coupon_code, coupon_type (public|assigned, legacy assignment-based definition), usage_model ("Public coupon: Single use per customer (global limit: …)" / "Assigned coupon: Up to … uses per assigned customer"), current_usage, global_limit, remaining_capacity (number or "unlimited"), is_multi_use_per_user, assignment_info ({total_assignments, assignments_with_usage, max_uses_per_user, total_possible_redemptions} or null), public_usage_count }`.

**Suggest-fix request:** `{ "desired_behavior": "multi_use_per_user|single_use_per_user" }` (anything else → 400 with `allowed_values`) → 200 `{ current_state, recommended_action (convert_to_assigned|update_assignments|no_change_needed), summary, steps[], expected_result, warnings[], available_actions[] (method+path links into the assignment APIs) }`. Read-only: nothing is modified.

---

## 17. Coupon Response Object (admin)

Main shape (`CouponResource`), all fields actually returned:

| Field | Example | Business Meaning |
| ----- | ------- | ---------------- |
| `id` | `1` | Coupon identifier (used in claim/targeting/assignment URLs) |
| `code` | `SUMMER20` | The code customers type (admin-visible; hidden from guests) |
| `name` | `Summer 20% Off` (locale) or `{ar,en}` on show | Display name |
| `image` | `{desktop: url, mobile: url}` | Banners |
| `borderColor` / `borderless` | `#FF0000` / `false` | Display styling |
| `discount` | `20` | Reduction value |
| `discount_type` | Localized label (`Fixed discount` / `Percentage discount` / raw `free_shipping`) | Reduction kind |
| `max_discount_amount` | `50` or `null` | Cap for percentage reductions |
| `start_date` / `end_date` | `2026-07-01` | Validity window |
| `limiter` | `1000` or `null` (= unlimited) | Global capacity |
| `used` | `50` | Completed redemptions so far |
| `status` | `true` | Enabled / disabled |
| `is_valid` | `true` | Basic validity **without** per-customer eligibility (§18) |
| `audience` | `{type, is_public, has_assignments, has_targeting}` | Who the coupon is for (§11) |
| `is_assigned` | `false` | Whether any personal grants exist |
| `audience_type` | `PUBLIC` | Composite audience label |
| `targeting_mode` | `dynamic` or `null` | How eligibility is evaluated (§10) |
| `targeting` | object or `null` | Claim/rule configuration |
| `assignments` | `[{id, coupon_id, user_id, max_uses, used, remaining, expires_at, assigned_at}]` | Personal grants (ids + quotas only) |
| `created_at` | ISO 8601 | Creation time |

---

## 18. Coupon Validity (`is_valid`)

`is_valid` on the response = **basic validity only**: enabled flag, start/end dates vs today, and global capacity (`used < limiter`, null = unlimited). It does **not** include per-customer eligibility (assignment, targeting, claim, area, prior use) — a coupon can show `is_valid: true` and still be unusable by a specific customer.

Different endpoints, different validity concepts (do not mix):

| Surface | What "valid" means |
| ------- | ------------------ |
| Admin response `is_valid` | Basic validity above (no customer context) |
| Admin list `is_valid` / `active` / `inactive` / `expired` filters | Same basic concept as query filters (`expired` = past end-date only) |
| Customer listing (`GET /api/v1/general/coupons`) | Basic validity **plus** catalog rules (assignment-only private coupons excluded) |
| Apply / claim / checkout | Full check: basic validity **plus** claim gate **plus** eligibility gate (assignment/targeting/area/prior use) |

---

## 19. Customer Eligibility ("Can this customer use this coupon?")

All of these must pass (each failure has its own reason code):

1. Coupon exists (`not_found` otherwise).
2. Coupon enabled and within dates (`disabled` / `not_active` / `expired`).
3. Global capacity left (`usage_limit_reached`); personal quota left (`usage_quota_exceeded`); personal grant not lapsed (`assignment_expired`).
4. Customer holds a required assignment (`not_assigned`) unless the targeting mode allows another path.
5. Targeting rule tree passes (`not_eligible`) when the mode evaluates rules.
6. Live claim held when required (`claim_required`); not already redeemed (`already_used`).
7. Saved-address area matches when an area rule exists (no-match → `not_eligible`).
8. Cart contains an allowed product when the coupon is product-restricted (`product_not_eligible`).
9. No prior public consumption of the same coupon (`already_used` — also blocks the assigned path).

In short: **a coupon may be globally valid but still unavailable to a specific customer.**

---

## 20. Area / Location Rules

Coupons can carry an `area_in` targeting rule, and it works as follows (verified in the eligibility engine):

- **What location is checked:** the customer's **own saved addresses** (`address.governorate_id`), intersected with currently **active** governorates. Addresses without a governorate never match.
- **What is ignored:** checkout delivery input, request-supplied ids, and any `governorate_id` context — delivery drives shipping only, never coupon eligibility.
- **When it is checked:** at claim, at apply, and again authoritatively at checkout/payment (a rule true at apply but false at checkout is rejected).
- **On mismatch:** the customer fails eligibility (`not_eligible`) and cannot claim/apply/use the coupon.

Frontend/QA consequence: area-gated coupons depend on the customer having a saved address in an allowed, active governorate — test with saved addresses, not checkout form values. Guests (no saved addresses) cannot satisfy area rules.

---

## 21. Error Scenarios

Only errors proven in code/tests:

| Situation | HTTP | `reason` / `code` | Business Meaning |
| --------- | ---- | ------------------ | ---------------- |
| Unknown code | 400 | `not_found` / `COUPON_NOT_FOUND` | No such coupon; check spelling |
| Coupon disabled by admin | 400 | `disabled` / `COUPON_DISABLED` | Temporarily switched off |
| Before start date | 400 | `not_active` / `COUPON_NOT_ACTIVE` | Not started yet |
| After end date | 400 | `expired` / `COUPON_EXPIRED` | Lapsed |
| Global redemptions exhausted | 400 | `usage_limit_reached` / `COUPON_USAGE_LIMIT_REACHED` | Fully consumed |
| Customer already used it | 400 | `already_used` / `COUPON_ALREADY_USED` | One use per customer (public path, incl. via past public use) |
| Coupon restricted to other products | 400 | `product_not_eligible` / `COUPON_PRODUCT_NOT_ELIGIBLE` | Cart has none of the allowed products |
| Claim required but missing | 400 | `claim_required` / `COUPON_CLAIM_REQUIRED` | Must claim first |
| No personal grant | 400 | `not_assigned` / `COUPON_NOT_ASSIGNED` | Coupon is for other customers |
| Personal grant lapsed | 400 | `assignment_expired` / `COUPON_ASSIGNMENT_EXPIRED` | Grant past its `expires_at` |
| Personal quota spent | 400 | `usage_quota_exceeded` / `COUPON_USAGE_QUOTA_EXCEEDED` | All personal uses consumed |
| Rules not satisfied | 400 | `not_eligible` / `COUPON_NOT_ELIGIBLE` | Targeting/area rules failed |
| No cart | 400 | `no_cart` / `COUPON_NO_CART` | Nothing to attach the coupon to |
| Bad request shape | 422 | validation errors | Missing/oversized `code`, bad pagination, bad admin fields |
| Claim conflict | 409 | `already_claimed` / `not_eligible` / `claim_not_required` / `no_targeting` / `max_claims_reached` | See §2 Endpoint 5 |
| Assignment conflict | 409 | message | Duplicate grant, or delete blocked by usage history |
| Assignment floor | 422 | message | `max_uses` below already-`used` |
| Not found | 404 | — | Coupon / assignment / targeting id unknown (or assignment of another coupon) |
| Unauthenticated | 401 | — | Login required (incl. dead bearer token on the public listing) |
| Forbidden | 403 | — | Signed in but missing the required coupon permission |
| Unexpected failure | 500 | — | Claim/checkout only; safe to retry later |

---

## 22. Customer Frontend Flow

The real flow using this system:

```text
Customer opens checkout (signed in)
        ↓
Frontend loads GET /api/v1/general/coupons (+ /available, + /mine)
and renders Apply / Claim buttons from each row's action
        ↓
If the coupon requires claiming:
    Frontend sends POST /api/v1/general/coupons/{id}/claim
        ↓
    Backend checks slots + eligibility, creates a live claim (201)
    or rejects (409 + reason)
        ↓
Customer enters/confirms the Coupon code
        ↓
Frontend sends POST /api/v1/general/coupons/apply  { "code": "…" }
        ↓
Backend validates (claim → eligibility → basic validity)
        ↓
Backend calculates the discount (promotion-first-then-coupon happens at checkout)
        ↓
Backend stores the code on the cart, returns new total + discount
        ↓
Frontend shows the discounted total
        ↓
Customer places the order (POST /api/v1/general/checkout)
        ↓
Backend revalidates everything, holds a 30-min slot, completes,
records usage, consumes the hold
        ↓
Customer sees the confirmed order with coupon_discount applied
```

Frontend rules of thumb: never compute a discount; a `null` code means "don't offer apply"; apply one code at a time (a new apply overwrites); after any 401, clear the token and re-authenticate; after a `claim_required` rejection, route the customer through claim-then-apply.

---

## 23. Admin Flow

```text
Admin creates Coupon (POST /api/v1/coupons: discount, dates, limits, publicity)
        ↓
(optional) Pre-check the plan via POST .../validate-configuration
        ↓
Admin configures who can use it: assignments for specific customers
and/or a targeting config (PUT .../{id}/targeting: mode, claim, rule tree)
        ↓
Coupon becomes available according to its rules
(discoverable in the public listing unless assignment-only private)
        ↓
Customer claims (if required) → applies → checks out
        ↓
Backend checks eligibility at every step; records usage at completion
        ↓
Admin monitors via GET .../{id}/usage-info, assignment list,
and asks .../{id}/suggest-fix before changing single/multi-use behavior
```

---

## 24. Audience vs Targeting

Required explanation, in plain language:

- **Audience answers: "Who is this coupon for?"** It is the computed, display-level summary (`is_public` flag × has personal grants × has a targeting config → `PUBLIC`, `ASSIGNED`, `TARGETED`, and the four combinations). Audience decides **visibility** (listed publicly vs private to assignees).
- **Targeting Mode answers: "How does the system check this customer?"** It is the evaluation method stored on the targeting config (`assignment` = check the personal grant; `dynamic` = evaluate the rule tree; `…_and_…` / `…_or_…` = the required combination). Targeting decides **eligibility** at claim/apply/checkout.
- **They never substitute for each other:** a `TARGETED`-audience coupon in `assignment` mode still checks personal grants; an `ASSIGNED`-audience coupon in `dynamic` mode still evaluates rules. The admin list can filter by both independently (`audience_type` + `targeting_mode`).
- **Simple memory aid:** *Audience = who it's for. Targeting = how we check. Assignment = the personal ticket. Claim = the reservation slip.*

---

## 25. Public vs Assigned vs Targeted

Using the authoritative audience definitions:

- **Public** — a general coupon with no personal grants and no targeting config (or the public flag on). Anyone can discover it; each customer can still only use it once.
- **Assigned** — the coupon has personal grants. Only granted customers (with live, quota-left grants) can use it; everyone else gets `not_assigned`. Invisible in the public listing unless also public or targeted.
- **Targeted** — the coupon has a targeting config. Usability is decided by the mode + rules (+ claim when required), evaluated fresh at every step.
- **Combined** (e.g. `PUBLIC_AND_ASSIGNED`, `ASSIGNED_AND_TARGETED`, `PUBLIC_AND_ASSIGNED_AND_TARGETED`) — more than one mechanism exists; the targeting mode decides which of them a given customer must satisfy.

---

## 26. Complete API Summary

| Type | Method | Endpoint | Who Uses It | Purpose |
| ---- | ------ | -------- | ----------- | ------- |
| Customer | GET | `/api/v1/general/coupons` | Frontend (guest or customer) | Browse valid coupons with per-customer actions |
| Customer | GET | `/api/v1/general/coupons/mine` | Signed-in customer | Personal grants + own claims (codes visible) |
| Customer | GET | `/api/v1/general/coupons/available` | Signed-in customer | Personalized "you can use these" shelf |
| Customer | POST | `/api/v1/general/coupons/apply` | Signed-in customer | Attach a code to the cart |
| Customer | POST | `/api/v1/general/coupons/{id}/claim` | Signed-in customer | Take a claim slot before applying |
| Customer | POST | `/api/v1/general/checkout` | Signed-in customer | Place the order (coupon revalidated + consumed) |
| Admin | GET | `/api/v1/coupons` | Admin (`view-coupons`) | List/search/filter/sort coupons |
| Admin | POST | `/api/v1/coupons` | Admin (`create-coupon`) | Create a coupon |
| Admin | GET | `/api/v1/coupons/{id}` | Admin (`view-coupons`) | Show one coupon (id or code) |
| Admin | PUT | `/api/v1/coupons/{id}` | Admin (`update-coupon`) | Update a coupon |
| Admin | DELETE | `/api/v1/coupons/{id}` | Admin (`delete-coupon`) | Delete a coupon |
| Admin | GET | `/api/v1/coupons/{coupon}/assignments` | Admin (`view-coupon-assignments`) | List personal grants |
| Admin | POST | `/api/v1/coupons/{coupon}/assignments` | Admin (`create-coupon-assignment`) | Grant to a customer |
| Admin | GET | `/api/v1/coupons/{coupon}/assignments/{assignment}` | Admin (`view-coupon-assignments`) | Show one grant |
| Admin | PUT | `/api/v1/coupons/{coupon}/assignments/{assignment}` | Admin (`update-coupon-assignment`) | Change quota/expiry |
| Admin | DELETE | `/api/v1/coupons/{coupon}/assignments/{assignment}` | Admin (`delete-coupon-assignment`) | Revoke an unused grant |
| Admin | GET | `/api/v1/coupons/{id}/targeting` | Admin (coupon-view perm.) | View targeting config |
| Admin | PUT | `/api/v1/coupons/{id}/targeting` | Admin (coupon-update perm.) | Create/replace targeting config |
| Admin | DELETE | `/api/v1/coupons/{id}/targeting` | Admin (coupon-update perm.) | Remove targeting |
| Admin | GET | `/api/v1/coupons/rules` | Admin (coupon-view perm.) | Rule catalog for the targeting builder |
| Admin | POST | `/api/v1/coupons/validate-configuration` | Admin (coupon-view perm.) | Pre-save configuration check |
| Admin | GET | `/api/v1/coupons/{id}/usage-info` | Admin (coupon-view perm.) | Usage report |
| Admin | POST | `/api/v1/coupons/{id}/suggest-fix` | Admin (coupon-view perm.) | Single/multi-use guidance (read-only) |
| Admin | POST/GET | `/api/v1/coupons/{id}/distribute`, `/api/v1/coupons/{id}/distributions`, `/api/v1/coupons/{id}/distributions/{runId}` | Admin/system | Coupon distribution runs (request/response shapes NOT VERIFIED FROM CURRENT SOURCE) |
| Admin | GET | `/api/v1/dashboard/coupons` | Admin (`VIEW_ANALYTICS`) | Coupon analytics (shape NOT VERIFIED FROM CURRENT SOURCE) |
| Legacy alias | — | `/api/v1/admin/coupons/...` mirrors of rules, validate-configuration, usage-info, suggest-fix, targeting | Admin | Backward-compat URLs for the same helpers |

---

## 27. Request / Response Matrix

| Endpoint | Request | Success Response | Main Error Responses | Business Result |
| -------- | ------- | ---------------- | -------------------- | --------------- |
| `GET /api/v1/general/coupons` | query filters (all optional) | rows with `visibility/requires_claim/eligible/claim_status/action/code` | 401 dead token | Customer sees what to do next |
| `GET /api/v1/general/coupons/mine` | auth only | `assignments[]` + `claims[]` with codes | 401 | Customer sees personal coupons |
| `GET /api/v1/general/coupons/available` | `page`, `limit` + auth | data + meta (`current_page`, `per_page`, `total`, `has_more_pages`) | 401, 422 | Personal shelf |
| `POST /api/v1/general/coupons/apply` | `{ code }` + auth + cart | `total_price`, `coupon_discount`, `free_shipping` | 400 + reason, 422, 401 | Code on cart, prices updated |
| `POST /api/v1/general/coupons/{id}/claim` | auth, empty body | 201 claim (`active`, maybe `expires_at`) | 409 + reason, 404, 401, 500 | Claim slot held |
| `GET /api/v1/coupons` | 30+ admin filters + `view-coupons` | paginated `CouponResource` rows | 401, 403, 422 | Admin worklist |
| `POST /api/v1/coupons` | multipart coupon fields + `create-coupon` | 201 coupon | 400, 422, 401/403 | Coupon exists |
| `PUT /api/v1/coupons/{id}` | partial fields + `update-coupon` | 200 coupon | 400, 404, 422, 401/403 | Coupon changed |
| `DELETE /api/v1/coupons/{id}` | `delete-coupon` | 200 | 404, 401/403 | Coupon removed |
| Assignment CRUD | `user_id/max_uses/expires_at` + assignment perms | grant rows (`remaining`, `is_expired`) | 404, 409, 422 | Who can use it, managed |
| Targeting GET/PUT/DELETE | mode/claim/rule fields + coupon perms | targeting row | 404, 422 | How eligibility is checked, managed |
| `…/validate-configuration` | `coupon_type/limiter/max_uses_per_user` | `valid/errors/warnings/recommendations` | 422, 401/403 | Plan checked |
| `…/{id}/usage-info` | auth | usage report | 404, 401/403 | Numbers explained |
| `…/{id}/suggest-fix` | `desired_behavior` | steps + action links (nothing changed) | 400, 404 | Next admin move proposed |

---

## 28. Filter / List Matrix (admin `GET /api/v1/coupons`)

All parameters verified in `CouponIndexRequest` + `fetchCoupons`/`AdminCouponFilter`. Multiple filters combine with **AND**.

| Parameter | Type | Example | Meaning | Exists? |
| --------- | ---- | ------- | ------- | ------- |
| `limit` | int 1–100 (default 15) | `15` | Page size | Yes |
| `active` | boolean | `1` | Only basically-valid coupons | Yes |
| `inactive` | boolean | `1` | Only basically-invalid coupons | Yes |
| `search` | string ≤ 191 | `SUMMER` | Name (translated) OR code contains | Yes |
| `order` | enum: `id code name discount discount_type start_date end_date limiter used status created_at updated_at` | `created_at` | Sort column | Yes |
| `sortedBy` | `asc`/`desc` (default `asc`) | `desc` | Sort direction | Yes |
| `status` | boolean | `1` | Enabled flag only (narrower than validity) | Yes |
| `is_valid` | boolean | `true` | Same basic-validity concept as filter | Yes |
| `start_date` | `Y-m-d` | `2026-09-01` | Exact start-date match (null never matches) | Yes |
| `end_date` | `Y-m-d` | `2026-09-30` | Exact end-date match (null never matches) | Yes |
| `start_date_from` / `start_date_to` | `Y-m-d` | `2026-01-01` | Start-date window (`from` ≤ `to`, else 422) | Yes |
| `end_date_from` / `end_date_to` | `Y-m-d` | `2026-12-31` | End-date window | Yes |
| `date_from` / `date_to` | `Y-m-d` | `2026-01-01` | Overlap window (nulls = open-ended) | Yes |
| `discount_type` | `fixed_rate`/`percentage` | `percentage` | Reduction kind (stored value) | Yes |
| `discount` | number ≥ 0 | `50` | Exact discount value match | Yes |
| `discount_min` / `discount_max` | number ≥ 0 | `10` | Discount value range | Yes |
| `max_discount_amount_min` / `max_discount_amount_max` | number ≥ 0 | `50` | Percentage-cap range | Yes |
| `limiter` | int ≥ 0 | `200` | Exact global-capacity match (unlimited rows never match) | Yes |
| `used` | int ≥ 0 | `7` | Exact consumption match | Yes |
| `limiter_min` / `limiter_max` | int ≥ 0 | `100` | Global-capacity range | Yes |
| `used_min` / `used_max` | int ≥ 0 | `5` | Consumption range | Yes |
| `remaining_min` / `remaining_max` | int ≥ 0 | `1` | Remaining-capacity range (null limiter = ∞) | Yes |
| `expired` | boolean | `true` | Past end-date only (narrower than invalid) | Yes |
| `audience_type` | 7 composite values (§11) | `PUBLIC_AND_ASSIGNED` | Who the coupon is for | Yes |
| `is_public` | boolean | `true` | Public-discoverability flag | Yes |
| `has_assignments` | boolean | `true` | Has personal grants | Yes |
| `is_assigned` / `assignments` | boolean | `true` | Aliases of `has_assignments` (same result) | Yes |
| `has_targeting` | boolean | `true` | Has targeting config | Yes |
| `targeting` | boolean | `true` | Alias of `has_targeting` (same result) | Yes |
| `targeting_mode` | `assignment`/`dynamic`/`assignment_and_dynamic`/`assignment_or_dynamic` | `dynamic` | How eligibility is evaluated | Yes |
| `assigned_user_id` | int ≥ 1 | `2` | Granted to this customer | Yes |
| `require_claim` | boolean | `true` | Claim requirement flag | Yes |
| `not_expired` / `starting_soon` / `currently_active` | — | — | — | **NOT FOUND** (no such parameters) |
| `valid` / `order_by` / `sort` | — | — | Older names appearing only in stale `api-desc` examples | **NOT FOUND** in the current request validation (do not use) |
| `assignment_expires_from` / `assignment_expires_to` | — | — | — | **NOT FOUND** (assignment expiry is per-grant, not a list filter) |

Boolean spellings `true`/`false` are accepted alongside `1`/`0`. Contradictory ranges (`from` > `to`, `min` > `max`) are rejected with 422. Invalid `order`/`sortedBy` values are rejected with 422 (sorting then falls back to the model's `updated_at desc` default only when no valid `order` is given).

---

## 29. Important Missing APIs

Searched for, **not found in the current codebase** (do not call these; listed so nobody assumes them):

- No public/customer coupon-list-by-code or "check this code" endpoint — guests cannot test a code without signing in and applying it.
- No dedicated customer validation endpoint (`/coupons/validate`, `/coupons/check`, …).
- No customer "remove coupon from cart" endpoint — overwrite by re-applying; invalid coupons clear automatically at checkout.
- No customer assignment/claim-list endpoint beyond `…/mine` (which covers both).
- No admin bulk-assign / bulk-update endpoint — assignments are managed one grant at a time.
- No customer or admin "coupon history / my usage" endpoint beyond `…/mine` and `…/usage-info`.
- No minimum-order, maximum-order, or currency condition on coupons.
- No `not_expired` / `starting_soon` / `currently_active` admin list parameters.
- No `POST /api/v1/coupons/add-to-cart` route (only described in stale `api-desc`; the live customer path is `POST /api/v1/general/coupons/apply`, the live admin cart helper is the repository method, not a route).
