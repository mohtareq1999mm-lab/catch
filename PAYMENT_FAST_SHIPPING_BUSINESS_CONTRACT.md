# PAYMENT + FAST SHIPPING API BUSINESS CONTRACT

## Audit Status

```text
CURRENT SYSTEM:
One shared payment system serves both checkout flows. Normal checkout
(POST /api/v1/general/checkout) and fast-shipping checkout
(POST /api/v1/general/fast-shipping/checkout) both create/update the
customer's pending order and both start online payments through the same
payment handler, the same gateway abstraction (MyFatoorah / Stripe /
PayPal), the same return pages, the same webhooks, the same verification
rules, and the same completion, refund, retry, and expiration logic.
Fast shipping differs in the cart slice it sells, the fee/ETA it adds,
its availability gates, and several request/response details — not in
the payment machinery itself.

FAST SHIPPING:
SUPPORTED (fully traced; payment reuses the normal payment system)

PUBLIC CHECKOUT OPTIONS:
DOES NOT EXIST (no single endpoint; fragments exist — see §16)

PRODUCTION CODE CHANGED:
NO
```

**Statement classes used in this document:** `CURRENT` = proven from
code · `PROPOSED` = recommended, not implemented · `NOT FOUND` =
searched, absent · `NOT PROVEN` = code does not establish conclusively.

---

## Executive Business Summary

- Fast shipping is a **separate checkout for a separate cart slice**: only
  products flagged fast-eligible, sold with an extra fast fee and a
  delivery promise (default: 120 minutes), during working hours, in
  enabled areas.
- For payment, fast shipping **is** normal checkout: same handler, same
  three gateways, same amount/currency rules, same callbacks, same
  webhooks, same refunds, same expiry.
- Both checkouts share **one pending-order pool**: retrying can convert a
  normal pending order into a fast one and vice versa.
- Real differences the frontend must know: fast checkout always requires
  address + area; allows cashier-with-delivery (normal forbids it); has no
  free-order shortcut (a zero total goes to the provider and fails);
  returns 422 (not 400) for an empty cart; and does not accept a `type`
  field on paper (but still honors it for app return pages).
- The frontend currently **hard-codes** gateways, methods, fulfillment
  types, and combination rules. Only fast-shipping on/off-hours/fee
  (public status endpoint) and raw settings/currency fragments are
  readable. A proposed `GET /api/v1/general/checkout/options` contract is
  included (§16) — **not implemented**.

---

## 1. Overview

The shop has two checkout endpoints that share one payment system:

| | Normal | Fast shipping |
|---|---|---|
| Endpoint | `POST /api/v1/general/checkout` | `POST /api/v1/general/fast-shipping/checkout` |
| Sells | Scheduled cart items | Fast cart items only |
| Extra charge | Normal shipping price | Normal shipping price **+ fast fee** |
| Delivery promise | Standard | Promised ETA (setting, default 120 min) |
| Availability gates | None beyond cart/auth | Global switch, working hours, area, product eligibility |
| Payment system | Shared | **Same shared system** |

---

## 2. Normal Checkout

`CURRENT`. Fully documented in `PAYMENT_API_BUSINESS_CONTRACT.md`. Summary:
one call creates/updates the pending order from the **scheduled** cart
slice and starts online payment (MyFatoorah default / Stripe / PayPal),
or records COD / cashier (pickup only) / free-order completion. Returns
`data.url` (redirect) or `data.order_id`.

---

## 3. Fast Shipping Checkout

### 3.1 Endpoint `CURRENT`

- Method: **POST**
- URL: **`/api/v1/general/fast-shipping/checkout`**
- Who uses it: **Frontend / customer (logged in)**
- Login required: **Yes** (same authenticated group as normal checkout)

Related `CURRENT` endpoints:

- `GET /api/v1/general/fast-shipping/status` — **public**, no login.
  Returns whether fast shipping is on and orderable right now:

```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": {
    "enabled": true,
    "available": true,
    "duration_minutes": 120,
    "fee": 2.5,
    "opens_at": "08:00",
    "closes_at": "22:00",
    "available_again_at": null
  }
}
```

- Admin: `GET` + `PUT fast-shipping/settings` (package routes, staff
  login) and per-area fast-shipping toggle on governorates. These control
  the gates below.

