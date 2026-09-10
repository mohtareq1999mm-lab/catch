# Fixed: Minimum Order Amount Not Enforcing Correctly

## What Happened

The `minimumOrderAmount` setting (configured in admin settings) was not being checked during checkout. Customers could place orders below the configured minimum because the validation was missing in the new checkout endpoint (`POST /api/v1/general/checkout`).

## What Was Fixed

The checkout now properly enforces `minimumOrderAmount` by comparing it against the **subtotal** (total price of all items **before** any discounts, promotions, coupons, or flash sales are applied).

This means:
- If `minimumOrderAmount` is set to 100 EGP
- A customer adds 90 EGP of products to cart
- Even with a 20% promotion or a 10 EGP coupon
- The checkout will be **rejected** because the raw subtotal (90 EGP) is below the minimum

## Why Subtotal?

The minimum order amount is about ensuring a baseline order value, not the final payment. Discounts and promotions should not reduce the effective minimum — otherwise a customer with 80 EGP of products and a 20 EGP coupon (subtotal 80) could bypass a 100 EGP minimum.

## Error Response

When the minimum is not met, the API returns:

```json
{
    "success": false,
    "message": "Minimum order amount is 100",
    "errors": {}
}
```

## Frontend Handling

Display the error message from the API response as a banner above the checkout form. No other changes required.
