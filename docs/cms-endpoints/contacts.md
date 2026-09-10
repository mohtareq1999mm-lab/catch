# Contacts API

## Overview

The Contacts module manages user-submitted inquiries and admin replies. It supports public submission, admin listing with filters, read/unread tracking, replies, and bulk delete operations.

---

## Database Schema

### `contacts` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `email` | varchar(255) | NOT NULL | Sender email |
| `subject` | varchar(255) | NOT NULL | Message subject |
| `message` | text | NOT NULL | Message body |
| `is_read` | tinyint(1) | DEFAULT 0 | Read status |
| `is_replay` | tinyint(1) | DEFAULT 0 | Reply status |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |
| `deleted_at` | timestamp | NULLABLE | Soft delete |

---

## Response Envelope

All endpoints return a standard JSON envelope:

```json
{
    "status": 200,
    "message": "Translated message string",
    "success": true,
    "data": {}
}
```

| Field | Type | Description |
|-------|------|-------------|
| `status` | int | HTTP status code |
| `message` | string | Translated message (via `resources/lang/{lang}/message.php`) |
| `success` | bool | Operation result |
| `data` | mixed | Payload (resource, collection, or omitted) |

---

## Resource Structure

### ContactResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Contact ID |
| `email` | string | Sender email |
| `subject` | string | Message subject |
| `message` | string | Message body |
| `is_read` | bool | Whether admin has read it |
| `is_replay` | bool | Whether admin has replied |
| `created_at` | datetime | Submission timestamp |

**Example:**
```json
{
    "id": 1,
    "email": "user@example.com",
    "subject": "Support Request",
    "message": "I need help with...",
    "is_read": false,
    "is_replay": false,
    "created_at": "2026-06-20T12:00:00.000000Z"
}
```

### ContactCollection

Paginated response wrapping an array of `ContactResource` with standard Laravel pagination links.

---

## Endpoints

---

### POST /contact-us — Submit Contact (Rate-Limited)

**Purpose:** Public endpoint for submitting contact inquiries under rate limiting.

**Method:** `POST`

**URL:** `/api/v1/contact-us`

**Authentication:** None

**Permissions:** None

**Rate Limit:** 5 requests/minute per IP (sensitive throttle group)

---

### POST /contacts — Submit Contact

**Purpose:** Public endpoint for submitting contact inquiries.

**Method:** `POST`

**URL:** `/contacts`

**Authentication:** None

**Permissions:** None

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `email` | string | Yes | `email`, `max:255` |
| `subject` | string | Yes | `string`, `max:255` |
| `message` | string | Yes | `string`, `min:3`, `max:5000` |

**Example Request:**
```json
{
    "email": "user@example.com",
    "subject": "Product Inquiry",
    "message": "I have a question about product #123."
}
```

**Business Logic:**
1. Validates via `ContactCreateRequest`
2. Repository extracts only `email`, `subject`, `message` (defined in `$dataArray`)
3. Creates contact with `is_read = false`, `is_replay = false`

**Success Response (201):**
```json
{
    "status": 201,
    "message": "Contact created successfully",
    "success": true,
    "data": {
        "id": 1,
        "email": "user@example.com",
        "subject": "Product Inquiry",
        "message": "I have a question about product #123.",
        "is_read": false,
        "is_replay": false,
        "created_at": "2026-06-20T12:00:00.000000Z"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 422 | Validation failure |
| 500 | Could not create resource |

**422 Validation Error:**
```json
{
    "email": ["The email field is required."],
    "subject": ["The subject field is required."],
    "message": ["The message field is required."]
}
```

---

### GET /contacts — List Contacts

**Purpose:** List all contact messages with optional filters and pagination.

**Method:** `GET`

**URL:** `/contacts`

**Authentication:** Required

**Permissions:** `view-contacts`

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 15 | Results per page |
| `read` | bool | — | Filter `is_read = true` |
| `unread` | bool | — | Filter `is_read = false` |
| `replay` | bool | — | Filter `is_replay = true` |

**Searchable fields** (via RequestCriteria): `email` (LIKE), `subject` (LIKE), `message` (LIKE)

**Business Logic:**
1. Builds query from `Contact` model
2. Applies `read` scope if `?read=true`
3. Applies `unread` scope if `?unread=true`
4. Applies `replay` scope if `?replay=true`
5. Paginates with given limit
6. Returns paginated `ContactCollection`

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
                "email": "user@example.com",
                "subject": "Support Request",
                "message": "Need help...",
                "is_read": false,
                "is_replay": false,
                "created_at": "2026-06-20T12:00:00.000000Z"
            }
        ],
        "links": {
            "current_page": 1,
            "from": 1,
            "to": 15,
            "last_page": 1,
            "path": "http://example.com/api/contacts",
            "per_page": 15,
            "total": 1,
            "next_page_url": null,
            "prev_page_url": null,
            "last_page_url": "http://example.com/api/contacts?page=1",
            "first_page_url": "http://example.com/api/contacts?page=1"
        }
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-contacts` permission |

---

### GET /contacts/{id} — Show Contact (Mark as Read)

**Purpose:** Fetch a single contact and mark it as read.

**Method:** `GET`

**URL:** `/contacts/{id}`

**Authentication:** Required

**Permissions:** `update-contact`

