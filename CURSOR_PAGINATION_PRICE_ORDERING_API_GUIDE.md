# Cursor Pagination — Price Ordering API Guide

**Endpoint:** `GET /v1/general/products`  
**Feature:** Cursor pagination with price-based sorting  
**Date:** 2026-09-14

---

## Overview

The products endpoint now supports **cursor pagination with price ordering**. You can paginate through products sorted by price (ascending or descending) while maintaining deterministic, duplicate-free results.

---

## Quick Start

### Basic Price Ordering

**Sort by price ascending:**
```http
GET /v1/general/products?pagination=cursor&order_price=asc
```

**Sort by price descending:**
```http
GET /v1/general/products?pagination=cursor&order_price=desc
```

---

## Request Parameters

| Parameter | Type | Required | Values | Description |
|-----------|------|----------|--------|-------------|
| `pagination` | string | Yes | `cursor` | Enables cursor pagination |
| `order_price` | string | Yes | `asc`, `desc` | Sort direction by price |
| `limit` | integer | Optional | 1-100 | Products per page (default: 10) |
| `order` | string | Optional | `asc`, `desc` | Ignored when `order_price` is present |

---

## Response Structure

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
        "slug": "product-slug",
        // ... other product fields
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

### Response Fields

**`data.data`**: Array of product objects  
**`data.links.next_page_url`**: URL for next page (null if last page)  
**`data.links.prev_page_url`**: URL for previous page (null if first page)  
**`data.links.per_page`**: Number of items per page

---

## Frontend Integration Steps

### Step 1: Initial Request

Make the first request with cursor pagination and price ordering:

```javascript
const response = await fetch(
  '/v1/general/products?pagination=cursor&order_price=asc&limit=20'
);
const data = await response.json();
```

### Step 2: Render Products

Display the products from `data.data.data`:

```javascript
const products = data.data.data;
products.forEach(product => {
  // Render product card
  console.log(product.name, product.price);
});
```

### Step 3: Handle Next Page

Use `next_page_url` to load the next page:

```javascript
const nextUrl = data.data.links.next_page_url;

if (nextUrl) {
  // User clicks "Load More" or scrolls to bottom
  const nextResponse = await fetch(nextUrl);
  const nextData = await nextResponse.json();
  
  // Append new products to existing list
  const moreProducts = nextData.data.data;
  // ... render moreProducts
}
```

### Step 4: Handle Previous Page

Use `prev_page_url` to navigate backwards:

```javascript
const prevUrl = data.data.links.prev_page_url;

if (prevUrl) {
  // User clicks "Previous"
  const prevResponse = await fetch(prevUrl);
  const prevData = await prevResponse.json();
  
  // Replace or prepend products
  const prevProducts = prevData.data.data;
  // ... render prevProducts
}
```

### Step 5: Handle Last Page

Check if you've reached the end:

```javascript
if (data.data.links.next_page_url === null) {
  // No more products
  hideLoadMoreButton();
}
```

---

## Common Use Cases

### Infinite Scroll

```javascript
let currentUrl = '/v1/general/products?pagination=cursor&order_price=asc&limit=20';
let loading = false;

async function loadMore() {
  if (loading || !currentUrl) return;
  
  loading = true;
  const response = await fetch(currentUrl);
  const data = await response.json();
  
  // Append products to DOM
  appendProducts(data.data.data);
  
  // Update next URL
  currentUrl = data.data.links.next_page_url;
  loading = false;
}

// Trigger on scroll
window.addEventListener('scroll', () => {
  if (isNearBottom() && currentUrl) {
    loadMore();
  }
});
```

### Load More Button

```javascript
let nextPageUrl = null;

async function loadProducts(url) {
  const response = await fetch(url);
  const data = await response.json();
  
  // Render products
  displayProducts(data.data.data);
  
  // Update next URL and button state
  nextPageUrl = data.data.links.next_page_url;
  
  if (nextPageUrl) {
    showLoadMoreButton();
  } else {
    hideLoadMoreButton();
  }
}

// Initial load
loadProducts('/v1/general/products?pagination=cursor&order_price=asc');

// Load more button click
loadMoreButton.addEventListener('click', () => {
  if (nextPageUrl) {
    loadProducts(nextPageUrl);
  }
});
```

### Paginated Navigation (Next/Previous)

```javascript
let currentPageData = null;

async function navigateToPage(url) {
  const response = await fetch(url);
  const data = await response.json();
  
  currentPageData = data;
  
  // Replace entire product list
  replaceProducts(data.data.data);
  
  // Update navigation buttons
  const hasNext = data.data.links.next_page_url !== null;
  const hasPrev = data.data.links.prev_page_url !== null;
  
  nextButton.disabled = !hasNext;
  prevButton.disabled = !hasPrev;
}

nextButton.addEventListener('click', () => {
  navigateToPage(currentPageData.data.links.next_page_url);
});

prevButton.addEventListener('click', () => {
  navigateToPage(currentPageData.data.links.prev_page_url);
});
```

---

## Combining with Filters

Cursor pagination with price ordering works with all existing filters:

### Price Range Filter

```http
GET /v1/general/products?pagination=cursor&order_price=asc&min_price=10&max_price=100
```

### Brand Filter

```http
GET /v1/general/products?pagination=cursor&order_price=desc&brand=nike
```

### Category Filter

```http
GET /v1/general/products?pagination=cursor&order_price=asc&category=electronics
```

### Multiple Filters

```http
GET /v1/general/products?pagination=cursor&order_price=asc&brand=apple&min_price=500&max_price=2000&category=phones
```

**Important:** When navigating pages, use the `next_page_url` and `prev_page_url` directly — they preserve all active filters.

