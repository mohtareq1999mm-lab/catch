# Database — Shipment Module

## Table: `shipments`

**Migration:** `database/migrations/2026_07_28_000004_create_shipments_table.php`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| uuid | uuid | | UNIQUE, NOT NULL | Auto-generated via Str::orderedUuid() |
| order_id | bigint unsigned | | FK → orders.id, RESTRICT ON DELETE | Cannot delete order with shipments |
| tracking_number | varchar(100) | NULL | UNIQUE, NULLABLE | |
| courier | varchar(50) | NULL | | e.g., DHL, FedEx, ARAMEX |
| status | varchar(30) | `'pending'` | NOT NULL | See ShipmentStatus enum |
| shipping_method | varchar(30) | NULL | | e.g., standard, express |
| shipping_cost | decimal(10,3) | 0 | | Precision to 3 decimal places |
| currency | varchar(3) | `'EGP'` | | 3-letter ISO code |
| origin_address | json | NULL | | Flexible address structure |
| destination_address | json | NULL | | Flexible address structure |
| items | json | NULL | | Array of line items |
| total_weight | decimal(10,3) | NULL | | |
| weight_unit | varchar(10) | `'kg'` | | e.g., kg, lb |
| shipped_at | timestamp | NULL | | Set when status → shipped/picked_up |
| estimated_delivery_at | timestamp | NULL | | Estimated arrival |
| delivered_at | timestamp | NULL | | Set when status → delivered |
| notes | text | NULL | | Max 2000 (validated in requests) |
| metadata | json | NULL | | Arbitrary additional data |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes

| Index Name | Columns | Type | Purpose |
|------------|---------|------|---------|
| PRIMARY | `id` | PK | Row identifier |
| `shipments_uuid_unique` | `uuid` | UNIQUE | UUID lookup |
| `shipments_tracking_number_unique` | `tracking_number` | UNIQUE | Tracking number uniqueness |
| `idx_ship_order_id` | `order_id` | INDEX | Filter by order |
| `idx_ship_status` | `status` | INDEX | Filter by status |
| `idx_ship_tracking` | `tracking_number` | INDEX | Tracking number search |

### Foreign Keys

| Column | Referenced Table | Referenced Column | On Delete |
|--------|-----------------|-------------------|-----------|
| `order_id` | `orders` | `id` | RESTRICT |

**Note:** `restrictOnDelete` means an order with existing shipments cannot be deleted. This prevents orphaned shipment records.

---

## Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `orders` | BelongsTo | `shipments.order_id` → `orders.id` |

---

## Casts

| Column | Cast Type | Notes |
|--------|-----------|-------|
| `origin_address` | array | JSON decoded to array |
| `destination_address` | array | JSON decoded to array |
| `items` | array | Array of shipment line items |
| `metadata` | array | Arbitrary metadata |
| `shipped_at` | datetime | Carbon instance |
| `estimated_delivery_at` | datetime | Carbon instance |
| `delivered_at` | datetime | Carbon instance |
| `shipping_cost` | float | Decimal cast to float |
| `total_weight` | float | Decimal cast to float |

---

## Fillable Mass Assignment

```php
protected $fillable = [
    'uuid', 'order_id', 'tracking_number', 'courier', 'status',
    'shipping_method', 'shipping_cost', 'currency',
    'origin_address', 'destination_address', 'items',
    'total_weight', 'weight_unit',
    'shipped_at', 'estimated_delivery_at', 'delivered_at',
    'notes', 'metadata',
];
```

---

## Soft Deletes

**Not implemented.** The `shipments` table does NOT use Laravel's `SoftDeletes` trait. Records are permanently deleted on `delete()`. However, the current controller has no `destroy` endpoint, so deletion is not exposed via API.

---

## Migration File

| File | Table |
|------|-------|
| `database/migrations/2026_07_28_000004_create_shipments_table.php` | `shipments` |
