# Fast Shipping Channel — Frontend Integration Guide

> **Audience**: Frontend Developers  
> **Purpose**: Integrate the Fast Shipping feature into your application  
> **Version**: 1.1.0

---

## Table of Contents

1. [Feature Overview](#1-feature-overview)
2. [How Frontend Enables the Feature](#2-how-frontend-enables-the-feature)
3. [Switching Between Channels](#3-switching-between-channels)
4. [API Endpoints](#4-api-endpoints)
5. [Fast Shipping Status](#5-fast-shipping-status)
6. [Product Listing](#6-product-listing)
7. [Product Details](#7-product-details)
8. [Categories](#8-categories)
9. [Home Page](#9-home-page)
10. [Search](#10-search)
11. [Checkout](#11-checkout)
12. [Orders](#12-orders)
13. [UI Flow](#13-ui-flow)
14. [Error Handling](#14-error-handling)
15. [Best Practices](#15-best-practices)
16. [Complete Frontend Examples](#16-complete-frontend-examples)
17. [FAQ](#17-faq)

---

## 1. Feature Overview

### What It Does

Fast Shipping transforms the entire shopping experience to show only products eligible for same-day or next-day delivery. When a user enables Fast Shipping mode, every page — products, categories, search results, home page, and checkout — automatically adapts to show only what can be shipped quickly.

### Why It Exists

Not all products support fast delivery. Large furniture, custom items, or out-of-stock inventory may require standard shipping. Rather than mixing both types and letting the user discover which ones support fast shipping, this feature creates a dedicated fast-shipping browsing mode.

### When the Frontend Should Use It

- Show a Fast Shipping toggle on the home page, navigation bar, or product listing pages
- Enable it by default for returning users who previously selected it
- Disable it automatically when the service is outside operating hours
- Route to the correct checkout flow based on the active channel

### What Changes for the User

| Aspect | Home Channel | Fast Shipping Channel |
|---|---|---|---|
| Product catalog | Standard-shipping products only (fast-shipping excluded) | Only fast-shipping-eligible products |
| Search results | Standard-product matches only | Fast-shipping-only matches |
| Product detail page | Standard product info; 404 for fast-shipping products | ETA badge and fast checkout button |
| Checkout | Standard shipping options | Flat fee + delivery ETA |
| Order tracking | Standard status | Real-time ETA + fast shipping status |

---

## 2. How Frontend Enables the Feature

The feature is activated by sending a single HTTP header with every API request:

```
X-Channel: fast-shipping
```

### Accepted Values

| Value | Effect |
|---|---|---|
| `home` | Default. Returns only standard-shipping products (excludes fast-shipping). |
| `fast-shipping` | Filters all responses to fast-shipping-eligible data only. |

### What Happens If the Header Is Omitted

The API defaults to `home` mode. Only standard-shipping products are returned. Your application will behave as if Fast Shipping does not exist — fast-shipping products are hidden from view.

### Difference Between Channels

| Aspect | `home` | `fast-shipping` |
|---|---|---|---|
| Products | Only products with `is_fast_shipping_available = false` | Only products with `is_fast_shipping_available = true` |
| Categories | Same categories, counts reflect only standard products | Same categories, counts reflect only fast-shipping products |
| Flash Sales | Flash sales with standard products only | Flash sales containing at least one fast-shipping product |
| Search | Standard-product matches only | Fast-shipping-only results |
| Product Detail | Returns 404 for fast-shipping products | Returns 404 for non-fast-shipping products |
| Checkout | Standard checkout flow | Fast checkout with flat fee and ETA |
| Orders | All orders regardless of method | Only fast-shipping orders |

### Example Request

```
GET /api/general/products
X-Channel: fast-shipping
Accept: application/json
```

---

## 3. Switching Between Channels

The frontend should store the current channel globally and attach it to every API request.

### Global Channel State

Use a state management solution (React Context, Redux, Zustand, or a simple module-level variable) to hold the current channel.

### Axios — Global Header via Interceptor

```javascript
// api/client.js
import axios from 'axios';

const client = axios.create({
  baseURL: '/api/general',
  headers: { Accept: 'application/json' },
});

let currentChannel = localStorage.getItem('shipping_channel') || 'home';

// Attach channel to every request automatically
client.interceptors.request.use((config) => {
  config.headers['X-Channel'] = currentChannel;
  return config;
});

export function setChannel(channel) {
  currentChannel = channel;
  localStorage.setItem('shipping_channel', channel);
}

export function getChannel() {
  return currentChannel;
}

export default client;
```

### Axios — Per-Request Header

Use this approach when you only want to override the channel for a single request:

```javascript
import client from './api/client';

client.get('/products', {
  headers: { 'X-Channel': 'fast-shipping' },
});
```

### Fetch API — Global Wrapper

```javascript
// api/fetch.js
let currentChannel = localStorage.getItem('shipping_channel') || 'home';

export function setChannel(channel) {
  currentChannel = channel;
  localStorage.setItem('shipping_channel', channel);
}

export function getChannel() {
  return currentChannel;
}

async function apiFetch(path, options = {}) {
  const response = await fetch(`/api/general${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-Channel': currentChannel,
      ...options.headers,
    },
  });
  if (!response.ok) {
    const error = new Error('API Error');
    error.response = response;
    throw error;
  }
  return response.json();
}

export default apiFetch;
```

### Should the Header Be Global or Per Request?

**Global.** Once the user selects a channel, apply it to every subsequent API request. This ensures consistency — the user should never see mixed results (e.g., home products in a fast-shipping cart).

Exceptions where you might override per-request:
- Checking Fast Shipping status (channel-agnostic endpoint)
- Admin or dashboard pages that should always show all data

---

## 4. API Endpoints

### GET /general/fast-shipping/status

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | Check if fast shipping service is currently available and operational |
| **Required Header** | None (channel-agnostic) |

**Request:**
```
GET /api/general/fast-shipping/status
```

**Response:**
```json
{
  "enabled": true,
  "available": true,
  "fee": 5.99,
  "duration_minutes": 90,
  "opens_at": "08:00",
  "closes_at": "22:00",
  "available_again_at": null
}
```

**Notes:**
- Call this endpoint when the app starts and whenever the user opens Fast Shipping UI
- Cache the response for up to 60 seconds to avoid hammering the API
- See [Section 5](#5-fast-shipping-status) for detailed field explanations

---

### GET /general/products

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | List products in the current channel with pagination and filtering |
| **Required Header** | `X-Channel` (optional, defaults to `home`) |

**Query Parameters:**

| Param | Type | Required | Description |
|---|---|---|---|
| `limit` | number | No | Items per page (default: 15, max: 100) |
| `page` | number | No | Page number (default: 1) |
| `search` | string | No | Search term |
| `category` | string | No | Filter by category slug (comma-separated) |
| `brand` | string | No | Filter by brand slug (comma-separated) |
| `minPrice` | number | No | Minimum price |
| `maxPrice` | number | No | Maximum price |
| `order` | string | No | Sort order: `asc` or `desc` (default: `desc`) |

**Request:**
```
GET /api/general/products?limit=20&page=1&category=electronics
X-Channel: fast-shipping
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Wireless Headphones",
      "slug": "wireless-headphones",
      "price": 89.99,
      "current_price": 89.99,
      "is_fast_shipping_available": true,
      "quantity": 42,
      "has_variants": false,
      "has_discount": false,
      "discount_valid": false,
      "ratings": 4.5,
      "image": {
        "thumbnail": "https://cdn.example.com/products/1/thumb.jpg",
        "original": ["https://cdn.example.com/products/1/img1.jpg"]
      }
    },
    {
      "id": 2,
      "name": "Bluetooth Speaker",
      "slug": "bluetooth-speaker",
      "price": 49.99,
      "current_price": 39.99,
      "is_fast_shipping_available": true,
      "quantity": 18,
      "has_variants": true,
      "has_discount": true,
      "discount_valid": true,
      "discount_type": "percentage",
      "discount_amount": 20,
      "ratings": 4.2,
      "image": {
        "thumbnail": "https://cdn.example.com/products/2/thumb.jpg",
        "original": ["https://cdn.example.com/products/2/img1.jpg"]
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 93,
    "from": 1,
    "to": 20
  }
}
```

**Notes:**
- When `X-Channel: fast-shipping`, only products with `is_fast_shipping_available: true` are returned
- When `X-Channel: home`, only products with `is_fast_shipping_available: false` are returned
- The `is_fast_shipping_available` field is for UI display (badges, labels) — do NOT use it for client-side filtering
- Products without discounts have `has_discount: false` and omit discount fields

---

### GET /general/products/{slug}

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | Get a single product's full details |
| **Required Header** | `X-Channel` |

**Request:**
```
GET /api/general/products/wireless-headphones
X-Channel: fast-shipping
```

**Response (Fast-Shipping-Eligible Product):**
```json
{
  "data": {
    "id": 1,
    "name": "Wireless Headphones",
    "slug": "wireless-headphones",
    "description": "High-quality wireless headphones with noise cancellation.",
    "price": 89.99,
    "current_price": 89.99,
    "price_after_discount": null,
    "price_after_flash_sale": null,
    "is_fast_shipping_available": true,
    "in_stock": true,
    "quantity": 42,
    "sold_quantity": 156,
    "sku": "PRD-001",
    "status": true,
    "product_type": "simple",
    "has_discount": false,
    "has_flash_sale": false,
    "height": 15,
    "width": 10,
    "length": 8,
    "weight": 0.3,
    "image": {
      "thumbnail": "https://...",
      "original": ["https://..."]
    },
    "categories": [
      { "id": 1, "name": "Electronics", "slug": "electronics" }
    ],
    "related_products": [
      {
        "id": 3,
        "name": "Earbuds",
        "slug": "earbuds",
        "price": 29.99,
        "is_fast_shipping_available": false
      }
    ]
  }
}
```

**Error Responses:**

**Non-Fast-Shipping Product Under Fast-Shipping Channel:**
```json
{
  "message": "Product not found.",
  "success": false
}
```
Status: **404 Not Found**

**Fast-Shipping Product Under Home Channel:**
```json
{
  "message": "Product not found.",
  "success": false
}
```
Status: **404 Not Found**

**Notes:**
- If a product does not match the current channel, the API returns 404
- `home` channel: fast-shipping products return 404
- `fast-shipping` channel: non-fast-shipping products return 404
- Handle 404 gracefully — show a message and suggest switching channels
- `related_products` may contain products from either shipping type (they are informational)

---

### GET /general/categories

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | List all categories with hierarchical structure and product counts |
| **Required Header** | `X-Channel` |

**Request:**
```
GET /api/general/categories
X-Channel: fast-shipping
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Electronics",
      "slug": "electronics",
      "products_count": 15,
      "image": {
        "thumbnail": "https://...",
        "original": ["https://..."]
      },
      "children": [
        {
          "id": 2,
          "name": "Headphones",
          "slug": "headphones",
          "products_count": 5,
          "image": {
            "thumbnail": "https://...",
            "original": ["https://..."]
          }
        }
      ]
    }
  ]
}
```

**Notes:**
- The category tree structure is identical regardless of channel
- `products_count` reflects only fast-shipping-eligible products when the channel is `fast-shipping`
- Categories with `products_count: 0` still appear in the tree — the frontend can choose to hide them

---

### GET /general/flash-sales

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | List active flash sales with their products |
| **Required Header** | `X-Channel` |

**Request:**
```
GET /api/general/flash-sales
X-Channel: fast-shipping
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Weekend Deal",
      "slug": "weekend-deal",
      "start_date": "2026-07-07T00:00:00Z",
      "end_date": "2026-07-09T23:59:59Z",
      "image": {
        "desktop": "https://...",
        "mobile": "https://..."
      },
      "products": [
        {
          "id": 10,
          "name": "Bluetooth Speaker",
          "price": 29.99,
          "is_fast_shipping_available": true
        }
      ]
    }
  ]
}
```

**Notes:**
- Only flash sales containing at least one fast-shipping product appear under the fast-shipping channel
- Products within flash sales are also filtered to fast-shipping-eligible only

---

### GET /general/banners

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | List active promotional banners with linked products |
| **Required Header** | `X-Channel` |

**Request:**
```
GET /api/general/banners
X-Channel: fast-shipping
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "Summer Sale",
      "description": "Get the best deals this summer",
      "status": true,
      "image": {
        "desktop": "https://...",
        "mobile": "https://..."
      },
      "products": [
        {
          "id": 5,
          "name": "Sunglasses",
          "is_fast_shipping_available": true
        }
      ]
    }
  ]
}
```

**Notes:**
- Banners that have no fast-shipping products still appear, but their `products` array will be empty

---

### GET /general/brands

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | List brands with their products |
| **Required Header** | `X-Channel` |

**Request:**
```
GET /api/general/brands
X-Channel: fast-shipping
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "TechBrand",
      "slug": "techbrand",
      "status": true,
      "image": {
        "desktop": "https://...",
        "mobile": "https://..."
      },
      "products": [
        {
          "id": 1,
          "name": "Wireless Headphones",
          "is_fast_shipping_available": true
        }
      ]
    }
  ]
}
```

---

### GET /general/sliders

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | List active sliders (carousel items) with linked products |
| **Required Header** | `X-Channel` |

**Request:**
```
GET /api/general/sliders
X-Channel: fast-shipping
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "New Arrivals",
      "status": true,
      "image": {
        "desktop": "https://...",
        "mobile": "https://..."
      },
      "products": [
        {
          "id": 20,
          "name": "Smart Watch",
          "is_fast_shipping_available": true
        }
      ]
    }
  ]
}
```

---

### GET /general/coupons

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | List available coupons |
| **Required Header** | `X-Channel` |

**Request:**
```
GET /api/general/coupons
X-Channel: fast-shipping
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "WELCOME10",
      "slug": "welcome10",
      "image": {
        "desktop": "https://...",
        "mobile": "https://..."
      },
      "borderColor": "#ff6600",
      "borderless": false
    }
  ]
}
```

**Notes:**
- Coupons are channel-agnostic — the same coupons are available regardless of channel

---

### GET /general/search

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | Search products, categories, shops, and brands |
| **Required Header** | `X-Channel` |

**Query Parameters:**

| Param | Type | Required | Description |
|---|---|---|---|
| `search` | string | Yes | Search term |
| `limit` | number | No | Results per section (default: 15) |

**Request:**
```
GET /api/general/search?search=wireless&limit=10
X-Channel: fast-shipping
```

**Current Response:**
```json
null
```

**Notes:**
- This endpoint currently returns `null` (the search implementation is pending)
- Search results are automatically scoped to the current channel once implemented
- The frontend does NOT perform any client-side filtering

---

### GET /general/home

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | None |
| **Purpose** | Get all home page data (sections, banners, products) |
| **Required Header** | `X-Channel` |

**Request:**
```
GET /api/general/home
X-Channel: fast-shipping
```

**Response:**
```json
{
  "data": {
    "sliders": [...],
    "dailyOffers": { ... },
    "bestCategories": [...],
    "discountProductsEndToday": [...],
    "banners": [...],
    "brands": [...],
    "parent_categories": [...],
    "coupons": [...],
    "flashSaleProducts": [...],
    "parentCategories": [...],
    "weeklyProducts": [...],
    "allDiscountProducts": [...],
    "newArrivals": [...]
  }
}
```

**Notes:**
- Every section is automatically filtered based on the `X-Channel` header
- Sections containing products (flash sales, new arrivals, discounts, weekly products) will show only fast-shipping-eligible products
- Sections that don't contain products (brands, coupons, parent categories) are unchanged

---

### POST /checkout

| Field | Value |
|---|---|
| **Method** | POST |
| **Authentication** | Required (Bearer token) |
| **Purpose** | Place a standard scheduled-delivery order |
| **Required Header** | `X-Channel: home` |

**Request:**
```
POST /api/general/checkout
X-Channel: home
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
Content-Type: application/json

