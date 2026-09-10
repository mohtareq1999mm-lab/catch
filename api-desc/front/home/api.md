# API Documentation - Home Feature

## Endpoints

---

### 1. Get Home Page Data

**GET** `/api/v1/general/home`

**Purpose:** Retrieve all sections for the home page (sliders, banners, categories, products, brands, coupons, flash sales). Supports section filtering and parent category scoping.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `sections` | `string\|array` | No | Comma-separated section keys to filter (e.g., `sliders,brands,coupons`) |
| `keys` | `string\|array` | No | Alias for `sections` |
| `parent_category_id` | `integer` | No | Parent category ID for scoping (defaults to 1) |

#### Available Section Keys

| Key | Description |
|-----|-------------|
| `nav-bar` | Navigation bar categories |
| `active_sliders` | Hero/featured sliders |
| `active_banners` | Promotional banners |
| `brands` | Active brand list |
| `best_categories` | Top categories by product count |
| `parent_categories` | Category tree |
| `discount_products_end_today` | Discounted products ending today or low stock |
| `flash_sales` | Daily flash sale events (start=end=today) |
| `flash_sale_products` | Products with flash sales ending this week |
| `weekly_parent_categories` | Weekly featured parent categories |
| `weekly_products` | Discounted products in weekly categories |
| `all_discount_products` | All currently discounted products |
| `flash_sales_after_9` | New arrivals (last 15 days) |
| `coupons` | Latest valid coupons |

#### Success Response (200)

```json
{
    "status": 200,
    "success": true,
    "message": "Data fetched successfully",
    "data": {
        "sliders": [
            { "id": 1, "title": "Summer Sale", "image": { "desktop": "...", "mobile": "..." } }
        ],
        "dailyOffers": [
            { "id": 1, "title": "Flash Deal", "type": "percentage", "discount": 50 }
        ],
        "bestCategories": [
            { "id": 1, "name": "Electronics", "slug": "electronics", "products_count": 50 }
        ],
        "discountProductsEndToday": [
            { "id": 1, "name": "Wireless Headphones", "current_price": 79.99 }
        ],
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

---

### 2. Get Navigation Data

**GET** `/api/v1/general/nav-data`

**Purpose:** Retrieve category tree for the navigation bar with depth control.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `level` | `integer` | No | Max depth level for children (default 3) |

#### Success Response (200)

```json
{
    "status": 200,
    "success": true,
    "message": "Data fetched successfully",
    "data": [
        {
            "id": 1,
            "name": "Electronics",
            "slug": "electronics",
            "level": 1,
            "image": { "desktop": "...", "mobile": "..." },
            "children": [
                {
                    "id": 10,
                    "name": "Headphones",
                    "slug": "headphones",
                    "level": 2,
                    "children": [
                        { "id": 100, "name": "Wireless", "slug": "wireless", "level": 3, "children": [] }
                    ]
                }
            ]
        }
    ]
}
```

## Business Rules

1. **Channel-Aware:** Home channel excludes `is_fast_shipping_available = true` products
2. **Caching:** All sections cached with 120 min TTL, isolated by channel (home: vs fast-shipping:)
3. **Section Filtering:** Only requested sections are computed and returned
4. **Default Parent:** If no `parent_category_id` provided, defaults to category ID 1
5. **Flash Sale Daily Offers:** Only flash sales where start_date = end_date = today appear in `dailyOffers`
6. **Product Limits:** Hard-coded limits per section (5-20 items, no pagination)
