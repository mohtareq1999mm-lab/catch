# Database - Search Feature

No search-specific tables. A global search would query across:

| Table | Searchable Fields |
|-------|------------------|
| `products` | name (JSON), description (JSON), sku |
| `categories` | name (JSON), slug |
| `brands` | name (JSON), slug |
| `banners` | title (JSON), slug |
| `sliders` | title (JSON), slug |
| `coupons` | code, name |
| `flash_sales` | title (JSON) |
| `promotions` | name (JSON), slug |
| `content_pages` | title (JSON), content (JSON) |
