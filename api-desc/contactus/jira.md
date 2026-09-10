# JIRA — Contacts Module (Frontend)

## Overview

The Contacts module enables website visitors to submit inquiries through a contact form and allows admins to view, search, filter, reply to, and delete messages.

This document contains all frontend tasks required to implement the Contacts feature. Each task is written as a user story with full API contract details, UI states, and acceptance criteria.

---

## Task 1: Contact List Page

### Feature Summary

Display a paginated, filterable, searchable list of contact messages for admins.

### User Story

As an admin,
I want to view a list of all contact messages
so that I can read, filter, and manage customer inquiries.

### Business Goal

Provide admins with a central inbox to manage all customer-submitted messages.

### API Contract

**Endpoint:** `GET /api/v1/contacts`

**HTTP Method:** `GET`

**Authentication:** Required (Bearer token)

**Permissions:** `view-contacts`

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Example URLs:**
```
GET /api/v1/contacts
GET /api/v1/contacts?page=1
GET /api/v1/contacts?page=1&limit=15
GET /api/v1/contacts?limit=50
GET /api/v1/contacts?read=true
GET /api/v1/contacts?unread=true
GET /api/v1/contacts?replay=true
GET /api/v1/contacts?search=keyword
GET /api/v1/contacts?read=true&search=support&page=2&limit=10
```

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 15 | Items per page |
| `read` | bool | — | Filter to read messages only |
| `unread` | bool | — | Filter to unread messages only |
| `replay` | bool | — | Filter to replied messages only |
| `search` | string | — | Search across email, subject, message |

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

**Error Responses:**

401 Unauthenticated:
```json
{
  "message": "Unauthenticated."
}
```

403 Forbidden:
```json
{
  "status": 403,
  "message": "You are not authorized to perform this action",
  "success": false
}
```

**Response Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Contact ID (use for navigation to detail page) |
| `email` | string | Sender email |
| `subject` | string | Message subject |
| `message` | string | Message body (preview) |
| `is_read` | bool | Whether message has been read |
| `is_replay` | bool | Whether admin has replied |
| `created_at` | string | ISO-8601 datetime |

### UI States

| State | Behavior |
|-------|----------|
| **Initial Loading** | Show skeleton rows (6 rows matching table column layout) |
| **Success (has data)** | Render table or card list with pagination |
| **Empty (no contacts at all)** | "No messages yet." with an illustration |
| **Empty (no filter results)** | "No messages match your filter." with a "Clear filters" button |
| **Unauthorized (401)** | Redirect to login page |
| **Forbidden (403)** | Show toast: "You don't have permission to view contacts." |
| **Network Error** | Show toast: "Could not load messages. Check your connection." with Retry button |

### UI Behavior

- Table columns: Email, Subject, Message (truncated), Status (read/unread/replied), Date
- Click row → navigate to contact detail page (`/contacts/{id}`)
- Search input with debounce (300ms) at top of list
- Filter toggles for Read / Unread / Replied
- Read/unread toggles are mutually exclusive — selecting one deselects the other
- Pagination controls at bottom: page numbers, Previous/Next, total count
- Limit selector (15, 25, 50) resets to page 1

### Acceptance Criteria

- [ ] User can view paginated list of contacts
- [ ] User can search by keyword with debounced input
- [ ] User can filter by read/unread/replied status
- [ ] Read and unread filters are mutually exclusive
- [ ] Pagination controls work correctly
- [ ] Skeleton loader shows while data loads
- [ ] Empty state shows when no contacts exist
- [ ] Empty filter state shows with clear button
- [ ] 401 redirects to login
- [ ] 403 shows permission error toast

### QA Checklist

- [ ] Verify pagination with different limits
- [ ] Verify search returns correct results
- [ ] Verify read/unread filtered results
- [ ] Verify read+unread together returns empty
- [ ] Verify soft-deleted contacts do not appear
- [ ] Verify empty state after deleting all contacts

### API Integration Notes

