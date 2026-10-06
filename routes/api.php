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
use App\Http\Controllers\Api\General\PaymentWebhookController;
use App\Http\Controllers\Api\General\PaymentGatewayController;
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
use App\Http\Controllers\Api\Admin\PaymentGatewaySettingsController;
use App\Http\Controllers\Api\Admin\CouponConfigurationController;
use App\Http\Controllers\Api\User\NotificationPreferencesController;
use App\Http\Controllers\Api\Admin\AnalyticsController;
use App\Http\Controllers\Api\Admin\AnalyticsExportController;
use App\Http\Controllers\Api\Admin\ShipmentController as AdminShipmentController;
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
        //======================== payment options (public availability snapshot) ========================/
        Route::get('payment-gateways', [PaymentGatewayController::class, 'index'])->name('api.general.payment-gateways.index');
        //======================== order flow definitions (guest-safe discovery) ========================//
        // Canonical frontend discovery: every ACTIVE flow, sanitized
        // contract (no internal ids, no admin flags). The frontend selects
        // shipping_type; the backend resolves the Flow. Source pointers
        // (countries, governorates, ...) resolve via their own catalog
        // endpoints below.
        Route::get('order-flows/available', [\App\Http\Controllers\Api\General\FlowDefinitionController::class, 'available'])->name('api.order-flows.available');
        // Per-type convenience (D8b): same sanitized contract as available,
        // guest-accessible so the frontend needs no auth for discovery.
        Route::get('order-flows/by-shipping-type/{shippingType}', [\App\Http\Controllers\Api\General\FlowDefinitionController::class, 'byShippingType'])->name('api.order-flows.by-shipping-type');
        //======================== payment callbacks (gateway redirect, public) ========================/
        Route::match(['get', 'post'], 'checkout/callback', [OrderController::class, 'checkoutCallback'])->middleware('throttle:payment-callback')->name('api.checkout.callback');
        Route::match(['get', 'post'], 'checkout/error-callback', [OrderController::class, 'checkoutErrorCallback'])->middleware('throttle:payment-callback')->name('api.checkout.errorCallback');
        //======================== payment webhooks (provider-signed, public) ========================/
        // MyFatoorah has NO webhook: browser-callback only (see
        // PaymentWebhookController class docblock for why).
        Route::post('checkout/webhooks/stripe', [PaymentWebhookController::class, 'stripe'])->middleware('throttle:payment-webhook')->name('api.checkout.webhooks.stripe');
        Route::post('checkout/webhooks/paypal', [PaymentWebhookController::class, 'paypal'])->middleware('throttle:payment-webhook')->name('api.checkout.webhooks.paypal');
        //======================== public order tracking (no auth, verified by email/phone) ========================/
        Route::post('track-order', [OrderTrackingController::class, 'trackByOrderNumber'])->middleware('throttle:public-tracking')->name('api.tracking.public');
    });

    Route::middleware(['api', 'auth:sanctum', 'throttle:authenticated'])->group(function () {
        //======================== coupons ========================/
        Route::get('coupons/mine', [CouponController::class, 'myCoupons']);
        Route::get('coupons/available', [CouponController::class, 'available']);
        Route::post('coupons/apply', [CouponController::class, 'applyCoupon']);
        Route::post('coupons/{id}/claim', [CouponController::class, 'claim'])->whereNumber('id');
        //======================== checkout ========================//
        Route::get('checkout/promotions', [OrderController::class, 'eligiblePromotions']);
        Route::post('checkout', [OrderController::class, 'checkout']);
        //======================== order flow definition (public schema, no values) ========================//
        // Frontend fetches this BEFORE checkout to render required inputs
        // dynamically. Definitions only — never runtime order values.
        // (The per-type route lives in the public section above, D8b.)
        // F-1 hardening: manual payment confirmation requires the dedicated
        // financial permission, NOT the generic update-order-status.
        Route::post('checkout/cod/{orderId}/mark-paid', [OrderController::class, 'markCodAsPaid'])->middleware(['permission:payments.mark_paid']);
        Route::post('checkout/cashier/{orderId}/mark-paid', [OrderController::class, 'markCashierPaid'])->middleware(['permission:payments.mark_paid']);
        //======================== fast shipping checkout ========================/
        Route::post('fast-shipping/checkout', [FastShippingController::class, 'checkout']);
        //======================== orders ========================//
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{orderId}/invoice', [OrderController::class, 'invoiceByOrderId'])->whereNumber('orderId');
        Route::get('orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
        // Customer self-cancellation (owner-only, pending/processing + unpaid).
        // Delegates to the canonical status pipeline; staff cancellations
        // continue through PATCH /api/v1/orders/status.
        Route::post('orders/{id}/cancel', [OrderController::class, 'cancel'])->whereNumber('id');
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
Route::prefix('v1/general/invoices')->middleware(['signed', 'throttle:30,1'])->group(function () {
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
Route::prefix('v1/admin/tracking')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('dashboard', [AdminOrderTrackingController::class, 'dashboard'])->name('api.admin.tracking.dashboard');
    Route::get('orders', [AdminOrderTrackingController::class, 'listOrders'])->name('api.admin.tracking.orders');
    Route::get('orders/{orderId}', [AdminOrderTrackingController::class, 'trackOrder'])->whereNumber('orderId')->name('api.admin.tracking.order');
    Route::get('requires-attention', [AdminOrderTrackingController::class, 'requiresAttention'])->name('api.admin.tracking.attention');
});

// Legacy admin coupon helpers — backward-compat alias for the pre-existing
// /api/v1/admin/coupons/* URLs (tests, admin frontend). Canonical routes live
// in packages/marvel/src/Rest/Routes.php under /api/v1/coupons/* with the
// api.admin.coupons.* names; these aliases carry no names to avoid collision.

Route::prefix('v1/user')->middleware(['api', 'auth:sanctum', 'throttle:authenticated'])->group(function () {
    Route::get('notification-preferences', [NotificationPreferencesController::class, 'index'])->name('api.user.notification-preferences.index');
    Route::put('notification-preferences', [NotificationPreferencesController::class, 'update'])->name('api.user.notification-preferences.update');
    Route::post('devices/register', [NotificationPreferencesController::class, 'registerDevice'])->name('api.user.devices.register');
    Route::delete('devices/{deviceId}', [NotificationPreferencesController::class, 'unregisterDevice'])->whereNumber('deviceId')->name('api.user.devices.unregister');
    Route::get('notifications/history', [NotificationPreferencesController::class, 'notificationHistory'])->name('api.user.notifications.history');
});

Route::prefix('v1/admin/analytics')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('dashboard', [AnalyticsController::class, 'dashboard'])->name('api.admin.analytics.dashboard');
    Route::get('time-series', [AnalyticsController::class, 'timeSeries'])->name('api.admin.analytics.time-series');
    Route::get('top-customers', [AnalyticsController::class, 'topCustomers'])->name('api.admin.analytics.top-customers');
    Route::get('customer-segmentation', [AnalyticsController::class, 'customerSegmentation'])->name('api.admin.analytics.segmentation');
    Route::get('performance', [AnalyticsController::class, 'performance'])->name('api.admin.analytics.performance');
    Route::post('clear-cache', [AnalyticsController::class, 'clearCache'])->name('api.admin.analytics.clear-cache');
});

Route::prefix('v1/admin/analytics/export')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::post('orders', [AnalyticsExportController::class, 'exportOrders'])->name('api.admin.analytics.export.orders');
    Route::post('customer-ltv', [AnalyticsExportController::class, 'exportCustomerLTV'])->name('api.admin.analytics.export.ltv');
    Route::post('performance', [AnalyticsExportController::class, 'exportPerformance'])->name('api.admin.analytics.export.performance');
});

