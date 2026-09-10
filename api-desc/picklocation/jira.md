# Jira - Pickup Location Feature

## Epic: Pickup Location Management

### Story Points Estimate: 8

## User Stories

### US-001: CRUD Pickup Locations (Admin)
**As** an admin
**I want** to create, read, update, and delete pickup locations
**So that** customers can collect orders at physical branches

**Acceptance Criteria:**
- List with search, active/inactive filter, pagination
- Order by display_order
- Create/edit with store_name, address, phone, email, coordinates, working_hours
- Soft delete (safe restoration)

### US-002: Public Pickup Location List (Checkout)
**As** a customer
**I want** to see available pickup locations during checkout
**So that** I can choose where to collect my order

**Acceptance Criteria:**
- Only active locations shown
- No authentication required
- Location snapshot saved on order at checkout

### US-003: Default Pickup Location (Branch)
**As** an admin
**I want** to mark one pickup location as the default branch
**So that** customers see a preselected branch and the store has a canonical default

**Acceptance Criteria:**
- Exactly one location is `is_default = true` at any time
- Setting a new default atomically clears the flag on all other locations
- Updating other fields of the default preserves `is_default`
- Deleting the default promotes the next location by lowest `id`
- `is_default` exposed on both admin and public APIs
- Public list includes `is_default` so checkout can preselect the default
- No new permission required (reuses `update-pickup-location`)

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | Pagination meta has duplicate `page`/`current_page` keys | Low | Low |
