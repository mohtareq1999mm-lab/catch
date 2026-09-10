# Documentation Changelog

## 2026-09-11 - Phase 2 Cleanup

### Added
- `docs/coupons/CONFIGURATION-GUIDE.md` - Complete guide for public vs assigned coupons
- `docs/order-tracking/CUSTOMER-API.md` - Customer tracking endpoints (Phase 1)
- `docs/order-tracking/ADMIN-DASHBOARD.md` - Admin dashboard API (Phase 1)
- `order_status_history` table and model for immutable order audit trail
- Admin coupon configuration validator endpoints (`validate-configuration`, `usage-info`, `suggest-fix`)
- Customer verification messaging for pending online payments (`verification_message`, `verification_status`)
- Admin stuck payment alerts (`payment_verification_stuck` + `alerts`)
- Structured logging (`OrderTrackingLogger`) and metrics (`OrderTrackingMetrics`)

### Changed
- **Coupon terminology:** `max_claims_per_user` → `max_claims` (reflects total capacity, not per-user) — migration `2026_09_10_000004`
- **Payment reconciliation:** Updated from hourly to every 15 minutes with `onOneServer()` and `withoutOverlapping()` (`app/Console/Kernel.php`)
- **Coupon assignments:** `coupon_assignment_usages.order_id` now `NOT NULL` with model validation + CHECK constraint (`2026_09_11_000002/000003`)
- **Queue system:** Removed all RabbitMQ references (replaced with Laravel queue + `ShouldDispatchAfterCommit` events)

### Removed
- Cart reservation pattern (inventory is now order-owned only — `OrderReservationService`)
- Webhook payment verification (using redirect + `GetPaymentStatus` only)
- Legacy tax calculation examples (updated to match `TaxCalculator` integer cents implementation)

### Fixed
- API response examples now match current `OrderResource` and `OrderTrackingController` shape
- State machine diagrams updated with `order_status_history`
- Pricing formulas corrected (shipping never taxable, order tax on same base as product tax)
- `Order` order_number generation idempotency for SQLite testing

---

## 2026-09-09 - Final Audit

- 3-pass deep audit (architecture + adversarial + production closure)
- Order-owned inventory state machine verified (`none → active → committed/released/restored`)
- Promotion gifts as descriptors, `ShouldDispatchAfterCommit` on all order/payment events
- Unique pending order constraint (`idx_orders_user_pending_unique`)
- Phase 1 tracking dashboard delivered (migration + controllers + tests)

## 2026-09-08 - Tax & Currency Hardening

- `TaxCalculator` integer cents, largest-remainder allocation, shipping never taxable
- Currency snapshot at `OrderCreationService` with `BCMath` handled via cents
