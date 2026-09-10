# Address Module — Frontend JIRA Tasks

## F-001: Address List Page

**Priority:** High
**Story Points:** 3
**Labels:** frontend, address

**API:** `GET /api/v1/address`

**Features:**
- Display all user addresses as cards or list items
- Each card shows: title, type badge (billing/shipping), default badge, full address (street, city, state, zip, country), location coordinates (if available)
- Edit button → navigates to AddressForm in edit mode
- Delete button → opens confirmation dialog
- "Add Address" button → navigates to AddressForm in create mode
- Empty state: "No addresses saved yet" with "Add Address" CTA
- Loading skeleton while fetching
- Error state with retry

---

## F-002: Address Create/Edit Form

**Priority:** High
**Story Points:** 5
**Labels:** frontend, address

**APIs:** `POST /api/v1/address` | `PUT /api/v1/address/{id}`

**Features:**
- Form fields:
  - Title (text input, required) — e.g., "Home", "Work"
  - Type (dropdown, required) — "billing" / "shipping"
  - Default (checkbox/toggle)
  - Street Address (text input, required)
  - City (text input, required)
  - State (text input, required)
  - ZIP/Postal Code (text input, required)
  - Country (text input or dropdown, required)
  - Latitude (number input, optional)
  - Longitude (number input, optional)
- Create mode: empty form, submit → `POST /api/v1/address`
- Edit mode: pre-populated from existing address, submit → `PUT /api/v1/address/{id}`
- Loading state while fetching existing address (edit mode)
- Field-level validation errors from 422 responses
- Success toast on save
- Cancel button → navigate back to list
- Disable submit while processing

---

## F-003: Delete Address Confirmation

**Priority:** Medium
**Story Points:** 1
**Labels:** frontend, address

**API:** `DELETE /api/v1/address/{id}`

**Features:**
- Confirmation dialog: "Delete [title] address?"
- Warning: "This action cannot be undone."
- Delete button (loading state, disabled while processing)
- Cancel button
- Success toast on deletion
- Navigate back to list after delete
- Error toast on failure

---

## F-004: Loading / Empty / Error States

**Priority:** Medium
**Story Points:** 1
**Labels:** frontend, address

**Features:**
- **Loading:** Skeleton cards with shimmer animation
- **Empty:** Illustration + "No addresses saved yet" + "Add Address" CTA
- **Error:** Error illustration + "Something went wrong" + "Try Again" button
- **Save error:** Toast notification with error message, form data preserved

---

## F-005: API Service Layer

**Priority:** High
**Story Points:** 1
**Labels:** frontend, infrastructure

```javascript
// services/addressApi.js
export const addressApi = {
  list()                       // GET /api/v1/address
  show(id)                     // GET /api/v1/address/{id}
  create(data)                 // POST /api/v1/address
  update(id, data)             // PUT /api/v1/address/{id}
  delete(id)                   // DELETE /api/v1/address/{id}
}
```

---

## Epic Summary

| Task | Points | Priority |
|------|--------|----------|
| F-001: Address List Page | 3 | High |
| F-002: Address Create/Edit Form | 5 | High |
| F-003: Delete Address Confirmation | 1 | Medium |
| F-004: Loading / Empty / Error States | 1 | Medium |
| F-005: API Service Layer | 1 | High |
| **Total** | **11** | |