{
  "name": "John Doe",
  "user_phone": "+201234567890",
  "user_email": "john@example.com",
  "address": "123 Main St, Cairo",
  "notes": "Leave at door",
  "selected_promotion_id": null,
  "selected_gift_product_id": null
}
```

**Response:**
```json
{
  "data": {
    "id": 1023,
    "status": "pending",
    "total_price": 89.99,
    "created_at": "2026-07-07T10:30:00Z"
  }
}
```

**Notes:**
- Use this endpoint when the current channel is `home`
- The cart is managed server-side — you do not send cart items in the request body

---

### POST /checkout/fast

| Field | Value |
|---|---|
| **Method** | POST |
| **Authentication** | Required (Bearer token) |
| **Purpose** | Place a fast-shipping order with flat fee and ETA |
| **Required Header** | `X-Channel: fast-shipping` |

**Request:**
```
POST /api/general/checkout/fast
X-Channel: fast-shipping
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
Content-Type: application/json

{
  "governorate_id": 5,
  "name": "John Doe",
  "user_phone": "+201234567890",
  "user_email": "john@example.com",
  "address": "123 Main St, Cairo",
  "notes": "Leave at door",
  "selected_promotion_id": null,
  "selected_gift_product_id": null
}
```

**Response:**
```json
{
  "data": {
    "id": 1024,
    "status": "pending",
    "shipping_method": "fast",
    "expected_delivery_at": "2026-07-08T14:00:00Z",
    "fast_shipping_fee": 5.99,
    "total_price": 95.98,
    "created_at": "2026-07-07T10:30:00Z"
  }
}
```

**Notes:**
- Use this endpoint when the current channel is `fast-shipping`
- Requires a `governorate_id` for delivery zone calculation
- Response includes `expected_delivery_at` — display this as the delivery ETA
- Response includes `fast_shipping_fee` — display this in the order summary

---

### GET /general/fast-shipping/orders

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | Required (Bearer token) |
| **Purpose** | List the authenticated user's fast-shipping orders only |
| **Required Header** | `X-Channel: fast-shipping` |

**Request:**
```
GET /api/general/fast-shipping/orders?limit=10
X-Channel: fast-shipping
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
```

**Response:**
```json
{
  "data": [
    {
      "id": 1024,
      "status": "pending",
      "shipping_method": "fast",
      "expected_delivery_at": "2026-07-08T14:00:00Z",
      "fast_shipping_fee": 5.99,
      "total_price": 95.98,
      "created_at": "2026-07-07T10:30:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 10,
    "total": 1
  }
}
```

**Notes:**
- This endpoint returns ONLY fast-shipping orders
- For the full order history (all shipping methods), use `GET /general/orders`

---

### GET /general/orders

| Field | Value |
|---|---|
| **Method** | GET |
| **Authentication** | Required (Bearer token) |
| **Purpose** | List all orders for the authenticated user (all shipping methods) |
| **Required Header** | `X-Channel` |

**Request:**
```
GET /api/general/orders
X-Channel: home
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
```

**Response:**
```json
{
  "data": [
    {
      "id": 1024,
      "shipping_method": "fast",
      "expected_delivery_at": "2026-07-08T14:00:00Z",
      "status": "pending",
      "total_price": 95.98
    },
    {
      "id": 1023,
      "shipping_method": "scheduled",
      "status": "completed",
      "total_price": 89.99
    }
  ]
}
```

**Notes:**
- Use the `shipping_method` field to distinguish fast (`fast`) from standard (`scheduled`) orders
- Fast shipping orders include `expected_delivery_at` — show it as the delivery ETA
- This endpoint is channel-independent — it returns all orders regardless of the `X-Channel` header

---

## 5. Fast Shipping Status

### How to Check

Call the status endpoint when the app starts and whenever the user is about to interact with Fast Shipping UI (e.g., opening the toggle, entering checkout).

```javascript
import client from './api/client';

export async function checkFastShippingStatus() {
  try {
    const { data } = await client.get('/fast-shipping/status');
    return data;
  } catch (error) {
    // If the endpoint fails, assume service is unavailable
    return {
      enabled: false,
      available: false,
      fee: 0,
      duration_minutes: 0,
      opens_at: null,
      closes_at: null,
      available_again_at: null,
    };
  }
}
```

### Field Reference

#### `enabled` (boolean)

Whether the Fast Shipping feature is turned on globally. When `false`, the backend is in maintenance mode.

**UI behavior:**
- Hide the Fast Shipping toggle entirely
- Show a message: "Fast Shipping is currently unavailable"
- Do not allow switching to fast-shipping channel

```javascript
if (!status.enabled) {
  return <DisabledBanner message="Fast Shipping is temporarily unavailable." />;
}
```

#### `available` (boolean)

Whether fast shipping can be used right now. This considers operating hours, current capacity, and other runtime conditions.

**UI behavior:**
- If `false` but `enabled` is `true`, show the toggle as disabled
- Show the next available time using `available_again_at` or `opens_at`
- Do not allow switching to fast-shipping channel

```javascript
if (!status.available) {
  return (
    <DisabledBanner>
      Fast Shipping is currently unavailable.
      {status.available_again_at
        ? ` Available again at ${formatTime(status.available_again_at)}.`
        : ` Opens at ${status.opens_at}.`}
    </DisabledBanner>
  );
}
```

#### `fee` (number)

The flat fee added to every fast shipping order, in local currency.

**UI behavior:**
- Display in the Fast Shipping toggle label: "Fast Shipping — 90 min (+$5.99)"
- Display in checkout summary: "Fast Shipping Fee: $5.99"
- Display in order details: "Fast Shipping Fee: $5.99"

#### `duration_minutes` (number)

Estimated delivery time in minutes from order placement.

**UI behavior:**
- Display on the toggle: "Delivery in ~90 minutes"
- Display on product cards: "Get it in ~90 min"
- Display on checkout: "Expected delivery in ~90 minutes"

```javascript
function formatDuration(minutes) {
  if (minutes < 60) return `${minutes} minutes`;
  const hours = Math.floor(minutes / 60);
  const mins = minutes % 60;
  return mins ? `~${hours}h ${mins}m` : `~${hours} hours`;
}
```

#### `opens_at` (string, HH:mm format)

The time the service opens today (24-hour format).

**UI behavior:**
- Show when unavailable: "Opens at 08:00"
- Can be used for a countdown timer

#### `closes_at` (string, HH:mm format)

The time the service closes today (24-hour format).

**UI behavior:**
- Show on the toggle: "Available until 22:00"
- Show when nearing closing: "Order within 2 hours for Fast Shipping"

#### `available_again_at` (string, ISO 8601 or null)

The next date/time when the service will be available, if currently unavailable.

**UI behavior:**
- Show when unavailable: "Available again at 8:00 AM tomorrow"
- Can be used for a countdown timer
- If `null` and not available, show "Check back later"

---

## 6. Product Listing

### Under Home Channel

```
GET /api/general/products
X-Channel: home
```

Returns **only** standard-shipping products (`is_fast_shipping_available: false`). Fast-shipping products are automatically excluded:

```json
{
  "data": [
    { "id": 2, "name": "Large Desk", "is_fast_shipping_available": false }
  ],
  "meta": { "total": 1 }
}
```

**UI note:** Since fast-shipping products are excluded from the home channel, the `is_fast_shipping_available` field will always be `false` in this mode. Do not rely on this field to detect fast-shipping eligibility — switch to the `fast-shipping` channel instead.

### Under Fast Shipping Channel

```
GET /api/general/products
X-Channel: fast-shipping
```

Returns **only** products where `is_fast_shipping_available` is `true`. The API handles all filtering:

```json
{
  "data": [
    { "id": 1, "name": "Wireless Headphones", "is_fast_shipping_available": true },
    { "id": 3, "name": "Bluetooth Speaker", "is_fast_shipping_available": true }
  ],
  "meta": { "total": 2 }
}
```

### What the Frontend Should NOT Do

**BAD** — Do NOT filter the array returned by the API:
```javascript
// WRONG — the backend already filtered
const filtered = response.data.filter(p => p.is_fast_shipping_available);
```

**BAD** — Do NOT hide products based on `is_fast_shipping_available`:
```javascript
// WRONG — redundant, backend handles this
if (!product.is_fast_shipping_available) return null;
```

**GOOD** — Just change the header and re-fetch:
```javascript
// CORRECT
setChannel('fast-shipping');
const { data } = await client.get('/products');
// data is already correct
```

---

## 7. Product Details

### Fast-Shipping Product

When the product has `is_fast_shipping_available: true`:

- This product is visible **only** in the `fast-shipping` channel
- In `home` channel, the API returns **404** — the product is not accessible
- Show a green "Fast Shipping" badge
- Show estimated delivery: "Delivered in ~90 minutes"
- Show the fast shipping fee: "+$5.99 shipping fee"
- Enable the "Fast Checkout" button (visible only when channel is `fast-shipping`)

```jsx
function ProductDetail({ product, channel }) {
  return (
    <div className="product-detail">
      <h1>{product.name}</h1>
      <p className="price">${product.current_price}</p>

      {product.is_fast_shipping_available && (
        <div className="fast-shipping-info">
          <span className="badge badge--fast">Fast Shipping Eligible</span>
          <p className="eta">Get it by tomorrow (~90 min delivery)</p>
        </div>
      )}

      {channel === 'fast-shipping' ? (
        <button className="btn btn--fast">Fast Checkout</button>
      ) : (
        <button className="btn btn--standard">Add to Cart</button>
      )}
    </div>
  );
}
```

### Non-Fast-Shipping Product

When the product has `is_fast_shipping_available: false`:

- Show a gray "Standard Shipping" label
- Hide the fast checkout button
- Show standard delivery estimates
- If the user is in `fast-shipping` channel, the API returns **404** (product is not visible at all)
- If the user is in `home` channel, the product is returned normally

### Handling 404 Responses

```javascript
import { useParams } from 'react-router-dom';
import { useContext } from 'react';
import { ChannelContext } from '../context/ChannelContext';
import client from '../api/client';

export default function ProductPage() {
  const { slug } = useParams();
  const { channel, setChannel } = useContext(ChannelContext);
  const [product, setProduct] = useState(null);
  const [notFound, setNotFound] = useState(false);

  useEffect(() => {
    async function load() {
      try {
        const { data } = await client.get(`/products/${slug}`);
        setProduct(data);
        setNotFound(false);
      } catch (error) {
        if (error.response?.status === 404) {
          setNotFound(true);
        }
      }
    }
    load();
  }, [slug, channel]);

  if (notFound) {
    const isFastChannel = channel === 'fast-shipping';
    return (
      <div className="product-not-found">
        <h2>Product Not Available in This Channel</h2>
        <p>
          {isFastChannel
            ? 'This product is not available for Fast Shipping. Switch to Standard Shipping to view it.'
            : 'This is a Fast Shipping product. Switch to Fast Shipping to view it.'}
        </p>
        <button onClick={() => setChannel(isFastChannel ? 'home' : 'fast-shipping')}>
          Switch to {isFastChannel ? 'Standard Shipping' : 'Fast Shipping'}
        </button>
      </div>
    );
  }

  if (!product) return <Loading />;

  return <ProductDetail product={product} channel={channel} />;
}
```

---

## 8. Categories

### How Category Pages Behave

Category pages work identically to product listing pages. The `X-Channel` header controls what data is returned.

**Request:**
```
GET /api/general/categories/electronics
X-Channel: fast-shipping
```

**Behavior:**

| Aspect | `home` | `fast-shipping` |
|---|---|---|
| Category tree | Same structure | Same structure |
| `products_count` | All products in category | Only fast-shipping products |
| Products within category | All active products | Only fast-shipping-eligible |

**Hiding Empty Categories**

Under fast shipping, some categories may have `products_count: 0` because none of their products are fast-shipping-eligible. The frontend can choose to hide these:

```javascript
function CategoryGrid({ categories, channel }) {
  const visible = channel === 'fast-shipping'
    ? categories.filter(cat => cat.products_count > 0)
    : categories;

  return (
    <div className="category-grid">
      {visible.map(cat => (
        <CategoryCard key={cat.id} category={cat} />
      ))}
    </div>
  );
}
```

**Alternative UI:** Show all categories but dim the empty ones:

```javascript
function CategoryCard({ category, channel }) {
  const isEmpty = channel === 'fast-shipping' && category.products_count === 0;

  return (
    <div className={`category-card ${isEmpty ? 'category-card--empty' : ''}`}>
      <h3>{category.name}</h3>
      <span className="count">{category.products_count} products</span>
      {isEmpty && <span className="label">No fast shipping available</span>}
    </div>
  );
}
```

---

## 9. Home Page

### How Sections Automatically Change

When the user switches to Fast Shipping, every section on the home page automatically updates because all API calls include the `X-Channel: fast-shipping` header.

### Section-by-Section Behavior

| Home Section | Under `home` | Under `fast-shipping` |
|---|---|---|---|
| **Sliders** | All active sliders (products within filtered to standard only) | All active sliders (products within filtered to fast only) |
| **Flash Sales** | Flash sales with standard products only | Only flash sales with fast-shipping products |
| **Best Categories** | Top categories (counts reflect standard products) | Same categories, counts reflect fast shipping |
| **Discount Products Ending Today** | Only standard discounted products | Only fast-shipping discounted products |
| **Banners** | All active banners (products within filtered to standard only) | Banners with empty product arrays if no fast products |
| **Brands** | All brands (products within filtered to standard only) | Unchanged |
| **Coupons** | All coupons | Unchanged |
| **Flash Sale Products** | Only standard flash sale products | Only fast-shipping flash sale products |
| **Weekly Products** | Only standard weekly products | Only fast-shipping weekly products |
| **All Discount Products** | Only standard discount products | Only fast-shipping discount products |
| **New Arrivals** | Only standard new arrivals | Only fast-shipping new arrivals |

### Implementation

```javascript
// pages/HomePage.jsx
import { useContext, useEffect, useState } from 'react';
import { ChannelContext } from '../context/ChannelContext';
import client from '../api/client';

export default function HomePage() {
  const { channel } = useContext(ChannelContext);
  const [homeData, setHomeData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    loadHomePage();
  }, [channel]); // Re-fetch when channel changes

  async function loadHomePage() {
    setLoading(true);
    try {
      const { data } = await client.get('/home');
      setHomeData(data);
    } catch (error) {
      console.error('Failed to load home page', error);
    }
    setLoading(false);
  }

  if (loading) return <PageSkeleton />;

  return (
    <div className="home-page">
      <SliderSection sliders={homeData.sliders} />
      <FlashSaleSection flashSales={homeData.dailyOffers} />
      <CategorySection categories={homeData.bestCategories} />
      <DiscountSection products={homeData.discountProductsEndToday} />
      <BannerSection banners={homeData.banners} />
      <NewArrivalsSection products={homeData.newArrivals} />
    </div>
  );
}
```



## 11. Checkout

There are two separate checkout endpoints. The frontend should route to the correct one based on the current channel.

### Standard Checkout

| Field | Value |
|---|---|
| **Endpoint** | `POST /api/general/checkout` |
| **Channel** | `home` |
| **Auth** | Required (Bearer token) |
| **When to use** | User is in `home` mode |

### Fast Shipping Checkout

| Field | Value |
|---|---|
| **Endpoint** | `POST /api/general/checkout/fast` |
| **Channel** | `fast-shipping` |
| **Auth** | Required (Bearer token) |
| **When to use** | User is in `fast-shipping` mode |

### Implementation

```javascript
// services/checkout.js
import client from '../api/client';
import { getChannel } from '../api/client';

