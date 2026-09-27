# Payment API Business Contract

> This document describes **what the payment system offers, what to send,
> what is returned, and what happens next** — in plain business language.
> Every endpoint, field, message, and rule below was taken from the actual
> current system. Anything not found is marked as not found. No production
> code was changed.

---

## 1. Overview

- The shop has **one** customer checkout endpoint. It creates the order
  **and** starts the online payment in a single call.
- Three online payment providers are registered: **MyFatoorah** (the
  default), **Stripe**, and **PayPal**. Cash on delivery and cashier
  payment are handled as variations of the same checkout call.
- The customer **never sends the amount or the currency**. The system takes
  both from the order.
- After checkout, the customer is redirected to the provider's payment
  page. The system verifies the payment itself (customer return page +
  provider notifications). **There is no "verify my payment" button or
  endpoint for the customer.**
- The admin can **enable/disable** gateways and change their display name
  and order, but **cannot create a new gateway** through the API.
- There is currently **no public endpoint** that lists which gateways are
  available to the customer. A proposed client-safe version of the admin
  gateway list (same data flow, plus fast-shipping availability) is
  specified in Section 14 — **PROPOSED, not implemented**.

---

## 2. Customer Payment Endpoints

### Endpoint 1 — Checkout (create order + start payment)

- Method: **POST**
- URL: **`/api/v1/general/checkout`**
- Who uses it: **Frontend / customer (logged in)**
- Login required: **Yes** (customer account token)

#### Business Purpose

> This endpoint is used to place the order and start the payment.
> The customer submits their details once, and the system creates the
> order and prepares the payment with the selected provider.

#### Request

```http
POST /api/v1/general/checkout
Content-Type: application/json
Accept: application/json
Authorization: Bearer {customer-token}
```

#### Request Fields

| Field | Required? | Example | What does it mean? |
| ----- | --------- | ------- | ------------------ |
| `name` | Yes | `"Sara"` | Customer name for the order |
| `user_phone` | Yes | `"96591234567"` | Customer phone number |
| `user_email` | No | `"s@example.com"` | Customer email (also used for payment notifications) |
| `fulfillment_type` | No (default `delivery`) | `"delivery"` or `"pickup"` | How the customer receives the order |
| `governorate_id` | Yes, for home delivery | `3` | Delivery area (used to calculate shipping) |
| `pickup_location_id` | Yes, for pickup | `7` | Which branch the customer picks up from |
| `address` | Yes, for home delivery | `{...}` | Delivery address details |
| `payment_method` | No (default `online`) | `"online"`, `"cod"`, `"pay_at_cashier"` | How the customer wants to pay |
| `gateway` | No (default `myfatoorah`) | `"stripe"`, `"paypal"`, `"myfatoorah"` | Which online provider to pay with (only matters when `payment_method` is `online`) |
| `type` | No | `"web"` or `"mobile"` | `"mobile"` tells the system the buyer uses the app, so return pages come back as app-readable responses instead of web redirects |
| `notes` | No | `"Call before arrival"` | Optional order note |
| `selected_promotion_id` | No | `12` | Promotion the customer chose |
| `selected_gift_product_id` | No | `44` | Gift product the customer chose |

#### Request Variations

##### Scenario A — Pay online with MyFatoorah (default)

```json
{
  "name": "Sara",
  "user_phone": "96591234567",
  "fulfillment_type": "delivery",
  "governorate_id": 3,
  "address": { "area": "...", "block": "1", "street": "2" },
  "payment_method": "online",
  "gateway": "myfatoorah"
}
```

(`"gateway"` may be omitted — MyFatoorah is the default.)

##### Scenario B — Pay online with Stripe

```json
{
  "name": "Sara",
  "user_phone": "96591234567",
  "fulfillment_type": "delivery",
  "governorate_id": 3,
  "address": { "area": "...", "block": "1", "street": "2" },
  "payment_method": "online",
  "gateway": "stripe"
}
```

##### Scenario C — Pay online with PayPal

Same as Scenario B with `"gateway": "paypal"`.

##### Scenario D — Cash on delivery

```json
{
  "name": "Sara",
  "user_phone": "96591234567",
  "fulfillment_type": "delivery",
  "governorate_id": 3,
  "address": { "area": "...", "block": "1", "street": "2" },
  "payment_method": "cod"
}
```

