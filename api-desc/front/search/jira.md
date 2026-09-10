# Jira - Search Feature

## Epic: Global Search

### Story Points Estimate: 5

## User Stories

### US-001: Global Search
**As** a customer
**I want** to search across products, categories, brands, and pages
**So that** I can quickly find what I'm looking for

**Acceptance Criteria:**
- `GET /api/v1/general/search` with query parameter
- Results grouped by type (products, categories, brands, pages)
- Rate limited to 30 requests/min per IP
- Returns paginated results

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | Route not registered — 404 on search endpoint | Blocker | Critical |
| BUG-002 | SearchService returns empty array (stub) | Blocker | Critical |
| BUG-003 | Existing test will fail (expects 200, gets 404) | High | High |
| BUG-004 | Rate limiter defined but never applied | Medium | Medium |
