# Cursor Pagination with Price Ordering — API Documentation

**Endpoint:** `GET /v1/general/products`

---

## What to Send

### Required Parameters

```
pagination=cursor
order_price=asc     (or desc)
```

### Optional Parameters

```
limit=20            (default: 10, max: 100)
```

### Example URLs

**Price Low to High:**
```
/v1/general/products?pagination=cursor&order_price=asc
```

**Price High to Low:**
```
/v1/general/products?pagination=cursor&order_price=desc
```

**With Custom Page Size:**
```
/v1/general/products?pagination=cursor&order_price=asc&limit=20
```

---

## What You Receive

### Success Response (200)

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
      "next_page_url": "https://api.example.com/v1/general/products?pagination=cursor&order_price=asc&cursor=eyJ...",
      "prev_page_url": null
    }
  }
}
```

### Response Fields

| Field | Value | Meaning |
|-------|-------|---------|
| `data.data` | Array | Products on current page |
| `data.links.next_page_url` | URL or `null` | Full URL for next page. `null` = no more pages |
| `data.links.prev_page_url` | URL or `null` | Full URL for previous page. `null` = first page |

---

## Navigation Steps

### 1. First Page
Send request with `pagination=cursor` and `order_price=asc` or `desc`

### 2. Next Page
Use exact URL from `next_page_url` field in response

### 3. Previous Page
Use exact URL from `prev_page_url` field in response

### 4. Last Page Detection
When `next_page_url` is `null`, you've reached the end

---

## Combining with Filters

### Price Range
```
/v1/general/products?pagination=cursor&order_price=asc&min_price=10&max_price=100
```

### Brand
```
/v1/general/products?pagination=cursor&order_price=desc&brand=nike
```

### Category
```
/v1/general/products?pagination=cursor&order_price=asc&category=electronics
```

### Multiple Filters
```
/v1/general/products?pagination=cursor&order_price=asc&brand=apple&category=phones&min_price=500
```

**Note:** `next_page_url` and `prev_page_url` automatically include all active filters.

---

## Error Responses

### Search Not Supported (422)

**Request:**
```
/v1/general/products?pagination=cursor&order_price=asc&search=phone
```

**Response:**
```json
{
  "success": false,
  "errors": {
    "pagination": ["Cursor pagination does not support search queries"]
  }
}
```

### Invalid Value (422)

**Request:**
```
/v1/general/products?pagination=cursor&order_price=invalid
```

**Response:**
```json
{
  "success": false,
  "errors": {
    "order_price": ["Invalid value"]
  }
}
```

---

## Summary

**Send:**
- `pagination=cursor&order_price=asc` (or `desc`)
- Optional: `limit` for page size
- Optional: filters (brand, category, price range)
- Do NOT send: `search` parameter

**Receive:**
- Array of products in `data.data`
- Next page URL in `data.links.next_page_url`
- Previous page URL in `data.links.prev_page_url`

**Navigate:**
- Use `next_page_url` exactly as provided
- Use `prev_page_url` exactly as provided
- Check for `null` to detect end