Cash on delivery cannot be combined with pickup — that combination is
rejected.

##### Scenario E — Pay at cashier (pickup only)

```json
{
  "name": "Sara",
  "user_phone": "96591234567",
  "fulfillment_type": "pickup",
  "pickup_location_id": 7,
  "payment_method": "pay_at_cashier"
}
```

##### Scenario F — Mobile app buyer

Same as any scenario above, plus `"type": "mobile"`. Everything else is
identical; only the format of the return message after payment changes
(app-readable response instead of a web redirect).

#### IMPORTANT REQUEST RULES

**Fields the frontend MUST send:** `name`, `user_phone`, plus delivery
details (`governorate_id` + `address`) for home delivery, or
`pickup_location_id` for pickup.

**Fields the frontend MAY send:** `user_email`, `notes`,
`payment_method`, `gateway`, `type`, `fulfillment_type`,
`selected_promotion_id`, `selected_gift_product_id`.

**Fields the frontend MUST NOT send:** `amount`, `total`, `total_price`,
`price`, `payment_amount`, `currency`, `currency_code`. These are not
accepted and are ignored if sent.

```text
Frontend does NOT send the payment amount.
Frontend does NOT choose the payment currency.
Backend determines them from the order.
```

#### SUCCESS RESPONSE — online payment

```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": {
    "url": "https://provider-payment-page/..."
  }
}
```

```text
Business meaning:

The order was created and the payment was prepared with the provider.

The frontend receives a payment page URL.

The frontend should redirect the customer to that URL so they can pay.
```

#### SUCCESS RESPONSE — cash on delivery / pay at cashier / free (zero-value) order

```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": {
    "order_id": 123
  }
}
```

```text
Business meaning:

The order was placed. There is nothing to pay online right now.

- Cash on delivery: the customer pays the courier later.
- Pay at cashier: the customer pays at the branch later.
- Free order (total is zero): the order is completed immediately
  without involving any payment provider.

The frontend should show an "order placed" confirmation, NOT a
payment page. Note there is no "url" in this case.
```

#### ERROR RESPONSES

##### Empty cart

```http
HTTP 400
```

```json
{
  "status": 400,
  "message": "Cart not found",
  "success": false
}
```

```text
Business meaning: the shopping cart is empty or was already used, so no
order can be created.
```

##### Wrong input (validation)

```http
HTTP 422
```

```json
{
  "name": ["The name field is required."],
  "...": ["..."]
}
```

```text
Business meaning: a required field is missing or invalid. Note this error
looks different from other errors (a plain list of field problems).
```

##### Cash on delivery with pickup

```http
HTTP 422
```

```text
Business meaning: cash on delivery is not offered for pickup orders.
```

##### Unknown payment method

```http
HTTP 422
```

```text
Business meaning: payment_method must be one of
online / cod / pay_at_cashier.
```

##### Gateway Disabled / Not Configured

```http
HTTP 422
```

```json
{
  "status": 422,
  "message": "Payment gateway is unavailable",
  "success": false
}
```

```text
Business meaning:

The administrator has disabled this payment gateway, or it is not set
up (missing keys). The payment is not created. The customer cannot
continue using this gateway and should pick another one.
```

##### Unsupported Currency

```http
HTTP 422
```

```json
{
  "status": 422,
  "message": "Payment gateway does not support the selected currency KWD",
  "success": false
}
```

```text
Business meaning:

The order's currency is not supported by the selected gateway. The
payment is not created. The customer should pick another gateway.
```

##### Payment Creation Failure (provider problem)

```http
HTTP 500
```

```json
{
  "status": 500,
  "message": "Error creating invoice",
  "success": false
}
```

```text
Business meaning: the payment provider could not be reached or rejected
the request. No payment was created. The customer can retry.
```

##### Login required

```http
HTTP 401
```

```text
Business meaning: the customer is not logged in. Checkout requires a
customer account.
```

##### Too many attempts

```http
HTTP 429
```

```text
Business meaning: too many requests in a short time. Wait and retry.
```

#### FRONTEND BEHAVIOR (online payment)

