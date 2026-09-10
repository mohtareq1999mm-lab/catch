# Contacts — Backend Documentation

## Overview

The Contacts module handles user-submitted inquiries and admin replies. It supports public submission, admin listing with filters, read/unread tracking, replies, and bulk delete operations.

---

## Overall Architecture

```mermaid
flowchart TD
    Client --> Route
    Route --> Auth["auth:sanctum"]
    Auth --> Permission["spatie/permission"]
    Permission --> Controller["ContactController"]
    Controller --> Request["FormRequest\n(validation)"]
    Request --> Repository["ContactRepository"]
    Repository --> Database[("contacts\ntable")]
    Repository --> Resource["ContactResource\nContactCollection"]
    Resource --> APIResponse["ApiResponse Trait\nJSON Envelope"]
    APIResponse --> Response["200/201/403/404/422"]
```

---

## Endpoints

---

### POST /api/v1/contact-us — Submit Contact (Rate-Limited)

**HTTP Method:** `POST`

**Full URL:** `POST /api/v1/contact-us`

**Authentication:** None

**Permissions:** None

**Rate Limit:** 5 requests/minute per IP (throttle:sensitive)

**Request Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "subject": "Product Inquiry",
  "message": "I have a question about product #123."
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| `name` | required, string, max:255 |
| `email` | required, email, max:255 |
| `subject` | required, string, max:255 |
| `message` | required, string, min:3, max:5000 |

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

**Database Tables Affected:** `contacts` — INSERT

**Events Dispatched:** `ContactMessageReceived`

**Notifications Sent:** `NewContactMessageNotification` (database + broadcast) to all active admin users

**Jobs Dispatched:** None

**Business Rules:**
- Public endpoint, no authentication
- Rate limited to 5 requests/minute/IP
- `is_read` and `is_replay` default to false
- Dispatches `ContactMessageReceived` which triggers admin notification

**Error Cases:**
- 422: Validation failure
- 429: Rate limit exceeded
- 500: Database error or resource creation failure

**Flow Diagram:**

```mermaid
flowchart TD
    A["Client\nPOST /api/v1/contact-us"]
    --> B["Route\npackages/marvel/src/Rest/Routes.php:112\nthrottle:sensitive"]
    --> C["ContactController@store\npackages/marvel/src/Http/Controllers/ContactController.php"]
    --> D["ContactCreateRequest\npackages/marvel/src/Http/Requests/ContactCreateRequest.php"]

    D -->|"authorize()\nalways true"| OK1
    D -->|"rules()\nvalidates name, email,\nsubject, message"| OK2
    D -->|"failedValidation()\ncustom JSON response"| ValErr["422 Validation Error"]

    OK1 --> OK2
    OK2 --> E["ContactRepository::saveContact($request)\npackages/marvel/src/Database/Repositories/ContactRepository.php"]

    E -->|"$request->only(\n  'name','email',\n  'subject','message'\n)"| F["Contact::create($data)\nINSERT INTO contacts"]

    F -->|"success"| G1["ContactMessageReceived::dispatch($contact)\napp/Events/ContactMessageReceived.php"]

    F -->|"exception"| Catch1["catch (MarvelException)"]
    Catch1 -->|"throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE)"| Err500["500 JSON Response"]

    G1 --> H1["SendContactMessageNotification\napp/Listeners/SendContactMessageNotification.php"]

    H1 --> I1["User::where('type', UserType::ADMIN)\n->where('is_active', true)\n->get()"]
    I1 -->|"admin users found"| J1["Notification::send($admins,\n  new NewContactMessageNotification($contact)\n)"]
    I1 -->|"no admin users"| NoOp["No notification sent"]

    J1 --> K1["via(): database, broadcast"]
    K1 --> L1["database\nINSERT INTO notifications"]
    K1 --> M1["broadcast\nPusher: contact.message"]

    E --> N["ContactResource::make($contact)\npackages/marvel/src/Http/Resources/ContactResource.php"]

    N --> O["$this->apiResponse(\n  CONTACT_CREATED_SUCCESSFULLY,\n  201,\n  true,\n  ContactResource::make($contact)\n)"]

    O --> P["201 JSON Response\n{status, message, success, data}"]
```

