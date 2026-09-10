# API Contract — Complete Endpoint Reference

> Version: 1.0 | Classification: INTERNAL | Last Updated: 2026-07-27

---

## Executive Summary

The API is split into two route groups: **`api/v1/general`** (storefront) and **`api/v1`** (admin/management). The storefront group handles public browsing, cart, checkout, orders, and payment callbacks with rate-limited checkout and cart endpoints. The admin group covers CRUD for all entities (products, categories, brands, coupons, promotions, shops, users, etc.) plus authentication, dashboard analytics, webhooks, messaging, and refunds. Total endpoints: **~250+**.

---

## 1. Base URLs & Prefixes

| Prefix | Source File | Middleware | Purpose |
|---|---|---|---|
| `api/v1/general` | `routes/api.php` | `api` | Storefront (public + auth) |
| `api/v1` | `packages/marvel/src/Rest/Routes.php` | `api` | Admin & management |
| `api/v1/dashboard` | `packages/marvel/src/Rest/Routes.php` | `api`, `throttle:analytics` | Analytics (60/min) |
| `api/v1/webhooks` | `packages/marvel/src/Rest/Routes.php` | `api` | Payment gateway webhooks |

---

## 2. Storefront Endpoints (`api/v1/general`)

### 2.1 Navigation & Content

| Method | URI | Auth | Purpose |
|---|---|---|---|
| GET | `/nav-data` | No | Navigation menu data |
| GET | `/settings` | No | Public settings |
| GET | `/faqs` | No | List FAQs |
| GET | `/search` | No | Global search |
| GET | `/governorates` | No | List governorates |
| GET | `/pickup-locations` | No | List pickup locations |
| GET | `/pickup-locations/{id}` | No | Single pickup location |

### 2.2 Categories, Brands, Banners, Sliders

| Method | URI | Auth | Purpose |
|---|---|---|---|
| GET | `/categories` | No | List categories |
| GET | `/categories/{slug}` | No | Category by slug |
| GET | `/brands` | No | List brands |
| GET | `/brands/{slug}` | No | Brand by slug |
| GET | `/brands-products` | No | Brands with product count |
| GET | `/banners` | No | List banners |
| GET | `/banners/{slug}` | No | Banner by slug |
| GET | `/sliders` | No | List sliders |
| GET | `/sliders/{slug}` | No | Slider by slug |
| GET | `/tags` | No | List tags |

### 2.3 Products

| Method | URI | Auth | Purpose |
|---|---|---|---|
| GET | `/products` | No | List/search products |
| GET | `/products/{slug}` | No | Product by slug |
| POST | `/products/{id}/reviews` | Yes | Add product review |
| PUT | `/products/reviews/{id}` | Yes | Update review |

### 2.4 Flash Sales

| Method | URI | Auth | Purpose |
|---|---|---|---|
| GET | `/flash-sales` | No | List flash sales |
| GET | `/flash-sales/{slug}` | No | Flash sale by slug |
| GET | `/flash-sale-products` | No | Flash sales with products |
| GET | `/flash-sale-products-ending-this-week` | No | Ending this week |
| GET | `/flash-sale-products-ending-today` | No | Ending today |

### 2.5 Promotions & Coupons

| Method | URI | Auth | Purpose |
|---|---|---|---|
| GET | `/promotions` | No | List promotions |
| GET | `/promotions/{slug}` | No | Promotion by slug |
| GET | `/coupons` | No | List coupons |
| POST | `/coupons/apply` | Yes | Apply coupon code |

### 2.6 Content Pages

| Method | URI | Auth | Purpose |
|---|---|---|---|
| GET | `/content-pages` | No | List content pages |
| GET | `/content-pages/{slug}` | No | Content page by slug |

### 2.7 Fast Shipping

