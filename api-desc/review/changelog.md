# Review Module — Changelog

## [1.0.0] — 2026-07-19

### Added
- Comprehensive API investigation documentation (`api-desc/review/`)
- Reviews API: full CRUD (index, store, show, update, destroy) + toggle-approve
- Rate limiting on store/update (5 requests/min per user via `throttle:content`)
- Review approval toggle for moderation
- Review image upload support (Spatie Media Library) — validation currently commented out
- Feedback (positive/negative) and abusive reports morph relationships on Review model

### Known Issues

1. **Missing translation keys** — `MESSAGE.REVIEW_CREATED_SUCCESSFULLY`, `MESSAGE.REVIEW_UPDATED_SUCCESSFULLY`, `MESSAGE.REVIEW_DELETED_SUCCESSFULLY`, and `ERROR.ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT` (English) are missing from `resources/lang/en/message.php` and `resources/lang/ar/message.php` (success keys only). API responses will display constant paths instead of human-readable messages.

2. **`updateReview()` is public** — The helper method in `ReviewController` is declared `public` but is only called internally by `update()`. Should be `private`.

3. **No database unique constraint on (user_id, product_id)** — Race conditions could allow duplicate reviews for the same product by the same user.

4. **Images validation commented out** — The `images` field is commented out in both `ReviewCreateRequest` and `ReviewUpdateRequest`, but the repository still handles image uploads when present.

5. **No transaction on `toggleApprove()`** — Unlike `storeReview()` and `updateReview()`, the `toggleApprove()` method does not wrap the update in a database transaction.

6. **No restore/force-delete endpoints** — Reviews are soft-deleted only. No admin API endpoint exists for restore or force delete.

7. **No dedicated test file** — Review tests exist only within `tests/Feature/ProductCrudTest.php` (14 tests). No separate `ReviewApiTest.php` or `ReviewProductionHardenTest.php` exists.
