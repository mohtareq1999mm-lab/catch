# Jira - Notification Feature (Frontend)

## Epic: Admin Notification Center UI

### Story Points Estimate: 5

---

## User Stories

### FE-US-001: Notification Bell with Dropdown
**As** an admin
**I want** a bell icon showing unread count with a dropdown of recent unread notifications
**So that** I can quickly see new activity

**Acceptance Criteria:**
- Bell icon with unread badge count from `/admin/notifications/unread`
- Dropdown shows last 5 unread with title, message, time
- Click notification navigates to action_url
- Mark as read on click
- Polling every 30 seconds (or WebSocket)

### FE-US-002: Notification Center Page
**As** an admin
**I want** a full notification page with pagination
**So that** I can review all my notification history

**Acceptance Criteria:**
- Paginated list from `/admin/notifications`
- Each item shows icon, title, message, time, read/unread state
- Mark as read button per item
- Mark all as read button
- Delete individual or all

---

## Frontend Tasks

| ID | Description | h | Component |
|----|-------------|---|-----------|
| FE-T-001 | Create NotificationBell + dropdown | 4 | `NotificationBell.vue` |
| FE-T-002 | Create NotificationCenter page | 4 | `NotificationCenter.vue` |
| FE-T-003 | Create API service | 1 | `services/notificationApi.js` |

## API Routes

| Method | Endpoint | Permission | Usage |
|--------|----------|-----------|-------|
| GET | `/api/v1/admin/notifications` | view-notifications | Full list |
| GET | `/api/v1/admin/notifications/unread` | view-notifications | Bell badge + dropdown |
| PATCH | `/api/v1/admin/notifications/{id}/read` | manage-notifications | Mark single as read |
| PATCH | `/api/v1/admin/notifications/read-all` | manage-notifications | Mark all as read |
| DELETE | `/api/v1/admin/notifications/{id}` | manage-notifications | Delete single |
| DELETE | `/api/v1/admin/notifications` | manage-notifications | Delete all |