| Method | URI | Auth | Purpose |
|---|---|---|---|
| GET | `/fast-shipping/status` | No | Fast shipping status |
| GET | `/fast-shipping/products` | No | Fast shipping products |
| POST | `/fast-shipping/checkout` | Yes | Fast shipping checkout |
| GET | `/fast-shipping/orders` | Yes | Fast shipping orders |

### 2.8 Checkout & Orders

| Method | URI | Auth | Rate Limit | Purpose |
|---|---|---|---|---|
| GET | `/checkout/promotions` | Yes | — | Eligible promotions |
| POST | `/checkout` | Yes | `throttle:checkout` | Process checkout |
| POST | `/checkout/cod/{orderId}/mark-paid` | Yes + permission | — | Mark COD as paid |
| POST | `/checkout/cashier/{orderId}/mark-paid` | Yes + permission | — | Mark cashier as paid |
| GET | `/checkout/transaction-qr/{uuid}` | Yes | — | Get transaction QR |
| ANY | `/checkout/callback` | No | — | Payment gateway callback |
| ANY | `/checkout/error-callback` | No | — | Payment error callback |
| GET | `/orders` | Yes | — | List user orders |

### 2.9 Invoices (Super Admin)

| Method | URI | Auth | Purpose |
|---|---|---|---|
| GET | `/invoices` | Super admin | List invoices |
| GET | `/invoices/{id}` | Super admin | Get invoice |
| POST | `/invoices/{id}/regenerate` | Super admin | Regenerate invoice |

---

## 3. Authentication & User Endpoints (`api/v1`)

### 3.1 Auth

| Method | URI | Rate Limit | Purpose |
|---|---|---|---|
| POST | `/register` | `throttle:auth` (10/min) | Register user |
| POST | `/token` | `throttle:auth` (10/min) | Login |
| POST | `/admin-login` | `throttle:auth` (10/min) | Admin login |
| POST | `/logout` | — | Logout (auth) |
| GET | `/me` | — | Current user (auth) |
| GET | `/social/redirect` | — | Social login redirect |
| GET | `/social/callback` | — | Social login callback |

### 3.2 Password Reset & OTP

| Method | URI | Rate Limit | Purpose |
|---|---|---|---|
| POST | `/forget-password` | `throttle:sensitive` (5/min) | Send reset email |
| POST | `/verify-forget-password-token` | `throttle:sensitive` | Verify token |
| POST | `/reset-password` | `throttle:sensitive` | Reset password |
| POST | `/send-otp-code` | `throttle:otp` (3/min) | Send OTP |
| POST | `/otp-login` | `throttle:otp` (3/min) | OTP login |

### 3.3 Admin User Management

| Method | URI | Purpose |
|---|---|---|
| POST | `/admin-users/add` | Admin add users |
| PUT | `/admin-users/update-activation` | Update activation |
| DELETE | `/admin-users/delete/{id}` | Soft-delete user |
| PUT | `/admin-users/restore/{id}` | Restore user |
| DELETE | `/admin-users/delete-forever/{id}` | Force-delete |

---

## 4. Core CRUD Endpoints (`api/v1`)

All entities follow RESTful patterns with standard index/store/show/update/destroy:

### 4.1 Products

| Method | URI | Purpose |
|---|---|---|
| GET | `/products` | List (paginated, filterable, searchable) |
| POST | `/products` | Create |
| GET | `/products/{id}` | Show |
| PUT | `/products/{id}` | Update |
| DELETE | `/products/{id}` | Delete |
| POST | `/products/bulk-delete` | Bulk delete |
| DELETE | `/products/all` | Delete all |
| GET | `/popular-products` | Popular products |
| GET | `/best-selling-products` | Best sellers |
| GET | `/products/calculate-rental-price` | Rental price |

### 4.2 Product Import/Export

| Method | URI | Purpose |
|---|---|---|
| POST | `/products/import` | Import from CSV |
| GET | `/products/import/{id}` | Import status |
| POST | `/products/import/{id}/cancel` | Cancel import |
| GET | `/products/import/{id}/download-errors` | Error log |
| GET | `/samples/product-import` | Sample file |
| GET | `/products/export` | Export to Excel |

