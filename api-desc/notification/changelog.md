# Changelog - Notification Feature (Phase 1 / 2 / 3)

## [Unreleased]

### Added
- 21 end-user notification classes (`app/Notifications/User*Notification.php`) covering:
  - Phase 1: order.created, payment.succeeded, payment.failed, order.delivered,
    order.cancelled, order.refunded, coupon.assigned, coupon.available, coupon.used
  - Phase 2: promotion.available, flash_sale.available
  - Phase 3: review.approved, review.rejected, discount.changed, price.drop,
    back.in.stock, promotion.price.drop, flash_sale.price.drop, cart.abandoned,
    promotion.ending_soon, flash_sale.ending_soon
- 21 queued listeners (`app/Listeners/SendUser*Notification.php`) on `meem-medium`
- 5 Phase 3 events (`app/Events/*`) + ProductObserver/ReviewRepository hooks
- `app/Actions/NotifyWishlistUsersOfProduct.php` for wishlist fan-out
- 3 scheduler commands: `NotifyAbandonedCarts`, `NotifyPromotionsEndingSoon`,
  `NotifyFlashSalesEndingSoon` (registered in `app/Console/Kernel.php`)
- en/ar translation keys for all notification titles/messages
- `UserNotificationPhase3Test` (17 tests, 87 assertions)

### Changed
- `notifications.type` now stores a **stable business identifier** (e.g.
  `price.drop`) instead of the PHP FQCN.
  - Added `databaseType()` to all 21 notifications aliasing `broadcastType()`.
  - No change to broadcast behavior (already used `broadcastType()`).
- Realtime delivery enabled for all 21 via `via(['database','broadcast'])` on
  `private-users.{id}`.

### Fixed
- `notifications.type` no longer couples to PHP class names (see `bug-report.md`).
- Realtime push added for end users.
- Wishlist fan-out scoped to product wishlist owners, admins excluded.

### Known Issues
- Breaking change: REST `type` value changed from FQCN to business id. SPA must
  update its type handling.
- Legacy `api-desc/notifaction` (typo) folder documents the unrelated admin
  notification feature; this `notification` folder documents the Phase 1/2/3
  end-user feature.
