# Backend - Notification Feature (Phase 1 / 2 / 3)

## Controller - `packages/marvel/src/Http/Controllers/NotificationController.php`

Extends `App\Http\Controllers\Controller`. Uses `Marvel\Traits\ApiResponse`.

### Middleware Stack (user endpoints)

```php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread', [NotificationController::class, 'unread']);
    Route::get('notifications/{id}', [NotificationController::class, 'show']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);
});
```

Admin endpoints (under `/api/v1/admin/notifications`) add
`permission:view-notifications` (read) and `permission:manage-notifications` (write).

### Methods

| Method | DB Query | Return |
|--------|----------|--------|
| `index()` | `$user->notifications()->latest()->paginate($perPage)` | Paginated list + meta |
| `unread()` | `$user->unreadNotifications()->latest()->get()` | All unread + count |
| `show($id)` | `$user->notifications()->findOrFail($id)` | Single notification |
| `markAsRead($id)` | `...->findOrFail($id)->markAsRead()` | Single notification |
| `markAllAsRead()` | `$user->unreadNotifications()->update(['read_at' => now()])` | `{marked_count}` |
| `destroy($id)` | `$user->notifications()->findOrFail($id)->delete()` | `{}` |

### formatNotification(DatabaseNotification): array

Extracts from `$notification->data` JSON. **Locale resolution** uses
`app()->getLocale()` (set from the `lang` request header by `CheckLangMiddleware`).

| Field | Source | Default |
|-------|--------|---------|
| `id` | `$notification->id` | |
| `type` | `$notification->type` | **business id, e.g. `price.drop`** |
| `title` | `$data['title'][$locale] ?? $data['title']['en']` | `''` |
| `message` | `$data['message'][$locale] ?? $data['message']['en']` | `''` |
| `icon` | `$data['icon']` | `'bell'` |
| `resource_type` | `$data['resource_type']` | `''` |
| `resource_id` | `$data['resource_id']` | `null` |
| `action_url` | `$data['action_url']` | `''` |
| `created_at` | `$notification->created_at?->toIso8601String()` | |
| `read_at` | `$notification->read_at?->toIso8601String()` | `null` |

## How the `type` is produced

`DatabaseChannel::buildPayload()` (Laravel framework):
```php
'type' => method_exists($notification, 'databaseType')
            ? $notification->databaseType($notifiable)
            : get_class($notification),
```
Every Phase `User*Notification` defines `databaseType()` which returns
`broadcastType()` (the stable business id). So the stored `type` is e.g.
`price.drop`, **not** `App\Notifications\UserProductPriceDropNotification`.

## Channel resolution (broadcast)

`BroadcastNotificationCreated::channelName()` calls
`$notifiable->receivesBroadcastNotificationsOn()` → `User::receivesBroadcastNotificationsOn()`
returns `'users.' . $this->id`. Wrapped by `PrivateChannel` → on-wire
`private-users.{id}`. The FQCN fallback (`App\Models\User.{id}`) is never used.

## Notification → Event → Listener map

| Phase | Event (example) | Listener | Notification class |
|-------|----------------|----------|--------------------|
| 1 | `OrderCreated` | `SendUserOrderCreatedNotification` | `UserOrderCreatedNotification` |
| 1 | `PaymentSucceeded` | `SendUserPaymentSucceededNotification` | `UserPaymentSucceededNotification` |
| 1 | `PaymentFailed` | `SendUserPaymentFailedNotification` | `UserPaymentFailedNotification` |
| 1 | `OrderDelivered` | `SendUserOrderDeliveredNotification` | `UserOrderDeliveredNotification` |
| 1 | `OrderCancelled` | `SendUserOrderCancelledNotification` | `UserOrderCancelledNotification` |
| 1 | `OrderRefunded` (refund) | `SendUserOrderRefundedNotification` | `UserOrderRefundedNotification` |
| 1 | `CouponAssigned` | `SendUserCouponAssignedNotification` | `UserCouponAssignedNotification` |
| 1 | `CouponCreated` (global) | `SendUserCouponAvailableNotification` | `UserCouponAvailableNotification` |
| 1 | `CouponUsed` | `SendUserCouponUsedNotification` | `UserCouponUsedNotification` |
| 2 | `PromotionActivated` | `SendUserPromotionAvailableNotification` | `UserPromotionAvailableNotification` |
| 2 | `FlashSaleActivated` | `SendUserFlashSaleAvailableNotification` | `UserFlashSaleAvailableNotification` |
| 3 | `ProductPriceDrop` | `SendUserProductPriceDropNotification` | `UserProductPriceDropNotification` |
| 3 | `ProductDiscountChanged` | `SendUserProductDiscountChangedNotification` | `UserProductDiscountChangedNotification` |
| 3 | `ProductBackInStock` | `SendUserProductBackInStockNotification` | `UserProductBackInStockNotification` |
| 3 | `ReviewApproved` | `SendUserReviewApprovedNotification` | `UserReviewApprovedNotification` |
| 3 | `ReviewRejected` | `SendUserReviewRejectedNotification` | `UserReviewRejectedNotification` |
| 3 | `PromotionActivated` (price drop) | `SendUserPromotionPriceDropNotification` | `UserPromotionPriceDropNotification` |
| 3 | `FlashSaleActivated` (price drop) | `SendUserFlashSalePriceDropNotification` | `UserFlashSalePriceDropNotification` |
| 3 | (scheduler) | `NotifyAbandonedCarts` command | `UserAbandonedCartNotification` |
| 3 | (scheduler) | `NotifyPromotionsEndingSoon` command | `UserPromotionEndingSoonNotification` |
| 3 | (scheduler) | `NotifyFlashSalesEndingSoon` command | `UserFlashSaleEndingSoonNotification` |

All listeners `implements ShouldQueue` and run on the `meem-medium` queue.
Phase 3 wishlist fan-out uses `app/Actions/NotifyWishlistUsersOfProduct.php`
(queries wishlist users, clones the notification per user).

## Source files

- Notifications: `app/Notifications/User*Notification.php` (21 classes)
- Listeners: `app/Listeners/SendUser*Notification.php`
- Events: `app/Events/*` (Phase 1/2/3 domain events)
- Action: `app/Actions/NotifyWishlistUsersOfProduct.php`
- Commands: `app/Console/Commands/Notify{AbandonedCarts,PromotionsEndingSoon,FlashSalesEndingSoon}.php`
- Observer/Repo hooks: `ProductObserver`, `ReviewRepository`
- Channel owner: `packages/marvel/src/Database/Models/User.php::receivesBroadcastNotificationsOn()`
