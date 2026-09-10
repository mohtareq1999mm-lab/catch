# Bug Report — Review Module

---

## BUG-RVW-001: `updateReview()` Method is Public

**Severity:** Low

**Component:** `Marvel\Http\Controllers\ReviewController`

**Description:** The `updateReview()` helper method is declared `public` but is only called internally by `update()`. A public method could be called as a route action if misconfigured.

**Code Location:** `packages/marvel/src/Http/Controllers/ReviewController.php`

**Current Behavior:**
```php
public function updateReview(ReviewUpdateRequest $request)
{
    $id = $request->id;
    return $this->repository->updateReview($request, $id);
}
```

**Recommendation:** Change visibility to `private`.

---

## BUG-RVW-002: Missing Translation Keys for Review Messages

**Severity:** Medium

**Component:** `resources/lang/en/message.php`, `resources/lang/ar/message.php`

**Description:** The following translation keys are used by the Review controller constants but are missing from the translation files:

| Key | en | ar |
|-----|----|----|
| `MESSAGE.REVIEW_CREATED_SUCCESSFULLY` | ❌ Missing | ❌ Missing |
| `MESSAGE.REVIEW_UPDATED_SUCCESSFULLY` | ❌ Missing | ❌ Missing |
| `MESSAGE.REVIEW_DELETED_SUCCESSFULLY` | ❌ Missing | ❌ Missing |
| `ERROR.ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT` | ❌ Missing | ✅ Present |

When a key is missing, Laravel's `__()` helper returns the dot-separated key string (e.g., `MESSAGE.REVIEW_CREATED_SUCCESSFULLY`) instead of a human-readable message. This means API responses for review create/update/delete will display the constant path instead of a proper message.

**Impact:** Medium — affects API response messages for all review CRUD operations.

**Recommendation:** Add the missing keys to both language files.

---

## BUG-RVW-003: No Authentication Check on `index` and `show` Routes in Public Section

**Severity:** Low

**Component:** `packages/marvel/src/Rest/Routes.php`

**Description:** Lines 344-346 define `index` and `show` routes outside the authenticated/rate-limited group. However, the controller does not check for authentication on these methods. The parent route group likely applies `auth:sanctum` globally, but if the group structure changes, these endpoints could become publicly accessible.

**Code Location:** `packages/marvel/src/Rest/Routes.php` — lines 344-346

```php
Route::apiResource('reviews', ReviewController::class, [
    'only' => ['index', 'show'],
]);
```

**Impact:** Low — currently auth is applied at the parent group level. This is a defensive concern.

---

## BUG-RVW-004: Images Validation is Commented Out But Repository Still Handles Images

**Severity:** Low

**Component:** `Marvel\Http\Requests\ReviewCreateRequest`, `Marvel\Http\Requests\ReviewUpdateRequest`, `Marvel\Database\Repositories\ReviewRepository`

**Description:** The `images` field validation is commented out in both `ReviewCreateRequest` and `ReviewUpdateRequest`:
```php
// 'images'                        => ['sometimes', 'array'],
// 'images.*'                      => ['required_with:images', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
```

However, the `ReviewRepository::storeReview()` and `updateReview()` methods still check `$request->has('images')` and attempt to upload via `uploadImages()` / `updateImages()`. This means:
1. Images can be sent without any server-side validation (mime type, size)
2. The validation gap could allow invalid files to be processed

**Impact:** Low — image handling is effectively disabled at the validation layer, but if images are sent, they bypass validation.

**Recommendation:** Either remove image handling from the repository, or uncomment and fix the validation rules.

---

## BUG-RVW-005: No Unique Constraint on (user_id, product_id)

**Severity:** Low

**Component:** Database Migration

**Description:** There is no database-level unique constraint preventing duplicate reviews for the same product by the same user. The application relies on the `storeReview()` method to catch exceptions, but without a unique constraint, race conditions could allow duplicate reviews to be created.

**Code Location:** `packages/marvel/database/migrations/2021_10_12_193855_create_reviews_table.php`

**Current Behavior:**
```php
Schema::create('reviews', function (Blueprint $table) {
    // ... columns ...
    // No unique constraint on (user_id, product_id)
});
```

**Impact:** Low — the controller catches `MarvelException` and returns `ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT`, but concurrent requests could bypass this check (race condition).

**Recommendation:** Add a unique composite index on `(user_id, product_id)` at the database level for race-condition safety.

---

## BUG-RVW-006: `show()` Accepts Non-Existent ID Without Explicit Error Handling

**Severity:** Low

**Component:** `Marvel\Http\Controllers\ReviewController`

**Description:** The `show()` method wraps `findOrFail()` in a try-catch for `MarvelException`, but the repository's `findOrFail` inherits from `BaseRepository` which may throw a different exception type (e.g., `ModelNotFoundException` from Eloquent) that is not caught by the `MarvelException` handler.

**Code Location:** `packages/marvel/src/Http/Controllers/ReviewController.php` — `show()` method

**Current Behavior:**
```php
public function show($id)
{
    try {
        $review = $this->repository->findOrFail($id);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ReviewResource::make($review));
    } catch (MarvelException $e) {
        throw new MarvelException(NOT_FOUND);
    }
}
```

**Impact:** Low — the global exception handler likely catches unhandled exceptions, but the error response format may differ from the expected API format.

---

## BUG-RVW-007: No Transaction Wrap on `toggleApprove()`

**Severity:** Low

**Component:** `Marvel\Database\Repositories\ReviewRepository::toggleApprove()`

**Description:** The `toggleApprove()` method updates the review outside of a database transaction. While this is a single-field update and unlikely to cause issues, wrapping in a transaction would provide atomicity consistency.

**Code Location:** `packages/marvel/src/Database/Repositories/ReviewRepository.php`

**Current Behavior:**
```php
public function toggleApprove($id)
{
    try {
        $review = $this->findOrFail($id);
        $review->approved = !$review->approved;
        $review->save();
        return $review;
    } catch (Exception $e) {
        throw new HttpException(400, SOMETHING_WENT_WRONG);
    }
}
```

**Recommendation:** Wrap in `DB::transaction()` for consistency with `storeReview()` and `updateReview()`.