export async function placeOrder(orderData) {
  const channel = getChannel();
  const endpoint = channel === 'fast-shipping'
    ? '/checkout/fast'
    : '/checkout';

  const { data } = await client.post(endpoint, orderData);
  return data;
}
```

### Checkout Form Differences

| Field | Standard Checkout | Fast Shipping Checkout |
|---|---|---|
| `governorate_id` | Not required | Required |
| `name` | Required | Required |
| `user_phone` | Required | Required |
| `user_email` | Required | Required |
| `address` | Required | Required |
| `notes` | Optional | Optional |
| `selected_promotion_id` | Optional | Optional |
| `selected_gift_product_id` | Optional | Optional |

### UI Recommendation

```jsx
function CheckoutPage() {
  const { channel } = useContext(ChannelContext);

  async function handleSubmit(formData) {
    const response = await placeOrder(formData);
    // Handle success
  }

  return (
    <div className="checkout-page">
      {channel === 'fast-shipping' && (
        <CheckoutBanner
          fee={fastShippingStatus.fee}
          eta={fastShippingStatus.duration_minutes}
        />
      )}

      <CheckoutForm
        showGovernorateField={channel === 'fast-shipping'}
        onSubmit={handleSubmit}
      />
    </div>
  );
}
```

---

## 12. Orders

### Fast Shipping Orders Only

Use this endpoint to show a dedicated "Fast Shipping Orders" section or page:

```
GET /api/general/fast-shipping/orders
X-Channel: fast-shipping
Authorization: Bearer <token>
```

### All Orders

Use this endpoint for the main order history page. Fast shipping orders are distinguished by the `shipping_method` field:

```
GET /api/general/orders
X-Channel: home
Authorization: Bearer <token>
```

### Response Fields for Fast Shipping Orders

| Field | Type | Description |
|---|---|---|
| `id` | number | Order ID |
| `status` | string | Order status: `pending`, `processing`, `shipped`, `delivered`, `cancelled` |
| `shipping_method` | string | Always `"fast"` for fast shipping orders |
| `expected_delivery_at` | string (ISO 8601) | Estimated delivery date/time |
| `fast_shipping_fee` | number | The flat fee charged |
| `total_price` | number | Order total including fee |
| `created_at` | string (ISO 8601) | When the order was placed |

### UI Example

```jsx
function OrderCard({ order }) {
  const isFastShipping = order.shipping_method === 'fast';

  return (
    <div className={`order-card ${isFastShipping ? 'order-card--fast' : ''}`}>
      <div className="order-header">
        <span className="order-id">#{order.id}</span>
        {isFastShipping && <span className="badge badge--fast">Fast Shipping</span>}
        <span className="order-status">{order.status}</span>
      </div>

      <div className="order-details">
        <span className="order-total">${order.total_price}</span>

        {isFastShipping && order.expected_delivery_at && (
          <div className="order-eta">
            <span>Expected delivery:</span>
            <strong>{formatDate(order.expected_delivery_at)}</strong>
          </div>
        )}

        {isFastShipping && (
          <div className="order-fee">
            <span>Shipping fee: ${order.fast_shipping_fee}</span>
          </div>
        )}
      </div>

      <div className="order-date">
        {formatDate(order.created_at)}
      </div>
    </div>
  );
}
```

---

## 13. UI Flow

```
                        +---------------------------------------+
                        |       APPLICATION OPENS               |
                        +------------------+--------------------+
                                           |
                                           v
                        +---------------------------------------+
                        |    GET /fast-shipping/status          |
                        |    (check availability)               |
                        +------------------+--------------------+
                                           |
                                           v
                              +------------+------------+
                              |                         |
                          +---v---+               +---v---+
                          | YES   |               | NO    |
                          +---+---+               +---+---+
                              |                       |
                              v                       v
                    +-------------------+    +-----------------------+
                    |   available?      |    | Hide Fast Shipping    |
                    +---+-------+-------+    | Toggle                |
                        |       |            | Show "Unavailable"    |
                    +---v---+ +---v---+      | message               |
                    | YES   | | NO    |      +-----------------------+
                    +---+---+ +---+---+
                        |       |
                        v       v
                 +-----------+ +--------------------------+
                 | Show      | | Show "Opens at" msg      |
                 | Toggle    | | Show countdown to        |
                 | Active    | | available_again_at       |
                 +-----+-----+ +--------------------------+
                       |
                       v
        +----------------------------------------------+
        |         USER ENABLES FAST SHIPPING           |
        |                                              |
        |  1. Save to localStorage                     |
        |  2. Set global channel = 'fast-shipping'     |
        |  3. Attach X-Channel header to all requests  |
        +----------------------+-----------------------+
                               |
                               v
        +----------------------------------------------+
        |         RELOAD CURRENT PAGE DATA              |
        |                                              |
        |  - Home -> re-fetch all sections             |
        |  - Products -> re-fetch filtered list        |
        |  - Categories -> re-fetch with new counts    |
        |  - Search -> re-fetch scoped results         |
        +----------------------+-----------------------+
                               |
                               v
        +----------------------------------------------+
        |       USER BROWSES FAST SHIPPING PRODUCTS    |
        |       Adds items to cart                     |
        |                                              |
        |  - Products show ETA badge                   |
        |  - Categories show filtered counts           |
        |  - Cart contains only fast-shipping items    |
        +----------------------+-----------------------+
                               |
                               v
        +----------------------------------------------+
        |       USER PROCEEDS TO CHECKOUT               |
        |                                              |
        |  Channel = 'fast-shipping'?                  |
        |    +-- YES -> POST /checkout/fast             |
        |    |          Requires governorate_id         |
        |    |          Flat fee applied                |
        |    |          ETA calculated                  |
        |    |                                          |
        |    +-- NO  -> POST /checkout                  |
        |               Standard shipping               |
        +----------------------+-----------------------+
                               |
                               v
        +----------------------------------------------+
        |          ORDER CONFIRMATION                   |
        |                                              |
        |  - Show order ID and status                   |
        |  - Show ETA (fast shipping) or standard info  |
        |  - Show total including fee                   |
        |  - Navigate to order tracking                 |
        +----------------------------------------------+
