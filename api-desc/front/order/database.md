# Database - Order Feature

## Tables

### `orders` Table

**Relevant migrations:**

- `2026_07_27_081603_add_order_status_columns_to_orders_table.php` — lifecycle columns
- `2026_08_19_000001_add_processing_to_orders_status_enum.php` — extends status enum with `processing`
- plus currency snapshot, pickup snapshot, governorate, inventory_restored_at migrations

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `id` | bigint unsigned PK | | |
| `user_id` | FK → users | | ownership scope |
| `governorate_id` | nullable FK | | shipping |
| `name`, `user_phone`, `user_email` | varchar | | contact snapshot |
| `address` | json | | |
| `notes` | text nullable | | |
| `shipping_method` | varchar | `SCHEDULED` | `SCHEDULED\|FAST` |
| `fulfillment_type` | varchar | `delivery` | `delivery\|pickup` |
| `payment_method` | varchar nullable | | `online\|cod\|pay_at_cashier` |
| `payment_gateway` | varchar nullable | | e.g. `myfatoorah` |
| `pickup_location_*` | mixed nullable | | snapshot columns |
| `price` | decimal(10,2) | subtotal |
| `shipping_price` | decimal(10,2) | 0 |
| `total_price` | decimal(10,2) | grand total (+ currency snapshot cols) |
| `coupon*`, `promotion*` | mixed | discount snapshots |
| `status` | ENUM(`pending`,`processing`,`completed`,`delivered`,`cancelled`) | `pending` | NOT NULL (SQLite: string fallback) |
| `payment_status` | string nullable | | `payment-pending\|payment-success\|payment-failed\|payment-refunded` |
| `fulfillment_status` | string nullable | | `pending\|processing\|ready_for_pickup\|out_for_delivery\|delivered\|cancelled` |
| `coupon_consumed` | boolean | false | coupon idempotency |
| `promotion_consumed` | boolean | false | promotion idempotency |
| `paid_at` | timestamp nullable | | set when tx paid |
| `completed_at` | timestamp nullable | | set on completed |
| `cancelled_at` | timestamp nullable | | set on first-time cancel |
| `inventory_restored_at` | timestamp nullable | | double-restore guard |

**Ordering:** global model scope `ORDER BY created_at DESC`.

### `order_products` Table

Price-snapshot rows per item: `product_id`, `product_variant_id`, `product_name/sku`, `attributes`, `product_quantity`, `product_price`, `product_total_price`, `product_discount_price`, `product_flash_sale_price`, `promotion_discount_amount`, `is_gift`, `promotion_id`, currency snapshot columns.

### `transactions` Table

`order_id`, `uuid` UNIQUE (auto-generated), `invoice_id`, `payment_method`, `status` (`pending|paid|failed`), `amount`, `currency`, `gateway_transaction_id`, `gateway_response` (json), `error_message`, `qr_code_url`, `paid_at`.

Row states drive idempotency for callbacks and mark-paid flows.

## Query Patterns

| Use Case | Query |
|----------|-------|
| My orders | `Order::forUser($userId)->when(status)->with([...])->paginate($limit)` (ordered by created_at DESC) |
| Order detail | same + `find($id)` scoped to user |
| Admin status change | `SELECT ... FOR UPDATE` then `UPDATE orders SET status=?, fulfillment_status=?, <timestamps>` inside one transaction |
| Completion effects | transaction row → `paid/paid_at`; coupon usage inserts; promotion finalize |
| Cancellation effects | transaction row → `failed`; promotion decrement; inventory restore once via listener |

> Direct pattern `Order::where('id',$id)->update(['status'=>...])` is **not used anywhere** in current controllers/services — all writes pass through `OrderService::changeOrderStatus()` validation.
