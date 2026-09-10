# Invoice Contents: Complete Field Reference

> **Source code**: `InvoiceSnapshotService@buildFullSnapshot`, `InvoiceResource@toArray`, `InvoiceSnapshotResource@toArray`

---

## 1. Invoice Header Fields (from `invoices` table)

| Field | Source | Mutable? | Description |
|-------|--------|----------|-------------|
| `id` | Auto-increment | ❌ Immutable | Primary key |
| `uuid` | `Str::orderedUuid()` on create | ❌ Immutable | Public identifier, used in URLs and QR |
| `order_id` | `Order.id` at generation | ❌ Immutable | FK to orders |
| `transaction_id` | `Transaction.id` (paid transaction) | ❌ Immutable | FK to transaction |
| `user_id` | `Order.user_id` | ❌ Immutable | FK to users |
| `invoice_number` | `InvoiceNumberService@generateNext` | ❌ Immutable | Format: `{series}-{year}-{sequence}` e.g. INV-2026-000001 |
| `invoice_series` | Default 'INV' | ❌ Immutable | Series prefix |
| `sequence_number` | Auto-increment per series+year | ❌ Immutable | Sequential number |
| `sequence_year` | Current year | ❌ Immutable | Year of sequence |
| `status` | `InvoiceStatus` enum | ✅ Mutable | See state machine |

## 2. Financial Fields

| Field | Source | Mutable? | Notes |
|-------|--------|----------|-------|
| `subtotal` | `Order.price` | ❌ Immutable | Sum of item prices before discounts |
| `shipping_price` | `Order.shipping_price` | ❌ Immutable | Shipping cost at order time |
| `coupon_discount` | `Order.coupon_discount` | ❌ Immutable | Coupon discount applied |
| `promotion_discount` | `Order.promotion_discount` | ❌ Immutable | Promotion discount applied |
| `total_discount` | `coupon_discount + promotion_discount` | ❌ Immutable | Computed at generation |
| `total` | `Order.total_price` | ❌ Immutable | Final total after all discounts |
| `amount_paid` | Same as `total` | ❌ Immutable | Amount recorded as paid |
| `currency` | `Transaction.currency ?? 'EGP'` | ❌ Immutable | 3-letter ISO code |

## 3. Payment Fields

| Field | Source | Mutable? |
|-------|--------|----------|
| `payment_method` | `Order.payment_method` | ❌ Immutable |
| `payment_gateway` | `Order.payment_gateway` | ❌ Immutable |

## 4. Integrity Fields

| Field | Source | Mutable? | Description |
|-------|--------|----------|-------------|
| `data` | `InvoiceSnapshotService@buildFullSnapshot` | ❌ Immutable | JSON snapshot of order at generation time (schema v3) |
| `snapshot_hash` | `SnapshotIntegrityService@computeHash` | ❌ Immutable | SHA-256 of sorted JSON snapshot |
| `verification_hash` | `SHA256(snapshot_hash + app_key)` | ❌ Immutable | HMAC-like verification hash |

## 5. PDF Tracking Fields

| Field | Mutable? | Description |
|-------|----------|-------------|
| `pdf_generated_at` | ✅ First write, then immutable | Set when PDF is first generated |
| `pdf_regenerated_at` | ✅ Updated on each regeneration | Set when PDF is re-generated |
| `pdf_path` | ✅ Updated on each generation | Storage path |
| `pdf_checksum` | ✅ Updated on each generation | SHA-256 of PDF file |
| `generation_attempts` | ✅ Incremented on each attempt | Counter |
| `last_generation_error` | ✅ Updated on failure | Error message |

## 6. Lifecycle Timestamps

| Field | Mutable? | Set When |
|-------|----------|----------|
| `generated_at` | ❌ Immutable | Invoice creation |
| `generated_by` | ❌ Immutable | 'system' at creation |
| `verified_at` | ✅ First verification only | First `verify()` call |
| `downloaded_at` | ✅ First download only | First `download()` call |
| `printed_at` | ✅ First print only | First print event |
| `archived_at` | ✅ When archived | Archive transition |
| `last_verified_at` | ✅ Updated on every verify | Each `verify()` call |
| `verify_count` | ✅ Incremented on each verify | Counter |
| `corrected_at` | ✅ When corrected | Correction creation |
| `cancelled_at` | ✅ When cancelled | Cancellation |

## 7. Correction Fields

| Field | Mutable? | Description |
|-------|----------|-------------|
| `correction_to_id` | ❌ Immutable | FK to original invoice |
| `is_correction` | ❌ Immutable | Boolean flag |
| `correction_reason` | ✅ Mutable | Reason text |
| `cancellation_reason` | ✅ Mutable | Reason text |

