# Frontend - Product Feature

## Status

**No dedicated frontend Vue/React components** found in `resources/js/`. The frontend is a separate SPA.

## Consumption Patterns

### 1. Public Product Listing Page

The main product catalog page uses strategy-based display:

```
GET /api/v1/general/products?type=index&category=electronics&minPrice=10&maxPrice=100&sort=price_asc

Response:
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
        "image": { "thumbnail": "...", "original": ["..."] }
      }
    ],
    "current_page": 1,
    "from": 1,
    "to": 30,
    "last_page": 5,
    "per_page": 30,
    "total": 150,
    "filters": {},
    "categories": []
  }
}
```

Available strategy types:
- `index` — All products (default)
- `best_product_sales` — Best sellers
- `brands_product` — By brand
- `new_arrivals` — Last 15 days
- `all_product_discounts` — Discounted products
- `flash_sales_product` — Flash sale products
- `flash_sales_end_today` — Ending today
- `flash_sales_end_week` — Ending this week
- `product_discount_today_or_low_qty` — Badged products
- `product_for_parent_category` — Parent category filter

### 2. Public Product Detail Page

```
GET /api/v1/general/products/wireless-headphones

Response:
{
  "success": true,
  "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
  "data": {
    "id": 1,
    "name": "Wireless Headphones",
    "slug": "wireless-headphones",
    "description": "Premium wireless headphones...",
    "price": 99.99,
    "current_price": 79.99,
    "discount_type": "percentage",
    "discount_amount": 20,
    "start_date": "2026-07-01",
    "end_date": "2026-07-31",
    "sku": "PRD-001",
    "quantity": 50,
    "sold_quantity": 25,
    "product_type": "simple",
    "in_stock": true,
    "height": null,
    "width": null,
    "length": null,
    "weight": null,
    "has_flash_sale": false,
    "has_discount": true,
    "is_fast_shipping_available": true,
    "discount_valid": true,
    "discount_active": true,
    "flash_sale_active": false,
    "categories": [
      { "id": 1, "level": 1, "name": "Electronics", "slug": "electronics" },
      { "id": 2, "level": 0, "name": "Root", "slug": "root" }
    ],
    "images": {
      "thumbnail": "...",
      "original": ["..."]
    },
    "tags": [],
    "variants": [
      {
        "id": 1, "price": 99.99, "current_price": 79.99,
        "quantity": 10, "height": null, "width": null,
        "length": null, "weight": null,
        "attributes": [{ "attribute_name": "Color", "value": "Black" }]
      }
    ],
    "reviews": [{ "id": 1, "rating": 5, "comment": "Great!", "user": { "name": "John" }, "images": [] }],
    "related_products": [
      {
        "id": 2, "name": "Earbuds", "slug": "earbuds", "price": 49.99,
        "has_variants": false, "current_price": 49.99, "quantity": 30,
        "in_stock": true, "discount_active": false,
        "flash_sale_active": false, "is_fast_shipping_available": false,
        "ratings": 4.0, "image": { "thumbnail": null, "original": [] }
      }
    ],
    "filters": { "brands": [], "categories": [], "attributes": [], "ratings": {}, "dimensions": {} }
  }
}
```

### 3. Admin Product Management

Admin users manage products via CRUD:

```
GET    /api/v1/products?search=headphones&sort=name&status=publish
POST   /api/v1/products  (multipart: JSON + images)
PUT    /api/v1/products/{id}
DELETE /api/v1/products/{id}
POST   /api/v1/products/bulk-delete
```

### 4. Special Endpoints

```
GET    /api/v1/best-selling-products?limit=10
GET    /api/v1/popular-products?limit=10
GET    /api/v1/products/calculate-rental-price?product_id=1&from=2026-08-01&to=2026-08-05&quantity=2
GET    /api/v1/draft-products
GET    /api/v1/products-stock
PUT    /api/v1/products/{id}/fast-shipping
```

## What a Frontend Implementation Would Need

### Public Components