Route::prefix('v1/admin/payment-gateways')->middleware(['api', 'auth:sanctum', 'throttle:admin', 'lang'])->group(function () {
    Route::get('/', [PaymentGatewaySettingsController::class, 'index'])->middleware('permission:view-settings|update-settings')->name('api.admin.payment-gateways.index');
    Route::put('/{code}', [PaymentGatewaySettingsController::class, 'update'])->middleware('permission:update-settings')->name('api.admin.payment-gateways.update');
});

Route::prefix('v1/admin/orders')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    // Order shipment fields are admin-operated: reads ride on shipment /
    // order viewing, writes require shipment management. Never leave these
    // without permission middleware (SEC-1: any authenticated caller could
    // otherwise read/mutate any order's shipment state).
    Route::get('{orderId}/shipment', [AdminShipmentController::class, 'show'])->whereNumber('orderId')->middleware('permission:view-shipment|view-shipments|view-orders|view-order')->name('api.admin.orders.shipment.show');
    Route::post('{orderId}/shipment/update-status', [AdminShipmentController::class, 'updateStatus'])->whereNumber('orderId')->middleware('permission:update-shipment|create-shipment')->name('api.admin.orders.shipment.update-status');
    // P9-7: during-fulfillment order cancellation. The sole writer is
    // OrderService::changeOrderStatus (Order Flow authority); warehouse scope
    // is enforced in-controller (fail-closed when the order spans warehouses).
    Route::post('{orderId}/cancel', [\App\Http\Controllers\Api\Admin\Wms\OrderCancellationController::class, 'cancel'])->whereNumber('orderId')->middleware('permission:order.cancel-during-fulfillment')->name('api.admin.orders.cancel');
});

