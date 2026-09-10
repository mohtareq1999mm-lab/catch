# Jira - Activity Log Feature (Frontend)

## Epic: Frontend Audit Log UI

### Story Points Estimate: 5

---

## User Stories

### FE-US-001: Activity Log List Page (Admin)
**As** an admin user
**I want** to view the activity log in a clean, filterable table
**So that** I can audit changes made across the system

**Acceptance Criteria:**
- Fetches `GET /api/v1/logs/activity` on mount
- Displays table with columns: Timestamp, User, Action, Entity, Description
- Filter row: log_name dropdown, event dropdown, search input
- Sortable by timestamp
- Pagination
- Loading skeleton
- Empty state ("No activity logs found")
- Error state with retry

---

### FE-US-002: Activity Log Detail (Optional)
**As** an admin user
**I want** to expand a log entry to see full properties
**So that** I can see what data changed

**Acceptance Criteria:**
- Click row → expand or modal with full JSON properties
- Shows causer info (name, email)
- Shows subject type and ID
- Shows all property values

---

## Frontend Tasks

| Task ID | Description | Estimate (h) | Component |
|---------|-------------|-------------|-----------|
| FE-T-001 | Create ActivityLogPage | 5 | `ActivityLogPage.vue` |
| FE-T-002 | Create ActivityLogTable | 3 | `ActivityLogTable.vue` |
| FE-T-003 | Create ActivityLogFilters | 3 | `ActivityLogFilters.vue` |
| FE-T-004 | Create ActivityLogDetail (expand) | 2 | `ActivityLogDetail.vue` |
| FE-T-005 | Create API service layer | 1 | `services/activityLogApi.js` |

## API Routes for Frontend Integration

| Method | Endpoint | Auth | Usage |
|--------|----------|------|-------|
| GET | `/api/v1/logs/activity` | Sanctum + `view-activity-log` | List + filters |