```text
1. Customer submits checkout.
2. System validates the request and the cart.
3. System creates the order (or reuses the customer's pending order
   from an earlier unpaid attempt).
4. System takes the payment amount from the order total.
5. System takes the payment currency from the order's saved currency.
6. System checks whether the selected gateway is available.
7. System creates the payment with the provider.
8. System returns the payment page URL.
9. Frontend redirects the customer to that URL.
```

#### Retrying after a failed / expired payment

There is **no separate "continue payment" endpoint**. The customer simply
submits checkout again. The system finds the customer's still-pending
order and updates it with the current cart instead of creating a
duplicate order, then prepares a fresh provider payment.

The customer can also view their orders (`GET /api/v1/general/orders`,
`GET /api/v1/general/orders/{id}`) and order invoice to follow up.

---

### Endpoint 2 — Fast-shipping checkout (create fast order + start payment)

- Method: **POST**
- URL: **`/api/v1/general/fast-shipping/checkout`**
- Who uses it: **Frontend / customer (logged in)**
- Login required: **Yes** (customer account token)

#### Business Purpose

> This endpoint is used exactly like normal checkout (order + payment in
> one call), but for **fast shipping**: only fast-eligible cart items are
> sold, a fast fee is added, and the order carries a promised delivery
> time. It is only usable when fast shipping is currently available
> (see availability check below).

#### Request

```http
POST /api/v1/general/fast-shipping/checkout
Content-Type: application/json
Accept: application/json
Authorization: Bearer {customer-token}
```

Same fields as Endpoint 1, with these proven differences:

| Difference | Normal checkout | Fast-shipping checkout |
| ---------- | --------------- | ---------------------- |
| `address` | Required for home delivery only | **Always required** (even for pickup) |
| `governorate_id` | Required for home delivery only | **Always required** (even for pickup) |
| `type` | Accepted (`web`/`mobile`) | No documented rule, but still honored for the return format |
| `pay_at_cashier` | Pickup only | **Delivery allowed too** |
| Minimum order amount | Enforced | Not enforced |
| `amount` / `currency` | Must not be sent (ignored) | Same — must not be sent (ignored) |

```json
{
  "name": "Sara",
  "user_phone": "96591234567",
  "fulfillment_type": "delivery",
  "governorate_id": 3,
  "address": { "area": "...", "block": "1", "street": "2" },
  "payment_method": "online",
  "gateway": "stripe"
}
```

(All payment variations from Endpoint 1 — MyFatoorah / Stripe / PayPal /
COD / cashier / mobile — work the same way here.)

#### Fast-shipping availability check (public, no login)

```http
GET /api/v1/general/fast-shipping/status
```

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

```text
Business meaning: tells the frontend whether fast shipping is switched
on at all (enabled), whether it can be ordered right now (available:
working hours), what it costs (fee), how fast it arrives
(duration_minutes), and when it opens again if currently closed.
The frontend should check this before offering the fast option.
```

At checkout, the order is additionally rejected with HTTP 422 unless the
area supports fast shipping and every fast cart item is fast-eligible.

#### Success / error responses

Identical shapes to Endpoint 1 (`data.url` for online, `data.order_id`
for COD/cashier, same error codes). Three differences:

1. Empty fast cart → `422` (not `400`).
2. Fast unavailable (switched off / off-hours / area / items) → `422`
   with the reason.
3. Free (zero-total) online order has **no free shortcut** here — it goes
   into the normal provider flow and fails there. Do not expect an
   `order_id`-only success for free fast orders.

#### FRONTEND BEHAVIOR (fast shipping)

```text
1. Check GET .../fast-shipping/status (public).
2. If unavailable, hide the fast option (reason shown if desired).
3-9. Same as normal online payment behavior (Section 2):
   submit → order created/updated → amount/currency from order →
   gateway checked → provider payment → return URL → redirect.
```

Payment afterwards (return pages, webhooks, verification, refunds,
retry, expiry) follows **the same flow** as normal checkout — see
Sections 3–5, 7, 8, 10. Retrying reuses the same shared pending order
(a normal pending order can become a fast one and vice versa).

---

## 3. Payment Return / Callback Endpoints

After the customer pays (or cancels) on the provider's page, the provider
sends the customer back to the system. These endpoints receive them.

### 3.1 Success return

