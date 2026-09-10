# Jira - Notification Feature

## Epic: Admin Notification Management

### Story Points Estimate: 5

## User Stories

### US-001: View Notifications
**As** an admin
**I want** to see a paginated list of my notifications
**So that** I can stay informed about orders, contacts, and system events

### US-002: View Unread Notifications
**As** an admin
**I want** to see only unread notifications
**So that** I can quickly catch up on new activity

### US-003: Mark Notifications as Read
**As** an admin
**I want** to mark individual or all notifications as read
**So that** I can track what I've reviewed

### US-004: Delete Notifications
**As** an admin
**I want** to delete individual or all notifications
**So that** I can clean up my notification history

## Bug Tickets

| Ticket | Description | Priority | Severity | Status |
|--------|-------------|----------|----------|--------|
| BUG-001 | Missing EN translation keys for all 6 notification messages | High | Medium | FIXED |
| BUG-002 | Non-existent `admin` middleware causes 500 on all 6 endpoints | Critical | Critical | FIXED |
| BUG-003 | `SendAdminLoginNotification` listener never registered in EventServiceProvider | Medium | Medium | FIXED |

## Technical Debt

| TD-001 | No real-time notification delivery (polling only) |
