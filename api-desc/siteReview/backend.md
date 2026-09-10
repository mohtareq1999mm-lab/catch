# Site Reviews — Backend Architecture

## Overview

The Site Reviews module is a website-wide (not product) review system with a moderation workflow. It follows the repository's enterprise layering: Controller → Service → Model, with FormRequest validation, JsonResource transformation, Spatie permission authorization, and tag-based frontend caching.

The **business logic lives entirely in `app/`** (`SiteReviewService`, `SiteReview` model, `SiteReviewStatus` enum, resources, request). The Marvel package provides only the **admin controller** (`Marvel\Http\Controllers\SiteReviewController`) as the Dashboard CRUD/moderate layer. There is no repository layer — the service queries the model directly (YAGNI: no cross-cutting logic to reuse).

There are no events, listeners, jobs, observers, or media uploads. Moderation is synchronous and transactional.

## Endpoints

### Admin API (`/api/v1/site-reviews`) — `auth:sanctum` + `throttle:admin` group

| Method | URL | Permission | Purpose |
|--------|-----|------------|---------|
| GET | `/api/v1/site-reviews` | `view-site-reviews` | Paginated list with `?status=` filter |
| GET | `/api/v1/site-reviews/{id}` | `view-site-reviews` | Single review detail |
| PATCH | `/api/v1/site-reviews/{id}/approve` | `approve-site-reviews` | Approve pending review |
| PATCH | `/api/v1/site-reviews/{id}/reject` | `reject-site-reviews` | Reject pending review |

### Customer / Public API (`routes/api.php`)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/site-reviews` | Public | Approved reviews (cached) |
| POST | `/api/v1/general/site-reviews` | `auth:sanctum` | Submit review (always pending) |

## Route Definitions

### Admin Routes — `packages/marvel/src/Rest/Routes.php` (lines 178–183)

```php
Route::get('site-reviews', [SiteReviewController::class, 'index']);
Route::get('site-reviews/{id}', [SiteReviewController::class, 'show'])->whereNumber('id');
Route::patch('site-reviews/{id}/approve', [SiteReviewController::class, 'approve'])->whereNumber('id');
Route::patch('site-reviews/{id}/reject', [SiteReviewController::class, 'reject'])->whereNumber('id');
```

These live inside the `Route::middleware(['auth:sanctum', 'throttle:admin'])->group(...)` block (line 110).

### Public Routes — `routes/api.php`

```php
// inside the public group (throttle:public-api):
Route::get('site-reviews', [SiteReviewController::class, 'index']);

// inside the authenticated group (auth:sanctum, throttle:authenticated):
Route::post('site-reviews', [SiteReviewController::class, 'store']);
```

## Middleware

### Admin Controller (`Marvel\Http\Controllers\SiteReviewController`)

Auth + throttle come from the route group. The constructor adds per-method permission middleware:

| Method | Middleware |
|--------|-----------|
| `index`, `show` | `permission:view-site-reviews` |
| `approve` | `permission:approve-site-reviews` |
| `reject` | `permission:reject-site-reviews` |

### Public Controller (`App\Http\Controllers\Api\General\SiteReviewController`)

- `index` — no auth (public)
- `store` — route-level `auth:sanctum`

## Controller Flow

### Admin Controller

