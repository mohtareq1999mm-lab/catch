# COUPON PUBLIC CODE — FIX FINAL REPORT (STAGE B)

> Labels: **VERIFIED** / **INFERRED** / **UNVERIFIED**.
> Full evidence: `COUPON_PUBLIC_CODE_ROOT_CAUSE_AUDIT.md` (Stage A).

---

## 1. Root Cause

`code: null` on public rows ⟺ `$request->user() === null` at
`CouponDiscoveryPolicy::decide()` (class B: authentication/context). The
policy, resource, eligibility, claim, and cache layers were each proven
correct — a real, unexpired Bearer token yields the code. The failing
production requests carried no resolvable user (missing header — the
documented client sends `Authorization` only `if (authToken)` — or expired
1-week/revoked/garbage token, all of which Sanctum resolves to null on
this middleware-free public route).

## 2. Files Changed

- `app/Http/Controllers/Api/General/CouponController.php` (`index` only):
  Bearer-present-but-unresolvable → 401 + token-free diagnostic log.
- `tests/Feature/CouponDiscoveryAuthContextTest.php` (NEW, 5 tests).
- Stage A probe file removed (superseded by the regression suite above).

## 3. Exact Fix

```php
$user = $request->user();

if ($user === null && $request->bearerToken() !== null) {
    \Illuminate\Support\Facades\Log::info('coupon.discovery.unresolvable_bearer_token', [
        'path' => $request->path(),
    ]);

    return $this->apiResponse('Unauthenticated.', 401, false);
}
```

Guests without any header are untouched (public listing, codes hidden).
No policy/resource/guard/targeting/claim/cache change — none was
warranted, and changing code-exposure without identity would violate the
security rule.

## 4. Why The Fix Is Correct

- It acts exactly on the proven failure input (dead credential presented)
  and nowhere else; all five matrix outcomes are byte-identical otherwise.
- It converts silent guest-degradation into the client's existing 401 flow
  (clear token → re-login → codes appear) — the only path by which a
  stale-session customer can receive codes.
- It is strictly stricter: Sanctum untouched, no bypass, no weakening;
  `available` (auth-guarded) already behaves this way, so the two
  discovery endpoints are now consistent on dead credentials.

## 5. Security Analysis

- No over-exposure added: 401 reveals nothing; guest hiding, claim-first
  hiding, and ineligible exclusion unchanged [VERIFIED by suites].
- No token material in logs (path only) [VERIFIED by code].
- No cross-user effect: decision is per-request; cache keys already
  segregate guest vs per-user entries (proven both directions)
  [VERIFIED].

## 6. Cache Analysis

Unchanged and proven sound: segregated keys, short per-user TTL, no
poisoning either direction (live test). Pre-deploy stale entries excluded
by response shape. No clearing needed or performed.

## 7. Authentication Verification

| Case | Expected | Actual | Status |
|------|----------|--------|--------|
| Valid Bearer (real token, no actingAs) | 200 + code | 200 + code | PASS |
| Expired Bearer | 401 | 401 | PASS |
| Garbage Bearer | 401 | 401 | PASS |
| No header (guest) | 200 guest shape | 200 guest shape | PASS |
| Guest→auth→guest cache order | no contamination | none | PASS |

## 8. Coupon Matrix Verification

Re-ran post-fix: `CouponGeneralDiscoveryTest` 9/9 (public/targeted±claim/
ineligible/expired/disabled/assignment-only/guest/dedupe/filters),
`AvailableCouponsApiTest` 12/12, `CouponRemediationTest` 15/15,
`SecurityRemediationTest --filter coupon_config` 3/3,
`CouponConfigurationTest` 8/8, `CouponSuggestFixTest` 8/8,
`CouponNotificationRequiresClaimTest` 9/9 — all PASS.

## 9. Regression Verification

Only `index()` gained a branch, taken solely when a Bearer header is
present but unresolvable; all suites above green. Pre-existing failures
elsewhere (order/auth/real-Pusher, one eligibility-semantics test) proven
identical on baseline in prior tasks and untouched.

## 10. Remaining Risks

- Clients sending dead tokens while casually browsing now get 401 instead
  of a public list (intended; monitor `coupon.discovery.unresolvable_bearer_token`).
- Frontend should send the token on this endpoint whenever possessed and
  refresh week-old sessions (client-side follow-up, out of backend scope).
- CDN/proxy header-stripping at production edge remains UNVERIFIED from
  here — if reports persist for fresh-token holders, capture edge request
  headers next.

## 11. Final Status

```text
PASS
```

Matrix verified end-to-end against the real code path (real tokens, real
HTTP, real cache store); uncertainty explicitly marked above.
