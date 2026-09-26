# COUPON PUBLIC CODE — ROOT CAUSE AUDIT (STAGE A, READ-ONLY)

> No application code, schema, routes, config, data, or caches were modified
> in Stage A. One additive probe test file was created as investigation
> tooling (`tests/Feature/CouponPublicCodeAuthProbeTest.php`); it changes no
> application behavior.
>
> Labels: **VERIFIED** / **INFERRED** / **UNVERIFIED**.

---

# 1. Executive Summary

The reported symptom (authenticated customer sees public coupons with
`"code": null`) is the **correct guest output served to a request whose
user context resolved to null**. The backend authorization, visibility,
claim, resource, and cache layers were each traced and proven correct with
real-token runtime evidence. Classification: **B (authentication/context)**.

```text
ROOT CAUSE FOUND
```

Precisely: `CouponDiscoveryPolicy::decide()` returns `can_expose_code=false`
iff `$user === null`, and `CustomerCouponResource` renders `code: null`
from that decision. A real, unexpired Sanctum Bearer token on this route
resolves a user and yields the code (proven). Therefore every observed
`code: null` row came from a request carrying **no valid token** — missing
header, expired token (1-week TTL, Sanctum-enforced), or revoked/invalid
token. The documented frontend client sends `Authorization` only `if
(authToken)`, and clears nothing until a 401 — while this public route
returns 200 for token-less requests, so stale sessions degrade silently
into the guest shape instead of re-authenticating.

# 2. Reproduction

- Reported: `GET /api/v1/general/coupons` → items shaped as
  `CustomerCouponResource` (`borderColor`/`borderless`, no `claim_status`)
  with `visibility: public`, `requires_claim: false`, `code: null`
  (coupons 19, 20).
- Probed locally (`CouponPublicCodeAuthProbeTest`, real tokens, no
  `actingAs` shortcut):
  - valid Bearer → `code` visible (3 assertions pass);
  - expired Bearer (`expires_at` past) → HTTP 200 guest shape, `code: null`;
  - guest cache warmed → authenticated request still correct; reverse order
    also correct (no cross-contamination).

# 3. Authentication Proof (no secrets exposed)

- Route: `GET v1/general/coupons` in the public group (`api`,
  `throttle:public-api`) — **no `auth:sanctum` middleware**, auth optional
  [VERIFIED `routes/api.php:48-72`, `Kernel.php:42-51`].
- Guard: default guard is `sanctum` (`config/auth.php:17`); Sanctum
  registers the guard driver; `$request->user()` resolves Bearer tokens
  without route middleware [VERIFIED by probe 1 + Sanctum `Guard` source].
- `AUTHENTICATION ROOT CAUSE (code defect) = NO`: with a valid token the
  user resolves and the code is exposed. The defect is not in guard
  wiring — it is the absence of a valid token on the failing requests.
- Middleware audit: `ChannelMiddleware` sets only `ChannelContext`
  (filtering scope); touches no auth/DB/user state [VERIFIED]. No custom
  auth middleware alters the context. No Octane (fresh container per
  request; guard memoization is test-process-only) [VERIFIED].
- Token lifecycle [VERIFIED]: `HasApiTokens` on `Marvel User`; tokens
  issued with 1-week expiry (`UserController`); Sanctum `Guard::
  isValidAccessToken` rejects past-`expires_at` tokens
  (`vendor/.../sanctum/src/Guard.php:160`).

# 4. Data Flow

```text
DB (coupons.code = HAPPY20 intact)
  ↓  Coupon::valid() + discovery predicate + with(targeting)
Service (CouponService::getCoupons): policy->decide($coupon, $user)
  $user === null → can_expose_code=false            ← FIRST null-origin
  ↓  setRelation('discoveryDecision')
Resource (CustomerCouponResource): 'code' => decision ? code : null
  ↓  (guest vs per-user cache entries — segregated keys)
JSON: "code": null
```

- DB/query: code column selected, never nulled [VERIFIED].
- requires_claim: `coupon_targetings.require_claim` via
  `CouponClaimRequirement`; public (no targeting) ⇒ `false` — never used
  as an auth/visibility proxy [VERIFIED].
- Visibility: targeting-row presence; `requires_claim=false` never
  conflated with public [VERIFIED].
- Category: **B**.

# 5. Exact Root Cause

`App\Services\Coupon\Discovery\CouponDiscoveryPolicy::decide()`,
`can_expose_code = $user !== null && (...)` — correct rule — evaluated
with `$user = null` because `CouponController@index`'s
`$request->user()` found no valid Bearer token on the failing requests.
Downstream (`CustomerCouponResource`, per-user/guest cache split) behaved
exactly as designed given that input. There is no transformation bug to
fix; the input (request auth state) was guest.

# 6. Why `code` Becomes Null

Single condition, no other path: null user → policy denies exposure →
resource renders null → (guest cache entry) → JSON null. Proven by
construction (code read) and by runtime probes in both directions.

# 7. Cache Analysis

- Keys: guests `md5(url)` / authenticated `md5(url:user:id)` with 60s TTL
  [VERIFIED `CouponController@index`]. Segregation proven live both ways
  (probe 3) — cache does **not** contribute.
- Pre-deploy stale entries ruled out by response shape (old entries lack
  `visibility`/`requires_claim` keys entirely) [INFERRED, high confidence].
- Verdict: cache architecturally sound; do not "fix" by clearing.

# 8. Business Rule Validation

All five authoritative combinations hold in code and in the passing matrix
suites (`CouponGeneralDiscoveryTest` 9/9,
`AvailableCouponsApiTest` 12/12): guest/public hidden; auth/public shown;
targeted+claim hidden (+`claim` action on `available`); targeted+noclaim
shown; ineligible absent [VERIFIED].

# 9. Security Impact

- No over-exposure: guests and claim-first coupons never receive codes
  through any field (leak scans in tests) [VERIFIED].
- No cross-user leakage (segregated cache, per-request policy)
  [VERIFIED].
- Current exposure is UNDER-exposure for stale-token holders, with a UX
  cost (silent guest degradation, no re-login signal) — the Stage B target.

# 10. Proposed Fix (Stage B, minimal)

In `CouponController@index` only: when an `Authorization: Bearer` header is
**present but unresolvable** (expired/revoked/garbage token), return 401
instead of the silent guest shape, and log a token-free diagnostic line.
Guests without any header keep the public listing unchanged. This converts
stale sessions into the frontend's existing 401 flow (clear token →
re-login → codes appear) without touching policy, resources, guards,
targeting, claims, or cache architecture. No Sanctum weakening; strictly
stricter.

# 11. Regression Risks

- Clients sending dead tokens while casually browsing will now get 401
  instead of a public list (intended; matches the documented client 401
  flow). Low risk, monitorable via the new log line.
- None to policy/resource/cache/claim/targeting (untouched).
