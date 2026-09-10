# Jira - Banner Feature

## Epic: Banner Management

### Story Points Estimate: 8

## User Stories

### US-001: CRUD Banners
**As** an admin
**I want** to create, read, update, and delete banners with translatable content and images
**So that** I can manage promotional content for the home page

**Acceptance Criteria:**
- Desktop + mobile image upload per banner
- Translatable title and description (EN/AR)
- Associate products with banner
- Soft delete with safe recovery

### US-002: Toggle Banner Status
**As** an admin
**I want** to toggle banner active/inactive status
**So that** I can control which banners are displayed

### US-003: Reorder Banners
**As** an admin
**I want** to drag-and-drop reorder banners
**So that** I can control display sequence

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | Duplicate `banners` apiResource route registration (lines 217 + 259) | Medium | Medium |
| BUG-002 | Duplicate pagination keys `page`/`current_page` in list response | Low | Low |
