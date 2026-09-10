# Database — Cart Module

> Source of truth: `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`, `2026_07_17_000001_fix_cart_foreign_key_cascades.php`, `Cart.php`, `CartItem.php` (verified on 2026-08-04, Revision 4).

---

## Table: `carts`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| user_id | bigint unsigned | NULL | FK → users.id (nullOnDelete), **UNIQUE** | One cart per user |
| coupon | varchar(255) | NULL | | Applied coupon code (string) |
| total_price | decimal(10,2) | 0.00 | NOT NULL | Sum of item total_prices |
| status | enum(active, expired, checked_out) | 'active' | NOT NULL | Lifecycle status |
| reserved_at | timestamp | NULL | | Last reservation timestamp |
| expires_at | timestamp | NULL | | TTL = now + 3 days (set on touch) |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
| Name | Columns | Type |
|------|---------|------|
| PRIMARY | `id` | Primary |
| carts_user_id_unique | `user_id` | **UNIQUE** |
| (composite) | `user_id`, `status` | Regular |
| (composite) | `status`, `expires_at` | Regular (expiration scan) |

### Foreign Keys
- `user_id` → `users.id` ON DELETE SET NULL (nullOnDelete, from the FK-fix migration; previously cascade)

### Status Values
| Status | Meaning |
|--------|---------|
| `active` | Cart in use, items reserved |
| `checked_out` | Cart finalized, stock deducted, items removed |
| `expired` | TTL exceeded, stock released, items removed |

---

## Table: `cart_items`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| cart_id | bigint unsigned | | FK → carts.id **cascadeOnDelete** | Parent cart |
| product_id | bigint unsigned | NULL | FK → products.id (nullOnDelete) | Null when product deleted (keeps line) |
| product_variant_id | bigint unsigned | NULL | FK → product_variants.id (nullOnDelete) | Nullable for simple products |
| quantity | int | | NOT NULL | Desired quantity |
| price | decimal(10,2) | | | **Snapshotted** unit price at reservation |
| total_price | decimal(10,2) | | | `round(price * quantity, 2)` |
| attributes | json | NULL | | Variant attributes / custom values |
| reserved_quantity | int | 0 | NOT NULL | Quantity held in stock reservation |
| discount_amount | decimal(10,2) | 0.00 | | Promotion discount on this item |
| shipping_method | string(20) | 'scheduled' | NOT NULL | `scheduled` (DB lowercase) / `fast` |
| is_gift | tinyint(1) | 0 | | Free gift item from promotion (price 0) |
| promotion_id | bigint unsigned | NULL | FK → promotions.id (nullOnDelete) | Link to promotion |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
| Name | Columns | Type |
|------|---------|------|
| PRIMARY | `id` | Primary |
| (composite) | `cart_id`, `product_id`, `product_variant_id` | Regular (line matching) |
| (composite) | `cart_id`, `is_gift` | Regular (gift lookup) |

### Foreign Keys
- `cart_id` → `carts.id` ON DELETE CASCADE
- `product_id` → `products.id` ON DELETE SET NULL
- `product_variant_id` → `product_variants.id` ON DELETE SET NULL
- `promotion_id` → `promotions.id` ON DELETE SET NULL

> ⚠️ DB stores `shipping_method` as lowercase (`scheduled`/`fast`). Application code normalizes to uppercase (`SCHEDULED`/`FAST`) at write time via `syncItems()` and `pluckItemsToCart()`. The `CartResource` splits items by uppercase enum values. The migration default is `'scheduled'`.

---

## Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `users` | BelongsTo (Cart) — `User::cart()` hasOne at `User.php:154-157` | `carts.user_id` |
| `products` | BelongsTo (CartItem, **withTrashed**) | `cart_items.product_id` |
| `product_variants` | BelongsTo (CartItem) | `cart_items.product_variant_id` |
| `promotions` | BelongsTo (CartItem) | `cart_items.promotion_id` |
| `coupons` | Referenced by code (not FK) | `carts.coupon` (string code) |

---

## Migration Files

| File | Tables | Purpose |
|------|--------|---------|
| `2020_06_02_051901_create_marvel_tables.php` | `carts`, `cart_items` (+ others) | Base schema |
| `2026_07_17_000001_fix_cart_foreign_key_cascades.php` | `carts`, `cart_items` | `user_id` and `product_id` → `nullOnDelete()` (**skips SQLite**) |

---

## Fillable / Casts

