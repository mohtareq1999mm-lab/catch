# Product Module — Frontend JIRA Tasks

## F-001: Public Product Listing Page (Catalog)

**Priority:** High
**Story Points:** 8
**Labels:** frontend, product, public

**API:** `GET /api/v1/general/products`

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `type` | `string` | No | Strategy: `index`, `best_product_sales`, `brands_product`, `new_arrivals`, `all_product_discounts`, `product_discount_today_or_low_qty`, `flash_sales_product`, `flash_sales_end_today`, `flash_sales_end_week`, `product_for_parent_category` |
| `search` | `string` | No | Full-text search (name, desc, sku, categories) via Laravel Scout (Meilisearch) |
| `limit` | `integer` | No | Per page (max 100, default 30) |
| `order` | `asc\|desc` | No | Sort by ID |
| `order_price` | `asc\|desc` | No | Sort by current price |
| `category` | `string\|array` | No | Filter by category slug (recursive descendants) |
| `brand` | `string\|array` | No | Filter by brand name/slug |
| `tag` | `string\|array` | No | Filter by tag slug (AND logic) |
| `promotion` | `string\|array` | No | Filter by promotion slug |
| `flash_sale` | `string\|array` | No | Filter by flash sale slug/title |
| `banner` | `string\|array` | No | Filter by banner slug/title |
| `slider` | `string\|array` | No | Filter by slider slug |
| `minPrice` | `float` | No | Minimum price (product + variant) |
| `maxPrice` | `float` | No | Maximum price (product + variant) |
| `rating` | `float` | No | Minimum avg rating |
| `rating_min` | `float` | No | Minimum rating floor |
| `rating_max` | `float` | No | Maximum rating ceiling |
| `height` | `string\|array` | No | Filter by height |
| `width` | `string\|array` | No | Filter by width |
| `length` | `string\|array` | No | Filter by length |
| `weight` | `string\|array` | No | Filter by weight |
| `productsId` | `string` | No | Comma-separated product IDs |
| `categoriesId` | `string` | No | Comma-separated category IDs |
| `brandsId` | `string` | No | Comma-separated brand IDs |
| `promotionsId` | `string` | No | Comma-separated promotion IDs |
| `flashSalesId` | `string` | No | Comma-separated flash sale IDs |
| `bannersId` | `string` | No | Comma-separated banner IDs |
| `couponsId` | `string` | No | Comma-separated coupon IDs |
| `slidersId` | `string` | No | Comma-separated slider IDs |

**Success Response (200):**

```json
{
    "success": true,
    "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
    "data": {
        "data": [
            {
                "id": 1,
                "name": "Wireless Headphones",
                "slug": "wireless-headphones",
                "price": 99.99,
                "has_variants": false,
                "current_price": 79.99,
                "quantity": 50,
                "in_stock": true,
                "discount_active": true,
                "flash_sale_active": false,
                "is_fast_shipping_available": true,
                "ratings": 4.5,
                "image": {
                    "thumbnail": "https://cdn.example.com/thumb.jpg",
                    "original": [
                        "https://cdn.example.com/img1.jpg"
                    ]
                }
            }
        ],
        "current_page": 1,
        "from": 1,
        "to": 30,
        "last_page": 5,
        "per_page": 30,
        "total": 150,
        "filters": {
            "brands": [],
            "categories": [],
            "attributes": [],
            "ratings": {},
            "dimensions": {}
        },
        "categories": [
            {
                "id": 1,
                "name": "Subcategory",
                "slug": "sub-cat",
                "image": { "desktop": null, "mobile": null }
            }
        ]
    }
}
```

**Features:**
- Product grid with ProductMiniResource cards (image thumbnail, name, price, current_price, rating, discount badge, flash sale badge, fast shipping badge)
- Strategy selector: index, new_arrivals, best_product_sales, flash_sales_product, all_product_discounts
- Grid/list view toggle
- Sort: price (asc/desc), newest, best selling
- Server-side pagination (30 per page, load more or page numbers)
- Filter sidebar: categories (tree), brands, price range (min/max), ratings, attributes, dimensions
- Full-text search bar with debounced input
- Loading skeleton grid
- Empty state: "No products found" with clear filters CTA
- URL query param sync for shareable filtered URLs

