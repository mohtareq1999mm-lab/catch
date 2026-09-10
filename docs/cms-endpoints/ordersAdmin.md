# ChawkBazar API

Headless e-commerce engine built on Laravel 10 — powers both storefront checkout and admin order management via a dual REST API layer.

## Tech Stack

- **Laravel 10** with PHP 8.1+
- **MySQL** with row-level locking (`SELECT ... FOR UPDATE`)
- **Sanctum** token-based authentication
- **Spatie Permissions** for role/ACL (super_admin, store_owner, staff, customer)
- **MyFatoorah** payment gateway integration
- **Prettus Repository** pattern for the legacy admin API
- **L5-Swagger** OpenAPI documentation (`/api/documentation`)

## Architecture

The project has two parallel API layers sharing the same database schema:

### Storefront API (`/api/v1/general/*`)

Used by the customer-facing storefront (checkout flow).

| Endpoint | Controller | Service Layer |
|---|---|---|
| `GET /general/orders` | `App\Http\Controllers\Api\General\OrderController` | `App\Services\General\OrderService` |
| `POST /general/checkout` | Same controller | `OrderService` + `CartInventoryService` |
| `GET /general/products` | `App\Http\Controllers\Api\General\ProductController` | Direct Eloquent |
| `GET /general/categories` | `App\Http\Controllers\Api\General\CategoryController` | Direct Eloquent |

### Admin API (`/api/v1/*`)

Used by the admin dashboard.

| Endpoint | Controller | Repository |
|---|---|---|
| `GET /orders` | `Marvel\Http\Controllers\Order\OrderController` | Direct Eloquent with filters |
| `GET /orders/{id}` | Same controller | Direct Eloquent |
| `POST /orders` | `Marvel\Http\Controllers\OrderController` | `OrderRepository::storeOrder()` |
| `PUT /orders/{id}` | Same controller | `OrderRepository::updateOrder()` |
| `CRUD /products` | `Marvel\Http\Controllers\ProductController` | `ProductRepository` |
| `CRUD /categories` | `Marvel\Http\Controllers\CategoryController` | `CategoryRepository` |

## Order API Filters

The `GET /orders` endpoint supports these query parameters:

| Parameter | Type | Example | Description |
|---|---|---|---|---|
| `status` | string | `pending` | Filter by order status (`pending`, `completed`, `cancelled`, `delivered`) |
| `user_id` | int | `5` | Filter by customer ID |
| `user_email` | string | `john@example.com` | Filter by customer email |
| `promotion_id` | int | `2` | Filter by promotion ID |
| `promotion_name` | string | `Summer+Sale` | Filter by promotion name |
| `product_id` | int | `42` | Orders containing this product ID |
| `product_name` | string | `T-shirt` | Orders containing a product matching this name |
| `flash_sale_name` | string | `Black+Friday` | Orders containing products in a flash sale with this name |
| `shipping_method` | string | `SCHEDULED` | Filter by shipping method (`SCHEDULED`, `FAST`) |
| `created_from` | date | `2026-01-01` | Orders created on or after this date |
| `created_to` | date | `2026-06-30` | Orders created on or before this date |
| `search` | string | `john` | Search across name, email, and phone |
| `limit` | int | `15` | Results per page (max 100) |
| `page` | int | `1` | Page number |

**Example requests:**

```bash
# List all orders
GET /api/v1/orders

# List all pending orders
GET /api/v1/orders?status=pending

# Filter by customer
GET /api/v1/orders?user_id=5
GET /api/v1/orders?user_email=john@example.com

# Filter by promotion
GET /api/v1/orders?promotion_id=2
GET /api/v1/orders?promotion_name=Summer+Sale

# Filter by product
GET /api/v1/orders?product_id=42
GET /api/v1/orders?product_name=T-shirt

# Filter by flash sale
GET /api/v1/orders?flash_sale_name=Black+Friday

# Filter by shipping method
GET /api/v1/orders?shipping_method=FAST
GET /api/v1/orders?shipping_method=SCHEDULED

# Filter by date range + status
GET /api/v1/orders?status=completed&created_from=2026-01-01&created_to=2026-06-30

# Search across name, email, phone
GET /api/v1/orders?search=john

# Full example with pagination
GET /api/v1/orders?user_id=5&shipping_method=FAST&limit=10&page=1
```

**Controller implementation (`packages/marvel/src/Http/Controllers/Order/OrderController.php`):**

