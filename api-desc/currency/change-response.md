# Currency Refactor — Frontend Response Change Guide

> **Scope:** Every **API response difference** introduced by the catalog → base currency refactor.
> Compare against the pre-refactor shape on `main`.
> Organized **by endpoint** so the frontend can find "what changed on the screen I own" fast.

---

## How to read this doc

| Tag | Meaning | What you must do |
|-----|---------|------------------|
| **ADDED** | New key now returned | Start reading it (treat as nullable until data is proven) |
| **CHANGED** | Key still returned, but value/semantics changed | Re-check how you display/store it (currency, unit, precision) |
| **REMOVED** | Key no longer returned | Stop reading it; migrate to the replacement |
| **UNCHANGED** | Same key, same meaning (listed for completeness) | Nothing |

> **GOLDEN RULE:** Any **money amount** in a response is now in the **base currency** unless the field name says otherwise. Always render it with the response's `currency` / `base_currency_code` symbol. Pair every amount with its currency code.

---

## TL;DR — 6 things the frontend must do

1. **Stop reading `converted_current_price`** on products → use `current_price`.
2. **Format all money with the base-currency symbol** (`currency` on cart/products, `currency_code`/`base_currency_code` on orders).
3. **Read the new `currency` field** returned by cart responses.
4. **Show `catalog_currency`** on orders/invoices if you want to display the "original" catalog currency.
5. **Hide/disable the storefront currency selector** when `currency_selection_enabled` is `false`; otherwise call `POST /api/v1/general/currencies/select`.
6. **Read admin list pagination** from the flattened envelope (`page`, `path`, `last_page_url`, `first_page_url`, …), not the old documented `{meta, links}` shape.

---

# Endpoint-by-endpoint changes

---

## A. Product endpoints

**Affected endpoints (all use `ProductResource` / `ProductMiniResource`):**
- `GET /api/v1/products` (list / search / filter)
- `GET /api/v1/products/{slug}` (detail)
- Any listing using the mini resource (home, categories, brands, flash-sale, search, etc.)

| Key | Status | Before | After (frontend must…) |
|-----|--------|--------|--------------------------|
| `price` | **CHANGED** | Raw catalog amount | **Base-currency converted** value. Display as-is with `currency`. |
| `current_price` | **UNCHANGED** | Converted value | Still converted to base. Use this for the "you pay" price. |
| `discount_amount` | **CHANGED** | Raw value always | **Converted to base ONLY when `discount_type = "fixed"`**; stays raw (catalog) for percentage discounts. |
| `converted_current_price` | **REMOVED** | Duplicate of `current_price` | **Delete all reads**; use `current_price`. |
| `currency` | UNCHANGED* | Already present | Already the base code (e.g. `KWD`). Use it for the symbol. |

\* `currency` was already in the product response before the refactor — it is listed here so you know which code to trust.

**Frontend action**
- Replace every `converted_current_price` read with `current_price`.
- For fixed discounts, the math is now consistent in base currency: `price - discount_amount == current_price`.
- For percentage discounts, `discount_amount` is a percentage value, not money — keep treating it as a rate.

**Example (catalog USD → base KWD, rate 0.221)**

Before
```json
{ "price": 100.0, "current_price": 22.1, "discount_type": "fixed", "discount_amount": 20.0, "converted_current_price": 22.1, "currency": "KWD" }
```
After
```json
{ "price": 22.1, "current_price": 22.1, "discount_type": "fixed", "discount_amount": 4.42, "currency": "KWD" }
```

---

## B. Cart endpoints

**Affected endpoints (use `CartResource` / `CartItemResource`):**
- `GET /api/v1/carts` (current cart)
- `POST /api/v1/carts` / `POST /api/v1/carts/add` / `PUT /api/v1/carts` (any cart mutation returns the cart)
- `GET /api/v1/checkout` (if it reuses the cart resource)