### 4.3 Categories

| Method | URI | Purpose |
|---|---|---|
| GET | `/categories` | List |
| POST | `/categories` | Create |
| GET | `/categories/{id}` | Show |
| PUT | `/categories/{id}` | Update |
| DELETE | `/categories/{id}` | Delete |
| PUT | `/categories/feature` | Toggle featured |
| GET | `/featured-categories` | Featured list |

### 4.4 Brands

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/brands` | List/Create |
| GET/PUT/DELETE | `/brands/{id}` | Show/Update/Delete |
| PUT | `/brands/reorder` | Reorder |

### 4.5 Banners

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/banners` | List/Create |
| GET/PUT/DELETE | `/banners/{id}` | Show/Update/Delete |
| PUT | `/banners/change-status` | Status toggle |
| POST | `/banners/reorder` | Reorder |

### 4.6 Sliders

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/sliders` | List/Create |
| GET/PUT/DELETE | `/sliders/{id}` | Show/Update/Delete |
| PATCH | `/sliders/change-status` | Status toggle |
| PUT | `/sliders/reorder` | Reorder |

### 4.7 Coupons

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/coupons` | List/Create |
| GET/PUT/DELETE | `/coupons/{id}` | Show/Update/Delete |
| POST | `/coupons/verify` | Verify coupon code |
| POST | `/coupons/add-to-cart` | Add to cart (auth) |
| POST | `/coupons/approve` | Approve |
| POST | `/coupons/disapprove` | Disapprove |

### 4.8 Coupon Assignments

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/coupons/{coupon}/assignments` | List/Create |
| GET/PUT/DELETE | `/coupons/{coupon}/assignments/{id}` | Show/Update/Delete |

### 4.9 Promotions

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/promotions` | List/Create |
| GET/PUT/DELETE | `/promotions/{id}` | Show/Update/Delete |

### 4.10 Flash Sales

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/flash-sale` | List/Create |
| GET/PUT/DELETE | `/flash-sale/{id}` | Show/Update/Delete |
| PUT | `/flash-sale/reorder` | Reorder |
| GET | `/product-flash-sale-info` | Info by product ID |

### 4.11 Attributes & Values

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/attributes` | List/Create |
| GET/PUT/DELETE | `/attributes/{id}` | Show/Update/Delete |
| GET/POST | `/attribute-values` | List/Create |
| GET/PUT/DELETE | `/attribute-values/{id}` | Show/Update/Delete |

### 4.12 Pickup Locations

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/pickup-locations` | List/Create |
| GET/PUT/DELETE | `/pickup-locations/{id}` | Show/Update/Delete |

### 4.13 Orders

| Method | URI | Auth | Purpose |
|---|---|---|---|
| GET | `/orders` | — | List (role-scoped) |
| GET | `/orders/{id}` | — | Get order |
| POST | `/orders/checkout/verify` | — | Verify checkout |
| POST | `/orders/payment` | — | Submit payment |
| GET | `/orders/tracking-number/{tn}` | Auth + email verified | Find by tracking # |

### 4.14 Reviews, Questions, Feedback

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/reviews` | List/Create |
| GET/PUT/DELETE | `/reviews/{id}` | Show/Update/Delete |
| PATCH | `/reviews/{id}/toggle-approve` | Toggle approval |
| GET/POST | `/questions` | List/Create |
| GET/PUT/DELETE | `/questions/{id}` | Show/Update/Delete |
| GET/POST | `/feedbacks` | List/Create |
| GET/PUT/DELETE | `/feedbacks/{id}` | Show/Update/Delete |