```
SiteReviewController (Marvel)
│
├── index(Request)
│   ├── limit = min(max((int)?limit, 1), 100)   // default 15 — normalized
│   ├── status = ?status (pending|approved|rejected|all)
│   └── SiteReviewService::getAllReviews(status, limit)
│       └── SiteReview::with(['user','moderator'])->latest()->paginate(limit)
│   └── AdminSiteReviewResource::collection(paginator)
│       └── response()->getData(true) → flattened pagination meta
│
├── show(int $id)
│   ├── SiteReviewService::findReview($id)
│   │   └── SiteReview::with(['user','moderator'])->find($id)
│   ├── null → 404 SITE_REVIEW_NOT_FOUND
│   └── AdminSiteReviewResource::make($review)
│
├── approve(Request, int $id)
│   ├── SiteReviewService::approveReview($id, $request->user())
│   │   └── moderate() inside DB::transaction
│   │       ├── find($id); null OR status !== pending → null
│   │       └── update(status=approved, moderated_by=admin, moderated_at=now)
│   ├── null → 404
│   ├── flushTag(FrontendResource::SITE_REVIEWS)
│   └── AdminSiteReviewResource::make($review) + SITE_REVIEW_APPROVED_SUCCESSFULLY
│
└── reject(Request, int $id)
    └── identical to approve with status=rejected + SITE_REVIEW_REJECTED_SUCCESSFULLY
```

### Public Controller

```
SiteReviewController (App\Http\Controllers\Api\General)
│
├── index()
│   ├── SiteReviewService::getApprovedReviews()
│   │   └── SiteReview::with('user')->where(status=approved)->latest()->get()
│   ├── remember(FrontendResource::SITE_REVIEWS, md5(fullUrl),
│   │           SiteReviewResource::collection(reviews))   // 4h TTL, tag 'site_reviews'
│   └── apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, cached)
│
└── store(CreateSiteReviewRequest)
    ├── SiteReviewService::createReview($request->user(), $request->validated())
    │   └── SiteReview::create(user_id, rating, title, comment,
    │           status=PENDING, moderated_by=null, moderated_at=null)
    └── apiResponse(SITE_REVIEW_CREATED_SUCCESSFULLY, 201, true,
            SiteReviewResource::make($review))
```

## Service Layer (`app/Services/SiteReview/SiteReviewService.php`)

| Method | Description |
|--------|-------------|
| `createReview(User, array $data)` | Creates review, always `pending`, moderator null. Ignores any customer-supplied moderation fields |
| `getApprovedReviews(): Collection` | Public list — only approved, eager-loads `user`, newest first |
| `getAllReviews(?string $status, int $limit): LengthAwarePaginator` | Admin list — eager-loads `user` + `moderator`, optional status filter (only valid enum values or `all`), newest first |
| `findReview(int $id): ?SiteReview` | Admin detail — eager-loads `user` + `moderator` |
| `approveReview(int $id, User $admin): ?SiteReview` | Moderate → approved |
| `rejectReview(int $id, User $admin): ?SiteReview` | Moderate → rejected |
| `moderate(int $id, User $admin, SiteReviewStatus $target)` (private) | Transactional: find, guard `status === pending`, update status + `moderated_by` + `moderated_at`, reload relations |

**Transition guard** — `moderate()` returns `null` when the review is missing OR already moderated. The controller maps `null` → 404. Only `pending → approved` and `pending → rejected` are possible; no reverts.

## Model (`app/Models/SiteReview.php`)

### Fillable
```php
protected $fillable = ['user_id', 'rating', 'title', 'comment', 'status', 'moderated_by', 'moderated_at'];
```

### Casts
```php
protected $casts = [
    'rating' => 'integer',
    'status' => SiteReviewStatus::class,
    'moderated_at' => 'datetime',
];
```

### Relations

| Relation | Type | FK |
|----------|------|-----|
| `user()` | BelongsTo | `site_reviews.user_id` → `users.id` |
| `moderator()` | BelongsTo | `site_reviews.moderated_by` → `users.id` |

### Enum (`app/Enums/SiteReviewStatus.php`)
`PENDING = 'pending'`, `APPROVED = 'approved'`, `REJECTED = 'rejected'`, with a `values(): array` helper.

## Resources

### Public — `SiteReviewResource` (`app/Http/Resources/SiteReview/SiteReviewResource.php`)

| Field | Type | Notes |
|-------|------|-------|
| id | int | |
| rating | int | 1–5 |
| title | string\|null | |
| comment | string | |
| customer | object\|null | `{id, name}` — `whenLoaded('user')` |
| created_at | datetime | |

