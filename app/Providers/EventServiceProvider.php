<?php

namespace App\Providers;

use App\Events\AdminLoggedIn;
use App\Events\ContactMessageReceived;
use App\Events\DigitalProductsDelivered;
use App\Events\FrontendCacheInvalidation;
use App\Events\InvoiceCreated;
use App\Events\AssignedCouponConsumed;
use App\Events\CouponAssigned;
use App\Events\CouponCreated;
use App\Events\FlashSaleActivated;
use App\Events\OrderCancelled;
use App\Events\OrderCreated;
// Phase 0 (#18): without this import the mapping below resolved to the
// nonexistent App\Providers\OrderDelivered, leaving delivered notifications
// permanently unwired despite production dispatches from OrderService.
use App\Events\OrderDelivered;
use App\Events\OrderStatusChanged;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\ProductBackInStock;
use App\Events\ProductDiscountChanged;
use App\Events\ProductPriceDrop;
use App\Events\PromotionActivated;
use App\Events\ReviewApproved;
use App\Events\ReviewRejected;
use App\Events\UserRolesUpdated;
use App\Listeners\RevokePendingDigitalEntitlements;
use App\Listeners\SendUserFlashSaleAvailableNotification;
use App\Listeners\SendUserPromotionAvailableNotification;
use App\Listeners\SendUserDigitalProductsAvailableNotification;
use App\Listeners\FulfillDigitalProducts;
use App\Listeners\DispatchFrontendCacheInvalidation;
use App\Listeners\LogInvoiceCreated;
use App\Listeners\LogUserRolesUpdated;
use App\Listeners\GenerateInvoiceListener;
use App\Listeners\SendAdminLoginNotification;
use App\Listeners\SendContactMessageNotification;
use App\Listeners\RestoreProductInventory;
use App\Listeners\SendNewOrderNotification;
use App\Listeners\SendOrderCancelledNotification;
use App\Listeners\SendOrderStatusChangedNotification;
use App\Listeners\SendPaymentFailedNotification;
use App\Listeners\SendPaymentSucceededNotification;
use App\Listeners\SendUserCouponAssignedNotification;
use App\Listeners\SendUserCouponAvailableNotification;
use App\Listeners\SendUserCouponUsedNotification;
use App\Listeners\SendUserOrderCancelledNotification;
use App\Listeners\SendUserOrderCreatedNotification;
use App\Listeners\SendUserOrderDeliveredNotification;
use App\Listeners\SendUserPaymentFailedNotification;
use App\Listeners\SendUserPaymentSucceededNotification;
use App\Listeners\SendUserProductBackInStockNotification;
use App\Listeners\SendUserProductDiscountChangedNotification;
use App\Listeners\SendUserProductPriceDropNotification;
use App\Listeners\SendUserPromotionPriceDropNotification;
use App\Listeners\SendUserFlashSalePriceDropNotification;
use App\Listeners\SendUserReviewApprovedNotification;
use App\Listeners\SendUserReviewRejectedNotification;
use App\Observers\BrandObserver;
use App\Observers\CategoryObserver;
use App\Observers\ContentPageObserver;
use App\Observers\CouponObserver;
use App\Observers\FlashSaleObserver;
use App\Observers\MediaCleanupObserver;
use App\Observers\PickupLocationObserver;
use App\Observers\ProductObserver;
use App\Observers\PromotionObserver;
use App\Observers\RoleObserver;
use App\Observers\SectionObserver;
use App\Observers\SectionTypeObserver;
use App\Observers\SectionTypeSettingObserver;
use App\Observers\StaticPageObserver;
use App\Observers\StaticSectionObserver;
use App\Observers\UserObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\PickupLocation;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Review;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\User;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Marvel\Database\Models\StaticPage;
use Marvel\Database\Models\StaticSection;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        \Illuminate\Queue\Events\JobFailed::class => [
            \App\Listeners\HandleFailedQueueJob::class,
        ],
        AdminLoggedIn::class => [
            SendAdminLoginNotification::class,
        ],
        ContactMessageReceived::class => [
            SendContactMessageNotification::class,
        ],
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        UserRolesUpdated::class => [
            LogUserRolesUpdated::class,
        ],
        OrderCancelled::class => [
            RestoreProductInventory::class,
            SendOrderCancelledNotification::class,
            SendUserOrderCancelledNotification::class,
        ],
        OrderDelivered::class => [
            SendUserOrderDeliveredNotification::class,
        ],
        OrderCreated::class => [
            SendNewOrderNotification::class,
            SendUserOrderCreatedNotification::class,
        ],
        OrderStatusChanged::class => [
            SendOrderStatusChangedNotification::class,
            \App\Listeners\SendOrderStatusSMS::class,
            \App\Listeners\SendOrderStatusEmail::class,
            \App\Listeners\SendOrderPushNotification::class,
        ],
        PaymentFailed::class => [
            SendPaymentFailedNotification::class,
            SendUserPaymentFailedNotification::class,
        ],
        PaymentSucceeded::class => [
            SendPaymentSucceededNotification::class,
            GenerateInvoiceListener::class,
            SendUserPaymentSucceededNotification::class,
            FulfillDigitalProducts::class,
        ],
        DigitalProductsDelivered::class => [
            SendUserDigitalProductsAvailableNotification::class,
        ],
        AssignedCouponConsumed::class => [
            SendUserCouponUsedNotification::class,
        ],
        CouponAssigned::class => [
            SendUserCouponAssignedNotification::class,
        ],
        CouponCreated::class => [
            SendUserCouponAvailableNotification::class,
        ],
        PromotionActivated::class => [
            SendUserPromotionAvailableNotification::class,
            SendUserPromotionPriceDropNotification::class,
        ],
        FlashSaleActivated::class => [
            SendUserFlashSaleAvailableNotification::class,
            SendUserFlashSalePriceDropNotification::class,
        ],
        ReviewApproved::class => [
            SendUserReviewApprovedNotification::class,
        ],
        ReviewRejected::class => [
            SendUserReviewRejectedNotification::class,
        ],
        ProductDiscountChanged::class => [
            SendUserProductDiscountChangedNotification::class,
        ],
        ProductPriceDrop::class => [
            SendUserProductPriceDropNotification::class,
        ],
        ProductBackInStock::class => [
            SendUserProductBackInStockNotification::class,
        ],
        InvoiceCreated::class => [
            LogInvoiceCreated::class,
        ],
        FrontendCacheInvalidation::class => [
            DispatchFrontendCacheInvalidation::class,
        ],
    ];

    /**
     * The model observers for the application.
     *
     * @var array
     */
    protected $observers = [
        Product::class        => [ProductObserver::class, MediaCleanupObserver::class],
        Category::class       => [CategoryObserver::class, MediaCleanupObserver::class],
        Brand::class          => [BrandObserver::class, MediaCleanupObserver::class],
        Coupon::class         => [CouponObserver::class],
        FlashSale::class      => [FlashSaleObserver::class, MediaCleanupObserver::class],
        Promotion::class      => [PromotionObserver::class],
        Role::class           => [RoleObserver::class],
        User::class           => [UserObserver::class, MediaCleanupObserver::class],
        PickupLocation::class => [PickupLocationObserver::class],
        ContentPage::class          => [ContentPageObserver::class],
        Section::class              => [SectionObserver::class],
        SectionType::class          => [SectionTypeObserver::class],
        SectionTypeSetting::class   => [SectionTypeSettingObserver::class],
        StaticPage::class           => [StaticPageObserver::class],
        StaticSection::class        => [StaticSectionObserver::class],
        Banner::class         => [MediaCleanupObserver::class],
        Review::class         => [MediaCleanupObserver::class],
        Shop::class           => [MediaCleanupObserver::class],
        Slider::class         => [MediaCleanupObserver::class],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
