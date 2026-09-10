# Backend - Tax System (Direct Rate)

## Overview

`TaxCalculator` sole math (integer cents, largest-remainder). `ProductTaxPresenter` `tax_enabled/rate` → catalog price. `OrderService.withTaxes` single point after promotion/coupon, before shipping, reading `Product.tax_enabled/rate` and `Settings.order_tax_enabled/rate`. Snapshots on `orders`/`order_products` via `OrderCreationService` (`hasColumn` guards for rolling deploy). No `TaxClass` indirection.

## Write Paths

### Migrations `2026_09_09_000001_simplify_tax_to_direct_rates.php`

1. ADD `products.tax_enabled bool default false`, `tax_rate decimal(6,3) null`
2. ADD `settings.order_tax_enabled bool default false`, `order_tax_rate decimal(6,3) null`
3. ADD `orders.product_taxable_amount 10,3 null, order_tax_rate 8,3 null, order_taxable_amount 10,3 null, order_tax_amount 10,3 default 0` (+ keep `product_tax_amount`)
4. ADD `order_products.product_taxable_amount 10,3 null` (keep `product_tax_rate/amount`)

### Product Assignment

`ProductCreateRequest|ProductUpdateRequest`: `tax_enabled sometimes boolean`, `tax_rate nullable numeric 0..100`. `ProductImportService` `tax_enabled/tax_rate` columns (empty clear, invalid → row error).

### Storefront Presentation

`ProductService.enrichProductWithPricing` → `final_price` (`ProductPricingService`) → `ProductTaxPresenter.applyTo(product, final_price)` (catalog) → `current_price` (respected by `Product::getCurrentPriceAttribute` pre-set guard) → `ConvertsProductPrice` → effective (`X-Currency`/auth). No `TaxClassMap`.

### Checkout (single point)

```
OrderService.withTaxes(totals, cart):
  taxableLine = lineNetAfterPromotion - allocatedCouponShare (TaxCalculator.allocate, clamped)
  productTax: groupLineTaxes(taxableLine where product.tax_enabled, rate) -> lineTaxes{taxable,rate,amount}, productTaxable Σ, productTax Σ
  orderTaxable = Σ taxableLine (same base, never productTax, never shipping)
  orderTax = order_tax_enabled && rate>0 ? amountOn(orderTaxable, rate) : 0
  => TaxBreakdown {productTaxable, productTax, orderTaxRate, orderTaxable, orderTax, lineTaxes}
```

`calcInvoicePrice` and `addItemsInOrder`/`FastShippingService` call `withTaxes` then `OrderCreationService.createOrder` (`total = finalTotal + productTax + orderTax + shipping + fastFee`).

### Order Snapshot

`OrderCreationService.createOrder/updateOrder` `if hasColumn(order_tax_rate)` → `product_taxable_amount, product_tax_amount, order_tax_rate, order_taxable_amount, order_tax_amount`; `createOrderItems` `product_tax_rate/taxable_amount/amount` per line (gift 0). `SyncOrderItems` deletes/recreates.

### Invoice / Payment

`InvoiceSnapshotService` reads `orders.*` + `order_products.product_tax_*` only; `PaymentCheckoutHandler` reads `order.total_price`.

## Read Paths

`OrderService.enrichOrderItemsPricing` → `ProductService.enrichProductWithPricing` (direct, no map)
`OrderResource` `tax{product_taxable_amount, product_tax_amount, order_tax_rate, order_taxable_amount, order_tax_amount}`
`Marvel\ProductResource` `tax_enabled/rate, tax{enabled,rate,amount}, price_including_tax`

## Permissions

Only `update-settings` (order tax) + product perms.

## Cache

`ProductObserver` flushes `products` on `tax_enabled/rate` change; `Settings` flushes `settings` on `order_tax_*` change (product caches warm, order tax doesn't affect `current_price`).
