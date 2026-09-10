# Implementation Plan — REST-Only Optional Email

**Date:** 2026-09-06
**Project:** `D:\work\meem` (Laravel 10.30.1 + `../../packages/marvel`)
**Scope:** REST API only. NO GraphQL, NO frontend, NO unrelated admin.
**Predecessor:** `email/email_vreifction.md` (full audit — this plan maps 1:1 to its findings).

> This is a PLAN ONLY. No code is changed in this task.

---

## Target Identity Model

```
userId        = primary identity          (users.id)
phone_number  = primary login/contact     (users.phone_number)
email         = optional contact channel  (users.email nullable)
```

```
order.user_id     = identity (FK -> users.id)   — unchanged
order.user_email  = optional contact snapshot   — may be NULL
```

---

## Impact Map (REST-only)

| Area | File (REST) | Current behavior | Problem with NULL email | Required change | Risk | Test |
|---|---|---|---|---|---|---|
| DB schema | `database/migrations/2014_10_12_000000_create_users_table.php:19` | `email` NOT NULL UNIQUE | Blocks phone-only registration | New migration → nullable unique | Migration on prod MySQL | DB constraint test |
| DB (test) | `tests/Concerns/CreatesTestTables.php:42` | `email` NOT NULL UNIQUE | Tests fail with null email | `->nullable()->unique()` | None | — |
| Registration | `packages/marvel/src/Http/Controllers/UserController.php:647-685` | `register()` calls `$user->sendOneTimePassword()` unconditionally | `Mail::to(null)` throws / OTP to null | Guard `if ($user->email)` | Breaking registration | register-no-email test |
| Registration validation | `packages/marvel/src/Http/Requests/UserCreateRequest.php:33` | `email` = `sometimes` (already optional) | None | **No change** (confirm phone required) | None | register-no-email test |
| Customer login | `UserController.php:480-504` `token()` | `where(email)->orWhere(phone)` | None | **No change** (phone works) | None | login-by-phone test |
| Admin login | `UserController.php:506-531` `adminToken()` | email-only + `hasVerifiedEmail()` gate | Phone-only admin blocked | **Keep email REQUIRED + verification REQUIRED — admin only.** No change to admin flow. | — | admin-login-still-verified test |
| OTP send | `UserController.php:536-571` `sendUserOtp()` | branches `if($request->email)` else phone | None | **No change** (already branches) | None | phone-otp test |
| OTP login | `UserController.php:1101-1124` `otpLogin()` | `if has(email) verifyLoginOtp else verifyOtp(phone)` | None | **No change** (phone path works) | None | otp-login test |
| OTP verify (email) | `UserController.php:572-596` `verifyLoginOtp()` | `email required` validation | Only reached when email present | **No change** (guarded by `otpLogin`); add `required_without:phone_number` for safety | None | — |
| Verification middleware | `app/Http/Middleware/VirifiyEmailMiddleware.php:20` | blocks if `email_verified_at == null` | Phone-only users blocked IF attached | **Not attached to any route** (confirmed). Fix guard to `if($user->email && is_null($user->email_verified_at))` as defense-in-depth | Latent | middleware test |
| Verification (Marvel) | `../../packages/marvel/src/Http/Middleware/EnsureEmailIsVerified.php` | logic commented out → no-op | None | **No change** | None | — |
| Checkout validation | `packages/marvel/src/Http/Requests/OrderCreateRequest.php:38` | `user_email` required | 422 blocks checkout | `['nullable','sometimes','email','max:255']` | Checkout break | checkout-no-email test |
| Fast checkout validation | `packages/marvel/src/Http/Requests/FastCheckoutRequest.php:22` | `user_email` required | 422 | `['nullable','sometimes','email','max:255']` | — | fast-checkout test |
| Order creation | `app/Services/Checkout/OrderCreationService.php:46,116` | `user_email => ??null` / `??$order->user_email` | None | **No change** (already nullable-aware) | None | order-user_email-null test |
| Order service | `app/Services/General/OrderService.php:45,233` | `dataArray` includes `user_email`; `$request->only(...)` | null passes | **No change** | None | — |
| Invoice snapshot | `app/Services/Invoice/InvoiceSnapshotService.php:28` | `'email' => $order->user_email` | null email in snapshot | `=> $order->user_email ?? ''` (or `?? null`) | Invoice break | invoice-no-email test |
| Invoice validator | `app/Services/Invoice/Validators/StructureValidator.php:25` | `REQUIRED_CUSTOMER_KEYS=['id','name','email','phone']` | throws if email key missing; null passes array_key_exists but wrong semantics | Remove `'email'` from required keys (keep `id`,`name`,`phone`), or allow nullable email | Invoice validation break | invoice-validation test |
| Payment gateway | `app/Services/Gateway/MyFatoorahGateway.php:44` | `CustomerEmail => $order->user_email` (no fallback) | MyFatoorah rejects empty email | Add centralized fallback (see Payment section) | Order stuck pending | gateway-fallback test |
| Payment (Marvel legacy) | `packages/marvel/src/Payment/{Stripe,Flutterwave,Paystack,Iyzico}.php` | Stripe: `$request->user()->email`; Flutterwave/Paystack/Iyzico already have fallbacks | Stripe null email | **Out of scope** (REST checkout uses `PaymentGatewayFactory` → MyFatoorah only). Document. | — | — |
| Payment trait | `packages/marvel/src/Traits/PaymentTrait.php:186` | `$order->customer->email` (legacy) | null | **Out of scope** (legacy Marvel path not used by REST `checkout`). Document. | — | — |
| Social login | `packages/marvel/src/Http/Controllers/SocialController.php:76` + `UserController.php:963-1005` | `firstOrCreate(['email'=>getEmail()])` | duplicate NULL-email rows / wrong linking | Use `providers` table (`provider` + `provider_user_id`) for identity; fallback email only for linking | Duplicate accounts | social-null-email test |
| Resources | `app/Http/Resources/User/UserResource.php:20,22` + `packages/marvel/src/Http/Resources/UserResource.php:18,21` | `email` + `hasVerifiedEmail()` | `email_verified:false` for null-email user | Serialize null safely; return `email_verified:true`/null when email is null (see Resources section) | Frontend verification wall | resource test |
| Password reset | `UserController.php:761-854` + `password_resets` | email-keyed | User without email cannot reset | **No change** (email reset stays email-required). Add clear guard/response. Document. | None | reset-no-email test |
| Factory | `database/factories/UserFactory.php:14` | always generates email | no null coverage | Add `withoutEmail()` state | — | all REST tests |
| GraphQL | `packages/marvel/src/GraphQL/**` | `RegisterInput.email: String!` etc. | — | **DO NOT MODIFY** (document only) | — | — |