### 4.15 Abusive Reports

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/abusive_reports` | List/Create |
| GET/PUT/DELETE | `/abusive_reports/{id}` | Show/Update/Delete |
| POST | `/abusive_reports/accept` | Accept report |
| POST | `/abusive_reports/reject` | Reject report |

### 4.16 Cart

| Method | URI | Auth | Rate Limit | Purpose |
|---|---|---|---|---|
| GET | `/cart` | Yes | `throttle:cart` (20/min) | List items |
| POST | `/cart` | Yes | `throttle:cart` (20/min) | Add item |
| GET | `/cart/{id}` | Yes | `throttle:cart` (20/min) | Get item |
| POST | `/cart/bulk-items` | Yes | `throttle:cart` (20/min) | Bulk add |
| PUT | `/cart/update-item` | Yes | `throttle:cart` (20/min) | Update item |
| DELETE | `/cart/delete-item/{itemId}` | Yes | `throttle:cart` (20/min) | Remove item |
| DELETE | `/cart/delete-items` | Yes | `throttle:cart` (20/min) | Clear cart |

### 4.17 Payment Methods

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/cards` | List/Create card |
| PUT/DELETE | `/cards/{id}` | Update/Delete |
| POST | `/set-default-card` | Set default |
| POST | `/save-payment-method` | Save method |
| GET | `/payment-intent` | Get intent |

---

## 5. Geography Endpoints (`api/v1`)

### 5.1 Countries

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/countries` | List/Create |
| GET/PUT/DELETE | `/countries/{id}` | Show/Update/Delete |
| GET | `/countries/{id}/governorates` | Governorates by country |
| POST | `/countries/change-status` | Bulk status |

### 5.2 Governorates

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/governorates` | List/Create |
| GET/PUT/DELETE | `/governorates/{id}` | Show/Update/Delete |
| PUT | `/governorates/change-status` | Bulk status |
| PUT | `/governorates/{id}/fast-shipping` | Toggle fast shipping |
| GET | `/governorates/{id}/cities` | Cities in governorate |

### 5.3 Cities

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/cities` | List/Create |
| GET/PUT/DELETE | `/cities/{id}` | Show/Update/Delete |

---

## 6. CMS & Content Endpoints (`api/v1`)

### 6.1 Content Pages

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/content-pages` | List/Create |
| GET/PUT/DELETE | `/content-pages/{id}` | Show/Update/Delete |
| POST | `/content-pages/{id}/attach-sections` | Attach sections |
| PATCH | `/content-pages/{id}/toggle-active` | Toggle active |

### 6.2 Sections

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/sections` | List/Create |
| GET/PUT/DELETE | `/sections/{id}` | Show/Update/Delete |
| PUT | `/sections/reorder` | Reorder |
| GET | `/sections/types` | Section types |
| PATCH | `/sections/{id}/toggle-active` | Toggle status |

### 6.3 Section Types

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/section-types` | List/Create |
| GET/PUT/DELETE | `/section-types/{id}` | Show/Update/Delete |
| GET/POST | `/section-types/{type}/settings` | Get/Update settings |

### 6.4 CMS Pages (Puck)

| Method | URI | Auth | Purpose |
|---|---|---|---|
| GET | `/cms-pages` | No | List CMS pages |
| GET | `/cms-pages/{slug}` | No | Page by slug |
| GET | `/puck/page` | No | Page by path |
| POST | `/cms-pages` | Editor/Admin | Create |
| PUT | `/cms-pages/{id}` | Editor/Admin | Update |
| DELETE | `/cms-pages/{id}` | Editor/Admin | Delete |

### 6.5 Component Data

| Method | URI | Purpose |
|---|---|---|
| GET | `/component-data/flash-sale-products` | Flash sale products |
| GET | `/component-data/categories` | Categories |
| GET | `/component-data/collections` | Collections |
| GET | `/component-data/popular-products` | Popular products |
| GET | `/component-data/best-selling-products` | Best sellers |

### 6.6 Settings

| Method | URI | Purpose |
|---|---|---|
| GET | `/settings` | Get all settings |
| PUT | `/settings` | Update settings |