`NOT FOUND`: no other fast-shipping API routes exist. Two controller
methods (`products`, `orders`) have **no route** and cannot be called.

### 3.2 Request `CURRENT`

```http
POST /api/v1/general/fast-shipping/checkout
Content-Type: application/json
Accept: application/json
Authorization: Bearer {customer-token}
```

| Field | Required | Type | Validation | Default | Business Meaning | Used By |
| ----- | -------- | ---- | ---------- | ------- | ---------------- | ------- |
| `name` | Yes | string ≤255 | required | — | Customer name | Order |
| `user_phone` | Yes | string ≤255 | required | — | Customer phone | Order + provider |
| `user_email` | No | email ≤255 | optional | — | Customer email | Order + provider |
| `address` | **Yes, always** | array | required | — | Delivery address (also required for pickup, unlike normal) | Order |
| `governorate_id` | **Yes, always** | integer | required, must exist | — | Area (also required for pickup, unlike normal) | Shipping + availability |
| `notes` | No | string | optional | — | Order note | Order |
| `fulfillment_type` | No | text | `delivery`\|`pickup` | `delivery` | How customer receives order | Order |
| `payment_method` | No | text | `online`\|`cod`\|`pay_at_cashier` | `online` | How customer pays | Payment branch |
| `gateway` | No | text ≤50 | any string (checked later) | `myfatoorah` | Online provider | Payment gate |
| `pickup_location_id` | If pickup | integer | required-if pickup, must exist | — | Branch | Order |
| `selected_promotion_id` | No | integer | must exist | cart default | Promotion | Totals |
| `selected_gift_product_id` | No | integer | must exist | cart default | Gift | Totals |

Accepted but NOT in the validation rules (still read by the system):
`type` (`web`/`mobile` — controls app vs web return format, same as
normal). There is deliberately **no `type` rule** on this endpoint, so a
bad value is not rejected here (the return page itself only accepts
`web|mobile`).

**MUST NOT send** (ignored): `amount`, `total`, `price`, `currency`,
`currency_code` and any totals — the system computes everything.

### 3.3 What makes an order "Fast Shipping" `CURRENT`

```text
1. It is built ONLY from cart lines marked for fast shipping
   (products must carry the fast-eligible flag).
2. It adds the configured fast fee on top of normal shipping.
3. It stamps a promised delivery time (now + configured minutes).
4. The order is stored with shipping method "FAST" (normal: "SCHEDULED").
```

Why a separate endpoint: it enforces the fast availability gates, prices
the fast slice (plus fee/ETA), and clears only the fast slice from the
cart — the scheduled slice stays for a normal checkout later. It does
**not** change inventory handling, payment, delivery tracking states, or
the provider integration.

### 3.4 Fast availability gates `CURRENT`

The fast checkout is rejected (HTTP 422, plain-text reasons joined
together) unless ALL hold:

1. Fast shipping globally enabled (admin setting, default **off**).
2. Current time within working hours (default 08:00–22:00).
3. The selected area has fast shipping enabled and is active.
4. Every fast cart line's product is fast-eligible.
5. The fast cart slice is not empty (dedicated message if so).

The public status endpoint (§3.1) lets the frontend check 1–2 + fee/ETA
before showing the option; 3–5 need cart + area and are only decided at
checkout.

### 3.5 Success responses `CURRENT`

Online (all gateways) — identical shape to normal checkout:

```json
{ "status": 200, "message": "Checkout successful", "success": true,
  "data": { "url": "https://provider-payment-page/..." } }
```

COD / cashier — identical to normal:

```json
{ "status": 200, "message": "Checkout successful", "success": true,
  "data": { "order_id": 123 } }
```

Free (zero-total) online order — **differs from normal**: there is no
free-order shortcut here. The zero amount is sent into the normal online
flow, where Stripe/PayPal reject it (`500`) and MyFatoorah forwards a
zero-value invoice to the provider (provider-dependent result). Frontend:
do not expect an `order_id`-only success for free fast orders.

Mobile (`"type": "mobile"` sent though unlisted): the return page still
answers with app JSON instead of a redirect (same mechanism as normal).