---

### POST /api/v1/contacts — Submit Contact

**HTTP Method:** `POST`

**Full URL:** `POST /api/v1/contacts`

**Authentication:** None

**Permissions:** None

**Request Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "subject": "Product Inquiry",
  "message": "I have a question about product #123."
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| `name` | required, string, max:255 |
| `email` | required, email, max:255 |
| `subject` | required, string, max:255 |
| `message` | required, string, min:3, max:5000 |

**Success Response (201):** Same as `/api/v1/contact-us`

**Validation Error (422):** Same as `/api/v1/contact-us`

**Server Error (500):** Same as `/api/v1/contact-us`

**Database Tables Affected:** `contacts` — INSERT

**Events Dispatched:** `ContactMessageReceived`

**Notifications Sent:** `NewContactMessageNotification` (database + broadcast) to all active admin users

**Jobs Dispatched:** None

**Business Rules:**
- Public endpoint, no authentication
- No rate limit (unlike `/api/v1/contact-us`)
- Identical behavior to `/api/v1/contact-us` otherwise

**Error Cases:**
- 422: Validation failure
- 500: Database error

**Flow Diagram:**

```mermaid
flowchart TD
    A["Client\nPOST /api/v1/contacts"]
    --> B["Route\npackages/marvel/src/Rest/Routes.php:194\napiResource"]
    --> C["ContactController@store\npackages/marvel/src/Http/Controllers/ContactController.php"]
    --> D["ContactCreateRequest\npackages/marvel/src/Http/Requests/ContactCreateRequest.php"]

    D -->|"authorize()\nalways true"| AuthOK
    D -->|"failedValidation()"| ValErr["422 Validation Error"]

    AuthOK --> E["ContactRepository::saveContact($request)"]

    E -->|"$request->only(...)"| F["Contact::create($data)\nINSERT INTO contacts"]

    F -->|"success"| G["ContactMessageReceived::dispatch($contact)"]

    F -->|"MarvelException"| Catch1
    Catch1["catch (MarvelException)"] --> Err500["500\nCould not create the resource"]

    G --> H["SendContactMessageNotification\nhandle(ContactMessageReceived)"]

    H --> I["Query active admin users"]
    I --> J["Notification::send($admins,\n  NewContactMessageNotification)"]

    J --> K1["Database Channel\nINSERT INTO notifications"]
    J --> K2["Broadcast Channel\nPusher event"]

    E --> L["ContactResource::make($contact)"]
    L --> M["ApiResponse Trait:\napiResponse(CONTACT_CREATED_SUCCESSFULLY, 201, true, ...)"]
    M --> N["201 JSON Response"]
```

---

### GET /api/v1/contacts — List Contacts

**HTTP Method:** `GET`

**Full URL:** `GET /api/v1/contacts`

**Query Parameters:** `page`, `limit`, `read`, `unread`, `replay`, `search`

**Authentication:** Required (Bearer token)

**Permissions:** `view-contacts`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 15 | Results per page |
| `read` | bool | — | Filter `is_read = true` |
| `unread` | bool | — | Filter `is_read = false` |
| `replay` | bool | — | Filter `is_replay = true` |
| `search` | string | — | Search email, subject, message (LIKE) |

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

**Database Tables Affected:** `contacts` — SELECT (with SoftDeletes scope)

**Events Dispatched:** None

**Notifications Sent:** None

**Jobs Dispatched:** None

**Business Rules:**
- Requires authentication and `view-contacts` permission
- Soft-deleted contacts excluded
- `read` and `unread` mutually exclusive
- Paginated with configurable `limit` (default 15)
- Searchable fields: email, subject, message

**Error Cases:**
- 401: No authentication token
- 403: Missing `view-contacts` permission

**Flow Diagram:**

