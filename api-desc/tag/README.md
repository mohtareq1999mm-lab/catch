# Tag Module

## Overview

The Tag module manages product tags — simple labels for organizing and filtering products. Tags support:

- **Admin API** (`/api/v1/tags`) — Full CRUD, protected by permissions
- Translatable names (en/ar via `HasTranslations`)
- Image and icon uploads via `MediaManager`
- Many-to-many product association via `product_tag` pivot (syncable via `products` on create/update)

## Key Files

| Layer | File |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/TagController.php` |
| Repository | `packages/marvel/src/Database/Repositories/TagRepository.php` |
| Model | `packages/marvel/src/Database/Models/Tag.php` |
| Resource | `packages/marvel/src/Http/Resources/TagResource.php` |
| Create Request | `packages/marvel/src/Http/Requests/TagCreateRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/TagUpdateRequest.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` (lines 405, 797) |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Migration | `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual name (en/ar)
- **Spatie Media Library** (`InteractsWithMedia`) — image/icon upload
- **Cviebrock Sluggable** (`Sluggable`) — slug auto-generation
- **Prettus Repository** — repository pattern

## Permissions

| Permission | Required For |
|------------|-------------|
| `view-tags` | GET /tags, GET /tags/{id} |
| `create-tags` | POST /tags |
| `update-tags` | PUT /tags/{id} |
| `delete-tags` | DELETE /tags/{id} |

## Routes

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/tags` | List tags (paginated, language filter) |
| POST | `/api/v1/tags` | Create tag |
| GET | `/api/v1/tags/{id}` | Show tag by ID or slug |
| PUT | `/api/v1/tags/{id}` | Update tag |
| DELETE | `/api/v1/tags/{id}` | Hard-delete tag |
