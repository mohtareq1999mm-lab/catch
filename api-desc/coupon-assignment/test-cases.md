# Coupon Assignment — Test Coverage

---

## Test Suites

### CouponAssignmentApiTest

**File:** `tests/Feature/CouponAssignment/CouponAssignmentApiTest.php`
**Tests:** 30 | **Assertions:** 101

#### Authentication & Authorization (2 tests)
- `unauthenticated_user_cannot_access_any_endpoint` — 5 endpoints all return 401
- `non_admin_user_gets_forbidden` — 5 endpoints all return 403

#### List / Index (4 tests)
- `admin_can_list_assignments` — 2 assignments in list, response structure verified
- `index_returns_empty_when_no_assignments` — empty result, total = 0
- `index_respects_per_page_parameter` — pagination with `?limit=2`
- `index_returns_only_assignments_for_the_specified_coupon` — cross-coupon isolation

#### Create / Store (5 tests)
- `admin_can_create_assignment` — full resource fields + database assertion
- `admin_can_create_assignment_with_expires_at` — future expiry accepted
- `cannot_create_duplicate_assignment` — 409 on duplicate `(coupon_id, user_id)`
- `cannot_create_assignment_for_non_existent_coupon` — 404

#### Show (3 tests)
- `admin_can_show_assignment` — resource fields + user data verified
- `show_returns_404_for_non_existent_assignment` — 404
- `show_returns_404_when_assignment_belongs_to_different_coupon` — cross-coupon 404

#### Update (5 tests)
- `admin_can_update_max_uses` — max_uses updated, remaining recalculated
- `admin_can_update_expires_at` — expiry updated
- `admin_can_set_expires_at_to_null` — null clears expiry
- `cannot_update_max_uses_below_current_usage` — 422
- `update_returns_404_for_non_existent_assignment` — 404

#### Delete / Destroy (3 tests)
- `admin_can_delete_assignment_without_usage` — success, DB row removed
- `cannot_delete_assignment_with_usage_history` — 409, DB row preserved
- `delete_returns_404_for_non_existent_assignment` — 404

#### Resource Computed Fields (6 tests)
- `resource_shows_remaining_as_max_uses_minus_used` — remaining = 7
- `resource_shows_remaining_as_zero_when_exhausted` — remaining = 0 (clamped)
- `resource_shows_is_expired_true_when_expired` — past expiry = true
- `resource_shows_is_expired_false_when_not_expired` — future expiry = false
- `resource_shows_is_expired_false_when_no_expiry` — null expiry = false
- `resource_includes_user_data_when_loaded` — user id, name, email present

#### Regression (2 tests)
- `coupon_with_zero_assignments_remains_public` — empty assignments = public
- `deleting_an_assignment_restores_one_unit_of_quota_per_user` — delete, then verify empty

---

### CouponAssignmentValidationTest

**File:** `tests/Feature/CouponAssignment/CouponAssignmentValidationTest.php`
**Tests:** 13 | **Assertions:** 50

#### Store Validation (7 tests)
- `store_requires_user_id` — missing user_id → 422
- `store_user_id_must_exist` — non-existent user_id → 422
- `store_requires_max_uses` — missing max_uses → 422
- `store_max_uses_must_be_integer` — non-integer max_uses → 422
- `store_max_uses_must_be_at_least_one` — max_uses = 0 → 422
- `store_expires_at_must_be_valid_date` — invalid date string → 422
- `store_expires_at_must_be_in_the_future` — past date → 422

#### Update Validation (5 tests)
- `update_max_uses_must_be_integer` — non-integer → 422
- `update_max_uses_must_be_at_least_one` — max_uses = 0 → 422
- `update_max_uses_is_optional` — empty body → 200 (no change)
- `update_expires_at_must_be_valid_date` — invalid date → 422
- `update_expires_at_must_be_in_the_future` — past date → 422

#### Update expiry null (1 test)
- `update_expires_at_null_clears_expiry` — explicit null → expires_at = null

#### Edge Cases (1 test)
- `store_with_all_fields_valid_succeeds` — happy path with all fields

---

## Total Coverage

| Suite | Tests | Assertions |
|-------|-------|------------|
| CouponAssignmentApiTest | 30 | 101 |
| CouponAssignmentValidationTest | 13 | 50 |
| **Total** | **43** | **151** |