**No moderation fields** — `status`, `moderated_by`, `moderated_at`, `moderator` are intentionally absent. Customer email is not exposed.

### Admin — `AdminSiteReviewResource`

| Field | Type | Notes |
|-------|------|-------|
| id | int | |
| user_id | int | |
| customer | object | `{id, name, email}` — `whenLoaded('user')` |
| rating | int | |
| title | string\|null | |
| comment | string | |
| status | string | `pending`/`approved`/`rejected` (enum cast → string) |
| moderator | object\|null | `{id, name}` — `whenLoaded('moderator')` + non-null guard, else `null` |
| moderated_at | datetime\|null | |
| created_at | datetime | |

## Form Request (`CreateSiteReviewRequest`)

`authorize()` returns `true` (auth enforced at route). Rules:
- `rating` → required, integer, min:1, max:5
- `title` → nullable, string, max:191
- `comment` → required, string, max:2000

## Permissions

| Permission | Constant | Description |
|------------|----------|-------------|
| `view-site-reviews` | `Permission::VIEW_SITE_REVIEWS` | View list + detail |
| `approve-site-reviews` | `Permission::APPROVE_SITE_REVIEWS` | Approve pending reviews |
| `reject-site-reviews` | `Permission::REJECT_SITE_REVIEWS` | Reject pending reviews |

Registered in `PermissionSeeder.php`, translated in `resources/lang/{en,ar}/permissions.php`.

## Constants & Translations

| Constant | Translation Key | en | ar |
|----------|-----------------|----|----|
| `SITE_REVIEW_CREATED_SUCCESSFULLY` | `MESSAGE.SITE_REVIEW_CREATED_SUCCESSFULLY` | Site review submitted successfully | تم إرسال تقييم الموقع بنجاح |
| `SITE_REVIEW_APPROVED_SUCCESSFULLY` | `MESSAGE.SITE_REVIEW_APPROVED_SUCCESSFULLY` | Site review approved successfully | تمت الموافقة على تقييم الموقع بنجاح |
| `SITE_REVIEW_REJECTED_SUCCESSFULLY` | `MESSAGE.SITE_REVIEW_REJECTED_SUCCESSFULLY` | Site review rejected successfully | تم رفض تقييم الموقع بنجاح |
| `SITE_REVIEW_NOT_FOUND` | `ERROR.SITE_REVIEW_NOT_FOUND` | Site review not found | تقييم الموقع غير موجود |

The `ApiResponse::apiResponse()` resolves constants via `translateNotice()` using the `message.` namespace.

## Caching

Public `GET /api/v1/general/site-reviews` is cached via `App\Traits\HasCache::remember()`:
- Tag: `FrontendResource::SITE_REVIEWS->value` = `site_reviews`
- Key: `md5(request()->fullUrl())`
- TTL: 4 hours
- Flushed by `flushTag(FrontendResource::SITE_REVIEWS->value)` on every admin approve/reject.

**Note:** The cache driver must support tags (`file`, `redis`, `database`, `memcached`, `array`). Default is `file` (`config/cache.php`).

## Database

See `database.md` for the full schema.

## Complete Dependency Graph

```
SiteReviewService
├── SiteReview (Model)
│   ├── user()  → Marvel\User
│   ├── moderator() → Marvel\User
│   └── status cast → SiteReviewStatus enum
├── SiteReviewResource (public)
└── AdminSiteReviewResource (admin)

Marvel\SiteReviewController (Admin)
├── SiteReviewService
├── AdminSiteReviewResource
├── Permission middleware (3)
├── HasCache (flushTag on moderate)
└── ApiResponse

App\Api\General\SiteReviewController (Public)
├── SiteReviewService
├── CreateSiteReviewRequest
├── SiteReviewResource
├── HasCache (remember)
└── ApiResponse
```