```

### State Machine

```
States:
  IDLE              -> App loaded, no channel decision yet
  CHECKING_STATUS   -> Calling /fast-shipping/status
  HOME              -> Channel = home, all products visible
  FAST_SHIPPING     -> Channel = fast-shipping, filtered catalog
  CHECKOUT          -> User in checkout flow
  ORDER_CONFIRMED   -> Order placed successfully

Transitions:
  IDLE -> CHECKING_STATUS (on app startup)
  CHECKING_STATUS -> HOME (status check complete, service enabled or not)
  CHECKING_STATUS -> HOME (status check failed, fallback to home)
  HOME -> FAST_SHIPPING (user toggles on, service available)
  FAST_SHIPPING -> HOME (user toggles off)
  HOME -> CHECKOUT (user clicks checkout)
  FAST_SHIPPING -> CHECKOUT (user clicks checkout, all items eligible)
  CHECKOUT -> ORDER_CONFIRMED (order placed)
  CHECKOUT -> HOME (user cancels checkout)
  ORDER_CONFIRMED -> HOME (user navigates away)
  ORDER_CONFIRMED -> FAST_SHIPPING (if channel persisted)
```

---

## 14. Error Handling

### 400 Invalid Header

```json
{
  "message": "Invalid channel value. Accepted values: home, fast-shipping.",
  "success": false
}
```

**Cause:** The `X-Channel` header contains a value that is not `home` or `fast-shipping`.

**Frontend handling:**
```javascript
if (error.response?.status === 400) {
  // This should never happen if you only send 'home' or 'fast-shipping'
  console.error('Invalid channel value. Falling back to home.', error);
  setChannel('home');
  showToast({
    type: 'error',
    message: 'Something went wrong. Switched to standard view.',
  });
}
```

**Prevention:** Only send `"home"` or `"fast-shipping"` as the header value:
```javascript
export const CHANNELS = {
  HOME: 'home',
  FAST_SHIPPING: 'fast-shipping',
};
```

### 401 Unauthorized

```json
{
  "message": "Unauthenticated."
}
```

**Cause:** The endpoint requires authentication but no valid token was provided.

**Frontend handling:**
```javascript
if (error.response?.status === 401) {
  redirectToLogin();
  showToast({
    type: 'warning',
    message: 'Please log in to continue.',
  });
}
```

**Affects:** Checkout endpoints, order history endpoints.

### 403 Forbidden

```json
{
  "message": "This action is unauthorized."
}
```

**Cause:** The user does not have permission to perform the action.

**Frontend handling:**
```javascript
if (error.response?.status === 403) {
  showToast({
    type: 'error',
    message: 'You do not have permission to perform this action.',
  });
}
```

### 404 Product Not Found

```json
{
  "message": "Product not found.",
  "success": false
}
```

**Cause:** The product does not exist OR the product exists but is not fast-shipping-eligible and the current channel is `fast-shipping`.

**Frontend handling:**
```javascript
if (error.response?.status === 404) {
  if (getChannel() === 'fast-shipping') {
    showToast({
      type: 'info',
      message: 'This product is not available for Fast Shipping.',
      action: {
        label: 'Switch to Standard',
        onClick: () => setChannel('home'),
      },
      duration: 8000,
    });
  } else {
    showToast({ type: 'error', message: 'Product not found.' });
    navigate('/products');
  }
}
```

### Fast Shipping Unavailable (runtime)

```javascript
async function initiateCheckout() {
  const status = await checkFastShippingStatus();

  if (!status.available) {
    showModal({
      title: 'Fast Shipping Unavailable',
      message: 'Fast Shipping is currently unavailable. It will be available again at ' +
        (status.available_again_at || status.opens_at) + '.',
      actions: [
        { label: 'Switch to Standard', onClick: () => setChannel('home') },
        { label: 'Cancel', variant: 'secondary' },
      ],
    });
    return;
  }

  navigate('/checkout');
}
```

### Service Disabled (global maintenance)

```javascript
if (!status.enabled) {
  showBanner({
    type: 'info',
    message: 'Fast Shipping is temporarily unavailable. We will notify you when it returns.',
  });
}
```

### Working Hours Closed

```javascript
if (!status.available && !status.available_again_at) {
  showBanner({
    type: 'info',
    message: 'Fast Shipping is currently closed (available ' + status.opens_at + ' - ' + status.closes_at + ').',
    closable: true,
  });
}
```

---

## 15. Best Practices

### Store Current Channel Globally

Use a state management solution (React Context, Redux, Zustand, or a simple module-level variable) to hold the current channel. Every component that makes API calls should read from this single source of truth.

```javascript
// context/ChannelContext.jsx
import { createContext, useState, useEffect } from 'react';