---

## 7. Shops & Vendors (`api/v1`)

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/shops` | List/Create |
| GET/PUT/DELETE | `/shops/{id}` | Show/Update/Delete |
| GET | `/near-by-shop/{lat}/{lng}` | Nearby shops |
| POST | `/shop-maintenance-event` | Trigger maintenance |
| POST | `/shops/{id}/relations/{relation}` | Sync relations |

---

## 8. Dashboard Analytics (`api/v1/dashboard`)

**Rate limit**: `throttle:analytics` (60 requests/min)

| Method | URI | Purpose |
|---|---|---|
| GET | `/dashboard/overview` | Overview stats |
| GET | `/dashboard/revenue` | Revenue analytics |
| GET | `/dashboard/order-stats` | Order statistics |
| GET | `/dashboard/recent-orders` | Recent orders |
| GET | `/dashboard/top-products` | Top products |
| GET | `/dashboard/category-stats` | Category stats |
| GET | `/dashboard/low-stock` | Low stock alerts |
| GET | `/dashboard/sales` | Sales analytics |
| GET | `/dashboard/customers` | Customer analytics |
| GET | `/dashboard/products` | Product analytics |
| GET | `/dashboard/orders` | Order analytics |
| GET | `/dashboard/categories` | Category analytics |
| GET | `/dashboard/coupons` | Coupon analytics |
| GET | `/dashboard/cart` | Cart analytics |
| GET | `/dashboard/finance` | Finance analytics |
| GET | `/dashboard/reconciliation` | Reconciliation data |

---

## 9. Roles & Permissions (`api/v1`)

| Method | URI | Purpose |
|---|---|---|
| GET | `/roles` | List roles |
| GET/POST | `/roles/{id}` | Show/Create |
| PUT/DELETE | `/roles/{id}` | Update/Delete |
| POST | `/users/{userId}/assign-role` | Assign role |
| POST | `/users/{userId}/remove-role` | Remove role |
| GET | `/permissions` | List permissions |
| POST | `/roles/{roleId}/permissions` | Assign to role |
| POST | `/users/{userId}/permissions` | Give permission |
| PUT | `/users/{userId}/permissions` | Sync permissions |
| DELETE | `/users/{userId}/permissions` | Remove permission |

---

## 10. Refunds (`api/v1`)

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/refunds` | List/Create |
| GET/PUT/DELETE | `/refunds/{id}` | Show/Update/Delete |
| GET/POST | `/refund-reasons` | List/Create |
| GET/PUT/DELETE | `/refund-reasons/{id}` | Show/Update/Delete |
| GET/POST | `/refund-policies` | List/Create |
| GET/PUT/DELETE | `/refund-policies/{id}` | Show/Update/Delete |

---

