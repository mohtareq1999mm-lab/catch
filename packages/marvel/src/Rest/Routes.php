<?php

use App\Http\Controllers\Api\General\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Marvel\Http\Controllers\AddressController;
use Marvel\Http\Controllers\ActivityLogController;
use Marvel\Http\Controllers\AttributeController;
use Marvel\Http\Controllers\BannerController;
use Marvel\Http\Controllers\BrandController;
use Marvel\Http\Controllers\ContactController;
use Marvel\Http\Controllers\CartController;
use Marvel\Http\Controllers\CategoryController;
use Marvel\Http\Controllers\CategoryExportController;
use Marvel\Http\Controllers\CategoryImportController;
use Marvel\Http\Controllers\CityController;
use Marvel\Http\Controllers\CouponAssignmentController;
use Marvel\Http\Controllers\CouponController;
use Marvel\Http\Controllers\FaqsController;
use Marvel\Http\Controllers\FlashSaleController;
use Marvel\Http\Controllers\Order\OrderController;
use Marvel\Http\Controllers\ProductController;
use Marvel\Http\Controllers\ProductImportController;
use Marvel\Http\Controllers\ProductExportController;
use Marvel\Http\Controllers\PromotionController;
use Marvel\Http\Controllers\RefundController;
use Marvel\Http\Controllers\ReviewController;
use Marvel\Http\Controllers\RoleAndPermissionController;
use Marvel\Http\Controllers\SettingsController;
use Marvel\Http\Controllers\SliderController;
use Marvel\Http\Controllers\SectionController;
use Marvel\Http\Controllers\SectionTypeController;
use Marvel\Http\Controllers\TagController;
use Marvel\Http\Controllers\UserController;
use Marvel\Http\Controllers\WishlistController;
use Marvel\Http\Controllers\ContentPageController;
use Marvel\Http\Controllers\StaticPageController;
use Marvel\Http\Controllers\CountryController;
use Marvel\Http\Controllers\CurrencyController;
use Marvel\Http\Controllers\CurrencyRateController;
use Marvel\Http\Controllers\FastShippingController;
use Marvel\Http\Controllers\GovernorateController;
use Marvel\Http\Controllers\NotificationController;
use Marvel\Http\Controllers\PickupLocationController;
use Marvel\Http\Controllers\ShippingPriceController;
use Marvel\Http\Controllers\SiteReviewController;
use Marvel\Http\Controllers\SocialController;
use App\Http\Controllers\Api\ShipmentController;

// use Illuminate\Support\Facades\Auth;

/**
 * ******************************************
 * Available Public Routes
 * ******************************************
 */

Broadcast::routes(['middleware' => ['auth:sanctum']]);


/**
 * Authentication Routes - Rate Limited (10/min per IP)
 * Protects against brute force and credential stuffing
 *
 */
Route::middleware(['throttle:login', 'lang'])->group(function () {
    Route::post('/register', [UserController::class, 'register']);
    Route::post('/token', [UserController::class, 'token']);
    Route::post('/admin-login', [UserController::class, 'adminToken']);
    Route::get('/social/redirect', [SocialController::class, 'redirectFromQuery']);
    Route::post('/social/exchange', [SocialController::class, 'exchange']);
    Route::get('/social/{provider}', [SocialController::class, 'redirect']);
    Route::get('/social/{provider}/callback', [SocialController::class, 'callback']);
});
// Logout is not rate limited - users should always be able to log out
Route::post('/logout', [UserController::class, 'logout'])->middleware('auth:sanctum', 'lang');
Route::get('me', [UserController::class, 'me'])->middleware('auth:sanctum', 'lang');

/**
 * Password Reset Routes - Rate Limited (5/min per IP)
 * Protects against email bombing and account takeover
 */
Route::middleware(['throttle:sensitive', 'lang'])->group(function () {
    Route::post('/forget-password', [UserController::class, 'forgetPassword']);
    Route::post('/verify-forget-password-token', [UserController::class, 'verifyForgetPasswordToken']);
    Route::post('/reset-password', [UserController::class, 'resetPassword']);
});

