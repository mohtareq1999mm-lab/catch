# API Documentation - Tax System (Direct Rate)

## Storefront Product (tax-inclusive `current_price`)

**GET** `/api/v1/general/products`, `GET /api/v1/general/products/{slug}` etc. (home/brand/banner/category/flash)

| Auth | Permission |
|------|------------|
| No | — |

**Response (200)**
```json
{
  "status": 200, "success": true, "data": {
    "id": 1, "name": "Widget", "price": 100.0, "current_price": 120.0,
    "currency": {"code":"EGP"},
    "variants": [{"id":10,"price":100.0,"current_price":120.0}]
  }
}
```
`price` = base (converted), `current_price` = effective + **product tax only** (catalog `tax_enabled/rate` → integer-cent, then `ConvertsProductPrice`).

**Errors:** 404 not found.

---

## Admin Product (pre-tax + breakdown)

**GET** `/api/v1/products`, `GET /api/v1/products/{id}` (Marvel)

| Auth | Permission |
|------|------------|
| Yes | product perms |

**Response (200)**
```json
{
  "price": 100.0, "current_price": 100.0,
  "tax_enabled": true, "tax_rate": 20.0,
  "tax": {"tax_enabled":true,"tax_rate":20.0,"amount":20.0},
  "price_including_tax": 120.0
}
```
Disabled/no rate: `tax: null` or `amount 0`, `price_including_tax == current_price`.

---

## Product Create / Update

**POST** `/api/v1/products`, **PUT** `/api/v1/products/{id}`

| Auth | Permission |
|------|------------|
| Yes | product perms |

**Body**
```json
{"tax_enabled": true, "tax_rate": 20.0}
```
Validation: `tax_enabled sometimes boolean`, `tax_rate nullable numeric min:0 max:100`. Disabled → `amount 0` but rate preserved.

**Errors:** 422 validation.

---

## Settings - Order Tax (global)

**PUT** `/api/v1/settings`

| Auth | Permission |
|------|------------|
| Yes | `update-settings` |

**Body**
```json
{"order_tax_enabled": true, "order_tax_rate": 10.0}
```
Validation: `order_tax_enabled sometimes boolean`, `order_tax_rate nullable numeric 0..100`. `false` → `order_tax_amount 0` for new orders, `null` rate allowed.

**Response (200)** `SettingResource` with `order_tax_enabled/rate`.

---

## Orders

**POST** `/api/v1/general/checkout`, **POST** `/api/v1/fast-shipping/checkout`
- Total `finalTotal + productTax + orderTax + shipping + fastFee` (shipping never taxable, productTax not in orderTax base).

**GET** `/api/v1/general/orders`, `GET /api/v1/general/orders/{id}`

```json
{
  "tax": {
    "product_taxable_amount": 90.0,
    "product_tax_amount": 18.0,
    "order_tax_rate": 10.0,
    "order_taxable_amount": 90.0,
    "order_tax_amount": 9.0
  },
  "order_items": [{"product_tax_rate":20.0,"product_taxable_amount":90.0,"product_tax_amount":18.0}]
}
```

**Invoice** `pricing_breakdown` / `taxes[]` and `OrderItemResource` read the same snapshots; `converted_*` via order rate.

---

## Import / Export

**POST** `/api/v1/products/import` (products sheet `tax_enabled`, `tax_rate` columns)
- `tax_enabled` `true/false/1/0`, `tax_rate` `0..100`; empty `tax_rate` → `null`; invalid → row error.

**GET** `/api/v1/products/export` → `tax_enabled`, `tax_rate`.

---
