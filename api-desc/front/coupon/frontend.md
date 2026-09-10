# Coupon Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/general/coupons — List Valid Coupons (Public)

**Purpose:** Fetch available coupons for displaying on the cart page, checkout, or promotional sections.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of coupons to return |
| search | string | - | Search by coupon name |
| start_date | string (date) | - | Filter by created_at >= |
| end_date | string (date) | - | Filter by created_at <= |
| couponsId | string | - | Comma-separated coupon IDs |
| order | string | desc | Sort direction |

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Summer Sale 10%",
      "slug": "summer-sale-10",
      "image": {
        "desktop": "https://cdn.example.com/coupons/summer-desktop.jpg",
        "mobile": "https://cdn.example.com/coupons/summer-mobile.jpg"
      },
      "borderColor": "#FF0000",
      "borderless": false
    }
  ]
}
```

---

### 2. POST /api/v1/general/coupons/apply — Apply Coupon to Cart (Authenticated)

**Purpose:** Apply a coupon code to the authenticated user's cart. Calculates discount and updates cart total.

**Authentication:** Required (`auth:sanctum`)

**Request Body:**
```json
{
  "code": "SUMMER10"
}
```

**Response 200 (applied):**
```json
{
  "status": 200,
  "message": "Coupon Applied Successfully",
  "success": true,
  "data": {
    "total_price": 90.00,
    "coupon_discount": 10.00,
    "free_shipping": false
  }
}
```

**Response 200 (already applied):**
```json
{
  "status": 200,
  "message": "Coupon Already Applied",
  "success": true,
  "data": {
    "already_applied": true
  }
}
```

**Response 400 (invalid/expired/limit reached):**
```json
{
  "status": 400,
  "message": "Invalid coupon code or coupon cannot be applied or coupon usage limit reached",
  "success": false
}
```

**Response 401 (unauthenticated):**
```json
{
  "status": 401,
  "message": "Unauthenticated",
  "success": false
}
```

---

## Frontend Usage

### Coupon Listing for Promotions
Use `GET /api/v1/general/coupons` to display available coupons as promotional cards on the cart page.

### Coupon Input on Cart/Checkout
Provide a text input for coupon code entry. On submit, call `POST /api/v1/general/coupons/apply` with the code.

### State Handling

| State | Behavior |
|-------|----------|
| **Listing loading** | Skeleton coupon cards |
| **Listing empty** | "No coupons available" |
| **Listing error** | Hide section |
| **Apply loading** | Button shows spinner, disabled |
| **Apply success** | Show discount amount, update cart total |
| **Apply already applied** | Show "Coupon already applied" message |
| **Apply invalid** | Show error message on input field |
| **Apply unauthenticated** | Redirect to login |
| **Apply network error** | Toast error with retry |
