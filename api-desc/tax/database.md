# Database - Tax System (Direct Rate)

## Tables (final)

### `products` (added)

| Column | Type | Default | FK |
|--------|------|---------|----|
| `tax_enabled` | `boolean` | `false` | — |
| `tax_rate` | `decimal(6,3)` | `NULL` | — |
| ~~`tax_class_id`~~ | — | removed | `tax_classes` dropped |

### `settings` (added)

| Column | Type | Default |
|--------|------|---------|
| `order_tax_enabled` | `boolean` | `false` |
| `order_tax_rate` | `decimal(6,3)` | `NULL` |
| ~~`default_tax_class_id`~~ | removed |  |

### `orders` (final tax snapshot)

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `product_taxable_amount` | `decimal(10,3)` | `NULL` | NEW, Σ taxableLine |
| `product_tax_amount` | `decimal(10,3)` | `0` | kept |
| `order_tax_rate` | `decimal(8,3)` | `NULL` | NEW, copy from `tax_rate` |
| `order_taxable_amount` | `decimal(10,3)` | `NULL` | NEW, copy from `taxable_amount` |
| `order_tax_amount` | `decimal(10,3)` | `0` | NEW, copy from `tax_amount` |
| ~~`tax_class_id, tax_mode, tax_name, tax_rate, taxable_amount, tax_amount, tax_override_*`~~ | removed |

### `order_products` (added)

| Column | Type | Default |
|--------|------|---------|
| `product_tax_rate` | `decimal(8,3)` | `NULL` | kept |
| `product_taxable_amount` | `decimal(10,3)` | `NULL` | NEW per-line taxable |
| `product_tax_amount` | `decimal(10,3)` | `0` | kept |
| ~~`tax_class_id` FK~~ | dropped |

### `tax_classes` (dropped)

`2026_09_08_000001` created, `2026_09_09_000001` drops after backfill.

## Migration `2026_09_09_000001_simplify_tax_to_direct_rates.php`

1. ADD new columns (all `hasColumn` guarded)
2. Backfill: `products` (`tax_class_id` → `tax_enabled/rate` where active), `settings` (`default_tax_class_id` → `order_tax_*`), `orders` (`tax_*` → `order_tax_*`, `tax_amount`→`order_tax_amount`), `order_products` `product_taxable_amount` stays `NULL` for old rows (not guessed)
3. DROP FKs then columns `tax_class_id, default_tax_class_id, tax_override_*, tax_mode, tax_name, tax_rate, taxable_amount, tax_amount` and table `tax_classes`
4. `down` recreates legacy `tax_classes` + FKs and drops new columns.

## Query Patterns

| Use Case | Query |
|----------|-------|
| Storefront current_price | No tax `WHERE IN` — `product.tax_enabled/rate` on model (0 queries) |
| Checkout withTaxes | `Product WHERE IN (cart product_ids)` for `tax_enabled/rate` (one) + `Settings::first()` for `order_tax_*` (one, `hasColumn` memoized) |
| Historical order | `orders`/`order_products` snapshots only, no `tax_classes` join |
| Admin product tax | `tax_enabled/rate` on model (0 queries) |

## Fresh DB

`tax_classes` does not exist; `tax_class_id`/`default_tax_class_id` absent; new `tax_enabled/rate`, `order_tax_*`, `product_taxable_amount` present with correct defaults/nulls and no FKs. Verified `php scripts/verify_migrations.php --fresh` `2026_09_09... 585ms DONE`.