// Order Status catalog + configurable Order Flows (linear, sort_order-driven).
// Granular flow permissions (view/create/update-order-flows, manage
// inputs) are accepted alongside the legacy order permissions so existing
// admins keep working without a permission migration flag-day.
Route::prefix('v1/admin/order-statuses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\OrderStatusCatalogController::class, 'index'])->middleware('permission:view-order-flows|view-orders|view-order')->name('api.admin.order-statuses.index');
    Route::get('{id}', [\App\Http\Controllers\Api\Admin\OrderStatusCatalogController::class, 'show'])->whereNumber('id')->middleware('permission:view-order-flows|view-orders|view-order')->name('api.admin.order-statuses.show');
    Route::put('{id}', [\App\Http\Controllers\Api\Admin\OrderStatusCatalogController::class, 'update'])->whereNumber('id')->middleware('permission:update-order-flows|update-order-status')->name('api.admin.order-statuses.update');
});
Route::prefix('v1/admin/order-flows')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\OrderFlowController::class, 'index'])->middleware('permission:view-order-flows|view-orders|view-order')->name('api.admin.order-flows.index');
    Route::post('/', [\App\Http\Controllers\Api\Admin\OrderFlowController::class, 'store'])->middleware('permission:create-order-flows|update-order-status')->name('api.admin.order-flows.store');
    Route::get('{id}', [\App\Http\Controllers\Api\Admin\OrderFlowController::class, 'show'])->whereNumber('id')->middleware('permission:view-order-flows|view-orders|view-order')->name('api.admin.order-flows.show');
    Route::put('{id}', [\App\Http\Controllers\Api\Admin\OrderFlowController::class, 'update'])->whereNumber('id')->middleware('permission:update-order-flows|update-order-status')->name('api.admin.order-flows.update');
    // Flow Input definitions (dynamic inputs). Reads ride on flow viewing;
    // writes require the dedicated input permission (or legacy status perm).
    Route::get('{flowId}/inputs', [\App\Http\Controllers\Api\Admin\FlowInputController::class, 'index'])->whereNumber('flowId')->middleware('permission:view-order-flows|view-orders|view-order')->name('api.admin.order-flows.inputs.index');
    Route::post('{flowId}/inputs', [\App\Http\Controllers\Api\Admin\FlowInputController::class, 'store'])->whereNumber('flowId')->middleware('permission:manage-order-flow-inputs|update-order-status')->name('api.admin.order-flows.inputs.store');
});
Route::prefix('v1/admin/order-flow-inputs')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::put('{id}', [\App\Http\Controllers\Api\Admin\FlowInputController::class, 'update'])->whereNumber('id')->middleware('permission:manage-order-flow-inputs|update-order-status')->name('api.admin.order-flow-inputs.update');
    Route::delete('{id}', [\App\Http\Controllers\Api\Admin\FlowInputController::class, 'destroy'])->whereNumber('id')->middleware('permission:manage-order-flow-inputs|update-order-status')->name('api.admin.order-flow-inputs.destroy');
});