export const ChannelContext = createContext();

export function ChannelProvider({ children }) {
  const [channel, setChannelState] = useState(() => {
    return localStorage.getItem('shipping_channel') || 'home';
  });

  const setChannel = (newChannel) => {
    setChannelState(newChannel);
    localStorage.setItem('shipping_channel', newChannel);
  };

  return (
    <ChannelContext.Provider value={{ channel, setChannel }}>
      {children}
    </ChannelContext.Provider>
  );
}
```

### Use Axios Interceptors

Attach the channel header automatically to every request -- never manually add it per-call:

```javascript
client.interceptors.request.use((config) => {
  config.headers['X-Channel'] = getChannel();
  const token = getAuthToken();
  if (token) {
    config.headers['Authorization'] = 'Bearer ' + token;
  }
  return config;
});
```

### Don't Duplicate Backend Logic

The backend is the authority on which products are fast-shipping-eligible. Do not re-implement this logic on the frontend.

| Do NOT do this | Do this instead |
|---|---|
| `products.filter(p => p.is_fast_shipping_available)` | Change `X-Channel` header and re-fetch |
| `if (!product.is_fast_shipping_available) hideButton()` | Let the backend decide what to return |
| Client-side search filtering | Just send `X-Channel: fast-shipping` |

### Don't Filter Products Manually

Never filter the product array returned by the API. If you need a different set of products, change the `X-Channel` header and re-fetch.

### Always Rely on Backend Responses

The `is_fast_shipping_available` field is for display purposes (badges, labels). The actual filtering is done by the backend.

### Call Status Before Showing Fast Shipping UI

Always call `GET /fast-shipping/status` before rendering the Fast Shipping toggle. Never assume the service is available.

```javascript
function FastShippingSection() {
  const [status, setStatus] = useState(null);

  useEffect(() => {
    checkFastShippingStatus().then(setStatus);
  }, []);

  // Wait for status before rendering anything
  if (!status) return null;

  // Now render based on actual status
  return <FastShippingUI status={status} />;
}
```

### Handle Channel Switching Cleanly

When the user switches channels:

1. Update the global state
2. Persist to `localStorage`
3. Re-fetch the current page data (use React effects that depend on channel)
4. Show a loading indicator during the transition
5. If on a product detail page and the product returns 404, handle it gracefully

### Cache Status Responses

Don't call the status endpoint on every render. Cache it for 30-60 seconds:

```javascript
let statusCache = null;
let statusCacheTime = 0;
const CACHE_TTL = 60000; // 60 seconds

