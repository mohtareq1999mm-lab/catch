# Meem Commerce — Guest Checkout Frontend Integration Contract

Status: **BACKEND IMPLEMENTED AND TESTED** (see `docs/audits/guest-checkout-implementation-report.md`)
Backend base: `/api/v1/general/guest`
Frontend repository: **not present in this workspace** — this is a standalone contract.

> Evidence labels used here: IMPLEMENTED, VERIFIED, TESTED, UNKNOWN, RISK.

---

## 1. Non-negotiable Frontend rules

1. Send **credentialed** requests on every guest call:
   ```js
   axios.defaults.withCredentials = true;
   // or per request: axios.get(url, { withCredentials: true })
   ```
2. Never read, copy, persist, or forward the `meem_guest` cookie. It is `HttpOnly` and the browser owns it.
3. Never send prices, totals, discounts, tax, shipping cost, or stock availability. The Backend recalculates all authoritative values.
4. On state-changing calls send `X-CSRF-Token` with `data.csrf_token` from `POST /guest/session`
   (same-origin clients may alternatively read the readable `meem_guest_csrf` cookie).
5. On checkout / payment initiation send a unique `Idempotency-Key` header per logical operation (UUID v4 recommended).
6. Never treat a provider redirect as proof of payment. Poll `GET /orders/{orderId}` or the public tracking endpoint.

---

## 2. Cookie contract

| Cookie | HttpOnly | Readable by JS | Purpose |
|---|---|---|---|
| `meem_guest` | yes | **no** | Server-managed guest credential (raw token never in JSON/DB; DB stores a keyed hash) |
| `meem_guest_csrf` | no | yes | Double-submit CSRF token |

Cookie attributes (defaults, configurable by Backend env):

- `Path=/api`
- `SameSite=Lax` (same-site Frontend/Backend subdomains)
- `Secure=true` whenever the request is HTTPS
- host-only (no `Domain`) unless `GUEST_COOKIE_DOMAIN` is explicitly configured
- expiry: 7 days from issue; **refreshed only on genuine cart mutations**, never on reads

### Separate domains

- **Same registrable domain** (`shop.example.com` → `api.example.com`): supported with
  `SameSite=Lax`, exact CORS origin, `CORS_SUPPORTS_CREDENTIALS=true`.
- **Different registrable domains** (`shop-example.com` → `meem-api.net`): browsers increasingly
  block third-party cookies. The recommended deployment is a **same-site BFF / reverse proxy**
  (e.g. `shop-example.com/api/*` proxied to the API) so cookies stay first-party.
  Do not fall back to `localStorage`. See §12.

---

## 3. Session lifecycle
### Initialize (first visit)

`POST /api/v1/general/guest/session`

No body, no CSRF requirement. Creates the identity and sets both cookies. Calling it again with a
valid cookie is idempotent (returns the current expiry).

Response `200`:

```json
{
  "status": 200,
  "message": "Guest session is ready.",
  "success": true,
  "data": {
    "guest": { "expires_at": "2026-11-18T10:00:00+00:00" },
    "csrf_token": "<csrf value; send as X-CSRF-Token>",
    "cookies": {
      "session_cookie": "meem_guest",
      "csrf_cookie": "meem_guest_csrf",
      "csrf_header": "X-CSRF-Token",
      "path": "/api",
      "domain": null,
      "same_site": "lax",
      "secure": true,
      "http_only": true,
      "ttl_days": 7
    }
  }
}
```

The raw credential is **not** present in the JSON (TESTED). `data.csrf_token` **is** returned and
must be kept in memory and sent as `X-CSRF-Token`; this is required when the Frontend runs on a
different host from the API (a host-only `meem_guest_csrf` cookie is not readable via
`document.cookie` on the Frontend origin). Same-origin clients may alternatively read the cookie.

### Revoke (optional)

`DELETE /api/v1/general/guest/session` + `X-CSRF-Token`

Revokes the token and clears cookies. The cart is left for retention/pruning; it is no longer
reachable from that browser.

**Revocation is the kill-switch.** After this call, the OLD `meem_guest` cookie value is dead
server-side (`guest_identities.revoked_at`). Any request that replays the old cookie on a WRITE
endpoint returns `401 session_required`; no new session/cart may be minted from it. Reads return
an empty cart. `POST /guest/session` rotates to a brand-new identity (the old one stays revoked).

