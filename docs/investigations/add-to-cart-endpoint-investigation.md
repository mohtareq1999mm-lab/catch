# Add-to-Cart: Endpoint, Headers, Execution Flow and Root-Cause Investigation

Status: backend investigation complete. Frontend investigation **not possible from this repository** (see §0.4).
Scope: guest (unauthenticated) add-to-cart primary path, authenticated path as comparison.
Evidence labels: **CONFIRMED** (read in source), **FRAMEWORK** (Laravel behaviour), **HYPOTHESIS** (needs runtime/network proof).

---

## 0. Investigation basis

### 0.1 Files examined

| Area | File |
|---|---|
| Routes (guest) | `routes/api.php` (lines 160–214) |
| Routes (authenticated cart) | `packages/marvel/src/Rest/Routes.php` (lines 415–424), loaded by `packages/marvel/src/Providers/RestAPIServiceProvider.php` with prefix `api/v1` |
| Route loading (api prefix) | `app/Providers/RouteServiceProvider.php` (`Route::prefix('api')`) |
| Guest controller | `app/Http/Controllers/Api/General/Guest/GuestCartController.php` |
| Guest cart service | `app/Services/Guest/GuestCartService.php` |
| Guest identity/CSRF service | `app/Services/Guest/GuestIdentityService.php` |
| Guest session controller | `app/Http/Controllers/Api/General/Guest/GuestSessionController.php` |
| Validation | `packages/marvel/src/Http/Requests/CartCreateRequest.php` |
| Canonical cart mutation | `packages/marvel/src/Database/Repositories/CartRepository.php` |
| Cart line/price writer | `app/Services/General/CartInventoryService.php` |
| Pricing | `packages/marvel/src/Services/Pricing/ProductPricingService.php` |
| Product rules | `packages/marvel/src/Database/Models/Product.php` (`scopeActive`, `scopeActiveStatus`, `variations`) |
| Middleware | `app/Http/Middleware/GuestOrigin.php`, `GuestCsrf.php`, `GuestRateLimit.php`, `ChannelMiddleware.php`, `CheckLangMiddleware.php`, `RequestIdMiddleware.php`, `EncryptCookies.php` |
| Middleware registration | `app/Http/Kernel.php`, `app/Providers/RateLimitServiceProvider.php` |
| Config | `config/guest_checkout.php`, `config/cors.php`, `config/channel.php`, `config/app.php` (`disable_rate_limiting`) |
| Responses | `packages/marvel/src/Traits/ApiResponse.php`, `app/Exceptions/Handler.php`, `packages/marvel/src/Http/Resources/CartResource.php`, `CartItemResource.php` |
| Contract docs | `docs/guest-checkout-frontend-contract.md` |
| Tests | `tests/Feature/Guest/GuestSessionAndCartTest.php`, `GuestSecurityContractTest.php`, `GuestCartMergeTest.php` |

### 0.2 Hard facts used throughout

- Guest add-to-cart URL: `POST /api/v1/general/guest/cart/items` — **CONFIRMED** (`routes/api.php` + `RouteServiceProvider` + `GuestCheckoutTestCase::PREFIX`).
- Authenticated add-to-cart URL: `POST /api/v1/cart` — **CONFIRMED**.
- Both eventually call the same function: `CartRepository::applyItemToCart()` → `CartInventoryService::incrementItem()` — **CONFIRMED**.
- Guest reference is a cookie (`meem_guest`), **not** a token in JSON — **CONFIRMED**.

### 0.3 What "add to cart" means in this codebase

A cart line is `(cart_id, product_id, product_variant_id, shipping_method)`. Adding = increment/merge that line and **re-price it server-side**. Stock is *not* reserved at cart time (`CartInventoryService` docblock: inventory reservation belongs to the order). So "added" ≠ "reserved".

### 0.4 Missing evidence (cannot be invented)

- The storefront (React/Next/PWA) is **not in this workspace**. `docs/guest-checkout-frontend-contract.md` states: "Frontend repository: not present in this workspace".
- No browser HAR, no console log, no Network capture, no failing/succeeding product IDs were supplied.
- Therefore §7 (frontend), §8 (network) and part of §9 are **hypothesis frameworks**, each tied to a concrete backend branch that would produce the observed symptom. Every hypothesis lists the exact check to confirm it.

---

## 1. Endpoint analysis

### 1.1 Endpoint inventory

| Purpose | Method | URL | Middleware chain (in order) |
|---|---|---|---|
| Create guest session (mints cookies + CSRF) | POST | `/api/v1/general/guest/session` | `api`, `throttle:public-api`, `guest.origin`, `guest.throttle:guest_session` |
| Revoke guest session | DELETE | `/api/v1/general/guest/session` | + `guest.csrf` |
| Read guest cart | GET | `/api/v1/general/guest/cart` | `api`, `throttle:public-api`, `guest.throttle:guest_cart_read` |
| **Add item (guest)** | **POST** | **`/api/v1/general/guest/cart/items`** | `api`, `throttle:public-api`, `guest.origin`, `guest.csrf`, `guest.throttle:guest_cart_write` |
| Update item (guest) | PUT | `/api/v1/general/guest/cart/items` | same as add |
| Remove line (guest) | DELETE | `/api/v1/general/guest/cart/items/{itemId}` (`whereNumber`) | same as add |
| Clear cart (guest) | DELETE | `/api/v1/general/guest/cart` | same as add |
| **Add item (authenticated)** | **POST** | **`/api/v1/cart`** | `auth:sanctum`, `throttle:cart`, `lang` |
| Update item (authenticated) | PUT | `/api/v1/cart/update-item` | same |
| Bulk add (authenticated) | POST | `/api/v1/cart/bulk-items` | same |

