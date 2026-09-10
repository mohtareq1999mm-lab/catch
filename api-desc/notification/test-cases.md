# Test Cases - Notification Feature (Phase 1 / 2 / 3)

## Current Coverage

**File:** `tests/Feature/UserNotificationPhase3Test.php` (17 tests, 87 assertions)

| Category | Tests | Coverage |
|----------|-------|----------|
| Review approved/rejected notifies reviewer | 2 | `review.approved`, `review.rejected` |
| Admin excluded from review notify | 1 | no admin recipient |
| Discount changed → wishlist fan-out | 2 | `discount.changed` + non-wishlist excluded |
| Price drop → wishlist fan-out | 2 | `price.drop` + price increase excluded |
| Admin excluded from price-drop fan-out | 1 | no admin recipient |
| Promotion/FlashSale activated → price drop | 2 | `promotion.price.drop`, `flash_sale.price.drop` |
| Back in stock | 2 | `back.in.stock` + reservation release not triggered |
| Abandoned cart | 2 | `cart.abandoned` once + rerun no duplicate |
| Ending soon | 4 | `promotion.ending_soon`, `flash_sale.ending_soon` + rerun no duplicate |

The full `tests/Feature` suite establishes a **152-failure baseline** (pre-existing,
unrelated to this feature). After the `databaseType()` refactor the baseline is
unchanged → **0 net-new failures** (Phase 3 suite: 17 passed).

## Coverage by Concern

✅ Event → Listener → Notification dispatch (Phase 3)
✅ Wishlist fan-out scoping (user-only, admin excluded)
✅ Idempotent schedulers (no duplicate notifications on rerun)
✅ `type` stored as business id for all 21 notifications (runtime-proven via temp test)
✅ Broadcast channel resolution (`users.{id}`) runtime-proven

## Recommended Additional Tests

| # | Test | Priority |
|---|------|----------|
| 1 | Persisted `notifications.type` == business id for all 21 (DB assertion) | High |
| 2 | Realtime `private-users.{id}` channel resolves from `receivesBroadcastNotificationsOn()` | High |
| 3 | Phase 1 full path (order/payment/coupon) end-to-end | High |
| 4 | Phase 2 promotion/flash-sale available end-to-end | Medium |
| 5 | `lang` header localization on list/unread endpoints | Medium |
| 6 | Unread count accuracy after mark-all | Medium |
| 7 | User-scoping: user A cannot read/delete user B's notification | High |
| 8 | `meem-medium` queue asserted on listeners | Low |
