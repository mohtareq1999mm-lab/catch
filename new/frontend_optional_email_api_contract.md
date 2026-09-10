# Frontend Optional Email — REST API Contract

**Date:** 2026-09-06
**Source:** `D:\work\meem` — Laravel 10.30.1 + `packages/marvel` (Pickbazar kernel) — actual source, `git diff HEAD`, and `php artisan test` execution
**Scope:** **REST API ONLY** — `routes/api.php` + `packages/marvel/src/Rest/Routes.php`. GraphQL (`packages/marvel/src/GraphQL/**`) is intentionally out of scope and remains `email: String!`.
**Evidence levels:** `SOURCE VERIFIED` (source), `TEST VERIFIED` (automated test), `SOURCE + TEST VERIFIED` (both), `CODE VERIFIED — NOT E2E TESTED` (exists but no complete test)

---

## 1. Executive Summary

**What changed?**

`users.email` is now `NULLABLE + UNIQUE` (`2026_09_06_114430_make_users_email_nullable.php`, `doctrine/dbal 3.7.1`). A customer can be `email = NULL` and still complete the full REST lifecycle. Checkout `user_email`, invoice `customer.email`, and social provider email are now optional. MyFatoorah receives a gateway-only fallback (`order-{id}@no-email.meem.local`) via `CustomerContactResolver` that is never persisted. Social identity is now `provider + provider_user_id` (not email) with `Provider::user()` relation and shadowing fix.

**What did NOT change?**

GraphQL `RegisterInput.email: String!`, frontend `resources/js/**`, Marvel legacy payments (`Flutterwave/Paystack/Iyzico/Stripe` under `packages/marvel/src/Payment/**`), admin `AdminCreateUserRequest`/`adminToken()` email-required + verification, email-specific features (newsletter, contact forms, `POST /update-email`, `POST /change-password`), and password-reset which stays `password_resets.email NOT NULL`.

**Who is affected?**

All REST customers. Phone-only customers (`email: null`) are now first-class. Existing email customers are unaffected.

**What frontend behavior must change?**

See §31 Frontend Rules. In short: make `email`/`user_email`/`customer.email` `string | null`, remove `required` from registration/checkout email, treat `email === null` as valid (not unverified), use phone OTP path when no email, never generate fake emails, handle `data.token` envelope, and handle social login without provider email.

**What frontend behavior must NOT change?**

GraphQL, admin, payment business rules (`COD+pickup` still 422), email-specific flows.

> `email = null` is **NOT** an error state. It is a valid customer state.

---

## 2. Endpoint Matrix — REST Customer Lifecycle

| # | Endpoint | Method | Purpose | Auth Required | Email Required? | Phone Required? | Changed? |
|---|----------|--------|---------|---------------|-----------------|-----------------|----------|
| 1 | `/api/v1/register` | POST | Register customer | No (`throttle:login`) | No (`nullable|sometimes`) | Yes | Yes |
| 2 | `/api/v1/token` | POST | Login — returns Sanctum token | No (`throttle:login`) | No (`required_without:phone_number`) | No (`required_without:email`) | No — already phone-capable |
| 3 | `/api/v1/me` | GET | Current user | Yes (`auth:sanctum`) | No | No | No — now returns `email:null` |
| 4 | `/api/v1/logout` | POST | Revoke current token | Yes (`auth:sanctum`) | No | No | No |
| 5 | `/api/v1/send-otp-code` | POST | Send OTP (phone or email) | No (`throttle:otp`) | No (`required_without:phone_number`) | No (`required_without:email`) | No — branching already existed |
| 6 | `/api/v1/otp-login` | POST | Verify OTP → token | No (`throttle:otp`) | No — routes to phone `verifyOtp()` when no email | Conditional | No |
| 7 | `/api/v1/forget-password` | POST | Request password reset | No (`throttle:sensitive`) | Yes (email-only by design) | No | No |
| 8 | `/api/v1/verify-forget-password-token` | POST | Verify reset token | No (`throttle:sensitive`) | Yes | No | No |
| 9 | `/api/v1/reset-password` | POST | Reset password | No (`throttle:sensitive`) | Yes | No | No |
| 10 | `/api/v1/change-password` | POST | Change password (authed) | Yes (`auth:sanctum`) | No | No | No |
| 11 | `/api/v1/update-contact` | POST | Update phone contact via OTP | Yes (`auth:sanctum`) | No | Yes | No |
| 12 | `/api/v1/cart` | GET | List cart | Yes (`auth:sanctum`) | No | No | No |
| 13 | `/api/v1/cart` | POST | Add item | Yes (`auth:sanctum`, `throttle:cart`) | No | No | No |
| 14 | `/api/v1/general/checkout` | POST | Normal checkout → order | Yes (`auth:sanctum`, `throttle:authenticated`) | No (`nullable|sometimes`) | Yes (`user_phone` required) | Yes |
| 15 | `/api/v1/general/fast-shipping/checkout` | POST | Fast checkout | Yes (`auth:sanctum`) | No (`nullable|sometimes`) | Yes | Yes |
| 16 | `/api/v1/general/orders` | GET | List orders | Yes (`auth:sanctum`) | No | No | No |
| 17 | `/api/v1/general/orders/{id}` | GET | Show order | Yes (`auth:sanctum`) | No | No | No |
| 18 | `/api/v1/general/coupons/apply` | POST | Apply coupon | Yes (`auth:sanctum`) | No | No | No |
| 19 | `/api/v1/general/invoices/my-invoices` | GET | My invoices | Yes (`auth:sanctum`) | No | No | No |
| 20 | `/api/v1/general/invoices/verify/{uuid}` | GET | Verify invoice | Yes (`throttle:5,1`) | No | No | No |
| 21 | `/api/v1/general/invoices/view/{uuid}` | GET | PDF view (signed URL) | Signed URL (`signed` middleware) | No | No | No |
| 22 | `/api/v1/general/invoices/download/{uuid}` | GET | PDF download (signed) | Signed URL | No | No | No |
| 23 | `/api/v1/social/redirect` + `/api/v1/social/{provider}` | GET | OAuth redirect | No (`throttle:login`) | No | No | No |
| 24 | `/api/v1/social/{provider}/callback` | GET | OAuth callback | No | No (provider may return null email) | No | Yes (identity fix) |
| 25 | `/api/v1/social/exchange` | POST | Exchange code → token | No (`throttle:login`) | No | No | No |
| 26 | `/api/v1/address` | API Resource | Address CRUD | Yes (`auth:sanctum`) | No | No | No |