- Method: **GET or POST**
- URL: **`/api/v1/general/checkout/callback?paymentId={reference}&type={web|mobile}`**
- Who calls it: **the payment provider (redirects the customer's browser
  here)** — not the frontend app directly.
- Login required: **No** (public, rate-limited).

```text
What it receives: the provider's payment reference (paymentId).

What it does (business perspective):
1. Finds the local payment using the provider reference.
2. Asks the provider whether the payment really succeeded
   (never trusts the browser alone).
3. If confirmed AND amount/currency match the order: marks the payment
   paid and completes the order.
4. If not confirmed: marks the payment failed.

What it returns:
- Web buyers: redirects the browser to the shop's success page
  (.../payment/success?...) or failure page (.../payment/failed?...).
- App buyers (checkout sent "type": "mobile"): returns an app-readable
  JSON response with success/failure instead of redirecting.
```

### 3.2 Cancel / failure return

- Method: **GET or POST**
- URL: **`/api/v1/general/checkout/error-callback?paymentId={reference}&type={web|mobile}`**
- Who calls it: **the payment provider (customer cancelled or payment
  failed there)**.
- Login required: **No** (public, rate-limited).

```text
Important business nuance: this page ALSO checks with the provider.
If the provider says the payment actually succeeded (e.g. the customer
paid but landed on the cancel page), the order is still completed.
Otherwise the payment is marked failed.
Returns the same web-redirect / app-JSON formats as the success return.
```

### Who-calls-what (do not mix)

```text
Frontend redirect .... customer is sent TO the provider (uses the "url"
                       from checkout).
Provider callback .... provider sends the customer BACK to
                       /checkout/callback or /checkout/error-callback.
Webhook .............. provider notifies the system directly
                       (server-to-server, no customer involved).
Verification ......... the system asks the provider "did this payment
                       really succeed?" — internal, no customer endpoint.
```

---

## 4. Payment Verification

There is **no customer-facing verification endpoint**. Verification is an
internal step performed automatically inside the callback and webhook
flows.

```text
Endpoint: none for the customer.

Request: none. Response: none.

What the system does:
The system checks with the payment provider whether the payment was
actually successful. It does not trust the frontend or the browser
alone. If the provider confirms the payment and the amount/currency
match the order, the order/payment is completed. If they do not match,
the payment is marked failed and the order stays pending.

Success behavior: payment marked paid, order completed, success page/
response shown.
Failure behavior: payment marked failed, order stays pending, failure
page/response shown, customer may retry checkout.
```

---

## 6. Admin Payment Gateway Endpoints

### List / Read

- Method: **GET**
- URL: **`/api/v1/admin/payment-gateways`**
- Who uses it: **Admin**
- Login required: **Yes**, plus settings-view permission.

Request: none (no body, no parameters).

Success response:

```json
{
  "status": 200,
  "message": "Payment gateways fetched successfully.",
  "success": true,
  "data": {
    "catalog_currency": "KWD",
    "gateways": [
      {
        "code": "myfatoorah",
        "display_name": "MyFatoorah",
        "enabled": true,
        "configured": true,
        "supported_currencies": ["KWD", "SAR", "AED", "BHD", "QAR", "OMR", "EGP"],
        "methods": ["online"],
        "sort_order": 0,
        "supports_catalog_currency": true
      },
      {
        "code": "stripe",
        "display_name": "stripe",
        "enabled": false,
        "configured": false,
        "supported_currencies": ["USD", "EUR", "KWD", "SAR", "AED"],
        "methods": ["online"],
        "sort_order": 1,
        "supports_catalog_currency": true
      },
      {
        "code": "paypal",
        "display_name": "paypal",
        "enabled": false,
        "configured": false,
        "supported_currencies": ["USD", "EUR"],
        "methods": ["online"],
        "sort_order": 2,
        "supports_catalog_currency": false
      }
    ]
  }
}
```

(Field values above illustrate the real structure; actual
`enabled`/`configured`/lists depend on current configuration. Currency
lists shown are the system defaults and can differ per environment.)

```text
Business Logic:

This endpoint tells the admin which payment gateways exist, whether each
one is currently enabled, whether it is properly set up (keys present),
which currencies it accepts, and whether it supports the shop's current
catalog currency. Secrets/keys are never included.
```

