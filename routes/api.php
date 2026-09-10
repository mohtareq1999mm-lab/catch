<?php

use App\Http\Controllers\Api\General\BannerController;
use App\Http\Controllers\Api\General\BrandController;
use App\Http\Controllers\Api\General\CategoryController;
use App\Http\Controllers\Api\Currency\CurrencyController;
use App\Http\Controllers\Api\General\CityController;
use App\Http\Controllers\Api\General\ContentPageController;
use App\Http\Controllers\Api\General\StaticPageController;
use App\Http\Controllers\Api\General\CountryController;
use App\Http\Controllers\Api\General\CouponController;
use App\Http\Controllers\Api\General\FAQController;
use App\Http\Controllers\Api\General\FastShippingController;
use App\Http\Controllers\Api\General\FlashSaleController;
use App\Http\Controllers\Api\General\GovernorateController;
use App\Http\Controllers\Api\General\HomeController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\General\OrderController;
use App\Http\Controllers\Api\General\PickupLocationController;
use App\Http\Controllers\Api\General\ProductController;
use App\Http\Controllers\Api\General\PromotionController;
use App\Http\Controllers\Api\General\SettingController;
use App\Http\Controllers\Api\General\SiteReviewController;
use App\Http\Controllers\Api\General\SliderController;
use App\Http\Controllers\Api\General\TagController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\General\OrderTrackingController;
use App\Http\Controllers\Api\Admin\AdminOrderTrackingController;
use App\Http\Controllers\Api\Admin\CouponConfigurationController;
use App\Http\Controllers\Api\User\NotificationPreferencesController;
use App\Http\Controllers\Api\Admin\AnalyticsController;
use App\Http\Controllers\Api\Admin\AnalyticsExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


