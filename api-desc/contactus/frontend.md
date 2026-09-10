# Contacts — Frontend Documentation

## Overview

The Contacts module allows website visitors to submit inquiries and admins to manage, reply to, and delete messages. This document is sufficient for a frontend developer to implement all contact-related features without reading backend code.

---

## Response Envelope

Every endpoint returns:

```json
{
  "status": 200,
  "message": "Localized message string",
  "success": true,
  "data": {}
}
```

| Field | Type | Description |
|-------|------|-------------|
| `status` | int | HTTP status code |
| `message` | string | Human-readable message |
| `success` | bool | Operation result |
| `data` | mixed | Response payload (object, array, or absent on delete) |

---

## Contact Resource Shape

Every single-contact endpoint returns data in this shape:

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

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Contact ID |
| `email` | string | Sender email |
| `subject` | string | Message subject |
| `message` | string | Message body |
| `is_read` | bool | Whether admin has read it |
| `is_replay` | bool | Whether admin has replied |
| `created_at` | string | ISO-8601 datetime |

---

## Endpoints

---

### POST /api/v1/contact-us — Submit Contact (Rate-Limited)

**HTTP Method:** `POST`

**Full URL:** `POST /api/v1/contact-us`

**Authentication:** None

**Required Headers:**
```
Content-Type: application/json
Accept: application/json
```

**Request Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "subject": "Product Inquiry",
  "message": "I have a question about product #123."
}
```

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | string | Yes | max 255 characters |
| `email` | string | Yes | Valid email format, max 255 |
| `subject` | string | Yes | max 255 characters |
| `message` | string | Yes | 3-5000 characters |

**Success Response (201):**
```json
{
  "status": 201,
  "message": "Contact created successfully",
  "success": true,
  "data": {
    "id": 1,
    "email": "john@example.com",
    "subject": "Product Inquiry",
    "message": "I have a question about product #123.",
    "is_read": false,
    "is_replay": false,
    "created_at": "2026-06-20T12:00:00.000000Z"
  }
}
```

**Validation Error (422):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": ["The name field is required."],
    "email": ["The email field is required."],
    "subject": ["The subject field is required."],
    "message": ["The message field is required."]
  }
}
```

**Rate Limit Error (429):**
```json
{
  "message": "Too Many Attempts."
}
```

**Server Error (500):**
```json
{
  "status": 500,
  "message": "Could not create the resource",
  "success": false
}
```

**Loading State:**
- Show a spinner on the submit button during the request
- Disable the button to prevent double submission

**Empty State:** Not applicable (this is a create endpoint)

**Permissions:** None — public endpoint

**Notes:**
- Rate limited to 5 requests per minute per IP
- The `is_read` and `is_replay` fields will always be `false` on creation
- Use this endpoint for public contact forms (e.g., "Contact Us" page)

---

### POST /api/v1/contacts — Submit Contact (Unlimited)

**HTTP Method:** `POST`

**Full URL:** `POST /api/v1/contacts`

**Authentication:** None

**Required Headers:**
```
Content-Type: application/json
Accept: application/json
```

**Request Body:** Same as `/api/v1/contact-us`

**Responses:** Same as `/api/v1/contact-us` but no rate limit (429 will not occur)

**Loading State:**
- Show a spinner on the submit button

**Empty State:** Not applicable

**Permissions:** None — public endpoint

**Notes:**
- Identical behavior to `/contact-us` but without rate limiting
- Use this for admin-facing create forms

---

### GET /api/v1/contacts — List Contacts

**HTTP Method:** `GET`

**Full URL Examples:**
```
GET /api/v1/contacts
GET /api/v1/contacts?page=1
GET /api/v1/contacts?page=1&limit=15
GET /api/v1/contacts?limit=20
GET /api/v1/contacts?read=true
GET /api/v1/contacts?unread=true
GET /api/v1/contacts?replay=true
GET /api/v1/contacts?search=keyword
GET /api/v1/contacts?read=true&search=support&page=2&limit=10
```

**Authentication:** Required (Bearer token)