---

## Implementation Order (Phases)

### Phase 1 — Audit (DONE in `email_vreifction.md`)

REST endpoints identified (actual route → handler):

| Route | Handler | REST? |
|---|---|---|
| `POST /api/v1/register` | `UserController@register` | Yes |
| `POST /api/v1/token` | `UserController@token` | Yes |
| `POST /api/v1/admin-login` | `UserController@adminToken` | Admin |
| `GET/POST /api/v1/social/*` | `SocialController` | Yes |
| `POST /api/v1/logout` | `UserController@logout` | Yes |
| `GET /api/v1/me` | `UserController@me` | Yes |
| `POST /api/v1/forget-password` / `verify-forget-password-token` / `reset-password` | `UserController` | Yes (email-keyed) |
| `POST /api/v1/send-otp-code` | `UserController@sendUserOtp` | Yes |
| `POST /api/v1/otp-login` | `UserController@otpLogin` | Yes |
| `POST /api/v1/general/checkout` | `app/Http/Controllers/Api/General/OrderController@checkout` | Yes |
| `POST /api/v1/general/fast-shipping/checkout` | `FastShippingController@checkout` | Yes |
| `GET /api/v1/general/orders` | `OrderController@index` | Yes |
| `GET /api/v1/general/invoices/*` | `InvoiceController` | Yes |
| `POST /api/v1/general/coupons/apply` | `CouponController@applyCoupon` | Yes |

### Phase 2 — Database

1. New migration `database/migrations/2026_09_06_XXXXXX_make_users_email_nullable.php`:
   ```php
   Schema::table('users', function (Blueprint $table) {
       $table->string('email')->nullable()->change();
   });
   ```
   - Keep `unique()` — MySQL allows multiple NULLs in a UNIQUE index (correct).
   - Requires `doctrine/dbal` (already a dependency; confirmed present in vendor).
   - Do NOT rewrite `2014_10_12_000000_create_users_table.php`.
2. `tests/Concerns/CreatesTestTables.php:42`: `string('email')->unique()` → `string('email')->nullable()->unique()`.
3. Verify: NULL+NULL valid, real email unique, same real email rejected.

### Phase 3 — Registration / Auth

1. `UserController::register()` (Marvel `UserController.php:647-685`):
   - Add `'email_verified_at' => now()`? **No** — per instruction 9, prefer not to fake verification. Instead guard OTP:
   ```php
   if ($user->email) {
       try {
           $user->sendOneTimePassword();
           $data = ['otp_status' => true];
           return $this->apiResponse(USER_REGISTERED_SUCCESSFULLY, 200, true, $data);
       } catch (\Exception $mailException) {
           $data = ['requires_resend' => true, 'email' => $user->email, 'phone_number' => $user->phone_number, 'otp_status' => false];
           return $this->apiResponse(ACCOUNT_CREATED_BUT_OTP_FAILED, 201, true, $data);
       }
   }
   // no email: registration succeeds with no email-side effect
   return $this->apiResponse(USER_REGISTERED_SUCCESSFULLY, 200, true, ['otp_status' => true]);
   ```
