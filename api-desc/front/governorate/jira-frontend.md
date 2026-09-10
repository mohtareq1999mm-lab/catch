# Governorate Module — Frontend Jira Tasks

---

## Task 1: Governorate Dropdown — Checkout Delivery Form

**Priority:** High
**Component:** Frontend — Checkout
**Story Points:** 3

**Description:** Build a governorate dropdown selector on the checkout delivery address form.

**API Endpoint:**
- `GET /api/v1/general/governorates`

**Acceptance Criteria:**
- [ ] Dropdown populated with {value: id, label: name} on page load
- [ ] Selected governorate_id sent in checkout POST body
- [ ] **Loading state:** Disabled dropdown with placeholder text
- [ ] **Empty state:** "No governorates available" message, hide delivery option or suggest contact support
- [ ] **Error state:** "Could not load governorates. Please try again." with retry button
