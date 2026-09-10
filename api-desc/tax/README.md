# Tax System - API Investigation

## Feature

Direct-rate tax: `products.tax_enabled/tax_rate` + `settings.order_tax_enabled/rate`, snapshots on `orders`/`order_products`.

## Architecture

```
Product {tax_enabled bool, tax_rate decimal(6,3)} -> ProductTaxPresenter.applyTo (catalog, integer cents)
Settings {order_tax_enabled bool, order_tax_rate decimal(6,3)} -> OrderService.withTaxes (same base, never shipping)

OrderService.withTaxes (single point, after promotion/coupon, before shipping):
  taxableLine = lineNetAfterPromotion - allocatedCouponShare (largest-remainder)
  productTaxable = Σ taxableLine (where product tax_enabled)
  productTax = Σ groupLineTaxes(taxableLine, product tax_rate)
  orderTaxable = Σ taxableLine (same base, never productTax)
  orderTax = order_tax_enabled ? round(orderTaxable * order_tax_rate) : 0
  => TaxBreakdown {productTaxable, productTax, orderTaxRate, orderTaxable, orderTax, lineTaxes{taxable,rate,amount}}
  => OrderCreationService snapshot -> order.total_price = finalTotal + productTax + orderTax + shipping + fastFee

Invoice reads snapshots only.
```

## Endpoints (final)

| Method | Path | Guard | Permission | Purpose |
|--------|------|-------|------------|---------|
| GET/PUT | `/api/v1/settings` | sanctum | `update-settings` | `order_tax_enabled` + `order_tax_rate` (global order tax) |
| POST/PUT | `/api/v1/products` | sanctum | product perms | `tax_enabled` + `tax_rate` (0..100) |
| POST | `/api/v1/products/import` | sanctum | product perms | columns `tax_enabled`, `tax_rate` |
| GET | `/api/v1/products/export` | sanctum | product perms | `tax_enabled`, `tax_rate` |
| GET | `/api/v1/general/products*`, `.../{slug}` | — | — | `current_price` tax-inclusive (product tax only, converted) |
| GET | `/api/v1/products` (admin) | sanctum | — | `tax_enabled`, `tax_rate`, `tax{enabled,rate,amount}`, `price_including_tax` (catalog) |
| GET | `/api/v1/general/orders*` | sanctum | — | `tax{product_taxable_amount, product_tax_amount, order_tax_rate, order_taxable_amount, order_tax_amount}` |

## Permissions

Only `update-settings` for `order_tax_*` (via settings) and existing product permissions for `tax_enabled/rate`.