2. `UserCreateRequest.php:33` — already `sometimes`; add explicit `'nullable'` and keep `phone_number` required. Confirm at-least-one-identifier rule.
3. `token()` login — no change (phone fallback already works).
4. Sanctum — no change (tokenable_id = users.id already).

### Phase 4 — Verification

1. `VirifiyEmailMiddleware.php:20` — change to:
   ```php
   if ($request->user()->email !== null && $request->user()->email_verified_at === null) {
       return $this->apiResponse(PLEASE_VERIFY_YOUR_EMAIL, 401, false);
   }
   ```
   (defense-in-depth; middleware currently unused).
2. Do NOT add `email_verified_at = now()` to registration (avoid faking). Null-email users pass verification by virtue of not having an email.
3. **`/admin-login` (`adminToken()`) — admin only: keep email required AND keep the `hasVerifiedEmail()` gate.** Admins must still have a real email and be verified. This rule is scoped strictly to `type === 'admin'`; customer (`type === 'user'`) login is unaffected (Phase 3 `token()` uses phone fallback, no verification gate).

### Phase 5 — Commerce

1. `OrderCreateRequest.php:38` → `'user_email' => ['nullable','sometimes','email','max:255']`.
2. `FastCheckoutRequest.php:22` → same.
3. `OrderCreationService` + `OrderService` — no change (already nullable-aware).
4. Cart, coupon, assigned-coupon lifecycle — no email dependency; regression test only.

### Phase 6 — Invoice

1. `InvoiceSnapshotService.php:28` → `'email' => $order->user_email` (allow null; do not invent a value).
2. `StructureValidator.php:25` → `REQUIRED_CUSTOMER_KEYS = ['id', 'name', 'phone']` (drop `email`), OR keep key but accept null/empty. Decision: **drop `email` from required set** — identity remains `id`; `email` is optional contact data. Verify `FinancialInvariantValidator`, `MoneyValidator`, etc. do not reference email (confirmed they do not).
3. Invoice PDF/Blade already guard with `@if(isset($customer['email']))` / `{{ $customer['email'] ?? '' }}` — no change.

### Phase 7 — Payments

1. Only REST-active gateway is `MyFatoorah` (`PaymentGatewayFactory::make('myfatoorah')`). `Stripe`/`Flutterwave`/`Paystack`/`Iyzico` are Marvel legacy payment classes not used by REST `checkout` — **out of scope** (document).
2. Centralized resolver (new class, REST-only):
   - `../../app/Services/Payment/CustomerContactResolver.php`
   ```php
   public function emailForGateway(Order $order): string
   {
       $email = $order->user_email ?? $order->user?->email;
       if ($email) return $email;
       // deterministic, technical, gateway-safe — NEVER persisted
       return 'order-' . $order->id . '@no-email.meem.local';
   }
   ```
   - Deterministic fallback only at the gateway boundary. Never write to `users.email` or `orders.user_email`.
3. `MyFatoorahGateway.php:44` → `'CustomerEmail' => $this->customerContactResolver->emailForGateway($order)`.
4. COD / Cashier QR (`PaymentCheckoutHandler`) — no email usage; regression test only.
5. Payment success/failure lifecycle — no email dependency (identity via `order_id`/`user_id`); regression test.

### Phase 8 — Social Login (REST)

1. `SocialController.php:76` and `UserController.php:977`:
   - Replace `firstOrCreate(['email' => $socialUser->getEmail()])` with provider-identity lookup:
   ```php
   $email = $socialUser->getEmail();
   $providerId = $socialUser->getId();

   $provider = Provider::where('provider', $provider)
       ->where('provider_user_id', $providerId)
       ->first();

   if ($provider) {
       $user = $provider->user;
   } else {
       // link by email only when email is non-null, else create fresh
       $user = $email
           ? User::firstOrCreate(['email' => $email], [...])
           : User::create([... 'email' => null ...]);
       $user->providers()->updateOrCreate([
           'provider' => $provider,
           'provider_user_id' => $providerId,
       ]);
   }
   ```
   - Guard `getEmail()` null; do not fabricate a stored email.
2. `SocialLoginExchangeRequest` / `SocialController@exchange` — uses `SocialLoginCode` + `user_id` already (no email) — no change.

### Phase 9 — Tests

New feature test class `../../tests/Feature/Rest/CustomerWithoutEmailTest.php` (or split per domain).

`UserFactory` — add state:
```php
public function withoutEmail()
{
    return $this->state(fn (array $a) => ['email' => null, 'email_verified_at' => null]);
}
```

