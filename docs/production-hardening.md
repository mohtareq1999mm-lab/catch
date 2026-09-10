# Production Hardening — Complete Architecture Documentation

> Version: 1.0 | Classification: INTERNAL | Last Updated: 2026-07-27

---

## Executive Summary

The platform employs **exceptional pessimistic locking** (52 `lockForUpdate()` calls) across the entire checkout, inventory, coupon, and financial pipeline — this is the system's strongest production asset. Rate limiting is comprehensive (11 named limiters), error handling is structured (9+ exception types with i18n), and caching covers API responses, home page data, and dashboard queries. The Docker build is production-optimized (OPcache, non-root user, healthcheck). However, the **scheduler is inactive** (no cron tasks), **no backup solution exists**, CORS is wide open, and queue defaults to `sync` in development. Laravel Telescope provides monitoring; no Horizon is configured.

---

## 1. Concurrency & Pessimistic Locking

### 1.1 `lockForUpdate()` Usage: 52 Instances

The system uses `lockForUpdate()` (exclusive row-level lock) in every critical financial and inventory path:

#### Inventory (`CartInventoryService`)
| Method | Locks | Purpose |
|---|---|---|
| `reserveItem()` | Cart, cart items, product/variant rows | Reserve stock on add-to-cart |
| `reserveGiftItem()` | Cart, gift items, product variants | Reserve gift product stock |
| `releaseItem()` | Cart item, inventory rows | Release stock on item removal |
| `releaseCart()` | Cart with items | Release all stock on clear |
| `finalizeCart()` | Cart, all items | Convert reserved → sold |
| `finalizeItemsByShippingMethod()` | Cart, items by shipping method | Selective finalization |
| `expireCart()` / `expireSingleCart()` | Cart, items | Release reserved stock |
| `ensureCartReservation()` | Cart, all items | Sync reservations |

#### Order Processing
| Method | Locks | File |
|---|---|---|
| `addItemsInOrder()` | Cart + items, coupon | `OrderService.php` |
| `findPendingOrderForUser()` | Pending order row | `OrderService.php` |
| `checkoutCallback()` | Transaction + order | `OrderController.php` |
| `checkoutErrorCallback()` | Transaction row | `OrderController.php` |
| `markCodAsPaid()` | Transaction, order, invoice | `OrderService.php` |
| `markCashierPaid()` | Transaction, order, invoice | `OrderService.php` |

#### Coupon & Promotion
| Method | Locks | File |
|---|---|---|
| `addCouponToCart()` | Coupon row | `CouponService.php` |
| `applyOutcome()` | Promotion, cart, cart items | `PromotionApplicator.php` |
| `recordCouponUsage()` | Coupon assignment row | `OrderService.php` |

#### Invoice & Number Generation
| Method | Locks | File |
|---|---|---|
| `generateNext()` | Sequence table row | `InvoiceNumberService.php` |

#### Inventory Restoration (Listeners)
| Method | Locks | File |
|---|---|---|
| `handle()` — cancellation | Order + product/variant rows | `RestoreProductInventory.php` |
| `handle()` — refund | Order + product/variant rows | `RestoreInventoryOnRefund.php` |

**Atomic guard**: Both restoration listeners use `WHERE inventory_restored_at IS NULL` to guarantee idempotency.

### 1.2 Transaction Usage: 21 `DB::transaction()` Calls

All critical paths are wrapped in database transactions — inventory operations, checkout, payment callbacks, order creation, coupon application, promotion application, invoice generation, refund approval.

---

## 2. Rate Limiting

### 2.1 Defined Limiters

| Name | Limit | Key | Purpose |
|---|---|---|---|
| `api` | 60/min | user ID or IP | General API DoS prevention (global middleware) |
| `auth` | 10/min | IP | Brute force / credential stuffing |
| `otp` | 3/min | IP | OTP bombing / SMS cost protection |
| `sensitive` | 5/min | IP | Password reset, contact forms |
| `orders` | 10/min | user ID or IP | Order spam / inventory locking |
| `content` | 5/min | user ID or IP | Fake reviews / spam |
| `refunds` | 5/min | user ID or IP | Financial operation protection |
| `uploads` | 10/min | user ID or IP | Storage abuse |
| `search` | 30/min | IP | Scraping prevention |
| `cart` | 20/min | user ID or IP | Inventory locking abuse |
| `analytics` | 60/min | user ID or IP | Business data scraping |

**Defined in**: `app/Providers/RouteServiceProvider.php:57-120`