### Writes require an existing session

Every write endpoint (cart add/update/remove/clear, checkout, payment, access-tokens) requires a
**valid existing** guest session:

- missing/expired/revoked credential → `401` (`session_required`);
- the backend NEVER auto-creates a session from a write endpoint;
- sessions are created ONLY by `POST /guest/session`.

CSRF therefore cannot be bypassed by "having no session": the no-session case is answered with
`401` before any state change. `419` is returned only when a session exists but the
`X-CSRF-Token` is missing/mismatched.

---

## 4. Cart

All cart writes require `X-CSRF-Token`. Cart reads do **not** extend the 7-day retention.

### Read

`GET /api/v1/general/guest/cart`

```json
{ "status": 200, "message": "...", "success": true, "data": { "cart": null } }
```

or `data.cart` = `CartResource` (same shape as the authenticated cart).

### Add item

`POST /api/v1/general/guest/cart/items` → `201`

```json
{
  "item": {
    "product_id": 123,
    "quantity": 2,
    "product_variant_id": null,
    "shipping_method": "scheduled"
  }
}
```

Rules (server-side):
- physical products only (digital → `422`);
- product must be active/available and channel-eligible;
- variable products require `product_variant_id`;
- quantity is bounded by `cart.max_item_quantity` (default 100) and current availability when known;
- `price`/`total_price` sent by the client are ignored (TESTED).

### Update quantity

`PUT /api/v1/general/guest/cart/items`

```json
{
  "item": {
    "product_id": 123,
    "quantity": 1,
    "shipping_method": "scheduled",
    "operation": "decrement"
  }
}
```

`operation` is `increment` or `decrement` (mirrors the authenticated cart contract).

### Remove one line

`DELETE /api/v1/general/guest/cart/items/{itemId}` → `200`, returns the updated cart.
Foreign/unknown line ids return `404` (no existence oracle).

### Clear

`DELETE /api/v1/general/guest/cart` → `200`.

If no guest session exists, writes return `401` (`session_required`); reads return an empty cart.

---

## 5. Checkout (create the order)

`POST /api/v1/general/guest/checkout` → `201`

Headers:

```
X-CSRF-Token: <meem_guest_csrf value>
Idempotency-Key: <uuid>          # strongly recommended; required for reliable retry
Content-Type: application/json
```

Body: the existing `OrderCreateRequest` contract, e.g.

```json
{
  "name": "Guest Buyer",
  "user_phone": "+201000000001",
  "user_email": "guest.buyer@example.test",
  "address": { "street": "1 Guest Street", "city": "Cairo" },
  "governorate_id": 1,
  "fulfillment_type": "delivery",
  "payment_method": "online",
  "gateway": "stripe",
  "shipping_type": "local",
  "notes": "optional"
}
```

- `payment_method` is limited to `online` or `cod` for guests. `pay_at_cashier` is rejected (`422`).
- `payment_method` is **bound at creation** (COD commits inventory at creation). The payment step
  must use the same method.
- No account is created; `orders.user_id` is `NULL` and the verified guest identity is linked.

Response `201`:

```json
{
  "status": 201,
  "message": "Checkout successful",
  "success": true,
  "data": {
    "order": { "id": 9001, "order_number": "ORD-00009001", "status": "pending", "...": "OrderResource" },
    "tracking_token": "raw-token-returned-once",
    "cancellation_token": "raw-token-returned-once",
    "payment": { "method": "online", "gateway": "stripe", "next": "initiate_payment" },
    "idempotent_replay": false
  }
}
```

- `tracking_token` / `cancellation_token` are returned **only on first creation**.
- Store them client-side only for the confirmation UX; the Backend stores hashes only.
- On an idempotent replay (`idempotent_replay: true`) both tokens are `null`; call
  `POST /orders/{orderId}/access-tokens` to re-issue them for the owning browser.
- A repeated checkout for the same guest reuses the existing pending order (one pending order per
  guest, DB-enforced).

### Confirmation after refresh

