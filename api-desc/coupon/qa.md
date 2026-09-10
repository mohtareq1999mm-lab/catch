# QA Test Cases — Coupon Module

## API Functionality

| ID | Test Case | Expected |
|----|-----------|----------|
| CF-01 | List coupons with pagination | Returns paginated results with meta |
| CF-02 | List coupons with search | Filters by code/name LIKE |
| CF-03 | Create coupon with all valid fields | Returns 201 with coupon data |
| CF-04 | Get coupon by ID | Returns 200 with coupon data |
| CF-05 | Get coupon by code | Returns 200 with coupon data (auto-detect) |
| CF-06 | Update coupon | Returns 200 with updated data |
| CF-07 | Delete coupon | Returns 200, coupon removed |
| CF-08 | Apply valid coupon to cart | Returns 200 with discount data |

## Validation

| ID | Test Case | Expected |
|----|-----------|----------|
| CV-01 | Create without name | 422, name required |
| CV-02 | Create without image-desktop | 422, image-desktop required |
| CV-03 | Create without discount | 422, discount required |
| CV-04 | Create with invalid discount_type | 422, discount_type invalid |
| CV-05 | Create without start_date | 422, start_date required |
| CV-06 | Create without end_date | 422, end_date required |
| CV-07 | Create with end_date before start_date | 422, end_date must be after start_date |
| CV-08 | Create with invalid image format | 422, image must be jpeg/png/jpg/webp |
| CV-09 | Create with duplicate name | 422, name already taken |
| CV-10 | Create percentage coupon without max_discount_amount | 422, max_discount_amount required |
| CV-11 | Create with negative discount | 422, discount min:0 |
| CV-12 | Update with invalid data | 422, appropriate field errors |

## Authorization

| ID | Test Case | Expected |
|----|-----------|----------|
| CA-01 | List coupons without token | 401 |
| CA-02 | List coupons without permission | 403 |
| CA-03 | Create coupon without permission | 403 |
| CA-04 | Update coupon without permission | 403 |
| CA-05 | Delete coupon without permission | 403 |
| CA-06 | Public list without auth | 200 (public) |
| CA-07 | Apply coupon without auth | 401 |
| CA-08 | Approve coupon without super_admin | 403 |
| CA-09 | Disapprove coupon without super_admin | 403 |
| CA-10 | Vendor can only update | 200 on update, 403 on create/delete |

## Coupon Validation (Service Layer)

| ID | Test Case | Expected |
|----|-----------|----------|
| CS-01 | Apply expired coupon | Error: coupon expired |
| CS-02 | Apply future coupon | Error: not yet active |
| CS-03 | Apply disabled coupon | Error: coupon disabled |
| CS-04 | Apply coupon at usage limit | Error: limit reached |
| CS-05 | Apply coupon already used by user | Error: already used |
| CS-06 | Apply coupon with product restriction (eligible product) | Success |
| CS-07 | Apply coupon with product restriction (ineligible product) | Error: not eligible |

## Coupon Calculation

| ID | Test Case | Expected |
|----|-----------|----------|
| CC-01 | Percentage discount on $100 subtotal (20%) | $20 discount |
| CC-02 | Percentage discount with max cap | Discount capped at max |
| CC-03 | Fixed rate discount on $100 subtotal ($15) | $15 discount |
| CC-04 | Fixed rate discount capped at subtotal ($200 on $100) | $100 discount |
| CC-05 | Free shipping | freeShipping=true |
| CC-06 | Zero price input | 0 discount |

## Coupon Assignment

| ID | Test Case | Expected |
|----|-----------|----------|
| CM-01 | Apply public coupon (no assignments) | Success |
| CM-02 | Apply assigned coupon (user is assigned) | Success |
| CM-03 | Apply assigned coupon (user not assigned) | Error: not assigned |
| CM-04 | Apply expired assignment | Error: assignment expired |
| CM-05 | Apply assignment with exhausted quota | Error: quota exceeded |
| CM-06 | Apply assignment with global limiter reached | Error: limit reached |

## Checkout Integration

| ID | Test Case | Expected |
|----|-----------|----------|
| CH-01 | Apply coupon → checkout | Usage recorded |
| CH-02 | Apply assigned coupon → checkout | Assignment used incremented |
| CH-03 | Checkout with expired coupon (applied pre-expiry) | Re-validated at checkout |
| CH-04 | Remove coupon from cart | Cart cleared of coupon |
| CH-05 | Apply new coupon replacing existing | Old coupon replaced |
| CH-06 | Checkout after cart modification | Coupon re-validated |

## Edge Cases

| ID | Test Case | Expected |
|----|-----------|----------|
| CE-01 | Coupon code with leading/trailing whitespace | Trimmed, applied |
| CE-02 | Decimal discount value (e.g., 10.50) | Correct calculation |
| CE-03 | Very large discount value | Capped at subtotal |
| CE-04 | Multiple coupons on same cart | Only one applied (last wins) |
| CE-05 | Apply coupon to empty cart | Error or handled gracefully |
| CE-06 | Delete coupon that's applied to active cart | Coupon removed from cart |
| CE-07 | Concurrency: same coupon applied simultaneously | One succeeds, other fails |
| CE-08 | Limiter = 0 (unlimited) | Works as unlimited |
| CE-09 | 0% discount percentage | No discount applied |

## API Schema

| ID | Test Case | Expected |
|----|-----------|----------|
| CS-01 | Admin list response has correct structure | data, pagination meta |
| CS-02 | Admin resource has all fields | id, code, name, discount, discount_type, etc. |
| CS-03 | Public resource has correct fields | id, name, slug, image, borderColor, borderless |
| CS-04 | Apply response has coupon + discount_amount | Correct structure |
| CS-05 | Assignment resource has user data | id, name, email when loaded |

## Missing Coverage

- [ ] CRUD test for coupon approval/disapproval (super admin)
- [ ] Test for coupon verify endpoint (currently commented out)
- [ ] Test for public coupon apply with already_applied state
- [ ] Test for coupon usage recording with assignment's AssignedCouponConsumed event
- [ ] Test for cascade delete (coupon → coupon_usages → coupon_assignments)
- [ ] Test for coupon with multiple assignments
- [ ] Test for coupon analytics endpoint
- [ ] Load test: 1000+ concurrent coupon applies
- [ ] Security: SQL injection in search parameter
- [ ] Security: XSS in name field