The `api` limiter is applied globally to all API routes via the middleware group in `app/Http/Kernel.php`.

---

## 3. Caching Architecture

### 3.1 API Response Caching

**Middleware**: `app/Http/Middleware/CacheApiResponse.php`
- Caches all GET responses for 3600 seconds (1 hour)
- Cache key: `md5(api_cache_version|fullUrl|userId|guest)`
- **Skip routes**: reviews, checkout, coupons/apply
- **Invalidation**: Any successful POST/PUT/DELETE increments `api_cache_version`
- **Manual clear**: `php artisan api:cache-clear` (increments version)

### 3.2 Home Page Query Caching

**Service**: `HomeService.php`
- 22 `Cache::remember()` calls with **120-second TTL**
- Cached: sliders, flash sales, categories, banners, brands, coupons, products, daily offers
- Flushed when admin updates related data

### 3.3 Dashboard Analytics Caching

**Service**: `DashboardService.php`
- 17 `Cache::remember()` calls with **300-second TTL**
- Cached: overview, revenue, order stats, recent orders, top products, category stats, low stock, sales/customer/product/order/category/coupon/cart/finance analytics

### 3.4 Cache Configuration

| Aspect | Setting |
|---|---|
| Default driver | `file` (via `CACHE_DRIVER`) |
| Redis configured | Yes — dedicated `cache` DB (DB 1) separate from default |
| Cache tags | **Not used** |
| Prefix | `Str::slug(APP_NAME)_cache` |

---

## 4. Queue Architecture

### 4.1 Configuration

| Aspect | Setting |
|---|---|
| Default connection | `sync` (dev); `database` (prod via `.env`) |
| Redis queue | `retry_after: 90`, `block_for: null` |
| Failed jobs | Database driver, `failed_jobs` table (UUID-based) |

### 4.2 Queue Routing

| Queue | Jobs/Listeners | Purpose |
|---|---|---|
| **high** | `GenerateInvoiceListener`, `ImportProductsJob` | Urgent, must-complete |
| **medium** | `LogActivityJob`, all `Send*Notification` listeners | Notifications & activity logging |
| **low** | `GenerateInvoicePdfJob`, `PaymentReconciliationJob` | Batch/non-urgent |
| **default** | `ExportProductsJob`, `SendConversationReminder` | General |

### 4.3 Jobs Reference

| Job | Queue | Retries | Timeout | Backoff |
|---|---|---|---|---|
| `LogActivityJob` | medium | — | — | — |
| `GenerateInvoicePdfJob` | low | 3 | — | 30s, 120s, 300s |
| `PaymentReconciliationJob` | low | — | — | — |
| `ImportProductsJob` | high | 3 | 1500s | 60s, 120s, 240s |
| `ExportProductsJob` | default | 2 | 600s | — |

### 4.4 Missing: Laravel Horizon

Horizon is **not installed** — no queue monitoring dashboard. Failed jobs are tracked via database table only.

---

## 5. Error Handling

### 5.1 Structured JSON Responses

**File**: `app/Exceptions/Handler.php` (399 lines)

All API exceptions return:
```json
{"message": "...", "status": false, "errors": {...}}
```

### 5.2 Exception Mapping

| Exception | HTTP Code | Message |
|---|---|---|
| `ModelNotFoundException` | 404 | Resource Not Found |
| `NotFoundHttpException` | 404 | Not Found |
| `MethodNotAllowedHttpException` | 405 | Method Not Allowed |
| `AuthenticationException` | 401 | Unauthenticated |
| `AuthorizationException` | 403 | This action is unauthorized |
| `ValidationException` | 422 | Validation message + errors |
| `HttpException` | dynamic | dynamic |
| `QueryException` | 409 | DB error (details in local only) |
| `MarvelException` | 404/403/500 | Extracted from message |
| Spatie Permission exceptions | 400-409 | Translated user-friendly messages |
| All others | 500 | Debug message or generic |

### 5.3 Custom Exception Classes

| Exception | Purpose |
|---|---|
| `CurrencyMismatchException` | Payment currency validation |
| `FinancialInvariantException` | Financial data consistency |
| `SnapshotValidationException` | Data snapshot integrity |
| `UnsupportedGatewayException` | Unknown payment gateway |
| `UnsupportedSchemaException` | Snapshot schema version mismatch |

### 5.4 Environment Awareness
- `QueryException` appends SQL details only in `local` environment
- 500 errors show `config('app.debug') ? message : 'Internal Server Error'`

---

## 6. Logging & Monitoring

### 6.1 Log Channels