## 11. Messaging (`api/v1`)

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/conversations` | List/Create |
| GET | `/conversations/{id}` | Get conversation |
| GET/POST | `/messages/conversations/{id}` | List/Send messages |
| POST | `/messages/seen/{id}` | Mark as seen |

---

## 12. Notifications (`api/v1/admin`)

| Method | URI | Purpose |
|---|---|---|
| GET | `/admin/notifications` | List |
| GET | `/admin/notifications/unread` | Unread count |
| PATCH | `/admin/notifications/{id}/read` | Mark read |
| PATCH | `/admin/notifications/read-all` | Mark all read |
| DELETE | `/admin/notifications/{id}` | Delete one |
| DELETE | `/admin/notifications` | Delete all |

---

## 13. Payment Webhooks (`api/v1/webhooks`)

| Method | URI | Gateway |
|---|---|---|
| POST | `/webhooks/razorpay` | Razorpay |
| POST | `/webhooks/stripe` | Stripe |
| POST | `/webhooks/paypal` | PayPal |
| POST | `/webhooks/mollie` | Mollie |
| POST | `/webhooks/paystack` | Paystack |
| POST | `/webhooks/paymongo` | Paymongo |
| POST | `/webhooks/xendit` | Xendit |
| POST | `/webhooks/iyzico` | Iyzico |
| POST | `/webhooks/bkash` | Bkash |
| POST | `/webhooks/flutterwave` | Flutterwave |
| GET | `/callback/flutterwave` | Flutterwave callback |

---

## 14. Miscellaneous (`api/v1`)

| Method | URI | Purpose |
|---|---|---|
| GET | `/product-type` | Product type keys + translations |
| GET | `/check-card-payment` | Test card details |
| GET | `/enum-types` | All enum values |
| GET | `/types` | List types |
| GET | `/types/{id}` | Get type |
| GET/POST | `/attachments` | List/Create |
| GET/PUT/DELETE | `/attachments/{id}` | Show/Update/Delete |
| GET | `/delivery-times` | List delivery times |
| GET | `/delivery-times/{id}` | Get delivery time |
| GET/POST | `/authors` | List/Create |
| GET/PUT/DELETE | `/authors/{id}` | Show/Update/Delete |
| GET | `/top-authors` | Top authors |
| GET/POST | `/manufacturers` | List/Create |
| GET/PUT/DELETE | `/manufacturers/{id}` | Show/Update/Delete |
| GET | `/top-manufacturers` | Top manufacturers |

## 15. Downloads & Invoices (`api/v1`)

| Method | URI | Purpose |
|---|---|---|
| GET | `/downloads` | List downloadable files |
| POST | `/downloads/digital_file` | Generate download URL |
| GET | `/download_url/token/{token}` | Download file |
| GET | `/download-invoice/token/{token}` | Download invoice |

## 16. Store Notices (`api/v1`)

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/store-notices` | List/Create |
| GET/PUT/DELETE | `/store-notices/{id}` | Show/Update/Delete |
| GET | `/store-notices/getStoreNoticeType` | Types |
| GET | `/store-notices/getUsersToNotify` | Users |
| POST | `/store-notices/read` | Mark read |
| POST | `/store-notices/read-all` | Mark all read |

## 17. Wishlists (`api/v1`)

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/wishlists` | List/Add |
| DELETE | `/wishlists/{id}` | Remove |
| POST | `/wishlists/toggle` | Toggle |
| GET | `/wishlists/in_wishlist/{product_id}` | Check |
| GET | `/my-wishlists` | My wishlisted products |

## 18. Withdraws (`api/v1`)

| Method | URI | Purpose |
|---|---|---|
| GET/POST | `/withdraws` | List/Create |
| GET/PUT/DELETE | `/withdraws/{id}` | Show/Update/Delete |
| POST | `/approve-withdraw` | Approve |

---

## 19. Standard Response Format

### Success
```json
{
    "success": true,
    "message": "",
    "data": {},
    "meta": {
        "current_page": 1,
        "last_page": 10,
        "per_page": 15,
        "total": 150
    }
}
```

### Error
```json
{
    "success": false,
    "message": "Error description",
    "errors": {}
}
```

### HTTP Status Codes Used

| Code | Usage |
|---|---|
| 200 | Success |
| 201 | Created |
| 400 | Bad request / Validation error |
| 401 | Unauthenticated |
| 403 | Forbidden (permission denied) |
| 404 | Not found |
| 409 | Conflict (e.g., COD not available for pickup) |
| 422 | Validation failed |
| 429 | Rate limit exceeded |
| 500 | Server error |

---

## 20. Rate Limits

| Throttle | Limit | Applied To |
|---|---|---|
| `throttle:checkout` | Configurable | POST `/checkout` |
| `throttle:cart` | 20/min | Cart endpoints |
| `throttle:auth` | 10/min | Auth endpoints (register, login) |
| `throttle:sensitive` | 5/min | Password reset |
| `throttle:otp` | 3/min | OTP endpoints |
| `throttle:analytics` | 60/min | Dashboard endpoints |