**Required Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 15 | Items per page |
| `read` | bool | — | Filter to only read messages (`is_read = true`) |
| `unread` | bool | — | Filter to only unread messages (`is_read = false`) |
| `replay` | bool | — | Filter to only replied messages (`is_replay = true`) |
| `search` | string | — | Search across email, subject, and message |

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
        "email": "john@example.com",
        "subject": "Product Inquiry",
        "message": "I have a question about product #123.",
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
      "per_page": 15,
      "total": 1,
      "path": "http://example.com/api/v1/contacts",
      "first_page_url": "http://example.com/api/v1/contacts?page=1",
      "last_page_url": "http://example.com/api/v1/contacts?page=1",
      "next_page_url": null,
      "prev_page_url": null
    }
  }
}
```

**Unauthenticated (401):**
```json
{
  "message": "Unauthenticated."
}
```

**Authorization Error (403):**
```json
{
  "status": 403,
  "message": "You are not authorized to perform this action",
  "success": false
}
```

**Loading State:**
- Show a skeleton table or cards while fetching
- Show a small spinner when changing pages or applying filters

**Empty State:**
- No contacts exist: "No messages yet." with an illustration
- No results after filtering: "No messages match your filter."

**Permissions:** Requires `view-contacts` permission

**Notes:**
- `read` and `unread` are mutually exclusive — do NOT send both simultaneously (backend returns empty)
- Soft-deleted contacts are never returned
- Search is a LIKE query across email, subject, and message (not full-text search)

---

### GET /api/v1/contacts/{id} — Show Contact

**HTTP Method:** `GET`

**Full URL Examples:**
```
GET /api/v1/contacts/1
GET /api/v1/contacts/15
GET /api/v1/contacts/42
```

**Authentication:** Required (Bearer token)

**Required Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Success Response (200):**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "email": "john@example.com",
    "subject": "Product Inquiry",
    "message": "I have a question about product #123.",
    "is_read": true,
    "is_replay": false,
    "created_at": "2026-06-20T12:00:00.000000Z"
  }
}
```

**Not Found (404):**
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

**Unhandled Response:**
- 401 (unauthorized), 403 (forbidden) — same structure as list endpoint

**Loading State:**
- Show a full-page spinner or content skeleton while loading

**Empty State:** Not applicable (the contact either exists or 404 is returned)

**Permissions:** Requires `update-contact` permission

**Notes:**
- Calling this endpoint automatically marks the contact as **read** (`is_read` becomes `true`)
- The returned `is_read` will always be `true` after this call
- Use this endpoint when an admin clicks on a contact to view its details

---

### POST /api/v1/contacts/{id}/replay — Send Reply

**HTTP Method:** `POST`

**Full URL Example:**
```
POST /api/v1/contacts/1/replay
```

**Authentication:** Required (Bearer token)

**Required Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

**Request Body:**
```json
{
  "subject": "RE: Product Inquiry",
  "message": "Thank you for your inquiry. Product #123 is in stock."
}
```

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `subject` | string | Yes | max 255 characters |
| `message` | string | Yes | 3-5000 characters |

**Success Response (200):**
```json
{
  "status": 200,
  "message": "Replay sent successfully",
  "success": true,
  "data": {
    "id": 2,
    "email": "john@example.com",
    "subject": "RE: Product Inquiry",
    "message": "Thank you for your inquiry. Product #123 is in stock.",
    "is_read": true,
    "is_replay": true,
    "created_at": "2026-06-20T12:30:00.000000Z"
  }
}
```