| Key | Status | Before | After (frontend must…) |
|-----|--------|--------|--------------------------|
| `total_price` | **CHANGED** | Catalog subtotal | **Base-currency converted**. Display with base symbol. |
| `subtotal` | **CHANGED** | Catalog subtotal | **Base-currency converted**. |
| `coupon_discount` | **CHANGED** | Catalog value | **Base-currency converted**. |
| `total_after_coupon` | **CHANGED** | Catalog value | **Base-currency converted**. |
| `currency` | **ADDED** | — | **New** base currency code. Use it for ALL cart totals. |
| `items[].price` | **CHANGED** | Raw catalog price | **Base-currency converted**. |
| `items[].total_price` | **CHANGED** | Raw catalog total | **Base-currency converted**. |
| `items[].discount_amount` | **CHANGED** | Raw value | **Base-currency converted**. |

**Frontend action**
- Read the new top-level `currency` field and use it for every money label on the cart page.
- Re-derive totals in base currency: `price - discount_amount == current_price` (fixed discounts).

**Example (catalog USD → base KWD, rate 0.221)**

Before
```json
{
  "total_price": 120.0, "subtotal": 120.0, "coupon_discount": 10.0, "total_after_coupon": 110.0,
  "items": [ { "id": 1, "price": 60.0, "total_price": 120.0, "discount_amount": 0.0 } ]
}
```
After
```json
{
  "total_price": 26.52, "subtotal": 26.52, "coupon_discount": 2.21, "total_after_coupon": 24.31,
  "currency": "KWD",
  "items": [ { "id": 1, "price": 13.26, "total_price": 26.52, "discount_amount": 0.0 } ]
}
```

---

## C. Order endpoints

**Affected endpoints (use `OrderResource`):**
- `GET /api/v1/orders` (list)
- `GET /api/v1/orders/{id}` (detail)
- `GET /api/v1/orders/invoice/{uuid}` (invoice)

| Key | Status | Before | After (frontend must…) |
|-----|--------|--------|--------------------------|
| `total_price` | **CHANGED** | Catalog total | **Base amount — now the authoritative total.** Display with base symbol. |
| `currency_code` | **CHANGED** | Catalog code on snapshot | **Base code** (now equals `base_currency_code`). Use for the symbol. |
| `base_currency_code` | **UNCHANGED** | Base code | Still the base code. |
| `catalog_currency` | **ADDED** | — | **New** — the original catalog code the prices were quoted in (`catalog_currency_code ?? base_currency_code ?? fallback`). Show it if the "original currency" matters to the user. |
| `currency_rate` | **CHANGED** | Catalog snapshot semantics | Now the **catalog → base** ratio. |
| `converted_total_price` | **UNCHANGED** | Converted value | Kept for backward compatibility (equals base `total_price`). |

**Frontend action**
- Always show `total_price` as the order total in `currency_code` / `base_currency_code`.
- Optionally surface `catalog_currency` (e.g. "originally priced in USD") using `catalog_currency`.
- Do not rely on `converted_total_price` for new logic; it is retained only for old clients.

**Example (catalog USD → base KWD)**

Before
```json
{ "total_price": 120.0, "currency_code": "USD", "base_currency_code": "KWD", "currency_rate": 1.0, "converted_total_price": 26.52 }
```
After
```json
{ "total_price": 26.52, "currency_code": "KWD", "base_currency_code": "KWD", "catalog_currency": "USD", "currency_rate": 4.5249, "converted_total_price": 26.52 }
```

---

## D. Settings endpoints

**Affected endpoints:**
- `GET /api/v1/general/settings` — **public** storefront settings
- `GET /api/v1/settings` — **admin** (auth:sanctum + `view-settings` permission)
- `PUT /api/v1/settings` — **admin** update (auth + `update-settings` permission)

| Key | Status | Before | After (frontend must…) |
|-----|--------|--------|--------------------------|
| `currency_selection_enabled` (top-level) | **ADDED** | — | **Boolean** (default `false`). Controls whether the storefront currency selector is active. |
| `options.currency_selection_enabled` | **ADDED** | — | Same value, nested in `options`. Written by `PUT /api/v1/settings`. |

**`PUT /api/v1/settings` request change**
- New optional field `currency_selection_enabled` (`sometimes|boolean`). When sent, it is **merged** into `options` (existing keys like `fast_shipping` are preserved), the effective-currency cache is cleared, and the `settings` cache tag is flushed. Omitting it leaves the stored value untouched (old clients stay compatible).

