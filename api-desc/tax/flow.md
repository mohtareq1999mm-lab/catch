# Flow - Tax System (Direct Rate)

## Product Storefront

```
GET /api/v1/general/products/{slug}
 -> ProductService.getProductBySlug
    -> ProductPricingService.calculateProductPricing -> final_price
    -> ProductTaxPresenter.applyTo(product.tax_enabled/rate, final_price) // integer cents
    -> setAttribute('current_price', tax_inclusive) // respected by Product::getCurrentPriceAttribute
 -> ProductResource
    -> ConvertsProductPrice.convertCatalogPrice(current_price) // effective / X-Currency
    -> {price: base, current_price: tax-inclusive}
```

`paginate`/`enrichCollection` now zero tax queries (was `TaxClassMap`).

## Product Admin

```
GET /api/v1/products (Marvel)
 -> Marvel\ProductResource
    -> ProductTaxPresenter.describe(product, current_price) -> {tax_enabled, tax_rate, amount}
    -> price_including_tax = applyTo(...)
 -> {price, current_price, tax_enabled, tax_rate, tax{enabled,rate,amount}, price_including_tax}
```

`current_price` pre-tax preserved.

## Checkout

```
POST /api/v1/general/checkout {name, phone, address, governorate_id?, coupon?}
 -> OrderService.addItemsInOrder (transaction, lock cart)
    -> refreshCartItemPrices (ProductPricingService, net)
    -> calculateCheckoutTotals (promotion/coupon -> finalTotal)
    -> resolveShippingPrice (governorate) + fastFee
    -> withTaxes(totals, cart) // product taxableLine = lineNet - allocatedCouponShare, productTax, orderTaxable = Σ taxableLine, orderTax
    -> OrderCreationService.createOrder/updateOrder (hasColumn guards, total = finalTotal+productTax+orderTax+shipping+fastFee, snapshots)
    -> createOrderItems (product_tax_rate/taxable_amount/amount per line, gift 0)
    -> reserveForOrder -> commit -> finalizeOrder
 -> Transaction.amount = total_price
```

`FastShipping` mirrors. `calcInvoicePrice` includes `withTaxes`. Shipping never in `order_taxable_amount`.

## Order / Invoice

```
GET /api/v1/general/orders/{id} -> OrderResource.tax{product_taxable_amount, product_tax_amount, order_tax_rate, order_taxable_amount, order_tax_amount}
InvoiceSnapshotService -> pricing_breakdown {product_taxable, product_tax, order_taxable, order_tax, shipping, fastFee, total}, items product_tax_*, taxes[]
```

No `Product`/`Settings` current lookup for historical rows.

## Import / Export

```
POST /api/v1/products/import (tax_enabled, tax_rate columns)
 -> ProductImportService: tax_enabled boolean, tax_rate 0..100, empty rate->null, invalid->row error
GET /api/v1/products/export -> tax_enabled, tax_rate
```

## Cache

```
Product tax_enabled/rate change -> ProductObserver flush products, products_<strategy>
Settings order_tax_* change -> flush settings + cached_settings_* (products untouched)
```