Coverage (fixture `email=null`, `phone_number=valid`):
- Registration without email → 200/201, user created, `email=null`, no `Mail::to(null)`
- Login by phone → token
- `/me` → email `null`, no error
- Phone OTP (if LocalGateway) → OTP issued
- OTP login by phone → token
- Email-verification middleware → does not block null-email user
- Cart → create/add/update
- Checkout (COD) without email → order created
- Order → `user_id` correct, `user_email` NULL
- Coupon + assigned coupon apply/consume
- Invoice → created, validation passes, response succeeds
- COD / Cashier QR payment
- Online payment gateway fallback → fallback used only in gateway, `users.email` and `orders.user_email` unchanged
- Social login (mock provider) with `email=null`, `provider_user_id` valid → correct user linked
- No `Mail::to(null)` in normal lifecycle

Regression (email users preserved):
- Existing email user full lifecycle unchanged.

DB constraint test:
- NULL + NULL → valid
- NULL + `a@b.com` → valid
- `a@b.com` + `a@b.com` → invalid
- `a@b.com` + `c@d.com` → valid

### Phase 10 — Final Audit

Post-change static search classification (see report section D/24):
- `Mail::to($user->email)` / `Mail::to($order->user_email)` → classify
- `->email`, `email_verified_at`, `hasVerifiedEmail`, `MustVerifyEmail`, `firstOrCreate(['email'`
- Confirm no fake emails persisted; uniqueness intact; existing email users work; phone-only users work; no GraphQL/frontend files touched.

---

## Coverage Matrix (REST)

| REST Area | Email NULL | Expected |
|---|---|---|
| Registration | Yes | Pass (no email side-effect) |
| Phone Login | Yes | Pass |
| Token | Yes | Pass |
| `/me` | Yes | Pass |
| Phone OTP | Yes | Pass |
| Email Verification Middleware | Yes | Bypass (no email) |
| Cart | Yes | Pass |
| Checkout | Yes | Pass |
| Order | Yes | Pass (user_email NULL) |
| Coupon | Yes | Pass |
| Assigned Coupon | Yes | Pass |
| Invoice | Yes | Pass (email nullable) |
| COD | Yes | Pass |
| Online Payment | Yes | Pass (gateway-safe fallback) |
| Social Login | Yes | Pass (provider_user_id identity) |
| Email Password Reset | Yes | Intentionally unavailable (email-keyed) |
| Newsletter | Yes | Email remains required |
| Contact Form | Yes | Email remains required |

---

## Files Intentionally NOT Changed (and why)

- **GraphQL** (`packages/marvel/src/GraphQL/**`, `user.graphql` `RegisterInput.email: String!`) — out of REST scope; documented limitation.
- **Frontend** (`resources/js/**`) — no frontend code present beyond `app.js`/`bootstrap.js`.
- **Marvel legacy payment classes** (`Stripe.php`, `Flutterwave.php`, `Paystack.php`, `Iyzico.php`, `PaymentTrait.php`) — REST checkout uses `PaymentGatewayFactory` (MyFatoorah only).
- **`EnsureEmailIsVerifiedDirective.php` / `EnsureEmailIsVerified.php`** — already no-op / gated.
- **`AdminCreateUserRequest`, `AdminCreateCommand`, `adminToken`** — admin flow; email required AND verification required intentionally (admin only, NOT customer).
- **`ContactCreateRequest`, `SettingsRequest`, `subscribeToNewsletter`** — email-specific features.

---

## Remaining Risks (outside REST scope)

1. GraphQL registration still requires email (`user.graphql`) — REST/GraphQL inconsistency.
2. Admin login requires verified email — admin not affected by this change (intended).
3. Marvel legacy payment paths (`PaymentTrait`, `Stripe::createCustomer`) still assume email — unused by REST checkout but latent if legacy path re-enabled.
4. `EnsureEmailIsVerifiedDirective` could block if `settings.options.useMustVerifyEmail` toggled on in admin UI.

---

## Definition of Done Checklist

```
[ ] users.email accepts NULL
[ ] non-null email remains unique
[ ] REST registration works without email
[ ] phone login works without email
[ ] phone OTP works without email
[ ] Sanctum token works
[ ] /me works
[ ] email verification does not block REST customers
[ ] cart works
[ ] checkout works
[ ] order creation works
[ ] coupons work
[ ] assigned coupons work
[ ] invoice works
[ ] invoice PDF works where applicable
[ ] COD works
[ ] online payment is gateway-safe
[ ] social login works without provider email
[ ] no NULL email inserted into email-keyed reset flow
[ ] email-specific endpoints remain intentionally email-required
[ ] existing email users remain unaffected
[ ] automated tests pass
[ ] no GraphQL/frontend changes were made
```