`api` group contents (`app/Http/Kernel.php`): `throttle:api`, `EncryptCookies`, `AddQueuedCookiesToResponse`, `SubstituteBindings`, `RequestIdMiddleware`, `ChannelMiddleware`, `CheckLangMiddleware`.
Note: the group is applied **twice** on `v1/general` (once by `RouteServiceProvider`, once by the in-file `Route::middleware(['api', ...])`) — this is why guest cookies are excluded from `EncryptCookies` (documented in that middleware).

### 1.2 What the guest add endpoint is responsible for

1. Prove the caller owns a live guest identity (HttpOnly cookie → DB lookup by HMAC hash).
2. Prove the request is a legitimate same/allowed-origin, CSRF-carrying state change.
3. Validate the payload shape (`CartCreateRequest`).
4. Enforce guest commerce policy (physical only, no fast shipping, channel eligibility, variant required, stock sanity).
5. Resolve the cart (`carts.guest_id`, UNIQUE) and upsert the line under a row lock.
6. Re-price the line with `ProductPricingService` (client prices ignored).
7. Recompute `carts.total_price`.
8. Extend retention (7 days) + refresh both cookies.
9. Return `201` with the full `CartResource`.

### 1.3 Request body

```json
{
  "item": {
    "product_id": 123,
    "quantity": 2,
    "product_variant_id": null,
    "shipping_method": "scheduled",
    "attributes": [],
    "operation": "increment"
  }
}
```

- `product_id` — required, integer, `exists:products,id`.
- `quantity` — required, integer, `1..config('cart.max_item_quantity', 100)`.
- `product_variant_id` — `sometimes|nullable|integer|exists:product_variants,id` (numeric existence only — **not** scoped to the product; see §9/CR-3).
- `attributes` — `sometimes|array`; ignored for variants (variant attributes are rebuilt from the DB) and stored as-is for simple products.
- `shipping_method` — **required**, `Rule::in(['SCHEDULED','FAST','scheduled','fast'])` (`ShippingMethod::SCHEDULED/FAST` are uppercase strings).
- `operation` — **not validated**; defaults to `increment`. `decrement` is the only other meaningful value.
- Anything else (`price`, `total_price`, `cart_id`) is silently ignored — **CONFIRMED** by `GuestSessionAndCartTest::add_item_creates_guest_owned_cart_with_server_side_price`.

### 1.4 Plain-text execution path (backend)

```
Route (guest cart store)
  → api group (EncryptCookies ×2, Channel, Lang, RequestId)
  → GuestOrigin::assertAllowed        → 403 on foreign Origin
  → GuestCsrf::handle                 → 419 when identity exists but CSRF mismatched
  → GuestRateLimit (40/min)           → 429
  → CartCreateRequest validation      → 422 (Handler JSON: {message,status:false,success:false,errors})
  → GuestIdentityService::current     → identity or 401 session_required
  → GuestCartService::addItem
       assertEligibleItem             → 422 InvalidArgumentException (specific message)
       ensureCart (UNIQUE guest_id)   → SELECT or INSERT (duplicate-key safe)
       applyWithRetry
         → CartRepository::applyItemToCart (DB::transaction + SELECT ... FOR UPDATE)
             syncItems
               Product::active()->findOrFail   → ModelNotFoundException → 422 product_unavailable
               fast-shipping / variant / variable checks → Exception → 422 INVALID_ITEM_DATA
               CartInventoryService::incrementItem (nested transaction, row lock)
                 upsertItem → ProductPricingService → cart_items upsert → cart activity touch
             cart.total_price = SUM(items.total_price)
             load items.product + variant attributes
       touch (guest_expires_at +7d, status=active, identity expiry, cookies)
  → 201 {status:201, message:"Cart created successfully", success:true, data:{cart:CartResource}}
```

---

## 2. Headers: every header actually sent with this request

Legend: **M** = mandatory for this endpoint (missing ⇒ failure), **O** = optional, **I** = ignored by the backend.

| Header | Typical value | Source | Purpose here | If removed / changed | M/O | Used by backend? |
|---|---|---|---|---|---|---|
| `Origin` | `https://catch.mohammedtareq.me` | Browser (auto on CORS requests). Cannot be set by JS. | Cross-origin authorisation + CSRF side-channel; `GuestOrigin` compares it to `cors.allowed_origins` / same-origin | Cross-origin without `Origin` is allowed by `GuestOrigin` (it returns early), but the browser's own CORS check still needs the response header. Wrong origin ⇒ `403 origin_not_allowed`. | M for cross-origin, effectively | **Yes** (`GuestOrigin::assertAllowed`) |
| `Cookie` | `meem_guest=<43 chars>; meem_guest_csrf=<64 hex>` | Browser (server-set, `Path=/api`) | Guest identity + double-submit CSRF value | Missing `meem_guest` ⇒ `401 session_required`; missing `meem_guest_csrf` ⇒ `419` (identity still exists) | M | **Yes** |
| `X-CSRF-Token` | same 64-hex value as `meem_guest_csrf` | Frontend from `data.csrf_token` of `POST /guest/session`, or read from the readable cookie on same-host setups | Double-submit proof of a state-changing request | Any mismatch/absence with a live identity ⇒ `419 csrf_failed` | **M** | **Yes** (`GuestCsrf`) |
| `Content-Type` | `application/json` | HTTP client (axios default when passing an object) | Lets Laravel parse the JSON body into `$request->item` | Without it the body isn't parsed ⇒ `422` "item field is required" | M | **Yes** (body parsing) |
| `Accept` | `application/json` | axios default / `fetch` gives `*/*` | Content negotiation | Not needed in this app: `App\Exceptions\Handler::shouldReturnJson()` also returns JSON for any `/api` path | O | Effectively no (custom handler) |
| `Authorization` | absent (guest) | — | Not used by guest routes | Adding a Sanctum token does **not** change guest routing | O | No |
| `X-Channel` | `home` or `fast-shipping` (default `home` when absent) | Frontend, per `config/channel.php` (`CHANNEL_HEADER`) | Selects `ChannelContext`; `home` **rejects fast-shipping products**, `fast-shipping` filters listings to them | Absent ⇒ `home` ⇒ fast-shipping products become `422 product_unavailable` | O but business-critical | **Yes** (`ChannelMiddleware`, `GuestCartService::assertEligibleItem`) |
| `lang` | `en` / `ar` | Frontend | Locale for validation/business messages | Absent ⇒ `en`; invalid ⇒ `config('app.locale')` (`ar`) | O | **Yes** (`CheckLangMiddleware`) |
| `X-Currency` | `EGP`, `USD`, … (3 letters) | Frontend | Response price conversion in `CartResource`/`CartItemResource` | Absent/invalid ⇒ default effective currency; never affects add success | O | **Yes** (presentation only) |
| `X-Request-ID` | UUID/string | Frontend (optional) or auto-generated | Correlation in audit context; echoed back on the response | Auto UUID generated | O | **Yes** (`RequestIdMiddleware`) |
| `Idempotency-Key` | UUID | Frontend | Used by checkout/payment (`GuestIdempotencyService`) | **Ignored by add-to-cart** — no idempotency protection on cart writes | O | **No** |
| `Referer` | page URL or absent | Browser | Nothing | Removing it changes nothing | O | **No** (no `referer` usage anywhere in `app/`) |
| `User-Agent` | browser UA | Browser | Only stored in `ActivityContext` audit (`RequestIdMiddleware`) | No effect on add-to-cart | O | Stored only |
| `sec-fetch-*`, `sec-ch-ua`, `Accept-Language`, `Accept-Encoding` | browser defaults | Browser | Browser metadata | No backend effect | O | No |
| `X-XSRF-TOKEN` | — | — | Laravel's *web* CSRF header | Irrelevant: API group has no `VerifyCsrfToken`; the guest contract uses `X-CSRF-Token` | I | No |
| `X-Requested-With: XMLHttpRequest` | axios default | Client | Historically toggles JSON expectations | Not needed here | O | No |