/**
 * OTP Routes - DISABLED
 * Uncomment if you need phone-based authentication
 */
Route::middleware(['throttle:otp', 'lang'])->group(function () {
    Route::post('/send-otp-code', [UserController::class, 'sendUserOtp']);
    Route::post('/otp-login', [UserController::class, 'otpLogin']);
});

Route::middleware(['auth:sanctum', 'lang'])->group(function () {
    Route::post('/change-password', [UserController::class, 'changePassword']);
    Route::post('/update-contact', [UserController::class, 'updateContact']); // for phone number
    Route::apiResource('address', AddressController::class);
});
/**
 * ******************************************
 * Available Public Routes
 * ******************************************
 */
Route::middleware(["throttle:sensitive", "lang"])->group(function () {
    Route::post('contacts/{id}/reply', [ContactController::class, 'sendReply']);
    Route::post('contact-us', [ContactController::class, 'store']);
    Route::delete('contacts/delete-all', [ContactController::class, 'deleteAll']);
    Route::delete('contacts/delete-all-read', [ContactController::class, 'deleteAllReadContacts']);
    Route::apiResource('contacts', ContactController::class)->except(['update']);
});

Route::middleware(['auth:sanctum', 'throttle:admin', 'lang'])->group(function () {
    //======================== settings site ========================/
    Route::get('settings', [SettingsController::class, 'index'])->middleware('permission:view-settings|update-settings');
    Route::put('settings', [SettingsController::class, 'update'])->middleware('permission:update-settings');

    //======================== activity log ========================/
    Route::get('logs/activity', [ActivityLogController::class, 'index'])->name('admin.activity-log.index')->middleware('permission:view-activity-log');

    Route::get('fast-shipping/settings', [FastShippingController::class, 'getSettings']);
    Route::put('fast-shipping/settings', [FastShippingController::class, 'updateSettings']);

    //======================== users ========================/
    Route::get('me', [UserController::class, 'me']);
    Route::post('admin-users/add', [UserController::class, 'adminAddUsers']);
    Route::put('admin-users/update-activation', [UserController::class, 'adminUpdateActivationUsers']);
    Route::delete('admin-users/delete/{id}', [UserController::class, 'adminDeleteUsers']);
    Route::put('admin-users/restore/{id}', [UserController::class, 'adminRestoreUser']);
    Route::get('admin-users/trashed', [UserController::class, 'adminTrashedUsers']);
    Route::delete('admin-users/delete-forever/{id}', [UserController::class, 'adminDeleteUsersForever']);

    //======================== brands ========================/
    // Custom import/export routes MUST precede apiResource('brands') so
    // single-segment GETs (e.g. /brands/export) are not captured by brands/{brand}.
    Route::post('brands/import', [\Marvel\Http\Controllers\BrandImportController::class, 'import'])->name('admin.brands.import');
    Route::get('brands/import/sample', [\Marvel\Http\Controllers\BrandImportController::class, 'downloadSample'])->name('admin.brands.import.sample');
    Route::get('brands/import/{id}', [\Marvel\Http\Controllers\BrandImportController::class, 'status'])->whereNumber('id')->name('admin.brands.import.status');
    Route::post('brands/import/{id}/cancel', [\Marvel\Http\Controllers\BrandImportController::class, 'cancel'])->whereNumber('id')->name('admin.brands.import.cancel');
    Route::get('brands/import/{id}/download-errors', [\Marvel\Http\Controllers\BrandImportController::class, 'downloadErrors'])->whereNumber('id')->name('admin.brands.import.download-errors');
    Route::get('brands/export', [\Marvel\Http\Controllers\BrandExportController::class, 'export'])->name('admin.brands.export');
    Route::post('brands/export', [\Marvel\Http\Controllers\BrandExportController::class, 'export'])->name('admin.brands.export.post');
    Route::get('brands/export/{id}', [\Marvel\Http\Controllers\BrandExportController::class, 'status'])->whereNumber('id')->name('admin.brands.export.status');
    Route::get('brands/export/{id}/download', [\Marvel\Http\Controllers\BrandExportController::class, 'download'])->whereNumber('id')->name('admin.brands.export.download');
    Route::post('brands/export/{id}/cancel', [\Marvel\Http\Controllers\BrandExportController::class, 'cancel'])->whereNumber('id')->name('admin.brands.export.cancel');
    Route::put('brands/reorder', [BrandController::class, 'reorder'])->middleware('permission:update-brand');
    Route::apiResource('brands', BrandController::class)->middleware('permission:view-brands|view-brand|create-brand|update-brand|delete-brand|import-brand|export-brand');

    //======================== sliders ========================/
    Route::patch('sliders/change-status', [SliderController::class, 'changeStatus'])->middleware('permission:update-slider');
    Route::put('sliders/reorder', [SliderController::class, 'reorder'])->middleware('permission:update-slider');
    Route::apiResource('sliders', SliderController::class)->middleware('permission:view-slider|create-slider|update-slider|delete-slider');

    //======================== categories ========================/
    Route::put('categories/feature', [CategoryController::class, 'addOrRemoveCategoryFromFeature'])->middleware('permission:update-category');
    Route::post('categories/import', [CategoryImportController::class, 'import'])->name('admin.categories.import')->middleware('permission:import-category');
    Route::get('categories/import/sample', [CategoryImportController::class, 'downloadSample'])->name('admin.categories.import.sample')->middleware('permission:import-category');
    Route::get('categories/import/{id}', [CategoryImportController::class, 'status'])->whereNumber('id')->name('admin.categories.import.status')->middleware('permission:import-category');
    Route::post('categories/import/{id}/cancel', [CategoryImportController::class, 'cancel'])->whereNumber('id')->name('admin.categories.import.cancel')->middleware('permission:import-category');
    Route::get('categories/import/{id}/download-errors', [CategoryImportController::class, 'downloadErrors'])->whereNumber('id')->name('admin.categories.import.download-errors')->middleware('permission:import-category');
    Route::get('categories/export', [CategoryExportController::class, 'export'])->name('admin.categories.export')->middleware('permission:export-category');
    Route::get('categories/export/{id}', [CategoryExportController::class, 'status'])->whereNumber('id')->name('admin.categories.export.status')->middleware('permission:export-category');
    Route::get('categories/export/{id}/download', [CategoryExportController::class, 'download'])->whereNumber('id')->name('admin.categories.export.download')->middleware('permission:export-category');
    Route::post('categories/export/{id}/cancel', [CategoryExportController::class, 'cancel'])->whereNumber('id')->name('admin.categories.export.cancel')->middleware('permission:export-category');
    Route::post('categories/bulk-delete', [CategoryController::class, 'bulkDelete'])->name('admin.categories.bulk-delete')->middleware('permission:delete-category');
    Route::get('categories/bulk-delete/{id}', [CategoryController::class, 'bulkDeleteStatus'])->name('admin.categories.bulk-delete.status')->middleware('permission:delete-category');
    Route::post('categories/bulk-delete/{id}/cancel', [CategoryController::class, 'cancelBulkDelete'])->name('admin.categories.bulk-delete.cancel')->middleware('permission:delete-category');
    Route::apiResource('categories', CategoryController::class)->middleware('permission:view-categories|view-category|create-category|update-category|delete-category');

    //======================== shops locations ========================/
    Route::apiResource('pickup-locations', PickupLocationController::class)->middleware('permission:view-pickup-locations|create-pickup-location|update-pickup-location|delete-pickup-location');


    //======================== attributes ========================/
    Route::apiResource('attributes', AttributeController::class)->middleware('permission:view-attributes|create-attribute|update-attribute|delete-attribute');
    //    Route::apiResource('attribute-values', AttributeValueController::class);


    Route::get('orders', [OrderController::class, 'index'])->name('orders.index')->middleware('permission:view-orders|view-order');
    Route::get('orders/{id}', [OrderController::class, 'show'])->name('orders.show')->middleware('permission:view-order|view-orders');
    Route::patch('orders/{id}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status')->whereNumber('id')->middleware('permission:update-order-status');

    //==================================== banner ========================/
    Route::put('banner/change-status', [BannerController::class, 'changeStatus'])->middleware('permission:update-banners');
    Route::post('banner/reorder', [BannerController::class, 'reorder'])->middleware('permission:update-banners');
    Route::apiResource('banners', BannerController::class)->middleware('permission:view-banners|create-banners|update-banners|delete-banners');

    //======================== countries ========================/
    Route::apiResource('countries', CountryController::class)->middleware('permission:view-country|create-country|update-country|delete-country');
    Route::get('countries/{id}/governorates', [CountryController::class, 'governorates'])->middleware('auth:sanctum');
    Route::post('countries/change-status', [CountryController::class, 'bulkStatus'])->middleware(['auth:sanctum', 'permission:' . \Marvel\Enums\Permission::UPDATE_COUNTRY]);

    //======================== governorates ========================/
    Route::put('governorates/change-status', [GovernorateController::class, 'bulkStatus'])->middleware(['auth:sanctum', 'permission:' . \Marvel\Enums\Permission::UPDATE_GOVERNORATE]);
    Route::put('governorates/{id}/fast-shipping', [GovernorateController::class, 'toggleFastShipping'])->middleware(['auth:sanctum', 'permission:' . \Marvel\Enums\Permission::UPDATE_GOVERNORATE]);
    Route::get('governorates/{id}/cities', [GovernorateController::class, 'cities'])->middleware('auth:sanctum');
    Route::apiResource('governorates', GovernorateController::class)->middleware('permission:view-governorate|create-governorate|update-governorate|delete-governorate');

    //======================== cities ========================/
    Route::apiResource('cities', CityController::class)->middleware('permission:view-city|create-city|update-city|delete-city');

    //============================= shipping prices ========================/
    Route::apiResource('shipping-prices', ShippingPriceController::class)->middleware('permission:manage-shipping-prices');

    //======================== reviews ========================/
    Route::patch('reviews/{id}/toggle-approve', [ReviewController::class, 'toggleApproveReview'])->middleware('permission:approve-reviews');
    Route::apiResource('reviews', ReviewController::class)->middleware('permission:view-contacts|update-contact|delete-contact|approve-reviews|delete-reviews');

    //======================== site reviews ========================/
    Route::get('site-reviews', [SiteReviewController::class, 'index'])->middleware('permission:view-site-reviews');
    Route::get('site-reviews/{id}', [SiteReviewController::class, 'show'])->whereNumber('id')->middleware('permission:view-site-reviews');
    Route::patch('site-reviews/{id}/approve', [SiteReviewController::class, 'approve'])->whereNumber('id')->middleware('permission:approve-site-reviews');
    Route::patch('site-reviews/{id}/reject', [SiteReviewController::class, 'reject'])->whereNumber('id')->middleware('permission:reject-site-reviews');

    //======================== currencies ========================/
    Route::apiResource('currencies', CurrencyController::class)->whereNumber('currency')->middleware('permission:view-currencies|create-currency|update-currency|delete-currency');
    Route::post('currencies/{id}/set-base', [CurrencyController::class, 'setBase'])->whereNumber('id')->middleware('permission:set-base-currency');
    Route::post('currencies/{id}/set-catalog', [CurrencyController::class, 'setCatalog'])->whereNumber('id')->middleware('permission:set-catalog-currency');
    Route::patch('currencies/{id}/rate-mode', [CurrencyController::class, 'setRateMode'])->whereNumber('id')->middleware('permission:update-currency');

    //======================== currency rates ========================/
    Route::apiResource('currency-rates', CurrencyRateController::class)->whereNumber('currency_rate')->middleware('permission:view-exchange-rates|create-exchange-rate|update-exchange-rate|delete-exchange-rate');

    //============================= products ========================/
    Route::post('products/bulk-delete', [ProductController::class, 'destroyBulk'])->middleware('permission:delete-all-products|delete-product');
    Route::delete('products/all', [ProductController::class, 'destroyAll'])->middleware('permission:delete-all-products');
    // Sample/export routes precede apiResource so single-segment GETs are
    // not captured by products/{product}.
    Route::get('products/import/sample', [ProductImportController::class, 'downloadSample'])->name('admin.products.import.sample');
    Route::get('products/export', [ProductExportController::class, 'export'])->name('admin.products.export');
    Route::post('products/export', [ProductExportController::class, 'export'])->name('admin.products.export.post');
    Route::get('products/export/{id}', [ProductExportController::class, 'status'])->whereNumber('id')->name('admin.products.export.status');
    Route::get('products/export/{id}/download', [ProductExportController::class, 'download'])->whereNumber('id')->name('admin.products.export.download')->middleware('permission:export-product');
    Route::post('products/export/{id}/cancel', [ProductExportController::class, 'cancel'])->whereNumber('id')->name('admin.products.export.cancel')->middleware('permission:export-product');
    Route::post('products/import', [ProductImportController::class, 'import'])->name('admin.products.import')->middleware('permission:import-product');
    Route::get('products/import/{id}', [ProductImportController::class, 'status'])->whereNumber('id')->name('admin.products.import.status')->middleware('permission:import-product');
    Route::post('products/import/{id}/cancel', [ProductImportController::class, 'cancel'])->whereNumber('id')->name('admin.products.import.cancel')->middleware('permission:import-product');
    Route::get('products/import/{id}/download-errors', [ProductImportController::class, 'downloadErrors'])->whereNumber('id')->name('admin.products.import.download-errors')->middleware('permission:import-product');
    Route::apiResource('products', ProductController::class)->middleware('permission:view-products|view-product|create-product|update-product|delete-product');

    //==================== digital assets (product files) ====================/
    Route::get('products/{product}/digital-assets', [\Marvel\Http\Controllers\DigitalAssetController::class, 'index'])->whereNumber('product')->middleware('permission:view-products');
    Route::post('products/{product}/digital-assets', [\Marvel\Http\Controllers\DigitalAssetController::class, 'store'])->whereNumber('product')->name('admin.products.digital-assets.store')->middleware('permission:create-product');
    Route::get('digital-assets/{uuid}', [\Marvel\Http\Controllers\DigitalAssetController::class, 'show'])->whereUuid('uuid')->name('admin.digital-assets.show')->middleware('permission:view-products');
    Route::put('digital-assets/{uuid}', [\Marvel\Http\Controllers\DigitalAssetController::class, 'update'])->whereUuid('uuid')->name('admin.digital-assets.update')->middleware('permission:update-product');
    Route::delete('digital-assets/{uuid}', [\Marvel\Http\Controllers\DigitalAssetController::class, 'destroy'])->whereUuid('uuid')->name('admin.digital-assets.destroy')->middleware('permission:delete-product');
    Route::post('digital-assets/{uuid}/replace', [\Marvel\Http\Controllers\DigitalAssetController::class, 'replace'])->whereUuid('uuid')->name('admin.digital-assets.replace')->middleware('permission:update-product');
    // W5 — bulk-provision encrypted keys into a LICENSE pool (A2/A4).
    Route::post('digital-assets/{uuid}/license-keys', [\Marvel\Http\Controllers\DigitalAssetController::class, 'storeLicenseKeys'])->whereUuid('uuid')->name('admin.digital-assets.license-keys.store')->middleware('permission:update-product');

    //==================== digital entitlement management (W6) ====================/
    Route::get('digital-entitlements', [\Marvel\Http\Controllers\DigitalEntitlementController::class, 'index'])->name('admin.digital-entitlements.index')->middleware('permission:manage-digital-access');
    Route::get('digital-entitlements/{uuid}', [\Marvel\Http\Controllers\DigitalEntitlementController::class, 'show'])->whereUuid('uuid')->name('admin.digital-entitlements.show')->middleware('permission:manage-digital-access');
    Route::patch('digital-entitlements/{uuid}/limit', [\Marvel\Http\Controllers\DigitalEntitlementController::class, 'setLimit'])->whereUuid('uuid')->name('admin.digital-entitlements.limit')->middleware('permission:manage-digital-access');
    Route::post('digital-entitlements/{uuid}/revoke', [\Marvel\Http\Controllers\DigitalEntitlementController::class, 'revoke'])->whereUuid('uuid')->name('admin.digital-entitlements.revoke')->middleware('permission:manage-digital-access');
    Route::post('digital-entitlements/{uuid}/restore', [\Marvel\Http\Controllers\DigitalEntitlementController::class, 'restore'])->whereUuid('uuid')->name('admin.digital-entitlements.restore')->middleware('permission:manage-digital-access');

    //============================= flash sale ========================/
    Route::put('flash-sale/reorder', [FlashSaleController::class, 'reorder'])->middleware('permission:update-flash-sale');
    Route::apiResource('flash-sale', FlashSaleController::class)->middleware('permission:view-flash-sale|create-flash-sale|update-flash-sale|delete-flash-sale');
    Route::get('product-flash-sale-info', [FlashSaleController::class, 'getFlashSaleInfoByProductID']);

    //============================= faqs ========================/
    Route::put('faqs/reorder', [FaqsController::class, 'reorder'])->middleware('permission:update-faq');
    Route::apiResource('faqs', FaqsController::class)->middleware('permission:view-faqs|create-faq|update-faq|delete-faq');

    //============================= coupons ========================/
    Route::prefix('coupons/{coupon}')->group(function () {
        Route::get('assignments', [CouponAssignmentController::class, 'index'])->middleware('permission:view-coupon-assignments');
        Route::post('assignments', [CouponAssignmentController::class, 'store'])->middleware('permission:create-coupon-assignment');
        Route::get('assignments/{assignment}', [CouponAssignmentController::class, 'show'])->middleware('permission:view-coupon-assignments');
        Route::put('assignments/{assignment}', [CouponAssignmentController::class, 'update'])->middleware('permission:update-coupon-assignment');
        Route::delete('assignments/{assignment}', [CouponAssignmentController::class, 'destroy'])->middleware('permission:delete-coupon-assignment');
    });
    Route::apiResource('coupons', CouponController::class);

    //============================= wishlists ========================/
    Route::patch('wishlists/toggle', [WishlistController::class, 'toggle']);
    Route::apiResource('wishlists', WishlistController::class)->only(['index', 'store']);
    Route::get('wishlists/in_wishlist/{product_id}', [WishlistController::class, 'in_wishlist']);

    //============================= promotions ========================/
    Route::apiResource('promotions', PromotionController::class);

    //============================= tags ========================/
    Route::apiResource('tags', TagController::class);

    //============================= role and permission ========================/
    Route::get('/roles', [RoleAndPermissionController::class, 'getAllRoles']);
    Route::get('/roles/{id}', [RoleAndPermissionController::class, 'showRole']);
    Route::post('/roles', [RoleAndPermissionController::class, 'addRole']);
    Route::put('/roles/{id}', [RoleAndPermissionController::class, 'updateRole']);
    Route::delete('/roles/{id}', [RoleAndPermissionController::class, 'destroyRole']);
    Route::post('/users/{userId}/assign-role', [RoleAndPermissionController::class, 'assignRole']);
    Route::post('/users/{userId}/remove-role', [RoleAndPermissionController::class, 'removeRoleFromUser']);
    //=============================  permission ========================/
    Route::get('/permissions', [RoleAndPermissionController::class, 'getAllPermissions']);
    Route::post('/roles/{roleId}/permissions', [RoleAndPermissionController::class, 'assignPermissionToRole']);
    Route::post('/users/{userId}/permissions', [RoleAndPermissionController::class, 'givePermission']);
    Route::put('/users/{userId}/permissions', [RoleAndPermissionController::class, 'syncPermissions']);
    Route::delete('/users/{userId}/permissions', [RoleAndPermissionController::class, 'removePermission']);

    //=============================  pages ========================/
    Route::post('content-pages/{content_page}/attach-sections', [ContentPageController::class, 'attachSections']);
    Route::patch('content-pages/{content_page}/toggle-active', [ContentPageController::class, 'toggleActive']);
    Route::apiResource('content-pages', ContentPageController::class);
    Route::put('sections/reorder', [SectionController::class, 'reorder']);
    Route::get('sections/types', [SectionController::class, 'getTypeSection']);
    Route::patch('sections/{section}/toggle-active', [SectionController::class, 'toggleStatus']);
    Route::apiResource('sections', SectionController::class);
    Route::apiResource('section-types', SectionTypeController::class);
    Route::post('section-types/{type}/settings', [SectionTypeController::class, 'updateSettings']);
    Route::get('section-types/{type}/settings', [SectionTypeController::class, 'settings']);

    //============================= static pages ========================/
    Route::get('static-pages', [StaticPageController::class, 'index']);
    Route::get('static-pages/{static_page:slug}', [StaticPageController::class, 'show']);
    Route::put('static-pages/{static_page:slug}', [StaticPageController::class, 'update']);
    Route::post('static-pages/{static_page:slug}/sections', [StaticPageController::class, 'storeSection']);
    // reorder is declared before sections/{static_section} to prevent route capture
    Route::post('static-pages/{static_page:slug}/sections/reorder', [StaticPageController::class, 'reorderSections']);
    Route::put('static-pages/{static_page:slug}/sections/{static_section}', [StaticPageController::class, 'updateSection']);
    Route::delete('static-pages/{static_page:slug}/sections/{static_section}', [StaticPageController::class, 'destroySection']);

    Route::apiResource('users', UserController::class);
    Route::post('users/block-user', [UserController::class, 'banUser']);
    Route::post('users/unblock-user', [UserController::class, 'activeUser']);
    Route::post('add-points', [UserController::class, 'addPoints']);
    Route::post('users/make-admin', [UserController::class, 'makeOrRevokeAdmin']);


    Route::get('product-type', function () {
        $keys = [
            'best_product_sales',
            'brands_product',
            'new_arrivals',
            'all_product_discounts',
            'product_discount_today_or_low_qty',
            'flash_sales_product',
            'flash_sales_end_today',
            'product_for_parent_category',
            'flash_sales_end_week',
        ];

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = __("message.PRODUCT_TYPE." . strtoupper($key));
        }

        return $result;
    });
});


