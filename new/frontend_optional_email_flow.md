# Frontend Optional Email — Complete Flows

**Date:** 2026-09-06
**Source:** `D:\work\meem` — actual `routes/api.php`, `packages/marvel/src/Rest/Routes.php`, `UserController`, `SocialController`, `OrderController`, `Invoice` services, and `php artisan test` (90 targeted + 34 invoice tests pass)
**Scope:** REST only. GraphQL remains `email: String!`.

---

## 1. How to Read This Document

Each flow shows the exact frontend sequence: screen → request → backend → response → next screen.

Where backend behavior is proven by automated tests, the flow is marked `TEST VERIFIED`. Where it is proven by source only (no complete E2E test or external HTTP not executed), it is marked `CODE VERIFIED`.

---

## 2. Register

### 2.1 Register — phone-only (new, TEST VERIFIED)

```text
Register Screen
  │
  ├── Phone *          ──────────────────┐
  ├── Email (Optional)                    │
  ├── Password *                          │
  ├── Confirm Password *                  │
  └── Policy *                            │
                                         ▼
                              POST /api/v1/register
                              Content-Type: application/json
                              {
                                "first_name": "John",
                                "last_name": "Doe",
                                "phone_number": "01012345678",
                                "password": "password123",
                                "password_confirmation": "password123",
                                "policy": "1"
                              }
                              // email omitted is preferred; { "email": null } also valid (nullable|sometimes)
                                         │
                                         ▼
                              Backend: UserCreateRequest validates
                                email: nullable|sometimes|email|unique:users,email|email:rfc,dns
                                phone_number: required|unique:users,phone_number
                              Backend: UserRepository::create { name, email: null, phone_number, password, type:user }
                              Backend: if (user.email) sendOneTimePassword() else skip (no Mail::to(null))
                                         │
                                         ▼
                              HTTP 200
                              {
                                "status": 200,
                                "message": "User registered successfully",
                                "success": true,
                                "data": { "otp_status": true }
                              }
                              // DB: users.email = NULL, phone_number = 01012345678
                                         │
                                         ▼
                              Frontend: store local state, proceed to Login
                              Do NOT redirect to "Verify your email" (email === null)
```

*Evidence: `UserCreateRequest.php:33`, `UserController::register:668-686`, `CustomerWithoutEmailTest` (13) + `PhoneOnlyE2ETest` (register with/without email) — SOURCE + TEST VERIFIED.*

### 2.2 Register — with email (preserved)

Same flow, but `{ email: "alice@gmail.com" }` is included. Backend stores `users.email = alice@gmail.com` and executes the email OTP branch (`sendOneTimePassword()` → OTP email via `emails.one-time-passwords`). Response is `{"status":200,"message":"...","success":true,"data":{"otp_status":true}}` on success; if mail fails: `201 {"status":201,"success":true,"data":{"requires_resend":true,"email":"alice@gmail.com","phone_number":"...","otp_status":false}}` — note `data` nesting, not top-level. **TEST VERIFIED** (`register_with_valid_email_still_works`, `AuthenticationTest`).

### 2.3 Register — error cases

| Status | Cause | Frontend |
|--------|-------|----------|
| 422 `phone_number` | missing/taken | Show field error |
| 422 `email` | invalid/taken (`unique:users,email` among non-null) | Show field error only if email was supplied |
| 422 `password` | mismatch/weak | Show field error |

---

## 3. Login

### 3.1 Phone login — phone-only customer (TEST VERIFIED)

```text
Login Screen
  │
  ├── Phone: 01012345678
  └── Password: ••••••
                │
                ▼
        POST /api/v1/token
        Content-Type: application/json
        { "phone_number": "01012345678", "password": "password123" }
        // Request is UserAuthEmailAndPasswordRequest:
        //   email: required_without:phone_number|email
        //   phone_number: required_without:email|string|max:15|min:8
                │
                ▼
        Backend: User::where('email',…)->orWhere('phone_number',…) + Hash::check
        Backend: adoptGuestCurrencyOnLogin + createToken('auth_token', [], +14 weekdays)
                │
                ▼
        HTTP 200
        {
          "status": 200,
          "message": "User logged in successfully",
          "success": true,
          "data": {
            "token": "1|a1b2c3...",
            "email_verified": false,
            "permissions": [...],
            "role": ["customer"],
            "expires_at": "..."
          }
        }
        // data.token is the Sanctum personal_access_token (tokenable_id = users.id)
                │
                ▼
        Frontend: persist data.token as Bearer, call GET /me
```