**Validation Error (422):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "subject": ["The subject field is required."],
    "message": ["The message field is required."]
  }
}
```

**Not Found (404):**
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

**Loading State:**
- Show a spinner on the send button during the request
- Disable the button to prevent double submission

**Empty State:** Not applicable

**Permissions:** Requires `update-contact` permission

**Notes:**
- The reply creates a **new** contact record — the original contact is NOT modified
- The new record has `is_read: true` and `is_replay: true`
- The email is automatically copied from the original contact
- The `id` in the URL is the original contact ID, not the reply — store the returned `id` for the new reply

---

### DELETE /api/v1/contacts/{id} — Delete Single Contact

**HTTP Method:** `DELETE`

**Full URL Example:**
```
DELETE /api/v1/contacts/1
```

**Authentication:** Required (Bearer token)

**Required Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Success Response (200):**
```json
{
  "status": 200,
  "message": "Contact deleted successfully",
  "success": true
}
```

**Not Found (404):**
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

**Loading State:**
- Show a confirmation dialog first ("Are you sure you want to delete this contact?")
- Show a spinner on the confirm button
- Remove the item from the list on success

**Empty State:** Not applicable

**Permissions:** Requires `delete-contact` permission

**Notes:**
- This is a **soft delete** — the record is hidden but still exists in the database
- The deleted contact will no longer appear in the list
- There is no restore endpoint in the current API

---

### DELETE /api/v1/contacts/delete-all — Delete All Contacts

**HTTP Method:** `DELETE`

**Full URL:**
```
DELETE /api/v1/contacts/delete-all
```

**Authentication:** Required (Bearer token)

**Required Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Success Response (200):**
```json
{
  "status": 200,
  "message": "All contacts deleted successfully",
  "success": true
}
```

**Loading State:**
- Show a confirmation dialog ("Are you sure you want to delete ALL contacts? This action is reversible but all messages will be hidden.")
- Show a spinner during the request
- Clear the list on success

**Empty State:** List will be empty after success

**Permissions:** Requires `delete-contact` permission

**Notes:**
- Deletes ALL contacts (soft delete — records remain in database)
- NOT reversible from the API (requires database restore)
- Show a strong warning before executing

---

### DELETE /api/v1/contacts/delete-all-read — Delete All Read Contacts

**HTTP Method:** `DELETE`

**Full URL:**
```
DELETE /api/v1/contacts/delete-all-read
```

**Authentication:** Required (Bearer token)

**Required Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Success Response (200):**
```json
{
  "status": 200,
  "message": "All read contacts deleted successfully",
  "success": true
}
```

**Loading State:**
- Show a confirmation dialog
- Show a spinner during the request
- Refresh the list on success

**Empty State:** Not applicable

**Permissions:** Requires `delete-read-contacts` permission

**Notes:**
- Only contacts with `is_read: true` are deleted (soft delete)
- Unread contacts remain in the list
- Show a warning before executing

---

## Permission Matrix

| Permission | index | show | store | sendReplay | destroy | deleteAll | deleteAllRead |
|------------|-------|------|-------|------------|---------|-----------|---------------|
| Public | — | — | ✓ | — | — | — | — |
| `view-contacts` | ✓ | — | — | — | — | — | — |
| `update-contact` | — | ✓ | — | ✓ | — | — | — |
| `delete-contact` | — | — | — | — | ✓ | ✓ | — |
| `delete-read-contacts` | — | — | — | — | — | — | ✓ |

---

## Frontend Implementation Notes

### Loading States Per View

| View | Loading State |
|------|---------------|
| Contact List | Skeleton rows or cards |
| Contact Detail | Full-page skeleton |
| Create Form | Spinner on submit button, button disabled |
| Reply Form | Spinner on send button, button disabled |
| Delete Single | Confirmation dialog → spinner on confirm |
| Delete Bulk | Confirmation dialog → spinner → refresh list |
| Pagination | Small spinner overlay or inline loading dots |
| Search | Debounce 300ms, show loading indicator |

### Empty States Per View

| View | Empty State Message |
|------|---------------------|
| Contact List (no contacts) | "No messages yet." |
| Contact List (no results after filter) | "No messages match your filter." |
| Contact Detail | Never empty (404 handled separately) |

### Error Handling

| Status | Frontend Action |
|--------|-----------------|
| 401 | Redirect to login page |
| 403 | Show toast: "You don't have permission to perform this action" |
| 422 | Display field-level validation errors below each input |
| 429 | Show toast: "Too many requests. Please wait a moment." |
| 404 | Show "Not found" page or toast |
| 500 | Show toast: "Something went wrong. Please try again." with retry button |

### Caching Strategy

- `GET /api/v1/contacts` — Cache for 30 seconds in admin panel
- Invalidate cache on: POST, DELETE operations
- Do NOT cache `GET /api/v1/contacts/{id}` (it modifies data)

### Pagination

- Default: 15 items per page
- Show: "Showing 1-15 of 42" with page controls
- Respect the `links` object from the response for page URLs
- Changing `limit` should reset to page 1

### Search & Filter

- Use debounced input (300ms) for the search field
- Filter toggles (read/unread/replay) should be mutually exclusive for read/unread
- Combine search + filter + pagination:
  ```
  GET /api/v1/contacts?search=john&read=true&page=1&limit=15
  ```

### Retry Strategy

- Failed POST/PUT/DELETE requests: Retry up to 2 times with exponential backoff
- Failed GET requests: Retry up to 2 times
- Show error toast after all retries are exhausted

### Offline Behaviour

- Contact creation (POST) should be queued in IndexedDB and retried when online
- All other operations require connectivity — show offline banner

### Screen Journey

```
Contact List (/contacts)
  → Click contact → Contact Detail (/contacts/{id})
    → Click Reply → Reply Form (inline or modal)
  → Click Create → Create Form (/contacts/new)
  → Select contacts → Bulk Delete (confirmation modal)
  → Click Delete All → Bulk Delete All (confirmation modal)
  → Click Delete All Read → Bulk Delete Read (confirmation modal)
```
