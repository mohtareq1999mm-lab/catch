# Coupon Module — QA Test Cases (Public API)

## Test Files

`tests/Feature/CouponSystemTest.php` — Good coverage of apply flow
`tests/Feature/AssignedCouponSystemTest.php` — Assigned coupon validation
`tests/Feature/FastShippingControllerTest.php` — Channel header test

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List coupons | GET /general/coupons | 200, valid coupons only |
| F2 | List with search | ?search=Summer | Filtered by name |
| F3 | List by IDs | ?couponsId=1,2 | Specified coupons |
| F4 | List empty | No valid coupons | 200, empty array |
| F5 | Apply coupon | POST /general/coupons/apply {code} | 200, discount applied |
| F6 | Apply already applied | Same code again | 200, already_applied |
| F7 | Apply invalid code | Non-existent code | 400 |
| F8 | Apply expired coupon | Past end_date | 400 |
| F9 | Apply disabled coupon | status=false | 400 |
| F10 | Apply usage limit reached | limiter exceeded | 400 |
| F11 | Apply unauthenticated | No token | 401 |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | List response | status, message, success, data | Correct keys |
| S2 | Coupon object | id, name, slug, image, borderColor, borderless | Correct types |
| S3 | Apply success | total_price, coupon_discount, free_shipping | Correct types |
| S4 | Apply already applied | already_applied: true | Boolean |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Apply without code | Empty request body | 400 or 422 |
| V2 | Apply without cart | User has no cart | 400 |
| V3 | Apply restricted product | Product not in coupon's allowed list | 400 |
| V4 | Apply exceeded quantity | Min/max quantity not met | 400 |

---

## Regression Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Expired coupon excluded from list | Past end_date | Not in listing |
| R2 | Usage limit reached excluded | used >= limiter | Not in listing |
| R3 | Disabled coupon excluded | status=false | Not in listing |
| R4 | Free shipping coupon | free_shipping: true | Returned in response |
| R5 | Percentage with max cap | 20% off, max $10 | Discount capped at $10 |
