# FAQ Module — Backend Architecture

## Overview

The FAQ module manages frequently asked questions on the platform. It is a straightforward CRUD module with soft deletes, translatable content, and sortable reordering. The module follows a standard Controller → Repository → Model pattern with permission-based middleware authorization.

Unlike more complex modules (promotions, coupons), there are no events, listeners, jobs, observers, or media uploads. The FAQ module is intentionally simple.

## Endpoints

### Admin API (`/api/v1/faqs`)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/faqs` | `auth:sanctum` | `view-faqs` | List FAQs (paginated, sortable, shop-scoped) |
| POST | `/api/v1/faqs` | `auth:sanctum` | `create-faq` | Create a new FAQ |
| GET | `/api/v1/faqs/{id}` | `auth:sanctum` | `view-faqs` | Show FAQ by ID |
| PUT | `/api/v1/faqs/{id}` | `auth:sanctum` | `update-faq` | Update FAQ |
| DELETE | `/api/v1/faqs/{id}` | `auth:sanctum` | `delete-faq` | Soft delete FAQ |
| PUT | `/api/v1/faqs/reorder` | `auth:sanctum` | `update-faq` | Reorder FAQs |

### Public API (`/api/v1/general/faqs`)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/faqs` | Public | List active FAQs |

## Route Definitions

### Admin Routes
**File:** `packages/marvel/src/Rest/Routes.php`

```
Line 229: Route::apiResource('faqs', FaqsController::class);                                    // Full CRUD (admin, no middleware group)
Line 393: Route::apiResource('faqs', FaqsController::class, ['only' => ['index', 'show']]);       // Public read
Line 535: Route::apiResource('faqs', FaqsController::class, ['only' => ['index', 'show']]);       // Customer (auth:sanctum, email.verified)
Line 616: Route::put('faqs/reorder', [FaqsController::class, 'reorder']);                         // Staff/store owner (auth:sanctum, email.verified)
Line 618: Route::apiResource('faqs', FaqsController::class, ['only' => ['store', 'update', 'destroy']]); // Staff/store owner mutate
Line 672: Route::apiResource('faqs', FaqsController::class, ['only' => ['store', 'update', 'destroy']]); // Super admin mutate
```

### Public Routes
**File:** `routes/api.php`

```
Line 66: Route::get('faqs', [FAQController::class, 'index']);  // Prefix: /api/v1/general
```

## Middleware

### Admin Controller (`Marvel\Http\Controllers\FaqsController`)

| Method | Middleware |
|--------|-----------|
| `index` | `permission:view-faqs` (via constructor) |
| `show` | `permission:view-faqs` (via constructor) |
| `store` | `permission:create-faq` (via constructor) |
| `update` | `permission:update-faq` (via constructor) |
| `reorder` | `permission:update-faq` (via constructor) |
| `destroy` | `permission:delete-faq` (via constructor) |

Auth middleware is applied at the route group level in `Routes.php`, not in the controller.

### Public Controller (`App\Http\Controllers\Api\General\FAQController`)

No middleware — fully public access.

## Controller Flow

### Admin Controller (`Marvel\Http\Controllers\FaqsController`)
**File:** `packages/marvel/src/Http/Controllers/FaqsController.php`

```
FaqsController
│
├── index(Request)
│   ├── fetchFAQs(Request)
│   │   └── Simplified: repository->query()->paginate(limit)
│   │       (Role-based scoping removed — shop_id/user_id columns
│   │        don't exist in the faqs migration)
│   ├── orderBy(order, sortedBy)
│   └── paginate(limit) → FaqResource::collection()
│
├── store(CreateFaqsRequest)  [was Request, now type-hinted]
│   └── FaqsRepository::storeFaqs($request)
│       ├── extract faq_title + faq_description
│       ├── status (only if present — DB defaults to 1)
│       └── Faqs::create()
│
├── show($id)
│   ├── FaqsRepository::findOrFail($id)
│   └── FaqResource::make()
│
├── update(UpdateFaqsRequest, $id)
│   ├── findOrFail($id)
│   └── FaqsRepository::updateFaqs($request, $faqs)
│       └── $faqs->update($request->only(dataArray))
│
├── reorder(Request)
│   ├── validate: faqs required|array, faqs.* exists:faqs,id
│   └── FaqsRepository::reorder($faqs)
│       └── setNewOrder() — Spatie Sortable
│
└── destroy($id, Request)
    ├── check permission:delete-faq
    ├── findOrFail($id)
    └── $faq->delete()  → soft delete (sets deleted_at)
```

### Public Controller (`App\Http\Controllers\Api\General\FAQController`)
**File:** `app/Http/Controllers/Api/General/FAQController.php`

```
FAQController
│
└── index()
    └── faqService::getfaqs()
        └── Faqs::active()->get()  →  FaqResource::collection()
```

## Repository Methods

**File:** `packages/marvel/src/Database/Repositories/FaqsRepository.php`

| Method | Description |
|--------|-------------|
| `storeFaqs(Request)` | Creates FAQ from request data |
| `updateFaqs(Request, Faqs)` | Updates FAQ with only fillable fields |
| `reorder(array $faqs)` | Reorders FAQs via Spatie Sortable `setNewOrder()` |

## Model Properties

**File:** `packages/marvel/src/Database/Models/Faqs.php`