```
ProductListPage.vue
  Fetches: GET /api/v1/general/products with strategy type
  Features:
    - Grid/list toggle
    - Sort by: price (asc/desc), newest, best selling
    - Filter sidebar: categories, brands, price range, attributes, rating
    - Search bar
    - Infinite scroll or pagination
    - Loading skeletons / empty state / error state

ProductDetailPage.vue
  Fetches: GET /api/v1/general/products/{slug}
  Features:
    - Image gallery with zoom
    - Variant selector (color, size, etc.)
    - Price display (original + discounted + flash sale)
    - Stock indicator
    - Add to cart / wishlist buttons
    - Review section with star ratings
    - Related products carousel
    - Fast shipping badge

ProductCard.vue
  Props: product (ProductMiniResource)
  Renders: image, name, price, rating, discount badge, fast-shipping badge
  Actions: click → detail, add to cart, add to wishlist

ReviewForm.vue
  Fields: rating (stars), comment, image upload
  Submit: POST /api/v1/general/products/{id}/reviews

ReviewList.vue
  Props: reviews (array)
  Renders: user avatar, name, rating stars, comment, images, date
```

### Admin Components

```
AdminProductListPage.vue
  Fetches: GET /api/v1/products
  Features:
    - Table: image, name, sku, price, stock, status, type
    - Advanced filters: status, category, type, stock level
    - Search by name/sku
    - Bulk delete
    - Create button
    - Export button (GET /api/v1/products/export)
    - Import button (POST /api/v1/products/import)

AdminProductForm.vue
  Fields (multi-tab for EN/AR):
    - name (text), description (rich text)
    - product_type (simple/variable toggle)
    - price, discount fields, flash sale fields
    - categories (multi-select tree), brands, tags
    - images (drag-and-drop upload)
    - Variants editor (table with attribute/price/stock rows)
    - Shipping dimensions
    - Status selector
  Validation errors inline
  Submit: POST /api/v1/products or PUT /api/v1/products/{id}
```

### API Service Layer

```javascript
// services/productApi.js
export const productApi = {
  // Public
  publicList(params)        // GET /api/v1/general/products
  publicShow(slug)         // GET /api/v1/general/products/{slug}
  addReview(id, data)      // POST /api/v1/general/products/{id}/reviews
  updateReview(id, data)   // PUT /api/v1/general/products/reviews/{id}

  // Admin
  list(params)             // GET /api/v1/products
  show(id)                 // GET /api/v1/products/{id}
  create(data)             // POST /api/v1/products
  update(id, data)         // PUT /api/v1/products/{id}
  delete(id)               // DELETE /api/v1/products/{id}
  bulkDelete(ids)          // POST /api/v1/products/bulk-delete

  // Special
  bestSelling(params)      // GET /api/v1/best-selling-products
  popular(params)          // GET /api/v1/popular-products
  calculateRental(params)  // GET /api/v1/products/calculate-rental-price
}
```

## Key Request/Response Examples

**Public Product Detail:**
```
GET /api/v1/general/products/wireless-headphones
Response: { data: { id, name, slug, description, price, current_price, discount_type, discount_amount, start_date, end_date, sku, quantity, sold_quantity, in_stock, product_type, height, width, length, weight, has_flash_sale, has_discount, is_fast_shipping_available, discount_valid, discount_active, flash_sale_active, categories, images, tags, variants, reviews, related_products, filters } }
```

**Admin Create with Variants:**
```
POST /api/v1/products
Content-Type: multipart/form-data
Fields:
  name[en]: "Wireless Headphones"
  name[ar]: "سماعات لاسلكية"
  description[en]: "Premium wireless headphones..."
  product_type: "variable"
  categories: [1, 2]
  price: 99.99
  in_stock: 1
  has_discount: 1
  discount_type: "percentage"
  discount_amount: 20
  variants[0][price]: 109.99
  variants[0][quantity]: 20
  variants[0][attribute_values]: [1, 3]
  images[]: (file upload)
```