### 3.6 Error responses `CURRENT`

| Situation | Response | Note vs normal |
|---|---|---|
| Not logged in | 401 | Same |
| No active cart | 400 Cart not found | Same pre-check |
| Cart has no fast items | 422 "No fast shipping items in cart." | Fast-only |
| Area missing/unknown | 422 validation / "Governorate not found." | Always required here |
| Fast unavailable (gates §3.4) | 422 with the gate reason(s) | Fast-only |
| Bad field | 422 field-problem list | Same shape |
| COD + pickup | 422 not available | Same rule |
| Unknown payment method | 422 invalid payment method | Same |
| Gateway disabled/unconfigured | 422 "Payment gateway is unavailable" | Same, same handler |
| Currency unsupported | 422 "…does not support the selected currency…" | Same |
| Provider failure | 500 "Error creating invoice" | Same |
| Too many requests | 429 | Same |

`NOT FOUND / NOT PROVEN`: no fast-specific callback, webhook, expiry, or
duplicate-request errors — the shared ones apply.

---

## 4. Payment Methods

`CURRENT` — identical set on both checkouts: `online`, `cod`,
`pay_at_cashier`. Unknown values → 422. COD + pickup → 422 on both.
**Difference**: normal checkout forces cashier to pickup; fast checkout
accepts cashier with delivery too.

## 5. Payment Gateways

`CURRENT` — identical set and selection on both: `gateway` field,
default `myfatoorah`; `myfatoorah` / `stripe` / `paypal`. Same
availability gate (exists → enabled → configured → online method →
currency). Same 422 responses. The order record on fast checkout may
store an empty provider label (the label-copy step of normal checkout is
absent there) — record-keeping only, no behavioral effect.

## 6. Amount & Currency

`CURRENT` — same authority on both flows:

```text
WHAT THE FRONTEND SENDS: nothing about money.
WHAT THE BACKEND DOES: totals = items − discounts + taxes + shipping
  (+ fast fee for fast orders), rounded to the order currency's decimals.
WHAT PAYMENT IS CREATED: provider invoice for exactly that total.
WHAT THE PROVIDER RECEIVES: same amount + order currency.
```

- Currency is the order's saved catalog-currency snapshot on both flows;
  display preferences never affect the charge.
- Retry of a pending order refreshes the snapshot to the current catalog
  currency on both flows.
- Fast passes the raw stored total (normal re-rounds first) — same value
  in practice; no separate precision logic.
- Zero-total difference: normal completes free orders internally; fast
  does not (see §3.5).

## 7. Callback

`CURRENT` — fast payments use the **normal** success return
(`GET|POST /api/v1/general/checkout/callback?paymentId=…`). Lookup is by
provider reference on the payment record, which carries no
shipping-method condition, so fast payments locate, verify
(amount/currency/reference), complete, or fail exactly like normal ones.
Web vs app response follows the stored checkout `type`. Unknown
references fail safe; repeats are idempotent. **Yes — a fast payment
completes through the normal callback.**

## 8. Error Callback

`CURRENT` — same shared endpoint
(`…/checkout/error-callback`) with the same guarantees: it re-verifies
with the provider, still completes a proven-paid payment, otherwise marks
failed. Fail-closed checks are identical to the success return.

## 9. Webhooks

`CURRENT`:

- **Stripe** (`POST …/webhooks/stripe`): processes fast payments —
  identification is by provider reference only, verification and
  completion identical, idempotent. `CURRENT`.
- **PayPal** (`POST …/webhooks/paypal`): same — fast payments complete,
  denials fail, external refunds ledgered, idempotent. `CURRENT`.
- **MyFatoorah**: no webhook exists at all (return pages only). `NOT
  FOUND` (by design, for all orders).

## 10. Verification

`CURRENT` — no customer endpoint (both flows). Automatic inside return
pages/webhooks: provider confirmation + exact amount/currency/reference
match → complete; else fail, order stays pending.

## 11. COD

`CURRENT` — fast checkout accepts COD (delivery). It records a pending
COD payment exactly like normal. The staff confirm endpoint
(`POST …/checkout/cod/{orderId}/mark-paid`) filters by COD + pending
only, so **it works for fast orders unchanged**. Expiry for COD follows
the 7-day COD window regardless of shipping speed.