```mermaid
flowchart TD
    A["Client\nGET /api/v1/contacts\n?page=1&limit=15&read=true"]
    --> B["Route\npackages/marvel/src/Rest/Routes.php:194\napiResource('contacts')"]

    B --> C["Middleware: auth:sanctum"]
    C -->|"unauthenticated"| Res401["401 Unauthenticated"]
    C -->|"authenticated"| D1["Middleware: permission:view-contacts"]

    D1 -->|"missing permission"| Res403["403 Forbidden"]
    D1 -->|"has permission"| E["ContactController@index"]

    E --> F["ContactRepository::allContacts($request)"]

    F -->|"1. Base query"| G1["Contact::query()\nEloquent Builder\nwith SoftDeletingScope\n(deleted_at IS NULL)"]

    G1 -->|"2. Search criteria"| G2["pushCriteria(RequestCriteria)\napp(Prettus\\Repository\\Criteria\\RequestCriteria)\n=> WHERE email LIKE %search%\n   OR subject LIKE %search%\n   OR message LIKE %search%"]

    G2 -->|"3. Filter: ?read=true"| H1["scopeRead\nWHERE is_read = 1"]
    G2 -->|"3. Filter: ?unread=true"| H2["scopeUnread\nWHERE is_read = 0"]
    G2 -->|"3. Filter: ?replay=true"| H3["scopeReplay\nWHERE is_replay = 1"]
    G2 -->|"3. No filters"| H4["Skip scopes"]

    H1 --> I["paginate($limit)\ndefault: 15"]
    H2 --> I
    H3 --> I
    H4 --> I

    I --> J[("SELECT contacts\n  WHERE deleted_at IS NULL\n  AND (search conditions)\n  AND (filter conditions)\n  ORDER BY created_at DESC\n  LIMIT 15 OFFSET 0")]

    J --> K["ContactCollection::make($contacts)\npackages/marvel/src/Http/Resources/ContactCollection.php"]

    K --> L["ContactResource::toArray($request)\nfor each contact"]
    L --> M["ApiResponse Trait:\napiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $collection)"]

    M --> N["200 JSON Response\n{\n  data: { data: [...], links: {...} },\n  status: 200,\n  message: 'Data fetched successfully',\n  success: true\n}"]
```

---

### GET /api/v1/contacts/{id} — Show Contact

**HTTP Method:** `GET`

**Full URL:** `GET /api/v1/contacts/{id}`

**Authentication:** Required (Bearer token)

**Permissions:** `update-contact`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | int | Contact ID |

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

**Database Tables Affected:**
- `contacts` — SELECT (find contact)
- `contacts` — UPDATE (set `is_read = true`)

**Events Dispatched:** None

**Notifications Sent:** None

**Jobs Dispatched:** None

**Business Rules:**
- Requires authentication and `update-contact` permission
- Automatically marks contact as read (`is_read = true`)
- Returns 404 if contact does not exist or is soft-deleted

**Error Cases:**
- 401: No authentication token
- 403: Missing `update-contact` permission
- 404: Contact not found or soft-deleted

**Flow Diagram:**

```mermaid
flowchart TD
    A["Client\nGET /api/v1/contacts/42"]
    --> B["Route\npackages/marvel/src/Rest/Routes.php:194\napiResource('contacts')"]

    B --> C["Middleware: auth:sanctum"]
    C -->|"unauthenticated"| Res401["401 Unauthenticated"]
    C -->|"authenticated"| D1["Middleware: permission:update-contact"]

    D1 -->|"missing permission"| Res403["403 Forbidden"]
    D1 -->|"has permission"| E["ContactController@show($id)"]

    E --> F["ContactRepository::findOrFail($id)"]

    F -->|"Contact::findOrFail($id)\nwith SoftDeletingScope"| G1[("SELECT contacts\nWHERE id = 42\nAND deleted_at IS NULL")]

    G1 -->|"not found\nModelNotFoundException"| Catch1["catch (ModelNotFoundException)"]
    Catch1 -->|"throw new MarvelException(NOT_FOUND)"| Res404["404 Not Found"]

    G1 -->|"found"| H["$contact->update(['is_read' => true])\nUPDATE contacts SET is_read = 1\nWHERE id = 42"]

    H --> I["ContactResource::make($contact)"]
    I --> J["ContactResource::toArray():\n  id, email, subject,\n  message, is_read (true),\n  is_replay, created_at"]

    J --> K["ApiResponse Trait:\napiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $resource)"]
    K --> L["200 JSON Response"]
```