*Evidence: `packages/marvel/src/Rest/Routes.php` (prefix `api/v1`), `routes/api.php` (prefix `api` + `v1/general` → `/api/v1/general/*`), `php artisan route:list` (not shown) — SOURCE VERIFIED. Tests use `/api/v1/register`, `/api/v1/token`, `/api/v1/me` etc. (not `/api/register` — previous doc was wrong) — TEST VERIFIED.*

---

## 3. `POST /api/v1/register`

### Purpose
Create a customer. Identity is `users.id`; `phone_number` is the login identifier; `email` is an optional contact channel.

### Authentication
Public. `Route::middleware(['throttle:login'])`. No `auth:*`.

### Headers
```http
Content-Type: application/json
Accept: application/json
```

### Request Contract — `UserCreateRequest::rules()` — SOURCE VERIFIED

| Field | Type | Required | Nullable | Validation | Description |
|-------|------|----------|----------|------------|-------------|
| `first_name` | string | Yes | No | `required|string|max:50|min:2` | Customer first name |
| `last_name` | string | Yes | No | `required|string|max:50|min:2` | Customer last name |
| `email` | string (email) | No | Yes | `nullable|sometimes|email|unique:users,email|email:rfc,dns` | Optional. When supplied must be valid and unique among non-null emails. `sometimes` + `nullable` means both omitted and `null` are valid. |
| `phone_number` | string | Yes | No | `required|string|max:20|min:10|unique:users,phone_number` | **Required.** Primary customer identifier when email absent. |
| `password` | string | Yes | No | `required|string|min:8|max:50|confirmed` |  |
| `password_confirmation` | string | Yes | No | `required|string|min:8|max:50` | Must match `password`. |
| `avatar` | file (image) | No | Yes | `sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048` | Optional profile image |
| `policy` | string/boolean | Yes | No | `required|in:1,true` | Terms acceptance |

*Evidence: `packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38` — SOURCE VERIFIED; behavior proven by `CustomerWithoutEmailTest` + `PhoneOnlyE2ETest` — TEST VERIFIED.*

### Request Examples