- The `id` field is used to navigate to the detail page
- The `is_read` and `is_replay` booleans determine status badges
- Do NOT send both `read=true` and `unread=true` — backend returns empty

---

## Task 2: Contact Detail Page

### Feature Summary

Display a single contact message and automatically mark it as read.

### User Story

As an admin,
I want to view the full message of a contact
so that I can read the customer's inquiry.

### Business Goal

Allow admins to read complete contact messages.

### API Contract

**Endpoint:** `GET /api/v1/contacts/{id}`

**HTTP Method:** `GET`

**Authentication:** Required (Bearer token)

**Permissions:** `update-contact`

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Example URL:**
```
GET /api/v1/contacts/1
GET /api/v1/contacts/42
```

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

**Error Responses:**

404 Not Found:
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

**Note:** `is_read` will always be `true` in the response — the backend sets it automatically when the endpoint is called.

### UI States

| State | Behavior |
|-------|----------|
| **Loading** | Full-page skeleton or spinner |
| **Success** | Display full message with sender info, subject, body, date |
| **Not Found (404)** | Show "Message not found" page with link back to list |
| **Unauthorized (401)** | Redirect to login |
| **Forbidden (403)** | Show toast |
| **Network Error** | Show toast with Retry |

### UI Behavior

- Display: sender email, subject, full message body, received date
- Show "Read" badge (always true)
- Show "Replied" badge if `is_replay` is true
- Provide "Reply" button → navigates to reply form
- Provide "Back to list" link
- Provide "Delete" button → confirmation → delete + redirect to list

### Acceptance Criteria

- [ ] User can view full contact message
- [ ] Message is shown as read (badge updates)
- [ ] Reply button is visible and functional
- [ ] Delete button shows confirmation dialog
- [ ] Back to list link works
- [ ] 404 shows "Not found" page
- [ ] Loading state shows while fetching

### QA Checklist

- [ ] Verify `is_read` is true in response (backend behaviour)
- [ ] Verify reply button navigates to reply form
- [ ] Verify delete + redirect works

### API Integration Notes

- Calling this endpoint automatically marks the contact as read on the backend
- No separate "mark as read" call is needed

---

## Task 3: Reply to Contact

### Feature Summary

Send a reply to a contact message. The reply creates a new record linked to the same email.

### User Story

As an admin,
I want to reply to a contact message
so that I can respond to the customer's inquiry.

### Business Goal

Enable admins to respond to customer inquiries.

### API Contract

**Endpoint:** `POST /api/v1/contacts/{id}/replay`

**HTTP Method:** `POST`

**Authentication:** Required (Bearer token)

**Permissions:** `update-contact`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

**Example URL:**
```
POST /api/v1/contacts/1/replay
POST /api/v1/contacts/42/replay
```

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | int | Original contact ID to reply to |

**Request Body:**
```json
{
  "subject": "RE: Product Inquiry",
  "message": "Thank you for your inquiry. Product #123 is in stock."
}
```

**Field Validation:**

| Field | Required | Rules |
|-------|----------|-------|
| `subject` | Yes | max 255 characters |
| `message` | Yes | 3-5000 characters |

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

### UI States

| State | Behavior |
|-------|----------|
| **Initial** | Reply form with subject (pre-filled "RE: {original subject}") and message fields |
| **Submitting** | Spinner on send button, button disabled |
| **Success** | Toast "Reply sent" + redirect back to contact detail or list |
| **Validation Error** | Field-level errors below each input |
| **Not Found (404)** | Toast "Original message not found" + redirect to list |
| **Unauthorized (401)** | Redirect to login |
| **Forbidden (403)** | Show toast |
| **Network Error** | Toast with Retry on send button |

### UI Behavior

- Pre-fill the subject field with "RE: {original subject}" (editable)
- Message field is a textarea (min 3, max 5000 chars)
- Character counter for message field
- Send button disabled while loading
- After success: show success toast, navigate back to contact detail

### Acceptance Criteria

