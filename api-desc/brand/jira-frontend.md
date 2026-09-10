# Brand Module — Frontend Jira Tasks

## Task 1: Admin Brand Listing Page — CRUD table with reorder

**Priority:** High
**Component:** Frontend — Admin Brands Page
**Story Points:** 8

**Description:** Build the admin brand management page with a data table listing all brands. The page must support listing, searching, filtering, sorting, and inline order management.

**API Endpoints:**
- `GET /api/v1/brands?page=&per_page=&search=&active=&inactive=&order=&sortedBy=`
- `PUT /api/v1/brands/reorder`

**Acceptance Criteria:**
- [ ] Table renders all brands with columns: order, image (thumbnail), name, slug, status badge, actions
- [ ] Pagination controls (page size selector, prev/next, page numbers)
- [ ] Search field filters by name
- [ ] Filter toggles for active/inactive status
- [ ] Sortable columns (click header to sort)
- [ ] Drag-and-drop reorder rows (sends `PUT /api/v1/brands/reorder` with new ID order)
- [ ] Each row shows edit/delete action buttons
- [ ] Loading skeleton while fetching
- [ ] Empty state: "No brands found" with "Create Brand" CTA

---

## Task 2: Admin Brand Create/Edit Form

**Priority:** High
**Component:** Frontend — Admin Brand Form
**Story Points:** 5

**Description:** Build the create/edit form for brands with translatable fields, image uploads, and product association.

**API Endpoints:**
- `POST /api/v1/brands` (create)
- `PUT /api/v1/brands/{id}` (update)
- `GET /api/v1/brands/{id}` (load existing data for edit)

**Acceptance Criteria:**
- [ ] Create mode: empty form with all fields
- [ ] Edit mode: form pre-filled from `GET /api/v1/brands/{id}`
- [ ] Translatable fields: `name` and `details` with language tabs (en, ar)
- [ ] Image uploads: `image-desktop` and `image-mobile` with preview
- [ ] Product multi-select: searchable dropdown to associate products
- [ ] Status toggle: active/inactive switch
- [ ] Form submits as `multipart/form-data`
- [ ] Validation errors displayed per field (422 response)
- [ ] Success redirect to brand listing with toast
- [ ] Cancel button returns to listing

---

## Task 3: Admin Brand — Image Upload with Preview

**Priority:** Medium
**Component:** Frontend — Image Upload
**Story Points:** 3

**Description:** Implement dual image upload for desktop and mobile brand images with preview, validation, and fallback.

**Acceptance Criteria:**
- [ ] Show current image preview loaded from API URLs (edit mode)
- [ ] File picker accepts only: `jpeg`, `png`, `jpg`, `gif`, `svg`
- [ ] File size limited to 2MB (client-side validation before upload)
- [ ] Show preview immediately after selecting a file
- [ ] Show placeholder/fallback icon if no image URL exists
- [ ] Desktop and mobile uploads are separate fields
- [ ] "Remove image" button to clear selection
- [ ] Disable upload button during form submission

---

## Task 4: Admin Brand — Product Association Multi-Select

**Priority:** Medium
**Component:** Frontend — Product Selector
**Story Points:** 3

**Description:** Build a searchable multi-select component for associating products with a brand.

**API Endpoints:**
- `GET /api/v1/products?search=&limit=` (to fetch products for selection)

**Acceptance Criteria:**
- [ ] Search input filters products by name
- [ ] Selected products shown as tags/chips with remove button
- [ ] On create/update, sends `products[]` array of IDs
- [ ] Product list loads with pagination or debounced search
- [ ] Show product name and SKU in dropdown
- [ ] On edit mode, pre-select already-associated products
- [ ] Empty state: "No products found" when search yields no results

---

## Task 5: Admin Brand — Drag-and-Drop Reorder

**Priority:** Medium
**Component:** Frontend — Reorder
**Story Points:** 5

**Description:** Implement drag-and-drop reordering on the brand listing table.

**API Endpoint:**
- `PUT /api/v1/brands/reorder` with body `{ "brands": [id1, id2, id3, ...] }`

**Acceptance Criteria:**
- [ ] Drag handle icon on each row
- [ ] Visual feedback during drag (elevated row, drop indicator)
- [ ] On drop, immediately send updated order to API
- [ ] Optimistic UI update (reorder locally before API response)
- [ ] Rollback on API error with error toast
- [ ] Disable reorder during API call (prevent rapid reorder requests)
- [ ] Debounce or throttle rapid reorder actions
- [ ] Loading state on the reordered rows during API call
- [ ] Error state: show error message and revert to previous order