// Admin payment operations (F-1): gateway refund against a paid order.
// Fail-closed — every validation failure is a 422, never a provider call.
Route::prefix('v1/admin/payments')->middleware(['api', 'auth:sanctum', 'throttle:admin', 'lang'])->group(function () {
    Route::post('{order}/refund', [\App\Http\Controllers\Api\Admin\PaymentRefundController::class, 'refund'])->whereNumber('order')->middleware('permission:payments.refund')->name('api.admin.payments.refund');
});

// Fulfillment Phase 9 (P9-2): Warehouse / Location admin surface. Reads ride
// on view-*, writes require manage-*. Object-level warehouse scope is enforced
// in-controller via WmsAdminController (permission alone is not authorization).
Route::prefix('v1/admin/warehouses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\Wms\WarehouseController::class, 'index'])->middleware('permission:view-warehouse')->name('api.admin.warehouses.index');
    Route::post('/', [\App\Http\Controllers\Api\Admin\Wms\WarehouseController::class, 'store'])->middleware('permission:manage-warehouse')->name('api.admin.warehouses.store');
    Route::get('{id}', [\App\Http\Controllers\Api\Admin\Wms\WarehouseController::class, 'show'])->whereNumber('id')->middleware('permission:view-warehouse')->name('api.admin.warehouses.show');
    Route::put('{id}', [\App\Http\Controllers\Api\Admin\Wms\WarehouseController::class, 'update'])->whereNumber('id')->middleware('permission:manage-warehouse')->name('api.admin.warehouses.update');
    Route::post('{id}/set-default', [\App\Http\Controllers\Api\Admin\Wms\WarehouseController::class, 'setDefault'])->whereNumber('id')->middleware('permission:manage-warehouse')->name('api.admin.warehouses.set-default');
    Route::post('{id}/activate', [\App\Http\Controllers\Api\Admin\Wms\WarehouseController::class, 'activate'])->whereNumber('id')->middleware('permission:manage-warehouse')->name('api.admin.warehouses.activate');
    Route::post('{id}/deactivate', [\App\Http\Controllers\Api\Admin\Wms\WarehouseController::class, 'deactivate'])->whereNumber('id')->middleware('permission:manage-warehouse')->name('api.admin.warehouses.deactivate');
    Route::delete('{id}', [\App\Http\Controllers\Api\Admin\Wms\WarehouseController::class, 'destroy'])->whereNumber('id')->middleware('permission:manage-warehouse')->name('api.admin.warehouses.destroy');
});
Route::prefix('v1/admin/locations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\Wms\LocationController::class, 'index'])->middleware('permission:view-location')->name('api.admin.locations.index');
    Route::post('/', [\App\Http\Controllers\Api\Admin\Wms\LocationController::class, 'store'])->middleware('permission:manage-location')->name('api.admin.locations.store');
    Route::get('{id}', [\App\Http\Controllers\Api\Admin\Wms\LocationController::class, 'show'])->whereNumber('id')->middleware('permission:view-location')->name('api.admin.locations.show');
    Route::put('{id}', [\App\Http\Controllers\Api\Admin\Wms\LocationController::class, 'update'])->whereNumber('id')->middleware('permission:manage-location')->name('api.admin.locations.update');
    Route::post('{id}/activate', [\App\Http\Controllers\Api\Admin\Wms\LocationController::class, 'activate'])->whereNumber('id')->middleware('permission:manage-location')->name('api.admin.locations.activate');
    Route::post('{id}/deactivate', [\App\Http\Controllers\Api\Admin\Wms\LocationController::class, 'deactivate'])->whereNumber('id')->middleware('permission:manage-location')->name('api.admin.locations.deactivate');
});

