# Guest Currency — Backend Implementation (Frontend-Owned Header)

**Status:** Implemented
**Date:** 2026-09-08
**Scope:** Laravel backend. The Frontend owns the selected currency and transports it via the **`X-Currency` request header**; the backend reads + validates it through the centralized `CurrencyService`. The `guest_currency` cookie has been **removed**.

---

## 1. Current Architecture (after this change)

```
Guest
  ↓
Frontend
  ↓
X-Currency: KWD  header  (plaintext "KWD", set once in the API client)
  ↓
Browser attaches X-Currency: KWD on every API request (client-managed)
  ↓
Laravel (no cookie middleware involvement)
  ↓
CurrencyService::getEffectiveCode()
  ↓
Authenticated User Preference
        OR
X-Currency Header
        OR
Default/Catalog Currency
```

---

## 2. Previous Flow (before this change)

- The backend used a **`guest_currency` cookie**: `POST /api/v1/general/currencies/select` queued a Laravel-encrypted, `HttpOnly` cookie via `UserCurrencyPreferenceService::setGuestCurrencyCode()`.
- Guests' currency resolved server-side via `CurrencyService::getEffectiveCode()`, but the value required cookie + CORS `credentials` plumbing across origins.

## 3. New Flow

- The guest currency is now transported via the **`X-Currency` HTTP header** (plaintext, read directly from the request).
- The **Frontend owns** the value (sets it centrally on selection). The backend does **not** set any currency cookie — `POST /currencies/select` no longer emits `Set-Cookie`.
- The backend **never trusts** the value: every read is normalized to uppercase ISO 4217 and validated against active `currencies` rows before use.

---

## 4. Transport Ownership

| Concern | Owner | Notes |
|---------|-------|-------|
| Write | Frontend | `X-Currency: KWD` set once in the API client / interceptor |
| Read | Backend | `UserCurrencyPreferenceService::getHeaderCurrencyCode()` → normalized + validated |
| Persist (auth) | Backend | `POST /currencies/select` persists `user_preferences.currency_code` when authenticated |
| Delete | n/a | No cookie to delete; an invalid header is simply ignored |

The backend does **not** write any currency cookie on any request.

---

## 5. Header Specification

| Attribute | Value | Where configured |
|-----------|-------|------------------|
| Name | `X-Currency` | hardcoded in `UserCurrencyPreferenceService::getHeaderCurrencyCode()` |
| Value | uppercase 3-letter ISO 4217 (e.g. `KWD`) | normalized server-side |
| Case/whitespace | normalized (`sar`, `" Sar "` → `SAR`) | `normalizeHeaderValue()` |
| CORS allow-list | `X-Currency` included | `config/cors.php` → `allowed_headers` (env `CORS_ALLOWED_HEADERS`) |
| Cookie | **none** | `EncryptCookies::$except` is empty; no `guest_currency` handling remains |

---

## 6. Cookie Removal

- `app/Http/Middleware/EncryptCookies.php` no longer lists `guest_currency` in `$except` (the cookie is gone entirely).
- `UserCurrencyPreferenceService::setGuestCurrencyCode()` / `getGuestCurrencyCode()` (cookie-based) were removed; `getHeaderCurrencyCode()` replaces them.
- `CurrencyController::select()` no longer emits a `Set-Cookie` header.
- No legacy-encrypted-cookie migration is required — the transport no longer involves cookies.

---

## 7. Backend Resolution Precedence (`CurrencyService::getEffectiveCode`)

Centralized and unchanged in shape:

```
1. currency_selection_enabled == false  → catalog/default currency (short-circuit)
2. Authenticated user's saved preference  (user_preferences.currency_code, validated active)
3. X-Currency header                       (normalized + validated active)
4. catalog/default currency                (settings.options.catalog_currency_code → USD)
```

- All consumers (`ProductController`, `CartResource`/`CartItemResource`, `OrderService`, `HomeService`, `ConvertsProductPrice`, etc.) read the effective currency through `CurrencyService::getEffectiveCode()` / `getEffectiveCurrency()` — **no consumer reads the header directly** except the preference service.

---

## 8. Guest Behavior

| Header | Effective currency |
|--------|--------------------|
| missing | catalog/default |
| `KWD` | `KWD` |
| `kwd` / `" KWD "` | `KWD` (normalized) |
| invalid code (`XXX`) | catalog/default (silently ignored) |
| malformed (`12`, `EG`, very long) | catalog/default (no error thrown) |
| inactive code (exists but `is_active=false`) | catalog/default (silently ignored) |

