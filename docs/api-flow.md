# API Flow: Complete Endpoint Reference

> **Source code**: `routes/api.php`, `packages/marvel/src/Rest/Routes.php`, all controllers

---

## 1. Cart Endpoints

### 1.1 POST /api/v1/general/cart — Add Item

**Controller**: `Marvel\Http\Controllers\CartController@store`  
**Auth**: `auth:sanctum`  
**Rate limit**: 20/min

**Request**:
```json
{
  "item": {
    "product_id": 1,
    "quantity": 2,
    "product_variant_id": null,
    "attributes": null,
    "shipping_method": "SCHEDULED"
  }
}
```

**Validation** (`CartCreateRequest`):
- `item` required, array
- `item.product_id` required, exists:products,id
- `item.quantity` required, integer, min:1
- `item.product_variant_id` optional, exists:product_variants,id
- `item.attributes` optional
- `item.shipping_method` optional, default: SCHEDULED

**Service**: `CartRepository@storeCart` → `CartInventoryService@reserveItem` + `CartRepository@revalidatePromotion`

**Response 200**:
```json
{
  "success": true,
  "message": "Data fetched successfully",
  "data": { /* CartResource */ }
}
```

**Error 400**: Insufficient stock, invalid product

### 1.2 PUT /api/v1/general/cart/{id} — Update Quantity

**Controller**: `Marvel\Http\Controllers\CartController@update`

**Service**: `CartRepository@updateCart` → `CartInventoryService@reserveItem` (recalculate reservation + delta)

**Response**: Updated CartResource

### 1.3 DELETE /api/v1/general/cart/{id} — Remove Item

**Controller**: `Marvel\Http\Controllers\CartController@destroy`

**Service**: `CartInventoryService@releaseItem` → delete CartItem → `revalidatePromotion`

**Response**: 200 success

### 1.4 GET /api/v1/general/cart — View Cart

**Controller**: `Marvel\Http\Controllers\CartController@index`  
**Auth**: `auth:sanctum`

**Response**: CartResource with items split by shipping method

---

## 2. Coupon Endpoints

### 2.1 POST /api/v1/general/coupons/apply — Apply Coupon

**Controller**: `App\Http\Controllers\Api\General\CouponController@applyCoupon`  
**Auth**: `auth:sanctum`

**Service**: `CouponService@addCouponToCart` → `CouponOrchestrator@validateByCode` → save coupon on cart

**Error 400**: Invalid/expired/exhausted coupon

### 2.2 GET /api/v1/general/coupons — List Coupons

**Auth**: None (public)  
**Controller**: `CouponController@index` (Marvel)

---

## 3. Promotion Endpoints

### 3.1 GET /api/v1/general/promotions — List Promotions

**Auth**: None (public)  
**Controller**: `App\Http\Controllers\Api\General\PromotionController@index`

### 3.2 GET /api/v1/general/checkout/promotions — Eligible Promotions

**Controller**: `App\Http\Controllers\Api\General\OrderController@eligiblePromotions`  
**Auth**: `auth:sanctum`

**Service**: `PromotionService@eligiblePromotionsPayload`

**Response**: List of eligible promotions with calculated discount

**Error 400**: No active cart found

---

## 4. Checkout Endpoints

### 4.1 POST /api/v1/general/checkout — Create Order

**Controller**: `App\Http\Controllers\Api\General\OrderController@checkout`  
**Auth**: `auth:sanctum`  
**Rate limit**: 10/min

**Request** (`OrderCreateRequest`):
```json
{
  "name": "Ahmed Ali",
  "user_phone": "+201234567890",
  "user_email": "ahmed@example.com",
  "address": "123 Main St, Cairo",
  "notes": "Leave at door",
  "fulfillment_type": "delivery",
  "payment_method": "online",
  "gateway": "myfatoorah",
  "governorate_id": 1,
  "pickup_location_id": null,
  "selected_promotion_id": 5
}
```

**Validation**:
- `name` required, string
- `user_phone` required, string
- `user_email` required, email
- `address` required, string|array
- `notes` optional, string
- `fulfillment_type` required, in:delivery,pickup
- `payment_method` required, in:online,cod,pay_at_cashier
- `gateway` required_if:payment_method=online
- `governorate_id` required_if:fulfillment_type=delivery, exists:governorates,id
- `pickup_location_id` integer, exists_if:fulfillment_type=pickup
- `selected_promotion_id` optional, integer