export async function checkFastShippingStatus() {
  if (statusCache && Date.now() - statusCacheTime < CACHE_TTL) {
    return statusCache;
  }

  const { data } = await client.get('/fast-shipping/status');
  statusCache = data;
  statusCacheTime = Date.now();
  return data;
}
```

### Log Channel for Debugging

Include the current channel in your analytics or logging:

```javascript
client.interceptors.request.use((config) => {
  config.headers['X-Channel'] = getChannel();
  console.debug('[API] ' + config.method?.toUpperCase() + ' ' + config.url + ' [channel: ' + getChannel() + ']');
  return config;
});
```

---

## 16. Complete Frontend Examples

### Axios -- Complete API Client

```javascript
// api/client.js
import axios from 'axios';

const client = axios.create({
  baseURL: '/api/general',
  headers: { Accept: 'application/json' },
  timeout: 15000,
});

// --- Channel Management ---

let currentChannel = localStorage.getItem('shipping_channel') || 'home';

export function setChannel(channel) {
  currentChannel = channel;
  localStorage.setItem('shipping_channel', channel);
}

export function getChannel() {
  return currentChannel;
}

// --- Interceptors ---

client.interceptors.request.use((config) => {
  config.headers['X-Channel'] = currentChannel;

  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers['Authorization'] = 'Bearer ' + token;
  }

  return config;
});