## 12. Cashier

`CURRENT` — fast checkout accepts `pay_at_cashier` (any fulfillment,
unlike normal). Same pending cashier payment; the staff confirm endpoint
(`POST …/checkout/cashier/{orderId}/mark-paid`) works for fast orders
unchanged (no shipping filter).

## 13. Refund

`CURRENT` — fast paid orders refund through the same admin endpoint
(`POST /api/v1/admin/payments/{order}/refund`): lookup is order +
provider reference, provider differences (Stripe success-only,
PayPal COMPLETED-only, MyFatoorah confirmed-only) apply equally.

## 14. Retry

`CURRENT`:

```text
WHAT HAPPENS IF CUSTOMER RETRIES:
The system finds THE SAME pending order both checkouts share
(no shipping filter) and updates it — including switching its shipping
method between FAST and SCHEDULED — re-syncs items, re-syncs the pending
payment amount (fast path), takes a fresh reservation, and creates a NEW
provider payment. Old provider references stay on old (still pending)
payment rows; they can never complete twice (single-completion guards).
```

- Changing gateway/method/cart/area at retry is allowed; the order is
  recalculated.
- Changing currency happens only via the catalog snapshot refresh, never
  via frontend input.
- Fast retry does not release the previous coupon/inventory hold first
  (normal does for coupons) — observed code difference, downstream impact
  `NOT PROVEN`.

## 15. Expiration

`CURRENT` — one expiration job for all orders: pending + reservation
expired → provider re-check (online only; COD/cashier skipped as internal
states) → release reservation + coupon hold → order `cancelled`
(payment `payment-failed`), pending payments failed, cancellation events.
Timeouts depend on payment method only (online 24h, COD 7 days) —
**fast orders expire exactly like normal ones**.

## 16. Public Checkout Options

### What exists today `CURRENT`

| Source | Access | Provides |
|---|---|---|
| `GET /api/v1/general/fast-shipping/status` | Public | Fast on/off, orderable-now, fee, ETA, hours |
| `GET /api/v1/general/settings` | Public | Full settings blob: catalog/base currency codes, currency-selection flag, fast-shipping block, gateway on/off overrides (no availability computation) |
| `GET /api/v1/general/currencies` + `POST …/currencies/select` | Public | Display-currency list/choice (display only, never the charge) |
| `GET /api/v1/general/checkout/promotions` | Logged in | Eligible promotions preview |
| `GET /api/v1/admin/payment-gateways` | Admin only | True availability data (enabled/configured/currencies) |

### What the frontend must hard-code today

Gateway codes, payment methods, fulfillment types, the COD≠pickup and
cashier rules, provider currency support, and fast-shipping gates beyond
the status endpoint.

### Proposed contract `PROPOSED — NOT IMPLEMENTED`

```text
GET /api/v1/general/checkout/options
```

Public (no login needed for the generic matrix; logged-in + cart context
refines eligibility). Suggested shape:

```json
{
  "status": 200, "message": "...", "success": true,
  "data": {
    "catalog_currency": "KWD",
    "fulfillment_types": [
      { "code": "delivery", "display_name": "Delivery", "sort_order": 0 },
      { "code": "pickup", "display_name": "Pickup", "sort_order": 1 }
    ],
    "shipping_modes": [
      { "code": "scheduled", "display_name": "Standard",
        "available": true, "unavailable_reasons": [] },
      { "code": "fast", "display_name": "Fast shipping",
        "available": true, "fee": 2.5, "eta_minutes": 120,
        "unavailable_reasons": [] }
    ],
    "payment_methods": [
      { "code": "online", "display_name": "Online payment" },
      { "code": "cod", "display_name": "Cash on delivery" },
      { "code": "pay_at_cashier", "display_name": "Pay at cashier" }
    ],
    "gateways": [
      { "code": "myfatoorah", "display_name": "MyFatoorah",
        "enabled": true, "configured": true,
        "supported_currencies": ["KWD", "SAR", "AED", "BHD", "QAR", "OMR", "EGP"],
        "supports_catalog_currency": true, "sort_order": 0 },
      { "code": "stripe", "display_name": "Stripe",
        "enabled": false, "configured": true,
        "supported_currencies": ["USD", "EUR", "KWD", "SAR", "AED"],
        "supports_catalog_currency": true, "sort_order": 1 },
      { "code": "paypal", "display_name": "PayPal",
        "enabled": true, "configured": true,
        "supported_currencies": ["USD", "EUR"],
        "supports_catalog_currency": false, "sort_order": 2 }
    ],
    "combinations": [
      { "shipping_mode": "scheduled", "fulfillment_type": "delivery",
        "payment_method": "online", "gateway": "myfatoorah",
        "available": true, "unavailable_reasons": [] },
      { "shipping_mode": "scheduled", "fulfillment_type": "delivery",
        "payment_method": "online", "gateway": "paypal",
        "available": false, "unavailable_reasons": ["unsupported_currency"] },
      { "shipping_mode": "scheduled", "fulfillment_type": "pickup",
        "payment_method": "cod", "gateway": null,
        "available": false, "unavailable_reasons": ["unsupported_fulfillment"] },
      { "shipping_mode": "fast", "fulfillment_type": "delivery",
        "payment_method": "pay_at_cashier", "gateway": null,
        "available": true, "unavailable_reasons": [] }
    ]
  }
}
```

Reason codes reuse existing system vocabulary (`disabled`,
`not_configured`, `unsupported_currency`, plus fulfillment/fast/cart
reasons):

```text
disabled · not_configured · unsupported_currency ·
unsupported_payment_method · unsupported_fulfillment ·
fast_shipping_disabled · fast_shipping_off_hours ·
governorate_not_supported · items_ineligible · cart_empty ·
gateway_unknown · login_required
```

## 17. Frontend Flow

```text
Open checkout
    ↓
Read fast-shipping status (+ settings/currencies for display)
    ↓
Show standard vs fast shipping (hide fast when unavailable + why)
    ↓
Customer selects shipping; show valid fulfillment types
    ↓
Show valid payment methods (COD≠pickup; cashier rules per §4)
    ↓
If online → show gateways that are enabled + configured +
support the catalog currency
    ↓
Customer submits → POST normal OR fast-shipping checkout
    ↓
If payment URL → redirect to provider → provider returns
    ↓
System verifies → success/failure page (web) or JSON (app)
    ↓
If failed/expired → resubmit checkout (same pending order reused)
```

## 18. Error Scenarios

Covered in §3.6 (fast) and the base contract §10 (shared). Fast adds:
no-fast-items (422), gate rejections (422), always-required address/area
(422), and the zero-total-via-provider outcome (500/provider-dependent).
No fast-only callback/webhook/expiry/duplicate errors exist.

## 19. Endpoint Matrix

| Type | Method | Endpoint | Caller | Auth | Purpose | Fast Shipping Supported? |
| ---- | ------ | -------- | ------ | ---- | ------- | ------------------------ |
| Customer | POST | `/api/v1/general/checkout` | Frontend | Login | Normal checkout + payment | N/A (the normal flow) |
| Customer | POST | `/api/v1/general/fast-shipping/checkout` | Frontend | Login | Fast checkout + payment | **Yes (this flow)** |
| Customer | GET | `/api/v1/general/fast-shipping/status` | Frontend | Public | Fast availability/fee/ETA | Yes |
| Provider | GET/POST | `/api/v1/general/checkout/callback` | Provider | Public | Return after payment | **Yes, shared** |
| Provider | GET/POST | `/api/v1/general/checkout/error-callback` | Provider | Public | Return after cancel/fail | **Yes, shared** |
| Provider | POST | `/api/v1/general/checkout/webhooks/stripe` | Stripe | Signature | Server events | **Yes, shared** |
| Provider | POST | `/api/v1/general/checkout/webhooks/paypal` | PayPal | Signature | Server events | **Yes, shared** |
| — | — | MyFatoorah webhook | — | — | — | NOT FOUND (by design) |
| Staff | POST | `/api/v1/general/checkout/cod/{orderId}/mark-paid` | Staff | Permission | Confirm COD cash | **Yes (no shipping filter)** |
| Staff | POST | `/api/v1/general/checkout/cashier/{orderId}/mark-paid` | Staff | Permission | Confirm cashier cash | **Yes (no shipping filter)** |
| Admin | POST | `/api/v1/admin/payments/{order}/refund` | Admin | Permission | Gateway refund | **Yes (order-based)** |
| Customer | GET | `/api/v1/general/orders`, `…/orders/{id}` | Frontend | Login | Follow-up | Yes (both kinds) |
| Admin | GET | `/api/v1/admin/payment-gateways` | Admin | Permission | Gateway status | Shared |
| Admin | PUT | `/api/v1/admin/payment-gateways/{code}` | Admin | Permission | Gateway update | Shared |
| Admin | GET/PUT | `fast-shipping/settings` | Admin | Login | Fast settings | Fast only |
| — | GET | `/api/v1/general/checkout/options` | Frontend | — | Availability matrix | **PROPOSED — NOT IMPLEMENTED** |