---

## Important Notes

### ✅ Supported

- Price ordering (ascending/descending)
- All existing filters (brand, category, price range, etc.)
- Forward navigation (`next_page_url`)
- Backward navigation (`prev_page_url`)
- Custom page sizes (`limit` parameter)

### ❌ Not Supported

**Search with cursor pagination:**
```http
GET /v1/general/products?pagination=cursor&order_price=asc&search=phone
```
**Returns:** `HTTP 422` with validation error

**Reason:** Search requires full-text indexing that doesn't support keyset pagination. Use offset pagination for search queries.

---

## Error Handling

### 422 Validation Error

**Scenario:** Using unsupported parameter combinations

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

**Action:** Remove `search` parameter or switch to offset pagination.

### Invalid `order_price` Value

```http
GET /v1/general/products?pagination=cursor&order_price=random
```

**Returns:** `HTTP 422` - only `asc` and `desc` are valid.

---

## Migration from Offset Pagination

### Old Approach (Offset)

```javascript
// Page 1
fetch('/v1/general/products?page=1&limit=20&order_price=asc');

// Page 2
fetch('/v1/general/products?page=2&limit=20&order_price=asc');
```

### New Approach (Cursor)

```javascript
// Page 1
const page1 = await fetch('/v1/general/products?pagination=cursor&order_price=asc&limit=20');
const data1 = await page1.json();

// Page 2 - use next_page_url
const page2 = await fetch(data1.data.links.next_page_url);
const data2 = await page2.json();
```

### Key Differences

| Feature | Offset Pagination | Cursor Pagination |
|---------|-------------------|-------------------|
| Page parameter | `?page=2` | Use `next_page_url` |
| Duplicate handling | Possible with concurrent updates | Guaranteed duplicate-free |
| Performance | Slower for large offsets | Consistent performance |
| Missing records | Possible with concurrent updates | Gap-free traversal |
| Backward navigation | `?page=N-1` | Use `prev_page_url` |

---

## Best Practices

### 1. Always Use Provided URLs

```javascript
// ✅ Correct
const nextPage = await fetch(data.data.links.next_page_url);

// ❌ Wrong - don't build URLs manually
const nextPage = await fetch(`/products?pagination=cursor&cursor=${customCursor}`);
```

### 2. Handle Null URLs

```javascript
const nextUrl = data.data.links.next_page_url;

if (nextUrl === null) {
  // End of results
  console.log('No more products');
}
```

### 3. Preserve Filter State

When users change filters, reset to page 1:

```javascript
function applyFilters(brand, minPrice, maxPrice) {
  // Start fresh with new filters
  const url = `/v1/general/products?pagination=cursor&order_price=asc&brand=${brand}&min_price=${minPrice}&max_price=${maxPrice}`;
  loadProducts(url);
}
```

### 4. Show Loading States

```javascript
async function loadMore() {
  showLoadingSpinner();
  
  try {
    const response = await fetch(nextPageUrl);
    const data = await response.json();
    appendProducts(data.data.data);
  } catch (error) {
    showError('Failed to load products');
  } finally {
    hideLoadingSpinner();
  }
}
```

### 5. Cache Management

The cursor URLs contain encoded state. Don't cache them long-term:

```javascript
// ✅ Good - cache current page only
sessionStorage.setItem('currentPage', JSON.stringify(pageData));

// ❌ Bad - don't persist cursor URLs
localStorage.setItem('nextPageUrl', nextUrl); // May become stale
```

---

## Example: React Implementation

```jsx
import { useState, useEffect } from 'react';

function ProductList() {
  const [products, setProducts] = useState([]);
  const [nextUrl, setNextUrl] = useState(null);
  const [loading, setLoading] = useState(false);

  async function loadProducts(url) {
    setLoading(true);
    
    try {
      const response = await fetch(url);
      const data = await response.json();
      
      setProducts(prev => [...prev, ...data.data.data]);
      setNextUrl(data.data.links.next_page_url);
    } catch (error) {
      console.error('Failed to load products', error);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadProducts('/v1/general/products?pagination=cursor&order_price=asc&limit=20');
  }, []);

  return (
    <div>
      <div className="products-grid">
        {products.map(product => (
          <ProductCard key={product.id} product={product} />
        ))}
      </div>
      
      {nextUrl && (
        <button 
          onClick={() => loadProducts(nextUrl)}
          disabled={loading}
        >
          {loading ? 'Loading...' : 'Load More'}
        </button>
      )}
    </div>
  );
}
```

---

## Testing Checklist

- [ ] Initial page loads with price ordering
- [ ] "Load More" fetches additional products
- [ ] No duplicate products across pages
- [ ] Products maintain correct price order
- [ ] Last page correctly shows no `next_page_url`
- [ ] Previous navigation works correctly
- [ ] Filters combine with price ordering
- [ ] Search + cursor returns 422 error
- [ ] Loading states display correctly
- [ ] Error handling works for network failures

---

## Support

- **API Errors:** Check response `errors` object for details
- **Feature Flag:** Cursor pagination must be enabled (`cursor.enabled=true`)
- **Rate Limits:** Standard API rate limits apply
- **Caching:** Product listings are cached when search is not present

---

## Summary

**To implement cursor pagination with price ordering:**

1. Add `pagination=cursor&order_price=asc` or `desc` to initial request
2. Render products from `data.data.data`
3. Use `data.links.next_page_url` for next page
4. Use `data.links.prev_page_url` for previous page
5. Check for `null` to detect end of results
6. Combine with filters as needed
7. Handle 422 errors when using unsupported combinations (search)

The cursor URLs are opaque tokens — always use them exactly as provided by the API.
