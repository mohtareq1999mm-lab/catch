# Jira - Shipping Feature

## Epic: Shipping Zone Management

### Story Points Estimate: 21

## User Stories

### US-001: Manage Countries
**As** an admin
**I want** to CRUD countries with translatable names
**So that** I can define shipping zones

### US-002: Manage Governorates
**As** an admin
**I want** to CRUD governorates within a country with shipping pricing
**So that** I can configure regional shipping rates

### US-003: Manage Cities
**As** an admin
**I want** to CRUD cities within a governorate
**So that** I can define the lowest-level shipping zones

### US-004: Toggle Fast Shipping
**As** an admin
**I want** to enable/disable fast shipping per governorate
**So that** certain regions can offer expedited delivery

### US-005: Bulk Status Management
**As** an admin
**I want** to toggle status on multiple countries/governorates at once
**So that** I can quickly enable/disable shipping zones

## Bug Tickets

| Ticket | Description | Priority | Severity | Status |
|--------|-------------|----------|----------|--------|
| BUG-001 | Missing EN/AR translation keys for country/city messages | High | Medium | Open |
| BUG-002 | Governorate delete throws on cities but error is generic | Low | Low | Open |
| BUG-003 | No auth middleware on routes (only permission middleware in constructor) | Medium | Medium | Open |
| BUG-004 | `PUT /governorates/change-status` route conflict — caught as `{id}`, hits `update()` with string param | Critical | Critical | FIXED |

## Technical Debt

| TD-001 | No test coverage for Country/Governorate/City CRUD |
| TD-002 | Search uses raw JSON column LOWER() — not indexable |