---

## 8. Snapshot Contents (`data` JSON column)

The snapshot is versioned (`snapshot_version: 2.1.0`, `snapshot_schema: 3`).  
ALL fields in the snapshot are **IMMUTABLE** — they are a point-in-time capture.

### 8.1 Order Section
```json
{
  "id": 123,
  "order_number": "ORD-00000123",
  "status": "completed",
  "payment_status": "payment-success",
  "fulfillment_status": "fulfillment-processing"
}
```
**Sources**: `Order.id`, `Order.order_number` (computed: ORD-padded(ID)), `Order.status`, `Order.payment_status`, `Order.fulfillment_status`

### 8.2 Customer Section
```json
{
  "id": 456,
  "name": "Ahmed Ali",
  "email": "ahmed@example.com",
  "phone": "+201234567890"
}
```
**Sources**: `Order.user_id`, `Order.name`, `Order.user_email`, `Order.user_phone`

### 8.3 Address Section
```json
{
  "street": "123 Main St",
  "city": "Cairo",
  "state": null,
  "governorate": "Cairo Governorate",
  "zip": null,
  "country": null,
  "coordinates": null
}
```
**Sources**: `Order.address[street/city/state/zip/country/coordinates]`, `Order.governorate.name`

### 8.4 Fulfillment Section
```json
{
  "type": "delivery",
  "shipping_method": "SCHEDULED",
  "shipping_price": 50.00,
  "fast_shipping_fee": 0,
  "expected_delivery_at": "2026-08-03T10:00:00+00:00"
}
```
**Sources**: `Order.fulfillment_type`, `Order.shipping_method`, `Order.shipping_price`, `Order.fast_shipping_fee`, `Order.expected_delivery_at`

### 8.5 Pickup Location Section (only if fulfillment_type=pickup)
```json
{
  "id": 1,
  "name": "Downtown Store",
  "address": "456 Mall St, Cairo",
  "phone": "+201098765432",
  "coordinates": "30.0444,31.2357"
}
```
**Sources**: `Order.pickup_location_id/name/address/phone/coordinates`

### 8.6 Items Section
```json
[
  {
    "product_id": 789,
    "product_variant_id": null,
    "product_name": "Product Name",
    "product_sku": "SKU-123",
    "attributes": null,
    "quantity": 2,
    "unit_price": 100.00,
    "original_price": 100.00,
    "effective_unit_price": 85.00,
    "discount_price": null,
    "flash_sale_price": null,
    "promotion_discount_amount": 15.00,
    "total_price": 170.00,
    "is_gift": false,
    "promotion_id": null,
    "images": []
  }
]
```
**Sources**: `OrderProduct` record for each item

| Field | Source Model | Source Column |
|-------|-------------|---------------|
| `product_id` | `OrderProduct` | `product_id` |
| `product_variant_id` | `OrderProduct` | `product_variant_id` |
| `product_name` | `OrderProduct` | `product_name` |
| `product_sku` | `OrderProduct` | `product_sku` |
| `attributes` | `OrderProduct` | `attributes` (JSON) |
| `quantity` | `OrderProduct` | `product_quantity` |
| `unit_price` | `OrderProduct` | `product_price` |
| `original_price` | `OrderProduct` | `product_price` |
| `effective_unit_price` | Computed | `product_discount_price ?? product_flash_sale_price ?? product_price` |
| `discount_price` | `OrderProduct` | `product_discount_price` |
| `flash_sale_price` | `OrderProduct` | `product_flash_sale_price` |
| `promotion_discount_amount` | `OrderProduct` | `promotion_discount_amount` |
| `total_price` | `OrderProduct` | `product_total_price` |
| `is_gift` | `OrderProduct` | `is_gift` |
| `promotion_id` | `OrderProduct` | `promotion_id` |

### 8.7 Pricing Breakdown Section
```json
{
  "subtotal": 200.00,
  "promotion_discount": 15.00,
  "coupon_discount": 10.00,
  "shipping_price": 50.00,
  "fast_shipping_fee": 0,
  "total": 225.00,
  "currency": "EGP",
  "exchange_rate": null,
  "coupon": {
    "code": "SAVE10",
    "type": "percentage",
    "discount": 10.00,
    "max_discount_amount": 50.00
  },
  "promotion": {
    "id": 5,
    "code": "PRO-SUMMER",
    "type": "percentage",
    "discount": 15.00
  }
}
```
**Sources**: Order columns (`price`, `promotion_discount`, `coupon_discount`, `shipping_price`, `total_price`), Coupon model, Promotion model