Meaning of each field:

| Field | Meaning |
| ----- | ------- |
| `code` | System name of the gateway (`myfatoorah`, `stripe`, `paypal`) — used in checkout requests |
| `display_name` | Name shown to customers/admins; admin-editable |
| `enabled` | Whether the admin switched it on |
| `configured` | Whether the required keys exist (e.g. API/secret keys) |
| `supported_currencies` | Currency list this gateway accepts |
| `methods` | Always `["online"]` currently |
| `sort_order` | Display order; admin-editable |
| `supports_catalog_currency` | Convenience flag: can this gateway charge in today's catalog currency? |
| `catalog_currency` | The shop's current catalog currency |

### Create

```text
No API endpoint was found for creating a new payment gateway.

The current system only updates/configures existing registered gateways
(myfatoorah, stripe, paypal). Adding a brand-new gateway is not possible
through the API.
```

### Update

- Method: **PUT**
- URL: **`/api/v1/admin/payment-gateways/{code}`**
  (example: `/api/v1/admin/payment-gateways/stripe`)
- Who uses it: **Admin**
- Login required: **Yes**, plus settings-update permission.
- Path parameter: `code` — one of `myfatoorah`, `stripe`, `paypal`.

Request body (all fields optional; only sent fields change):

```json
{
  "enabled": false,
  "display_name": "Stripe",
  "sort_order": 1
}
```

| Field | Type / rules | Meaning |
| ----- | ------------ | ------- |
| `enabled` | true/false | Switch the gateway on or off |
| `display_name` | text, max 60 characters (empty clears it back to default) | Customer/admin-facing name |
| `sort_order` | whole number 0–999999 (empty clears it) | Display order |

Anything else in the request (keys, secrets, currency lists) is ignored —
those can only be changed in the server configuration, never via API.

Success response:

```json
{
  "status": 200,
  "message": "Payment gateway updated successfully.",
  "success": true,
  "data": {
    "code": "stripe",
    "display_name": "Stripe",
    "enabled": false,
    "configured": true,
    "supported_currencies": ["USD", "EUR", "KWD", "SAR", "AED"],
    "methods": ["online"],
    "sort_order": 1
  }
}
```

Error cases:

| Case | Response | Meaning |
| ---- | -------- | ------- |
| Unknown `code` (e.g. `bitcoin`) | `404` "Payment gateway not found." | Only the three registered gateways can be updated |
| `enabled` not true/false, name too long, bad sort order | `422` validation error | The value was rejected; nothing was saved |
| Missing admin permission | `403` | Staff without settings permission cannot change gateways |

### MOST IMPORTANT — What happens when admin updates a gateway?

```text
Admin changes: Stripe, enabled = false.
The system saves this setting.
After that: new customers cannot start a new Stripe payment
(they get "Payment gateway is unavailable").
Existing payments already in progress still verify and complete
normally (disabling never cancels or strands an in-flight payment).
```

- **When `enabled = true`:** customers can start new payments with this
  gateway (provided it is configured and supports the order currency).
- **When `enabled = false`:** new payments with this gateway are rejected
  with HTTP 422. Payments already created/pending are unaffected: their
  return pages and webhooks still verify and complete.
- **When `display_name` changes:** only the displayed name changes.
  Nothing about payments, availability, or behavior changes.
- **When `sort_order` changes:** only the ordering in the admin list
  changes. Nothing about payments changes.
- **Invalid gateway code:** `404`, nothing saved.
- **Invalid value:** `422`, nothing saved.

---

## 7. Gateway Availability Logic

From a business perspective, the system allows a **new** payment to start
only if ALL of these are true:

```text
1. The gateway exists (myfatoorah / stripe / paypal).
2. The gateway is enabled by the admin.
3. The gateway is configured (its required keys are present).
4. The gateway supports online payments.
5. The gateway supports the order's currency.
```

```text
If all conditions pass → payment can start (provider page URL returned).
If any condition fails → payment is rejected with HTTP 422 and no
provider page is created.
```

Real failure responses:

| Failed condition | Response |
| ---------------- | -------- |
| Gateway unknown / disabled / not configured | `422` "Payment gateway is unavailable" |
| Order currency not in the gateway's list | `422` "Payment gateway does not support the selected currency …" |

