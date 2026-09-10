# Guest Currency — Frontend Requirements

> Implementation contract for the Frontend team. The Backend has **removed** the `guest_currency` cookie and now reads the guest currency from the **`X-Currency` HTTP request header** (Frontend-owned, Backend-validated). The Frontend is the source of truth for the guest currency; the Backend reads + validates it on every request through the centralized currency service.

---

## 1. Transport — the `X-Currency` Header

```text
Header name:  X-Currency
Value:        uppercase 3-letter ISO 4217 code, e.g. "KWD"
              (plaintext — NOT encrypted, NOT signed)
```

| Concern | Rule |
|---------|------|
| Header name | `{ "currency_code": "KWD" }
` (case-insensitive, but send it exactly like this) |
| Value format | 3 uppercase letters, e.g. `SAR`, `KWD`, `AED` |
| Normalization | Backend normalizes (`sar`, `" Sar "` → `SAR`); send uppercase + trimmed anyway |
| Where to set it | **Once, centrally** in your API client / interceptor — not per endpoint |
| Persistence | Keep it in memory (store). If you persist the picker, persist the string only as a UI preference — never as a second source of truth |
| Cookie | **Do NOT** set `guest_currency` or any currency cookie |

```text
X-Currency is a display preference, not a security token.
No X-Guest-ID. No query param (?currency=). No localStorage-as-source-of-truth.
```

---

## 2. Initial Page Load

```
Read your selected currency (memory/store picker state)
  ↓
if exists AND /^[A-Z]{3}$/ after trim+uppercase:
    use it (set app currency state AND send X-Currency: <code>)
  ↓
if missing or invalid:
    use the Backend-provided default/effective currency
    (GET /api/v1/general/currencies lists active currencies; the catalog/default is authoritative)
    and send NO X-Currency header (or omit it) so the Backend falls back to the default
```

The Backend always resolves the effective currency itself. You do **not** need to mirror the Backend's decision locally beyond showing the correct prices returned in each response.

---

## 3. Currency Selection

```text
User selects KWD
  ↓
Frontend updates local state   selected = "KWD" (uppercase, trimmed)
  ↓
Frontend updates central client  X-Currency: KWD  (every future request carries it)
  ↓
(Optional for guest, required to persist an authenticated preference)
Call POST /api/v1/general/currencies/select { "currency_code": "KWD" }
  ↓
Future API requests automatically carry X-Currency: KWD (client-managed)
```

- For a **guest**, calling `select` is optional — just start sending `X-Currency: KWD`.
- For an **authenticated** user, call `select` to persist the preference server-side (survives logout / other devices).

---

## 4. API Requests — Send `X-Currency` Centrally

```text
DO send X-Currency on every API request (central client/interceptor).
DO NOT add currency manually to each endpoint.
DO NOT send X-Guest-ID.
DO NOT send a currency cookie.
```

Once your client sets `X-Currency` globally, every request carries the guest's chosen currency and the Backend resolves it centrally.

### Axios example

```js
let selectedCurrency = 'SAR'; // from currency picker, default catalog (USD)

axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.headers.common['X-Currency'] = selectedCurrency;

function setCurrency(code) {
  selectedCurrency = code.toUpperCase();
  axios.defaults.headers.common['X-Currency'] = selectedCurrency;
}
```

### Fetch wrapper example

```js
let selectedCurrency = 'SAR';

export function apiFetch(url, opts = {}) {
  opts.headers = { ...(opts.headers || {}), 'Accept': 'application/json', 'X-Currency': selectedCurrency };
  // credentials only needed for auth endpoints (profile, checkout)
  return fetch(url, opts);
}
```

> CORS note: your Backend must allow the `X-Currency` header (it already does by default). No `credentials:include` is needed for currency.

---

## 5. Authenticated Users

```text
User logs in
  ↓
Backend User Preference (user_preferences.currency_code) becomes authoritative
```

- After login, the Backend prefers the saved user preference over the `X-Currency` header.
- **Keep sending** the same `X-Currency` header — the Backend will ignore it when a saved preference exists.
- The Frontend should **not** fight or overwrite a server-side saved preference; reflect whatever currency the Backend reports effective.

---

## 6. Login / Logout

| Event | Frontend behavior |
|-------|-------------------|
| Login, user had **no** saved preference | Backend adopts the `X-Currency` header value into the user's preference. Keep sending the header. |
| Login, user **already had** a preference | Backend keeps the saved preference; do **not** overwrite the UI with the header value. |
| Logout | Backend revokes the token only. The `X-Currency` header continues to determine the guest currency — no cookie to manage. |

Continue sending the same `X-Currency` header before/after login and logout. The Backend decides.

---

## 7. Invalid / Missing Header

| State | Frontend behavior |
|-------|-------------------|
| No `X-Currency` header | Backend falls back to the default/catalog currency. |
| `X-Currency` invalid (not 3 letters / inactive) | Backend safely falls back to the default (no error). Fix the value after a new selection. |
| Value casing/whitespace (`sar`, `" Sar "`) | Backend normalizes to `SAR`; send uppercase trimmed anyway. |

No request ever fails because of a stale/malformed `X-Currency` header — the Backend falls back to the default.

---

## 8. API Contract (as implemented)

### GET /api/v1/general/currencies

```http
GET /api/v1/general/currencies
Accept: application/json
```

Response `200`: list of active currencies (id, code, name, symbol, country_name, numeric_code, decimal_places, icon, is_active, sort_order, is_base, is_catalog).

### POST /api/v1/general/currencies/select

```http
POST /api/v1/general/currencies/select
Accept: application/json
Content-Type: application/json

{"currency_code": "KWD"}
```

Response `200`:

```json
{
  "status": 200,
  "message": "Currency updated successfully",
  "success": true,
  "data": { "id": 2, "code": "KWD", "name": "Kuwaiti Dinar", "symbol": "KD", "country_name": "Kuwait", "numeric_code": "414", "decimal_places": 3, "icon": "kw", "is_active": true, "sort_order": 2, "is_base": false, "is_catalog": false, "created_at": "..." }
}
```

- **No `Set-Cookie` is emitted.** Persistence for an authenticated user happens in `user_preferences`.
- Guest: `select` is optional (validates + returns the currency, no server persistence).
- Errors: `422` for unknown/inactive `currency_code`.

---

## 9. Frontend Acceptance Criteria

```text
[ ] Central client sends X-Currency: <selected> on every API request
[ ] Picker normalizes to uppercase 3-letter (e.g. SAR)
[ ] Currency persists after page refresh (re-apply from store state)
[ ] Currency persists across normal API requests (no manual header)
[ ] No currency cookie is set/read
[ ] No X-Guest-ID is required
[ ] Guest currency works before login (header only)
[ ] Authenticated currency follows Backend User Preference (header does not override)
[ ] Login transition works (guest adopted when user has no preference; kept when user has one)
[ ] Logout behavior works (header still determines guest currency after logout)
[ ] Missing/invalid header falls back to default without errors
```
