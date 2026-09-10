# Backend - FAQ Feature

## Overview

The FAQ feature spans two layers with GraphQL support:

1. **App Layer (`app/`)**: Public API — simple listing of active FAQs
2. **Package Layer (`packages/marvel/`)**: Admin CRUD with permissions, reordering, GraphQL

## Key Files

### 1. Model - `packages/marvel/src/Database/Models/Faqs.php`

**Table:** `faqs`

**Traits:** `HasTranslations` (Spatie), `SoftDeletes`, `SortableTrait` (Spatie)

**Translatable:** `['faq_title', 'faq_description']`

**Fillable:**
- `faq_title`, `faq_description`, `status`, `order`

**Relationships:**

| Method | Type | Related |
|--------|------|---------|
| `user()` | `BelongsTo` | `User` |
| `shop()` | `BelongsTo` | `Shop` |

**Scopes:** `active()` — `where('status', 1)`

**Sortable config:** `order_column_name => 'order'`, `sort_when_creating => true`

### 2. Repository - `packages/marvel/src/Database/Repositories/FaqsRepository.php`

**Extends:** `BaseRepository`

| Method | Description |
|--------|-------------|
| `model()` | Returns `Faqs::class` |
| `boot()` | Pushes `RequestCriteria` |
| `storeFaqs($request)` | Creates FAQ with title and description |
| `updateFaqs(Request, Faqs)` | Updates allowed fields |
| `reorder(array $faqs)` | Spatie Sortable `setNewOrder()` |

**Searchable:** `faq_title` (like)

### 3. Controller (Admin) - `packages/marvel/src/Http/Controllers/FaqsController.php`

**Extends:** `CoreController`

**Permissions (via constructor middleware):**

| Method | Permission |
|--------|-----------|
| `index`, `show` | `view-faqs` |
| `store` | `create-faq` |
| `update`, `reorder` | `update-faq` |
| `destroy` | `delete-faq` |

**Methods:**

| Method | Description |
|--------|-------------|
| `index(Request)` | Paginated list with sorting, role-based scoping via `fetchFAQs()` |
| `fetchFAQs(Request)` | Query builder with role-based scoping (Super Admin: all; Store Owner: own shop; Staff: assigned shop) |
| `store(Request)` | Creates via `FaqsRepository::storeFaqs()` |
| `show($id)` | Single FAQ by ID |
| `update(UpdateFaqsRequest, $id)` | Updates via `FaqsRepository::updateFaqs()` |
| `reorder(Request)` | Validates and reorders |
| `destroy($id, Request)` | Soft deletes |

### 4. Controller (Public) - `app/Http/Controllers/Api/General/FAQController.php`

| Method | Description |
|--------|-------------|
| `index()` | Returns all active FAQs as `FaqResource` collection |

### 5. Service (Public) - `app/Services/General/faqService.php`

| Method | Description |
|--------|-------------|
| `getfaqs()` | Returns `Faqs::active()->get()` |

### 6. Form Requests

**CreateFaqsRequest:**
- `faq_title` (required, array)
- `faq_title.*` (required, string, min:3, max:1000, unique translation)
- `faq_description` (required, array)
- `faq_description.*` (required, string, min:3, max:1000, unique translation)
- `shop_id` (nullable, exists:shops,id)

**UpdateFaqsRequest:**
- Same fields but all `sometimes`, unique check ignores current ID
- Additional `status` (sometimes, in:0,1)

### 7. API Resources

| Resource | Fields |
|----------|--------|
| `FaqResource` (Admin) | `id`, `faq_title` (translated), `faq_description` (translated) |
| `FaqResource` (Public) | `id`, `faq_title` (translated), `faq_description` (translated) |

### 8. Permissions - `packages/marvel/src/Enums/Permission.php`

| Constant | Value |
|----------|-------|
| `VIEW_FAQS` | `view-faqs` |
| `CREATE_FAQ` | `create-faq` |
| `UPDATE_FAQ` | `update-faq` |
| `DELETE_FAQ` | `delete-faq` |

### 9. Config Constants - `packages/marvel/config/constants.php`

| Constant | Message Key |
|----------|-------------|
| `FAQ_CREATED_SUCCESSFULLY` | `message.FAQ_CREATED_SUCCESSFULLY` |
| `FAQ_UPDATED_SUCCESSFULLY` | `message.FAQ_UPDATED_SUCCESSFULLY` |
| `FAQ_DELETED_SUCCESSFULLY` | `message.FAQ_DELETED_SUCCESSFULLY` |
| `FAQS_REORDERED_SUCCESSFULLY` | `message.FAQS_REORDERED_SUCCESSFULLY` |

### 10. GraphQL

**Schema:** `packages/marvel/src/GraphQL/Schema/models/faqs.graphql`

**Queries:**
- `faqs(search, orderBy, language, shop_id, ...): [Faqs!]! @paginate(builder: "FaqQuery@fetchFaqs")`
- `faq(id, slug, language): Faqs @find`

**Mutations:**
- `createFaq(input: CreateFaqInput!): Faqs` — resolver: `FaqMutator@storeFaq`
- `updateFaq(input: UpdateFaqInput!): Faqs` — resolver: `FaqMutator@updateFaq`
- `deleteFaq(id: ID!): Faqs` — resolver: `FaqMutator@deleteFaq`

**Type:** `Faqs` — id, shop_id, faq_title, slug, faq_description, faq_type, issued_by, shop, language, translated_languages

## Data Flow

```
Client
  |
  GET /api/v1/general/faqs
  |
  v
General\FAQController@index()
  |
  v
faqService::getfaqs()
  |--- Faqs::active()->get()
  |
  v
FaqResource collection
  |--- Maps: id, faq_title (translated), faq_description (translated)
  |
  v
JSON Response
```