Route::prefix('v1/general')->group(function () {
    Route::middleware(['api', 'throttle:public-api'])->group(function () {
        //======================== nav data ========================/
        Route::get('nav-data', [HomeController::class, 'navData']);
        //======================== category ========================/
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('categories/{slug}', [CategoryController::class, 'getCategoryBySlug']);
        //======================== brand ========================/
        Route::get('brands', [BrandController::class, 'index']);
        Route::get('brands/{slug}', [BrandController::class, 'getBrandBySlug']);
        Route::get('brands-products', [BrandController::class, 'getBrandsProductsByQtySet']);
        //======================== banner ========================/
        Route::get('banners', [BannerController::class, 'index']);
        Route::get('banners/{slug}', [BannerController::class, 'getBannerBySlug']);
        //======================== slider ========================/
        Route::get('sliders', [SliderController::class, 'index']);
        Route::get('sliders/{slug}', [SliderController::class, 'getSliderBySlug']);
        //======================== tags ========================/
        Route::get('tags', [TagController::class, 'index']);
        Route::get('tags/{slug}', [TagController::class, 'show']);
        //======================== promotions ========================/
        Route::get('promotions', [PromotionController::class, 'index']);
        Route::get('promotions/{slug}', [PromotionController::class, 'getPromotionBySlug']);
        //======================== coupons ========================/
        Route::get('coupons', [CouponController::class, 'index']);
        //======================== pages ========================/
        Route::controller(ContentPageController::class)->group(function () {
            Route::get('content-pages', 'index')->name('general-content-page-index');
            Route::get('content-pages/{slug}', 'show')->name('general-content-page-show');
        });
        //======================== static pages ========================/
        Route::controller(StaticPageController::class)->group(function () {
            Route::get('static-pages', 'index')->name('general-static-page-index');
            Route::get('static-pages/{slug}', 'show')->name('general-static-page-show');
        });
        //======================== products ========================/
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{slug}', [ProductController::class, 'getProductBySlug']);
        //======================== flash sales ========================/
        Route::get('flash-sales', [FlashSaleController::class, 'index']);
        Route::get('flash-sales/{slug}', [FlashSaleController::class, 'getFlashSaleBySlug']);
        Route::get('flash-sale-products', [FlashSaleController::class, 'getFlashSalesAndHereProductsByQtySet']);
        Route::get('flash-sale-products-ending-this-week', [FlashSaleController::class, 'getFlashSaleProductsEndingThisWeek']);
        Route::get('flash-sale-products-ending-today', [FlashSaleController::class, 'getFlashSaleProductsEndingToday']);
        //======================== settings ========================/
        Route::get('settings', [SettingController::class, 'index'])->name('settings.front');
        //======================== faqs ========================/
        Route::get('faqs', [FAQController::class, 'index']);
        //======================== governorates ========================/
        Route::get('governorates', [GovernorateController::class, 'index']);
        Route::get('governorates/{id}', [GovernorateController::class, 'show'])->whereNumber('id');
        //======================== countries ========================/
        Route::get('countries', [CountryController::class, 'index']);
        Route::get('countries/{id}', [CountryController::class, 'show'])->whereNumber('id');
        //======================== cities ========================/
        Route::get('cities', [CityController::class, 'index']);
        Route::get('cities/{id}', [CityController::class, 'show'])->whereNumber('id');
        //============================ pickup locations ========================/
        Route::get('pickup-locations', [PickupLocationController::class, 'index']);
        Route::get('pickup-locations/{id}', [PickupLocationController::class, 'show'])->whereNumber('id');
        //============================ fast shipping ========================/
        Route::get('fast-shipping/status', [FastShippingController::class, 'status']);
        //============================ site reviews ========================/
        Route::get('site-reviews', [SiteReviewController::class, 'index']);
        //============================ currencies ========================/
        Route::get('currencies', [CurrencyController::class, 'index']);
        Route::post('currencies/select', [CurrencyController::class, 'select']);
        //======================== payment callbacks (gateway redirect, public) ========================/
        Route::any('checkout/callback', [OrderController::class, 'checkoutCallback'])->name('api.checkout.callback');
        Route::any('checkout/error-callback', [OrderController::class, 'checkoutErrorCallback'])->name('api.checkout.errorCallback');
        //======================== public order tracking (no auth, verified by email/phone) ========================/
        Route::post('track-order', [OrderTrackingController::class, 'trackByOrderNumber'])->name('api.tracking.public');
    });

    Route::middleware(['api', 'auth:sanctum', 'throttle:authenticated'])->group(function () {
        //======================== coupons ========================/
        Route::post('coupons/apply', [CouponController::class, 'applyCoupon']);
        Route::post('coupons/{id}/claim', [CouponController::class, 'claim']);
        //======================== checkout ========================//
        Route::get('checkout/promotions', [OrderController::class, 'eligiblePromotions']);
        Route::post('checkout', [OrderController::class, 'checkout']);
        Route::post('checkout/cod/{orderId}/mark-paid', [OrderController::class, 'markCodAsPaid'])->middleware(['permission:update-order-status']);
        Route::post('checkout/cashier/{orderId}/mark-paid', [OrderController::class, 'markCashierPaid'])->middleware(['permission:update-order-status']);
        //======================== fast shipping checkout ========================/
        Route::post('fast-shipping/checkout', [FastShippingController::class, 'checkout']);
        //======================== orders ========================//
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{orderId}/invoice', [OrderController::class, 'invoiceByOrderId'])->whereNumber('orderId');
        Route::get('orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
        //======================== order tracking (authenticated) ========================//
        Route::get('my-orders', [OrderTrackingController::class, 'listUserOrders'])->name('api.tracking.my-orders');
        Route::get('orders/{orderId}/track', [OrderTrackingController::class, 'trackAuthenticatedOrder'])->name('api.tracking.order');
        //======================== digital downloads ========================//
        Route::get('digital/downloads', [\App\Http\Controllers\Api\General\DigitalDownloadController::class, 'index']);
        // W5 — license/access credential reveal (auth-scoped, never signed:
        // secrets must not appear in shareable URLs or referrer headers).
        Route::get('digital/license/{entitlement}/{asset}', [\App\Http\Controllers\Api\General\DigitalDownloadController::class, 'reveal'])
            ->whereUuid('entitlement')->whereUuid('asset')
            ->name('general.digital.license');
        // W7 — audited external redirect for URL assets (auth-scoped, no
        // credit consumption; the application never fetches the target).
        Route::get('digital/url/{entitlement}/{asset}', [\App\Http\Controllers\Api\General\DigitalDownloadController::class, 'redirectToExternal'])
            ->whereUuid('entitlement')->whereUuid('asset')
            ->name('general.digital.url');
        //========================= product reviews =========================//
        Route::post('products/{id}/reviews', [ProductController::class, 'addProductReview']);
        Route::put('products/reviews/{id}', [ProductController::class, 'updateProductReview']);
        //========================= device tokens (FCM) =========================//
        Route::post('device-tokens', [\App\Http\Controllers\Api\General\DeviceTokenController::class, 'store']);
        Route::delete('device-tokens', [\App\Http\Controllers\Api\General\DeviceTokenController::class, 'destroy']);
        //========================= site reviews =========================//
        Route::post('site-reviews', [SiteReviewController::class, 'store']);
        //======================== invoices ========================/
        Route::prefix('invoices')->group(function () {
            Route::get('my-invoices', [InvoiceController::class, 'myInvoices']);
            // verify route — required by InvoiceVerifyEndpointTest; do not drop on merge
            Route::get('verify/{uuid}', [InvoiceController::class, 'verify'])->middleware('throttle:5,1');
        });
    });
});

// Customer invoice PDF VIEW/DOWNLOAD via temporary SIGNED urls (no Sanctum).
// Ownership is enforced when the urls are generated (my-invoices / order invoice).
Route::prefix('v1/general/invoices')->middleware('signed')->group(function () {
    Route::get('view/{uuid}', [\App\Http\Controllers\Api\InvoiceController::class, 'viewByUuidSigned'])
        ->whereUuid('uuid')->name('general.invoices.view');
    Route::get('download/{uuid}', [\App\Http\Controllers\Api\InvoiceController::class, 'downloadByUuidSigned'])
        ->whereUuid('uuid')->name('general.invoices.download');
});

// Digital product downloads via temporary SIGNED urls (no Sanctum at
// redemption). Ownership is enforced when the URL is issued; the controller
// re-checks entitlement status, asset ownership and the download limit.
Route::get('v1/general/digital/download/{entitlement}/{asset}', [\App\Http\Controllers\Api\General\DigitalDownloadController::class, 'download'])
    ->middleware(['signed', 'throttle:30,1'])
    ->whereUuid('entitlement')->whereUuid('asset')
    ->name('general.digital.download');

// Admin tracking dashboard
Route::prefix('v1/admin/tracking')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('dashboard', [AdminOrderTrackingController::class, 'dashboard'])->name('api.admin.tracking.dashboard');
    Route::get('orders', [AdminOrderTrackingController::class, 'listOrders'])->name('api.admin.tracking.orders');
    Route::get('orders/{orderId}', [AdminOrderTrackingController::class, 'trackOrder'])->whereNumber('orderId')->name('api.admin.tracking.order');
    Route::get('requires-attention', [AdminOrderTrackingController::class, 'requiresAttention'])->name('api.admin.tracking.attention');
});

