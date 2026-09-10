# FAQ Module — Frontend Jira Tasks

## Task 1: Admin FAQ Listing Table

**Description:** Create admin table showing all FAQs with columns: ID, Title, Status, Order, Created, Actions.

**Requirements:**
- Server-side pagination (page, limit)
- Sortable columns (order, sortedBy)
- Loading skeleton state
- Empty state when no FAQs found
- Drag-and-drop reorder handles
- **Response now includes** `status` (int: 1=active, 0=inactive) and `order` (int) on every FAQ object
- Show status badge (Active/Inactive) and order number in table

---

## Task 2: Admin FAQ Create/Edit Form

**Description:** Create form for creating and editing FAQs.

**Fields:**
- `faq_title` — multilingual (en, ar) text inputs
- `faq_description` — multilingual (en, ar) textareas
- `status` — toggle switch (optional on create, defaults to Active)
- No `shop_id` field — shop scoping removed, all users see all FAQs

---

## Task 3: Drag-and-Drop Reorder

**Description:** Add drag-and-drop reordering to the FAQ admin list.

**Flow:**
1. User drags FAQ items to desired order
2. On drop, collect IDs in new sequence
3. Send PUT `/api/v1/faqs/reorder` with `{ faqs: [3, 1, 2] }`
4. Show loading state during request
5. Refresh list on success
6. Show error toast on failure

---

## Task 4: Public FAQ Accordion Page

**Description:** Create a public FAQ page with expandable accordion component.

**Data source:** `GET /api/v1/general/faqs`

**Features:**
- Accordion/collapse behavior (one open at a time or multiple)
- Smooth open/close animation
- Locale-aware content
- Mobile-responsive layout
- Each item now includes `status` and `order` in response — display order can be shown if needed

---

## Task 5: Delete Confirmation Dialog

**Description:** Add confirmation dialog before deleting a FAQ.

- Modal: "Are you sure you want to delete this FAQ?"
- Shows FAQ title
- Confirm/Cancel buttons
- Loading state on delete

---

## Task 6: Loading, Empty, Error States

**Description:** Implement consistent states across FAQ components.

- **Loading:** Skeleton loaders for table rows and accordion items
- **Empty:** "No FAQs available" empty state with illustration
- **Error:** Inline error messages for API failures with retry

---

## Task 7: Multilingual Translatable Fields

**Description:** Ensure FAQ title and description support bilingual input.

- Tab/segment toggle for language (en/ar)
- Send as JSON object `{"en": "...", "ar": "..."}`
- Display translated content based on current locale
