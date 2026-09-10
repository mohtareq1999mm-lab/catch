# Product Module — Frontend JIRA Tasks

## F-001: Product List Page (Table View)

**Priority:** High
**Story Points:** 5
**Labels:** frontend, product

**Description:** Create a product list page with a data table showing:
- Columns: ID, Name (translated), SKU, Type, Price, Stock, Status, Actions
- Server-side pagination (GET /products?limit=15&page=N)
- Search bar (GET /products?search=term)
- Sorting by name, price, created_at, sold_quantity
- Filters: category, banner, promotion, flash_sale, slider, status
- Row actions: Edit, Delete, View

---

## F-002: Product Create/Edit Form

**Priority:** High
**Story Points:** 13
**Labels:** frontend, product

**Description:** Create product form supporting:
- **Basic Info:** Name (en/ar), Description (en/ar), SKU (auto-generated), Product Type (simple/variable)
- **Pricing:** Price, Discount toggle (type, amount, dates), Flash Sale selector
- **Inventory:** Stock quantity, In Stock toggle
- **Categories:** Multi-select (required)
- **Brands:** Multi-select
- **Tags:** Multi-select (optional)
- **Images:** Upload with preview (required, multiple, jpeg/png/jpg, max 2MB each)
- **Dimensions:** Height, Width, Length, Weight (optional)
- **Variants (if variable):** Dynamic variant rows with price, quantity, SKU, attribute values
- **Status:** Dropdown (publish, draft, under_review)
- **Validation:** Show field-level 422 errors

---

## F-003: Product Detail Page

**Priority:** Medium
**Story Points:** 3
**Labels:** frontend, product

**Description:** Product detail page showing all product information:
- Gallery (all images)
- Name, description, pricing breakdown
- Variant selection table
- Categories, brands, tags
- Reviews section
- Related products

---

## F-004: Delete Product Dialog

**Priority:** Medium
**Story Points:** 1
**Labels:** frontend, product

**Description:** Confirmation dialog before deleting a product.
- "Are you sure you want to delete [name]?"
- Warning about soft delete (can be restored in future)
- Delete button → DELETE /products/{id}
- Success toast

---

## F-005: Loading / Empty / Error States

**Priority:** Medium
**Story Points:** 2
**Labels:** frontend, product

**Description:** Handle all async states:
- **Loading:** Skeleton table/cards while products load
- **Empty:** Empty state illustration + "Create First Product" button
- **Error:** Error state with retry button
- **404:** Product not found page for invalid IDs

---

## F-006: Product Search & Filter UI

**Priority:** Medium
**Story Points:** 3
**Labels:** frontend, product

**Description:** Search bar with debounced input, filter dropdowns (category, banner, promotion, flash_sale, slider, status), clear filters button. URL query param sync for shareable filtered URLs.

---

## F-007: Response Translation Handling

**Priority:** Medium
**Story Points:** 2
**Labels:** frontend, i18n

**Description:** Handle translatable fields (name, description) returned as JSON `{ en, ar }`. Display correct locale based on current app language. Send both locales on create; allow single-locale updates.

---

## F-008: Product Bulk Delete & Destroy All

**Priority:** Low
**Story Points:** 2
**Labels:** frontend, product

**Description:** Add bulk actions to the product list table:
- Checkbox selection per row
- "Delete Selected" button → `POST /products/bulk-delete` with `{ ids: [...] }`
- Confirmation dialog showing count of selected products
- "Delete All" button (admin only) → `DELETE /products/all`
- Success toast with count of deleted products
- Table refreshes after deletion

---

## F-009: Product Import UI

**Priority:** Medium
**Story Points:** 5
**Labels:** frontend, product, import

**Description:** Product import from spreadsheet:
- Upload page with drag-and-drop file upload (.xlsx, .csv)
- Upload button → `POST /products/import` with file
- Polling progress: `GET /products/import/{id}` every 3 seconds
- Progress bar showing processed/success/failed rows and percentage
- Cancel button → `POST /products/import/{id}/cancel`
- Download errors button → `GET /products/import/{id}/download-errors`
- States: uploading, importing, completed, failed, cancelled
- Sample file download link
- Error states: file too large, wrong format, server error

---

## F-010: Reviews Management

**Priority:** Low
**Story Points:** 3
**Labels:** frontend, reviews

**Description:** Review management on product detail page:
- List reviews for a product → `GET /reviews?product_id=X`
- Display: rating stars, comment, user name, date, approval status
- Toggle approve/unapprove → `PATCH /reviews/{id}/toggle-approve`
- Delete review → `DELETE /reviews/{id}` with confirmation
- Loading/empty/error states for review section
- Review count and average rating summary
