# Review Module — Backend Architecture

## Overview

The Review module manages product reviews on the platform. Customers submit ratings (1-5) and comments for products. Reviews support soft deletes, approval toggling, media attachments (images — currently commented out), and abuse reporting. All endpoints require authentication via `auth:sanctum`.

## Endpoints

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/reviews` | `auth:sanctum` | None | List reviews by product_id (paginated) |
| POST | `/api/v1/reviews` | `auth:sanctum` | None | Create a new review |
| GET | `/api/v1/reviews/{id}` | `auth:sanctum` | None | Show a single review |
| PUT | `/api/v1/reviews/{id}` | `auth:sanctum` | None | Update a review |
| DELETE | `/api/v1/reviews/{id}` | `auth:sanctum` | `delete-reviews` | Soft-delete a review |
| PATCH | `/api/v1/reviews/{id}/toggle-approve` | `auth:sanctum` | `approve-reviews` | Toggle approval status |

## Route Definitions

**File:** `packages/marvel/src/Rest/Routes.php`

Line 214-215 (unauthenticated section — but still auth-protected by controller group):
```php
Route::patch('reviews/{id}/toggle-approve', [ReviewController::class, 'toggleApproveReview']);
Route::apiResource('reviews', ReviewController::class);
```

Line 344-346 (public read-only section — only index, show):
```php
Route::apiResource('reviews', ReviewController::class, [
    'only' => ['index', 'show'],
]);
```

Line 457-463 (content creation, rate-limited):
```php
Route::middleware(['throttle:content'])->group(function () {
    Route::apiResource('reviews', ReviewController::class, [
        'only' => ['store', 'update']
    ]);
});
```

## Middleware

### Controller (`Marvel\Http\Controllers\ReviewController`)

| Method | Middleware |
|--------|-----------|
| `toggleApproveReview` | `permission:approve-reviews` (via constructor) |
| `destroy` | `permission:delete-reviews` (via constructor) |
| `store` | `throttle:content` (5/min, via route group) |
| `update` | `throttle:content` (5/min, via route group) |

Auth (`auth:sanctum`) is applied at the route group level in the parent group.

## Controller Flow

**File:** `packages/marvel/src/Http/Controllers/ReviewController.php`

```
GET /reviews?product_id=1&limit=15
  → ReviewController@index(Request)
    → $request->validate(['product_id' => 'required|integer|exists:products,id'])
    → $this->repository->where('product_id', $request['product_id'])->paginate($limit)
    → ReviewResource::collection($data)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)

POST /reviews
  → ReviewController@store(ReviewCreateRequest)
    → validation: product_id required|exists, comment required|string, rating required|integer|min:1|max:5
    → $this->repository->storeReview($request)
      → DB::beginTransaction
        → Extract data: product_id, user_id (from auth), comment, rating
        → $this->create($reviewInput)
        → If images: uploadImages($request, 'images', $review, 'reviews', 'reviews')
        → DB::commit
      → On failure: DB::rollBack, HttpException(400)
    → ReviewResource::make($review)
    → $this->apiResponse(REVIEW_CREATED_SUCCESSFULLY, 200, true, ...)
    → On MarvelException: ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT

GET /reviews/{id}
  → ReviewController@show($id)
    → $this->repository->findOrFail($id)
    → ReviewResource::make($review)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)
    → On MarvelException: NOT_FOUND

PUT /reviews/{id}
  → ReviewController@update(ReviewUpdateRequest, $id)
    → $request->merge(['id' => $id])
    → $this->updateReview($request) [private]
      → $this->repository->updateReview($request, $id)
        → DB::beginTransaction
          → findOrFail($id)
          → $review->update($data)
          → If images: updateImages(...)
        → DB::commit
      → On failure: DB::rollBack, HttpException(400)
    → ReviewResource::make($review)
    → $this->apiResponse(REVIEW_UPDATED_SUCCESSFULLY, 200, true, ...)
    → On MarvelException: SOMETHING_WENT_WRONG

DELETE /reviews/{id}
  → ReviewController@destroy($id)
    → $this->repository->findOrFail($id)
    → $review->delete()  [soft delete]
    → $this->apiResponse(REVIEW_DELETED_SUCCESSFULLY, 200, true)
    → On MarvelException: NOT_FOUND

PATCH /reviews/{id}/toggle-approve
  → ReviewController@toggleApproveReview($id)
    → $this->repository->toggleApprove($id)
      → findOrFail($id)
      → $review->approved = !$review->approved
      → $review->save()
    → ReviewResource::make($review)
    → $this->apiResponse(REVIEW_UPDATED_SUCCESSFULLY, 200, true, ...)
    → On MarvelException: NOT_FOUND