// Fulfillment Phase 9 (P9-3): Fulfillment admin surface. Command routes only —
// no generic status mutation. Lifecycle authority stays in FulfillmentService;
// object-level warehouse scope is enforced in-controller via WmsAdminController.
Route::prefix('v1/admin/fulfillments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\Wms\FulfillmentController::class, 'index'])->middleware('permission:view-fulfillment')->name('api.admin.fulfillments.index');
    Route::post('/release', [\App\Http\Controllers\Api\Admin\Wms\FulfillmentController::class, 'release'])->middleware('permission:fulfillment.create')->name('api.admin.fulfillments.release');
    Route::get('{id}', [\App\Http\Controllers\Api\Admin\Wms\FulfillmentController::class, 'show'])->whereNumber('id')->middleware('permission:view-fulfillment')->name('api.admin.fulfillments.show');
    Route::post('{id}/cancel', [\App\Http\Controllers\Api\Admin\Wms\FulfillmentController::class, 'cancel'])->whereNumber('id')->middleware('permission:fulfillment.cancel')->name('api.admin.fulfillments.cancel');
    Route::post('{id}/assign', [\App\Http\Controllers\Api\Admin\Wms\FulfillmentController::class, 'assign'])->whereNumber('id')->middleware('permission:manage-fulfillment')->name('api.admin.fulfillments.assign');
    Route::post('{id}/create-tasks', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'createTasks'])->whereNumber('id')->middleware('permission:picking-execute')->name('api.admin.fulfillments.create-tasks');
    Route::post('{id}/complete-picking', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'completePicking'])->whereNumber('id')->middleware('permission:manage-fulfillment')->name('api.admin.fulfillments.complete-picking');
});
Route::prefix('v1/admin/fulfillment-items')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::post('{id}/assign-placement', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'assignPlacement'])->whereNumber('id')->middleware('permission:manage-fulfillment')->name('api.admin.fulfillment-items.assign-placement');
});