## 20. Request/Response Matrix

`CURRENT`: normal checkout (base contract); fast checkout (§3.2 request →
§3.5 success / §3.6 errors; result: FAST order + provider payment or
pending manual payment); shared callback/webhook/admin rows (base
contract, apply to fast identically). `PROPOSED`: options endpoint (§16;
result: frontend knows valid combinations before checkout).

## 21. Normal vs Fast Shipping Comparison

| Concern | Normal Checkout | Fast Shipping |
|---|---|---|
| Endpoint | `POST …/checkout` | `POST …/fast-shipping/checkout` |
| Request | Address/area conditional; `type` accepted; cashier→pickup enforced; min-order checked | Address/area **always** required; **no `type` rule** (still honored); cashier+delivery allowed; no min-order check |
| Order creation | SCHEDULED slice; new or reuse shared pending order | FAST slice only; fee + ETA; `shipping_method=FAST`; same shared pool (flows can take over each other's pending order) |
| Shipping | Area price, thresholds, free-shipping coupon | Same + fast fee; same area price source |
| Inventory | Reserve per order slice | Same mechanism |
| Payment methods | online/cod/cashier | Same three |
| Gateways | myfatoorah/stripe/paypal via shared handler | **Same handler, same gateways** |
| Amount source | Order total (re-rounded) | Order total (raw stored value) |
| Currency source | Order catalog snapshot | Same |
| Zero total | Completed internally, no provider | **Sent to provider flow (fails)** |
| Callback | Shared | **Same shared** |
| Error callback | Shared, fail-closed | **Same shared** |
| Stripe webhook | Yes | **Same, applies** |
| PayPal webhook | Yes | **Same, applies** |
| MyFatoorah webhook | None | None |
| COD | Delivery; staff mark-paid | Same; same mark-paid works |
| Cashier | Pickup only; staff mark-paid | Delivery allowed too; same mark-paid works |
| Refund | Admin endpoint | **Same endpoint works** |
| Retry | Reuse pending order | Same pool; also re-syncs payment amount |
| Expiration | Reservation-based (24h online / 7d COD) | **Identical (method-based, not speed-based)** |
| Mobile behavior | `type=mobile` → JSON | Same (via unlisted but honored `type`) |

## 22. Payment State Machine

`CURRENT` (both flows, payment record): `pending` (created with provider
reference) → `paid` (verified + matched + completed; single-completion
guards make repeats no-ops) **or** `failed` (unconfirmed / mismatched /
provider failure / expiry). `paid → refunded` via admin refund flows
(order `payment-refunded`). No shipping-specific states exist.

## 23. Order State Machine

`CURRENT` (both flows): `pending` → `completed` (payment success path) |
`cancelled` (expiry; `payment-failed`) | `processing` / `delivered`
(post-payment fulfillment). Payment sub-states: `payment-pending` →
`payment-success` | `payment-failed` | `payment-refunded`. Inventory:
`active` → `committed` (paid) | `released` (retry/expiry). Fulfillment
track runs independently (`pending → processing → out_for_delivery /
ready_for_pickup → delivered`). Fast vs scheduled changes none of these —
only the `shipping_method` label (`FAST`/`SCHEDULED`).

## 24. Security Rules

`CURRENT` (both flows): login for checkout; public return pages throttled
(20/min/IP) and never trusted without provider re-verification; webhooks
signature-verified with terminal safe-acks; backend-only amount/currency
(tampering impossible — no such input); gateway switching by the customer
only re-runs the same availability gate; unknown references fail safe;
replays idempotent. Fast inherits everything (same code paths).

## 25. Current Limitations

1. No public options endpoint — frontend hard-codes combinations.
2. Fast zero-total orders hit the provider instead of completing.
3. Fast `type` works but is undocumented/validated nowhere.
4. Fast order record can store an empty provider label.
5. Fast retry skips the coupon/inventory release steps normal retry does.
6. Unrouted `products`/`orders` controller methods (dead surface).
7. Shared pending-order pool lets the flows silently convert each other's
   orders (by design of the lookup, but invisible to the customer).

## 26. Recommended API Contract Changes

1. Add `GET …/checkout/options` per §16 (`PROPOSED`).
2. Mirror the free-order shortcut + total rounding into fast checkout.
3. Add the `type` rule to the fast request (document mobile support).
4. Copy the provider label into fast orders (`gateway` → `payment_gateway`
   when online).
5. Mirror normal retry's release steps (or prove them unneeded).
6. Route or remove the dead fast `products`/`orders` methods.
7. Surface a "converted from standard/fast" note when retry switches a
   pending order's shipping method.

---

## Implementation Gap List

| Gap | Current Behavior | Desired Behavior | Why It Matters | Affected Endpoint / Layer | Risk | Recommended Next Step |
|---|---|---|---|---|---|---|
| Public options endpoint | Frontend hard-codes all combinations | `GET …/checkout/options` (§16) | Stale UI, failed checkouts on disable/currency change | New read endpoint | Low (read-only) | Design review, then implement read-only aggregation |
| Fast zero-total | Provider flow, fails | Internal completion like normal | Free fast orders unpayable | Fast checkout | Medium | Port D-05 guard |
| Undocumented `type` on fast | Works, unvalidated | Validated rule | App flows rely on luck | Fast request | Low | Add `in:web,mobile` rule |
| Empty provider label (fast) | `payment_gateway` often null | Copy `gateway` when online | Records/audits inconsistent | Fast service | Low | One-line mapping |
| Retry release parity | Skipped on fast reuse | Same as normal or proven unneeded | Coupon/reservation edge cases | Fast service | Medium | Audit + test, then align |
| Dead methods | `products`, `orders` unrouted | Routed or deleted | Confusion, false API surface | Fast controller/routes | Low | Decide + clean up |
| Silent flow conversion | Shared pool converts order type | Visible notice | Customer surprise | Both checkouts | Low | Return a `converted` flag |

---

## Final Findings

### Confirmed Current Behavior

- Fast shipping = separate availability/pricing wrapper around the **same**
  payment system: same handler, gateways, callbacks, webhooks,
  verification, completion, refunds, retries, expiry.
- All three gateways + COD + cashier work for fast orders; callbacks,
  webhooks, mark-paid, refund, and expiration apply without shipping
  filters.
- Amount/currency fully backend-derived on both flows; mismatch rejects.

### Missing Capabilities

- Public checkout-options endpoint; free-order handling on fast checkout;
  validated `type` on fast checkout; provider-label copy on fast orders.

### Proposed Public Options Contract

- §16: `GET /api/v1/general/checkout/options` with fulfillment types,
  shipping modes, methods, gateways + availability, combinations with
  machine-readable reason codes. **Not implemented.**

### Risks / Inconsistencies

- Zero-total fast orders fail at the provider instead of completing.
- Cashier+delivery allowed on fast but impossible on normal.
- Shared pending pool converts order types silently across flows.
- Retry release steps differ between flows (impact unproven).

### Final Verification Result

```text
PASS 1 — Route Verification: all fast + shared + admin routes confirmed
from routes/api.php and package routes; no options route exists; two
fast controller methods unrouted. DONE.
PASS 2 — Execution Verification: online/cod/cashier traced
route → controller → request → service → payment → provider → response
for fast checkout; identical payment tail as normal. DONE.
PASS 3 — State Verification: order/payment/callback/webhook/refund/
expiry traced with no shipping-method contradictions; timeouts are
method-based. DONE.

No contradictions between conclusions. No production code changed.
```