| Channel | Retention | Use |
|---|---|---|
| `stack` → `single` | — | Default |
| `daily` | 14 days | (configured, not default) |
| `slack` | — | Critical errors only |
| `papertrail` | — | Via SyslogUdpHandler |
| `emergency` | — | Fallback to `laravel.log` |

### 6.2 Key Log Points

| Event | Level | Location |
|---|---|---|
| Payment reconciliation | info | `PaymentReconciliationJob` |
| Payment amount/currency mismatch | warning | `OrderController` |
| Gateway failure | warning | `PaymentReconciliationJob` |
| PDF generation success/failure | info/error | `GenerateInvoicePdfJob` |
| Inventory restoration error | error | `RestoreInventoryOnRefund` |
| Invoice generation failure | error | `GenerateInvoiceListener` |

### 6.3 Activity Logging (Spatie)

- 60-day retention
- 10 model observers + 6 event listeners → `LogActivityJob` (medium queue)
- Sensitive fields excluded: passwords, remember_token, timestamps
- HTTP methods excluded: GET/HEAD/OPTIONS/TRACE/CONNECT

### 6.4 Laravel Telescope

All watchers enabled — cache, queries, jobs, exceptions, mail, notifications, requests, Redis, schedules, models, events, logs, gates, dumps, commands, batches, client requests.
- Slow query threshold: 100ms
- Request size limit: 64KB
- Access gated by email whitelist

---

## 7. Commands & Scheduler

### 7.1 Custom Commands

| Command | Signature | Description | Critical |
|---|---|---|---|
| `CancelUnpaidOrders` | `orders:cancel-unpaid` | Cancels pending orders past timeout | Yes |
| `PaymentReconciliationCommand` | `payments:reconcile` | Dispatches reconciliation job | Yes |
| `ExpireAbandonedCarts` | `cart:expire` | Expires abandoned carts, restores stock | Yes |
| `ClearApiCache` | `api:cache-clear` | Invalidates all cached API responses | Admin |

### 7.2 Scheduler Status: INACTIVE

**No scheduled tasks are registered** in `app/Console/Kernel.php`. All commands must be run manually or via external cron.

**Required cron entry for production**:
```
* * * * * php artisan schedule:run >> /dev/null 2>&1
```

**Recommended schedule**:
```php
$schedule->command('orders:cancel-unpaid')->everyMinute();
$schedule->command('cart:expire')->everyFiveMinutes();
$schedule->command('payments:reconcile')->hourly();
```

---

## 8. Security Middleware

### 8.1 Global Middleware Stack

1. `TrustProxies` — trusts all proxies (`'*'`), AWS ELB headers
2. `PreventRequestsDuringMaintenance` — no except URIs
3. `ValidatePostSize`
4. `TrimStrings`
5. `ConvertEmptyStringsToNull`
6. `HandleCors`

### 8.2 API Group Middleware

- `throttle:api` (60 req/min)
- `SubstituteBindings`
- `ChannelMiddleware` (multi-channel support)
- `CheckLangMiddleware` (locale: en/ar)

### 8.3 Custom Middleware

| Middleware | Purpose | File |
|---|---|---|
| `AdminMiddleware` | Checks `user_type === ADMIN` | `app/Http/Middleware/AdminMiddleware.php` |
| `ChannelMiddleware` | Validates `X-Channel` header | `app/Http/Middleware/ChannelMiddleware.php` |
| `VirifiyEmailMiddleware` | Checks `email_verified_at` | `app/Http/Middleware/VirifiyEmailMiddleware.php` |
| `CheckLangMiddleware` | Sets locale from header | `app/Http/Middleware/CheckLangMiddleware.php` |
| `CacheApiResponse` | Caches GET responses | `app/Http/Middleware/CacheApiResponse.php` |

### 8.4 Security Gaps

| Gap | Severity | Recommendation |
|---|---|---|
| CORS `allowed_origins: ['*']` | HIGH | Restrict to specific domains |
| No HSTS / X-Frame-Options / X-Content-Type-Options | MEDIUM | Add `\Fruitcake\Cors\HandleCors` or security headers middleware |
| No rate limit on checkout endpoint for throttling | MEDIUM | Verify `throttle:checkout` is applied |
| `TrustProxies` trusts all proxies (`'*'`) | LOW | Restrict to known proxy IPs if not behind AWS ELB |

---

## 9. Deployment Configuration

### 9.1 Docker (Multi-Stage Build)

