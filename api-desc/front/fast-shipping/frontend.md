# Frontend - Fast Shipping Feature

## Status

**No dedicated frontend Vue/React components** found. Frontend is a separate SPA.

## Consumption Patterns

```javascript
// services/fastShippingApi.js
export const fastShippingApi = {
  status()                    // GET /api/v1/general/fast-shipping/status
  products(params)            // GET /api/v1/general/fast-shipping/products
  checkout(data)              // POST /api/v1/general/fast-shipping/checkout
  orders()                    // GET /api/v1/general/fast-shipping/orders
}
```

## What a Frontend Implementation Would Need

```
FastShippingBanner.vue
  Fetches: GET /api/v1/general/fast-shipping/status
  Renders: Shipping status, ETA, cutoff time
  Dynamic: Show/hide based on availability

FastShippingProductList.vue
  Fetches: GET /api/v1/general/fast-shipping/products
  Renders: Product grid/cards with fast-shipping badge
  Features: Search, pagination

FastShippingCart.vue
  Checks: Cart items must all have shipping_method = 'FAST'
  Renders: Fast shipping fee, ETA

FastCheckoutForm.vue
  Fields: name, phone, email, address, governorate selector
  Payment: COD, online, cashier
  Submit: POST /api/v1/general/fast-shipping/checkout

FastOrderHistory.vue
  Fetches: GET /api/v1/general/fast-shipping/orders
  Renders: List of past fast orders

ProductFastBadge.vue
  Props: is_fast_shipping_available (boolean)
  Renders: "Fast Shipping" badge on eligible products

FastShippingToggle.vue (Admin)
  PUT /api/v1/products/{id}/fast-shipping
  Toggle switch per product
```