- [ ] User can write and send a reply
- [ ] Subject is pre-filled with "RE: " prefix (editable)
- [ ] Validation errors appear inline under the correct field
- [ ] Send button shows spinner and is disabled while submitting
- [ ] Success toast appears after reply sent
- [ ] User is redirected back to contact detail after success
- [ ] 404 shows error toast and redirects to list

### QA Checklist

- [ ] Verify reply creates a new record (count increases)
- [ ] Verify original contact is_unread and is_replay unchanged
- [ ] Verify email is auto-copied from original contact
- [ ] Verify message min 3 validation
- [ ] Verify subject max 255 validation

### API Integration Notes

- The reply creates a **new** contact record — the original contact is NOT modified
- The email is automatically taken from the original contact — do NOT send it in the request
- The response contains the new reply's `id`, not the original

---

## Task 4: Create Contact (Public Form)

### Feature Summary

Allow website visitors to submit contact inquiries.

### User Story

As a website visitor,
I want to submit a contact form
so that I can send a message to the company.

### Business Goal

Provide a public-facing contact form for customer inquiries.

### API Contract

**Endpoint:** `POST /api/v1/contacts`

**HTTP Method:** `POST`

**Authentication:** None

**Permissions:** None

**Headers:**
```
Content-Type: application/json
Accept: application/json
```

