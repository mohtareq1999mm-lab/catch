# Data Flow - Fast Shipping Feature

## Flow 1: Channel-Aware Product Listing

```
Client
  |
  GET /api/v1/general/products
  Header: X-Channel: fast-shipping
  |
  v
ChannelMiddleware:
  |-- Read X-Channel header
  |-- Valid: fast-shipping → ChannelContext::setChannel(FAST_SHIPPING)
  |-- Invalid + strict mode → 400
  |-- Invalid + non-strict → fallback to home
  |
  v
ProductController@index(Request)
  |
  v
ProductService::paginate(Request)
  |
  v
Product::query()->active()->...->paginate()
  |
  v
FastShippingScope (Global):
  |-- Apply: WHERE is_fast_shipping_available = 1
  |
  v
Database: SELECT * FROM products WHERE ... AND is_fast_shipping_available = 1
```

## Flow 2: Fast Shipping Checkout

```
Client (Auth)
  |
  POST /api/v1/general/fast-shipping/checkout
  Body: { name, phone, email, address, governorate_id, payment_method }
  |
  v
FastShippingController@checkout(FastCheckoutRequest)
  |
  +-- Validate request
  |
  v
FastShippingService::createFastOrder($request)
  |
  +-- DB::transaction():
  |     |-- FastShippingRepository::validateCheckout():
  |     |     |-- isGloballyEnabled()
  |     |     |-- isWithinWorkingHours()
  |     |     |-- isGovernorateEnabled($governorate_id)
  |     |     |-- areProductsFastEligible($cartItems)
  |     |
  |     |-- CartInventoryService::lockInventoryRow():
  |     |     |-- Product::query()->whereKey($id)->lockForUpdate()
  |     |     |-- (BUG: global scope may cause ModelNotFoundException)
  |     |
  |     |-- Calculate totals: subtotal + shipping + fast_shipping_fee
  |     |
  |     |-- OrderCreationService::createOrder():
  |     |     |-- Create order with shipping_method = 'FAST'
  |     |     |-- Create order items
  |     |     |-- Handle payment (COD/online/cashier)
  |     |
  |     |-- Clear user's cart
  |
  v
JSON Response: { order_id, total, message }
```

## Flow 3: Product Fast Shipping Toggle (Admin)

```
Admin
  |
  PUT /api/v1/products/{id}/fast-shipping
  Body: { is_fast_shipping_available: true }
  |
  v
ProductController@toggleFastShipping($id, Request)
  |
  v
Product::findOrFail($id)->update(['is_fast_shipping_available' => true])
  |
  v
Response: { "message": "Fast shipping availability updated" }
```