---

### POST /api/v1/contacts/{id}/replay — Send Reply

**HTTP Method:** `POST`

**Full URL:** `POST /api/v1/contacts/42/replay`

**Authentication:** Required (Bearer token)

**Permissions:** `update-contact`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | int | Original contact ID |

**Request Body:**
```json
{
  "subject": "RE: Product Inquiry",
  "message": "Thank you for your inquiry. Product #123 is in stock."
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| `subject` | required, string, max:255 |
| `message` | required, string, min:3, max:5000 |

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

**Not Found (404):**
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
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

**Database Tables Affected:**
- `contacts` — SELECT (find original)
- `contacts` — INSERT (create reply)

**Events Dispatched:** None (reply does NOT fire ContactMessageReceived)

**Notifications Sent:** None

**Jobs Dispatched:** None

**Business Rules:**
- Requires authentication and `update-contact` permission
- Uses original contact's email for the new reply record
- Creates a NEW contact record (original is NOT modified)
- New record has `is_read = true`, `is_replay = true`
- Original record remains unchanged (no mark as read, no mark as replayed)

**Error Cases:**
- 401: No authentication token
- 403: Missing `update-contact` permission
- 404: Original contact not found or soft-deleted
- 422: Validation failure

**Flow Diagram:**

```mermaid
flowchart TD
    A["Client\nPOST /api/v1/contacts/42/replay"]
    --> B["Route\npackages/marvel/src/Rest/Routes.php:195\ncontacts/{id}/replay"]

    B --> C["Middleware: auth:sanctum"]
    C -->|"unauthenticated"| Res401["401 Unauthenticated"]
    C -->|"authenticated"| D1["Middleware: permission:update-contact"]

    D1 -->|"missing permission"| Res403["403 Forbidden"]
    D1 -->|"has permission"| E["ContactController@sendReplay($id)"]

    E --> F["ContactCreateReplayRequest\npackages/marvel/src/Http/Requests/ContactCreateReplayRequest.php"]

    F -->|"rules():\nsubject: required, string, max:255\nmessage: required, string, min:3, max:5000"| Valid
    F -->|"failedValidation()"| ValErr["422 Validation Error"]

    Valid --> G["ContactRepository::replayContact($request, $id)"]

    G -->|"1. Find original"| H["Contact::findOrFail($id)\nSELECT * FROM contacts\nWHERE id = 42\nAND deleted_at IS NULL"]

    H -->|"not found\nModelNotFoundException"| Catch1["catch (ModelNotFoundException)"]
    Catch1 -->|"throw new MarvelException(NOT_FOUND)"| Res404["404 Not Found"]

    H -->|"found"| I["$original->email\n'john@example.com'"]

    I --> J["Contact::create([\n  'name' => '',\n  'email' => $original->email,\n  'subject' => $request->subject,\n  'message' => $request->message,\n  'is_read' => true,\n  'is_replay' => true\n])"]

    J --> K[("INSERT INTO contacts\n(name, email, subject, message,\n is_read, is_replay, created_at,\n updated_at)")]

    K --> L["ContactResource::make($reply)"]

    L --> M["ApiResponse Trait:\napiResponse(REPLAY_SENT_SUCCESSFULLY, 200, true, $resource)"]
    M --> N["200 JSON Response"]