`GET /api/v1/general/guest/orders/{orderId}` (cookie-owned only) → `OrderResource` + `can_cancel`.

### Re-issue public credentials (owning browser only)

`POST /api/v1/general/guest/orders/{orderId}/access-tokens` + CSRF →

```json
{ "tracking_token": "...", "tracking_revoked": false, "cancellation_token": "...", "tracking_expires_at": null }
```

Admin-revoked tracking is never resurrected (`tracking_token: null`, `tracking_revoked: true`).

---

## 5.1 Login response — guest cart merge

Guest cart merge runs **after successful authentication** in the password
(`POST /api/v1/token`), social login, and OTP login flows. Registration alone
does not authenticate and therefore does not merge. Merge failure never fails
login.

Successful login may include:

```json
{
  "data": {
    "token": "...",
    "guest_cart_merge": {
      "status": "merged",
      "added_items": 1,
      "merged_items": 2,
      "guest_cart_removed": true,
      "adjusted_items": [
        { "product_id": 10, "quantity": 2, "applied_quantity": 1, "dropped_quantity": 1, "reason": "quantity_capped", "message": "…" }
      ],
      "unavailable_items": [
        { "product_id": 11, "quantity": 1, "reason": "product_unavailable", "message": "…" }
      ],
      "cart": { "id": 5, "normal_items": [], "fast_items": [], "…": "CartResource" }
    }
  }
}
```

- `status`: `merged` | `failed` | absent (no guest cart).
- Unavailable items are **preserved** in the authenticated cart and reported.
  The cart item resource also exposes `is_available` / `unavailable_reason`.
- Checkout rejects unavailable lines until the customer removes/replaces them.
- `adjusted_items` explains quantity caps (max per-line and stock).

---

## 6. Payment

`POST /api/v1/general/guest/orders/{orderId}/payment` + CSRF (+ `Idempotency-Key` recommended)

### Online

```json
{ "payment_method": "online", "gateway": "stripe" }
```

Response `200`:

```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": {
    "url": "https://provider.example/checkout/…",
    "order_id": 9001,
    "payment_method": "online",
    "gateway": "stripe"
  }
}
```

Redirect the browser to `data.url`. Completion is authoritative only via provider webhook or the
reconciliation worker. A return redirect is UX-only and never completes the order (TESTED).

If an earlier attempt is still unresolved (may still collect money), the endpoint returns `409`
(`payment_in_progress`); do not create a second session. Retry the same request with the same
`Idempotency-Key` after reconciliation.

Zero-total orders complete locally without a provider session (`data.zero_value = true`).

### COD

```json
{ "payment_method": "cod" }
```

Response `200` with `{ order_id, payment_method: "cod" }`. Repeating is idempotent; no second
transaction is created and the order is **never** marked paid (TESTED).

---

## 7. Public tracking (no cookie required)

`POST /api/v1/general/guest/orders/track`

```json
{ "token": "<tracking_token>" }
```

Tokens are sent in the **POST body**, never in the URL (avoids access-log leakage).

Response `200` (intentionally minimal):

```json
{
  "data": {
    "order": {
      "order_number": "ORD-00009001",
      "status": "processing",
      "payment_status": "payment-pending",
      "fulfillment_status": "pending",
      "created_at": "…",
      "last_updated_at": "…"
    },
    "timeline": [
      { "timestamp": "…", "title": "Order created", "description": "…" }
    ],
    "tracking_expires_at": null
  }
}
```

Never returned: customer name, phone, email, address, items, totals, gateway data, other orders.

Validity: for the whole order lifecycle plus 30 days after terminal status (delivered/cancelled).
Invalid, expired, revoked, and foreign tokens are indistinguishable (`404`).

---

## 8. Guest cancellation (no cookie required)

`POST /api/v1/general/guest/orders/cancel`

```json
{ "token": "<cancellation_token>" }
```

- single-use: reuse returns `422` (`cancel_link_used`);
- expired/invalid returns `404`/`422`;
- existing cancellation policy applies (pending/processing and unpaid);
- an unresolved payment attempt blocks cancellation (`409`);
- cancellation runs through the canonical status writer (inventory/coupon release included).

Response `200`: `{ data: { order: { order_number, status: "cancelled" } } }`.

