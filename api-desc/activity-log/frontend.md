# Frontend - Activity Log Feature

## Status

**No dedicated frontend Vue/React components** found in `resources/js/`. The frontend is a separate SPA.

## Consumption Pattern

### Admin Audit Log Page

```
GET /api/v1/logs/activity?log_name=products&event=created&search=Product&per_page=20

Response: Paginated activity log entries with filters
```

## What a Frontend Implementation Would Need

```
AdminActivityLogPage.vue
  Fetches: GET /api/v1/logs/activity
  Features:
    - Table: timestamp, user, action, entity type, description
    - Filters: log_name dropdown, event dropdown, date range, search
    - Pagination
    - Loading skeleton / empty state / error state

ActivityLogFilters.vue
  Dropdowns: log_name, event
  Date range picker
  Search input

ActivityLogTable.vue
  Columns: Timestamp, User, Action, Entity, Description
  Sortable by timestamp
```

### API Service Layer

```javascript
export const activityLogApi = {
  list(params)     // GET /api/v1/logs/activity
}
```