```

---

### DELETE /api/v1/contacts/{id} — Delete Contact

**HTTP Method:** `DELETE`

**Full URL:** `DELETE /api/v1/contacts/42`

**Authentication:** Required (Bearer token)

**Permissions:** `delete-contact`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | int | Contact ID |

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

**Database Tables Affected:** `contacts` — UPDATE (set `deleted_at = now()`) — soft delete

**Events Dispatched:** None

**Notifications Sent:** None

**Jobs Dispatched:** None

**Business Rules:**
- Requires authentication and `delete-contact` permission
- Soft delete — record remains in database but excluded from queries
- Returns 404 if contact not found or already soft-deleted

**Error Cases:**
- 401: No authentication token
- 403: Missing `delete-contact` permission
- 404: Contact not found

**Flow Diagram:**

```mermaid
flowchart TD
    A["Client\nDELETE /api/v1/contacts/42"]
    --> B["Route\npackages/marvel/src/Rest/Routes.php:194\napiResource('contacts')"]

    B --> C["Middleware: auth:sanctum"]
    C -->|"unauthenticated"| Res401["401 Unauthenticated"]
    C -->|"authenticated"| D1["Middleware: permission:delete-contact"]

    D1 -->|"missing permission"| Res403["403 Forbidden"]
    D1 -->|"has permission"| E["ContactController@destroy($id)"]

    E --> F["ContactRepository::findOrFail($id)\nContact::findOrFail($id)"]

    F -->|"not found\nModelNotFoundException"| Catch1["catch (ModelNotFoundException)"]
    Catch1 -->|"throw MarvelException(NOT_FOUND)"| Res404["404 Not Found"]

    F -->|"found"| G["$contact->delete()"]

    G -->|"SoftDeletes trait\nrunSoftDelete()"| H[("UPDATE contacts\nSET deleted_at = NOW()\nWHERE id = 42")]

    H --> I["ApiResponse Trait:\napiResponse(CONTACT_DELETED_SUCCESSFULLY, 200, true)"]
    I --> J["200 JSON Response\n{status: 200, success: true,\n message: 'Contact deleted successfully'}"]
```

---

### DELETE /api/v1/contacts/delete-all — Delete All Contacts

**HTTP Method:** `DELETE`

**Full URL:** `DELETE /api/v1/contacts/delete-all`

**Authentication:** Required (Bearer token)

**Permissions:** `delete-contact`

**Success Response (200):**
```json
{
  "status": 200,
  "message": "All contacts deleted successfully",
  "success": true
}
```

**Server Error (500):**
```json
{
  "status": 500,
  "message": "Could not delete the resource",
  "success": false
}
```

**Database Tables Affected:** `contacts` — UPDATE (set `deleted_at = now()` on all non-deleted rows)

**Events Dispatched:** None

**Notifications Sent:** None

**Jobs Dispatched:** None

**Business Rules:**
- Requires authentication and `delete-contact` permission
- Soft-deletes ALL contacts (mass update, not model instance delete)
- Uses `SoftDeletingScope` — only affects non-deleted records
- No per-model events fired (mass update)

**Error Cases:**
- 401: No authentication token
- 403: Missing `delete-contact` permission
- 500: Database error

**Flow Diagram:**

```mermaid
flowchart TD
    A["Client\nDELETE /api/v1/contacts/delete-all"]
    --> B["Route\npackages/marvel/src/Rest/Routes.php:196\ncontacts/delete-all"]

    B --> C["Middleware: auth:sanctum"]
    C -->|"unauthenticated"| Res401["401 Unauthenticated"]
    C -->|"authenticated"| D1["Middleware: permission:delete-contact"]

    D1 -->|"missing permission"| Res403["403 Forbidden"]
    D1 -->|"has permission"| E["ContactController@deleteAll()"]

    E --> F["try {"] --> G["ContactRepository::deleteAllContacts()"]

    G -->|"Contact::query()\nwith SoftDeletingScope\n->update(['deleted_at' => now()])"| H[("UPDATE contacts\nSET deleted_at = NOW()\nWHERE deleted_at IS NULL")]

    H --> I["$this->apiResponse(\n  ALL_CONTACTS_DELETED_SUCCESSFULLY,\n  200,\n  true\n)"]

    I --> J["200 JSON Response"]

    E --> K["} catch (\\Exception $e) {"]
    K --> L["$this->apiResponse(\n  COULD_NOT_DELETE_THE_RESOURCE,\n  500,\n  false\n)"]
    L --> M["500 JSON Response"]
