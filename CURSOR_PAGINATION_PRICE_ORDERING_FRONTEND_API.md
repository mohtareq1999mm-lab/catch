# Cursor Pagination — Price Ordering API

**Endpoint:** `GET /v1/general/products`  
**Date:** 2026-09-14

---

## What to Send

### Request Parameters

| Parameter | Required | Values | Description |
|-----------|----------|--------|-------------|
| `pagination` | Yes | `cursor` | Enable cursor pagination |
| `order_price` | Yes | `asc`, `desc` | Sort products by price |
| `limit` | No | 1-100 | Products per page (default: 10) |

### Example Requests

**Price ascending:**
```
GET /v1/general/products?pagination=cursor&order_price=asc
```

**Price descending:**
```
GET /v1/general/products?pagination=cursor&order_price=desc
```

**With page size:**
```
GET /v1/general/products?pagination=cursor&order_price=asc&limit=20
```

---

## What You Receive

### Response Structure

```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": {
    "data": [
      {
        "id": 1,
        "name": "Product Name",
        "price": 99.99,
        "slug": "product-slug"
      }
    ],
    "links": {
      "path": "https://api.example.com/v1/general/products",
      "per_page": 10,
      "next_page_url": "https://api.example.com/v1/general/products?pagination=cursor&order_price=asc&cursor=eyJwcmljZSI6...",
      "prev_page_url": null
    }
  }
}
```

### Important Fields

| Field | Type | Description |
|-------|------|-------------|
| `data.data` | Array | List of products for current page |
| `data.links.next_page_url` | String or null | Full URL for next page. `null` = last page |
| `data.links.prev_page_url` | String or null | Full URL for previous page. `null` = first page |
| `data.links.per_page` | Number | Items per page |

---

## How to Navigate Pages

### Step 1: First Page
Send initial request with `pagination=cursor` and `order_price`

### Step 2: Next Page
Use the exact URL from `data.links.next_page_url`

### Step 3: Previous Page
Use the exact URL from `data.links.prev_page_url`

### Step 4: Detect Last Page
Check if `data.links.next_page_url` is `null`

**Rule:** Always use the URLs provided in the response. Do not build cursor URLs manually.

---

## Combining with Filters

All existing filters work with cursor pagination:

### Price Range
```
GET /v1/general/products?pagination=cursor&order_price=asc&min_price=10&max_price=100
```

### Brand Filter
```
GET /v1/general/products?pagination=cursor&order_price=desc&brand=nike
```

### Category Filter
```
GET /v1/general/products?pagination=cursor&order_price=asc&category=electronics
```

### Multiple Filters
```
GET /v1/general/products?pagination=cursor&order_price=asc&brand=apple&category=phones&min_price=500
```

**Important:** When navigating pages, the `next_page_url` and `prev_page_url` preserve all active filters automatically.

---

## Error Responses

### Search Not Supported (422)

**Request:**
```
GET /v1/general/products?pagination=cursor&order_price=asc&search=phone
```

**Response:**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "pagination": [
      "Cursor pagination does not support search queries"
    ]
  }
}
```

**Solution:** Remove `search` parameter or use offset pagination instead.

### Invalid order_price (422)

**Request:**
```
GET /v1/general/products?pagination=cursor&order_price=random
```

**Response:**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "order_price": [
      "Invalid sort direction"
    ]
  }
}
```

**Solution:** Use only `asc` or `desc`.

---

## Quick Reference

### Initial Request
```
GET /v1/general/products?pagination=cursor&order_price=asc&limit=20
```

### Navigate to Next Page
```
GET {next_page_url from previous response}
```

### Navigate to Previous Page
```
GET {prev_page_url from previous response}
```

### Detect End
```
if (next_page_url === null) {
  // No more products
}
```

### When User Changes Filters
Start fresh from page 1 with new filter parameters.

---

## Important Rules

1. ✅ **Use provided URLs** — Don't construct cursor URLs manually
2. ✅ **Check for null** — `next_page_url` or `prev_page_url` can be null
3. ✅ **Reset on filter change** — New filters = new first page request
4. ❌ **No search** — `search` + `cursor` returns 422 error
5. ❌ **No URL modification** — Use cursor URLs exactly as returned

---

## Summary

**What to send:**
- Add `pagination=cursor&order_price=asc` or `desc` to request
- Optionally add `limit` for page size
- Combine with any filters except `search`

**What you get:**
- Array of products in `data.data`
- Next page URL in `data.links.next_page_url`
- Previous page URL in `data.links.prev_page_url`

**How to navigate:**
- Use `next_page_url` for next page
- Use `prev_page_url` for previous page
- Check for `null` to detect end of results
- Always use URLs exactly as provided