// Fulfillment Phase 9 (P9-4.1): Picking + Batch read surface. Commands land in
// P9-4.2/P9-4.3. Object-level warehouse scope is enforced in-controller via
// WmsAdminController (permission alone is not authorization).
Route::prefix('v1/admin/picking-tasks')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'index'])->middleware('permission:picking-execute')->name('api.admin.picking-tasks.index');
    Route::get('{id}', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'show'])->whereNumber('id')->middleware('permission:picking-execute')->name('api.admin.picking-tasks.show');
    Route::post('{id}/claim', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'claim'])->whereNumber('id')->middleware('permission:picking-execute')->name('api.admin.picking-tasks.claim');
    Route::post('{id}/release', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'releaseClaim'])->whereNumber('id')->middleware('permission:picking-execute')->name('api.admin.picking-tasks.release');
    Route::post('{id}/confirm', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'confirm'])->whereNumber('id')->middleware('permission:picking-execute')->name('api.admin.picking-tasks.confirm');
    Route::post('{id}/record-pick', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'recordPick'])->whereNumber('id')->middleware('permission:picking-execute')->name('api.admin.picking-tasks.record-pick');
    Route::post('{id}/skip', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'skip'])->whereNumber('id')->middleware('permission:picking-execute')->name('api.admin.picking-tasks.skip');
    Route::post('{id}/reallocate', [\App\Http\Controllers\Api\Admin\Wms\PickingController::class, 'reallocate'])->whereNumber('id')->middleware('permission:picking-execute')->name('api.admin.picking-tasks.reallocate');
});
Route::prefix('v1/admin/batches')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\Wms\BatchController::class, 'index'])->middleware('permission:view-fulfillment')->name('api.admin.batches.index');
    Route::post('/', [\App\Http\Controllers\Api\Admin\Wms\BatchController::class, 'store'])->middleware('permission:batch.manage')->name('api.admin.batches.store');
    Route::get('pending-fulfillments', [\App\Http\Controllers\Api\Admin\Wms\BatchController::class, 'pendingFulfillments'])->middleware('permission:view-fulfillment')->name('api.admin.batches.pending-fulfillments');
    Route::get('{id}', [\App\Http\Controllers\Api\Admin\Wms\BatchController::class, 'show'])->whereNumber('id')->middleware('permission:view-fulfillment')->name('api.admin.batches.show');
    Route::get('{id}/next-task', [\App\Http\Controllers\Api\Admin\Wms\BatchController::class, 'nextTask'])->whereNumber('id')->middleware('permission:view-fulfillment')->name('api.admin.batches.next-task');
    Route::post('{id}/assign', [\App\Http\Controllers\Api\Admin\Wms\BatchController::class, 'assign'])->whereNumber('id')->middleware('permission:batch.operate')->name('api.admin.batches.assign');
    Route::post('{id}/start', [\App\Http\Controllers\Api\Admin\Wms\BatchController::class, 'start'])->whereNumber('id')->middleware('permission:batch.operate')->name('api.admin.batches.start');
    Route::post('{id}/refresh-progress', [\App\Http\Controllers\Api\Admin\Wms\BatchController::class, 'refreshProgress'])->whereNumber('id')->middleware('permission:batch.operate')->name('api.admin.batches.refresh-progress');
    Route::post('{id}/cancel', [\App\Http\Controllers\Api\Admin\Wms\BatchController::class, 'cancel'])->whereNumber('id')->middleware('permission:batch.manage')->name('api.admin.batches.cancel');
    Route::post('{id}/retry', [\App\Http\Controllers\Api\Admin\Wms\BatchController::class, 'retry'])->whereNumber('id')->middleware('permission:batch.manage')->name('api.admin.batches.retry');
});

// Fulfillment Phase 9 (P9-6): Packing + Package surface. Packing tasks scope
// via fulfillment.warehouse_id, stations carry warehouse_id directly,
// packages scope via fulfillment.warehouse_id. Shipment creation from a
// verified task is P9-8 territory and is NOT exposed here.
Route::prefix('v1/admin/fulfillments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::post('{id}/create-packing-task', [\App\Http\Controllers\Api\Admin\Wms\PackingController::class, 'createTask'])->whereNumber('id')->middleware('permission:packing-execute')->name('api.admin.fulfillments.create-packing-task');
});
Route::prefix('v1/admin/packing-tasks')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\Wms\PackingController::class, 'index'])->middleware('permission:view-fulfillment')->name('api.admin.packing-tasks.index');
    Route::get('{id}', [\App\Http\Controllers\Api\Admin\Wms\PackingController::class, 'show'])->whereNumber('id')->middleware('permission:view-fulfillment')->name('api.admin.packing-tasks.show');
    Route::post('{id}/assign', [\App\Http\Controllers\Api\Admin\Wms\PackingController::class, 'assign'])->whereNumber('id')->middleware('permission:packing-execute')->name('api.admin.packing-tasks.assign');
    Route::post('{id}/start', [\App\Http\Controllers\Api\Admin\Wms\PackingController::class, 'start'])->whereNumber('id')->middleware('permission:packing-execute')->name('api.admin.packing-tasks.start');
    Route::post('{id}/pack', [\App\Http\Controllers\Api\Admin\Wms\PackingController::class, 'pack'])->whereNumber('id')->middleware('permission:packing.complete')->name('api.admin.packing-tasks.pack');
    Route::post('{id}/verify', [\App\Http\Controllers\Api\Admin\Wms\PackingController::class, 'verify'])->whereNumber('id')->middleware('permission:packing.complete')->name('api.admin.packing-tasks.verify');
    Route::post('{id}/cancel', [\App\Http\Controllers\Api\Admin\Wms\PackingController::class, 'cancel'])->whereNumber('id')->middleware('permission:manage-fulfillment')->name('api.admin.packing-tasks.cancel');
});
Route::prefix('v1/admin/packing-stations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\Wms\PackingController::class, 'stations'])->middleware('permission:view-fulfillment')->name('api.admin.packing-stations.index');
});
Route::prefix('v1/admin/packages')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\Wms\PackageController::class, 'index'])->middleware('permission:view-fulfillment')->name('api.admin.packages.index');
    Route::post('/', [\App\Http\Controllers\Api\Admin\Wms\PackageController::class, 'store'])->middleware('permission:packing-execute')->name('api.admin.packages.store');
    Route::get('{id}', [\App\Http\Controllers\Api\Admin\Wms\PackageController::class, 'show'])->whereNumber('id')->middleware('permission:view-fulfillment')->name('api.admin.packages.show');
    Route::post('{id}/add-item', [\App\Http\Controllers\Api\Admin\Wms\PackageController::class, 'addItem'])->whereNumber('id')->middleware('permission:packing-execute')->name('api.admin.packages.add-item');
    Route::post('{id}/seal', [\App\Http\Controllers\Api\Admin\Wms\PackageController::class, 'seal'])->whereNumber('id')->middleware('permission:packing-execute')->name('api.admin.packages.seal');
    Route::post('{id}/void', [\App\Http\Controllers\Api\Admin\Wms\PackageController::class, 'void'])->whereNumber('id')->middleware('permission:manage-fulfillment')->name('api.admin.packages.void');
});