**Example URL:**
```
POST /api/v1/contacts
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

**Field Validation:**

| Field | Required | Rules |
|-------|----------|-------|
| `name` | Yes | max 255 characters |
| `email` | Yes | valid email, max 255 |
| `subject` | Yes | max 255 characters |
| `message` | Yes | 3-5000 characters |

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

### UI States

| State | Behavior |
|-------|----------|
| **Initial** | Contact form with name, email, subject, message fields |
| **Submitting** | Spinner on submit button, button disabled |
| **Success** | Toast "Message sent successfully" + clear form or redirect to thank-you page |
| **Validation Error** | Inline errors below each field |
| **Network Error** | Toast "Could not send message. Please try again." with Retry |

### UI Behavior

- Name and email are text inputs
- Subject is a text input
- Message is a textarea (min 3, max 5000 chars) with character counter
- Submit button disabled while loading
- Show success confirmation after submission

### Acceptance Criteria

- [ ] User can fill and submit the form
- [ ] All 4 fields are validated with inline error messages
- [ ] Submit button shows spinner and is disabled while loading
- [ ] Success message appears after submission
- [ ] Form clears or shows thank-you message after success

### QA Checklist

- [ ] Verify all 4 fields required (name, email, subject, message)
- [ ] Verify email format validation
- [ ] Verify message min 3 and max 5000 validation
- [ ] Verify name max 255 validation
- [ ] Verify subject max 255 validation

### API Integration Notes

- This is a public endpoint — no auth token needed
- The `/api/v1/contact-us` endpoint behaves identically but has rate limiting (5/min/IP)
- Use `/api/v1/contact-us` for public-facing forms and `/api/v1/contacts` for admin-facing forms

---

## Task 5: Delete Single Contact

### Feature Summary

Delete a single contact message.

### User Story

As an admin,
I want to delete a contact message
so that I can remove irrelevant or spam messages.

### Business Goal

Allow admins to clean up individual contact messages.

### API Contract

**Endpoint:** `DELETE /api/v1/contacts/{id}`

**HTTP Method:** `DELETE`

**Authentication:** Required (Bearer token)

**Permissions:** `delete-contact`

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Example URL:**
```
DELETE /api/v1/contacts/1
DELETE /api/v1/contacts/42
```

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

### UI States

| State | Behavior |
|-------|----------|
| **Initial** | Confirmation dialog: "Are you sure you want to delete this message?" |
| **Deleting** | Spinner on confirm button, buttons disabled |
| **Success** | Toast "Message deleted" + remove item from list or redirect to list |
| **Not Found (404)** | Toast "Message not found" |
| **Forbidden (403)** | Toast "You don't have permission to delete" |
| **Network Error** | Toast "Could not delete. Please try again." |

### Acceptance Criteria

- [ ] Confirmation dialog appears before delete
- [ ] Confirm button shows spinner while deleting
- [ ] Success toast appears after delete
- [ ] Item is removed from list without full page reload
- [ ] 404 shows error toast

### QA Checklist

- [ ] Verify contact is removed from list
- [ ] Verify deleted contact does not appear in list after refresh
- [ ] Verify delete of already-deleted contact returns 404

---

## Task 6: Bulk Delete All Contacts

### Feature Summary

Delete all contact messages at once.

### User Story

As an admin,
I want to delete all contact messages at once
so that I can quickly clear the inbox.

### Business Goal

Provide bulk cleanup functionality for admins.

### API Contract

**Endpoint:** `DELETE /api/v1/contacts/delete-all`

**HTTP Method:** `DELETE`

**Authentication:** Required (Bearer token)

**Permissions:** `delete-contact`

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Example URL:**
```
DELETE /api/v1/contacts/delete-all
```

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

### UI States

| State | Behavior |
|-------|----------|
| **Initial** | Confirmation dialog with warning: "Delete ALL messages? This action hides all messages." |
| **Deleting** | Spinner on confirm button, buttons disabled |
| **Success** | Toast "All messages deleted" + list clears (empty state shown) |
| **Error** | Toast "Could not delete messages. Please try again." |

### Acceptance Criteria

- [ ] Confirmation dialog with strong warning appears
- [ ] Confirm button shows spinner while deleting
- [ ] List clears and shows empty state after success
- [ ] Full-page loader not needed — inline deletion is sufficient

### QA Checklist

- [ ] Verify all contacts removed from list
- [ ] Verify bulk delete does not cause page crash
- [ ] Verify empty state appears after deletion

---

## Task 7: Bulk Delete Read Contacts

### Feature Summary

Delete only read contact messages.

### User Story

As an admin,
I want to delete only read contact messages
so that I can keep unread messages for follow-up.

### Business Goal

Allow selective bulk cleanup of already-handled messages.

### API Contract

**Endpoint:** `DELETE /api/v1/contacts/delete-all-read`

**HTTP Method:** `DELETE`

**Authentication:** Required (Bearer token)

**Permissions:** `delete-read-contacts`

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Example URL:**
```
DELETE /api/v1/contacts/delete-all-read
```

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

### UI States

| State | Behavior |
|-------|----------|
| **Initial** | Confirmation dialog: "Delete all read messages?" |
| **Deleting** | Spinner on confirm button |
| **Success** | Toast "Read messages deleted" + list refreshes |
| **Error** | Toast with retry |

### Acceptance Criteria

- [ ] Confirmation dialog appears
- [ ] List refreshes after deletion (read contacts removed, unread remain)
- [ ] Success toast appears

### QA Checklist

- [ ] Verify only read contacts are removed
- [ ] Verify unread contacts remain in list
- [ ] Verify list refreshes correctly

---

## Implementation Priority Order

1. **Task 4** — Create Contact (public form, highest business impact)
2. **Task 1** — Contact List Page (foundation for all other admin tasks)
3. **Task 2** — Contact Detail Page (required before reply and delete)
4. **Task 3** — Reply to Contact
5. **Task 5** — Delete Single Contact
6. **Task 6** — Bulk Delete All Contacts
7. **Task 7** — Bulk Delete Read Contacts

---

## Global Error Handling

| HTTP Status | Frontend Action |
|-------------|-----------------|
| 401 | Redirect to login page |
| 403 | Show toast: "You don't have permission" |
| 422 | Display field-level validation errors below inputs |
| 429 | Show toast: "Too many requests. Please wait." |
| 404 | Show "Not found" message or toast |
| 500 | Show toast: "Something went wrong." with Retry |

---

## Integration Notes

- Base URL: `/api/v1`
- Auth token format: `Bearer {token}` (Sanctum)
- All admin endpoints require the user to have the appropriate permission
- The public contact form does not require authentication
- All responses follow the same envelope format: `{status, message, success, data}`
- Dates are returned in ISO-8601 format (e.g., `2026-06-20T12:00:00.000000Z`)