---

## F-002: Public Product Detail Page

**Priority:** High
**Story Points:** 5
**Labels:** frontend, product, public

**API:** `GET /api/v1/general/products/{slug}`

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `limit` | `integer` | Number of related products to include (default 10) |

**Success Response (200):**

```json
{
    "success": true,
    "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
    "data": {
        "id": 1,
        "name": "Wireless Headphones",
        "slug": "wireless-headphones",
        "description": "Premium wireless headphones with noise cancellation.",
        "price": 99.99,
        "current_price": 79.99,
        "discount_type": "percentage",
        "discount_amount": 20,
        "start_date": "2026-07-01",
        "end_date": "2026-07-31",
        "sku": "PRD-001",
        "quantity": 50,
        "sold_quantity": 25,
        "in_stock": true,
        "product_type": "simple",
        "height": null,
        "width": null,
        "length": null,
        "weight": null,
        "has_flash_sale": false,
        "has_discount": true,
        "is_fast_shipping_available": false,
        "discount_valid": true,
        "discount_active": true,
        "flash_sale_active": false,
        "categories": [
            { "id": 1, "level": 1, "name": "Electronics", "slug": "electronics" },
            { "id": 2, "level": 0, "name": "Root Category", "slug": "root" }
        ],
        "images": {
            "thumbnail": "https://cdn.example.com/thumb.jpg",
            "original": [
                "https://cdn.example.com/img1.jpg",
                "https://cdn.example.com/img2.jpg"
            ]
        },
        "tags": [
            { "id": 1, "name": "new", "slug": "new" }
        ],
        "variants": [
            {
                "id": 10,
                "price": 109.99,
                "current_price": 89.99,
                "quantity": 20,
                "height": null,
                "width": null,
                "length": null,
                "weight": null,
                "attributes": [
                    { "attribute_name": "Color", "value": "Black" }
                ]
            }
        ],
        "reviews": [
            {
                "id": 1,
                "rating": 5,
                "comment": "Great product!",
                "user": { "name": "John" },
                "images": [],
                "created_at": "2026-07-15T10:00:00Z"
            }
        ],
        "related_products": [
            {
                "id": 2,
                "name": "Earbuds",
                "slug": "earbuds",
                "price": 49.99,
                "has_variants": false,
                "current_price": 49.99,
                "quantity": 30,
                "in_stock": true,
                "discount_active": false,
                "flash_sale_active": false,
                "is_fast_shipping_available": false,
                "ratings": 4.0,
                "image": { "thumbnail": null, "original": [] }
            }
        ],
        "filters": {
            "brands": [],
            "categories": [],
            "attributes": [],
            "ratings": {},
            "dimensions": {}
        }
    }
}
```

**Error Response (404):**

```json
{
    "success": false,
    "message": "MESSAGE.NOT_FOUND"
}
```

**Features:**
- Image gallery: thumbnail + original image array with zoom/lightbox
- Product info: name, description (translated), SKU, stock status, sold quantity
- Pricing display: original price (strikethrough if discounted), current_price, discount percentage badge, flash sale badge
- Variant selector (if variable): attribute options (color, size, etc.) with price/stock updates
- Quantity selector + Add to Cart button
- Add to Wishlist button
- Fast shipping badge
- Categories/brands/tags breadcrumbs
- Reviews section: list with stars, comment, user name, date, images
- Related products carousel (ProductMiniResource cards)
- Dynamic filters sidebar (brands, categories, attributes for this product category)
- Loading skeleton
- 404 page for invalid slugs

---

## F-003: Loading / Empty / Error States

**Priority:** Medium
**Story Points:** 2
**Labels:** frontend, product, public

**Description:** Handle all async states across the public product pages.

**Features:**
- **Loading:** Skeleton cards (grid) or skeleton detail page with shimmer animation
- **Empty:** Illustration + "No products found" message + "Clear Filters" CTA
- **Error:** Error illustration + message + "Try Again" button
- **404:** Dedicated "Product not found" page for invalid slugs
- **Network error:** Toast notification + retry capability
- **Timeout:** User-friendly message after prolonged loading

---

## F-004: Reviews (Public)