```php
$orders = Order::query()
    ->with($this->relations())
    ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
    ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->user_id))
    ->when($request->filled('user_email'), fn($q) => $q->where('user_email', 'like', "%{$request->user_email}%"))
    ->when($request->filled('promotion_id'), fn($q) => $q->where('promotion_id', $request->promotion_id))
    ->when($request->filled('promotion_name'), function ($q) use ($request) {
        $q->whereIn('promotion_code', Promotion::query()
            ->where('name', 'like', "%{$request->promotion_name}%")
            ->select('code'));
    })
    ->when($request->filled('product_id'), fn($q) => $q->whereHas('orderItems', fn($i) => $i->where('product_id', $request->product_id)))
    ->when($request->filled('product_name'), fn($q) => $q->whereHas('orderItems.product', fn($p) => $p->where('name', 'like', "%{$request->product_name}%")))
    ->when($request->filled('flash_sale_name'), function ($q) use ($request) {
        $q->whereHas('orderItems.product.flash_sales', fn($f) => $f->where('title', 'like', "%{$request->flash_sale_name}%"));
    })
    ->when($request->filled('shipping_method'), fn($q) => $q->where('shipping_method', $request->shipping_method))
    ->when($request->filled('created_from'), fn($q) => $q->whereDate('created_at', '>=', $request->created_from))
    ->when($request->filled('created_to'), fn($q) => $q->whereDate('created_at', '<=', $request->created_to))
    ->when($request->filled('search'), function ($q) use ($request) {
        $search = $request->search;
        $q->where(function ($sub) use ($search) {
            $sub->where('name', 'like', "%{$search}%")
                ->orWhere('user_email', 'like', "%{$search}%")
                ->orWhere('user_phone', 'like', "%{$search}%");
        });
    })
    ->paginate($limit)
    ->withQueryString();
```

All responses include pagination metadata (current_page, last_page, per_page, total) and use the Marvel `OrderResource` which returns: id, order_number, status, payment_status, shipping_method, customer info, address, price breakdown, promotion details, order_items, transactions, and timestamps.

## Order Show Endpoint

```
GET /api/v1/orders/{id}
```

Returns a single order by its ID. Includes all related data.

**Example:**

```bash
GET /api/v1/orders/42
```

**Response fields:** All fields from `OrderResource` including order_items (with product and variant details), transactions, customer info, address, price breakdown, promotion details, and timestamps.

**Access control:**
- Super admins can view any order
- Regular users can only view their own orders (403 otherwise)

## Inventory System

Two parallel inventory systems exist:

1. **Cart-based reservation** (storefront checkout): Stock is `reserved` when items are added to cart, then `finalized` (deducted) on successful payment.
2. **Direct deduction** (admin order creation): Stock is validated with row locking then decremented synchronously.

**Stock columns on `products` and `product_variants` tables:**

| Column | Purpose |
|---|---|
| `stock_quantity` | Base stock level |
| `reserved_quantity` | Reserved during active cart session |
| `sold_quantity` | Total units ever sold |

Available stock (read-only) = `stock_quantity - reserved_quantity`.

## Setup

```bash
cp .env.example .env
# Configure DB, app URL, payment keys in .env

composer install
php artisan key:generate
php artisan migrate
php artisan db:seed --class=DemoDataSeeder
php artisan storage:link

# Start queue worker (for order processing jobs)
php artisan queue:work
```

## Key Packages

| Package | Purpose |
|---|---|
| `packages/marvel/` | Core business logic: models, repositories, listeners, events, traits |
| `app/Services/General/` | Storefront services: OrderService, CartInventoryService, PromotionService, MyfatoraService |
| `app/Http/Controllers/Api/General/` | Storefront controllers |

## Events

| Event | Listeners |
|---|---|
| `OrderProcessed` | Stock decrement (redundant, done synchronously) |
| `OrderCancelled` | `ProductInventoryRestore` (restores stock), `SendOrderCancelledNotification` |
| `OrderCreated` | Notifications |

## Cancellation & Stock Restore

When an order is cancelled:
- **Storefront flow** (non-completed): Cart inventory is released (`CartInventoryService::releaseCart()`), no stock was finalized.
- **Admin flow** / completed orders: `OrderCancelled` event fires, `ProductInventoryRestore` listener restores `stock_quantity` and decrements `sold_quantity` on both `products` and `product_variants` via `orderItems()` relationship.
