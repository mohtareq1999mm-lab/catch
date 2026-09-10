# Social Login (Web/Mobile) — Frontend Jira Tasks

---

## Task 1: Web Social Login — OAuth Redirect Flow

**Priority:** High
**Component:** Frontend — Login Page / Auth
**Story Points:** 8

**Description:** Wire the web Google/Facebook login buttons to the OAuth redirect flow. The browser is sent to the provider, then redirected back to the frontend with a single-use `code` that is exchanged for a Sanctum token.

**API Endpoints:**
- `GET /api/v1/social/{provider}` (returns `{ success, url }` — authorization URL)
- `GET /api/v1/social/{provider}/callback` (web: `302` redirect to `{frontend_url}/?code={code}`)
- `POST /api/v1/social/exchange` (body: `{ code }` → returns `{ success, message, token, token_type, user }`)

**Acceptance Criteria:**
- [ ] On "Continue with Google": call `GET /api/v1/social/google`, read `data.url`, redirect browser
- [ ] On "Continue with Facebook": call `GET /api/v1/social/facebook`, read `data.url`, redirect browser
- [ ] **Do NOT send `type`** for web (server defaults to `web` — backward compatible)
- [ ] On return to `/?code={code}`: capture `code` from the URL query, then `POST /api/v1/social/exchange`
- [ ] On exchange success: store `token`, load user from `data.user`, run normal post-login flow (redirect by role)
- [ ] On exchange 400: show "Invalid or expired authorization code" and return to login
- [ ] **Error state:** provider cancel/deny → server redirects to `/auth?error=social_login_failed` → show failure toast
- [ ] **Loading state:** disable social buttons, show spinner while obtaining redirect URL and during exchange
- [ ] Never expose or log the `code` or `token`

---

## Task 2: Mobile Social Login — JSON Redirect Flow

**Priority:** High
**Component:** Mobile App — Login Screen / Deep Link
**Story Points:** 8

**Description:** Add mobile support to social login. Mobile requests send `type=mobile`, so the callback returns a JSON payload with the single-use `code` instead of a browser redirect. The app then exchanges it for a token.

**API Endpoints:**
- `GET /api/v1/social/{provider}?type=mobile` (returns `{ success, url }`)
- `GET /api/v1/social/{provider}/callback?state=mobile` (mobile success → `200` JSON `{ success, code }`; failure → `400` JSON `{ success, message }`)
- `POST /api/v1/social/exchange` (body: `{ code }`)

**Acceptance Criteria:**
- [ ] Mobile login calls `GET /api/v1/social/google?type=mobile` / `?type=facebook` and reads `data.url`
- [ ] Provider callback must be handled without a full browser page (in-app browser / WebView / custom tab that returns to the app)
- [ ] On return, read the `code` from the **callback JSON body** (NOT the URL query — mobile gets JSON)
- [ ] On success (`success: true`, `code` present): call `POST /api/v1/social/exchange` with the code
- [ ] On failure (`400`, `success: false`): show the localized `message` from the response
- [ ] **Error state:** network failure / callback never fires → timeout with "Try again" toast
- [ ] **Loading state:** spinner on the social button while redirecting and while exchanging
- [ ] Store token securely (keychain/secure storage — never localStorage on mobile)
- [ ] Same post-login flow as web after exchange

---

## Task 3: Single-Use Code Exchange Service

**Priority:** High
**Component:** Frontend — Shared Auth Service
**Story Points:** 3

**Description:** Shared API layer for exchanging the single-use authorization code for a Sanctum token.

**API Endpoint:**
- `POST /api/v1/social/exchange`

**Acceptance Criteria:**
- [ ] `exchangeSocialCode(code)` sends `{ code }` to `POST /api/v1/social/exchange`
- [ ] On 200: return `{ token, user }` — store token, update auth state
- [ ] On 400: surface `success: false` + `message` (invalid/expired/unknown code)
- [ ] On 422: surface validation error (missing code field)
- [ ] On 429: surface rate limit message
- [ ] Code is single-use — a second call with the same code must fail; handle gracefully (fall back to re-login)
- [ ] Never log the code or token