```

## Repository

**File:** `packages/marvel/src/Database/Repositories/ReviewRepository.php`
**Extends:** `BaseRepository` (extends `Prettus\Repository\Eloquent\BaseRepository`)

| Method | Description |
|--------|-------------|
| `model()` | Returns `Review::class` |
| `boot()` | Pushes `RequestCriteria` for search/filter |
| `storeReview($request)` | Transactional create with user_id, images upload |

### `storeReview()` Flow
```
1. DB::beginTransaction()
2. Extract $data from request (product_id, user_id, comment, rating)
3. Set user_id from auth()->id()
4. $this->create($data)
5. If images: uploadImages($request, 'images', $review, 'reviews', 'reviews')
6. DB::commit()
7. Return $review

On error:
  - HttpException(400): SOMETHING_WENT_WRONG (rollback + log)
```

### `updateReview()` Flow
```
1. DB::beginTransaction()
2. findOrFail($id)
3. $review->update($data)
4. If images: updateImages(...)
5. DB::commit()
6. Return $review

On error:
  - HttpException(400): SOMETHING_WENT_WRONG (rollback + log)
```

### `toggleApprove()` Flow
```
1. findOrFail($id)
2. $review->approved = !$review->approved
3. $review->save()
4. Return $review

On error:
  - HttpException(400): SOMETHING_WENT_WRONG
```

### Base Repository (`BaseRepository`)
**File:** `packages/marvel/src/Database/Repositories/BaseRepository.php`

| Method | Description |
|--------|-------------|
| `findBySlugOrId($value, $language)` | Find by `id` (numeric) or `slug` (string) |
| `findOneByField($field, $value)` | Find single record by field |
| `findOneByFieldOrFail($field, $value)` | Find single or throw MarvelException |

Uses `CacheableRepository` trait (Prettus) for automatic query caching.

## Model

**File:** `packages/marvel/src/Database/Models/Review.php`
**Table:** `reviews`
**Traits:** `SoftDeletes`, `InteractsWithMedia`
**Implements:** `HasMedia`

| Property | Details |
|----------|---------|
| Fillable | `user_id`, `product_id`, `comment`, `rating`, `approved` |
| Media Collections | `reviews` |

### Scopes

| Scope | Description |
|-------|-------------|
| `scopeApproved($q)` | `where('approved', true)` |
| `scopeNotApproved($q)` | `where('approved', false)` |

### Relationships

| Relation | Type | Foreign |
|----------|------|---------|
| `product()` | BelongsTo | `product_id` → `products.id` |
| `user()` | BelongsTo | `user_id` → `users.id` |
| `feedbacks()` | MorphMany | `Feedback` (positive/negative) |
| `abusive_reports()` | MorphMany | `AbusiveReport` |

### Computed Attributes

| Attribute | Description |
|-----------|-------------|
| `positiveFeedbacksCount` | Count of feedbacks where `positive = 1` |
| `negativeFeedbacksCount` | Count of feedbacks where `negative = 1` |
| `myFeedback` | Current authenticated user's feedback on this review |
| `abusiveReportsCount` | Count of abusive reports for this review |

## Resource

**File:** `packages/marvel/src/Http/Resources/ReviewResource.php`

```json
{
  "id": "integer",
  "rating": "integer",
  "comment": "string",
  "images": ["media url array"],
  "is_approved": "boolean (only when user has 'approve-reviews' permission)"
}
```

## Request Validation

### ReviewCreateRequest (`Marvel\Http\Requests\ReviewCreateRequest`)

**File:** `packages/marvel/src/Http/Requests/ReviewCreateRequest.php`

| Field | Rules |
|-------|-------|
| `product_id` | `required`, `exists:Marvel\Database\Models\Product,id` |
| `comment` | `required`, `string` |
| `rating` | `required`, `integer`, `min:1`, `max:5` |

Note: `images` field is commented out in the rules array — image upload validation is not currently enforced at the request level.

### ReviewUpdateRequest (`Marvel\Http\Requests\ReviewUpdateRequest`)

**File:** `packages/marvel/src/Http/Requests/ReviewUpdateRequest.php`

| Field | Rules |
|-------|-------|
| `comment` | `required`, `string` |
| `rating` | `required`, `integer`, `min:1`, `max:5` |

Note: `product_id` is not updatable — it is not present in the update request.

## Media Handling

**Trait:** `Marvel\Traits\MediaManager`

**Disk:** `reviews` (local, `storage/app/public/reviews`)

**Collection:**

| Collection | Type | Upload Method |
|------------|------|---------------|
| `reviews` | Multiple images | `uploadImages()` on create, `updateImages()` on update |

Both `uploadImages()` and `updateImages()` are called from the repository but the `images` field validation is commented out in FormRequests, creating a gap where validation happens only at the repository level (which checks `$request->has('images')`).

## Database Schema

### Table: `reviews`
**Migration:** `packages/marvel/database/migrations/2021_10_12_193855_create_reviews_table.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `user_id` | bigint unsigned | FK → users.id ON DELETE CASCADE |
| `product_id` | bigint unsigned | FK → products.id ON DELETE CASCADE |
| `comment` | longText | NOT NULL |
| `rating` | double | NULLABLE |
| `approved` | boolean | DEFAULT false |
| `deleted_at` | timestamp | NULLABLE (soft deletes) |
| `created_at` | timestamp | NULLABLE |
| `updated_at` | timestamp | NULLABLE |