**Why credentials must be on:** `meem_guest` is `HttpOnly`, `SameSite=Lax` by default, `Path=/api`, host-only unless `GUEST_COOKIE_DOMAIN` is set. A cross-origin call without `withCredentials: true` sends no cookie ⇒ `401`. Cross-origin *and* different registrable domain ⇒ third-party cookie blocking; the documented remedy is a same-site BFF/reverse proxy.

**Preflight:** `POST` + `Content-Type: application/json` + `X-CSRF-Token` are non-simple ⇒ the browser sends `OPTIONS /api/v1/general/guest/cart/items` first. `HandleCors` (global) answers it; `CORS_ALLOWED_HEADERS` in `.env` already lists `Content-Type`, `X-CSRF-Token`, `x-channel`/`X-Channel`, `lang`, `X-Currency`. `CORS_SUPPORTS_CREDENTIALS=true`, `CORS_MAX_AGE=0` (no preflight caching ⇒ one `OPTIONS` per add, visible in Network as a 204/200 with no body).

---

## 3. Validation rules and rejection matrix

### 3.1 `CartCreateRequest` (both guest and authenticated)

```php
'item' => ['required','array','min:1'],
'item.product_id' => ['required','integer','exists:products,id'],
'item.quantity' => ['required','integer','min:1','max:'.max(1,(int)config('cart.max_item_quantity',100))],
'item.product_variant_id' => ['sometimes','nullable','integer','exists:product_variants,id'],
'item.attributes' => ['sometimes','array'],
'item.shipping_method' => ['required','string', Rule::in([ShippingMethod::SCHEDULED, ShippingMethod::FAST, 'scheduled','fast'])],
```

### 3.2 Guest-only business rules (`GuestCartService::assertEligibleItem`)

| # | Rule | Code | Result |
|---|---|---|---|
| G1 | Product must be `active()` | `Product::query()->active()->find(...)` | `422 product_unavailable` |
| G2 | Not digital | `item_type === ItemType::DIGITAL` | `422 digital_not_supported` |
| G3 | Home channel hides fast-shipping | `ChannelContext::isHome() && is_fast_shipping_available` | `422 product_unavailable` |
| G4 | Shipping method must be `SCHEDULED` | `strtoupper(...) !== ShippingMethod::SCHEDULED` | `422 fast_shipping_not_supported` |
| G5 | Variable needs a variant | `product_type === 'variable' && empty(product_variant_id)` | `422 variant_required` |
| G6 | Quantity ≤ product-level availability | `stock_quantity > 0 && quantity > (stock - reserved)` | `422 insufficient_stock` |

### 3.3 Canonical rules (`CartRepository::syncItems`) — apply after the guest checks

| # | Rule | Result |
|---|---|---|
| C1 | `FAST` requested but product not fast eligible | `Exception` → `422 INVALID_ITEM_DATA` (message names the product) |
| C2 | `product_variant_id` does not belong to the product (`$product->variations()->whereKey($variantId)`) | `422 INVALID_ITEM_DATA` |
| C3 | `product_type === 'variable'` without variant (duplicate of G5) | `422 INVALID_ITEM_DATA` |
| C4 | Resulting quantity > `cart.max_item_quantity` (100) | `422` quantity-maximum message |
| C5 | `operation` neither `increment` nor `decrement` | **silent no-op** — line untouched, still `201` (see CR-6) |
| C6 | Product deleted between validation and lock | `ModelNotFoundException` → `422 product_unavailable` |

`Product::scopeActive()` (this is the real gate, not `status` alone):

```php
->where('status', 1)
->where(fn ($b) => $b->where('in_stock', true)
        ->orWhereRaw('(COALESCE(stock_quantity,0) - COALESCE(reserved_quantity,0)) > 0'))
->where(fn ($q) => $q->whereDoesntHave('categories')
        ->orWhereHas('categories', fn ($cq) => $cq->active()));
```

Consequences — **CONFIRMED**:
- `status = 0` ⇒ never addable.
- `in_stock = false` **and** `stock_quantity - reserved_quantity <= 0` ⇒ never addable.
- Product whose only categories are inactive ⇒ never addable, even when in stock.