```

---

### DELETE /api/v1/contacts/delete-all-read — Delete All Read Contacts

**HTTP Method:** `DELETE`

**Full URL:** `DELETE /api/v1/contacts/delete-all-read`

**Authentication:** Required (Bearer token)

**Permissions:** `delete-read-contacts`

**Success Response (200):**
```json
{
  "status": 200,
  "message": "All read contacts deleted successfully",
  "success": true
}
```

**Server Error (500):**
```json
{
  "status": 500,
  "message": "Could not delete the resource",
  "success": false
}
```

**Database Tables Affected:** `contacts` — UPDATE (set `deleted_at = now()` on rows WHERE `is_read = true` AND `deleted_at IS NULL`)

**Events Dispatched:** None

**Notifications Sent:** None

**Jobs Dispatched:** None

**Business Rules:**
- Requires authentication and `delete-read-contacts` permission
- Soft-deletes only contacts where `is_read = true`
- Unread contacts are NOT affected
- Mass update — no per-model events fired

**Error Cases:**
- 401: No authentication token
- 403: Missing `delete-read-contacts` permission
- 500: Database error

**Flow Diagram:**

```mermaid
flowchart TD
    A["Client\nDELETE /api/v1/contacts/delete-all-read"]
    --> B["Route\npackages/marvel/src/Rest/Routes.php:197\ncontacts/delete-all-read"]

    B --> C["Middleware: auth:sanctum"]
    C -->|"unauthenticated"| Res401["401 Unauthenticated"]
    C -->|"authenticated"| D1["Middleware: permission:delete-read-contacts"]

    D1 -->|"missing permission"| Res403["403 Forbidden"]
    D1 -->|"has permission"| E["ContactController@deleteAllReadContacts()"]

    E --> F["try {"] --> G["ContactRepository::deleteAllReadContacts()"]

    G -->|"Contact::query()\n->where('is_read', true)\n->update(['deleted_at' => now()])"| H[("UPDATE contacts\nSET deleted_at = NOW()\nWHERE is_read = 1\nAND deleted_at IS NULL")]

    H --> I["$this->apiResponse(\n  ALL_READ_CONTACTS_DELETED_SUCCESSFULLY,\n  200,\n  true\n)"]

    I --> J["200 JSON Response"]

    E --> K["} catch (\\Exception $e) {"]
    K --> L["$this->apiResponse(\n  COULD_NOT_DELETE_THE_RESOURCE,\n  500,\n  false\n)"]
    L --> M["500 JSON Response"]
