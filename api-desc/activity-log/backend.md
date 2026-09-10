# Backend - Activity Log Feature

## Overview

The Activity Log feature uses a dual-path architecture to capture audit events: Eloquent Observers for CRUD operations and Event Listeners for business events. Both paths dispatch a queued `LogActivityJob` that writes to the `activity_log` table via `spatie/laravel-activitylog`.

## Write Paths

### Path 1: Eloquent Observers (9 entities)

All observers follow this pattern:
```php
class ProductObserver
{
    public function created(Product $product): void
    {
        dispatch(new LogActivityJob(
            subjectType: get_class($product),
            subjectId: $product->id,
            causerId: Auth::id(),
            event: 'created',
            logName: 'products',
            description: $description,
            properties: $data
        ))->onQueue('medium');
    }
}
```

Registered in `EventServiceProvider`:
```php
protected $observers = [
    Product::class        => [ProductObserver::class],
    Category::class       => [CategoryObserver::class],
    Brand::class          => [BrandObserver::class],
    Coupon::class         => [CouponObserver::class],
    FlashSale::class      => [FlashSaleObserver::class],
    Promotion::class      => [PromotionObserver::class],
    Role::class           => [RoleObserver::class],
    User::class           => [UserObserver::class],
    PickupLocation::class => [PickupLocationObserver::class],
];
```

### Path 2: Event Listeners (6 events)

Listeners in event → listener chain that dispatch LogActivityJob:

| Event | Listener | Log Name |
|-------|----------|----------|
| `OrderCreated` | `SendNewOrderNotification` | orders |
| `OrderCancelled` | `SendOrderCancelledNotification` | orders |
| `OrderStatusChanged` | `SendOrderStatusChangedNotification` | orders |
| `PaymentSucceeded` | `SendPaymentSucceededNotification` | orders |
| `PaymentFailed` | `SendPaymentFailedNotification` | orders |
| `UserRolesUpdated` | `LogUserRolesUpdated` | users |

## Read Path

### Controller - `packages/marvel/src/Http/Controllers/ActivityLogController.php`

| Method | Description |
|--------|-------------|
| `index(Request)` | Paginated list with filters (log_name, event, causer_id, search) |

**Authorization:** `$this->middleware('permission:' . Permission::VIEW_ACTIVITY_LOG)`

### Resource - `packages/marvel/src/Http/Resources/ActivityLogResource.php`

Fields: `id`, `log_name`, `description`, `event`, `subject_type`, `subject_id`, `causer_type`, `causer_id`, `properties`, `created_at`, `updated_at`

### Job - `app/Jobs/LogActivityJob.php`

```php
class LogActivityJob implements ShouldQueue
{
    public string $queue = 'medium';

    public function handle(): void
    {
        $subject = app($this->subjectType)::find($this->subjectId);
        if (!$subject) return;  // Silent exit if deleted

        $causer = $this->causerId ? User::find($this->causerId) : null;

        activity($this->logName)
            ->performedOn($subject)
            ->withProperties($this->properties)
            ->event($this->event)
            ->causedBy($causer)
            ->log($this->description ?? $this->event);
    }
}
```

### Configuration - `config/activitylog.php`

| Key | Value | Env |
|-----|-------|-----|
| `enabled` | `true` | `ACTIVITY_LOGGER_ENABLED` |
| `delete_records_older_than_days` | `60` | |
| `default_log_name` | `'default'` | |
| `subject_returns_soft_deleted_models` | `true` | |
| `table_name` | `'activity_log'` | `ACTIVITY_LOGGER_TABLE_NAME` |

## Permissions

| Permission | Value |
|------------|-------|
| `VIEW_ACTIVITY_LOG` | `view-activity-log` |