---

## 4. Success and error catalogue (exact shapes)

Success `201` (controller `ApiResponse` trait):

```json
{
  "status": 201,
  "message": "Cart created successfully",
  "success": true,
  "data": { "cart": { "id": 5, "total_price": 250, "normal_items": [ … ], "fast_items": [], "total_quantity": 2, "guest_expires_at": "…" } }
}
```

Validation failure `422` (`App\Exceptions\Handler::handleJsonResponse`):

```json
{ "message": "The given data was invalid.", "status": false, "success": false, "errors": { "item.shipping_method": ["The shipping method field is required."] } }
```

(`message.ERROR.INVALID_DATA` = "The given data was invalid." — `resources/lang/en/message.php`.)

Note the contract inconsistency: here `status` is the **boolean** `false`, while success responses put the **HTTP code** in `status`. Clients that branch on `status` must handle both.

| HTTP | Where it comes from | Body `message` |
|---|---|---|
| `401` | `GuestIdentityService::current()` null (no/expired/revoked cookie) | `A guest session is required for this action.` |
| `403` | `GuestOrigin::assertAllowed()` | `This origin is not allowed to perform this action.` |
| `419` | `GuestCsrf` | `The security token is missing or invalid. Please refresh the page and try again.` |
| `422` | FormRequest | `The given data was invalid.` + `errors` map |
| `422` | G1–G6 | product-specific sentence (`This product is not available for guest checkout.`, `Digital products are not available for guest checkout.`, `Fast shipping is not available for guest checkout.`, `Please select a product option before adding it to the cart.`, `The requested quantity is not currently available.`) |
| `422` | C1–C4 / generic Throwable | `Invalid item data` / `Quantity must not exceed :max per cart line.` |
| `429` | `GuestRateLimit` (40 writes/min per guest-or-IP) | `Too many requests. Please wait a moment and try again.` + `Retry-After`, `retry_after` |
| `500` | unexpected (`report()`ed) | `Internal Server Error` |
| `404` | unknown route/item line | handler `RESOURCE_NOT_FOUND` |

---

## 5. Complete execution flow (step by step)

| # | Step | Component / file |
|---|---|---|
| 1 | User clicks **Add to Cart** | Storefront (not in this repo) |
| 2 | Click handler runs | Storefront product card / PDP component (unknown) |
| 3 | Handler builds cart line from product state | Storefront (unknown) |
| 4 | `product_id` from product object | Storefront; must equal a live `products.id` |
| 5 | `product_variant_id` from selected variant | Storefront; for `product_type='variable'` it is **required** |
| 6 | `quantity`, `shipping_method`, `attributes` collected | Storefront; `shipping_method` **required** and must be `scheduled` for guests |
| 7 | Body built as `{ item: {...} }` | Storefront |
| 8 | Headers attached: `Content-Type`, `Accept`, `X-CSRF-Token`, `X-Channel`, `lang`, `X-Currency`; cookie attached via `withCredentials` | Storefront |
| 9 | `POST /api/v1/general/guest/cart/items` (+ possibly an `OPTIONS` preflight) | Browser/HTTP client |
| 10 | `api` group: decrypt-cookie passthrough, channel, locale, request id | `Kernel.php`, `ChannelMiddleware`, `CheckLangMiddleware`, `RequestIdMiddleware` |
| 11 | Origin allowlist + CSRF double-submit + throttle | `GuestOrigin`, `GuestCsrf`, `GuestRateLimit` |
| 12 | Payload validation | `CartCreateRequest` |
| 13 | Identity lookup by `hash_hmac('sha256', cookie, APP_KEY)` | `GuestIdentityService::current` |
| 14 | Eligibility/stock/variant policy | `GuestCartService::assertEligibleItem` |
| 15 | Cart resolve-or-create (`UNIQUE(carts.guest_id)`) | `GuestCartService::ensureCart` |
| 16 | Transaction + `SELECT … FOR UPDATE` on the cart | `CartRepository::applyItemToCart` |
| 17 | Duplicate-line resolution and price snapshot | `CartRepository::syncItems` → `CartInventoryService::incrementItem` → `upsertItem` |
| 18 | Price from `ProductPricingService` (flash sale → discount → base) | `ProductPricingService::calculateVariantCurrentPrice` / `calculateProductCurrentPrice` |
| 19 | `cart_items` upsert, `carts.total_price` recompute | `CartInventoryService`, `CartRepository` |
| 20 | Retention touch + cookie refresh | `GuestCartService::touch` → `GuestIdentityService::extend` → `queueCookies` |
| 21 | `201` + `CartResource` (currency-converted) | `GuestCartController::store`, `CartResource` |
| 22 | Frontend applies response | Storefront (unknown) |
| 23 | Cart badge / drawer re-rendered | Storefront (unknown) |

---

## 6. Request A vs Request B — successful vs failing comparison

Two realistic pairs taken from the code, not invented from a capture.

### Pair 1 — product type / policy divergence (most likely real-world split)

| Field | Request A (works) | Request B (fails) |
|---|---|---|
| URL | `/api/v1/general/guest/cart/items` | same |
| Method | `POST` | `POST` |
| `Origin` | allowed | allowed |
| `Cookie meem_guest` | present | present |
| `X-CSRF-Token` | matches | matches |
| `X-Channel` | `home` | `home` |
| `item.product_id` | simple physical, `status=1`, `in_stock=1`, `item_type=PHYSICAL`, `is_fast_shipping_available=0`, active category | **digital** (`item_type=DIGITAL`) or **fast-shipping** product |
| `item.product_variant_id` | `null` | `null` |
| `item.shipping_method` | `scheduled` | `scheduled` |
| `item.quantity` | `1` | `1` |
| Status | `201` | `422` |
| Body | `success:true`, cart | `message: "Digital products are not available for guest checkout."` / `"This product is not available for guest checkout."` (G2 / G3) |
| Difference class | — | **Expected by design, but invisible in a generic storefront** |