**Effective-currency resolution (`CurrencyService::getEffectiveCode()`)**
- `currency_selection_enabled = false` (default) → effective currency is **always the catalog code** (user preference / `X-Currency` header ignored).
- `currency_selection_enabled = true` → `user preference > X-Currency header > catalog code`.

**Frontend action**
- On the admin settings form: read & write `currency_selection_enabled` (boolean).
- On the storefront: read it from `GET /api/v1/general/settings` to decide whether to show the currency selector.

**Example**
```json
{
  "minimumOrderAmount": 100,
  "currency_selection_enabled": false,
  "options": { "minimumOrderAmount": 100, "currency_selection_enabled": false, "fast_shipping": { "enabled": true } }
}
```

---

## E. `POST /api/v1/general/currencies/select` — NEW (public)

Persist a storefront currency selection. **No auth required**, `throttle:public-api`.

**Request**
```json
{ "currency_code": "KWD" }
```
- `currency_code`: required, string, `max:3`, must exist in `currencies` **and** `is_active = true`.

**Behavior**
1. Code is uppercased.
2. If an authenticated user is present → stores the **user preference** (`user_preferences`).
3. For guests, the frontend owns the value and sends it via `X-Currency` header on subsequent requests (no `guest_currency` cookie; backend reads `UserCurrencyPreferenceService::getHeaderCurrencyCode()`).
4. Clears the effective-currency cache (`CurrencyService::forgetEffectiveCode()`).
5. Returns `200` `CURRENCY_SELECTED_SUCCESSFULLY` with a `CurrencyResource` in `data`.

**Note:** the selection only affects pricing/display when `currency_selection_enabled = true` (section D). While disabled, the preference/header is stored but **ignored** by `getEffectiveCode()`.

**Frontend action**
- When the user picks a currency in the storefront selector, call this endpoint with `{ currency_code }`.
- Read `data` (the `CurrencyResource`) to update the active-currency UI state.

**Response**
```json
{
  "status": 200, "message": "Currency updated successfully", "success": true,
  "data": { "id": 2, "code": "KWD", "name": "Kuwaiti Dinar", "symbol": "KD", "country_name": "Kuwait",
            "numeric_code": "414", "decimal_places": 3, "icon": "kw", "is_active": true, "sort_order": 2,
            "is_base": true, "is_catalog": false, "created_at": "2026-08-10T00:00:00+00:00" }
}
```

---

## F. `GET /api/v1/currencies` — admin list

**Changes**
1. **Pagination envelope changed** (see section H for the full envelope). Read pagination from the flattened keys.
2. **New query filters** (response shape unchanged): `search` (code / numeric_code / name / symbol / country_name incl. translations), `code`, `is_active`, `sort_order`.

**Frontend action**
- Build the admin currencies grid using the flattened pagination envelope, not `{meta, links}`.
- Wire the new filter inputs to the query params above.

---

## G. `GET /api/v1/currency-rates` — admin list

**Changes**
1. **Pagination envelope changed** (same flattened shape as section H).
2. **New query filters**: `date_from`, `date_to`, `code` (in addition to existing `currency_id`, `effective_date`).

**Frontend action**
- Same pagination handling as section F.
- Wire date-range and `code` filters.

---

## H. `POST /api/v1/currencies/{id}/set-catalog` — NEW (admin)

Sets the catalog currency. Requires `set-catalog-currency` permission. Returns `SET_CATALOG_CURRENCY_SUCCESSFULLY`.

**Frontend action (admin)**
- Expose a "set as catalog currency" action in the admin currencies grid; call this endpoint, then refresh the list.

---

## Flattened pagination envelope (sections F & G)

Both admin lists now return a **custom flattened envelope** inside `data`. The previously documented `{data, meta, links}` shape was wrong.

Before (as documented)
```json
{
  "status": 200, "message": "Data fetched successfully", "success": true,
  "data": { "data": [], "current_page": 1, "from": 1, "to": 15, "last_page": 1, "per_page": 15, "total": 3, "next_page_url": "", "prev_page_url": "" }
}
```

After (actual response)
```json
{
  "status": 200, "message": "Data fetched successfully", "success": true,
  "data": {
    "data": [], "page": 1, "current_page": 1, "from": 1, "to": 15, "last_page": 1,
    "path": "http://localhost/api/v1/currencies", "per_page": 15, "total": 3,
    "next_page_url": "", "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/currencies?page=1",
    "first_page_url": "http://localhost/api/v1/currencies?page=1"
  }
}
```