### Cart Model (`packages/marvel/src/Database/Models/Cart.php`)
```php
protected $fillable = [
    'user_id', 'coupon', 'total_price', 'status', 'reserved_at', 'expires_at',
];
protected $casts = [
    'reserved_at' => 'datetime',
    'expires_at' => 'datetime',
];
```
Relations: `user()` BelongsTo, `items()` HasMany, `scheduledItems()` HasMany (SCHEDULED), `fastItems()` HasMany (FAST).

### CartItem Model (`packages/marvel/src/Database/Models/CartItem.php`)
```php
protected $fillable = [
    'cart_id', 'product_id', 'quantity', 'product_variant_id', 'price',
    'total_price', 'attributes', 'reserved_quantity', 'discount_amount',
    'shipping_method', 'is_gift', 'promotion_id',
];
protected $casts = [
    'attributes' => 'array',
    'is_gift' => 'boolean',
    'shipping_method' => 'string',
    'price' => 'float',
    'total_price' => 'float',
    'discount_amount' => 'float',
];
```
Relations: `cart()` BelongsTo, `product()` BelongsTo **withTrashed**, `productVariant()` BelongsTo, `promotion()` BelongsTo.

---

## Query Patterns

### N+1 Prevention — Eager Loading (used by all read paths)
```php
// CartController::index and ::show
->with(['items.product', 'items.productVariant.attributeProducts.attributeValue.attribute'])
```

> ⚠️ **Remaining N+1:** `CartItemResource::toArray()` calls `$this->product->getFirstMediaUrl('products')` (thumbnail). Media is NOT in the eager-load set, so this issues one media query per product line. Low severity (bounded by line count), flagged as an optimization opportunity.

### Row Locking — Inventory Concurrency (all mutations)
```php
// Lock cart row
Cart::where('user_id', $userId)->lockForUpdate()->first();

// Lock inventory row (product or variant)
ProductVariant::whereKey($variant->id)->lockForUpdate()->firstOrFail();
// or
Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

// Lock existing non-gift cart item (product + variant + shipping)
CartItem::where('cart_id', $cart->id)
    ->where('product_id', $productId)
    ->where('is_gift', false)
    ->lockForUpdate()
    ->first();
```

### Total Price Recalculation
```php
// Repository (sum at commit)
$cart->update(['total_price' => $cart->items()->sum('total_price')]);

// Inventory service (line-level, PHP rounding)
'total_price' => round($price * $desiredQuantity, 2),

// Resource (subtotal + coupon)
'subtotal' => round((float) $items->sum('total_price'), 2),
'total_after_coupon' => round(max(0, $subtotal - $couponDiscount), 2),
```

### Promotion Revalidation (`revalidatePromotion`)
```php
$cart->items()
    ->where(function ($q) {
        $q->whereNotNull('promotion_id')->orWhere('discount_amount', '>', 0);
    })
    ->update([
        'promotion_id' => null,
        'discount_amount' => 0,
        'total_price' => DB::raw('ROUND(price * quantity, 2)'),
    ]);
if ($affected > 0) {
    $cart->update(['total_price' => $cart->items()->sum('total_price')]);
}
```

### Cart Expiration Query (scheduled)
```php
Cart::where('status', 'active')
    ->whereNotNull('expires_at')
    ->where('expires_at', '<=', now())
    ->orderBy('id')
    ->chunkById(100, function ($carts) { ... });   // no lock on chunk query
```

### Coupon Clear on Last Item Delete
```php
$remaining = CartItem::where('cart_id', $cartId)->lockForUpdate()->count();
if ($remaining === 0) {
    Cart::whereKey($cartId)->lockForUpdate()->update(['coupon' => null]);
}
```

### Bulk Add Pre-Filter (non-existent / soft-deleted)
```php
$existingIds = Product::whereIn('id', $items->pluck('product_id'))
    ->whereNull('deleted_at')
    ->pluck('id')
    ->toArray();
```

---

## Transaction / Locking Model

| Operation | Transactions | Row locks |
|-----------|--------------|-----------|
| `persistCart` (add / update) | 1 outer + nested (inventory) | cart, item, inventory |
| `incrementItem` / `decrementItem` / `reserveItem` | Own transaction | cart, item, inventory |
| `releaseItem` / `releaseCart` | Own transaction | item, inventory, cart |
| `finalizeCart` / `finalizeItemsByShippingMethod` | Own transaction | cart, item, inventory |
| `expireCart` | Own transaction | cart, item, inventory |
| `pluckItemsToCart` (bulk) | **No outer transaction** — per-item storeCart | per-item (independent) |
| `revalidatePromotion` | Outside transaction (called after commit) | none |

> ⚠️ `revalidatePromotion()` runs **after** `DB::commit()` in the controller. It is not covered by the write transaction, so there is a small window where a stale promotion could be read between commit and revalidation.