Route::middleware(['auth:sanctum', "throttle:cart", "lang"])->group(function () {
    Route::get('cart', [CartController::class, 'index']);
    Route::post('cart', [CartController::class, 'store']);
    Route::get('cart/{id}', [CartController::class, 'show'])->whereNumber('id');
    Route::post('cart/bulk-items', [CartController::class, 'pluckItemsToCart']);
    Route::put('cart/update-item', [CartController::class, 'update']);
    Route::delete('cart/delete-item/{itemId}', [CartController::class, 'deleteItemFromCart']);
    Route::delete('cart/delete-items', [CartController::class, 'destroy']);
});


Route::middleware(['auth:sanctum', 'throttle:analytics', 'permission:' . \Marvel\Enums\Permission::VIEW_ANALYTICS, 'lang'])->prefix('dashboard')->group(function () {
    Route::get('overview', [DashboardController::class, 'overview']);
    Route::get('revenue', [DashboardController::class, 'revenue']);
    Route::get('order-stats', [DashboardController::class, 'orderStats']);
    Route::get('recent-orders', [DashboardController::class, 'recentOrders']);
    Route::get('top-products', [DashboardController::class, 'topProducts']);
    Route::get('category-stats', [DashboardController::class, 'categoryStats']);
    Route::get('low-stock', [DashboardController::class, 'lowStock']);
    Route::get('sales', [DashboardController::class, 'salesAnalytics']);
    Route::get('customers', [DashboardController::class, 'customerAnalytics']);
    Route::get('products', [DashboardController::class, 'productAnalytics']);
    Route::get('orders', [DashboardController::class, 'orderAnalytics']);
    Route::get('categories', [DashboardController::class, 'categoryAnalytics']);
    Route::get('coupons', [DashboardController::class, 'couponAnalytics']);
    Route::get('cart', [DashboardController::class, 'cartAnalytics']);
    Route::get('finance', [DashboardController::class, 'financeAnalytics']);
    Route::get('reconciliation', [DashboardController::class, 'reconciliation']);
});
// need to ad end point to update data for dashboard
// Route::apiResource('shippings', ShippingController::class);