**Business Logic:**
1. Finds contact by ID
2. Sets `is_read = true` and persists
3. Returns the contact

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "email": "user@example.com",
        "subject": "Product Inquiry",
        "message": "I have a question about product #123.",
        "is_read": true,
        "is_replay": false,
        "created_at": "2026-06-20T12:00:00.000000Z"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-contact` permission |
| 404 | Contact not found |

---

### POST /contacts/{id}/replay — Send Reply

**Purpose:** Send a reply to a contact inquiry. Creates a new contact record linked to the same email.

**Method:** `POST`

**URL:** `/contacts/{id}/replay`

**Authentication:** Required

**Permissions:** `update-contact`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `subject` | string | Yes | `string`, `max:255` |
| `message` | string | Yes | `string`, `min:3`, `max:5000` |

**Example Request:**
```json
{
    "subject": "RE: Product Inquiry",
    "message": "Thank you for your inquiry. Product #123 is in stock."
}
```

**Business Logic:**
1. Finds the original contact by ID
2. Reads original contact's email address
3. Creates a **new** contact record with:
   - Same `email` as original
   - Provided `subject` and `message`
   - `is_read = true`, `is_replay = true`
4. Returns the newly created reply record

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Replay sent successfully",
    "success": true,
    "data": {
        "id": 2,
        "email": "user@example.com",
        "subject": "RE: Product Inquiry",
        "message": "Thank you for your inquiry. Product #123 is in stock.",
        "is_read": true,
        "is_replay": true,
        "created_at": "2026-06-20T12:30:00.000000Z"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-contact` permission |
| 404 | Original contact not found |
| 422 | Validation failure |

---

### DELETE /contacts/{id} — Delete Contact

**Purpose:** Soft-delete a single contact.

**Method:** `DELETE`

**URL:** `/contacts/{id}`

**Authentication:** Required

**Permissions:** `delete-contact`

**Business Logic:**
1. Finds contact by ID
2. Calls `delete()` — uses model's `SoftDeletes` trait
3. Record is soft-deleted (`deleted_at` set)

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Contact deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-contact` permission |
| 404 | Contact not found |

---

### DELETE /contacts/delete-all — Delete All Contacts

**Purpose:** Permanently delete ALL contacts (hard delete). **Irreversible.**

**Method:** `DELETE`

**URL:** `/contacts/delete-all`

**Authentication:** Required

**Permissions:** `delete-contact`

**Business Logic:**
1. Calls `Contact::query()->delete()` — hard delete on all records
2. Bypasses `SoftDeletes`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "All contacts deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-contact` permission |
| 500 | Delete failed |

---

### DELETE /contacts/delete-all-read — Delete All Read Contacts

**Purpose:** Permanently delete all read contacts (hard delete). **Irreversible.**

**Method:** `DELETE`

**URL:** `/contacts/delete-all-read`

**Authentication:** Required

**Permissions:** `delete-read-contacts`

**Business Logic:**
1. Calls `Contact::query()->where('is_read', true)->delete()` — hard delete only read records
2. Bypasses `SoftDeletes`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "All read contacts deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-read-contacts` permission |
| 500 | Delete failed |

---

## Route Definitions

```php
// Public routes
Route::post('/contact-us', [ContactController::class, 'store']);            // Rate-limited (5/min/IP)

// Admin routes
Route::apiResource('contacts', ContactController::class)->except(['update']);
Route::delete('contacts/delete-all', [ContactController::class, 'deleteAll']);
Route::delete('contacts/delete-all-read', [ContactController::class, 'deleteAllReadContacts']);
Route::post('contacts/{id}/replay', [ContactController::class, 'sendReplay']);
```

Source: `packages/marvel/src/Rest/Routes.php`

---

## Permissions Map

| Permission Enum | String | Applied To |
|----------------|--------|------------|
| `VIEW_CONTACTS` | `view-contacts` | `index` |
| `UPDATE_CONTACT` | `update-contact` | `show`, `sendReplay` |
| `DELETE_CONTACT` | `delete-contact` | `destroy`, `deleteAll` |
| `DELETE_READ_CONTACTS` | `delete-read-contacts` | `deleteAllReadContacts` |

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `ContactController` | Controller | `packages/marvel/src/Http/Controllers/ContactController.php` |
| `ContactRepository` | Repository | `packages/marvel/src/Database/Repositories/ContactRepository.php` |
| `Contact` | Model | `packages/marvel/src/Database/Models/Contact.php` |
| `ContactResource` | Resource | `packages/marvel/src/Http/Resources/ContactResource.php` |
| `ContactCollection` | Resource Collection | `packages/marvel/src/Http/Resources/ContactCollection.php` |
| `ContactCreateRequest` | Form Request | `packages/marvel/src/Http/Requests/ContactCreateRequest.php` |
| `ContactCreateReplayRequest` | Form Request | `packages/marvel/src/Http/Requests/ContactCreateReplayRequest.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |
| `ApiResponse` | Trait | `packages/marvel/src/Traits/ApiResponse.php` |

---

## Notes

- `POST /contacts` and `POST /contact-us` both call `store()` — one is the API resource route, the other is a rate-limited public route
- `DELETE /contacts/delete-all` and `DELETE /contacts/delete-all-read` use **hard delete** — they call `Model::query()->delete()` which bypasses `SoftDeletes`
- The `store()` method is public (no auth middleware)
- The `replay` route uses the misspelled `ReplayContact` (method name preserved from source for BC)
- Message strings are resolved via `resources/lang/{en|ar}/message.php` using the `APP_NOTICE_DOMAIN` prefix
- `read` and `unread` filters are mutually exclusive — passing both returns empty results