The same Request B sent to `POST /api/v1/cart` with a Sanctum token **succeeds** for digital and for fast-shipping products (no `item_type` and no channel check in `syncItems`; `FAST` is allowed only if `is_fast_shipping_available`). ⇒ "works for me / not for guests", "works for one user, not another".

### Pair 2 — payload divergence (silent or hard failure)

| Field | Request A (works) | Request B (fails) |
|---|---|---|
| `item.shipping_method` | `"scheduled"` | **omitted** (product page sends only `product_id` + `quantity`) |
| `item.product_variant_id` | `null` (simple product) | omitted for a `product_type='variable'` product |
| Status | `201` | `422` |
| Body | success | `errors.item.shipping_method = ["The shipping method field is required."]` / `message: "Please select a product option before adding it to the cart."` |
| Difference class | — | **Client contract violation** — must be fixed in the frontend |

### Pair 3 — variant ID from the wrong product

| Field | A | B |
|---|---|---|
| `product_id` | `123` | `123` |
| `product_variant_id` | `1234` (belongs to 123) | `5678` (belongs to 456) |
| Validation | passes | **also passes** (`exists:product_variants,id` is global) |
| Backend | line created | `$product->variations()->whereKey(5678)` → null → `Exception` → `422 Invalid item data` |
| Difference class | — | Backend gap + client bug (see CR-3) |
| Response `message` | — | `Invalid item data` |

### Pair 4 — same line, second add within 60 s after 40 writes

| Field | A | B |
|---|---|---|
| Request count | 1–40 per minute per guest | 41st |
| Status | `201` | `429` + `Retry-After` |
| Difference class | — | Rate limit (`guest_cart_write` = 40/min), **not** a product problem |

---

## 7. Frontend investigation (what can and cannot be concluded)

Cannot be concluded: which component/handler builds the payload, whether errors are swallowed, whether the UI is optimistic. The storefront is not in the workspace and no bundle/network trace was supplied.

What the backend contract *forces* the frontend to do (all **CONFIRMED** by code + `docs/guest-checkout-frontend-contract.md`):

1. `withCredentials: true` on every guest call (or same-site BFF).
2. `POST /guest/session` before the first write; keep `data.csrf_token` in memory.
3. Send `X-CSRF-Token` on every cart write.
4. Send `shipping_method: "scheduled"` for guests.
5. Send `product_variant_id` for `product_type === 'variable'`.
6. Send the `X-Channel` header matching the UI section being browsed.
7. Re-init the session when `401` arrives; refresh the CSRF token when `419` arrives (retry once).
8. Treat `422` as a *product-level* rejection and surface `message` (the message is already localised via `lang`).

Symptom → culpable frontend behaviour mapping:

| Symptom | Frontend cause | Backend branch |
|---|---|---|
| All adds fail | `withCredentials` off, or wrong `Origin`, or session never created | `401` / `403` |
| All writes fail but reads work | missing/stale `X-CSRF-Token` | `419` |
| Only some products fail, consistently | missing `shipping_method`, missing variant, wrong `X-Channel` | `422` G3/G4/G5 |
| Only products from the "fast" section fail | `shipping_method: "fast"` | `422` G4 |
| Intermittent failures after many clicks | duplicate clicks / burst | `429` (40/min) |
| Cart adds but UI doesn't update | response ignored / wrong path (`data.cart`) | `201` (server fine) |
| Item disappears later | login merge, retention prune, or unavailable-at-checkout | `GuestCartMergeService`, `PruneGuestCarts` |

---

## 8. Network and error analysis playbook

Distinguish the three failure classes with the Network tab:

| Observation | Class | Meaning |
|---|---|---|
| No request in Network at all | Frontend | Handler never fired (JS error, disabled button, guard/conditional) |
| `OPTIONS` 403 | CORS/network | Origin not in `CORS_ALLOWED_ORIGINS`; `GuestOrigin` abort |
| `OPTIONS` fails, `POST` never sent | Network/CORS | Preflight rejected by browser (`Allow-Credentials`/`Allow-Headers` mismatch) |
| `POST` 401 | Session | No/expired/revoked `meem_guest` cookie, or cookie stripped (cross-site / `SameSite`) |
| `POST` 403 | Security | Foreign `Origin` |
| `POST` 419 | CSRF | `X-CSRF-Token` ≠ cookie ≠ server-derived value |
| `POST` 422 with `errors` | Validation | Payload shape (see §3.1) |
| `POST` 422 with `message` only | Business rule | G1–G6 / C1–C4 |
| `POST` 429 | Throttle | > 40 writes/min |
| `POST` 201 but cart unchanged | Logic | `operation` not `increment`/`decrement` ⇒ silent no-op (CR-6) |
| `POST` 201 and cart changed, UI stale | Frontend | Response not applied; verify `data.cart` |
| Duplicate requests same ms | Frontend | Double submit; second is a legit increment (quantity +2) or a `429` |

Backend-side evidence to collect: `storage/logs/laravel.log` (controller does `report($e)` on unexpected Throwable; `INVALID_ITEM_DATA` 422 from `CartRepository` is *not* reported), Telescope (`RequestIdMiddleware` ties the row to `X-Request-ID`), and DB verification:

```sql
SELECT c.id, c.guest_id, c.total_price, ci.product_id, ci.product_variant_id,
       ci.quantity, ci.price, ci.shipping_method
FROM carts c LEFT JOIN cart_items ci ON ci.cart_id = c.id
WHERE c.guest_id = ?;
```

---

## 9. Root-cause analysis

### Confirmed root causes (code-level, reproducible)