---

## Task 4: Frontend Error & Cancel Handling

**Priority:** Medium
**Component:** Frontend — Shared Auth Flow
**Story Points:** 3

**Description:** Consistent handling of provider failures, user cancellations, and expired codes across web and mobile.

**API Endpoints:**
- `GET /api/v1/social/{provider}/callback` (web failure redirect: `/auth?error=social_login_failed`)
- `GET /api/v1/social/{provider}/callback?state=mobile` (mobile failure JSON: `400 { success: false, message }`)

**Acceptance Criteria:**
- [ ] Web: if returned URL contains `error=social_login_failed`, show localized error toast, do NOT attempt exchange
- [ ] Web: if `code` missing/empty in URL, do NOT call exchange
- [ ] Mobile: on `400 { success: false }`, show the `message` from the response (localized: EN `"Social login failed, please try again."` / AR)
- [ ] On 401 from exchange: clear stored token, redirect to login
- [ ] **Empty state:** no social providers available → hide social section or show "unavailable"
- [ ] **Error state:** retry button restarts the flow from the top

---

## Task 5: Localization of Social Login Messages

**Priority:** Low
**Component:** Frontend — Shared i18n
**Story Points:** 2

**Description:** All social login user-facing strings must be localized (EN/AR) and driven by the app locale.

**Acceptance Criteria:**
- [ ] Use response `message` from the API where provided (server already localizes via `lang` header)
- [ ] Client-side strings (button labels, "Continue with Google/Facebook") use the i18n system
- [ ] Send the correct `lang` header (`en`/`ar`) with all social login requests so the server returns the right locale
- [ ] Arabic labels render with correct RTL alignment on the login screen

---

## Task 6: Auth State & Session After Social Login

**Priority:** Medium
**Component:** Frontend — AuthContext / AuthProvider
**Story Points:** 3

**Description:** Ensure social login produces the same auth state as a normal login.

**API Endpoint:**
- `POST /api/v1/social/exchange` (returns `user` with `role` + `permissions`)

**Acceptance Criteria:**
- [ ] Store returned `user` (with `role`/`permissions`) in global auth state
- [ ] `isAuthenticated` becomes true immediately after exchange
- [ ] Protected route guards re-evaluate after social login
- [ ] On 401 from any later API call: clear token, redirect to login
- [ ] Logout clears the social login token like a normal login
- [ ] If the social email already has an account, log into it (no duplicate account creation)

---

## Jest Test Cases

### SocialLoginService API layer
1. `getSocialUrl(provider, 'web')` — GET `/api/v1/social/{provider}` → returns `data.url`
2. `getSocialUrl(provider, 'mobile')` — GET `/api/v1/social/{provider}?type=mobile` → returns `data.url`
3. `getSocialUrl` without `type` — defaults to web (`state=web` on provider)
4. `exchangeSocialCode(code)` — POST `/api/v1/social/exchange` → returns `{ token, user }`
5. `exchangeSocialCode` with used/replayed code → 400, surfaces message
6. `exchangeSocialCode` with missing code → 422, surfaces validation error
7. All social requests include `lang` header (en/ar)
8. All social API calls handle 401 → auto-logout
9. All social API calls handle 429 → rate limit message

### Social login UI flow (web)
10. Clicking Google button calls `getSocialUrl('google')` and redirects to `data.url`
11. Returning with `?code=X` triggers exchange and stores token
12. Returning with `?error=social_login_failed` shows error toast, no exchange call
13. Returning without `code` does not call exchange

### Social login UI flow (mobile)
14. Clicking Google button calls `getSocialUrl('google', 'mobile')`
15. Callback JSON `{ success: true, code }` → exchange → login
16. Callback JSON `400 { success: false, message }` → shows localized message, no exchange
17. Auth state updates correctly after social login (same as normal login)
