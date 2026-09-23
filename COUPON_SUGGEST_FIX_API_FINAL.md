# COUPON SUGGEST-FIX API — FINAL REPORT

> `POST /api/v1/admin/coupons/{id}/suggest-fix` remediation.
> Status: IMPLEMENTED + TESTED + REGRESSION VERIFIED.

---

# Purpose

Turn `suggestFix` from a developer-oriented hint (it returned
`CouponAssignment::create(...)` / `::where(...)->update(...)` PHP snippets)
into business-level, actionable Admin guidance. The endpoint name promises a
suggested fix; the response now delivers one an Admin/Product user can
actually follow.

# Business Meaning

- **Public coupon** = no `coupon_assignments` rows (`Coupon::isPublic()`).
  Single use per customer is enforced by the prior-use check
  (`coupon_usages.used_at`, via `CouponValidator` for public flows and
  `CouponOrchestrator::rejectIfPubliclyUsed` for assigned flows) [VERIFIED].
- **Multi-use per user** = the customer holds an assignment with
  `used < max_uses` (`CouponAssignmentValidator`; orchestrator bypasses the
  prior-use check with a null user for assignment holders) [VERIFIED].
  `max_uses = 5` really means that customer can redeem five times.
- **Single-use per user** = public coupon, or assignment with `max_uses = 1`
  [VERIFIED].

# What Was Wrong With the Old Response

1. Returned `example_code` with Eloquent calls — meaningless and
   unactionable for Admin users, and an internal-implementation leak.
2. Multi-use update branch returned a raw `->update(...)` query as a "step".
3. `remove_assignments_or_set_max_uses_1` casually recommended deleting
   assignments (destroys eligibility, claim state, usage history,
   notifications) as if equivalent to a limit change.
4. No targeting/claim awareness; no warnings; no expected result; steps were
   numbered code-flavored strings.

# Supported desired_behavior Values

`multi_use_per_user`, `single_use_per_user`. Anything else → 400 with
`data.allowed_values`. Endpoint URL, Sanctum auth, and admin permission
checks are unchanged.

# Response Contract

```json
{
  "current_state": "string — what the coupon looks like now",
  "recommended_action": "convert_to_assigned | update_assignments | no_change_needed",
  "summary": "string — one-line recommendation",
  "steps": ["string — admin-doable actions, possibly empty"],
  "expected_result": "string — outcome after following the steps",
  "warnings": ["string — consequences, limitations, interactions"],
  "available_actions": [
    { "label": "Create coupon assignment", "method": "POST", "path": "/api/v1/coupons/{id}/assignments" }
  ]
}
```

Type guarantees (test-enforced): `steps`/`warnings` are string arrays,
`recommended_action`/`summary`/`expected_result` are strings, no
`example_code`, no `::`, no `->`, no SQL, no class names anywhere in the
payload. The endpoint modifies nothing — applying a suggestion is a
separate admin action.

# Multi-Use Recommendation

- Public → `convert_to_assigned`: select customers → create one assignment
  each → set per-customer max uses → verify. Warns that the first
  assignment converts public→assigned (unassigned customers lose access)
  and that usage history is preserved.
- Assigned `max_uses = 1` → `update_assignments`: per-assignment raise via
  the assignments API. Warns there is no bulk update and past uses are kept.
- Assigned `max_uses > 1` → `no_change_needed`, empty steps.

# Single-Use Recommendation

- Public or assigned `max_uses = 1` → `no_change_needed`, empty steps.
- Assigned `max_uses > 1` → `update_assignments` (lower to one per
  assignment). Removal is never recommended. Warns the limit cannot go
  below already-recorded uses (API-enforced) and history is preserved.

# Targeting Interaction

Assignment limits and targeting are orthogonal concepts; the engine never
conflates them, and neither do the suggestions. Two targeting-aware
warnings are appended whenever proven by the row:

- `mode = dynamic`: assignments alone do not affect dynamic evaluation
  (the orchestrator skips the assignment gate for pure-dynamic mode);
  changing the mode is a separate admin action via the targeting API.
- Other modes (`assignment`, `assignment_and_dynamic`,
  `assignment_or_dynamic`) honor assignments — no warning needed.

# Claim Interaction

If `coupon_targetings.require_claim` is set, every suggestion carries:
"Customers may need to claim the coupon before they can use it… Changing
usage limits does not change the claim requirement." Suggestions never
bypass or alter claim semantics.

# Examples

Public → multi-use (`convert_to_assigned`): current_state explains
public/single-use; 5 steps (select → create → set limit → verify →
customers redeem up to limit); expected "independent usage limit" per
customer; warnings cover audience change + history + claim/dynamic when
applicable; available_actions link POST/GET assignments.

Assigned `max_uses=5` → single-use (`update_assignments`): steps open list →
select → set one → save → verify; warnings cover no-bulk, floor at recorded
uses, preserved history.

# Edge Cases

- Coupon with assignments where max varies per customer: decision uses the
  maximum (`assignments()->max('max_uses')`), matching `getUsageInfo` and
  `getUsageDescription` conventions.
- Missing `coupon_targetings` table (odd environments): targeting warnings
  are skipped defensively (`safeTargeting`), never fatal.
- Non-existent coupon: 404 via `findOrFail` (unchanged).
- Unauthenticated/forbidden: 401/403 via unchanged `authorizeAdmin`.

# Validation

- New `tests/Feature/CouponSuggestFixTest.php`: 8 tests / 172 assertions —
  all six behavior cases + targeting/claim warnings + invalid input +
  contract/no-leak guard on every response.
- Updated `CouponConfigurationTest::suggests_conversion_for_public_to_multi_use`
  (no more `example_code`).
- Regression: `CouponConfigurationTest` 8/8, `SecurityRemediationTest
  --filter coupon_config` 3/3, `CouponAssignment` 43/43 — all green.

# Files Changed

- `app/Http/Controllers/Api/Admin/CouponConfigurationController.php`
  (`suggestFix` rewritten + two private helpers; auth/URL/values preserved).
- `tests/Feature/CouponConfigurationTest.php` (contract assertion update).
- `tests/Feature/CouponSuggestFixTest.php` (new).

# Final Verification

Acceptance criteria: no PHP/Eloquent/SQL/class names in responses (test
scans every payload) ✓; steps admin-doable via verified existing endpoints
(POST/PUT/GET assignments, PUT targeting) with explicit no-bulk notice ✓;
no destructive recommendation (removal path deleted) ✓; targeting/claim
never contradicted (warnings) ✓; multi/single semantics match runtime
enforcement (verified against validator/orchestrator code) ✓; auth,
business rules, assignment/targeting/claim/usage semantics unchanged ✓;
invalid input rejected with allowed values ✓; suites green ✓.

Remaining (unverified): Admin-UI wording review with a non-technical
reviewer; Arabic localization if the Admin API later requires it (current
contract is English, consistent with the existing endpoint).