**Stage 1 — Composer** (`php:8.2-cli-alpine`):
- Extensions: `pdo_mysql`, `gd`, `bcmath`, `zip`, `intl`, `exif`, `opcache`, `mbstring`
- `composer install --no-dev --optimize-autoloader --prefer-dist`

**Stage 2 — Production Runtime**:
- `php.ini-production`
- **OPcache**: `enable=1`, `memory_consumption=128`, `interned_strings_buffer=8`, `max_accelerated_files=10000`, `revalidate_freq=0`, `validate_timestamps=0`
- **Non-root user**: `www` (UID/GID 1000)
- Storage: `chmod 775`
- **HEALTHCHECK**: `curl -f http://localhost:8080/api`
- CMD: `php artisan serve --host=0.0.0.0 --port=8080`

### 9.2 Entrypoint (`docker-entrypoint.sh`)
1. Validates `APP_KEY` is set
2. Creates storage symlink
3. Caches config/routes/views (`config:cache`, `route:cache`, `view:cache`)
4. Starts PHP built-in server

### 9.3 Release Script (`release.sh`)
- `migrate:fresh --force` when `RUN_MIGRATIONS=true`
- `marvel:seed` when `RUN_SEED=true`

### 9.4 Deployment Gaps

| Gap | Severity | Recommendation |
|---|---|---|
| Uses `artisan serve` (PHP built-in) not Nginx/FPM | MEDIUM | Add Nginx or Caddy as reverse proxy |
| No supervisor/queue worker config | HIGH | Add `supervisord.conf` for `queue:work --sleep=3 --tries=3` |
| No CI/CD pipelines (no GitHub Actions) | MEDIUM | Add deploy workflow |
| No healthcheck for queue worker | MEDIUM | Add queue health check endpoint |

---

## 10. Database Configuration

| Aspect | Setting |
|---|---|
| Engine | MySQL/InnoDB |
| Charset | `utf8mb4` |
| Collation | `utf8mb4_unicode_ci` |
| Strict mode | `false` |
| SSL CA | Env-configured (`MYSQL_ATTR_SSL_CA`) |
| Schema length | `defaultStringLength(191)` |

### Indexes

Key tables with named indexes:
- `invoices`: 11 indexes (user_id, status, currency, payment_method, generated_at, total, sequence_year, transaction_id, correction_to_id, snapshot_hash)
- `coupon_product`: composite (coupon_id, product_id)
- `payment_reconciliation_results`: mismatch_type, resolved_at
- Standard foreign key indexes on all pivot tables

---

## 11. Strengths Summary

| Area | Strength |
|---|---|
| **Concurrency** | 52 `lockForUpdate()` calls — every critical path locked |
| **Rate Limiting** | 11 named limiters covering all attack surfaces |
| **Error Handling** | 9+ exception types → proper HTTP codes with i18n |
| **Caching** | API response (1h), home page (120s), dashboard (300s) |
| **Docker Build** | Multi-stage, OPcache, non-root user, healthcheck |
| **Monitoring** | Laravel Telescope (all watchers), Spatie Activitylog (60-day) |
| **Idempotency** | `inventory_restored_at` guards for cancellation/refund |
| **Invoice Numbers** | Sequence table with `lockForUpdate()` — no duplicates |

---

## 12. Gap Summary & Recommendations

| # | Gap | Severity | Action Required |
|---|---|---|---|
| 1 | **No scheduled cron** | HIGH | Register all 4 commands in `Kernel.php` scheduler; add server cron |
| 2 | **No backup solution** | HIGH | Add database backup command (Spatie Backup or custom) |
| 3 | **No queue worker config** | HIGH | Add supervisor config for `queue:work` on all queues |
| 4 | **CORS wide open** | HIGH | Restrict `allowed_origins` to production domains |
| 5 | **PHP built-in server** | MEDIUM | Replace with Nginx + PHP-FPM for production |
| 6 | **Cache driver = `file`** | MEDIUM | Switch to Redis for cache (and configure cache tags) |
| 7 | **Session driver = `file`** | MEDIUM | Switch to Redis for session (multi-instance support) |
| 8 | **No security headers** | MEDIUM | Add HSTS, X-Frame-Options, X-Content-Type-Options |
| 9 | **No Horizon** | MEDIUM | Install Laravel Horizon for queue monitoring |
| 10 | **No CI/CD** | MEDIUM | Add GitHub Actions or equivalent deploy pipeline |
| 11 | **Scout sync not queued** | LOW | Set `SCOUT_QUEUE=true` for async search indexing |
| 12 | **Strict mode = false** | LOW | Consider enabling MySQL strict mode |