Related default currency lists (system defaults; environment may differ):

| Gateway | Accepts (default) |
| ------- | ----------------- |
| MyFatoorah | KWD, SAR, AED, BHD, QAR, OMR, EGP |
| Stripe | USD, EUR, KWD, SAR, AED |
| PayPal | USD, EUR |

---

## 8. Amount & Currency Logic

```text
Customer does NOT choose the payment amount.
Customer does NOT choose the payment currency.

The system takes the amount from the order total
(items - discounts + taxes + shipping).
The system takes the currency from the order's saved currency
(which equals the shop's catalog currency at the time of ordering).
The payment provider receives exactly that amount and currency.
```

- Amounts keep full precision (e.g. 3 decimals for KWD/BHD/OMR/JOD/TND,
  2 for most others) all the way to the provider.
- When the system confirms payment, it re-checks that the provider's
  amount and currency **exactly match** the order. Any mismatch rejects
  the payment and the order stays unpaid.
- The customer's display/currency preference (including any currency
  header) only changes how prices are *shown* — it never changes the
  charged currency.

**What happens if admin changes the catalog currency after an order was
created?**

```text
Old order (created in KWD): remains KWD, charged in KWD.
New orders: use the new catalog currency (e.g. SAR).

Reason: the currency is saved on each order at creation time, and
payment always uses the order's saved currency.
```

(If the customer retries checkout on their old pending order, the order
is refreshed and its saved currency follows the current catalog
currency at that moment.)

---

## 9. Frontend Business Flow

```text
Customer opens checkout
        ↓
(Proposed: read public payment-gateways once → show only available
 gateways + fast-shipping if available. Today: options are hard-coded
 and 422 responses are the fallback.)
        ↓
Customer selects normal or fast shipping (fast: check status first)
        ↓
Customer selects payment gateway (MyFatoorah / Stripe / PayPal / COD / cashier)
        ↓
Frontend sends ONE checkout request (no amount, no currency)
        ↓
System validates gateway + cart
        ↓
System creates (or reuses) the order
        ↓
System takes amount from the order, currency from the order
        ↓
System creates the provider payment
        ↓
System returns the payment page URL
        ↓
Frontend redirects customer to the provider
        ↓
Customer pays (or cancels)
        ↓
Provider returns customer / notifies system
        ↓
System verifies with the provider and checks amount/currency match
        ↓
System completes payment + order (or marks failed for retry)
```

---

## 10. Error Scenarios

| # | Situation | What the customer sees | Can they retry? |
|---|-----------|------------------------|-----------------|
| 1 | Empty cart | 400 Cart not found | Yes, after adding items |
| 2 | Missing/invalid field | 422 field-problem list | Yes, after fixing input |
| 3 | Gateway disabled / not set up | 422 Payment gateway is unavailable | Yes, with another gateway |
| 4 | Currency not supported | 422 …does not support the selected currency… | Yes, with another gateway |
| 5 | Provider unreachable | 500 Error creating invoice | Yes |
| 6 | Customer cancels at provider | Failure page / failure response | Yes, resubmit checkout |
| 7 | Provider says unpaid | Failure page / failure response | Yes |
| 8 | Paid amount/currency differs from order | Payment rejected, order stays pending | Support case — do not auto-retry blindly |
| 9 | Not logged in | 401 | Yes, after login |
| 10 | Too many attempts | 429, wait and retry | Yes, after waiting |
| 11 | Unknown payment reference on return | Failure page (never a false success) | Check orders / support |

Unpaid online orders do not stay open forever: the system cancels orders
left unpaid past the configured timeout (24 hours for online payments, 7
days for cash on delivery), after double-checking with the provider that
nothing was actually paid.

---

## 11. Complete API Summary Table