client.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

// --- Status ---

export async function getFastShippingStatus() {
  const { data } = await client.get('/fast-shipping/status');
  return data;
}

// --- Products ---

export async function getProducts(params = {}) {
  const { data } = await client.get('/products', { params });
  return data;
}

export async function getProduct(slug) {
  const { data } = await client.get('/products/' + slug);
  return data;
}

// --- Categories ---

export async function getCategories(params = {}) {
  const { data } = await client.get('/categories', { params });
  return data;
}

// --- Home ---

export async function getHomeData() {
  const { data } = await client.get('/home');
  return data;
}

// --- Search ---

export async function searchProducts(params = {}) {
  const { data } = await client.get('/search', { params });
  return data;
}

// --- Flash Sales ---

export async function getFlashSales(params = {}) {
  const { data } = await client.get('/flash-sales', { params });
  return data;
}

// --- Banners ---

export async function getBanners() {
  const { data } = await client.get('/banners');
  return data;
}

// --- Brands ---

export async function getBrands() {
  const { data } = await client.get('/brands');
  return data;
}

// --- Sliders ---

export async function getSliders() {
  const { data } = await client.get('/sliders');
  return data;
}

// --- Coupons ---

export async function getCoupons() {
  const { data } = await client.get('/coupons');
  return data;
}

// --- Checkout ---

export async function placeOrder(orderData) {
  const endpoint = currentChannel === 'fast-shipping'
    ? '/checkout/fast'
    : '/checkout';

  const { data } = await client.post(endpoint, orderData);
  return data;
}

// --- Orders ---

export async function getOrders(params = {}) {
  const { data } = await client.get('/orders', { params });
  return data;
}

export async function getFastOrders(params = {}) {
  const { data } = await client.get('/fast-shipping/orders', { params });
  return data;
}

export default client;
```

### Fetch API -- Complete Client

```javascript
// api/fetch-client.js
let currentChannel = localStorage.getItem('shipping_channel') || 'home';
let authToken = localStorage.getItem('auth_token');

export function setChannel(channel) {
  currentChannel = channel;
  localStorage.setItem('shipping_channel', channel);
}

export function getChannel() {
  return currentChannel;
}

export function setAuthToken(token) {
  authToken = token;
  if (token) {
    localStorage.setItem('auth_token', token);
  } else {
    localStorage.removeItem('auth_token');
  }
}