**Business rules**:
- `cod + pickup` → 422 "COD not available for pickup"
- `pay_at_cashier` → forces pickup fulfillment
- Subtotal ≥ `settings.minimum_order_amount` → else rollback
- Inventory re-checked → if stock lost → 400

**Response (online)**:
```json
{
  "success": true,
  "message": "Checkout successful",
  "data": { "url": "https://gateway.com/pay/..." }
}
```

**Response (COD)**:
```json
{
  "success": true,
  "data": { "order_id": 123 }
}
```

**Response (cashier)**:
```json
{
  "success": true,
  "data": { "order_id": 123, "transaction_uuid": "...", "qr_code": "data:image/svg+xml;base64,..." }
}
```

### 4.2 GET /api/v1/general/checkout/callback — Payment Callback

**Controller**: `App\Http\Controllers\Api\General\OrderController@checkoutCallback`  
**Auth**: None (external gateway callback)

**Query**: `?paymentId=MF-123456` or `?type=mobile`

**Flow**: See customer-flow.md §5.1 for full execution trace.

**Response (success)**:
- Web: Redirect to `{frontend}/{locale}/payment/success?status=success&order_id=123`
- Mobile: JSON `{ status: 'success', order_id: 123 }`

**Response (failure)**:
- Web: Redirect to `{frontend}/{locale}/payment/failed?status=failed&message=...`
- Mobile: JSON with error

### 4.3 GET /api/v1/general/checkout/error-callback — Payment Error

**Controller**: `OrderController@checkoutErrorCallback`

**Flow**: Verifies payment, updates transaction to failed, fires PaymentFailed event, redirects to failure page.

### 4.4 POST /api/v1/general/checkout/cod/{orderId}/mark-paid — Mark COD Paid

**Auth**: `auth:sanctum` + permission:update-order-status  
**Controller**: `OrderController@markCodAsPaid`

### 4.5 POST /api/v1/general/checkout/cashier/{orderId}/mark-paid — Mark Cashier Paid

**Auth**: Same as COD  
**Controller**: `OrderController@markCashierPaid`

### 4.6 GET /api/v1/general/checkout/transaction-qr/{uuid} — Get Transaction QR

**Auth**: `auth:sanctum`  
**Controller**: `OrderController@getTransactionQr`

**Security**: Checks `transaction.order.user_id === auth.id` (ownership verification)  
**Response**: SVG image

---

## 5. Order Endpoints

### 5.1 GET /api/v1/general/orders — List Orders (Customer)

**Controller**: `App\Http\Controllers\Api\General\OrderController@index`  
**Auth**: `auth:sanctum`

**Query params**: `?status=pending&limit=15`

**Service**: `OrderService@paginateForUser` — scoped to `forUser(auth.id)`

---

## 6. Invoice Endpoints

### 6.1 GET /api/v1/general/invoices — List Invoices (Admin)

**Controller**: `App\Http\Controllers\Api\InvoiceController@index`  
**Auth**: `auth:sanctum` + permission:VIEW_INVOICES

**Filters**: search, status, order_id, user_id, invoice_series, currency, from, to  
**Sort**: created_at, total, status, invoice_number  
**Rate limit**: Inherits API rate limit (60/min)

**Response**: Paginated InvoiceCollection with InvoiceResource

### 6.2 GET /api/v1/general/invoices/my-invoices — My Invoices

**Controller**: `InvoiceController@myInvoices`  
**Auth**: `auth:sanctum`

**Scope**: `where('user_id', auth.id)`

### 6.3 GET /api/v1/general/invoices/{uuid}/download — Download PDF

**Controller**: `InvoiceController@download`  
**Auth**: `auth:sanctum`  
**Rate limit**: 30/min

**Security**: Ownership check: `invoice.user_id === auth.id` OR `can(VIEW_INVOICE)`  
**Error 404**: No PDF generated yet

### 6.4 GET /api/v1/general/invoices/verify/{uuid} — Verify Invoice