Ordinary API requests (products, cart, checkout…) never throw because of a stale/malformed header — they fall back to the default.

---

## 9. Authenticated Behavior

- `user_preferences.currency_code` is authoritative. A stale `X-Currency` header can never override it.
- Selecting a currency while authenticated persists to `user_preferences` (the header continues to work after logout for guest continuity).
- Invalid/stale stored preference is cleaned and resolution falls through to the header → default.

## 10. Login Migration

`UserCurrencyPreferenceService::adoptGuestCurrencyOnLogin()` now reads the `X-Currency` header (unchanged semantics):

| Header | Saved preference | Result |
|--------|------------------|--------|
| `KWD` | none | `user_preferences = KWD` |
| `KWD` | `USD` | stays `USD` (not overwritten) |
| `XXX` / missing | none | no adoption |

## 11. Logout Behavior

- Logout only revokes the token; there is no cookie to touch.
- After logout the `X-Currency` header continues to determine the guest currency.

## 12. Cross-Origin Considerations

- The header is sent by the API client on every request, so it works cross-origin provided CORS allows it.
- `config/cors.php` `allowed_headers` already includes `X-Currency`:

```env
CORS_ALLOWED_ORIGINS=http://localhost:3000,https://app.example.com
CORS_ALLOWED_HEADERS=Content-Type,Accept,Authorization,lang,x-channel,X-Channel,X-Requested-With,Origin,X-Currency
```

- Preflight `OPTIONS` with `Access-Control-Request-Headers: X-Currency` must return `Access-Control-Allow-Headers` containing `X-Currency` (already configured by default).
- No `credentials`/cookie coupling is required for currency.

## 13. Migration Strategy (legacy cookie → header)

1. Frontend stops reading/writing `guest_currency` and starts sending `X-Currency` centrally.
2. Any stale `guest_currency` cookie in browsers is ignored by the backend (no code reads it).
3. No purge is required — the cookie expires naturally and is never re-issued.

## 14. Security Considerations

- The header is **not a security boundary**: it is a display preference. It is never trusted without `Currency::where('code', ...)->where('is_active', true)` validation.
- Plaintext header is safe because the value is non-sensitive (a public currency code).
- No `X-Guest-ID`, no guest database entity were introduced.

## 15. API Behavior

`POST /api/v1/general/currencies/select`:
- Body: `{"currency_code":"KWD"}` (validated active).
- Authenticated: persists `user_preferences`.
- Guest: validates and returns the currency; **no server persistence, no cookie**.
- Response: `200` + `CurrencyResource`.
- Errors: `422` for unknown/inactive `currency_code`.

## 16. Files Changed

| File | Change |
|------|--------|
| `app/Services/Currency/UserCurrencyPreferenceService.php` | Replaced cookie helpers with `getHeaderCurrencyCode()` (reads `X-Currency`, normalizes, validates); login adoption reads the header |
| `app/Services/Currency/CurrencyService.php` | `getEffectiveCode()` reads the `X-Currency` header (step 3) instead of the cookie |
| `app/Http/Controllers/Api/Currency/CurrencyController.php` | `select()` no longer emits a `Set-Cookie`; persists `user_preferences` when authenticated |
| `app/Http/Middleware/EncryptCookies.php` | Removed `guest_currency` from `$except` |
| `config/currency.php` | Removed guest-cookie config; documents the `X-Currency` header |
| `config/cors.php` | `allowed_headers` includes `X-Currency` (env `CORS_ALLOWED_HEADERS`) |
| `.env.example` | Updated `CORS_ALLOWED_HEADERS` to include `X-Currency` |

## 17. Tests Added / Updated

- `tests/Feature/Currency/GuestCurrencyCookieTest.php` — header normalization, invalid/malformed fallback, authed-no-pref fallback to header, header resolves when unauthenticated, `select` emits no `guest_currency` cookie.
- `tests/Feature/Currency/UserCurrencyPreferenceTest.php` — header read/normalize, invalid header ignored, resolution precedence (user pref > header > catalog), login adoption from header, no override of existing pref, `select` persists pref for authenticated users and emits no guest cookie.

## 18. Deployment Considerations

- No database migration.
- No environment variables are required for localhost — the `X-Currency` header is allowed by default.
- For cross-origin production, ensure `CORS_ALLOWED_HEADERS` includes `X-Currency` and `CORS_ALLOWED_ORIGINS` lists the Frontend origin.
- No legacy cookie purge needed — stale `guest_currency` cookies are ignored.