### Fillable
```php
protected $fillable = [
    'faq_title',
    'faq_description',
    'status',
    'order',
];
```

### Translatable
```php
public array $translatable = ['faq_title', 'faq_description'];
```

### Sortable
```php
public $sortable = [
    'order_column_name' => 'order',
    'sort_when_creating' => true,
];
```

### Soft Deletes
The model uses `Illuminate\Database\Eloquent\SoftDeletes`.

### Relations

| Relation | Type | FK |
|----------|------|-----|
| `user()` | BelongsTo | `faqs.user_id` → `users.id` |
| `shop()` | BelongsTo | `faqs.shop_id` → `shops.id` |

### Scopes

| Scope | Description |
|-------|-------------|
| `active()` | `where('status', 1)` |

## Service Layer

### faqService (`app/Services/General/faqService.php`)

| Method | Description |
|--------|-------------|
| `getfaqs()` | Returns all active FAQs (no pagination) |

## Resources

### Admin FaqResource (`packages/marvel/src/Http/Resources/FaqResource.php`)

| Field | Type | Behavior |
|-------|------|----------|
| id | int | FAQ ID |
| faq_title | string/object | On `faqs.index`: translated string for current locale. On other routes: raw JSON with all locales |
| faq_description | string/object | Same behavior as faq_title |
| status | int | 1 = active, 0 = inactive (was missing — fixed 2026-07-21) |
| order | int | Display order (was missing — fixed 2026-07-21) |

### Public FaqResource (`app/Http/Resources/Faqs/FaqResource.php`)

| Field | Type | Behavior |
|-------|------|----------|
| id | int | FAQ ID |
| faq_title | string | Translated string for current locale |
| faq_description | string | Translated string for current locale |
| status | int | 1 = active, 0 = inactive (was missing — fixed 2026-07-21) |
| order | int | Display order (was missing — fixed 2026-07-21) |

## Permissions

| Permission | Constant | Description |
|------------|----------|-------------|
| `view-faqs` | `Permission::VIEW_FAQS` | View FAQ list and details |
| `create-faq` | `Permission::CREATE_FAQ` | Create new FAQs |
| `update-faq` | `Permission::UPDATE_FAQ` | Update FAQs and reorder |
| `delete-faq` | `Permission::DELETE_FAQ` | Delete FAQs |

## Constants

| Constant | Translation Key |
|----------|-----------------|
| `FAQ_CREATED_SUCCESSFULLY` | `MESSAGE.FAQ_CREATED_SUCCESSFULLY` |
| `FAQ_UPDATED_SUCCESSFULLY` | `MESSAGE.FAQ_UPDATED_SUCCESSFULLY` |
| `FAQ_DELETED_SUCCESSFULLY` | `MESSAGE.FAQ_DELETED_SUCCESSFULLY` |
| `FAQS_REORDERED_SUCCESSFULLY` | `MESSAGE.FAQS_REORDERED_SUCCESSFULLY` |

## Translations

### Arabic (`resources/lang/ar/message.php`)
```php
'MESSAGE.FAQ_CREATED_SUCCESSFULLY' => 'تم إنشاء الأسئلة الشائعة بنجاح',
'MESSAGE.FAQ_UPDATED_SUCCESSFULLY' => 'تم تحديث الأسئلة الشائعة بنجاح',
'MESSAGE.FAQ_DELETED_SUCCESSFULLY' => 'تم حذف الأسئلة الشائعة بنجاح',
'MESSAGE.FAQS_REORDERED_SUCCESSFULLY' => 'تمت إعادة ترتيب الأسئلة الشائعة بنجاح',
```

**Note:** English translations for FAQ messages are missing from `resources/lang/en/message.php`. The `ApiResponse` trait falls back to the raw constant string when the translation key is not found.

## Seeders

| File | Description |
|------|-------------|
| `packages/marvel/src/Database/Seeders/FaqSeeder.php` | Seeds 5 global FAQs + 20 shop-specific FAQs + 20 German translations |
| `database/seeders/FaqSeeder.php` | Seeds 50 FAQs with bilingual (en/ar) content |

## GraphQL

| File | Type | Purpose |
|------|------|---------|
| `packages/marvel/src/GraphQL/Queries/FaqQuery.php` | Query | `faqs()` paginated query, `faq()` single find |
| `packages/marvel/src/GraphQL/Mutations/FaqMutator.php` | Mutation | `createFaq`, `updateFaq`, `deleteFaq` |
| `packages/marvel/src/GraphQL/Schema/models/faqs.graphql` | Schema | Type definition with fields, inputs, and mutations |

**Note:** The GraphQL schema references fields (`shop_id`, `slug`, `faq_type`, `issued_by`, `language`, `translated_languages`) that no longer exist in the current migration/model. The schema is out of sync with the database.

## Complete Dependency Graph

```
FaqsController (Admin)
├── CreateFaqsRequest / UpdateFaqsRequest (validation)
├── FaqsRepository
│   └── Faqs (Model)
│       ├── HasTranslations (faq_title, faq_description)
│       ├── SoftDeletes (deleted_at)
│       └── SortableTrait (order column)
└── FaqResource (response)

FAQController (Public)
├── faqService
│   └── Faqs::active()
└── FaqResource (public response)
```