Do **not** assume `response.token` — envelope is `data.token` via `ApiResponse`.

### 3.2 Email login — existing email customer

Same `POST /api/v1/token` with `{ email: "alice@gmail.com", password }` → same `{"status":200,"success":true,"data":{"token":"1|...","email_verified":false,"permissions":[...],"role":[...],"expires_at":"..."}}` shape — `data.token` not top-level `token`.

### 3.3 `GET /me` (TEST VERIFIED)

```http
GET /api/v1/me
Authorization: Bearer [REDACTED:Authorization header]
Accept: application/json
```

```http
HTTP 200 — phone-only
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
// Full envelope: status/message/success top-level, all user fields nested under data (never top-level). Via Marvel\Http\Resources\UserResource + ApiResponse.

HTTP 200 — email customer
{
  "status": 200,
  "message": "User profile retrieved successfully",
  "success": true,
  "data": { "id": 123, "name": "Alice", "email": "alice@gmail.com", "email_verified_at": "2026-09-06T...", "is_active": true, "image": null, "type": "user", "phone_number": "01011113333", "created_at": "...", "updated_at": "...", "roles": [...], "permissions": [...], "address": [...] }
}
```

Resource: `Marvel\Http\Resources\UserResource` (`id`, `name`, `email`, `email_verified_at`, `is_active`, `image`, `type`, `phone_number`, `created_at`, `updated_at`, `roles`, `permissions`, `address`). All under `data`. Frontend model must be `email: string | null`.

---

## 4. Email Verification State Machine

```text
                 /me response
                      │
           ┌──────────┴──────────┐
           │                     │
     email !== null        email === null
           │                     │
     ┌─────┴─────┐               ▼
     │           │         isPhoneOnlyUser = true
  verified    unverified       │
     │           │               ▼
     │           ▼         NO verification UI
     │     hasUnverifiedEmail  (do not show "Verify Email")
     │           │
     ▼           ▼
 Normal     Show verify
```

```ts
const isPhoneOnlyUser = user.email === null
const hasUnverifiedEmail = user.email !== null && user.email_verified_at === null
```

`VirifiyEmailMiddleware` now `if (email !== null && email_verified_at === null)` — phone-only never blocked. Middleware is not attached to any REST customer route. **TEST VERIFIED** (direct invocation).

---

## 5. OTP

### 5.1 Phone OTP — send

```http
POST /api/v1/send-otp-code
Content-Type: application/json
{ "phone_number": "01012345678" }
```

**Validation:** `email: required_without:phone_number|email`, `phone_number: required_without:email|string|max:15|min:11`. Phone-only sends phone's `otp_id`.

**Success (phone):**
```http
HTTP 200
{ "status": 200, "message": "OTP sent successfully", "success": true, "data": { "otp_id": "local-verification-id" } }
```
**Error:** `404 USER_NOT_FOUND`, `422` validation.

### 5.2 Phone OTP — verify → token

```http
POST /api/v1/otp-login
Content-Type: application/json
{ "phone_number": "01012345678", "otp_id": "local-verification-id", "code": "123456" }
```
Backend routes: `otpLogin()` → `has("email") ? verifyLoginOtp : has("phone_number") && verifyOtp()`. Phone path uses `OtpGateway` (`LocalGateway` fallback in test env). On valid `checkVerification`, backend does `User::where('phone_number', $phone_number)->first()` → `createToken()` → `{"status":200,"success":true,"data":{"token":"...","email_verified":false,"permissions":[...],"role":[...],"expires_at":"..."}}` — `data.token` envelope.

