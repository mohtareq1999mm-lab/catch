# API Reference — Coupon Module (Public API)

---

### GET /api/v1/general/coupons

List valid coupons. Only returns coupons where `status = true`, within date range, and usage limit not reached.

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

**Response 200:**
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

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/general/coupons" \
  -H "Accept: application/json"
```

---

### POST /api/v1/general/coupons/apply

Apply a coupon code to the authenticated user's cart.

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

**Response 400 (invalid):**
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

**Quick Test:**
```bash
curl -X POST "http://example.com/api/v1/general/coupons/apply" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"code": "SUMMER10"}'
```

**Business Rules:**
- Coupon must be active, within date range, and not exceed usage limit
- User must have a cart
- Coupon cannot be applied twice to the same cart
- Some coupons are restricted to specific users (assignments) or products
- Minimum/maximum cart quantity requirements may apply