```

---

## Permissions Map

| Permission Enum | String Value | Applied To |
|----------------|--------------|------------|
| `VIEW_CONTACTS` | `view-contacts` | GET /api/v1/contacts |
| `UPDATE_CONTACT` | `update-contact` | GET /api/v1/contacts/{id}, POST /api/v1/contacts/{id}/replay |
| `DELETE_CONTACT` | `delete-contact` | DELETE /api/v1/contacts/{id}, DELETE /api/v1/contacts/delete-all |
| `DELETE_READ_CONTACTS` | `delete-read-contacts` | DELETE /api/v1/contacts/delete-all-read |

---

## Database Schema

### `contacts` Table

| Column | Type | Constraints | Default | Description |
|--------|------|-------------|---------|-------------|
| id | bigint | PK, AUTO_INCREMENT | | Unique identifier |
| name | varchar(255) | NOT NULL | | Sender name |
| email | varchar(255) | NOT NULL | | Sender email |
| subject | varchar(255) | NOT NULL | | Message subject |
| message | text | NOT NULL | | Message body |
| is_read | tinyint(1) | NOT NULL | 0 | Read status |
| is_replay | tinyint(1) | NOT NULL | 0 | Reply status |
| created_at | timestamp | NULLABLE | NULL | Creation time |
| updated_at | timestamp | NULLABLE | NULL | Last update |
| deleted_at | timestamp | NULLABLE | NULL | Soft delete timestamp |

---

## Event Flow

### ContactMessageReceived

| Property | Value |
|----------|-------|
| Class | `App\Events\ContactMessageReceived` |
| Dispatched by | `ContactRepository::saveContact()` after `Contact::create()` |
| Trigger | Contact creation via POST /api/v1/contacts or POST /api/v1/contact-us |
| Data | `$contact` (Contact model instance) |
| Listener | `App\Listeners\SendContactMessageNotification` |

### SendContactMessageNotification

| Property | Value |
|----------|-------|
| handle() | Queries `User::where('type', UserType::ADMIN)->where('is_active', true)->get()` |
| Sends | `NewContactMessageNotification` to all found admin users |
| Channels | `database`, `broadcast` |
| Queueable | Yes (`ShouldQueue` + `Queueable`) |

### NewContactMessageNotification

| Property | Value |
|----------|-------|
| Database payload | title, message, icon, resource_type, resource_id, action_url, contact_id, customer_name, customer_email, subject |
| Broadcast type | `contact.message` |

---

## Response Envelope

```json
{
  "status": 200,
  "message": "Localized message string",
  "success": true,
  "data": {}
}
```

Implemented via `Marvel\Traits\ApiResponse` — used by `ContactController` which extends `CoreController`.

---

## Complete File Dependency Map

| Layer | File | Responsibility |
|-------|------|----------------|
| Routes | `packages/marvel/src/Rest/Routes.php:108,193-196` | Route definitions |
| Controller | `packages/marvel/src/Http/Controllers/ContactController.php` | HTTP handling |
| CoreController | `packages/marvel/src/Http/Controllers/CoreController.php` | Base controller (pagination, limit) |
| Create Request | `packages/marvel/src/Http/Requests/ContactCreateRequest.php` | Create validation |
| Reply Request | `packages/marvel/src/Http/Requests/ContactCreateReplayRequest.php` | Reply validation |
| Repository | `packages/marvel/src/Database/Repositories/ContactRepository.php` | Data access |
| Model | `packages/marvel/src/Database/Models/Contact.php` | Eloquent model + scopes |
| Resource | `packages/marvel/src/Http/Resources/ContactResource.php` | Response transform |
| Collection | `packages/marvel/src/Http/Resources/ContactCollection.php` | Paginated transform |
| Event | `app/Events/ContactMessageReceived.php` | Event fired on create |
| Listener | `app/Listeners/SendContactMessageNotification.php` | Event handler |
| Notification | `app/Notifications/NewContactMessageNotification.php` | DB + broadcast |
| Permission Enum | `packages/marvel/src/Enums/Permission.php` | Permission constants |
| ApiResponse Trait | `packages/marvel/src/Traits/ApiResponse.php` | Response envelope |
| Migration | `packages/marvel/database/migrations/2026_05_09_000003_create_contacts_table.php` | Schema |
| EN Translations | `resources/lang/en/message.php` | English messages |
| AR Translations | `resources/lang/ar/message.php` | Arabic messages |
| Constants | `packages/marvel/config/constants.php` | Message keys |

---

## Localization Keys

All keys prefixed with `APP_NOTICE_DOMAIN` (default: `MARVEL_`). Final key example: `MARVEL_MESSAGE.CONTACT_CREATED_SUCCESSFULLY`.

| Constant | English |
|----------|---------|
| `MESSAGE.CONTACT_CREATED_SUCCESSFULLY` | Contact created successfully |
| `MESSAGE.REPLAY_SENT_SUCCESSFULLY` | Replay sent successfully |
| `MESSAGE.CONTACT_DELETED_SUCCESSFULLY` | Contact deleted successfully |
| `MESSAGE.ALL_CONTACTS_DELETED_SUCCESSFULLY` | All contacts deleted successfully |
| `MESSAGE.ALL_READ_CONTACTS_DELETED_SUCCESSFULLY` | All read contacts deleted successfully |
| `MESSAGE.FETCH_DATA_SUCCESSFULLY` | Data fetched successfully |
| `ERROR.COULD_NOT_CREATE_THE_RESOURCE` | Could not create the resource |
| `ERROR.COULD_NOT_DELETE_THE_RESOURCE` | Could not delete the resource |
