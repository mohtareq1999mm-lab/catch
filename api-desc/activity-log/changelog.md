# Changelog - Activity Log Feature

## [Unreleased]

### Added
- Complete audit trail system via `spatie/laravel-activitylog`
- Read endpoint: `GET /api/v1/logs/activity` with filtering (log_name, event, causer_id, search)
- Queued logging via `LogActivityJob` (medium queue)
- 9 Eloquent Observers for entity CRUD: User, Product, Category, Brand, Coupon, FlashSale, Promotion, Role, PickupLocation
- 6 Event Listeners for order/payment lifecycle: OrderCreated, OrderCancelled, OrderStatusChanged, PaymentSucceeded, PaymentFailed, UserRolesUpdated
- `ActivityLogResource` for standardized JSON response
- Translation files (EN + AR) with 90 keys covering all entity events
- Configurable retention (60 days), table name, and auth driver
- Permission: `view-activity-log`
- Seeder with 10 sample activity log entries

### Infrastructure
- Dual-path architecture: Observer (CRUD) + Event-Listener (business events)
- Polymorphic morphs for subject/causer relationships
- Protected attributes excluded from logging (password, remember_token, etc.)
- GET requests excluded to prevent recursive logging

### Tests
- 6 API endpoint tests covering auth, permissions, filters, search, empty state
- 11 event system tests covering job dispatch and DB record creation

## [Unreleased - Technical Debt]

- [ ] Fix LogActivityJob to use `withTrashed()` for soft-deleted subjects
- [ ] Add date range filters (date_from, date_to)
- [ ] Add sort customization (sort_by, sort_order)
- [ ] Add translation fallback text for all observers
- [ ] Handle causer gracefully when Auth::id() returns null
- [ ] Remove duplicate route registration
