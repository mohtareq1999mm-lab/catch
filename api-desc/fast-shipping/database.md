# Fast Shipping — Database

## Table Structure

Fast Shipping does NOT have a dedicated database table. Settings are stored as JSON within the `settings.options` column.

### settings Table (relevant columns)

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-increment |
| `options` | text (JSON) | Stores all module settings including `fast_shipping` |
| `fast_shipping_page_publish` | boolean (default: true) | Toggle fast shipping page visibility |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### The `options.fast_shipping` JSON Structure

```json
{
    "enabled": false,
    "duration_minutes": 120,
    "fee": 0,
    "start_hour": "08:00",
    "end_hour": "22:00"
}
```

### products Table (relevant columns)

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | bigint (PK) | | Auto-increment |
| `is_fast_shipping_available` | boolean | `false` | Whether product is eligible for fast shipping |

**Index:** Consider adding an index on `is_fast_shipping_available` if this column is frequently filtered.

### governorates Table (relevant columns)

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `id` | bigint (PK) | | Auto-increment |
| `is_fast_shipping_enabled` | boolean | `false` | Whether governorate supports fast shipping |
| `status` | boolean | `true` | Whether governorate is active |

### orders Table (relevant columns)

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-increment |
| `shipping_method` | string (`FAST` / `SCHEDULED`) | Shipping method used |
| `fast_shipping_fee` | decimal | Fast shipping fee charged |
| `eta` | datetime | Estimated time of arrival |

**Index:** Consider adding an index on `(shipping_method, user_id)` for the `Order::fast()->forUser()` query pattern.

## Migration Reference

The `fast_shipping_page_publish` column is defined in:

`packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` (line 399)

```php
$table->boolean('fast_shipping_page_publish')->default(true);
```

No dedicated fast-shipping migration file exists. All settings are managed through the `Settings.options` JSON column.

## Entity Relationships

```
Settings 1──────────────────────────── hasMany modules (JSON options)
    └── options.fast_shipping ──────── Fast Shipping config

Product 1───────────────────────────── is_fast_shipping_available
    └── FastShippingScope ──────────── Filters queries by channel

Governorate 1───────────────────────── is_fast_shipping_enabled
    └── GovernorateController ─────── toggle endpoint

Order 1─────────────────────────────── shipping_method = 'FAST'
    └── scopeFast() ────────────────── Query scope
```