---

## Task 6: Public Brand Section — Homepage Brand Logos

**Priority:** High
**Component:** Frontend — Public Homepage
**Story Points:** 3

**Description:** Display brand logos in a scrollable/horizontal section on the homepage or brand listing page.

**API Endpoint:**
- `GET /api/v1/general/brands?limit=10`

**Acceptance Criteria:**
- [ ] Fetch brands on mount via public API
- [ ] Display brand logos in a grid or horizontal scroll
- [ ] Use `image.desktop` for desktop viewport, `image.mobile` for mobile
- [ ] Each brand logo links to brand detail page (`/brands/{slug}`)
- [ ] Show brand name as alt text and fallback if image fails to load
- [ ] **Loading state:** Show skeleton placeholders (3-4 brand logo shapes)
- [ ] **Empty state:** Hide section entirely if no brands returned (empty array)
- [ ] **Error state:** Hide brand section with console warning (non-critical content)
- [ ] Responsive: adjust grid columns based on viewport width

---

## Task 7: Public Brand Detail Page — Brand with Products

**Priority:** High
**Component:** Frontend — Public Brand Page
**Story Points:** 5

**Description:** Build the public brand detail page showing brand information and associated products.

**API Endpoint:**
- `GET /api/v1/general/brands/{slug}`

**Acceptance Criteria:**
- [ ] Page loads brand info (name, details, images)
- [ ] Display desktop/mobile images with responsive handling
- [ ] Show brand description/details text
- [ ] Display product grid showing associated products
- [ ] Product cards show: image, name, price, rating, discount badge
- [ ] Products link to product detail pages
- [ ] **Loading state:** Full page skeleton with brand info and product grid placeholders
- [ ] **Empty state (no products):** Show brand info with "No products available for this brand" message
- [ ] **Error state (404):** Show "Brand not found" page with link back to brands listing

---

## Task 8: Admin Brand — Delete Confirmation Dialog

**Priority:** Medium
**Component:** Frontend — Delete Modal
**Story Points:** 2

**Description:** Implement a confirmation dialog before deleting a brand.

**API Endpoint:**
- `DELETE /api/v1/brands/{id}`

**Acceptance Criteria:**
- [ ] Clicking delete opens a confirmation modal
- [ ] Modal shows brand name and warning text
- [ ] "Confirm" button submits DELETE request
- [ ] "Cancel" closes modal
- [ ] Loading spinner on confirm button during deletion
- [ ] On success: remove row from table with success toast
- [ ] On error: show error toast, keep modal open
- [ ] Disable confirm button during API call to prevent double-submit

---

## Task 9: Admin Brand — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 3

**Description:** Handle all non-happy-path states across the brand admin pages.

**Acceptance Criteria:**
- [ ] **Listing loading:** Skeleton table rows (5 rows)
- [ ] **Listing empty:** Illustration with "No brands yet" and "Create your first brand" button
- [ ] **Listing error:** Error message with "Retry" button
- [ ] **Form loading (edit):** Form skeleton while fetching brand data
- [ ] **Form error:** Toast with error message
- [ ] **Form validation:** Inline field errors from API 422 response
- [ ] **Reorder error:** Toast "Failed to reorder" with automatic revert
- [ ] **Delete error:** Toast "Failed to delete brand"
- [ ] **Network error:** Toast "Network error, please try again" for all API calls

---

## Task 10: Admin Brand — Multilingual Translatable Fields

**Priority:** Medium
**Component:** Frontend — i18n
**Story Points:** 3

**Description:** Handle translatable fields (`name`, `details`) that are sent/received as language-keyed objects.

**Request/Response format:**
```json
{
  "name": { "en": "Apple", "ar": "أبل" },
  "details": { "en": "Description", "ar": "الوصف" }
}
```

**Acceptance Criteria:**
- [ ] Read supported languages from app config
- [ ] Show language tabs for each supported locale
- [ ] Each translatable field shows separate input per language tab
- [ ] On save, fields serialized to `{ "en": "...", "ar": "..." }` format
- [ ] On load, each tab shows correct translation for its language
- [ ] Default language tab pre-selected
- [ ] Validation errors shown per-language (e.g., `name.en` error displays on English tab)