**Priority:** Low
**Story Points:** 3
**Labels:** frontend, reviews, public

**APIs:**
- `POST /api/v1/general/products/{id}/reviews` — create review
- `PUT /api/v1/general/products/reviews/{id}` — update own review

**Features (Public):**
- Review list on product detail page
- Each review: user avatar, name, star rating, comment, images, date
- Create review form: rating (star picker), comment (textarea), image upload
- Edit own review
- Loading/empty/error states
- Review count and average rating summary

---

## F-005: API Service Layer

**Priority:** High
**Story Points:** 2
**Labels:** frontend, infrastructure

**Description:** Create the API service layer for public product endpoints.

```javascript
// services/productApi.js
export const productApi = {
  // Public
  publicList(params)        // GET /api/v1/general/products
  publicShow(slug)          // GET /api/v1/general/products/{slug}
  addReview(id, data)       // POST /api/v1/general/products/{id}/reviews
  updateReview(id, data)    // PUT /api/v1/general/products/reviews/{id}
}
```

---

## F-006: Response Data Normalization

**Priority:** Medium
**Story Points:** 1
**Labels:** frontend, infrastructure

**ProductMiniResource (list):**

| Field | Type | Notes |
|-------|------|-------|
| `id` | number | |
| `name` | string | Already translated by API |
| `slug` | string | |
| `price` | number\|null | Rounded 2dp |
| `has_variants` | boolean | |
| `current_price` | number | Effective price |
| `quantity` | number | Stock |
| `in_stock` | boolean | |
| `discount_active` | boolean | |
| `flash_sale_active` | boolean | |
| `is_fast_shipping_available` | boolean | |
| `ratings` | number | 0-5, rounded 2dp |
| `image.thumbnail` | string\|null | First media URL |
| `image.original` | string[] | Remaining media URLs |

**ProductResource (detail):**

| Field | Type | Notes |
|-------|------|-------|
| `id` | number | |
| `name` | string | Translated |
| `slug` | string | |
| `description` | string | Translated |
| `price` | number\|null | Rounded 2dp |
| `current_price` | number | Effective price |
| `discount_type` | string\|null | percentage/fixed_rate/free_shipping |
| `discount_amount` | number\|null | |
| `start_date` | string\|null | ISO date |
| `end_date` | string\|null | ISO date |
| `sku` | string | |
| `quantity` | number | Stock |
| `sold_quantity` | number | |
| `in_stock` | boolean | |
| `product_type` | string | simple/variable |
| `height` | string\|null | |
| `width` | string\|null | |
| `length` | string\|null | |
| `weight` | string\|null | |
| `has_flash_sale` | boolean | |
| `has_discount` | boolean | |
| `is_fast_shipping_available` | boolean | |
| `discount_valid` | boolean | Only present if has_discount |
| `discount_active` | boolean | |
| `flash_sale_active` | boolean | |
| `categories` | array | `[{ id, level, name, slug }]` |
| `images` | object | `{ thumbnail, original[] }` |
| `tags` | array | |
| `variants` | array | `[{ id, price, current_price, quantity, attributes }]` |
| `reviews` | array | |
| `related_products` | array | ProductMiniResource[] |
| `filters` | object | `{ brands, categories, attributes, ratings, dimensions }` |

---

## F-007: Error Handling

**Priority:** Medium
**Story Points:** 1
**Labels:** frontend, product

| Status | Handling |
|--------|----------|
| 200 | Success — render data |
| 401 | Redirect to login |
| 403 | Show "You don't have permission" |
| 404 | Show "Product not found" (detail) or empty state (list) |
| 422 | Display field-level validation errors on review form |
| 429 | Show "Too many requests, please wait" |
| 500 | Show "Something went wrong" toast with retry |

---

## Epic Summary

| Task | Points | Priority |
|------|--------|----------|
| F-001: Public Product Listing Page | 8 | High |
| F-002: Public Product Detail Page | 5 | High |
| F-003: Loading / Empty / Error States | 2 | Medium |
| F-004: Reviews (Public) | 3 | Low |
| F-005: API Service Layer | 2 | High |
| F-006: Response Data Normalization | 1 | Medium |
| F-007: Error Handling | 1 | Medium |
| **Total** | **22** | |