Route::group(['prefix' => 'admin', 'controller' => NotificationController::class], function () {
    Route::middleware('permission:' . \Marvel\Enums\Permission::VIEW_NOTIFICATIONS, 'lang')->group(function () {
        Route::get('notifications', 'index');
        Route::get('notifications/unread', 'unread');
    });
    Route::middleware('permission:' . \Marvel\Enums\Permission::MANAGE_NOTIFICATIONS, 'lang')->group(function () {
        Route::patch('notifications/{id}/read', 'markAsRead');
        Route::patch('notifications/read-all', 'markAllAsRead');
        Route::delete('notifications/{id}', 'destroy');
        Route::delete('notifications', 'destroyAll');
    });
});

Route::middleware(['auth:sanctum', 'lang'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread', [NotificationController::class, 'unread']);
    Route::get('notifications/{id}', [NotificationController::class, 'show']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);
});


/**
 * Refund Routes - Rate Limited (5/min per user)
 * Protects against refund fraud attempts
 */
Route::middleware(['auth:sanctum', 'throttle:refunds', 'lang'])->group(function () {
    Route::apiResource('refunds', RefundController::class);
});


Route::prefix('shipments')->middleware('auth:sanctum', 'lang')->group(function () {
    Route::get('/', [ShipmentController::class, 'index']);
    Route::get('uuid/{uuid}', [ShipmentController::class, 'showByUuid'])->whereUuid('uuid');
    Route::get('{id}', [ShipmentController::class, 'show'])->whereNumber('id');
    Route::post('/', [ShipmentController::class, 'store']);
    Route::put('{id}/status', [ShipmentController::class, 'updateStatus'])->whereNumber('id');
    Route::put('{id}', [ShipmentController::class, 'update'])->whereNumber('id');
});