// Fulfillment Phase 9 (P9-8): fulfillment-scoped shipment adapter.
// ShipmentService owns shipment rows; FulfillmentTransition owns the
// fulfillment moves (inside the service); Order Flow owns orders. Scope
// resolves via shipment.fulfillment_id → fulfillment.warehouse_id —
// order-only labels (null fulfillment) 404 on this surface.
Route::prefix('v1/admin/fulfillments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::post('{id}/shipments', [\App\Http\Controllers\Api\Admin\Wms\ShipmentController::class, 'store'])->whereNumber('id')->middleware('permission:create-shipment')->name('api.admin.fulfillments.shipments.store');
});
Route::prefix('v1/admin/shipments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\Wms\ShipmentController::class, 'index'])->middleware('permission:view-shipment')->name('api.admin.shipments.index');
    Route::get('{id}', [\App\Http\Controllers\Api\Admin\Wms\ShipmentController::class, 'show'])->whereNumber('id')->middleware('permission:view-shipment')->name('api.admin.shipments.show');
    Route::post('{id}/dispatch', [\App\Http\Controllers\Api\Admin\Wms\ShipmentController::class, 'dispatchShipment'])->whereNumber('id')->middleware('permission:update-shipment')->name('api.admin.shipments.dispatch');
    Route::post('{id}/deliver', [\App\Http\Controllers\Api\Admin\Wms\ShipmentController::class, 'markDelivered'])->whereNumber('id')->middleware('permission:update-shipment')->name('api.admin.shipments.deliver');
    Route::post('{id}/cancel', [\App\Http\Controllers\Api\Admin\Wms\ShipmentController::class, 'cancelShipment'])->whereNumber('id')->middleware('permission:update-shipment')->name('api.admin.shipments.cancel');
});
        // //======================== shipments ========================/
        // Route::get('shipments/track/{trackingNumber}', [ShipmentController::class, 'trackShipment'])->name('shipments.track');
        // Route::get('shipments/{id}', [ShipmentController::class, 'show'])->middleware('auth:sanctum');


//Card: 4242 4242 4242 4242
//Expiry: أي تاريخ مستقبلي
//CVC: أي 3 أرقام
//ZIP: أي قيمة مناسبة