---

## 9. Error contract

```json
{ "status": 422, "message": "Human readable", "success": false }
```

| Status | Meaning |
|---|---|
| `400` | cart empty / not found at checkout |
| `401` | guest session required |
| `403` | Origin not allowed |
| `404` | order/item/token not found (anti-enumeration) |
| `409` | checkout/payment already in progress, order not payable, unresolved payment |
| `419` | CSRF token missing/mismatch |
| `422` | validation or business rule rejection |
| `429` | rate limited; honour `Retry-After` |
| `500` | safe generic failure (no internals) |

---

## 10. Rate limits (defaults, per minute)

| Surface | Limiter | Default |
|---|---|---|
| Session init/revoke | `guest_session` | 10 / IP |
| Cart reads | `guest_cart_read` | 120 / guest-or-IP |
| Cart writes | `guest_cart_write` | 40 / guest-or-IP |
| Checkout | `guest_checkout` | 6 / guest-or-IP |
| Payment initiation | `guest_payment` | 6 / guest-or-IP |
| Public tracking | `guest_tracking` | 20 / IP |
| Cancellation | `guest_cancel` | 5 / IP |

Protected public guest endpoints **fail closed** (429) if the limiter backend is unavailable.
Multi-server deployments must use a shared cache store (Redis) for limits to be global.

---

## 11. Frontend flow (recommended)

```
1. POST /guest/session
2. GET  /guest/cart (render)
3. POST /guest/cart/items (repeat)
4. GET  /guest/cart (refresh-safe restore)
5. POST /guest/checkout            [Idempotency-Key]
6. POST /guest/orders/{id}/payment [Idempotency-Key]
7. redirect to data.url (online) or show COD confirmation
8. confirmation page: GET /guest/orders/{id}; keep tracking/cancellation tokens
9. tracking page: POST /guest/orders/track {token}
10. cancel action: POST /guest/orders/cancel {token}
```

Axios example:

```js
const api = axios.create({
  baseURL: 'https://api.example.com/api/v1/general',
  withCredentials: true,
});

// Returned by POST /guest/session (keep in memory only).
let csrfToken = null;

await api.post('/guest/session').then(({ data }) => {
  csrfToken = data.data.csrf_token;
});

await api.post('/guest/checkout', payload, {
  headers: { 'X-CSRF-Token': csrfToken, 'Idempotency-Key': crypto.randomUUID() },
});
```

---

## 12. Deployment prerequisites (Backend)

1. `CORS_ALLOWED_ORIGINS=https://catch.mohammedtareq.me` (EXACT Frontend origin; never `*`)
2. `CORS_SUPPORTS_CREDENTIALS=true`
3. `GUEST_COOKIE_SAMESITE=lax` (same-site subdomains) or `none` **only** with HTTPS and a BFF/proxy
4. `GUEST_COOKIE_DOMAIN` unset (host-only) by default
5. HTTPS end-to-end (cookies become `Secure`)
6. Shared Redis cache for rate limiting/reconciliation in multi-server setups
7. Scheduler running: `guest:prune-carts`, `payments:reconcile-attempts`, `orders:cancel-unpaid`
8. `php artisan migrate` (additive guest schema + nullable invoice user)
9. Guest routes additionally enforce: SAME-ORIGIN requests always pass
   (reverse-proxy rewrites); CROSS-ORIGIN requests must match the exact
   allowed origin; wildcard `*` never authorizes a guest route and any
   wildcard/foreign CORS header is stripped from guest responses (fail closed).

---

## 13. Known business decisions still open (not invented)

| Topic | Status |
|---|---|
| Guest cart merge on login | **IMPLEMENTED** — password/social/OTP logins merge the guest cart (`data.guest_cart_merge`). Registration does not authenticate, so it does not merge. |
| Different registrable domains | **RISK** — prefer BFF/reverse proxy; third-party cookies are unreliable. Guest routes enforce exact-origin CORS and never emit `*`. |
| Guest coupon application | **NOT IMPLEMENTED** (no approved guest coupon policy). The public product/coupon endpoints remain as-is. |
| POS guest checkout | **DISABLED** by design. |
| Digital products | **REJECTED** by design. |
