# Test Coverage — Checkout Module

## Existing Tests

**Files:** `CheckoutApiTest.php`, `CheckoutRegressionTest.php`, `CouponSystemTest.php`, `AssignedCouponSystemTest.php`, `CouponsProductionHardenTest.php`, `FastShippingHardenTest.php`

| # | Test | File |
|---|------|------|
| 1 | test_checkout_requires_auth | CheckoutApiTest |
| 2 | test_checkout_requires_cart | CheckoutApiTest |
| 3 | test_checkout_cod_creates_order | CheckoutApiTest |
| 4 | test_checkout_finalizes_inventory | CheckoutApiTest |
| 5 | test_checkout_cod_with_pickup_rejected | CheckoutApiTest |
| 6 | test_eligible_promotions_with_cart | CheckoutApiTest |
| 7 | test_checkout_validates_governorate | CheckoutApiTest |
| 8 | test_checkout_stores_coupon_usage | CheckoutApiTest |
| 9 | checkout_uses_current_product_price | CheckoutRegressionTest |
| 10 | checkout_recalculates_price_when_flash_sale_starts | CheckoutRegressionTest |
| 11 | checkout_uses_product_price_when_flash_sale_ends | CheckoutRegressionTest |
| 12 | checkout_refreshes_promotion_price | CheckoutRegressionTest |
| 13 | checkout_rejects_expired_coupon | CheckoutRegressionTest |
| 14 | checkout_stores_price_snapshot_immutable | CheckoutRegressionTest |
| 15 | checkout_coupon_locked_during_validation | CheckoutRegressionTest |
| 16 | checkout_records_coupon_usage | CouponSystemTest |
| 17 | checkout_with_free_shipping_coupon | CouponSystemTest |
| 18 | checkout_with_percentage_coupon | CouponsProductionHardenTest |
| 19 | checkout_with_fixed_coupon | CouponsProductionHardenTest |
| 20 | checkout_clears_expired_coupon | CouponsProductionHardenTest |
| 21 | checkout_with_assigned_coupon | AssignedCouponSystemTest |

## Recommended Additional Tests

### Payment Method Tests
| # | Test | Description |
|---|------|-------------|
| 1 | test_checkout_online_payment | Creates transaction, returns URL |
| 2 | test_checkout_pay_at_cashier | Returns order_id only (no QR) — implemented as `scheduled_checkout_with_pay_at_cashier_requires_pickup_location` in `PaymentCheckoutTest` |
| 3 | test_mark_cod_as_paid_no_permission | 403 |
| 4 | test_pay_at_cashier_full_lifecycle | Checkout → mark-paid → transaction paid, order completed — implemented as `pay_at_cashier_lifecycle_is_preserved_after_qr_removal` in `PaymentCheckoutTest` |
| 5 | test_pay_at_cashier_delivery_rejected | fulfillment_type=delivery → 422 validation error |
| 6 | test_cashier_response_has_no_qr | Assert no `qr_code`/`transaction_uuid` keys in response data |

### Callback Tests
| # | Test | Description |
|---|------|-------------|
| 1 | test_callback_missing_payment_id | 400 |
| 2 | test_callback_successful_payment | Redirect /success |
| 3 | test_callback_amount_mismatch | Redirect /failed + cancelled |
| 4 | test_callback_currency_mismatch | Redirect /failed |
| 5 | test_callback_mobile_response | JSON response |

### Minimum Order Amount Tests
| # | Test | Description |
|---|------|-------------|
| 1 | test_checkout_below_minimum_rejected | Subtotal 90, minimum 100 → 400 |
| 2 | test_checkout_at_minimum_succeeds | Subtotal 100, minimum 100 → 201 |
| 3 | test_checkout_minimum_with_promotion | Subtotal 90, promo discount applied → still 400 |
| 4 | test_checkout_minimum_with_coupon | Subtotal 90, coupon applied → still 400 |
| 5 | test_checkout_minimum_zero_disabled | minimum = 0 → always passes |

### Regression Tests
| # | Test | Description |
|---|------|-------------|
| 1 | test_order_pickup_snapshot | Pickup data frozen |
| 2 | test_order_promotion_snapshot | Promotion frozen |
| 3 | test_order_coupon_snapshot | Coupon frozen |
| 4 | test_inventory_double_sell | Parallel checkout same product |
| 5 | test_coupon_usage_race_condition | Parallel checkouts |
