# Checkout Module — QA Test Cases

## Test Files

`tests/Feature/CheckoutApiTest.php`, `CheckoutRegressionTest.php`, `CouponSystemTest.php`, `AssignedCouponSystemTest.php`, `CouponsProductionHardenTest.php`, `FastShippingHardenTest.php`

---

## Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | Eligible promotions with cart | GET /checkout/promotions | 200, eligible_promotions |
| F2 | Eligible promotions no cart | No cart | 400 |
| F3 | Checkout COD | POST /checkout {cod} | 200, order_id |
| F4 | Checkout online | POST /checkout {online} | 200, url |
| F5 | Checkout cashier | POST {pay_at_cashier, pickup} | 200, order_id |
| F6 | Checkout COD+pickup | COD + pickup | 422 |
| F7 | Checkout without cart | No items | 400 |
| F8 | Checkout with promotion | selected_promotion_id | 200 |
| F9 | Checkout with coupon | Coupon on cart | 200 |
| F10 | Expired coupon cleared | Expired coupon | 200, coupon removed |
| F11 | Mark COD paid | POST /cod/{id}/mark-paid | 200 |
| F12 | Mark cashier paid | POST /cashier/{id}/mark-paid | 200 |
| F13 | Cashier response has no QR | Response body | No qr_code / transaction_uuid keys |
| F14 | Cashier lifecycle preserved | Checkout → mark-paid | Transaction pending → paid, order completed |
| F15 | Callback success | ANY /callback | Redirect /success |
| F16 | Callback mismatch | Amount mismatch | Redirect /failed |
| F17 | Error callback | ANY /error-callback | Redirect /failed |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Missing name | 422 |
| V2 | Missing phone | 422 |
| V3 | Missing email | 422 |
| V4 | Missing address | 422 |
| V5 | Delivery without governorate | 422 |
| V6 | Pickup without location_id | 422 |
| V7 | Invalid governorate | 422 |
| V8 | Unauthenticated | 401 |
| V9 | Pay at cashier + delivery | fulfillment_type=delivery | 422, fulfillment_type error |
| V10 | Pay at cashier without pickup_location_id | pickup, no location | 422, pickup_location_id error |
| V11 | COD + pickup | business rule | 422, "COD is not available for pickup" |
| V12 | Delivery without governorate, pickup with location | mixed | 200 (when governorate provided) |

---

## Request Body Requirements (what to send, when)

| Field | When required | Notes |
|-------|---------------|-------|
| name | Always | string, max:255 |
| user_phone | Always | string, max:255 |
| user_email | Always | email, max:255 |
| address | Always | array; empty `{}` acceptable for pickup |
| notes | Optional | string |
| payment_method | Optional | `online`/`cod`/`pay_at_cashier`; default `online` |
| gateway | Only for `payment_method=online` | default `myfatoorah` |
| fulfillment_type | Optional | `delivery`/`pickup`; default `delivery`; **must be `pickup` for pay_at_cashier** |
| governorate_id | **When `fulfillment_type=delivery`** | exists:governorates,id |
| pickup_location_id | **When `fulfillment_type=pickup`** | exists:pickup_locations,id |
| selected_promotion_id | Optional | exists:promotions,id |
| selected_gift_product_id | Optional | exists:products,id |
| type | Optional | `web`/`mobile`; controls callback format |

---

## Regression Tests

| # | Test | Expected |
|---|--------|
| R1 | Price recalculated at checkout | New price used |
| R2 | Flash sale active → discounted | Discounted price |
| R3 | Flash sale ended → regular | Regular price |
| R4 | Promo price refreshed | Current promo |
| R5 | Coupon locked during validation | Atomic update |
| R6 | Inventory finalized | Stock decremented |
| R7 | Price snapshot immutable | Order price unchanged |
| R8 | Coupon usage recorded | used incremented |
| R9 | No duplicate coupon usage | firstOrCreate |
| R10 | Free shipping coupon | shipping=0 |
| R11 | Minimum order amount enforced | 90 < 100 → 400 |
| R12 | Minimum order with promotion | 90 with promo discount → still 400 (uses subtotal) |
| R13 | Minimum order amount zero = skip | 0 → always passes |
