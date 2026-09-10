# FAQs API

## Overview

The FAQs module manages frequently asked questions with translatable titles and descriptions.

---

## Database Schema

### `faqs` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `faq_title` | text | NULLABLE | Translatable title |
| `faq_description` | text | NULLABLE | Translatable description |
| `faq_type` | varchar(255) | NULLABLE | FAQ type |
| `issued_by` | varchar(255) | NULLABLE | Who issued the FAQ |
| `status` | tinyint(1) | DEFAULT true | Active/inactive |
| `order` | int | DEFAULT 0 | Sort order |
| `deleted_at` | timestamp | NULLABLE | Soft delete |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |

---

## Response Envelope

All endpoints return:

```json
{
    "status": 200,
    "message": "Translated message string",
    "success": true,
    "data": {}
}
```

---

## Resource Structure

### FaqResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | FAQ ID |
| `faq_title` | string | Translated title |
| `faq_description` | string | Translated description |
| `issued_by` | string|null | Who issued the FAQ |

**Example:**
```json
{
    "id": 1,
    "faq_title": "How to return a product?",
    "faq_description": "You can return any product within 30 days.",
    "issued_by": null
}
```

---

## Endpoints

### GET /faqs — List FAQs

**Purpose:** List all FAQs with optional filtering, sorting, and pagination.

**Method:** `GET`

**URL:** `/faqs`

**Authentication:** Optional (public reads available; authenticated users see role-scoped results)

**Permissions:** `view-faqs`

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 10 | Results per page (alias: `limit`) |
| `limit` | int | 10 | Results per page (alias: `per_page`) |
| `search` | string | — | Search by `faq_title` (LIKE) |
| `order` | string | — | Field to sort by. Allowed: `id`, `faq_title`, `faq_type`, `issued_by`, `status`, `created_at`, `updated_at` |
| `sortedBy` | string | `asc` | Sort direction (`asc` or `desc`). Only applies when `order` is set. |

**Example Usage:**
```
GET /faqs?page=2&per_page=20              # Page 2, 20 per page
GET /faqs?order=faq_title&sortedBy=asc    # Alphabetical A-Z
GET /faqs?order=faq_title&sortedBy=desc   # Alphabetical Z-A
GET /faqs?search=return                   # Search by title
```

**Business Logic:**
1. If authenticated, applies role-based scoping (super_admin sees all, store_owner sees their shops, staff sees assigned shop)
2. If `order` is a valid field, applies `orderBy($order, $sortedBy)`
3. Paginates with given limit
4. Returns paginated `FaqResource` collection

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "data": [
            {
                "id": 1,
                "faq_title": "How to return a product?",
                "faq_description": "You can return any product within 30 days.",
            }
        ],
        "page": 1,
        "current_page": 1,
        "from": 1,
        "to": 10,
        "last_page": 1,
        "path": "https://api.example.com/faqs",
        "per_page": 10,
        "total": 1,
        "next_page_url": null,
        "prev_page_url": null,
        "last_page_url": "https://api.example.com/faqs?page=1",
        "first_page_url": "https://api.example.com/faqs?page=1"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 403 | Missing `view-faqs` permission |

---

### POST /faqs — Create FAQ

**Purpose:** Create a new FAQ.

**Method:** `POST`

**URL:** `/faqs`

**Authentication:** Required

**Permissions:** `create-faq`

**Roles:** `super_admin`, `store_owner`, `staff`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `faq_title` | object | **Yes** | Translatable array |
| `faq_title.*` | string | **Yes** | `string`, `min:3`, `max:1000`, unique translation |
| `faq_description` | object | **Yes** | Translatable array |
| `faq_description.*` | string | **Yes** | `string`, `min:3`, `max:1000`, unique translation |

**Example Request:**
```json
{
    "faq_title": {
        "en": "How to return a product?",
        "ar": "كيفية إرجاع منتج؟"
    },
    "faq_description": {
        "en": "You can return any product within 30 days.",
        "ar": "يمكنك إرجاع أي منتج خلال 30 يومًا."
    }
}
```

**Business Logic:**
1. Validates via `CreateFaqsRequest`
2. Auto-sets `user_id` from authenticated user
3. Auto-sets `order` via Spatie Sortable (`sort_when_creating: true`)
4. Creates FAQ record
5. Returns created FAQ

