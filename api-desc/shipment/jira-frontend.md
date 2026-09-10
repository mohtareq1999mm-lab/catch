# Shipment Module — Frontend Jira Tasks

---

## Task 1: Admin Shipment Listing Page

**Priority:** High
**Component:** Frontend — Admin Shipments Page
**Story Points:** 8

**Description:** Build the admin shipment management page with a data table listing all shipments with filtering, search, and pagination.

**API Endpoints:**
- `GET /api/v1/shipments?limit=&status=&courier=&order_id=&tracking_number=&from=&to=`

**Acceptance Criteria:**
- [ ] Table renders all shipments with columns: UUID, order ID, courier, tracking number, status badge, dates, actions
- [ ] Pagination controls (page size selector, prev/next, page numbers)
- [ ] Filter dropdowns: status (multi-select), courier
- [ ] Search field filters by tracking number
- [ ] Date range picker for from/to filtering
- [ ] Status badges with color coding (see QA document)
- [ ] Click row → navigate to shipment detail
- [ ] **Loading state:** Skeleton table rows (5 rows) while fetching
- [ ] **Empty state:** "No shipments found" with illustration
- [ ] **Error state:** Error message with "Retry" button

---

## Task 2: Admin Shipment Detail Page

**Priority:** High
**Component:** Frontend — Admin Shipment Detail
**Story Points:** 5

**Description:** Build the shipment detail page showing all shipment information and status timeline.

**API Endpoints:**
- `GET /api/v1/shipments/{id}` (or by UUID)

**Acceptance Criteria:**
- [ ] Display all shipment fields: UUID, order reference, courier, tracking number
- [ ] Address display: origin and destination with formatted address
- [ ] Items list: product IDs, quantities
- [ ] Weight and dimensions display
- [ ] Cost display with currency
- [ ] Status timeline/history showing past transitions
- [ ] Estimated delivery date countdown
- [ ] Notes section
- [ ] **Loading state:** Full page skeleton
- [ ] **Error state (404):** "Shipment not found" with link to listing

---

## Task 3: Admin Shipment Status Transition UI

**Priority:** High
**Component:** Frontend — Status Update
**Story Points:** 5

**Description:** Build a status transition UI that shows available next states based on current status and allows the user to transition.

**API Endpoint:**
- `PUT /api/v1/shipments/{id}/status`

**Acceptance Criteria:**
- [ ] Show current status with color badge
- [ ] Display available next transitions as clickable buttons
- [ ] Button shows target status name
- [ ] Optional notes textarea on status change
- [ ] Confirmation dialog before transition
- [ ] Optimistic UI update (status changes before API response)
- [ ] Rollback on error with error toast
- [ ] Disable buttons during API call
- [ ] Terminal states (delivered, returned, cancelled) show no transition buttons
- [ ] **Loading state:** Spinner on the clicked transition button
- [ ] **Error state (422):** Show error message from API (invalid transition)

---

## Task 4: Admin Shipment Create Form

**Priority:** High
**Component:** Frontend — Shipment Create
**Story Points:** 5

**Description:** Build a create shipment form with all required/optional fields.

**API Endpoint:**
- `POST /api/v1/shipments`

**Acceptance Criteria:**
- [ ] Order ID selector (searchable dropdown fetching from orders API)
- [ ] Courier text input
- [ ] Shipping method dropdown (standard, express, etc.)
- [ ] Shipping cost and currency fields
- [ ] Origin and destination address forms (flexible JSON)
- [ ] Items section (product ID + quantity pairs)
- [ ] Weight and weight unit inputs
- [ ] Estimated delivery date picker
- [ ] Notes textarea
- [ ] All fields optional except order_id
- [ ] Validation errors displayed per field (422 response)
- [ ] Success → navigate to shipment detail with success toast
- [ ] **Loading state:** Submit button spinner during creation
- [ ] **Error state:** Show API error messages per field

---

## Task 5: Admin Shipment Edit Form

**Priority:** Medium
**Component:** Frontend — Shipment Edit
**Story Points:** 3

**Description:** Build an edit form for updating shipment details (not status).

**API Endpoint:**
- `PUT /api/v1/shipments/{id}`

**Acceptance Criteria:**
- [ ] Pre-populated form with current shipment data
- [ ] Same fields as create form (all optional on update)
- [ ] Tracking number uniqueness validated
- [ ] Validation errors displayed per field
- [ ] Success toast + return to detail page
- [ ] Cancel button returns to detail page

---

## Task 6: Public Shipment Tracking Page

**Priority:** Medium
**Component:** Frontend — Public Tracking
**Story Points:** 5

**Description:** Build a public shipment tracking page where customers can enter a UUID or tracking number to see their shipment status.

**API Endpoints:**
- `GET /api/v1/shipments/uuid/{uuid}`

**Acceptance Criteria:**
- [ ] Input field for UUID or tracking number
- [ ] "Track" button submits lookup
- [ ] Display shipment info: courier, tracking number, current status
- [ ] Status timeline/progress bar showing journey
- [ ] Estimated delivery date
- [ ] Origin and destination
- [ ] **Loading state:** Spinner on lookup button
- [ ] **Empty/initial state:** Search form with instructions
- [ ] **Error state (404):** "Shipment not found" with "Try again" prompt
- [ ] **Error state (401):** Redirect to login (public tracking may need auth reconsideration)

---

## Task 7: Status Badge Component

**Priority:** Medium
**Component:** Frontend — Shared Component
**Story Points:** 2

**Description:** Create a reusable status badge component for shipment statuses with color coding.

**Acceptance Criteria:**
- [ ] Component accepts status string and optional size prop
- [ ] Color mapping per status:
  - pending → gray
  - label_created → blue
  - picked_up → indigo
  - in_transit → cyan
  - out_for_delivery → purple
  - delivered → green
  - failed_delivery → red
  - returned → orange
  - delayed → yellow
  - cancelled → dark gray
- [ ] Status label formatted (e.g., "label_created" → "Label Created")
- [ ] Optional tooltip with description
- [ ] Reusable across listing and detail pages

---

## Task 8: Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 3

**Description:** Handle all non-happy-path states across the shipment pages.

**Acceptance Criteria:**
- [ ] **Listing loading:** Skeleton table rows (5 rows)
- [ ] **Listing empty:** Illustration with "No shipments yet" message
- [ ] **Listing error:** Error message with "Retry" button
- [ ] **Detail loading:** Full page skeleton
- [ ] **Detail error (404):** "Shipment not found" page
- [ ] **Create loading:** Submit button spinner
- [ ] **Create validation:** Inline field errors from API 422
- [ ] **Status update loading:** Button spinner on clicked transition
- [ ] **Status update error (422):** Display API error message
- [ ] **Network error:** Toast "Network error, please try again" for all API calls
