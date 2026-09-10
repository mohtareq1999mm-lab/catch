# Review Module — Jira Tasks

---

## Task 1: Add Missing Review Translation Keys

**Priority:** High
**Component:** Translations
**Effort:** Small
**Files:**
- `resources/lang/en/message.php`
- `resources/lang/ar/message.php`

**Description:** The following review translation keys are missing from both language files, causing API response messages to display constant paths instead of human-readable text:

| Key | en | ar |
|-----|----|----|
| `MESSAGE.REVIEW_CREATED_SUCCESSFULLY` | Missing | Missing |
| `MESSAGE.REVIEW_UPDATED_SUCCESSFULLY` | Missing | Missing |
| `MESSAGE.REVIEW_DELETED_SUCCESSFULLY` | Missing | Missing |
| `ERROR.ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT` | Missing | Present |

**Status:** ⏳ Pending

**Acceptance Criteria:**
- [ ] All review success keys added to `resources/lang/en/message.php`
- [ ] All review success keys added to `resources/lang/ar/message.php`
- [ ] `ERROR.ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT` added to `resources/lang/en/message.php`
- [ ] API responses show proper human-readable messages for all review operations

---

## Task 2: Change `updateReview()` Visibility to Private

**Priority:** Low
**Component:** Review Controller
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Http/Controllers/ReviewController.php`

**Description:** The `updateReview()` helper method is declared `public` but is only called internally by `update()`. A public method exposes unnecessary surface area and could be called as a route action if misconfigured.

**Status:** ⏳ Pending

**Acceptance Criteria:**
- [ ] `updateReview()` changed from `public` to `private`
- [ ] `update()` can still call `updateReview()`
- [ ] No external code calls `updateReview()` (verify)

---

## Task 3: Add Database Unique Constraint on (user_id, product_id)

**Priority:** Medium
**Component:** Database
**Effort:** Small
**Files:**
- New migration file

**Description:** There is no database-level unique constraint on `(user_id, product_id)` in the `reviews` table. This creates a race condition where concurrent requests could create duplicate reviews for the same product by the same user. Add a unique composite index to prevent this at the database level.

**Status:** ⏳ Pending

**Acceptance Criteria:**
- [ ] New migration adds unique composite index on `(user_id, product_id)`
- [ ] Duplicate review attempts at the database level return integrity constraint violation
- [ ] Existing duplicate reviews are handled (if any)
- [ ] Tests verify concurrent requests cannot create duplicate reviews

---

## Task 4: Fix Images Validation Gap

**Priority:** Low
**Component:** Review Requests + Repository
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Requests/ReviewCreateRequest.php`
- `packages/marvel/src/Http/Requests/ReviewUpdateRequest.php`
- `packages/marvel/src/Database/Repositories/ReviewRepository.php`

**Description:** The `images` field validation is commented out in both Form Requests, but the repository still attempts to upload images when present. Either:
- Uncomment and properly validate the `images` field in both requests, or
- Remove image handling code from the repository if image upload is not intended

**Acceptance Criteria:**
- [ ] Decision made: keep or remove image upload functionality
- [ ] If keep: validation rules uncommented with proper mime/size limits
- [ ] If remove: image handling code removed from repository
- [ ] Tests verify image upload validation works correctly

---

## Task 5: Add Comprehensive Review Test Suite

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/ReviewApiTest.php` (new)
- `tests/Feature/ReviewProductionHardenTest.php` (new)

**Description:** Currently, review tests only exist within `tests/Feature/ProductCrudTest.php` (14 tests). Create dedicated test files for the Review module with comprehensive coverage:

- All CRUD operations (success + validation + authorization)
- Toggle approve (success + 404)
- Rate limiting (429 response)
- Edge cases: non-existent product_id, non-existent review, already reviewed
- Response JSON structure validation
- Soft delete behavior

**Acceptance Criteria:**
- [ ] `ReviewApiTest.php` created with core CRUD + toggle tests
- [ ] `ReviewProductionHardenTest.php` created with edge cases and regression tests
- [ ] All existing 14 ProductCrudTest review tests continue to pass
- [ ] Coverage includes success, validation, authorization, and edge case scenarios
