# Fast Shipping — Jira Tasks (Backend)

## Done

| Task | Description | Status |
|------|-------------|--------|
| FS-001 | Create FastShippingRepository with settings CRUD + caching | DONE |
| FS-002 | Create FastCheckoutRequest validation | DONE |
| FS-003 | Create FastShippingService with createFastOrder logic | DONE |
| FS-004 | Create public FastShippingController (status, products, checkout, orders) | DONE |
| FS-005 | Create Admin FastShippingController (getSettings, updateSettings) | DONE |
| FS-006 | Add fast-shipping routes to Routes.php and api.php | DONE |
| FS-007 | Add fast-shipping permissions to PermissionSeeder | DONE |
| FS-008 | Add translation keys (en, ar, de) for checkout errors | DONE |
| FS-009 | Create FastShippingScope for channel-based product filtering | DONE |
| FS-010 | Create ChannelContext + ChannelMiddleware + Channel enum | DONE |
| FS-011 | Create config/channel.php | DONE |
| FS-012 | Add `is_fast_shipping_available` to Product model + migration | DONE |
| FS-013 | Add `is_fast_shipping_enabled` to Governorate model + migration | DONE |
| FS-014 | Add `fast_shipping_fee` + `eta` to Order model | DONE |
| FS-015 | Create Feature tests (FastShippingControllerTest) | DONE |
| FS-016 | Create Harden tests (FastShippingHardenTest) | DONE |
| FS-017 | Create Unit tests (FastShippingRepositoryTest, FastShippingScopeTest) | DONE |
| FS-018 | Add toggle endpoints for Product and Governorate fast shipping | DONE |
| FS-019 | Integrate with CouponValidator during checkout | DONE |
| FS-020 | Integrate with PromotionService during checkout | DONE |
| FS-021 | Integrate with PaymentCheckoutHandler (online, cod, cashier) | DONE |
| FS-022 | Add `fast_shipping_page_publish` to Settings model | DONE |

## Open/Backlog

| Task | Description | Priority |
|------|-------------|----------|
| FS-023 | Add rate limiting to checkout endpoint | Medium |
| FS-024 | Add admin notification when fast shipping settings change | Low |
| FS-025 | Add webhook for fast order status updates | Low |
| FS-026 | Add export for fast shipping orders | Low |
