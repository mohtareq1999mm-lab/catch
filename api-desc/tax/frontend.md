# Frontend - Tax System (Direct Rate)

## Storefront (`general/*`)

| Need | How |
|------|-----|
| `current_price` | **Tax-inclusive** (`effective + product tax`, converted). Don't compute `price*rate` client-side. |
| `price` | Base pre-tax (converted) for strikethrough. |
| `currency` | Same product response, guest `X-Currency` / auth effective. |
| Checkout totals | Don't trust frontend totals; `POST /api/v1/general/checkout` returns authoritative `order.total`. |
| Order tax | From `GET /api/v1/general/orders/{id}` `tax{product_taxable_amount, product_tax_amount, order_tax_rate, order_taxable_amount, order_tax_amount}`. |

```json
// GET /api/v1/general/products/widget
{"price":100.0,"current_price":120.0,"currency":{"code":"EGP"}}
{"price":100.0,"current_price":100.0} // when tax_enabled false
```

## Admin

| Need | How |
|------|-----|
| Product | `POST|PUT /api/v1/products` `{tax_enabled, tax_rate}` (`tax_rate` 0..100, `false`→`amount 0` but rate preserved). `GET` shows `tax_enabled, tax_rate, tax{enabled,rate,amount}, price_including_tax`. |
| Settings | `PUT /api/v1/settings` `{order_tax_enabled, order_tax_rate}` (`update-settings`), order-only. |
| Orders | `tax{product_taxable_amount, product_tax_amount, order_tax_rate, order_taxable_amount, order_tax_amount}` (snapshot) |
| Import | `tax_enabled`, `tax_rate` columns; Export `tax_enabled`, `tax_rate`. |

## QA Checklist

- Storefront 100+20%→120, disabled→100, 0%→100, 100%→200, discount 80→96, variant inherits, order-tax isolation (global 10% doesn't affect product 115).
- Admin 100+20%→`tax 20, price_including_tax 120`, disabled→`0`.
- Order taxable 90→product 18+order 9+shipping20→137, coupon 10→80→16+8→124, shipping excluded.
- Historical: change product/settings rate → old order/invoice unchanged.
- Import empty→clear, invalid→row error, 0% accepted.