**Evidence level:** Send branch is `TEST VERIFIED`; full `send → verify → token` with a mocked-valid gateway is `CODE VERIFIED — NOT E2E TESTED` (LocalGateway's `checkVerification` returns `valid=false` for any code in the test env; the gateway fallback is correct and no `Mail::to(null)` occurs, but a mocked-valid verify is not in the suite).

---

## 6. Cart — phone-only (TEST VERIFIED)

```http
POST /api/v1/cart
Authorization: Bearer [REDACTED:Authorization header]
Content-Type: application/json
{ "item": { "product_id": 1, "quantity": 2, "shipping_method": "scheduled" } }
→ HTTP 201 { "status": 201, "message": "Cart created successfully", "success": true, "data": { ... } }

GET /api/v1/cart
Authorization: Bearer [REDACTED:Authorization header]
→ HTTP 200 { "status": 200, "success": true, "data": { "total_items": 1, "normal_items": [...] } }
```

Identity is `carts.user_id`, never email. `phone_only_user_can_add_and_read_cart` proves phone-only.

---

## 7. Checkout — phone-only (TEST VERIFIED)

### 7.1 Normal checkout — `POST /api/v1/general/checkout`

**Auth:** `auth:sanctum` + `throttle:authenticated`.

**Phone-only COD (delivery) — proven:**

```http
POST /api/v1/general/checkout
Authorization: Bearer {token}
Content-Type: application/json
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

**Validation (SOURCE VERIFIED):** `OrderCreateRequest` — `user_email: nullable|sometimes|email|max:255`; `name/user_phone` required; `address` required for physical delivery; `governorate_id` required for delivery; `pickup_location_id` required for pickup. `COD + pickup` is `422 COD_NOT_AVAILABLE_FOR_PICKUP` — frontend must respect this existing business rule (not an email issue).

**Success (COD):**
```http
HTTP 200
{ "status": 200, "message": "Checkout successful", "success": true, "data": { "order_id": 123 } }
```
`orders.user_id = auth()->id()`, `orders.user_email = null`, `users.email` stays `null`.

### 7.2 Fast checkout — `POST /api/v1/general/fast-shipping/checkout`

Same `user_email` rule. Phone-only `governorate_id + address + null email` passes — **TEST VERIFIED** (`phone_only_fast_checkout_with_null_email`), though the endpoint's side effects are verified via normal checkout path.

---

## 8. COD / Cashier

- `PaymentCheckoutHandler::handleCodPayment` / `handleCashierQrPayment` — no email dependency (code inspected, no `CustomerEmail`).
- Phone-only `cod+delivery` and `pay_at_cashier+pickup` are valid; `cod+pickup` remains 422 by business rule.

---

## 9. Online Payment — MyFatoorah (mocked)

**Flow:**
```text
Frontend checkout (online)
  POST /api/v1/general/checkout { payment_method: online, gateway: myfatoorah, fulfillment_type, ... user_email: null }
    → Backend Order (user_email: null)
    → PaymentCheckoutHandler::handleOnlinePayment
      → PaymentGatewayFactory::make('myfatoorah') → MyFatoorahGateway
        → CustomerContactResolver::emailForGateway(order)
            if (order.user_email ?? order.user?.email) real else order-{id}@no-email.meem.local
        → MyfatoraService::createInvoice { CustomerEmail: <resolved>, ... }
      → Transaction::create { order_id, user_id, amount, gateway_transaction_id, gateway_response }
    → 200 { data: { url: "https://.../pay" } }
```

**Important:** Fallback is never persisted. After mocked `createInvoice`, `orders.user_email` and `users.email` are re-checked `null`.

**Phone-only:** `CustomerEmail = order-{id}@no-email.meem.local` — **TEST VERIFIED** (unit + mocked `MyfatoraService`); `Real gateway: NOT EXECUTED`.

**With email:** `CustomerEmail = real@gmail.com` — **TEST VERIFIED** (`real_email_used_at_gateway_when_present`).

**Frontend:** Send the real customer email only if the user has one; otherwise send `user_email: null` and let the backend derive the gateway contact.

---

## 10. Coupons

Coupon identity is `coupon_assignments.user_id` / `coupon_usages.user_id`, never email.

```http
POST /api/v1/general/checkout
{
  // ... + cart already has coupon code via cart.coupon
  "user_email": null
}
```

- `phone_only_user_can_use_normal_coupon` — `order.coupon == coupon.code`, `user_email: null`.
- `phone_only_user_can_use_assigned_coupon` — `CouponAssignment` → checkout → same.

---

## 11. Orders

`GET /api/v1/general/orders` / `GET /api/v1/general/orders/{id}` — `OrderResource` serializes `user_id` + `user_email: null` for phone-only.

```ts
type Order = { id: number; user_id: number; user_email: string | null; ... }
```

---

## 12. Invoices

```http
GET /api/v1/general/invoices/my-invoices          → auth:sanctum
GET /api/v1/general/invoices/verify/{uuid}         → throttle:5,1
GET /api/v1/general/invoices/view/{uuid}           → signed URL (no Sanctum)
GET /api/v1/general/invoices/download/{uuid}       → signed URL
```

**Snapshot:** `InvoiceSnapshotService::buildFullSnapshot(Order)` → `customer: { id, name, email: order.user_email, phone }`. With null email, `email: null`.

**Validator:** `StructureValidator::REQUIRED_CUSTOMER_KEYS = [id, name, phone]` (email optional).

**View:** `resources/views/pdf/invoice.blade.php: {{ $customer['email'] ?? '' }}`, `resources/views/emails/order/order-invoice.blade.php: @if(isset($customer['email']))` — null renders as empty.

**Phone-only invoice:** real order `user_email: null` → snapshot → validator → view snippet (`customer.email ?? ''`) — **TEST VERIFIED** (`invoice_generation_with_null_email_and_pdf`). Full `pdf.invoice` bytes with a real `Invoice` model for phone-only order is exercised in `InvoiceLifecycleTest` only for email-bearing orders — phone-only snapshot+view is proven.

Frontend should render:

```text
Customer: Phone Only Buyer
Phone: 01012345678
Email: —   // or omitted
```

Never `undefined`/`null`.

---

## 13. Social Login

| Endpoint | Method | Auth | Route |
|----------|--------|------|-------|
| `/api/v1/social/redirect` (`/api/v1/social/{provider}`) | GET | No (`throttle:login`) | `SocialController::redirect` |
| `/api/v1/social/{provider}/callback` | GET | No | `SocialController::callback` |
| `/api/v1/social/exchange` | POST | No (`throttle:login`) | `SocialController::exchange` |

**Canonical identity:** `providers (provider, provider_user_id, user_id)` — **not email**. Fixed: `$providerName` capture before shadowing, `Provider::user(): BelongsTo` added.

| Case | Backend | Frontend |
|------|---------|----------|
| Provider returns email (`user@gmail.com`, id `1135…`) | Link to existing `users.email` user or create + link `providers: google/id` | Treat as normal login |
| Provider returns no email (`email: null`, id `9876…`) | Create `users.email = null`, `providers: google/id` | Do NOT reject; same token flow |
| Repeat `provider+provider_user_id` | Same user, no duplicate | Treat as normal login |

**TEST VERIFIED:** `test_callback_creates_user_and_issues_single_use_code`, `test_callback_creates_user_without_provider_email`, `test_callback_repeat_login_returns_same_user` (17-test suite).

`UserController::socialLogin()` remains GraphQL-only.

---

## 14. Password Reset

Email-only by design (`password_resets.email NOT NULL`).

- `POST /api/v1/forget-password` with no `email` → `where email = NULL` → `0` rows → generic `200 "Check your inbox"` before any `updateOrInsert`/`Mail::to`. No `Mail::to(null)` and no `NULL` inserted.
- `POST /api/v1/verify-forget-password-token` / `POST /api/v1/reset-password` require email.

Phone-only users: email reset is unavailable — intentional. Phone OTP reset is independent where existing. Frontend should not tell `email = null` user "Your email is unverified" for reset.

---

## 15. E2E — What the Tests Prove

**Phone-only E2E (PhoneOnlyE2ETest — 15 passed):**

```
register (null/valid/invalid/duplicate) → phone OTP send → no fake email → cart add/read → checkout cod (delivery+null email) → fast checkout → normal coupon → assigned coupon → invoice snapshot+validator+view → online fallback (mocked, not persisted) → real email at gateway
```

**Plus:**

- `CustomerWithoutEmailTest` — 13 (register, phone login, /me, middleware, validation, invoice validator, DB constraints)
- `SocialLoginFlowTest` — 17 (including repeat + null-email)
- `AuthenticationTest` — 45 (including `register allows optional email`)
- `InvoiceLifecycleTest` — 34

Combined targeted: **90 passed (212 assertions)** + invoice 34 = **124 targeted**.

Pre-existing failure: `UserAuthRegressionTest::registration assigns default customer role` — 7/8 (register never assigned role; out of scope).

---

## 16. Remaining Frontend Gaps (not defects)

- Phone OTP `verify → token` fully-mocked valid path — `LocalGateway::checkVerification` returns `valid=false` in test env; phone branch correctly routed but mocked-valid verify not in suite.
- Full `pdf.invoice` bytes for phone-only order — snapshot+view proven; real `Invoice` → PDF job proven only for email case in `InvoiceLifecycleTest`.
- Real MyFatoorah HTTP — intentionally mocked (`Real gateway: NOT EXECUTED`).

---

*Source: `D:\work\meem` — `git diff HEAD` + `php artisan test` (90 targeted + 34 invoice tests pass). No GraphQL changes.*