Route::prefix('invoices')->group(function () {
    Route::middleware(['auth:sanctum', 'lang'])->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('{uuid}/download', [InvoiceController::class, 'download'])->whereUuid('uuid')->middleware('throttle:30,1');
        Route::get('{uuid}/view', [InvoiceController::class, 'view'])->whereUuid('uuid')->middleware('throttle:30,1');
        Route::get('{id}', [InvoiceController::class, 'show'])->whereNumber('id');
        Route::post('{id}/regenerate', [InvoiceController::class, 'regenerate'])->whereNumber('id');
        Route::post('{id}/correct', [InvoiceController::class, 'correct'])->whereNumber('id');
        Route::post('{id}/cancel', [InvoiceController::class, 'cancel'])->whereNumber('id');
        Route::post('{id}/debit-note', [InvoiceController::class, 'issueDebitNote'])->whereNumber('id');
        Route::get('verify/{uuid}', [InvoiceController::class, 'verify'])->middleware('throttle:5,1');
        Route::get('uuid/{uuid}', [InvoiceController::class, 'showByUuid']);
    });
});


Route::get('check-card-payment', function () {
    return [
        'CardNumber' => '2223000000000007',
        'CardExpiryMonthand year' => '01/39',
        'CardCVV' => '100',
    ];
});


Route::get('/enum-types', function () {
    return response()->json(
        [
            'discount-type' => \Marvel\Enums\DiscountType::getValues(),
            'coupon-type' => \Marvel\Enums\CouponType::getValues(),
            'product-type' => \Marvel\Enums\ProductType::getValues(),
            'promotion-type' => \Marvel\Enums\PromotionType::getValues(),
            'promotion-mount-type' => \Marvel\Enums\PromotionMountType::getValues(),
            'flash-sale-type' => \Marvel\Enums\FlashSaleType::getValues(),
        ],
        200
    );
});