**Phone-only — email omitted (preferred):**
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "phone_number": "01012345678",
  "password": "password123",
  "password_confirmation": "password123",
  "policy": "1"
}
```

**Phone-only — email explicit null (also valid):**
```json
{
  "first_name": "Jane",
  "last_name": "Doe",
  "email": null,
  "phone_number": "01011112222",
  "password": "password123",
  "password_confirmation": "password123",
  "policy": "1"
}
```

**With email (existing flow preserved):**
```json
{
  "first_name": "Alice",
  "last_name": "Smith",
  "email": "alice@gmail.com",
  "phone_number": "01011113333",
  "password": "password123",
  "password_confirmation": "password123",
  "policy": "1"
}
```

### Response Contract — `ApiResponse` trait — SOURCE VERIFIED

```php
// packages/marvel/src/Traits/ApiResponse.php
$result = ['status' => $status, 'message' => $message, 'success' => $success];
if (!empty($data)) $result['data'] = $data;
return response()->json($result, $status);
```

**Success — phone-only (no email OTP attempted):**
```http
HTTP 200
{
  "status": 200,
  "message": "User registered successfully",
  "success": true,
  "data": { "otp_status": true }
}
```
`200` for both email and no-email paths when email OTP is skipped or succeeds — envelope is always `status/message/success/data` with `data` nesting (never top-level `otp_status`).

**Success — with email but OTP email failed (201, requires resend):**
```http
HTTP 201
{
  "status": 201,
  "message": "User registered successfully",
  "success": true,
  "data": {
    "requires_resend": true,
    "email": "alice@gmail.com",
    "phone_number": "01011113333",
    "otp_status": false
  }
}
```
Note: `data.requires_resend` etc. are nested under `data`, not top-level; HTTP status `201` with `status:201` mirrored.

**Error — validation:**
```http
HTTP 422
{
  "first_name": ["..."],
  "phone_number": ["phone number is required"],
  "email": ["email must be unique"]
}
```
Note: validation errors are at the **JSON root**, not under `errors`. `assertJsonValidationErrors` with default `errors.` prefix will miss them — use `assertJsonStructure(['phone_number'])`.

| Field | Type | Nullable | Always Present? | Meaning |
|-------|------|----------|-----------------|---------|
| `status` | int | No | Yes | HTTP status mirrored |
| `message` | string | No | Yes | Translated message |
| `success` | bool | No | Yes |  |
| `data` | object | Yes | On success with OTP | `otp_status`, etc. |

### Error Contract

| Status | Cause | Frontend Action |
|--------|-------|-----------------|
| 422 | `phone_number` missing/taken, `email` invalid/taken, `password` mismatch, `policy` missing | Show field error |
| 500 | Unexpected exception (DB) | Generic error |
| 429 | `throttle:login` exceeded | Retry-After |

*Evidence: `tests/Feature/Rest/CustomerWithoutEmailTest` (13 cases) + `PhoneOnlyE2ETest` (4 registration cases) — TEST VERIFIED.*

---

## 4. `POST /api/v1/token`

### Purpose
Customer login — returns Sanctum `personal_access_token` (`tokenable_id = users.id`).

### Authentication
Public (`throttle:login`). No prior token.

### Request — `UserAuthEmailAndPasswordRequest` — SOURCE VERIFIED

| Field | Type | Required | Nullable | Validation |
|-------|------|----------|----------|------------|
| `email` | string | Conditional | No | `required_without:phone_number|email` |
| `phone_number` | string | Conditional | No | `required_without:email|string|max:15|min:8` |
| `password` | string | Yes | No | `required|string|min:6` |

At least one of `email` or `phone_number` must be present. Phone-only customers send `phone_number`.

**Phone-only example:**
```json
{ "phone_number": "01012345678", "password": "password123" }
```

**Email example:**
```json
{ "email": "alice@gmail.com", "password": "password123" }
```

### Response — SOURCE + TEST VERIFIED

```http
HTTP 200
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "1|a1b2c3...",
    "email_verified": false,
    "permissions": ["..."],
    "role": ["customer"],
    "expires_at": "2026-09-13T..."
  }
}
```
Token is at **`data.token`** (not `token` top-level). `email_verified` is `hasVerifiedEmail()` — `false` when `email_verified_at === null` even if `email === null`; frontend must not treat this as a block for phone-only users.

| Status | Meaning |
|--------|---------|
| 200 | Success |
| 404 | `INVALID_CREDENTIALS` (`email/phone` or `password` wrong, or `is_active == false`) |
| 422 | Validation |
| 429 | Throttle |

---

## 5. `GET /api/v1/me`

### Purpose
Current authenticated user.

### Authentication
Yes — `auth:sanctum`. Header `Authorization: Bearer {token}`.

### Response — `Marvel\Http\Resources\UserResource` via `ApiResponse` envelope — SOURCE VERIFIED

Envelope is `{"status":200,"message":"...","success":true,"data":{...}}` — all user fields are nested under `data`, never top-level. `status` mirrors HTTP status, `message` is translated via `ApiResponse::translateNotice`, `success` is bool, `data` omitted when `empty($data)`.

```json
{
  "status": 200,
  "message": "User profile retrieved successfully",
  "success": true,
  "data": {
    "id": 123,
    "name": "John Doe",
    "email": null,
    "email_verified_at": null,
    "is_active": true,
    "image": null,
    "type": "user",
    "phone_number": "01012345678",
    "created_at": "2026-09-06T...",
    "updated_at": "2026-09-06T...",
    "roles": [],
    "permissions": [],
    "address": []
  }
}
```
Full `UserResource::toArray` fields: `id`, `name`, `email`, `email_verified_at`, `is_active`, `image` (`getFirstMediaUrl('user-image') ?: null`), `type`, `phone_number`, `created_at`, `updated_at`, `roles` (`RoleResource::collection` whenLoaded), `permissions` (`PermissionResource::collection` via `getPermissionsViaRoles()`), `address` (`whenLoaded('address')`). `image` is `string | null`; `roles`/`permissions`/`address` are arrays (empty when not loaded).

| Field | Type | Nullable | Always Present? | Notes |
|-------|------|----------|-----------------|-------|
| `id` | int | No | Yes |  |
| `email` | string | Yes (`null`) | Yes | `null` for phone-only |
| `email_verified_at` | string (datetime) | Yes (`null`) | Yes |  |
| `phone_number` | string | Yes (`null` for admin, required for customer) | Yes |  |
| `image` | string \| null | Yes | Yes | `null` when no media |
| `roles` | array | No | Yes | May be `[]` |
| `permissions` | array | No | Yes | May be `[]` |
| `address` | array | No | When loaded | `whenLoaded('address')` |

*Evidence: `packages/marvel/src/Http/Resources/UserResource.php:12-26` + `packages/marvel/src/Traits/ApiResponse.php:8-15` + `CustomerWithoutEmailTest::user_without_email_can_access_me_endpoint` — SOURCE + TEST VERIFIED.*

**Frontend User model (recommended):**
```ts
type User = {
  id: number
  name: string
  email: string | null
  email_verified_at: string | null
  phone_number: string | null
  is_active: boolean
  image: string | null
  type: string
  created_at: string
  updated_at: string
  roles: Role[]
  permissions: Permission[]
  address: Address[]
}
```

---

## 6. OTP

### 6.1 `POST /api/v1/send-otp-code` — `UserController::sendUserOtp` — SOURCE VERIFIED

**Auth:** No (`throttle:otp`, 3/min/IP).

**Request:**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `email` | string | Conditional | `required_without:phone_number|email` |
| `phone_number` | string | Conditional | `required_without:email|string|max:15|min:11` |

Branching: `if ($request->email)` → email OTP (`createOneTimePassword()` + `OneTimePasswordNotification` via `emails.one-time-passwords`), else phone OTP (`OtpGateway::startVerification($phone_number)` → `Result` with `getId()`).

**Success (phone):**
```http
HTTP 200
{ "status": 200, "message": "OTP sent successfully", "success": true, "data": { "otp_id": "local-verification-id" } }
```
`otp_id` is the gateway's `Result::getId()` (`local-verification-id` for `LocalGateway`).

**Error:**
| Status | Cause |
|--------|-------|
| 404 | `USER_NOT_FOUND` (no active user with that email/phone) |
| 201 | `ACCOUNT_CREATED_BUT_OTP_FAILED` (email OTP `notify()` threw) |
| 422 | Validation |

### 6.2 `POST /api/v1/otp-login` — `UserController::otpLogin` — SOURCE VERIFIED

Routes to `verifyLoginOtp` when `has("email")`, else phone `verifyOtp()`.

**Phone OTP request:**
```json
{ "phone_number": "01012345678", "otp_id": "local-verification-id", "code": "123456" }
```

**Email OTP request:**
```json
{ "email": "user@gmail.com", "code": "123456" }
```

**Success (phone, when `verifyOtp()` returns valid):**
```http
HTTP 200
{ "status": 200, "message": "User logged in successfully", "success": true, "data": { "token": "2|..." } }
```

**Evidence level:** Phone branch routing is `TEST VERIFIED` (`send_otp_by_phone...`); full `send → verify → token` with a mocked-valid gateway is `CODE VERIFIED — NOT E2E TESTED` (LocalGateway's `checkVerification` returns `valid=false` for any code in test env — the gateway fallback is correct and no `Mail::to(null)` occurs, but a mocked-valid verify is not in the suite).

---

## 7. Password Reset (email-only by design)

| Endpoint | Method | Auth | Route | Purpose |
|----------|--------|------|-------|---------|
| `/api/v1/forget-password` | POST | No (`throttle:sensitive`) | `UserController::forgetPassword` | `findByField('email', $request->email)` → `password_resets.email`; if not found returns `CHECK_INBOX…` 200 without leaking existence |
| `/api/v1/verify-forget-password-token` | POST | No | `verifyForgetPasswordToken` | `email: required|email`, `otp: required|string` → `checkResetToken()` |
| `/api/v1/reset-password` | POST | No | `resetPassword` | `email: email|required`, `otp: required`, `password: required|confirmed` → `password_resets` delete |

Phone-only users: `forget-password` with no `email` → `where email = NULL` → `0` rows → generic 200 (no `Mail::to(null)`, no `NULL` inserted into `password_resets.email` which stays `NOT NULL INDEX`). Email reset is unavailable to phone-only users — intentional, documented. Phone OTP reset is independent where existing.

---

## 8. Email Verification

**Correct semantics (FIXED):**

```php
// VirifiyEmailMiddleware.php:20 — SOURCE VERIFIED
if ($request->user()->email !== null && $request->user()->email_verified_at === null) {
    return apiResponse(PLEASE_VERIFY_YOUR_EMAIL, 401, false);
}
```

- `email = null, email_verified_at = null` → **NOT blocked** (phone-only).
- `email != null, email_verified_at = null` → blocked where middleware is used.

**Middleware attachment:** `VirifiyEmailMiddleware` (typo) and Marvel `EnsureEmailIsVerified` (logic commented out → `return $next`) are **not attached to any REST customer route** (`routes/*.php`, `Rest/Routes.php` have no `verified`/`VirifiyEmail` middleware). `adminToken()` keeps its own inline `hasVerifiedEmail()` gate for admin only.

**Frontend rule:**
```ts
const hasUnverifiedEmail = user.email !== null && user.email_verified_at === null
const isPhoneOnlyUser = user.email === null
if (hasUnverifiedEmail) { /* show verify */ }
if (isPhoneOnlyUser) { /* never show verify */ }
```

---

## 9. Cart

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `/api/v1/cart` | GET | Yes (`auth:sanctum`) | List cart |
| `/api/v1/cart` | POST | Yes (`auth:sanctum`, `throttle:cart` 20/min) | Add item `{ item: { product_id, quantity, shipping_method } }` |

Identity is `carts.user_id` (FK to `users.id`), never email. Cart `status` is `active`.

**Phone-only:** `POST /api/v1/cart` → `201` (or `200`), `GET /api/v1/cart` → `success:true` — **TEST VERIFIED** (`phone_only_user_can_add_and_read_cart`).

---

## 10. Normal Checkout — `POST /api/v1/general/checkout`

**Auth:** Yes (`auth:sanctum`, `throttle:authenticated`).

**Request — `OrderCreateRequest::rules()` — SOURCE VERIFIED:**

| Field | Type | Required | Validation | Notes |
|-------|------|----------|------------|-------|
| `name` | string | Yes | `required|string|max:255` | Customer name snapshot |
| `user_phone` | string | Yes | `required|string|max:255` | Snapshot |
| `user_email` | string | No | `nullable|sometimes|email|max:255` | **Optional — `null` valid** |
| `address` | array | Conditional | `Rule::requiredIf(cartHasPhysicalItems && fulfillment_type !== pickup), nullable|array` | Required for physical `delivery`; not for pickup |
| `governorate_id` | int | Conditional | `Rule::requiredIf(requiresShipping && fulfillment_type === delivery), integer|exists:governorates,id` | Required for physical `delivery` |
| `pickup_location_id` | int | Conditional | `nullable|integer|Rule::requiredIf(fulfillment_type===pickup)|exists:pickup_locations,id` | Required for `pickup` |
| `fulfillment_type` | string | No | `nullable|string|in:delivery,pickup` (or `pickup` only for `pay_at_cashier`) | Default `delivery` |
| `payment_method` | string | No | `nullable|string|in:online,cod,pay_at_cashier` | Default `online` |
| `gateway` | string | No | `nullable|string|max:50` | e.g. `myfatoorah` for `online` |
| `selected_promotion_id` | int | No | `nullable|integer|exists:promotions,id` | |
| `selected_gift_product_id` | int | No | `nullable|integer|exists:products,id` | |

**Business rule (unchanged):** `COD + pickup` → `422 COD_NOT_AVAILABLE_FOR_PICKUP` (use `pay_at_cashier` for pickup). Frontend must handle this existing error as not email-related.

**Phone-only example (delivery + COD):**
```json
{
  "name": "Phone Only Buyer",
  "user_phone": "01012345678",
  "user_email": null,
  "address": { "street": "Test Street", "city": "Cairo", "country": "Egypt" },
  "governorate_id": 1,
  "fulfillment_type": "delivery",
  "payment_method": "cod"
}
```

**Success (COD):**
```http
HTTP 200
{ "status": 200, "message": "Checkout successful", "success": true, "data": { "order_id": 123 } }
```
Order is created with `orders.user_id = auth()->id()`, `orders.user_email = null`.

**Validation error:** `422` with field-keyed errors at JSON root (not under `errors`).

*Evidence: `OrderCreateRequest.php`, `OrderController::checkout`, `OrderCreationService`, `PhoneOnlyE2ETest` (delivery + null email) — SOURCE + TEST VERIFIED.*

---

## 11. Fast Checkout — `POST /api/v1/general/fast-shipping/checkout`

Same `user_email` rule: `nullable|sometimes|email|max:255` (`FastCheckoutRequest`). `governorate_id: required|integer|exists:governorates,id`. Phone-only `governorate_id + address + null email` passes — **TEST VERIFIED** (`phone_only_fast_checkout_with_null_email`).

---

## 12. Orders

| Endpoint | Method | Auth |
|----------|--------|------|
| `/api/v1/general/orders` | GET | Yes |
| `/api/v1/general/orders/{id}` | GET | Yes |

**Order model (actual):**

```php
// Marvel\Database\Models\Order — SOURCE VERIFIED
$table->foreignId('user_id')->constrained('users')
$table->string('user_email')->nullable()   // via migration/test schema — nullable since phone-only
// + governorate() BelongsTo (added for InvoiceSnapshotService)
```

**OrderResource (actual) serializes** `user_id`, `user_email` as stored (may be `null`). Frontend model:
```ts
type Order = { id: number; user_id: number; user_email: string | null; ... }
```

---

## 13. Payments

### COD / Cashier

No email dependency. `PaymentCheckoutHandler::handleCodPayment` / `handleCashierQrPayment` create `transactions` with `user_id`, `order_id`, `amount`, `currency` — no email field.

### Online — MyFatoorah via `PaymentGatewayFactory` → `MyFatoorahGateway`

REST online checkout calls `PaymentCheckoutHandler::handleOnlinePayment` → `PaymentGatewayFactory::make('myfatoorah')` → `MyFatoorahGateway::createInvoice(Order, amount, callbackUrl, errorUrl)`.

`MyFatoorahGateway` now:
```php
private CustomerContactResolver $customerContactResolver;
'CustomerEmail' => $this->customerContactResolver->emailForGateway($order)
// CustomerContactResolver::emailForGateway:  order.user_email ?? order.user?.email  else  order-{id}@no-email.meem.local
```
Fallback is **not persisted** (`users.email` and `orders.user_email` remain `null` after mocked `createInvoice`).

**Real external HTTP:** `NOT EXECUTED` — `MyfatoraService` is mocked (`createInvoice` returns `InvoiceURL/InvoiceId`); the unit resolver + mocked gateway prove the boundary is correct without hitting MyFatoorah's API.

**Frontend rule:** Do not generate, store, display, or send the fallback. Send the real customer email only if the user has one; otherwise send `user_email: null` and let the backend derive the gateway contact.

---

## 14. Coupons

Coupon identity is `coupon_assignments.user_id` / `coupon_usages.user_id`, never email.

**Phone-only:** `phone_only_user_can_use_normal_coupon` and `phone_only_user_can_use_assigned_coupon` both checkout with `user_email: null` and verify `order.coupon` and `order.user_id` — **TEST VERIFIED** (assigned coupon via `CouponAssignment`).

---

## 15. Invoices

| Endpoint | Method | Auth | Notes |
|----------|--------|------|-------|
| `/api/v1/general/invoices/my-invoices` | GET | Yes | Customer invoices |
| `/api/v1/general/invoices/verify/{uuid}` | GET | Yes (`throttle:5,1`) | Verify |
| `/api/v1/general/invoices/view/{uuid}` | GET | Signed URL (`signed` middleware) | PDF view |
| `/api/v1/general/invoices/download/{uuid}` | GET | Signed URL | PDF download |
| `/api/v1/general/orders/{orderId}/invoice` | GET | Yes | Order invoice |

**Snapshot:** `InvoiceSnapshotService::buildFullSnapshot(Order)` always emits `customer: { id: order.user_id, name, email: order.user_email, phone }`. With null email, `email: null`.

**Validator:** `StructureValidator::REQUIRED_CUSTOMER_KEYS` is now `[id, name, phone]` (email optional). Other validators do not reference email.

**Views:** `resources/views/pdf/invoice.blade.php` uses `{{ $customer['email'] ?? '' }}`, order email blads use `@if(isset($customer['email']))` — null renders as empty.

**Phone-only invoice:** real order `user_email: null` → snapshot → validator → view snippet (`customer.email ?? ''`) — **TEST VERIFIED** (`invoice_generation_with_null_email_and_pdf`). Full `pdf.invoice` bytes with a real `Invoice` model for phone-only order is exercised in `InvoiceLifecycleTest` only for email-bearing orders — phone-only snapshot+view is proven.

---

## 16. Social Login

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `/api/v1/social/redirect` (`/api/v1/social/{provider}`) | GET | No (`throttle:login`) | `SocialController::redirect` → `Socialite::driver($provider)->redirect()` |
| `/api/v1/social/{provider}/callback` | GET | No | `SocialController::callback` — `Socialite::driver($provider)->user()` → `firstOrCreate` by provider identity |
| `/api/v1/social/exchange` | POST | No (`throttle:login`) | `SocialController::exchange` — `SocialLoginExchangeRequest` (`code: required|string`) → `SocialLoginCode` → `personal_access_token` |

**Canonical identity:** `providers (provider, provider_user_id, user_id)` — **not email**. Fixed: `$providerName` capture before shadowing, `Provider::user(): BelongsTo` added.

| Case | Request | Expected | Result |
|------|---------|----------|--------|
| Provider returns email (`user@gmail.com`, id `X`) | Link to existing `users.email` user or create + link `providers: google/X` | `test_callback_creates_user_and_issues_single_use_code` | TEST VERIFIED |
| Provider returns no email (`email:null`, id `Y`) | Create `users.email = null`, `providers: google/Y` | `test_callback_creates_user_without_provider_email` | TEST VERIFIED |
| Repeat login same `provider+provider_user_id` | Same user, no duplicate | `test_callback_repeat_login_returns_same_user` | TEST VERIFIED |

`UserController::socialLogin()` (`social-login-token`) still uses `firstOrCreate(['email'=>getEmail()])` — it is **GraphQL-only** (`SocialLoginInput` mutation), intentionally unchanged.

---

## 17. Password Reset

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `/api/v1/forget-password` | POST | No (`throttle:sensitive`) | `UserController::forgetPassword` — `findByField('email', $request->email)` |
| `/api/v1/verify-forget-password-token` | POST | No | `verifyForgetPasswordToken` |
| `/api/v1/reset-password` | POST | No | `resetPassword` |

Phone-only users: `forget-password` with no `email` → `where email = NULL` → `0` rows (MySQL/SQLite `= NULL` never matches) → generic `200 "Check your inbox"` before any `updateOrInsert`/`Mail::to`. No `Mail::to(null)` and no `NULL` inserted into `password_resets.email` which stays `NOT NULL INDEX`. Email reset is unavailable to phone-only users — intentional, documented. Phone OTP reset is independent where existing.

---

## 18. Complete Flows

### Flow A — Email customer (preserved)

```
POST /api/v1/register { phone, email: alice@gmail.com, ... } → 200 {"status":200,"message":"...","success":true,"data":{"otp_status":true}} / 201 {"status":201,"success":true,"data":{"requires_resend":true,"email":"alice@gmail.com","phone_number":"...","otp_status":false}} if email OTP fails
  ↓ (email OTP branch: sendOneTimePassword)