| Type | Method | Endpoint | Who Uses It | Purpose |
| ---- | ------ | -------- | ----------- | ------- |
| Customer | POST | `/api/v1/general/checkout` | Frontend | Place order + start payment (online/COD/cashier) |
| Customer | POST | `/api/v1/general/fast-shipping/checkout` | Frontend | Place fast order + start payment (online/COD/cashier) |
| Customer | GET | `/api/v1/general/fast-shipping/status` | Frontend | Check fast-shipping availability/fee/ETA (public) |
| Customer | GET | `/api/v1/general/payment-gateways` | Frontend | List available gateways + fast shipping (PROPOSED — NOT IMPLEMENTED) |
| Customer | GET | `/api/v1/general/orders` | Frontend | List my orders (follow-up) |
| Customer | GET | `/api/v1/general/orders/{id}` | Frontend | View one order (follow-up) |
| Provider | GET/POST | `/api/v1/general/checkout/callback` | Provider redirect | Customer return after payment |
| Provider | GET/POST | `/api/v1/general/checkout/error-callback` | Provider redirect | Customer return after cancel/failure |
| Admin | GET | `/api/v1/admin/payment-gateways` | Admin | List gateways + status |
| Admin | PUT | `/api/v1/admin/payment-gateways/{code}` | Admin | Enable/disable/rename/reorder a gateway |

MyFatoorah webhook: NOT FOUND IN CURRENT CODEBASE (return pages only).
Public payment-methods endpoint: PROPOSED IN §14 (not implemented).
Gateway CREATE endpoint: NOT FOUND IN CURRENT CODEBASE.

---

## 12. Request / Response Matrix

| Endpoint | Request | Success Response | Main Error Responses | Business Result |
| -------- | ------- | ---------------- | -------------------- | --------------- |
| POST checkout (online) | Customer + shipping + `payment_method:online` + `gateway` | 200 + `data.url` | 400 empty cart; 422 validation/disabled/currency; 500 provider | Order created + provider payment ready → redirect |
| POST checkout (COD/cashier/free) | Same, `payment_method:cod`/`pay_at_cashier` | 200 + `data.order_id` | Same minus provider errors | Order placed, pay later (or done if free) |
| POST fast-shipping checkout (online) | Same as online + always address/area; fast cart | 200 + `data.url` | Same + 422 fast-unavailable / no-fast-items | Fast order created + provider payment ready → redirect |
| POST fast-shipping checkout (COD/cashier) | Same, `payment_method:cod`/`pay_at_cashier` | 200 + `data.order_id` | Same + fast gates | Fast order placed, pay later |
| GET fast-shipping status | — (public) | 200 + availability/fee/ETA | 429 | Frontend knows whether to offer fast shipping |
| GET public payment-gateways (PROPOSED) | — (public) | 200 + gateways/methods/fast shipping | 429 | Frontend renders valid options, no hard-coding |
| GET/POST callback | `paymentId` (+`type`) from provider | Redirect success page / app JSON | Failure page / app JSON; 400 bad reference | Paid→order completed; else failed |
| GET/POST error-callback | Same | Same (still completes if provider confirms paid) | Same | Paid→completed; else failed |
| GET admin gateways | — (admin token) | 200 + gateway list | 401/403 | Admin sees status of all gateways |
| PUT admin gateway | `enabled`/`display_name`/`sort_order` | 200 + updated row | 404 unknown code; 422 bad value; 403 | Gateway switched/renamed/reordered |

---

## 13. Admin Enable/Disable Matrix

| Admin Action | System Result | New Payments | Existing (Pending) Payments |
| ------------ | ------------- | ------------ | --------------------------- |
| Enable gateway | Saved as enabled | Allowed (if configured + currency supported) | Unaffected |
| Disable gateway | Saved as disabled | Rejected with 422 "Payment gateway is unavailable" | Unaffected — return pages and webhooks still verify and complete |
| Update display name | Name saved | Unaffected (only the shown name changes) | Unaffected |
| Update sort order | Order saved | Unaffected (only admin list order changes) | Unaffected |
| Invalid gateway code | 404, nothing saved | — | — |
| Invalid value | 422, nothing saved | — | — |

---

## 14. Public Payment Methods Endpoint

```text
CURRENT status: no public payment-method availability endpoint exists in
the codebase (verified by route + resource search). The only
gateway-listing endpoint is the admin one (Section 6).

PROPOSED status: the contract below specifies a client-safe public
version of the admin list, following the SAME data flow, plus
fast-shipping availability. NOT IMPLEMENTED — do not call it yet.
```

### Proposed endpoint — public gateway + fast-shipping availability

- Method: **GET**
- URL (proposed): **`/api/v1/general/payment-gateways`**
- Who uses it: **Frontend / client (customer app, website)**
- Login required: **No** (public, rate-limited like other public APIs)