### 8.8 Payment Section
```json
{
  "method": "online",
  "gateway": "myfatoorah",
  "transaction_id": 999,
  "gateway_transaction_id": "MF-123456",
  "paid_at": "2026-07-27T10:30:00+00:00",
  "gateway_invoice_id": null,
  "gateway_response_summary": null
}
```
**Sources**: `Order.payment_method`, `Order.payment_gateway`, `Transaction.id`, `Transaction.gateway_transaction_id`, `Transaction.paid_at`

### 8.9 Metadata Section
```json
{
  "system_version": "1.0.0",
  "locale": "ar",
  "ip_address": null,
  "user_agent": null,
  "generated_at": "2026-07-27T10:30:05+00:00"
}
```
**Sources**: `config('app.version')`, `app()->getLocale()`, current timestamp

### 8.10 Audit Section
```json
{
  "generated_by": "system",
  "generation_attempts": 1,
  "correction_reason": null,
  "cancellation_reason": null
}
```

---

## 9. Response Fields (InvoiceResource)

| Response Field | Type | Source | Conditionally Loaded? |
|---------------|------|--------|----------------------|
| `id` | int | `invoices.id` | No |
| `uuid` | string | `invoices.uuid` | No |
| `order_id` | int | `invoices.order_id` | No |
| `invoice_number` | string | `invoices.invoice_number` | No |
| `status` | string | `invoices.status` | No |
| `subtotal` | float | `invoices.subtotal` | No |
| `shipping_price` | float | `invoices.shipping_price` | No |
| `coupon_discount` | float | `invoices.coupon_discount` | No |
| `promotion_discount` | float | `invoices.promotion_discount` | No |
| `total_discount` | float | `invoices.total_discount` | No |
| `total` | float | `invoices.total` | No |
| `amount_paid` | float | `invoices.amount_paid` | No |
| `currency` | string | `invoices.currency` | No |
| `payment_method` | string | `invoices.payment_method` | No |
| `payment_gateway` | string | `invoices.payment_gateway` | No |
| `snapshot_hash` | string | `invoices.snapshot_hash` | No |
| `verification_hash` | string | `invoices.verification_hash` | No |
| `pdf_generated_at` | ISO8601 | `invoices.pdf_generated_at` | No |
| `generated_at` | ISO8601 | `invoices.generated_at` | No |
| `generation_attempts` | int | `invoices.generation_attempts` | No |
| `last_generation_error` | string/null | `invoices.last_generation_error` | No |
| `is_correction` | bool | `invoices.is_correction` | No |
| `correction_reason` | string/null | `invoices.correction_reason` | No |
| `corrected_at` | ISO8601/null | `invoices.corrected_at` | No |
| `cancelled_at` | ISO8601/null | `invoices.cancelled_at` | No |
| `cancellation_reason` | string/null | `invoices.cancellation_reason` | No |
| `verified_at` | ISO8601/null | `invoices.verified_at` | No |
| `downloaded_at` | ISO8601/null | `invoices.downloaded_at` | No |
| `printed_at` | ISO8601/null | `invoices.printed_at` | No |
| `archived_at` | ISO8601/null | `invoices.archived_at` | No |
| `last_verified_at` | ISO8601/null | `invoices.last_verified_at` | No |
| `verify_count` | int | `invoices.verify_count` | No |
| `created_at` | ISO8601 | `invoices.created_at` | No |
| `verification_url` | string | Computed URL | When uuid exists |
| `qr_content` | object | Computed | When uuid exists |
| `download_url` | string | Computed URL | When uuid + pdf_path exist |
| `snapshot` | object | `InvoiceSnapshotResource` | When `data` is not null |
| `timeline` | array | `InvoiceTimeline` (latest 10) | When relation loaded |
| `credit_notes_summary` | object | Computed from `CreditNote` | When relation loaded |
| `debit_notes_summary` | object | Computed from `DebitNote` | When relation loaded |

---

## 10. Immutability Rules

1. **Once an invoice is created, financial fields NEVER change**
2. Corrections create a **new** invoice (`correction_to_id` points to original)
3. Credit/debit notes are **separate documents** — they never modify the original invoice
4. The `data` snapshot is write-once — never updated after creation
5. `snapshot_hash` and `verification_hash` are cryptographic — any change would invalidate verification
6. Only lifecycle timestamps (`verified_at`, `downloaded_at`, etc.) and PDF/status fields are mutable