**CR-1 — Guest and authenticated add-to-cart have different rule sets (product-dependent).**
`GuestCartService::assertEligibleItem` rejects digital products (G2) and fast-shipping products on the `home` channel (G3), and rejects any non-`SCHEDULED` shipping method (G4). `CartRepository::syncItems` (the authenticated path) has none of G2/G3/G4. ⇒ The *same product* is addable for a logged-in customer and rejected for a guest. This alone explains "some products add, others don't" with a perfectly stable, product-correlated pattern. Evidence: `GuestCartService.php` lines in `assertEligibleItem`; `tests/Feature/Guest/GuestSessionAndCartTest::digital_products_are_rejected_for_guest_checkout`, `GuestSecurityContractTest::guest_fast_shipping_lines_are_rejected_instead_of_silently_dropped`.

**CR-2 — `shipping_method` is a required field and must be exactly scheduled for guests.**
Omission ⇒ `422` from `CartCreateRequest`; `fast`/`FAST` ⇒ `422 fast_shipping_not_supported` (G4). Frontends that build the payload from a generic "options" object often omit it. Evidence: `CartCreateRequest::rules()`, `GuestCartService::assertEligibleItem`.

**CR-3 — `product_variant_id` is validated globally, then scoped per product.**
`exists:product_variants,id` accepts any variant; `syncItems` then requires it to belong to `product_id`. A variant ID taken from a stale/wrong product object ⇒ `422 Invalid item data`. Also, a variant that exists but whose `product_id` differs is indistinguishable to the client from a generic failure. Evidence: `CartCreateRequest`, `CartRepository::syncItems` (`$product->variations()->whereKey($variantId)`).

**CR-4 — Product eligibility is stricter than the storefront usually assumes.**
`Product::scopeActive()` requires `status=1` **and** (`in_stock=1` **or** `stock-reserved>0`) **and** at least one active-or-missing category. Out-of-stock or category-disabled products can still be *listed* from a cached/paginated response, but never added. Evidence: `Product.php::scopeActive`.

**CR-5 — Variant stock is never validated at add time; product stock can be bypassed.**
`assertEligibleItem` checks only `products.stock_quantity - products.reserved_quantity`, and only when `stock_quantity > 0`:
- product stock `0`/`NULL` ⇒ check skipped (`unlimited/unknown` semantics) ⇒ can add an actually unavailable product;
- a variant with `stock_quantity = 0` is still addable as long as the parent passes.
`CartItemResource::availability()` later flags such a line `variant_unavailable`, and checkout rejects it — the customer experiences "added, then broken".
Evidence: `GuestCartService` G6; `CartItemResource::availability`; `GuestCartMergeService::knownAvailableQuantity` (which *does* treat variant stock as stricter — proof the asymmetry is unintentional).

**CR-6 — `operation` is not validated: a mistyped value silently adds nothing.**
`syncItems` performs work only for `increment`/`decrement`; otherwise it `return true`, `applyItemToCart` commits, the controller returns `201` with an unchanged cart. Any client that sends `operation: "add"`, `"set"`, `"1"`, etc. on the POST endpoint sees a false success. Evidence: `CartRepository::syncItems` (`$operation = $item['operation'] ?? CartOperation::INCREMENT;` + the `if/elseif` with no `else`).

**CR-7 — Two different `422` body contracts.**
FormRequest failures go through `App\Exceptions\Handler`: `{message, status:false, success:false, errors:{…}}`.
Business rejections go through `ApiResponse`: `{status:422, message, success:false}` (no `errors`).
A client that only reads `errors` or only reads `message` will silently "swallow" half the failures.

**CR-8 — Guest credential lifecycle can invalidate every write.**
Writes require a live identity. Revoked (`DELETE /guest/session`), expired (>7 days idle), or cookie-lost (Path `/api` mismatch when the storefront proxies differently) ⇒ every add returns `401`. `GuestCsrf` then returns `419` for any identity whose CSRF cookie/header got out of sync (e.g. cached token across a session rotation).

**CR-9 — CSRF/rate-limit failures are indistinguishable at the UI level from product failures** unless the storefront maps `401/403/419/429` to specific UX. The backend messages are distinct and localised, so the mapping is purely a frontend responsibility.

**CR-10 — Cart state can be wiped or mutated by login merge.**
`GuestCartMergeService` merges on password/social/OTP login; unavailable lines are preserved but quantity may be capped (`quantity_capped`), and a merge failure keeps the guest cart. A customer who logs in between add and view can see an item "disappear" or change quantity. Evidence: `GuestCartMergeService`, docs §5.1.

### Secondary risks found during the investigation (not the add-to-cart root cause)

- **RISK-1 (framework/throttle):** `throttle:public-api` is attached to all `v1/general` routes but the `public-api` limiter is commented out in `AppServiceProvider`. With `DISABLE_RATE_LIMITING` (currently `true` in `.env`), `BypassThrottle` short-circuits and nothing breaks. If rate limiting is ever enabled, `BypassThrottle` calls `ThrottleRequests::handle()` with 6 arguments, so the named-limiter branch is skipped and `resolveMaxAttempts('public-api')` throws `InvalidArgumentException` ⇒ `500` on public APIs. Needs verification before enabling.
- **RISK-2:** the `api` middleware group is applied twice on `v1/general` (`AddQueuedCookiesToResponse` runs twice ⇒ possibly duplicated `Set-Cookie` headers). Harmless today, but it was the reason cookies are excluded from `EncryptCookies`.
- **RISK-3:** guest cart writes are **not idempotent** (`Idempotency-Key` is ignored on cart routes). A network retry of a "successful" add increments quantity twice. The 40/min limiter is the only backpressure.

---

## 10. Recommended fixes and tests

### 10.1 Fixes

**FIX-1 (behavior alignment, product-dependent failures) — decide and document**
File: `app/Services/Guest/GuestCartService.php`, `assertEligibleItem()`.
Keep the rules (they are deliberate: guest checkout consumes only `SCHEDULED` lines and has no digital delivery path) **but make the frontend pre-empt them**:
- the storefront must hide/disable add-to-cart for `item_type === 'DIGITAL'` and for `is_fast_shipping_available === true` while on the `home` channel, or switch to the fast-shipping channel **and** send `shipping_method: "fast"` (which guests cannot use) — so in practice: hide.
- Do **not** silently relax backend rules to make the button "work"; that would corrupt checkout.