**Success Response (201):**
```json
{
    "status": 201,
    "message": "FAQ created successfully",
    "success": true,
    "data": {
        "id": 1,
       "faq_title": {
        "en": "How to return a product?",
        "ar": "كيفية إرجاع منتج؟"
    },
    "faq_description": {
        "en": "You can return any product within 30 days.",
        "ar": "يمكنك إرجاع أي منتج خلال 30 يومًا."
    }
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `create-faq` permission |
| 422 | Validation failure |
| 500 | Server error |

---

### GET /faqs/{id} — Show FAQ

**Purpose:** Fetch a single FAQ by ID.

**Method:** `GET`

**URL:** `/faqs/{id}`

**Authentication:** Optional

**Permissions:** `view-faqs`

**Business Logic:**
1. Finds FAQ by ID
2. Returns FAQ resource

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
       "faq_title": {
        "en": "How to return a product?",
        "ar": "كيفية إرجاع منتج؟"
    },
    "faq_description": {
        "en": "You can return any product within 30 days.",
        "ar": "يمكنك إرجاع أي منتج خلال 30 يومًا."
    }
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 403 | Missing `view-faqs` permission |
| 404 | FAQ not found |

---

### PUT /faqs/{id} — Update FAQ

**Purpose:** Update an existing FAQ.

**Method:** `PUT`

**URL:** `/faqs/{id}`

**Authentication:** Required

**Permissions:** `update-faq`

**Roles:** `super_admin`, `store_owner`, `staff`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `faq_title` | object | No | Translatable array |
| `faq_title.*` | string | No | `string`, `min:3`, `max:1000`, unique translation (ignores self) |
| `faq_description` | object | No | Translatable array |
| `faq_description.*` | string | No | `string`, `min:3`, `max:1000` |

**Example Request:**
```json
{
    "faq_title": {
        "en": "Updated return policy",
        "ar": "سياسة الإرجاع المحدثة"
    }
}
```

**Business Logic:**
1. Validates via `UpdateFaqsRequest`
2. Finds FAQ by ID
3. Updates FAQ with provided fields
4. Returns updated FAQ

**Success Response (200):**
```json
{
    "status": 200,
    "message": "FAQ updated successfully",
    "success": true,
    "data": {
        "id": 1,
       "faq_title": {
        "en": "How to return a product?",
        "ar": "كيفية إرجاع منتج؟"
    },
    "faq_description": {
        "en": "You can return any product within 30 days.",
        "ar": "يمكنك إرجاع أي منتج خلال 30 يومًا."
    }
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-faq` permission |
| 404 | FAQ not found |
| 422 | Validation failure |
| 500 | Server error |

---

### DELETE /faqs/{id} — Delete FAQ

**Purpose:** Delete an FAQ (soft delete).

**Method:** `DELETE`

**URL:** `/faqs/{id}`

**Authentication:** Required

**Permissions:** `delete-faq`

**Roles:** `super_admin`, `store_owner`, `staff`

**Business Logic:**
1. Checks user has `delete-faq` permission
2. Finds FAQ by ID
3. Soft deletes the record

**Success Response (200):**
```json
{
    "status": 200,
    "message": "FAQ deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-faq` permission |
| 404 | FAQ not found |

---

### PUT /faqs/reorder — Reorder FAQs

**Purpose:** Set a custom order for multiple FAQs using Sortable.

**Method:** `PUT`

**URL:** `/faqs/reorder`

**Authentication:** Required

**Permissions:** `update-faq`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `faqs` | array | **Yes** | Array of FAQ IDs |
| `faqs.*` | int | **Yes** | `exists:faqs,id` |

**Example Request:**
```json
{
    "faqs": [3, 1, 2]
}
```

**Business Logic:**
1. Validates FAQ IDs exist
2. Calls `setNewOrder()` (Spatie Sortable) to reorder by the given sequence
3. The `order` column is updated based on position in the array

**Success Response (200):**
```json
{
    "status": 200,
    "message": "FAQs reordered successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-faq` permission |
| 422 | Validation failure (invalid `faqs` array) |
| 500 | Server error |

---

## Route Definitions

```php
// Public routes (no auth)
Route::apiResource('faqs', FaqsController::class, ['only' => ['index', 'show']]);

// Admin routes (auth + permissions)
Route::apiResource('faqs', FaqsController::class);
Route::put('faqs/reorder', [FaqsController::class, 'reorder']);
```

Source: `packages/marvel/src/Rest/Routes.php`

---

## Permissions Map

| Permission Enum | String | Applied To |
|----------------|--------|------------|
| `VIEW_FAQS` | `view-faqs` | `index`, `show` |
| `CREATE_FAQ` | `create-faq` | `store` |
| `UPDATE_FAQ` | `update-faq` | `update`, `reorder` |
| `DELETE_FAQ` | `delete-faq` | `destroy` |

---

## Model Features

- **Translatable:** `faq_title` and `faq_description` fields (Spatie `HasTranslations`)
- **SoftDeletes:** Records are soft-deleted
- **Sortable:** Uses Spatie `SortableTrait` with `order` column; auto-sets order on create
- **Relations:**
  - `BelongsTo` with `User`
  - `BelongsTo` with `Shop`
- **Scopes:**
  - `scopeActive` — filters by `status = 1`

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `FaqsController` | Controller | `packages/marvel/src/Http/Controllers/FaqsController.php` |
| `FaqsRepository` | Repository | `packages/marvel/src/Database/Repositories/FaqsRepository.php` |
| `Faqs` | Model | `packages/marvel/src/Database/Models/Faqs.php` |
| `FaqResource` | Resource | `packages/marvel/src/Http/Resources/FaqResource.php` |
| `CreateFaqsRequest` | Form Request | `packages/marvel/src/Http/Requests/CreateFaqsRequest.php` |
| `UpdateFaqsRequest` | Form Request | `packages/marvel/src/Http/Requests/UpdateFaqsRequest.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |
| `FAQController` | Public Controller | `app/Http/Controllers/Api/General/FAQController.php` |
| `Role` | Enum | `packages/marvel/src/Enums/Role.php` |

---

## Changelog

| Date | Change |
|------|--------|
| 2026-06-21 | Added `order` column migration, `SortableTrait` to model, `reorder()` to controller/repository, `PUT /faqs/reorder` route, `FAQS_REORDERED_SUCCESSFULLY` constant + translations |
| 2026-06-21 | `FaqsRepository::storeFaqs()` simplified: removed `shop_id`, `faq_type`, `issued_by` auto-population. Now only stores `faq_title`, `faq_description`, `user_id`. |
| 2026-06-21 | `FaqsRepository::$fieldSearchable` reduced to only `faq_title` |
| 2026-06-21 | Added `order`/`sortedBy` query params to `index()` |
| 2026-06-21 | Fixed `show()` to use consistent `apiResponse` format |
| 2026-06-21 | Fixed `update()` to use `$request->merge()` instead of `$request["id"] = $id` |
