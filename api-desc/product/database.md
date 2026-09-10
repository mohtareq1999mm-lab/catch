# Product Module — Database Schema

## Table: `products`

The core products table with 59 fillable fields, SoftDeletes, JSON translations.

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| id | BIGINT PK AI | | |
| name | JSON | | Translatable `{ en, ar }` |
| slug | VARCHAR | | Unique, sluggable |
| description | TEXT | nullable | Translatable `{ en, ar }` |
| price | DECIMAL(10,2) | nullable | |
| sku | VARCHAR | nullable | Auto-generated: `PRD-{id+1:03d}` |
| product_type | ENUM | `simple` | `simple`, `variable` |
| quantity | INTEGER | 0 | |
| stock_quantity | INTEGER | 0 | |
| reserved_quantity | INTEGER | 0 | |
| sold_quantity | INTEGER | 0 | |
| in_stock | BOOLEAN | true | |
| status | BOOLEAN/VARCHAR | false | Also accepts enum values |
| pieces | INTEGER | 1 | |
| has_discount | BOOLEAN | false | |
| has_flash_sale | BOOLEAN | false | |
| is_fast_shipping_available | BOOLEAN | false | |
| discount_type | ENUM | `percentage` | `percentage`, `fixed_rate`, `free_shipping` |
| discount_amount | DOUBLE(10,2) | 0 | |
| discount_status | BOOLEAN | nullable | |
| start_date | DATE | nullable | Discount start |
| end_date | DATE | nullable | Discount end |
| price_after_discount | DECIMAL(10,2) | nullable | Computed on save |
| price_after_flash_sale | DECIMAL(10,2) | nullable | Computed on save |
| height | VARCHAR | nullable | |
| width | VARCHAR | nullable | |
| length | VARCHAR | nullable | |
| weight | VARCHAR | nullable | |
| deleted_at | TIMESTAMP | nullable | Soft deletes |
| created_at | TIMESTAMP | | |
| updated_at | TIMESTAMP | | |

### Indexes
- `INDEX price`
- `INDEX sold_quantity`
- `INDEX name`
- `INDEX slug`
- `INDEX sku`
- `INDEX is_fast_shipping_available`
- `INDEX (status, deleted_at, price) AS idx_products_status_deleted_price`
- `INDEX height/width/length/weight`

## Table: `product_variants`

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK AI | |
| product_id | BIGINT FK | `products.id` CASCADE |
| sku | VARCHAR | UNIQUE |
| price | DECIMAL(10,2) | |
| sale_price | DECIMAL(10,2) | nullable |
| quantity | INTEGER | 0 |
| stock_quantity | INTEGER | 0 |
| reserved_quantity | INTEGER | 0 |
| sold_quantity | INTEGER | 0 |
| in_stock | BOOLEAN | true |
| height/width/length/weight | VARCHAR | nullable |
| timestamps | | |

**Index:** `INDEX(product_id, sku, price, height, width, length, weight)`

## Pivot Tables

| Table | Columns | FK1 | FK2 |
|-------|---------|-----|-----|
| `category_product` | product_id, category_id | products CASCADE | categories CASCADE |
| `brand_product` | product_id, brand_id | products CASCADE | brands CASCADE |
| `product_tag` | tag_id, product_id | tags CASCADE | products CASCADE |
| `banner_product` | banner_id, product_id | banners CASCADE | products CASCADE |
| `slider_product` | slider_id, product_id | sliders CASCADE | products CASCADE |
| `flash_sale_products` | flash_sale_id, product_id | flash_sales CASCADE | products CASCADE |
| `promotion_product` | promotion_id, product_id | promotions CASCADE | products CASCADE |
| `coupon_product` | coupon_id, product_id | coupons CASCADE | products CASCADE |
| `product_shop` | product_id, shop_id | products CASCADE | shops CASCADE |
| `attribute_product` | attribute_value_id, product_variant_id | attribute_values CASCADE | product_variants CASCADE |
| `dropoff_location_product` | product_id, resource_id | resources CASCADE | products CASCADE |
| `pickup_location_product` | product_id, resource_id | resources CASCADE | products CASCADE |
| `deposit_product` | product_id, resource_id | resources CASCADE | products CASCADE |
| `person_product` | product_id, resource_id | resources CASCADE | products CASCADE |
| `feature_product` | product_id, resource_id | resources CASCADE | products CASCADE |

## Cascade Chain

```
DELETE product
  → product_variants (CASCADE)
    → attribute_product (CASCADE via variant)
  → category_product (CASCADE)
  → brand_product (CASCADE)
  → product_tag (CASCADE)
  → banner_product (CASCADE)
  → slider_product (CASCADE)
  → flash_sale_products (CASCADE)
  → reviews (SET NULL or CASCADE)
  → wishlists (CASCADE)
  → questions (CASCADE)
```

## Table: `tags`

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK AI | |
| name | JSON | Translatable `{ en, ar }` |
| slug | VARCHAR | Unique |
| image | JSON | Media library reference |
| icon | JSON | Media library reference |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

**Relations:** BelongsToMany `products` via `product_tag` pivot.

### Media
- Tags use Spatie Media Library via `InteractsWithMedia` trait
- `image` and `icon` are stored as media collections (`tags` collection)
- Accessible via `$tag->getFirstMediaUrl('tags')`

## Table: `product_tag` (pivot)

| Column | Type | FK |
|--------|------|-----|
| product_id | BIGINT FK | products.id CASCADE |
| tag_id | BIGINT FK | tags.id CASCADE |

**Primary Key:** (product_id, tag_id)