**FIX-2 (clear rejection taxonomy) — recommended, small, backend**
Return a machine-readable `reason` in guest cart rejections so the UI does not have to string-match localised text:

```php
// GuestCartController::store
} catch (\InvalidArgumentException $e) {
    return $this->apiResponse($e->getMessage(), 422, false);
}
```

Suggested (additive, non-breaking): include `data: ['reason' => $reason]` where `$reason ∈ {product_unavailable, digital_not_supported, fast_shipping_not_supported, variant_required, insufficient_stock, invalid_item_data}`. Implement by throwing a small dedicated exception carrying the reason instead of `\InvalidArgumentException`, or by passing a reason string into `apiResponse`.

**FIX-3 (variant scoping) — backend hardening**
File: `packages/marvel/src/Http/Requests/CartCreateRequest.php`.
Replace the global existence rule with a scoped one so the client gets a field-level error instead of `Invalid item data`:

```php
'item.product_variant_id' => [
    'sometimes', 'nullable', 'integer',
    Rule::exists('product_variants', 'id')->where(
        fn ($q) => $q->where('product_id', (int) $this->input('item.product_id'))
    ),
],
```

Side effects: authenticated clients sending a foreign variant now receive a validation `422` instead of a generic `422` — same status, clearer body. Keep the `syncItems` check as defence in depth.

**FIX-4 (variant stock at add time) — product decision required**
File: `app/Services/Guest/GuestCartService.php`, `assertEligibleItem()`.
For variable products, validate the variant (not just the parent) using the same semantics as `GuestCartMergeService::knownAvailableQuantity()`:

```php
if (!empty($item['product_variant_id'])) {
    $variant = $product->variations()->whereKey((int) $item['product_variant_id'])->first();
    if (!$variant) {
        throw new \InvalidArgumentException(__('guest_checkout.variant_unavailable'));
    }
    $variantStock = (int) ($variant->stock_quantity ?? 0);
    if ($variantStock > 0 && $quantity > max(0, $variantStock - (int) ($variant->reserved_quantity ?? 0))) {
        throw new \InvalidArgumentException(__('guest_checkout.insufficient_stock'));
    }
}
```

Requires a new translation key `guest_checkout.variant_unavailable` in `resources/lang/en/guest_checkout.php` and `resources/lang/ar/guest_checkout.php`.
Trade-off: stricter add-time UX; stock still re-validated at checkout (authority unchanged). If the business prefers "allow add, reject at checkout", keep current behaviour — but then the storefront must surface `is_available`/`unavailable_reason` from `CartItemResource`.

**FIX-5 (silent no-op) — backend hardening**
File: `packages/marvel/src/Database/Repositories/CartRepository.php`, `syncItems()`.
Add an explicit failure for unknown operations (or validate `operation` in `CartCreateRequest` with `Rule::in(CartOperation::getValues())`):

```php
} else {
    throw new Exception(INVALID_ITEM_DATA);
}
```

**FIX-6 (frontend contract) — storefront (not in this repo)**
Add-to-cart helper must: `withCredentials`, ensure session + CSRF, send `shipping_method: 'scheduled'`, send `product_variant_id` for variable products, echo `X-Channel`, retry once on `419` after `POST /guest/session`, and map `401/403/419/429/422` to distinct UX. Show `message` from the response as-is (already localised by `lang`).

**FIX-7 (observability) — backend (optional)**
The `catch (\Throwable $e) { report($e); }` in `GuestCartController::store` collapses every unexpected error into `Invalid item data.` with a generic 422. Log `product_id`, `product_variant_id`, `shipping_method`, `channel` and the exception class in a structured channel so production triage does not require Telescope.

### 10.2 Test cases

Existing coverage (green paths and guest security): `GuestSessionAndCartTest`, `GuestSecurityContractTest`, `GuestCartMergeTest`.

Add:

| Test | Type | Assertion |
|---|---|---|
| `add_to_cart_without_shipping_method_returns_422_with_field_error` | Validation | `422`, `errors['item.shipping_method']` present, no line created |
| `fast_shipping_method_is_rejected_for_guest` | Business | `422`, `fast_shipping_not_supported`, still 0 lines |
| `fast_shipping_product_on_home_channel_is_rejected` | Business | `422`, `product_unavailable` |
| `same_fast_product_can_be_added_in_fast_shipping_channel_with_scheduled` | Regression/parity | documents current channel behaviour |
| `variant_from_another_product_is_rejected_with_field_error` | Validation | `422` and **no** `cart_items` row |
| `variable_product_without_variant_is_rejected` | Business | `422`, `variant_required` |
| `out_of_stock_variant_is_rejected_at_add_time` (after FIX-4) | Business | `422`, `insufficient_stock` |
| `unknown_operation_does_not_return_false_success` (after FIX-5) | Bug fix | `422`, line count unchanged |
| `authenticated_and_guest_parity_matrix` | Regression | table-driven: digital / fast / variable / OOS × guest vs auth |
| `concurrent_adds_of_the_same_line_do_not_exceed_max_quantity` | Concurrency | 2 parallel adds ⇒ quantity ≤ `max_item_quantity` |
| `cart_add_after_session_revocation_returns_401` | Security | existing — keep |
| `add_with_stale_csrf_returns_419` | Security | `419`, no line created |

Manual verification checklist for a suspected product:
1. `GET /api/v1/general/products/{slug}` → confirm `product_type`, `item_type`, `is_fast_shipping_available`, `in_stock`, `stock_quantity`, variant `id`s.
2. `POST /guest/session` → confirm `csrf_token` + both cookies (`meem_guest` HttpOnly, `meem_guest_csrf` readable).
3. Replay the exact add with the same headers/body and record status + `message`.
4. If `422`, match `message` against the G1–G6 table to identify the branch.
5. If `201`, re-check `GET /guest/cart` — if the line is absent, inspect `operation` (CR-6).

