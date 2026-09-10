# FAQ Feature - API Investigation

## Feature Name

FAQ Management (Frequently Asked Questions)

## Description

The FAQ feature provides management of frequently asked questions with translatable titles and descriptions, drag-and-drop reordering, soft deletes, and both REST and GraphQL APIs. Supports role-based scoping (Super Admin sees all, Store Owner sees own shop, Staff sees assigned shop).

## Architecture Overview

```
[Client]
    |
    |--- GET  /api/v1/general/faqs               (Public API)
    |
    |--- GET    /api/v1/faqs                     (Admin API)
    |--- POST   /api/v1/faqs                     (Admin API)
    |--- GET    /api/v1/faqs/{id}                (Admin API)
    |--- PUT    /api/v1/faqs/{id}                (Admin API)
    |--- DELETE /api/v1/faqs/{id}                (Admin API)
    |--- POST   /api/v1/faqs/reorder             (Admin API)
    |
    |--- GraphQL: faqs, faq                     (Queries)
    |--- GraphQL: createFaq, updateFaq, deleteFaq (Mutations)
    |
    v
[FaqsController (Marvel)]  or  [FAQController (General)]
    |
    v
[FaqsRepository / faqService]
    |
    v
[Faqs Model]
    |--- user (BelongsTo User)
    |--- shop (BelongsTo Shop)
    |
    v
[faqs table]
```

## Key Endpoints

### Public API (routes/api.php - prefix: `v1/general`)

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `/v1/general/faqs` | `General\FAQController@index` | No |

### Admin API (expected routes)

| Method | URI | Controller | Permission |
|--------|-----|-----------|-----------|
| GET | `/v1/faqs` | `FaqsController@index` | `view-faqs` |
| POST | `/v1/faqs` | `FaqsController@store` | `create-faq` |
| GET | `/v1/faqs/{id}` | `FaqsController@show` | `view-faqs` |
| PUT | `/v1/faqs/{id}` | `FaqsController@update` | `update-faq` |
| DELETE | `/v1/faqs/{id}` | `FaqsController@destroy` | `delete-faq` |
| POST | `/v1/faqs/reorder` | `FaqsController@reorder` | `update-faq` |

### GraphQL

| Operation | Resolver |
|-----------|----------|
| `faqs` (query) | `FaqQuery@fetchFaqs` (paginated) |
| `faq` (query) | `@find` (Lighthouse built-in) |
| `createFaq` (mutation) | `FaqMutator@storeFaq` |
| `updateFaq` (mutation) | `FaqMutator@updateFaq` |
| `deleteFaq` (mutation) | `FaqMutator@deleteFaq` |

## Key Files

| Layer | Path |
|-------|------|
| Model | `packages/marvel/src/Database/Models/Faqs.php` |
| Repository | `packages/marvel/src/Database/Repositories/FaqsRepository.php` |
| Controller (Admin) | `packages/marvel/src/Http/Controllers/FaqsController.php` |
| Controller (Public) | `app/Http/Controllers/Api/General/FAQController.php` |
| Service (Public) | `app/Services/General/faqService.php` |
| Create Request | `packages/marvel/src/Http/Requests/CreateFaqsRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/UpdateFaqsRequest.php` |
| Resource (Admin) | `packages/marvel/src/Http/Resources/FaqResource.php` |
| Resource (Public) | `app/Http/Resources/Faqs/FaqResource.php` |
| GraphQL Schema | `packages/marvel/src/GraphQL/Schema/models/faqs.graphql` |
| GraphQL Query | `packages/marvel/src/GraphQL/Queries/FaqQuery.php` |
| GraphQL Mutator | `packages/marvel/src/GraphQL/Mutations/FaqMutator.php` |
| Enum (Permission) | `packages/marvel/src/Enums/Permission.php` |
| Migration | `packages/marvel/database/migrations/2023_07_19_162433_create_faqs_table.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Spatie Translatable** for localized faq_title and faq_description
- **Spatie Sortable** for drag-and-drop reordering
- **Soft Deletes** for safe removal
- **Lighthouse PHP** for GraphQL
- **Spatie Permission** for authorization (no Policy class)