// Admin coupon configuration helpers
Route::prefix('v1/admin/coupons')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::post('validate-configuration', [CouponConfigurationController::class, 'validateConfiguration'])->name('api.admin.coupons.validate-config');
    Route::get('{id}/usage-info', [CouponConfigurationController::class, 'getUsageInfo'])->whereNumber('id')->name('api.admin.coupons.usage-info');
    Route::post('{id}/suggest-fix', [CouponConfigurationController::class, 'suggestFix'])->whereNumber('id')->name('api.admin.coupons.suggest-fix');
});

Route::prefix('v1/user')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('notification-preferences', [NotificationPreferencesController::class, 'index'])->name('api.user.notification-preferences.index');
    Route::put('notification-preferences', [NotificationPreferencesController::class, 'update'])->name('api.user.notification-preferences.update');
    Route::post('devices/register', [NotificationPreferencesController::class, 'registerDevice'])->name('api.user.devices.register');
    Route::delete('devices/{deviceId}', [NotificationPreferencesController::class, 'unregisterDevice'])->whereNumber('deviceId')->name('api.user.devices.unregister');
    Route::get('notifications/history', [NotificationPreferencesController::class, 'notificationHistory'])->name('api.user.notifications.history');
});

Route::prefix('v1/admin/analytics')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('dashboard', [AnalyticsController::class, 'dashboard'])->name('api.admin.analytics.dashboard');
    Route::get('time-series', [AnalyticsController::class, 'timeSeries'])->name('api.admin.analytics.time-series');
    Route::get('top-customers', [AnalyticsController::class, 'topCustomers'])->name('api.admin.analytics.top-customers');
    Route::get('customer-segmentation', [AnalyticsController::class, 'customerSegmentation'])->name('api.admin.analytics.segmentation');
    Route::get('performance', [AnalyticsController::class, 'performance'])->name('api.admin.analytics.performance');
    Route::post('clear-cache', [AnalyticsController::class, 'clearCache'])->name('api.admin.analytics.clear-cache');
});

Route::prefix('v1/admin/analytics/export')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::post('orders', [AnalyticsExportController::class, 'exportOrders'])->name('api.admin.analytics.export.orders');
    Route::post('customer-ltv', [AnalyticsExportController::class, 'exportCustomerLTV'])->name('api.admin.analytics.export.ltv');
    Route::post('performance', [AnalyticsExportController::class, 'exportPerformance'])->name('api.admin.analytics.export.performance');
});
        // //======================== shipments ========================/
        // Route::get('shipments/track/{trackingNumber}', [ShipmentController::class, 'trackShipment'])->name('shipments.track');
        // Route::get('shipments/{id}', [ShipmentController::class, 'show'])->middleware('auth:sanctum');
