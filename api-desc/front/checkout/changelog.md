# Checkout Module — Changelog

## [1.2.0] — 2026-08-18

### Added
- **`payment-audit.md`** — complete read-only payment system audit covering all 3 payment methods, request/response contracts, transaction lifecycle, MyFatoorah/COD/cashier flows, fulfillment matrix, currency/amount verification, inventory/coupon/promotion behavior, events/notifications, idempotency, failure matrix, auth/routes, gateway architecture, DB model, tests, cross-flow comparison, critical findings, and a frontend requirements specification. Verified: `PaymentCheckoutTest` 29/29 pass; `coupon_assignments` test failure and `BkashTokenizePaymentController` route error are pre-existing and unrelated.

---

## [1.1.0] — 2026-08-18

### Removed
- **Pay at Cashier QR code feature.** The checkout response no longer includes `qr_code` or `transaction_uuid` — it now returns only `order_id`.
- Deleted `GET /api/v1/general/checkout/transaction-qr/{uuid}` endpoint.
- Deleted `app/Services/Gateway/CashierQrService.php` (QR generation).
- Removed `Transaction::byUuid` scope (only used by the deleted QR endpoint).
- Removed `pay_at_cashier` config block from `config/payment.php` (QR-only settings).

### Preserved
- Pay at Cashier lifecycle unchanged: checkout → pending transaction → cashier marks paid (`/cashier/{orderId}/mark-paid`) → transaction paid, order completed, `PaymentSucceeded` event.
- `transactions.uuid` column stays in the database and continues to be auto-generated for all transactions.

### Documentation
- Added full request bodies (with **required** / **required-when** semantics) and response bodies for every checkout endpoint across `api.md`, `frontend.md`, `flow.md`, `backend.md`, `README.md`:
  - `POST /checkout` — all three payment methods (online / COD / pay-at-cashier) and both fulfillment types (delivery / pickup), with the compatibility matrix.
  - `POST /checkout/cod/{orderId}/mark-paid` and `POST /checkout/cashier/{orderId}/mark-paid` — request/response.
  - `ANY /checkout/callback` and `ANY /checkout/error-callback` — web redirect vs mobile JSON responses.
  - `GET /orders`, `GET /orders/invoice/{uuid}`, `POST /fast-shipping/checkout`.
- Documented the unified `ApiResponse` envelope (`status`, `message`, `success`, `data`) and the raw 422 validation error shape.
- Corrected the promotions response to the real shape: `data.eligible_promotions[]` (was previously documented as `promotions` + `gift_products`).
- Added the `gateway_response` / `error_message` columns to the `transactions` table documentation.

---

## [1.0.0] — 2026-07-20

### Added
- Comprehensive API investigation documentation (`api-desc/front/checkout/`)
- 7 checkout endpoints (promotions, place order, COD/cashier mark-paid, QR, callbacks)
- 3 payment methods: online (gateway redirect), COD, pay at cashier (QR code)
- Real-time price recalculation at checkout (flash sales, promotions, coupons)
- Pricing snapshot immutability (order stores all pricing at checkout time)
- Atomic inventory finalization with pessimistic locking
- Payment gateway callback handling with amount/currency mismatch detection
- Order status state machine with allowed transitions
- Coupon usage recording (firstOrCreate + assignment consumption)
- Extensive existing test coverage

### Fixed
- **HIGH:** `POST /api/v1/general/checkout` now enforces the global `minimumOrderAmount` setting (from `settings.options.minimumOrderAmount`). Previously the check was only present in the old Marvel checkout flow (`CheckoutRepository::verify()`), not in the new `OrderService::addItemsInOrder()`. The check compares against `subtotal` (pre-discount) so promotions, coupons, flash sales, and discounts cannot bypass the minimum.

### Known Issues
1. **Duplicated callback logic** — ~230 lines shared (BUG-CHK-002)
2. **No cart vs empty cart** — Same 400 response (BUG-CHK-001)
3. **FAST items not locked** — Only SCHEDULED items (BUG-CHK-003)
4. **Locale lost on callback** — Uses app()->getLocale() (BUG-CHK-006)
5. **FAST prices not refreshed** — Only SCHEDULED in scope (BUG-CHK-007)
6. **Hardcoded status transitions** (BUG-CHK-005)
7. **Deleted order with valid transaction** — 404 instead of 500 (BUG-CHK-004)