**New keys to read:** `page`, `path`, `last_page_url`, `first_page_url`.

---

# Behavioral changes (no response shape — payments/invoices)

These ensure payment artifacts quote the **order's base currency** instead of a hardcoded `EGP`/`KWD` default. Listed so the frontend knows payment screens/invoices are now currency-correct:

| Component | Now uses |
|-----------|----------|
| `MyFatoorahGateway::createInvoice` (DisplayCurrencyIso) | `order.base_currency_code ?? order.currency_code ?? 'EGP'` |
| `MyFatoorahGateway::refund` | `order.base_currency_code ?? order.currency_code ?? 'EGP'` |
| `PaymentCheckoutHandler` transactions | `order.currency_code ?? order.base_currency_code ?? config(...)` |
| `CashierQrService` QR payload | `transaction.currency ?? order.currency_code ?? order.base_currency_code` |
| `InvoiceService` / `InvoiceSnapshotService` | `paidTransaction.currency ?? order.currency_code ?? order.base_currency_code ?? 'EGP'` |
| `PaymentReconciliationJob::compareCurrency` | `order.base_currency_code ?? order.currency_code ?? config(...)` |
| `OrderController` callback currency-mismatch check | `order.base_currency_code ?? order.currency_code ?? config('payment.default_currency', 'EGP')` |

---

# Doc corrections (verified in source, not code changes)

| Was documented as | Actual |
|-------------------|--------|
| `GET /api/v1/currencies` returns standard Laravel pagination `{data, meta, links}` | Custom flattened envelope (section H) |
| `GET /api/v1/currency-rates` same standard shape | Same custom flattened envelope |
| Rate create/update response includes nested `currency` object | `CurrencyRateResource` outputs only `id, currency_id, exchange_rate, effective_date, created_at` (relation loaded but not serialized) |
| `GET /api/v1/settings` is public | It is inside `auth:sanctum` + `view-settings`; the public endpoint is `GET /api/v1/general/settings` |

---

# Caveats for consumers

- **Nulls:** converted fields return `null` when source is null/empty (same as before).
- **Precision:** converted amounts are rounded to 2 decimals (`round(..., 2)`).
- **Same code:** when catalog == base, conversion is identity (rate `1`, no DB query) — values unchanged.

---

# Frontend Migration Checklist (by endpoint)

| Endpoint / screen | Task | Change |
|-------------------|------|--------|
| Product card (`GET /api/v1/products`, `/products/{slug}`, listings) | Stop reading `converted_current_price`; use `current_price` | REMOVED |
| Product card | Show `price` / `current_price` / `discount_amount` with base symbol (`currency`) | CHANGED |
| Cart page (`GET /api/v1/carts`, checkout) | Read new `currency` field; format all totals in base | ADDED |
| Cart page | Re-derive `price - discount_amount == current_price` in base (fixed discounts) | CHANGED |
| Order/Invoice (`GET /api/v1/orders`, `/orders/invoice/{uuid}`) | Show base `total_price` with `currency_code`/`base_currency_code` | CHANGED |
| Order/Invoice | Show `catalog_currency` if "original currency" matters | ADDED |
| Storefront currency selector | Hide/disable when `currency_selection_enabled` is `false` (from `GET /api/v1/general/settings`) | ADDED |
| Storefront currency selector | Call `POST /api/v1/general/currencies/select` with `{ currency_code }` | ADDED (new endpoint) |
| Admin settings form (`PUT /api/v1/settings`) | Read/write `currency_selection_enabled` (boolean) | ADDED |
| Admin currencies grid (`GET /api/v1/currencies`) | Use flattened pagination envelope; add `search`/`code`/`is_active`/`sort_order` filters | ADDED |
| Admin currency-rates grid (`GET /api/v1/currency-rates`) | Use flattened pagination envelope; add `date_from`/`date_to`/`code` filters | ADDED |
| Admin currencies grid | "Set catalog" action → `POST /api/v1/currencies/{id}/set-catalog` | ADDED (new endpoint) |
