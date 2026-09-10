# Site Reviews — Backend Jira Tasks

## Task 1: Add `whereNumber('id')` to Admin `{id}` Routes

**Component:** Routes
**File:** `packages/marvel/src/Rest/Routes.php`
**Status:** ✅ Done (2026-08-10)

**Description:** `show`/`approve`/`reject` returned HTTP 500 (`TypeError`) for non-numeric ids. Added `->whereNumber('id')` to the three `{id}` routes so invalid ids produce 404 (BUG-SR-001).

---

## Task 2: Normalize `limit` Parameter in Admin List

**Component:** Admin Controller
**File:** `packages/marvel/src/Http/Controllers/SiteReviewController.php`
**Status:** ✅ Done (2026-08-10)

**Description:** `?limit=-5` caused SQL `LIMIT -5` → 409; `0`/`abc` silently fell back. Now `min(max((int)?limit, 1), 100)` (default 15) — BUG-SR-002.

---

## Task 3: Add Regression Tests

**Component:** Tests
**File:** `tests/Feature/SiteReviews/SiteReviewBugRegressionTest.php`
**Status:** ✅ Done (2026-08-10)

**Description:** 4 regression tests covering non-numeric ids and invalid/oversized limit values. Full suite now 58 tests / 152 assertions.

---

## Task 4: Add DB CHECK Constraint on `rating` (Optional)

**Component:** Database
**Status:** ❌ Open

**Description:** `rating` 1–5 is enforced only in `CreateSiteReviewRequest`. Consider a follow-up migration adding `rating BETWEEN 1 AND 5` CHECK (BUG-SR-004). Low priority — all writes flow through validation.

---

## Task 5: Remove Redundant Indexes (Optional)

**Component:** Database
**Status:** ❌ Open

**Description:** Drop `idx_site_reviews_user_id` and `idx_site_reviews_moderated_by` (duplicate the auto-created FK indexes); keep `idx_site_reviews_status` (BUG-SR-007). Requires `composer dump-autoload` + migration if applied.

---

## Task 6: Differentiate "Not Found" vs "Already Moderated" (Optional)

**Component:** Service / Admin Controller
**Status:** ❌ Open

**Description:** `moderate()` returns `null` for both missing and already-moderated reviews → controller always returns 404. If the API contract requires a distinct 409 for already-moderated, refactor `moderate()` to signal the case (BUG-SR-006).

---

## Task 7: Run Full Regression

**Component:** QA
**Status:** ✅ Done (2026-08-10)

**Description:** `vendor/bin/phpunit tests/Feature/SiteReviews` — OK (58 tests, 152 assertions). No regressions in the module suite.
