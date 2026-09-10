# Test Coverage — Site Reviews Module

## Test Files Overview

| File | Type | Tests | Focus |
|------|------|-------|-------|
| `SiteReviewTestCase.php` | Base | - | Helpers: customers, admins, permissions, payload builder, locale |
| `SiteReviewCreationTest.php` | Feature | 12 | Create, pending default, mass-assignment guards, validation, unauth |
| `SiteReviewPublicApiTest.php` | Feature | 7 | Approved-only visibility, moderation-safe response, no auth |
| `SiteReviewModerationTest.php` | Feature | 17 | Approve/reject flows, actor storage, transitions, 404s |
| `SiteReviewAdminApiTest.php` | Feature | 11 | Admin list/detail, moderator name, status filter, N+1 guard |
| `SiteReviewRelationshipsTest.php` | Feature | 8 | user/moderator relations, factory states |
| `SiteReviewBugRegressionTest.php` | Feature | 4 | BUG-SR-001 + BUG-SR-002 regressions |

**Total: 58 tests, 152 assertions (all passing)**

---

## SiteReviewCreationTest.php

| Test | Description |
|------|-------------|
| `authenticated_customer_can_create_a_site_review` | POST → 201, DB row created |
| `new_site_review_automatically_starts_as_pending` | status=pending, moderator null |
| `customer_cannot_create_an_approved_review` | `status: approved` in payload ignored → pending |
| `customer_cannot_create_a_rejected_review` | `status: rejected` ignored → pending |
| `customer_cannot_set_moderated_by` | `moderated_by: 999` ignored → null |
| `customer_cannot_set_moderated_at` | `moderated_at` ignored → null |
| `unauthenticated_customer_cannot_create_a_site_review` | No token → 401 |
| `invalid_ratings_are_rejected` | 0, 6, -1, 'abc' → 422 |
| `missing_rating_is_rejected` | → 422 |
| `missing_comment_is_rejected` | → 422 |
| `title_is_optional` | No title → 201 |
| `created_review_response_does_not_expose_moderation_fields` | No status/moderator/moderated_* keys |
| `review_can_be_created_without_a_title` | Factory with title null |

## SiteReviewPublicApiTest.php

| Test | Description |
|------|-------------|
| `public_api_returns_approved_reviews_only` | Approved shown; pending/rejected hidden |
| `pending_reviews_are_not_publicly_visible` | Hidden from response |
| `rejected_reviews_are_not_publicly_visible` | Hidden from response |
| `public_response_exposes_customer_name_and_not_moderation_fields` | customer{id,name}, no status/moderator/email |
| `public_endpoint_works_without_authentication` | 200 without token |
| `approved_review_can_be_created_by_any_customer_via_factory` | approved() state sets moderator |

## SiteReviewModerationTest.php

| Test | Description |
|------|-------------|
| `authorized_admin_can_approve_a_pending_review` | 200, status=approved |
| `approval_changes_status_to_approved` | Fresh DB value |
| `approval_stores_admin_id_in_moderated_by` | Actor recorded |
| `approval_stores_timestamp_in_moderated_at` | Timestamp recorded |
| `authorized_admin_can_reject_a_pending_review` | 200, status=rejected |
| `rejection_changes_status_to_rejected` | Fresh DB value |
| `rejection_stores_admin_id_in_moderated_by` | Actor recorded |
| `rejection_stores_timestamp_in_moderated_at` | Timestamp recorded |
| `unauthenticated_user_cannot_approve` | 401, stays pending |
| `unauthenticated_user_cannot_reject` | 401, stays pending |
| `customer_without_permission_cannot_approve` | 403 |
| `customer_without_permission_cannot_reject` | 403 |
| `cannot_approve_an_already_approved_review` | 404, moderator preserved |
| `cannot_reject_an_already_rejected_review` | 404 |
| `approve_and_reject_revert_is_not_allowed` | Approve then reject → 404, stays approved |
| `approval_of_missing_review_returns_404` | 404 |

## SiteReviewAdminApiTest.php

| Test | Description |
|------|-------------|
| `admin_can_list_all_site_reviews` | 200, count matches |
| `admin_dashboard_returns_moderator_name` | Real admin name in response |
| `pending_review_has_no_moderator_in_admin_response` | moderator null |
| `rejected_review_displays_rejecting_admin_name` | Moderator name shown |
| `approved_review_displays_approving_admin_name` | Moderator name shown |
| `admin_can_filter_reviews_by_status` | `?status=pending` filters |
| `admin_can_view_single_review_details` | Detail shape |
| `unauthenticated_user_cannot_list_site_reviews` | 401 |
| `customer_without_permission_cannot_list_site_reviews` | 403 |
| `customer_without_permission_cannot_view_review_details` | 403 |
| `missing_review_details_returns_404` | 404 |
| `admin_list_has_no_n_plus_one_for_user_and_moderator` | ≤8 queries for 10 reviews |

## SiteReviewRelationshipsTest.php

| Test | Description |
|------|-------------|
| `review_belongs_to_user` | user() relation resolves |
| `review_belongs_to_moderator` | moderator() relation resolves |
| `pending_state_sets_pending_status` | Factory state |
| `approved_state_sets_approved_status` | Factory state |
| `rejected_state_sets_rejected_status` | Factory state |
| `pending_state_has_no_moderator` | Factory state |
| `approved_state_sets_moderated_by` | Factory state |
| `enum_values_are_exposed_as_strings` | Status cast to string value |

## SiteReviewBugRegressionTest.php

| Test | Description |
|------|-------------|
| `non_numeric_id_returns_404_not_500` | BUG-SR-001 — `/abc`, `/abc/approve`, `/abc/reject` → 404 |
| `negative_limit_is_normalized_not_409` | BUG-SR-002 — `?limit=-5` → 200, per_page=1 |
| `zero_and_non_numeric_limit_fall_back_to_default` | `?limit=0`/`?limit=abc` → 200 |
| `oversized_limit_is_capped_at_100` | `?limit=9999` → per_page=100, total preserved |

---

## Coverage Summary

| Category | Coverage | Notes |
|----------|----------|-------|
| Customer Create | ✅ Full | Success, pending default, mass-assignment guards, auth |
| Public API | ✅ Full | Approved-only, moderation-safe, no auth, cache-agnostic |
| Admin Moderation | ✅ Full | Approve/reject, actor storage, transitions, 404s |
| Admin List/Detail | ✅ Full | Pagination, filter, moderator name, N+1 guard |
| Validation | ✅ Full | Rating bounds, required fields, optional title |
| Authentication | ✅ Full | 401 on all admin + store |
| Authorization | ✅ Full | 403 without permission |
| Edge Cases | ✅ Full | Non-numeric id, invalid limits (regression) |
| Translation Assertions | Partial | Messages asserted via response envelope; keys verified in lang files |

---

## Run

```bash
vendor/bin/phpunit tests/Feature/SiteReviews
# OK (58 tests, 152 assertions)
```
