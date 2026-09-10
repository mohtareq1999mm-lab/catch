# Bug Report — Site Reviews Module

---

## BUG-SR-001: Non-Numeric `{id}` Returns HTTP 500 Instead of 404

**Severity:** High

**Component:** `packages/marvel/src/Http/Controllers/SiteReviewController.php` + `packages/marvel/src/Rest/Routes.php`

**Status:** ✅ FIXED (2026-08-10)

**Description:** The admin methods `show(int $id)`, `approve(Request, int $id)`, `reject(Request, int $id)` declare a native `int` type-hint on the `$id` parameter. Calling the endpoint with a non-numeric id (e.g., `GET /api/v1/site-reviews/abc`, `PATCH /api/v1/site-reviews/abc/approve`) raised a PHP `TypeError` — `Argument #1 ($id) must be of type int, string given` — which the global handler converts to **HTTP 500** instead of the expected **404 Not Found**.

**Reproduction:**
```bash
curl -X GET "http://example.com/api/v1/site-reviews/abc" \
  -H "Authorization: Bearer {token}"   # → 500 TypeError
```

**Root Cause:** Route `{id}` accepts any string segment; the controller type-hint then throws for non-numeric values.

**Fix:** Added `->whereNumber('id')` to all three `{id}` admin routes (matching the existing `cart/{id}` pattern at `Routes.php:282`). Non-numeric ids now fail route matching → 404.

**Regression Tests:** `SiteReviewBugRegressionTest::non_numeric_id_returns_404_not_500`

---

## BUG-SR-002: Unvalidated `limit` Query Parameter — 409 / Silent Fallback

**Severity:** Medium

**Component:** `packages/marvel/src/Http/Controllers/SiteReviewController.php::index()`

**Status:** ✅ FIXED (2026-08-10)

**Description:** The admin list endpoint accepted an unvalidated `limit` parameter:

```php
$limit = $request->limit ?: 15;
$reviews = $this->siteReviewService->getAllReviews($status, (int) $limit);
```

- `?limit=-5` → `paginate(-5)` → SQL `LIMIT -5` → `QueryException` → HTTP **409**.
- `?limit=0` and `?limit=abc` → PHP-falsy/zero cast → silently fell back to the model default, hiding the caller's intent.
- No upper bound — `?limit=999999` could request an unbounded page.

**Fix:** Normalized in the controller:
```php
$limit = max(1, min((int) $request->query('limit', 15), 100));
```
Now `0`, `-5`, `abc` → clamped to `1`; values > 100 capped at 100; default 15.

**Regression Tests:** `SiteReviewBugRegressionTest` (`negative_limit_is_normalized_not_409`, `zero_and_non_numeric_limit_fall_back_to_default`, `oversized_limit_is_capped_at_100`).

---

## BUG-SR-003: Public List Cached with 4h TTL — Stale After Non-Moderation Changes

**Severity:** Low (by design)

**Component:** `app/Http/Controllers/Api/General/SiteReviewController.php::index()`

**Status:** ❌ Open (documented)

**Description:** The public approved-reviews list is cached for 4 hours under the `site_reviews` tag. It is flushed on admin approve/reject, but there is no mechanism to invalidate on other mutations (none exist today — no update/delete endpoints). If a moderation revert or data fix is applied directly, the public list can be stale for up to 4 hours.

**Recommendation:** Acceptable as-is (only moderation mutates status, and moderation flushes the tag). If a future bulk/delete admin endpoint is added, it must also flush the tag.

---

## BUG-SR-004: `rating` Enforced Only at Application Layer

**Severity:** Low

**Component:** `database/migrations/2026_08_10_000001_create_site_reviews_table.php`

**Status:** ❌ Open (documented)

**Description:** `rating` is `tinyint unsigned` with no database CHECK constraint. The 1–5 range is enforced only by `CreateSiteReviewRequest` validation. A direct database insert could store any 0–255 value.

**Recommendation:** Optionally add a CHECK constraint (`rating BETWEEN 1 AND 5`) in a follow-up migration. Low risk since all writes flow through the validated endpoint.

---

## BUG-SR-005: No Unique Constraint on Site Reviews Per User

**Severity:** Low (by design)

**Component:** Database

**Status:** ❌ Open (by design)

**Description:** A customer can submit an unlimited number of website reviews — there is no `(user_id)` unique constraint and no service-level dedup check. This is intentional: this is website-level feedback (unlike product reviews which are deduped per product). Flagged for awareness — if one-review-per-customer is ever required, add a DB unique index and a guard in `createReview()`.

---

## BUG-SR-006: `moderate()` Returns `null` for Both "Not Found" and "Already Moderated"

**Severity:** Low

**Component:** `app/Services/SiteReview/SiteReviewService.php::moderate()`

**Status:** ❌ Open (documented)

**Description:** `moderate()` collapses two distinct cases into one `null` return: review missing **and** review already moderated. The controller maps both to **404 `SITE_REVIEW_NOT_FOUND`**, which is semantically imprecise for "already approved/rejected" (a 409 Conflict or a distinct message would be more accurate).

**Recommendation:** For strict REST semantics, differentiate (e.g., throw a typed exception or return a status flag). Current behavior is safe (no unintended transitions) and covered by tests; change only if the API contract requires a distinct code.

---

## BUG-SR-007: Redundant Indexes on `user_id` / `moderated_by`

**Severity:** Low (cosmetic/perf)

**Component:** Migration

**Status:** ❌ Open (documented)

**Description:** `foreignId('user_id')->constrained()` and `foreignId('moderated_by')->constrained()` auto-create FK indexes (`site_reviews_user_id_foreign`, `site_reviews_moderated_by_foreign`). The migration additionally defines `idx_site_reviews_user_id` and `idx_site_reviews_moderated_by` on the same columns — redundant index storage/write overhead.

**Recommendation:** Drop the two explicit redundant indexes in a follow-up migration (keep `idx_site_reviews_status` which is genuinely used by the status filter).
