# FAQ Module

## Overview

The FAQ module manages frequently asked questions on the e-commerce platform. It provides two API surfaces:

- **Admin API** (`/api/v1/faqs`) — Full CRUD + reorder, protected by permissions (super admin + vendor + store owner + staff scoped)
- **Public API** (`/api/v1/general/faqs`) — Read-only listing of active FAQs, no authentication required

FAQs support translatable titles and descriptions (en/ar), soft deletes, and drag-and-drop reordering via the Spatie Sortable trait. They are shop-scoped for multi-vendor setups.

## Key Files

| Layer | File |
|-------|------|
| Admin Controller | `packages/marvel/src/Http/Controllers/FaqsController.php` |
| Public Controller | `app/Http/Controllers/Api/General/FAQController.php` |
| Repository | `packages/marvel/src/Database/Repositories/FaqsRepository.php` |
| Model | `packages/marvel/src/Database/Models/Faqs.php` |
| Admin Resource | `packages/marvel/src/Http/Resources/FaqResource.php` |
| Public Resource | `app/Http/Resources/Faqs/FaqResource.php` |
| Create Request | `packages/marvel/src/Http/Requests/CreateFaqsRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/UpdateFaqsRequest.php` |
| FAQ Service | `app/Services/General/faqService.php` |
| Admin Routes | `packages/marvel/src/Rest/Routes.php` |
| Public Routes | `routes/api.php` |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Migration | `packages/marvel/database/migrations/2023_07_19_162433_create_faqs_table.php` |
| Seeder (Marvel) | `packages/marvel/src/Database/Seeders/FaqSeeder.php` |
| Seeder (App) | `database/seeders/FaqSeeder.php` |
| Tests | `tests/Feature/Faqs/FaqCrudTest.php` |
| Tests | `tests/Feature/Faqs/FaqValidationTest.php` |
| Tests | `tests/Feature/Faqs/FaqAuthenticationTest.php` |
| Tests | `tests/Feature/Faqs/FaqAuthorizationTest.php` |
| Tests | `tests/Feature/Faqs/FaqResourceTest.php` |
| Tests | `tests/Feature/Faqs/FaqSoftDeleteTest.php` |
| Tests | `tests/Feature/Faqs/FaqTranslationTest.php` |
| Tests | `tests/Feature/Faqs/FaqReorderTest.php` |
| Tests | `tests/Feature/Faqs/FaqRegressionTest.php` |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual title + description (en/ar)
- **Spatie Eloquent Sortable** (`SortableTrait`) — drag-and-drop reordering via `order` column
- **SoftDeletes** — soft delete support
- **Prettus Repository** — repository pattern with search/filter criteria
- **CodeZero UniqueTranslation** — unique validation per locale

## Permissions

| Permission | Required For |
|------------|-------------|
| `view-faqs` | GET /faqs, GET /faqs/{id} |
| `create-faq` | POST /faqs |
| `update-faq` | PUT /faqs/{id}, PUT /faqs/reorder |
| `delete-faq` | DELETE /faqs/{id} |

## Routes

### Admin (Full CRUD — super admin + authenticated)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/faqs` | List FAQs (paginated, searchable, sortable) |
| POST | `/api/v1/faqs` | Create FAQ |
| GET | `/api/v1/faqs/{id}` | Show FAQ by ID |
| PUT | `/api/v1/faqs/{id}` | Update FAQ |
| DELETE | `/api/v1/faqs/{id}` | Soft delete FAQ |
| PUT | `/api/v1/faqs/reorder` | Reorder FAQs (sorted ID array) |

### Staff / Store Owner (scoped)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/faqs` | List FAQs (scoped to shop) |
| GET | `/api/v1/faqs/{id}` | Show FAQ (scoped to shop) |
| POST | `/api/v1/faqs` | Create FAQ (scoped to shop) |
| PUT | `/api/v1/faqs/{id}` | Update FAQ (scoped to shop) |
| DELETE | `/api/v1/faqs/{id}` | Delete FAQ (scoped to shop) |
| PUT | `/api/v1/faqs/reorder` | Reorder FAQs (scoped to shop) |

### Public

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/general/faqs` | List active FAQs (no auth required) |
