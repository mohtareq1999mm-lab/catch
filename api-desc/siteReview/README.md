# Site Reviews Module

## Overview

The Site Reviews module collects **website-wide** customer reviews (not product reviews). Customers submit a rating (1–5), an optional title, and a comment about their overall experience on the platform. Every new review starts as `pending` and is invisible to the public until an admin **approves** it. Admins can also **reject** reviews; both outcomes record the acting admin (`moderated_by`) and a timestamp (`moderated_at`).

The module exposes two API surfaces:

- **Admin API** (`/api/v1/site-reviews`) — paginated list, detail, approve, reject; protected by three distinct permissions.
- **Public API** (`/api/v1/general/site-reviews`) — read-only listing of approved reviews (no auth); authenticated customer submission (`POST`).

Only approved reviews are ever returned publicly. The public listing is cached with the `FrontendResource::SITE_REVIEWS` tag (4h TTL) and flushed on every approve/reject so the frontend reflects moderation immediately.

## Key Files

| Layer | File |
|-------|------|
| Admin Controller | `packages/marvel/src/Http/Controllers/SiteReviewController.php` |
| Public Controller | `app/Http/Controllers/Api/General/SiteReviewController.php` |
| Service | `app/Services/SiteReview/SiteReviewService.php` |
| Model | `app/Models/SiteReview.php` |
| Status Enum | `app/Enums/SiteReviewStatus.php` |
| Public Resource | `app/Http/Resources/SiteReview/SiteReviewResource.php` |
| Admin Resource | `app/Http/Resources/SiteReview/AdminSiteReviewResource.php` |
| Create Request | `app/Http/Requests/SiteReview/CreateSiteReviewRequest.php` |
| Migration | `database/migrations/2026_08_10_000001_create_site_reviews_table.php` |
| Factory | `database/factories/SiteReviewFactory.php` |
| Seeder | `database/seeders/SiteReviewSeeder.php` |
| Admin Routes | `packages/marvel/src/Rest/Routes.php` (lines 178–183) |
| Public Routes | `routes/api.php` (lines 96–97, 119–120) |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Constants | `packages/marvel/config/constants.php` |
| Translations | `resources/lang/{en,ar}/message.php`, `resources/lang/{en,ar}/permissions.php` |
| Tests | `tests/Feature/SiteReviews/` (6 files, 58 tests, 152 assertions) |

## Dependencies

- **Laravel Sanctum** — `auth:sanctum` on customer `store` and all admin routes
- **Spatie Laravel Permission** — `permission:` middleware on admin methods
- **Marvel User model** — `Marvel\Database\Models\User` for `user()` and `moderator()` relations
- **FrontendResource cache** — `FrontendResource::SITE_REVIEWS` tag via `App\Traits\HasCache`
- **ApiResponse trait** — `Marvel\Traits\ApiResponse` standard envelope

## Permissions

| Permission | Required For |
|------------|-------------|
| `view-site-reviews` | GET /site-reviews, GET /site-reviews/{id} |
| `approve-site-reviews` | PATCH /site-reviews/{id}/approve |
| `reject-site-reviews` | PATCH /site-reviews/{id}/reject |

## Routes

### Admin (`auth:sanctum` + `throttle:admin` group)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/site-reviews` | List reviews (paginated, `?status=` filter, `?limit=` 1–100) |
| GET | `/api/v1/site-reviews/{id}` | Show single review (with customer + moderator) |
| PATCH | `/api/v1/site-reviews/{id}/approve` | Approve a pending review |
| PATCH | `/api/v1/site-reviews/{id}/reject` | Reject a pending review |

### Customer / Public (`routes/api.php`)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/general/site-reviews` | Public | List approved reviews (cached) |
| POST | `/api/v1/general/site-reviews` | `auth:sanctum` | Submit a new review (always pending) |

## Key Business Rules

- **Website-wide, not product** — no `product_id`, no shop/vendor concept.
- **New reviews always `pending`** — the service forces `status`, `moderated_by`, and `moderated_at`; customer-supplied values are ignored (mass-assignment-safe because those keys are not read from the request).
- **Transition guard** — only `pending → approved` and `pending → rejected` are allowed. Already-moderated reviews cannot be re-moderated (returns 404).
- **Moderation records actor** — `moderated_by` = acting admin id, `moderated_at` = now, written inside a `DB::transaction`.
- **Eager loading** — admin list/detail eager-loads `user` and `moderator` (dashboard shows the real admin name; no N+1).
- **Cache invalidation** — approve/reject flush the `site_reviews` cache tag.
- **Public response is moderation-safe** — no `status`, `moderated_by`, `moderated_at`, or `moderator` in the public resource.

## Revision History

| Revision | Date | Summary |
|----------|------|---------|
| 1 | 2026-08-10 | Initial implementation (service, controllers, resources, migration, factory, seeder, 54 tests) |
| 2 | 2026-08-10 | API investigation — fixed BUG-SR-001 (non-numeric `{id}` → 500) and BUG-SR-002 (unvalidated `limit` → 409/fallback); +4 regression tests (58 total) |