async function request(method, path, options = {}) {
  const url = '/api/general' + path;

  const headers = {
    'Accept': 'application/json',
    'X-Channel': currentChannel,
  };

  if (authToken) {
    headers['Authorization'] = 'Bearer ' + authToken;
  }

  // Don't set Content-Type for GET/HEAD requests
  if (method !== 'GET' && method !== 'HEAD') {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(url, {
    method,
    headers,
    ...options,
  });

  if (!response.ok) {
    if (response.status === 401) {
      localStorage.removeItem('auth_token');
      window.location.href = '/login';
    }

    const error = new Error('API Error');
    error.response = response;
    throw error;
  }

  if (response.status === 204) {
    return null;
  }

  return response.json();
}

// --- API Methods ---

export const api = {
  getFastShippingStatus: () => request('GET', '/fast-shipping/status'),

  getProducts: (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return request('GET', '/products' + (query ? '?' + query : ''));
  },

  getProduct: (slug) => request('GET', '/products/' + slug),

  getCategories: (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return request('GET', '/categories' + (query ? '?' + query : ''));
  },

  getHomeData: () => request('GET', '/home'),

  search: (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return request('GET', '/search' + (query ? '?' + query : ''));
  },

  getFlashSales: (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return request('GET', '/flash-sales' + (query ? '?' + query : ''));
  },

  getBanners: () => request('GET', '/banners'),
  getBrands: () => request('GET', '/brands'),
  getSliders: () => request('GET', '/sliders'),
  getCoupons: () => request('GET', '/coupons'),

  placeOrder: (orderData) => {
    const endpoint = currentChannel === 'fast-shipping'
      ? '/checkout/fast'
      : '/checkout';
    return request('POST', endpoint, {
      body: JSON.stringify(orderData),
    });
  },

  getOrders: (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return request('GET', '/orders' + (query ? '?' + query : ''));
  },

  getFastOrders: (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return request('GET', '/fast-shipping/orders' + (query ? '?' + query : ''));
  },
};
```

### Usage Example -- React Application

```jsx
// App.jsx
import { useEffect, useState } from 'react';
import { ChannelProvider, useChannel } from './context/ChannelContext';
import { api } from './api/fetch-client';
import FastShippingBanner from './components/FastShippingBanner';
import HomePage from './pages/HomePage';
import ProductPage from './pages/ProductPage';
import CheckoutPage from './pages/CheckoutPage';

function AppContent() {
  const { channel, setChannel } = useChannel();
  const [status, setStatus] = useState(null);

  useEffect(() => {
    // Check status on app startup
    api.getFastShippingStatus()
      .then(setStatus)
      .catch(() => setStatus({ enabled: false, available: false }));
  }, []);

  return (
    <div className="app">
      {status && (
        <FastShippingBanner
          status={status}
          channel={channel}
          onToggle={(enabled) => {
            setChannel(enabled ? 'fast-shipping' : 'home');
          }}
        />
      )}

      <main>
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/products/:slug" element={<ProductPage />} />
          <Route path="/checkout" element={<CheckoutPage />} />
        </Routes>
      </main>
    </div>
  );
}

export default function App() {
  return (
    <ChannelProvider>
      <AppContent />
    </ChannelProvider>
  );
}
```

---

## 17. FAQ

### Do I have to filter products manually?

**No.** The backend filters everything based on the `X-Channel` header. Just change the header and re-fetch. The response contains only the relevant data for the current channel.

### Should I save the channel in localStorage?

**Yes.** Persist the user's preference so it survives page reloads:

```javascript
// Save
localStorage.setItem('shipping_channel', 'fast-shipping');

// Read on startup
const saved = localStorage.getItem('shipping_channel') || 'home';
```

This way, if a user enables Fast Shipping, browses away, and comes back later, their preference is preserved.

### Can I switch channels anytime?

**Yes.** The user can switch between `home` and `fast-shipping` at any time. When they switch, reload the current page data with the new header. The backend handles all the filtering.

**Recommended triggers for switching:**
- Toggle switch in the navigation bar (always visible)
- Toggle on the home page hero section
- Toggle in the mobile menu
- Automatic switch back to `home` when the user enters checkout with non-fast-shipping items

### Does checkout use the same endpoint?

**No.** Standard checkout uses `POST /checkout` with `X-Channel: home`. Fast shipping checkout uses `POST /checkout/fast` with `X-Channel: fast-shipping`. Your code should route to the correct endpoint based on `getChannel()`.

### What if the service is unavailable?

The status endpoint returns `{ enabled: false }` or `{ available: false }`. Hide the Fast Shipping toggle and show a message. Do not allow the user to switch to fast-shipping mode.

### What if the header is forgotten?

The backend defaults to `home`. Your app behaves in standard-shipping mode with fast-shipping products hidden. Always attach the header via an Axios interceptor or a fetch wrapper.

### Does the backend remember my channel?

**No.** The backend is stateless. It relies entirely on the `X-Channel` header in each request. Your frontend must send it with every API call.

### What happens to the cart when I switch channels?

The cart is channel-agnostic -- it holds whatever the user added. However, during checkout, only items matching the shipping method are processed. If the user switches to fast-shipping and their cart contains non-fast-shipping items, the frontend should warn them:

```javascript
async function handleCheckout() {
  const cart = await getCart();
  const hasNonFastItems = cart.items.some(
    item => !item.product?.is_fast_shipping_available
  );

  if (channel === 'fast-shipping' && hasNonFastItems) {
    showModal({
      title: 'Mixed Cart Items',
      message: 'Some items in your cart are not available for Fast Shipping. They will be checked out separately with standard shipping.',
      actions: [
        { label: 'Continue with Fast Shipping', onClick: proceedCheckout },
        { label: 'Switch to Standard', secondary: true, onClick: () => setChannel('home') },
      ],
    });
    return;
  }

  proceedCheckout();
}
```

### How do I know if a product supports fast shipping?

Each product response includes `is_fast_shipping_available`. Use it to show a badge or label, but do NOT use it to filter products manually -- the `X-Channel` header handles filtering for you.

### Can I show fast-shipping products while in home mode?

**No.** In `home` mode, the API only returns standard-shipping products (`is_fast_shipping_available: false`). Fast-shipping products are completely excluded from every endpoint including products, categories, banners, sliders, flash sales, brands, and home page sections. To see fast-shipping products, switch to the `fast-shipping` channel by sending `X-Channel: fast-shipping`.

**To show a "Switch to Fast Shipping" CTA in home mode:** Use the fast-shipping status endpoint to check availability, and if enabled, show a banner or button that calls `setChannel('fast-shipping')`.

### What if the status endpoint fails?

Fall back gracefully:

```javascript
async function checkStatus() {
  try {
    return await api.getFastShippingStatus();
  } catch {
    // If status endpoint fails, assume service is unavailable
    return {
      enabled: false,
      available: false,
      fee: 0,
      duration_minutes: 0,
      opens_at: null,
      closes_at: null,
      available_again_at: null,
    };
  }
}
```

### Should I show the Fast Shipping toggle on every page?

**Yes.** The toggle should be accessible from anywhere -- typically in the navigation bar or as a sticky banner. Users should be able to switch channels without navigating to a specific page.

### What about SEO?

Fast Shipping is a client-side feature toggled by the user. It does not affect server-side rendering or SEO. Search engines will always see the `home` channel (default). The `X-Channel` header is not sent by crawlers.

---

> **Document Version**: 1.1.0  
> **Last Updated**: 2026-07-07  
> **Questions?** Contact the API team.
