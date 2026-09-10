# Frontend — Guest Currency Header Contract (`X-Currency`)

**Backend transport is now `X-Currency` header. `guest_currency` cookie is removed.**

---

## 1. What Changed

| Before | After |
|---|---|
| `guest_currency` cookie (`SameSite=None; Secure`) | `X-Currency: SAR` header |
| `credentials:include` required for currency | `credentials:include` only for auth (`Authorization: Bearer`) |
| `POST /currencies/select` set `Set-Cookie` | `POST /currencies/select` **does not** set cookie |

Do not use: `localStorage` as source of truth, `X-Guest-ID`, `Cookie` handling for currency.

---

## 2. Ownership

- **Frontend owns** the selected value (`SAR`, `AED`, `KWD` …) and sends it.
- **Backend owns** validation (`exists + is_active`), effective resolution (`UserPreference > X-Currency > Catalog`), conversion, `currency` metadata in responses.

---

## 3. How to Send (Central — Do Not Per-Endpoint)

Add header once in your API client / interceptor, every request. Do not add to each service manually.

### Axios

```js
// store
let selectedCurrency = 'SAR'; // from currency picker, default catalog (USD)

axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.headers.common['X-Currency'] = selectedCurrency;

// when user picks
function setCurrency(code){
  selectedCurrency = code.toUpperCase(); // SAR
  axios.defaults.headers.common['X-Currency'] = selectedCurrency;
}

// optional: keep in memory only. If you persist, persist the string, not a second source.
```

### Fetch wrapper

```js
let selectedCurrency = 'SAR';

export function apiFetch(url, opts={}){
  opts.headers = { ...(opts.headers||{}), 'Accept':'application/json', 'X-Currency': selectedCurrency };
  // credentials only needed for auth endpoints (profile, checkout)
  // opts.credentials = 'include';
  return fetch(url, opts);
}
```

> Header name is case-insensitive but use `X-Currency` exactly. Value `sar` / ` Sar ` is normalized to `SAR` server-side, but send uppercase trimmed.

---

## 4. When to Send

- **Public endpoints (guest):** `GET /api/v1/products`, `GET /api/v1/general/brands/{slug}`, `GET /api/v1/categories`, `GET /api/v1/general/brands-products`, cart, etc. → always send `X-Currency`.
- **No header:** backend falls back to catalog (`USD`) — no error.
- **Invalid/inactive header (`XXX`, inactive `AED`):** backend ignores and falls back to catalog — no error on product reads. `POST /currencies/select` with invalid code returns `422`.

---

## 5. `POST /api/v1/general/currencies/select` (Optional for Guest, Required for Auth Persistence)

**Guest:** No server persistence needed. Just start sending `X-Currency: SAR`. Calling `select` is optional; it only validates and returns `200 {data:{code:SAR}}`, does not set cookie.

**Authenticated:** To make preference survive logout / other devices, call:

```http
POST /api/v1/general/currencies/select
Content-Type: application/json
Accept: application/json
Authorization: Bearer <token>
X-Currency: SAR

{"currency_code":"SAR"}
```

→ `200` and `user_preferences.currency_code = SAR`. Keep sending `X-Currency: SAR`; backend will ignore header and return `KWD` if `user_preferences` is `KWD`.

Supports both `application/json` and `multipart/form-data` (`currency_code`).

---

## 6. Login / Registration / Logout Flow (You Don't Need Branching)

```text
Before login:  Frontend selected = SAR → X-Currency: SAR → effective SAR
After login (user has KWD saved):  X-Currency: SAR still sent → effective KWD (pref overrides)
After login (no saved pref):        X-Currency: SAR → effective SAR (and optionally adopt via select)
After logout:                       X-Currency: SAR → effective SAR (guest, no cookie)
```

Continue sending the same `X-Currency` after login/logout. Backend decides.

---

## 7. What Response Contains

Every product/brand/cart response now contains converted prices + metadata (same as before, now header-driven):

```json
{
  "id": 1, "slug": "nike-air",
  "price": 375.0,
  "price_after_discount": 375.0,
  "current_price": 375.0,
  "currency": {"id":3,"code":"SAR","name":{"en":"Saudi Riyal"},"symbol":{"en":"ر.س"},"icon":"sa"}
}
```

Compare:
- `X-Currency: SAR` → `price 375` + `currency.code SAR`
- No header → `price 100` + `currency.code USD`

---

## 8. CORS

Frontend origin must be explicit in backend env:

```env
CORS_ALLOWED_ORIGINS=http://localhost:3000,https://your-production-frontend.example.com
CORS_ALLOWED_HEADERS=Content-Type,Accept,Authorization,lang,x-channel,X-Currency
```

Preflight `OPTIONS` with `Access-Control-Request-Headers: X-Currency` must return `Access-Control-Allow-Headers: ... X-Currency ...` and `Access-Control-Allow-Origin: http://localhost:3000` (not `*`).

---

## 9. Do Not

- Do not set `Cookie: guest_currency=...`
- Do not use `X-Guest-ID`, query `?currency=`, or second cookie
- Do not duplicate conversion in frontend — use `price` + `currency` from API
- Do not require `localStorage` for currency (if you persist picker, persist the string only as UI preference)

---

## 10. Checklist

- [ ] Central client sends `X-Currency: <selected>` on every API request
- [ ] Picker normalizes to uppercase 3-letter (`SAR`)
- [ ] `POST /currencies/select` called only to persist auth pref (optional for guest)
- [ ] Login/logout keep sending same header — no extra logic
- [ ] Prices use API `price`/`currency`, not local conversion
