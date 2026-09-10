# Changelog - Notification Feature

## [Unreleased]

### Added
- 6 admin notification endpoints under `/api/v1/admin/notifications`
- Paginated list with user-scoping
- Unread-only endpoint
- Mark as read (single + all)
- Delete (single + all)
- `auth:sanctum` + Spatie permissions middleware
- 3 event-driven notification types (OrderCreated, ContactMessageReceived, AdminLoggedIn)
- Custom `formatNotification()` extracting typed fields from data JSON
- 38 test methods covering auth, permissions, CRUD, events, and JSON structure

### Fixed
- Removed non-existent `admin` middleware from `NotificationController` (caused "Target class [admin] does not exist" on all endpoints)
- Registered `SendAdminLoginNotification` listener in `EventServiceProvider` (was missing, `AdminLoggedIn` event never created notifications)
- Added 6 missing translation keys to `resources/lang/en/message.php`

### Known Issues
- No real-time delivery (polling via frontend)