POST /api/v1/token { email + password } → 200 {"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":false,"permissions":[...],"role":[...],"expires_at":"..."}}
  ↓
GET /api/v1/me → 200 {"status":200,"success":true,"data":{"id":...,"name":"...","email":"alice@gmail.com","email_verified_at":"...","is_active":true,"image":null,"type":"user","phone_number":"...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[...]}} 
  ↓
POST /api/v1/cart → 201/200
GET /api/v1/cart → 200
  ↓
POST /api/v1/general/checkout { user_email: alice@gmail.com, ... delivery ... } → 200 order_id
  ↓
Order: user_id = me.id, user_email = alice@gmail.com
  ↓
Invoice: customer.email = alice@gmail.com → validator → PDF {{ $customer['email'] ?? '' }}
  ↓
COD or MyFatoorah (CustomerEmail = alice@gmail.com, persisted)
```

### Flow B — Phone-only customer (new, TEST VERIFIED)

```
POST /api/v1/register { phone, password, ... } (no email) → 200 {"status":200,"message":"...","success":true,"data":{"otp_status":true}} (if(email) guard skipped, no Mail::to(null))
  ↓
POST /api/v1/token { phone_number + password } → 200 {"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":false,"permissions":[...],"role":[...],"expires_at":"..."}} — note data.token not top-level token
  ↓
GET /api/v1/me → 200 {"status":200,"success":true,"data":{"id":...,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"010...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[...]}} 
  ↓
POST /api/v1/send-otp-code { phone_number } → 200 {"status":200,"success":true,"data":{"otp_id":"local-verification-id"}} (phone branch, via OtpGateway)
  ↓
POST /api/v1/cart → 201 + GET /api/v1/cart → 200 (user_id identity)
  ↓
POST /api/v1/general/checkout { user_email: null, address, governorate_id, delivery, cod } → 200 {"status":200,"success":true,"data":{"order_id":123}}
  ↓
Order: user_id = me.id, user_email = null (users.email stays null)
  ↓
Invoice: customer.email = null → StructureValidator (now [id,name,phone]) → view {{ $customer['email'] ?? '' }} → empty
  ↓
COD (no email) or MyFatoorah (CustomerEmail = order-{id}@no-email.meem.local via CustomerContactResolver, never persisted)
```

### Flow C — Social with / without email

```
GET /api/v1/social/redirect?provider=google → GET /api/v1/social/google/callback (Socialite)  // also GET /api/v1/social/{provider} with provider path param
  provider returns email → firstOrCreate by email or link to existing
  provider returns null  → User::create(email: null), providers: google/id
  repeat same provider+id → same user (TEST VERIFIED)
  → SocialLoginCode → POST /api/v1/social/exchange { code } → 200 {"status":200,"success":true,"data":{"token":"1|...","expires_at":"...","permissions":[...],"role":[...]}} + Bearer
```

---

## 19. Frontend Rules

1. `email` is `string | null` (not required, not `email?`).
2. `phone_number` remains required for normal customer registration.
3. `email = null` is a valid customer state — not unverified, not incomplete.
4. `email = null` does NOT mean email is unverified — distinguish `hasUnverifiedEmail = email !== null && email_verified_at === null` vs `isPhoneOnlyUser = email === null`.
5. Phone-only users use phone-based auth/OTP paths.
6. Cart/order/coupon identity is `user_id`, not email.
7. Social identity is `provider + provider_user_id`, not email.
8. MyFatoorah fallback is backend-only — never generate/persist/display it.
9. Never generate fake emails (`phone@example.com`, `user-{id}@no-email…`).
10. Existing email users must continue working.

---

## 20. Nullable Fields Reference

| Field | Old assumption | New type | Always present? |
|-------|---------------|----------|-----------------|
| `users.email` | `string` required | `string | null` | Yes (may be `null`) |
| `email_verified_at` | `string` | `string | null` | Yes |
| `orders.user_email` | `string` required | `string | null` | Yes |
| `Invoice customer.email` | `string` required | `string | null` | Yes (`customer` always has `email` key, now nullable) |
| Social `provider.email` | `string` | `string | null` | Yes (may be `null`) |
| `UserResource.email` | `string` | `string | null` | Yes |

---

## 21. Evidence Levels

| Area | Level | How proven |
|------|-------|------------|
| Migration `users.email` nullable-unique | SOURCE + TEST VERIFIED | `2026_09_06_114430…`, `CreatesTestTables`, `database_allows_multiple_null_emails` + `database_enforces_unique…` |
| Register without/null email | SOURCE + TEST VERIFIED | `UserCreateRequest` rules + `register()` guard + `CustomerWithoutEmailTest` + `PhoneOnlyE2ETest` |
| Phone login + `/me` | SOURCE + TEST VERIFIED | `UserAuthEmailAndPasswordRequest`, `UserController::token/me`, `user_without_email_can_login_by_phone`, `user_without_email_can_access_me_endpoint` |
| Phone OTP routing | SOURCE + TEST VERIFIED (send) / CODE VERIFIED (verify) | `sendUserOtp` branching + `send_otp_by_phone…` |
| Email verification | SOURCE + TEST VERIFIED | `VirifiyEmailMiddleware` + direct invocation test |
| Cart | SOURCE + TEST VERIFIED | `CartController`, `phone_only_user_can_add_and_read_cart` |
| Checkout / Order | SOURCE + TEST VERIFIED | `OrderCreateRequest`/`FastCheckoutRequest` + `OrderCreationService` + `phone_only_user_can_checkout_cod…` |
| Coupon | SOURCE + TEST VERIFIED | `CouponAssignment`, `phone_only_user_can_use_normal_coupon` + assigned |
| Invoice snapshot/validator | SOURCE + TEST VERIFIED | `InvoiceSnapshotService` + `StructureValidator` + `invoice_*` tests |
| PDF customer email | CODE VERIFIED — PARTIAL TEST | `{{ $customer['email'] ?? '' }}` + view snippet test; full `Invoice` → PDF bytes for phone-only not exercised as a PDF-gen job |
| MyFatoorah fallback | SOURCE + TEST VERIFIED (mocked) | `CustomerContactResolver` unit + mocked `MyfatoraService`; persistence re-checked `null` |
| Real MyFatoorah HTTP | NOT EXECUTED | Intentionally mocked — documented `Real gateway: NOT EXECUTED` |
| Social with/without email + repeat | SOURCE + TEST VERIFIED | `SocialController` (fixed) + `Provider::user()` + 3 `SocialLoginFlowTest` cases |
| Password reset | SOURCE VERIFIED — CODE ONLY | No `NULL` inserted, `Mail::to(null)` not reachable |

---

## 22. Final Endpoint Reference (compact)

```
AUTH
├── POST   /api/v1/register                          → 3.  email nullable — UserCreateRequest: nullable|sometimes|email|unique:users,email|email:rfc,dns ; phone required
├── POST   /api/v1/token                             → 4.  phone or email + password → {"status":200,"success":true,"data":{"token":"1|...","email_verified":bool,"permissions":[...],"role":[...],"expires_at":"..."}} — note data.token not top-level
├── GET    /api/v1/me                                → 5.  auth:sanctum → {"status":200,"success":true,"data":{"id":...,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[...]}} — UserResource via ApiResponse
├── POST   /api/v1/logout                            →      auth:sanctum
├── POST   /api/v1/send-otp-code                     → 6.  phone or email → {"status":200,"success":true,"data":{"otp_id":"..."}}
├── POST   /api/v1/otp-login                         → 6.  phone verifyOtp / email verifyLoginOtp → {"status":200,"success":true,"data":{"token":"..."}}
├── POST   /api/v1/forget-password                   → 7.  email-only (password_resets.email NOT NULL)
├── POST   /api/v1/verify-forget-password-token      → 7.
├── POST   /api/v1/reset-password                    → 7.
├── POST   /api/v1/change-password                   →      auth:sanctum
├── POST   /api/v1/update-contact                    →      auth:sanctum (phone via OTP)
└── SOCIAL
    ├── GET    /api/v1/social/redirect               → 16. throttle:login
    ├── GET    /api/v1/social/{provider}             → 16. throttle:login
    ├── GET    /api/v1/social/{provider}/callback    → 16. provider+id identity, null email supported
    └── POST   /api/v1/social/exchange               → 16. throttle:login — code → token

CART
├── GET    /api/v1/cart                              → 9. auth:sanctum
└── POST   /api/v1/cart                              → 9. auth:sanctum, throttle:cart

CHECKOUT
├── POST   /api/v1/general/checkout               → 10. user_email: nullable
└── POST   /api/v1/general/fast-shipping/checkout → 11.

ORDERS
├── GET    /api/v1/general/orders                 → 12.
└── GET    /api/v1/general/orders/{id}            → 12.

COUPONS
├── POST   /api/v1/general/coupons/apply          → 14. user_id identity
└── (assigned via coupon_assignments.user_id)     → 14.

INVOICES
├── GET    /api/v1/general/invoices/my-invoices   → 15.
├── GET    /api/v1/general/invoices/verify/{uuid} → 15.
├── GET    /api/v1/general/invoices/view/{uuid}   → 15. (signed)
└── GET    /api/v1/general/invoices/download/{uuid} → 15. (signed)

PAYMENTS
├── COD / Cashier via checkout payment_method      → 13.
└── ONLINE via MyFatoorahGateway → CustomerContactResolver → gateway-only fallback → 13.

ADDRESS
└── /api/v1/address (apiResource)                 → auth:sanctum — Marvel Rest/Routes: Route::apiResource('address', AddressController::class)
```

---

*Generated from actual source at `D:\work\meem` — `git diff HEAD` + `php artisan test` (90 targeted + 34 invoice tests pass). No GraphQL changes.*
