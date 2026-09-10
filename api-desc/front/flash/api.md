# API Reference — Flash Sale Module (Public API)

---

### GET /api/v1/general/flash-sales

List active flash sales (paginated). Only returns flash sales where `status = true`, `start_date <= today`, and `end_date >= today`.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 10 | Items per page |
| start_date | string (date) | - | Filter by created_at >= |
| end_date | string (date) | - | Filter by created_at <= |
| flashSalesId | string | - | Comma-separated IDs |
| order | string | desc | Sort direction |
| slug | string | - | Get single flash sale by slug |

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Summer Flash Sale",
      "discription": "Big discounts on summer products",
      "slug": "summer-flash-sale",
      "start_date": "2026-07-01",
      "end_date": "2026-07-31",
      "image": {
        "desktop": "https://cdn.example.com/flash-sales/summer-desktop.jpg",
        "mobile": "https://cdn.example.com/flash-sales/summer-mobile.jpg"
      }
    }
  ]
}
```

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/general/flash-sales" \
  -H "Accept: application/json"
```

---

### GET /api/v1/general/flash-sales/{slug}

Get a single flash sale with its associated products.

**Response 200:** Flash sale object with `products` array (ProductMiniResource)

**Response 404:**
```json
{ "status": 404, "message": "Data not found", "success": false }
```

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/general/flash-sales/summer-flash-sale" \
  -H "Accept: application/json"
```

---

### GET /api/v1/general/flash-sale-products

Fetch a flat list of products from valid flash sales, limited per flash sale.

**Parameters:** `?limit=5` (products per flash sale)

**Response 200:** Array of ProductMiniResource objects

---

### GET /api/v1/general/flash-sale-products-ending-this-week

Products in flash sales ending within the next 7 days.

**Parameters:** `?limit=10`

**Response 200:** Array of ProductMiniResource objects with `flash_sale_active = true`

---

### GET /api/v1/general/flash-sale-products-ending-today

Products in flash sales ending today.

**Parameters:** `?limit=10`

**Response 200:** Array of ProductMiniResource objects with `flash_sale_active = true`

---

## Business Rules

- Only flash sales with `status = true` and within the valid date range are returned (except slug lookup — see BUG-FLASH-002)
- Products are channel-filtered (home/fast-shipping)
- Products are enriched with real-time pricing including flash sale discounts
- The `flash_sale_active` flag on products indicates an active flash sale
