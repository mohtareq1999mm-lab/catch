# Guest Routes — Approved Security & Middleware Decisions (Q1–Q5)

Status: **design approved, implementation NOT authorized.** This document records decisions only; no code, migration or route change accompanies it.

Context: verification of the guest route matrix (`route:list -v`) for web (cookie) and mobile (Bearer) transports after the hybrid-auth merge (#32, #41).

## Approved decisions

| ID | Decision |
|---|---|
| Q1 | Register the named `public-api` rate limiter in `AppServiceProvider` so guest routes cannot 500 when rate limiting is enabled (`DISABLE_RATE_LIMITING=false`). |
| Q2 | Keep the shared `guest_session` limiter for mobile session create, rotate and destroy. |
| Q3 | Remove the ineffective `guest.csrf` middleware from `GET orders/{id}` while strictly preserving order authorization and ownership verification. |
| Q4 | Add automated tests pinning the middleware and security contract of every guest route. |
| Q5 | No change to mobile checkout, payment or access-token endpoints; the Bearer CSRF exemption stays. |

## Mandatory security requirements

1. Order access: an order ID alone is never sufficient. Guest Session ownership and the Order Access Token mechanism are preserved; no new ownership mechanism without review.
2. Web: cookie authentication preserved; CSRF enforced on applicable cookie writes.
3. Mobile: Bearer authentication preserved; existing CSRF exemption kept; authorization never weakened.
4. Guest routes: Origin validation, `no-store` (via `GuestOrigin`) and an appropriate limiter on every route. Any middleware exception must be explicit and justified.
5. If order-ID-only access is found, record it as a separate Security Defect, stop, and request review before touching authorization.

## Verification performed (read-only)

Requirement 1 / 5 — no defect found:

- `GuestOrderController::show/payment/accessTokens` resolve the Guest Identity from the verified credential, then `ownedOrder()` selects `whereKey($orderId) AND guest_id = <identity> AND user_id IS NULL`.
- No credential → identity null → 401; another guest's order → 404; a claimed order → excluded by `user_id IS NULL`.
- Public `orders/track|cancel` paths require the 1-way token minted by `accessTokens`, never the order id.

Requirement 4 — current matrix (verified via `route:list -v`):

| Route | Middleware (after `api`) |
|---|---|
| `POST mobile/session`, `.../rotate`, `DELETE mobile/session` | BypassThrottle:public-api → GuestOrigin → GuestRateLimit:guest_session |
| `GET cart` | BypassThrottle:public-api → GuestOrigin → GuestRateLimit:guest_cart_read |
| cart writes (`POST/PUT/DELETE`) | + GuestCsrf → GuestRateLimit:guest_cart_write |
| `POST checkout` | + GuestCsrf → GuestRateLimit:guest_checkout |
| `POST orders/{id}/payment`, `.../access-tokens` | + GuestCsrf → GuestRateLimit:guest_payment |
| `GET orders/{id}` | + GuestCsrf (inert on GET; Q3 removes it) → GuestRateLimit:guest_cart_read |
| `orders/track`, `orders/cancel` | GuestOrigin → GuestRateLimit:guest_tracking / guest_cancel |

## Known gap addressed by Q1

`config('app.disable_rate_limiting')` defaults to `true`; with it set to `false`, `BypassThrottle:public-api` requires the `public-api` limiter, which is currently not registered (only a commented block exists in `AppServiceProvider`). Every guest route — web and mobile — would 500. Registering the limiter removes that landmine.

## Implementation status

Not started. Requires separate explicit authorization. On authorization the change set is: `AppServiceProvider` (register `public-api` limiter), `routes/api.php` (drop `guest.csrf` from `GET orders/{id}` only), one contract test suite, plus the standard verification runs.
