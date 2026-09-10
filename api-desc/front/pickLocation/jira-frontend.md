# Pickup Location Module — Frontend Jira Tasks

---

## Task 1: Pickup Location Selector — Checkout Page

**Priority:** High
**Component:** Frontend — Checkout
**Story Points:** 5

**Description:** Build a pickup location selector dropdown/list on the checkout page.

**API Endpoints:**
- `GET /api/v1/general/pickup-locations`
- `GET /api/v1/general/pickup-locations/{id}`

**Acceptance Criteria:**
- [ ] Dropdown or radio list of locations showing store_name and address
- [ ] When selected, show full details (working hours, phone, email)
- [ ] Map preview with pin at selected location's lat/lng
- [ ] "Get Directions" button linking to maps app
- [ ] **Loading state:** Skeleton dropdown
- [ ] **Empty state:** "No pickup locations available, standard shipping will be used"
- [ ] **Error state:** Hide pickup option, default to shipping

---

## Task 2: Pickup Location Details Page

**Priority:** Medium
**Component:** Frontend — Store Info
**Story Points:** 3

**Description:** Optional page showing a specific pickup location's full details.

**API Endpoint:**
- `GET /api/v1/general/pickup-locations/{id}`

**Acceptance Criteria:**
- [ ] Full location details: name, address, phone, email
- [ ] Working hours table rendered from JSON object
- [ ] Map with marker at lat/lng
- [ ] "Get Directions" button
- [ ] **Loading state:** Skeleton details card
- [ ] **Error state (not found):** "Location not available" with back button