**Indexes:**
- `rating` — index for filtering by rating
- `(rating, product_id)` — composite index for common review queries

## Soft Deletes

- Reviews use `SoftDeletes` — calling `delete()` sets `deleted_at` instead of removing the row.
- No admin API endpoint exists for restore or force delete.
- Related feedbacks and abusive reports are morphMany — they remain associated after soft delete.

## Constants

**File:** `packages/marvel/config/constants.php`

```php
define('REVIEW_CREATED_SUCCESSFULLY',     APP_NOTICE_DOMAIN . 'MESSAGE.REVIEW_CREATED_SUCCESSFULLY');
define('REVIEW_UPDATED_SUCCESSFULLY',     APP_NOTICE_DOMAIN . 'MESSAGE.REVIEW_UPDATED_SUCCESSFULLY');
define('REVIEW_DELETED_SUCCESSFULLY',     APP_NOTICE_DOMAIN . 'MESSAGE.REVIEW_DELETED_SUCCESSFULLY');
define('ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT', APP_NOTICE_DOMAIN . 'ERROR.ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT');
```

## Permissions

**Enum:** `Marvel\Enums\Permission`

| Constant | Value | Used In Controller |
|----------|-------|-------------------|
| `APPROVE_REVIEWS` | `approve-reviews` | `toggleApproveReview` middleware |
| `DELETE_REVIEWS` | `delete-reviews` | `destroy` middleware |
| `CREATE_REVIEW` | `create-review` | Not used in controller |
| `UPDATE_REVIEW` | `update-review` | Not used in controller |
| `DELETE_REVIEW` | `delete-review` | Not used in controller (uses `DELETE_REVIEWS`) |

## Translation Keys Used

| Key | Context | Exists? |
|-----|---------|---------|
| `MESSAGE.REVIEW_CREATED_SUCCESSFULLY` | POST response message | ❌ Missing in en + ar |
| `MESSAGE.REVIEW_UPDATED_SUCCESSFULLY` | PUT / toggle response message | ❌ Missing in en + ar |
| `MESSAGE.REVIEW_DELETED_SUCCESSFULLY` | DELETE response message | ❌ Missing in en + ar |
| `ERROR.ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT` | 400 duplicate review | ✅ en: missing, ar: present |
| `ERROR.NOT_FOUND` | 404 error response | ✅ Common key |
| `ERROR.SOMETHING_WENT_WRONG` | 500 error response | ✅ Common key |

**Note:** Review translation keys are missing from both `resources/lang/en/message.php` and `resources/lang/ar/message.php` (except for `ERROR.ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT` in Arabic). This means the API endpoint success messages will fall back to the constant path string rather than showing a human-readable message.

## Dependencies

| File | Role |
|------|------|
| `packages/marvel/src/Rest/Routes.php` | Route definitions |
| `packages/marvel/src/Http/Controllers/ReviewController.php` | Controller |
| `packages/marvel/src/Http/Requests/ReviewCreateRequest.php` | Create validation |
| `packages/marvel/src/Http/Requests/ReviewUpdateRequest.php` | Update validation |
| `packages/marvel/src/Http/Resources/ReviewResource.php` | API resource |
| `packages/marvel/src/Database/Models/Review.php` | Model |
| `packages/marvel/src/Database/Repositories/ReviewRepository.php` | Repository |
| `packages/marvel/src/Database/Repositories/BaseRepository.php` | Base repository |
| `packages/marvel/src/Enums/Permission.php` | Permissions enum |
| `packages/marvel/config/constants.php` | Response message constants |
| `packages/marvel/src/Traits/MediaManager.php` | Image upload trait |
| `packages/marvel/database/migrations/2021_10_12_193855_create_reviews_table.php` | Reviews table migration |
| `resources/lang/en/message.php` | English translations (missing review keys) |
| `resources/lang/ar/message.php` | Arabic translations (missing review success keys) |
| `tests/Feature/ProductCrudTest.php` | Review feature tests (14 tests) |