---

## 11. Final technical report

### 11.1 System architecture (add-to-cart slice)

```
Storefront (external repo)
   │  POST /api/v1/general/guest/cart/items  (cookie + X-CSRF-Token + X-Channel + lang + X-Currency)
   ▼
Laravel 10 API (route prefix /api, v1/general, guest)
   │  api group (EncryptCookies, AddQueuedCookies, Bindings, RequestId, Channel, Lang)
   │  guest.origin → guest.csrf → guest.throttle
   │  CartCreateRequest
   ▼
GuestCartController ──► GuestCartService ──► GuestIdentityService (cookie ↔ HMAC ↔ guest_identities)
   │                        │
   │                        ├─ assertEligibleItem (Product::active, physical, channel, scheduled, variant, stock)
   │                        └─ ensureCart (UNIQUE carts.guest_id)
   ▼
CartRepository::applyItemToCart (DB transaction, FOR UPDATE)
   ├─ syncItems (variant scoped to product, variable rule, fast eligibility, operation)
   └─ CartInventoryService::incrementItem (upsertItem → ProductPricingService → cart_items)
   ▼
CartResource / CartItemResource (CurrencyService, PromotionService, CouponCalculator)
   ▼
201 + cookies refreshed + guest_expires_at +7d
```

### 11.2 Endpoint, payload and response — summary

- **Endpoint:** `POST /api/v1/general/guest/cart/items` (guest) / `POST /api/v1/cart` (authenticated).
- **Body:** `{"item":{"product_id","quantity","product_variant_id","shipping_method","attributes","operation"}}`.
- **Required beyond the body:** valid `meem_guest` cookie, matching `X-CSRF-Token`, allowed/absent `Origin`, `Content-Type: application/json`.
- **Response:** `201 {status,message,success,data.cart}`; failures `401/403/419/422/429/500` with localised `message` and, for validation, an `errors` map.
- **Authority:** the server re-prices and recomputes totals; client `price`/`total_price` are ignored.

### 11.3 Why some products are added and others are not — conclusion

Confirmed, code-backed causes, in order of explanatory power:

1. **Guest-only policy** (digital, fast-shipping on home channel, non-scheduled shipping) — `GuestCartService::assertEligibleItem`. Product-correlated and stable: exactly the "some products work, some never do" signature. Logged-in users hit a looser path.
2. **Required `shipping_method`** and **`variant_required`** — the two payload fields most often missing from a storefront's generic add handler.
3. **`Product::scopeActive()`** — inactive status, zero availability, or only-inactive categories make a still-listed product unaddable.
4. **Variant mismatch / variant out of stock** — global `exists` rule + no variant stock check at add time.
5. **Session/CSRF defects** (`401`/`419`) — would fail *everything*, so only consistent with "nothing adds", not with "some products".
6. **Rate limiting** (`429`) — only with bursty clicking or retry loops.

Not confirmed (needs capture): which of 1–4 fires for the specific failing products, and whether the storefront swallows the response. Missing evidence is listed in §0.4; the decisive test is §10.2 step 3–4.

### 11.4 Sequence diagram

```mermaid
sequenceDiagram
    autonumber
    participant U as User
    participant F as Storefront (external)
    participant B as Browser
    participant M as Middleware (origin/csrf/throttle)
    participant C as GuestCartController
    participant S as GuestCartService
    participant R as CartRepository
    participant I as CartInventoryService
    participant P as ProductPricingService
    participant DB as MySQL

    U->>F: click "Add to cart"
    F->>F: build {item:{product_id, quantity, product_variant_id, shipping_method}}
    F->>B: axios.post(withCredentials, X-CSRF-Token, X-Channel, lang, X-Currency)
    B->>M: OPTIONS /api/v1/general/guest/cart/items (preflight, max-age 0)
    M-->>B: 204 + CORS (exact origin, credentials)
    B->>M: POST + Cookie(meem_guest, meem_guest_csrf) + JSON body
    M->>M: Origin allowlist? CSRF double-submit? throttle 40/min?
    alt origin/CSRF/throttle fails
        M-->>B: 403 / 419 / 429
        B-->>F: error message
        F-->>U: toast
    else allowed
        M->>C: validated request (CartCreateRequest)
        C->>S: addItem()
        S->>S: assertEligibleItem() (active, physical, channel, scheduled, variant, stock)
        alt ineligible
            S-->>C: InvalidArgumentException(message)
            C-->>B: 422 {message}
        else eligible
            S->>DB: ensureCart (select / insert, UNIQUE guest_id)
            S->>R: applyItemToCart(cart, item, 'add')
            R->>DB: BEGIN; SELECT cart FOR UPDATE
            R->>I: incrementItem()
            I->>DB: lock cart; find line; 
            I->>P: current price (flash sale → discount → base)
            P-->>I: price
            I->>DB: upsert cart_items; touch activity
            R->>DB: cart.total_price = SUM(items); COMMIT
            S->>DB: guest_expires_at = +7d; identity.expires_at = +7d
            S-->>C: Cart (with items + variant attributes)
            C-->>B: 201 {success:true, data:{cart}} + refreshed cookies
            B-->>F: cart payload
            F-->>U: badge/drawer updated
        end
    end
```

---

## 12. Open questions that require external evidence

1. Exact failing and succeeding `product_id` / `product_variant_id` pairs (needed to prove which branch fires).
2. A Network capture of one success and one failure: status, `message`, `errors`, request headers, request body.
3. Whether the storefront sends `X-Channel`, and whether `shipping_method` is always included.
4. Whether the storefront calls `POST /guest/session` on mount and re-uses `data.csrf_token` across page reloads (a reload without re-init ⇒ orphaned CSRF value).
5. Whether the two request classes (guest vs logged-in) are visible to the same user in production (login state difference would confirm CR-1 as the customer-visible cause).

Until those are supplied, the confirmed backend causes above are the only defensible explanation; everything else remains a hypothesis.