**Controller**: `InvoiceController@verify`  
**Rate limit**: 60/min (no auth required)

**Response (authentic)**:
```json
{
  "authentic": true,
  "invoice": { /* InvoiceResource */ },
  "order": { "id": 1, "order_number": "...", "status": "...", ... },
  "qr_content": "https://..."
}
```

**Response (tampered)**: 409 `{ "authentic": false, "tampered": true }`

### 6.5 POST /api/v1/general/invoices/{id}/regenerate — Regenerate PDF

**Controller**: `InvoiceController@regenerate`  
**Auth**: `auth:sanctum` + permission:REGENERATE_INVOICE

**Allowed when**: status in [failed, ready, generated]  
**Effect**: Re-dispatches GenerateInvoicePdfJob

---

## 7. Error Response Format

All endpoints use `Marvel\Traits\ApiResponse` trait:
```json
// Success
{ "success": true, "message": "Data fetched successfully", "data": {...} }

// Error
{ "success": false, "message": "Cart not found" }

// Validation Error (422)
{ "success": false, "message": "Validation failed", "errors": {...} }
```

### HTTP Status Codes Used

| Code | Usage |
|------|-------|
| 200 | Success |
| 400 | Bad request (cart not found, invalid coupon) |
| 404 | Not found (order, invoice, transaction) |
| 409 | Conflict (tampered invoice, callback mismatch) |
| 422 | Validation error, business rule violation |
| 500 | Server error (gateway unavailable, transaction failure) |

---

## 8. Rate Limits

| Endpoint Group | Limit | Applied At |
|---------------|-------|------------|
| General API | 60/min | `RouteServiceProvider` |
| Auth endpoints | 10/min | `RouteServiceProvider` |
| OTP | 3/min | `RouteServiceProvider` |
| Sensitive operations | 5/min | `RouteServiceProvider` |
| Orders | 10/min | `RouteServiceProvider` |
| Cart | 20/min | `RouteServiceProvider` |
| Search | 30/min | `RouteServiceProvider` |
| Analytics | 60/min | `RouteServiceProvider` |
| Content | 5/min | `RouteServiceProvider` |
| Refunds | 5/min | `RouteServiceProvider` |
| Uploads | 10/min | `RouteServiceProvider` |
| Invoice download | 30/1min | Route middleware |
| Invoice verify | 60/1min | Route middleware |

---

## 9. Route Map Summary

```
GET    /api/v1/general/categories
GET    /api/v1/general/brands
GET    /api/v1/general/banners
GET    /api/v1/general/sliders
GET    /api/v1/general/tags
GET    /api/v1/general/promotions
GET    /api/v1/general/coupons
POST   /api/v1/general/coupons/apply           [auth]
GET    /api/v1/general/products
GET    /api/v1/general/flash-sales
GET    /api/v1/general/settings
GET    /api/v1/general/faqs
GET    /api/v1/general/governorates
GET    /api/v1/general/search
GET    /api/v1/general/pickup-locations
GET    /api/v1/general/fast-shipping/status
GET    /api/v1/general/fast-shipping/products
POST   /api/v1/general/fast-shipping/checkout   [auth]
GET    /api/v1/general/fast-shipping/orders      [auth]

GET    /api/v1/general/checkout/promotions       [auth]
POST   /api/v1/general/checkout                  [auth]
POST   /api/v1/general/checkout/cod/{id}/mark-paid     [auth+perm]
POST   /api/v1/general/checkout/cashier/{id}/mark-paid [auth+perm]
GET    /api/v1/general/checkout/transaction-qr/{uuid}  [auth]
ANY    /api/v1/general/checkout/callback
ANY    /api/v1/general/checkout/error-callback

GET    /api/v1/general/orders                    [auth]

GET    /api/v1/general/invoices/my-invoices      [auth]
GET    /api/v1/general/invoices/verify/{uuid}    [throttle:60]
GET    /api/v1/general/invoices/uuid/{uuid}      [auth]
GET    /api/v1/general/invoices                  [auth]
GET    /api/v1/general/invoices/{uuid}/download   [auth+throttle:30]
GET    /api/v1/general/invoices/{id}              [auth]
POST   /api/v1/general/invoices/{id}/regenerate   [auth]
```
