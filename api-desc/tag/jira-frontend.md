# Tag Module — Frontend Jira Tasks

---

## Task 1: Admin Tag Listing Page

**Priority:** High
**Component:** Frontend — Admin Tags Page
**Story Points:** 5

**Description:** Build the admin tag management page with a sortable, filterable table.

**API Endpoint:**
- `GET /api/v1/tags?page=&limit=&language=`

**Acceptance Criteria:**
- [ ] Table with columns: ID, Name, Slug, Image/Icon, Products count, Actions
- [ ] Pagination using the standard envelope: read `data.total`, `data.last_page`, `data.current_page` (response is `{ status, message, success, data: { data, page, current_page, from, to, last_page, path, per_page, total, ... } }`)
- [ ] Language filter dropdown
- [ ] Search field filters by name
- [ ] Each row shows edit/delete action buttons
- [ ] Loading skeleton while fetching
- [ ] Empty state: "No tags found" with "Create Tag" CTA

---

## Task 2: Admin Tag Create/Edit Form — with Product Assignment

**Priority:** High
**Component:** Frontend — Admin Tag Form
**Story Points:** 5

**Description:** Build the create/edit form for tags with translatable name, image upload, icon upload, and product assignment via the `product_tag` relation.

**API Endpoints:**
- `POST /api/v1/tags`
- `PUT /api/v1/tags/{id}`
- `GET /api/v1/tags/{id}` (load existing — returns `products` array)

**Acceptance Criteria:**
- [ ] Create mode: empty form, Edit mode: pre-filled
- [ ] Translatable `name` field with language tabs (en, ar)
- [ ] Product multi-select: pick products to attach; body sends `products: [id, id, ...]`
- [ ] On edit, pre-select the products returned in `data.products`
- [ ] Image upload with preview
- [ ] Icon upload with preview
- [ ] Form submits as `multipart/form-data`
- [ ] Validation errors displayed per field
- [ ] Loading skeleton on edit fetch
- [ ] On success (201 create / 200 update): read from `data` field of the response envelope

---

## Task 3: Admin Tag Delete

**Priority:** Medium
**Component:** Frontend — Delete Modal
**Story Points:** 2

**Description:** Implement delete with confirmation modal.

**API Endpoint:**
- `DELETE /api/v1/tags/{id}`

**Acceptance Criteria:**
- [ ] Clicking delete opens confirmation modal
- [ ] Modal shows tag name and a warning that linked products are unaffected (only pivot links are removed)
- [ ] Confirm button triggers delete
- [ ] On success: remove row with success toast (response is `{ status: 200, message: "Tag deleted successfully", success: true, data: true }`)
- [ ] On 404 error: row already deleted, remove from table
- [ ] On network error: toast with error message

---

## Task 4: Product Assignment Sync Behavior

**Priority:** High
**Component:** Frontend — Tag Form Product Selector
**Story Points:** 3

**Description:** Understand and implement the product `sync` semantics so the form behaves correctly.

**API Endpoints:**
- `POST /api/v1/tags` — `products` attaches products on create
- `PUT /api/v1/tags/{id}` — `products` **replaces** all existing associations (`sync`)

**Acceptance Criteria:**
- [ ] On create with `products: [1, 2]`, the tag is created with both products attached
- [ ] On update, the sent `products` list fully replaces the previous associations
- [ ] **Sending `products: []` clears all product associations** — the form must be able to represent "no products" and send an empty array
- [ ] **Omitting `products` leaves associations untouched** — the form must NOT send an empty array when the field was simply not edited
- [ ] Invalid product IDs (non-existent) produce a 422 with `errors.products.*`
- [ ] After update, refresh the tag detail (`GET /tags/{id}`) to confirm the returned `products` array matches what was sent

---

## Task 5: Admin Tag — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 2

**Description:** Handle all non-happy-path states across the tag admin pages.

**Acceptance Criteria:**
- [ ] **Listing loading:** Skeleton table rows
- [ ] **Listing empty:** "No tags yet" with "Create your first tag" button
- [ ] **Listing error:** Error message with "Retry" button
- [ ] **Form loading (edit):** Skeleton while fetching tag data
- [ ] **Form error:** Toast with error message
- [ ] **Form validation:** Inline field errors from API 422 response (`errors` key)
- [ ] **Delete error (not found):** Toast "Tag already deleted"
- [ ] **Network error:** Toast "Network error, please try again" for all API calls
- [ ] All success/error handling reads from the standard `{ status, message, success, data }` envelope

---

## Task 6: Admin Tag — Multilingual Translatable `name` Field

**Priority:** Medium
**Component:** Frontend — i18n
**Story Points:** 2

**Description:** Handle the translatable `name` field (sent/received as language-keyed object).

**Request/Response format:**
```json
{
  "name": { "en": "Organic", "ar": "عضوي" }
}
```

**Acceptance Criteria:**
- [ ] Language tabs for each supported locale
- [ ] Each tab shows separate input for `name`
- [ ] On save, serialized to `{ "en": "...", "ar": "..." }` format
- [ ] On load, each tab shows correct translation
- [ ] Default language tab pre-selected
- [ ] Validation errors shown per-language
- [ ] `name` in the response is a single translated string (locale-aware), not the raw object — except on the show route which returns the full translation object

---

## Jest Test Cases

### Tag API service layer
1. `listTags()` — GET `/api/v1/tags` → returns paginated `data.data` + `data.total`
2. `createTag(payload)` — POST `/api/v1/tags` → 201, reads from `data`
3. `createTag` with `products` — sends `products: [id1, id2]`
4. `getTag(id)` — GET `/api/v1/tags/{id}` → returns `data` including `products`
5. `updateTag(id, payload)` — PUT `/api/v1/tags/{id}` → 200, reads from `data`
6. `updateTag` with `products: []` — clears associations (sends empty array)
7. `updateTag` without `products` — does not send the key
8. `deleteTag(id)` — DELETE `/api/v1/tags/{id}` → `data: true`
9. `createTag` with invalid product id → 422, surfaces `errors.products.*`
10. All tag API calls handle 401 → redirect to login
11. All tag API calls handle 403 → permission denied toast

### Tag form behavior
12. Create form initializes empty; Edit form pre-fills from `data`
13. Product multi-select pre-selects `data.products` on edit
14. `products: []` saved when user clears selection
15. `products` omitted when field untouched
16. Translatable `name` serializes as `{ en, ar }` on save
17. Language tabs display the correct translation on load
18. 422 validation errors map to per-field inline messages
19. Delete confirmation modal closes and triggers delete on confirm
20. Delete success removes row and shows success toast