#### Business Purpose

> This endpoint tells the client which payment options can be offered
> right now — gateways that are switched on, set up, and able to charge
> in the shop's current currency, plus whether fast shipping can be
> offered — so the frontend no longer hard-codes any of this.

#### Same-flow guarantee (why the client can trust it)

The proposed endpoint reuses the **exact same flow** as the two existing
endpoints, only trimmed to what the client needs:

```text
Admin GET /api/v1/admin/payment-gateways
  → same gateway view (code, display_name, supported_currencies,
    supports-catalog-currency flag)
  → MINUS internal fields (enabled, configured, sort_order stay
    admin-only; secrets were never included anyway)
  → PLUS the fast-shipping block from
    GET /api/v1/general/fast-shipping/status
  = Proposed GET /api/v1/general/payment-gateways
```

Because the data comes from the same source checkout's own gate reads,
the list can never disagree with what checkout will accept — and where
it could (a setting changed mid-second), checkout's 422 stays the final
authority.

#### Proposed request

None (no body, no parameters). Optional: standard `Accept: application/json`.

#### Proposed success response

Same envelope as every other endpoint
(`{status, message, success, data}`):

```json
{
  "status": 200,
  "message": "Payment options fetched successfully.",
  "success": true,
  "data": {
    "gateways": [
      {
        "code": "myfatoorah",
        "display_name": "MyFatoorah",
        "supported_currencies": ["KWD", "SAR", "AED", "BHD", "QAR", "OMR", "EGP"],
        "supports_catalog_currency": true
      }
    ],
    "payment_methods": [
      { "code": "online", "display_name": "Online payment" },
      { "code": "cod", "display_name": "Cash on delivery" },
      { "code": "pay_at_cashier", "display_name": "Pay at cashier" }
    ],
    "fast_shipping": {
      "enabled": true,
      "available": true,
      "fee": 2.5,
      "duration_minutes": 120,
      "opens_at": "08:00",
      "closes_at": "22:00",
      "available_again_at": null
    }
  }
}
```

(One object per registered gateway — `myfatoorah`, `stripe`, `paypal` —
with that gateway's real values; lists depend on current configuration.)

Meaning of the client fields:

| Field | Meaning |
| ----- | ------- |
| `code` | System name used in checkout's `gateway` field |
| `display_name` | Name to show the customer (admin-editable) |
| `supported_currencies` | Currencies this gateway accepts |
| `supports_catalog_currency` | Can it charge in today's shop currency? Offer it when `true` |
| `payment_methods` | Fixed documented list (online / cod / cashier); combination rules (COD≠pickup, cashier rules) stay as documented in §2 |
| `fast_shipping` | Same block as the public status endpoint (identical field names) — offer fast checkout only when `available` |

```text
Business Logic:

The client calls this once (e.g. when opening checkout) and renders
the gateways using display_name, offering the ones with
"supports_catalog_currency": true, plus the payment methods and the
fast-shipping option from the fast_shipping block. If checkout rejects
a gateway at submit time (e.g. it was disabled a second ago — 422
"Payment gateway is unavailable"), the client shows that message as
today. No availability verdict is computed here; checkout stays the
single authority that accepts or rejects a payment.
```

#### What the proposed endpoint deliberately does NOT return

- No secrets/keys (none exist in the admin view either).
- No internal `enabled` / `configured` flags (folded into
  `available` + reasons).
- No per-cart/per-area verdicts (area and item eligibility are decided
  at checkout, HTTP 422, as today).
- No order, amount, or currency choice (backend-derived, §8).

---

## 15. Notes & Limits of This Document

- Currency lists, secrets presence, and timeouts depend on the deployed
  environment; defaults are shown where the system defines them.
- Message wordings shown are the English defaults.
- Fast-shipping checkout is covered in Section 2, Endpoint 2 (summary);
  the full traced contract — availability gates, provider-by-provider
  behavior, retry/expiry/state machines, comparison tables — lives in
  `PAYMENT_FAST_SHIPPING_BUSINESS_CONTRACT.md`.
- The public `payment-gateways` endpoint in Section 14 is PROPOSED
  (not implemented); everything else in this document is CURRENT.
