# Route Inventory — full runtime table (VERIFIED, append-only companion)

Generated: 2026-10-14T18:13:13+00:00
Total routes: 533
Note: canonical summary lives in error/route-inventory.md (parallel track). This file is the exhaustive method+URI+name+action+middleware table from this audit track; kept separate to avoid clobbering.

| # | Methods | URI | Name | Action | Middleware |
|---|---|---|---|---|---|
| 1 | GET|HEAD | api/documentation | l5-swagger.default.api | \L5Swagger\Http\Controllers\SwaggerController@api | L5Swagger\Http\Middleware\Config |
| 2 | GET|HEAD | docs/{jsonFile?} | l5-swagger.default.docs | \L5Swagger\Http\Controllers\SwaggerController@docs | L5Swagger\Http\Middleware\Config |
| 3 | GET|HEAD | docs/asset/{asset} | l5-swagger.default.asset | \L5Swagger\Http\Controllers\SwaggerAssetController@index | L5Swagger\Http\Middleware\Config |
| 4 | GET|HEAD | api/oauth2-callback | l5-swagger.default.oauth2_callback | \L5Swagger\Http\Controllers\SwaggerController@oauth2Callback | L5Swagger\Http\Middleware\Config |
| 5 | GET|HEAD | bkash/payment |  | App\Http\Controllers\BkashTokenizePaymentController@index | web |
| 6 | GET|HEAD | bkash/create-payment | bkash-create-payment | App\Http\Controllers\BkashTokenizePaymentController@createPayment | web |
| 7 | GET|HEAD | bkash/callback | bkash-callBack | App\Http\Controllers\BkashTokenizePaymentController@callBack | web |
| 8 | GET|HEAD | bkash/search/{trxID} | bkash-serach | App\Http\Controllers\BkashTokenizePaymentController@searchTnx | web |
| 9 | GET|HEAD | bkash/refund | bkash-refund | App\Http\Controllers\BkashTokenizePaymentController@refund | web |
| 10 | GET|HEAD | bkash/refund/status | bkash-refund-status | App\Http\Controllers\BkashTokenizePaymentController@refundStatus | web |
| 11 | GET|HEAD | sanctum/csrf-cookie | sanctum.csrf-cookie | Laravel\Sanctum\Http\Controllers\CsrfCookieController@show | web |
| 12 | GET|HEAD | playground | graphiql | MLL\GraphiQL\GraphiQLController |  |
| 13 | GET|POST|HEAD | graphql | graphql | Nuwave\Lighthouse\Support\Http\Controllers\GraphQLController | Nuwave\Lighthouse\Support\Http\Middleware\AcceptJson,Nuwave\Lighthouse\Support\Http\Middleware\AttemptAuthentication |
| 14 | GET|HEAD | _ignition/health-check | ignition.healthCheck | Spatie\LaravelIgnition\Http\Controllers\HealthCheckController | Spatie\LaravelIgnition\Http\Middleware\RunnableSolutionsEnabled |
| 15 | POST | _ignition/execute-solution | ignition.executeSolution | Spatie\LaravelIgnition\Http\Controllers\ExecuteSolutionController | Spatie\LaravelIgnition\Http\Middleware\RunnableSolutionsEnabled |
| 16 | POST | _ignition/update-config | ignition.updateConfig | Spatie\LaravelIgnition\Http\Controllers\UpdateConfigController | Spatie\LaravelIgnition\Http\Middleware\RunnableSolutionsEnabled |
| 17 | GET|POST|HEAD | broadcasting/auth |  | \Illuminate\Broadcasting\BroadcastController@authenticate | web |
| 18 | GET|HEAD | api/v1/general/nav-data |  | App\Http\Controllers\Api\General\HomeController@navData | api,throttle:public-api |
| 19 | GET|HEAD | api/v1/general/categories |  | App\Http\Controllers\Api\General\CategoryController@index | api,throttle:public-api |
| 20 | GET|HEAD | api/v1/general/categories/{slug} |  | App\Http\Controllers\Api\General\CategoryController@getCategoryBySlug | api,throttle:public-api |
| 21 | GET|HEAD | api/v1/general/brands |  | App\Http\Controllers\Api\General\BrandController@index | api,throttle:public-api |
| 22 | GET|HEAD | api/v1/general/brands/{slug} |  | App\Http\Controllers\Api\General\BrandController@getBrandBySlug | api,throttle:public-api |
| 23 | GET|HEAD | api/v1/general/brands-products |  | App\Http\Controllers\Api\General\BrandController@getBrandsProductsByQtySet | api,throttle:public-api |
| 24 | GET|HEAD | api/v1/general/banners |  | App\Http\Controllers\Api\General\BannerController@index | api,throttle:public-api |
| 25 | GET|HEAD | api/v1/general/banners/{slug} |  | App\Http\Controllers\Api\General\BannerController@getBannerBySlug | api,throttle:public-api |
| 26 | GET|HEAD | api/v1/general/sliders |  | App\Http\Controllers\Api\General\SliderController@index | api,throttle:public-api |
| 27 | GET|HEAD | api/v1/general/sliders/{slug} |  | App\Http\Controllers\Api\General\SliderController@getSliderBySlug | api,throttle:public-api |
| 28 | GET|HEAD | api/v1/general/tags |  | App\Http\Controllers\Api\General\TagController@index | api,throttle:public-api |
| 29 | GET|HEAD | api/v1/general/tags/{slug} |  | App\Http\Controllers\Api\General\TagController@show | api,throttle:public-api |
| 30 | GET|HEAD | api/v1/general/promotions |  | App\Http\Controllers\Api\General\PromotionController@index | api,throttle:public-api |
| 31 | GET|HEAD | api/v1/general/promotions/{slug} |  | App\Http\Controllers\Api\General\PromotionController@getPromotionBySlug | api,throttle:public-api |
| 32 | GET|HEAD | api/v1/general/coupons |  | App\Http\Controllers\Api\General\CouponController@index | api,throttle:public-api |
| 33 | GET|HEAD | api/v1/general/content-pages | general-content-page-index | App\Http\Controllers\Api\General\ContentPageController@index | api,throttle:public-api |
| 34 | GET|HEAD | api/v1/general/content-pages/{slug} | general-content-page-show | App\Http\Controllers\Api\General\ContentPageController@show | api,throttle:public-api |
| 35 | GET|HEAD | api/v1/general/static-pages | general-static-page-index | App\Http\Controllers\Api\General\StaticPageController@index | api,throttle:public-api |
| 36 | GET|HEAD | api/v1/general/static-pages/{slug} | general-static-page-show | App\Http\Controllers\Api\General\StaticPageController@show | api,throttle:public-api |
| 37 | GET|HEAD | api/v1/general/products |  | App\Http\Controllers\Api\General\ProductController@index | api,throttle:public-api |
| 38 | GET|HEAD | api/v1/general/products/{slug} |  | App\Http\Controllers\Api\General\ProductController@getProductBySlug | api,throttle:public-api |
| 39 | GET|HEAD | api/v1/general/flash-sales |  | App\Http\Controllers\Api\General\FlashSaleController@index | api,throttle:public-api |
| 40 | GET|HEAD | api/v1/general/flash-sales/{slug} |  | App\Http\Controllers\Api\General\FlashSaleController@getFlashSaleBySlug | api,throttle:public-api |
| 41 | GET|HEAD | api/v1/general/flash-sale-products |  | App\Http\Controllers\Api\General\FlashSaleController@getFlashSalesAndHereProductsByQtySet | api,throttle:public-api |
| 42 | GET|HEAD | api/v1/general/flash-sale-products-ending-this-week |  | App\Http\Controllers\Api\General\FlashSaleController@getFlashSaleProductsEndingThisWeek | api,throttle:public-api |
| 43 | GET|HEAD | api/v1/general/flash-sale-products-ending-today |  | App\Http\Controllers\Api\General\FlashSaleController@getFlashSaleProductsEndingToday | api,throttle:public-api |
| 44 | GET|HEAD | api/v1/general/settings | settings.front | App\Http\Controllers\Api\General\SettingController@index | api,throttle:public-api |
| 45 | GET|HEAD | api/v1/general/faqs |  | App\Http\Controllers\Api\General\FAQController@index | api,throttle:public-api |
| 46 | GET|HEAD | api/v1/general/governorates |  | App\Http\Controllers\Api\General\GovernorateController@index | api,throttle:public-api |
| 47 | GET|HEAD | api/v1/general/governorates/{id} |  | App\Http\Controllers\Api\General\GovernorateController@show | api,throttle:public-api |
| 48 | GET|HEAD | api/v1/general/countries |  | App\Http\Controllers\Api\General\CountryController@index | api,throttle:public-api |
| 49 | GET|HEAD | api/v1/general/countries/{id} |  | App\Http\Controllers\Api\General\CountryController@show | api,throttle:public-api |
| 50 | GET|HEAD | api/v1/general/cities |  | App\Http\Controllers\Api\General\CityController@index | api,throttle:public-api |
| 51 | GET|HEAD | api/v1/general/cities/{id} |  | App\Http\Controllers\Api\General\CityController@show | api,throttle:public-api |
| 52 | GET|HEAD | api/v1/general/pickup-locations |  | App\Http\Controllers\Api\General\PickupLocationController@index | api,throttle:public-api |
| 53 | GET|HEAD | api/v1/general/pickup-locations/{id} |  | App\Http\Controllers\Api\General\PickupLocationController@show | api,throttle:public-api |
| 54 | GET|HEAD | api/v1/general/fast-shipping/status |  | App\Http\Controllers\Api\General\FastShippingController@status | api,throttle:public-api |
| 55 | GET|HEAD | api/v1/general/site-reviews |  | App\Http\Controllers\Api\General\SiteReviewController@index | api,throttle:public-api |
| 56 | GET|HEAD | api/v1/general/currencies |  | App\Http\Controllers\Api\Currency\CurrencyController@index | api,throttle:public-api |
| 57 | POST | api/v1/general/currencies/select |  | App\Http\Controllers\Api\Currency\CurrencyController@select | api,throttle:public-api |
| 58 | GET|HEAD | api/v1/general/payment-gateways | api.general.payment-gateways.index | App\Http\Controllers\Api\General\PaymentGatewayController@index | api,throttle:public-api |
| 59 | GET|HEAD | api/v1/general/order-flows/available | api.order-flows.available | App\Http\Controllers\Api\General\FlowDefinitionController@available | api,throttle:public-api |
| 60 | GET|HEAD | api/v1/general/order-flows/by-shipping-type/{shippingType} | api.order-flows.by-shipping-type | App\Http\Controllers\Api\General\FlowDefinitionController@byShippingType | api,throttle:public-api |
| 61 | GET|POST|HEAD | api/v1/general/checkout/callback | api.checkout.callback | App\Http\Controllers\Api\General\OrderController@checkoutCallback | api,throttle:public-api,throttle:payment-callback |
| 62 | GET|POST|HEAD | api/v1/general/checkout/error-callback | api.checkout.errorCallback | App\Http\Controllers\Api\General\OrderController@checkoutErrorCallback | api,throttle:public-api,throttle:payment-callback |
| 63 | POST | api/v1/general/checkout/webhooks/stripe | api.checkout.webhooks.stripe | App\Http\Controllers\Api\General\PaymentWebhookController@stripe | api,throttle:public-api,throttle:payment-webhook |
| 64 | POST | api/v1/general/checkout/webhooks/paypal | api.checkout.webhooks.paypal | App\Http\Controllers\Api\General\PaymentWebhookController@paypal | api,throttle:public-api,throttle:payment-webhook |
| 65 | POST | api/v1/general/track-order | api.tracking.public | App\Http\Controllers\Api\General\OrderTrackingController@trackByOrderNumber | api,throttle:public-api,throttle:public-tracking |
| 66 | GET|HEAD | api/v1/general/coupons/mine |  | App\Http\Controllers\Api\General\CouponController@myCoupons | api,auth:sanctum,throttle:authenticated |
| 67 | GET|HEAD | api/v1/general/coupons/available |  | App\Http\Controllers\Api\General\CouponController@available | api,auth:sanctum,throttle:authenticated |
| 68 | POST | api/v1/general/coupons/apply |  | App\Http\Controllers\Api\General\CouponController@applyCoupon | api,auth:sanctum,throttle:authenticated |
| 69 | POST | api/v1/general/coupons/{id}/claim |  | App\Http\Controllers\Api\General\CouponController@claim | api,auth:sanctum,throttle:authenticated |
| 70 | GET|HEAD | api/v1/general/checkout/promotions |  | App\Http\Controllers\Api\General\OrderController@eligiblePromotions | api,auth:sanctum,throttle:authenticated |
| 71 | POST | api/v1/general/checkout |  | App\Http\Controllers\Api\General\OrderController@checkout | api,auth:sanctum,throttle:authenticated |
| 72 | POST | api/v1/general/checkout/cod/{orderId}/mark-paid |  | App\Http\Controllers\Api\General\OrderController@markCodAsPaid | api,auth:sanctum,throttle:authenticated,permission:payments.mark_paid |
| 73 | POST | api/v1/general/checkout/cashier/{orderId}/mark-paid |  | App\Http\Controllers\Api\General\OrderController@markCashierPaid | api,auth:sanctum,throttle:authenticated,permission:payments.mark_paid |
| 74 | POST | api/v1/general/fast-shipping/checkout |  | App\Http\Controllers\Api\General\FastShippingController@checkout | api,auth:sanctum,throttle:authenticated |
| 75 | GET|HEAD | api/v1/general/orders |  | App\Http\Controllers\Api\General\OrderController@index | api,auth:sanctum,throttle:authenticated |
| 76 | GET|HEAD | api/v1/general/orders/{orderId}/invoice |  | App\Http\Controllers\Api\General\OrderController@invoiceByOrderId | api,auth:sanctum,throttle:authenticated |
| 77 | GET|HEAD | api/v1/general/orders/{id} |  | App\Http\Controllers\Api\General\OrderController@show | api,auth:sanctum,throttle:authenticated |
| 78 | POST | api/v1/general/orders/{id}/cancel |  | App\Http\Controllers\Api\General\OrderController@cancel | api,auth:sanctum,throttle:authenticated |
| 79 | GET|HEAD | api/v1/general/my-orders | api.tracking.my-orders | App\Http\Controllers\Api\General\OrderTrackingController@listUserOrders | api,auth:sanctum,throttle:authenticated |
| 80 | GET|HEAD | api/v1/general/orders/{orderId}/track | api.tracking.order | App\Http\Controllers\Api\General\OrderTrackingController@trackAuthenticatedOrder | api,auth:sanctum,throttle:authenticated |
| 81 | GET|HEAD | api/v1/general/digital/downloads |  | App\Http\Controllers\Api\General\DigitalDownloadController@index | api,auth:sanctum,throttle:authenticated |
| 82 | GET|HEAD | api/v1/general/digital/license/{entitlement}/{asset} | general.digital.license | App\Http\Controllers\Api\General\DigitalDownloadController@reveal | api,auth:sanctum,throttle:authenticated |
| 83 | GET|HEAD | api/v1/general/digital/url/{entitlement}/{asset} | general.digital.url | App\Http\Controllers\Api\General\DigitalDownloadController@redirectToExternal | api,auth:sanctum,throttle:authenticated |
| 84 | GET|HEAD | api/v1/general/digital/download/{entitlement}/{asset} | general.digital.download | App\Http\Controllers\Api\General\DigitalDownloadController@download | api,signed,throttle:30,1 |
| 85 | POST | api/v1/general/products/{id}/reviews |  | App\Http\Controllers\Api\General\ProductController@addProductReview | api,auth:sanctum,throttle:authenticated |
| 86 | PUT | api/v1/general/products/reviews/{id} |  | App\Http\Controllers\Api\General\ProductController@updateProductReview | api,auth:sanctum,throttle:authenticated |
| 87 | POST | api/v1/general/device-tokens |  | App\Http\Controllers\Api\General\DeviceTokenController@store | api,auth:sanctum,throttle:authenticated |
| 88 | DELETE | api/v1/general/device-tokens |  | App\Http\Controllers\Api\General\DeviceTokenController@destroy | api,auth:sanctum,throttle:authenticated |
| 89 | POST | api/v1/general/site-reviews |  | App\Http\Controllers\Api\General\SiteReviewController@store | api,auth:sanctum,throttle:authenticated |
| 90 | GET|HEAD | api/v1/general/invoices/my-invoices |  | App\Http\Controllers\Api\InvoiceController@myInvoices | api,auth:sanctum,throttle:authenticated |
| 91 | GET|HEAD | api/v1/general/invoices/verify/{uuid} |  | App\Http\Controllers\Api\InvoiceController@verify | api,auth:sanctum,throttle:authenticated,throttle:5,1 |
| 92 | GET|HEAD | api/v1/general/invoices/view/{uuid} | general.invoices.view | App\Http\Controllers\Api\InvoiceController@viewByUuidSigned | api,signed,throttle:30,1 |
| 93 | GET|HEAD | api/v1/general/invoices/download/{uuid} | general.invoices.download | App\Http\Controllers\Api\InvoiceController@downloadByUuidSigned | api,signed,throttle:30,1 |
| 94 | GET|HEAD | api/v1/admin/tracking/dashboard | api.admin.tracking.dashboard | App\Http\Controllers\Api\Admin\AdminOrderTrackingController@dashboard | api,auth:sanctum,throttle:admin |
| 95 | GET|HEAD | api/v1/admin/tracking/orders | api.admin.tracking.orders | App\Http\Controllers\Api\Admin\AdminOrderTrackingController@listOrders | api,auth:sanctum,throttle:admin |
| 96 | GET|HEAD | api/v1/admin/tracking/orders/{orderId} | api.admin.tracking.order | App\Http\Controllers\Api\Admin\AdminOrderTrackingController@trackOrder | api,auth:sanctum,throttle:admin |
| 97 | GET|HEAD | api/v1/admin/tracking/requires-attention | api.admin.tracking.attention | App\Http\Controllers\Api\Admin\AdminOrderTrackingController@requiresAttention | api,auth:sanctum,throttle:admin |
| 98 | GET|HEAD | api/v1/user/notification-preferences | api.user.notification-preferences.index | App\Http\Controllers\Api\User\NotificationPreferencesController@index | api,auth:sanctum,throttle:authenticated |
| 99 | PUT | api/v1/user/notification-preferences | api.user.notification-preferences.update | App\Http\Controllers\Api\User\NotificationPreferencesController@update | api,auth:sanctum,throttle:authenticated |
| 100 | POST | api/v1/user/devices/register | api.user.devices.register | App\Http\Controllers\Api\User\NotificationPreferencesController@registerDevice | api,auth:sanctum,throttle:authenticated |
| 101 | DELETE | api/v1/user/devices/{deviceId} | api.user.devices.unregister | App\Http\Controllers\Api\User\NotificationPreferencesController@unregisterDevice | api,auth:sanctum,throttle:authenticated |
| 102 | GET|HEAD | api/v1/user/notifications/history | api.user.notifications.history | App\Http\Controllers\Api\User\NotificationPreferencesController@notificationHistory | api,auth:sanctum,throttle:authenticated |
| 103 | GET|HEAD | / |  | Closure | web |
| 104 | GET|POST|HEAD | api/v1/broadcasting/auth |  | \Illuminate\Broadcasting\BroadcastController@authenticate | api,auth:sanctum |
| 105 | POST | api/v1/register |  | Marvel\Http\Controllers\UserController@register | api,throttle:login,lang |
| 106 | POST | api/v1/token |  | Marvel\Http\Controllers\UserController@token | api,throttle:login,lang |
| 107 | POST | api/v1/admin-login |  | Marvel\Http\Controllers\UserController@adminToken | api,throttle:login,lang |
| 108 | GET|HEAD | api/v1/social/redirect |  | Marvel\Http\Controllers\SocialController@redirectFromQuery | api,throttle:login,lang |
| 109 | POST | api/v1/social/exchange |  | Marvel\Http\Controllers\SocialController@exchange | api,throttle:login,lang |
| 110 | GET|HEAD | api/v1/social/{provider} |  | Marvel\Http\Controllers\SocialController@redirect | api,throttle:login,lang |
| 111 | GET|HEAD | api/v1/social/{provider}/callback |  | Marvel\Http\Controllers\SocialController@callback | api,throttle:login,lang |
| 112 | POST | api/v1/logout |  | Marvel\Http\Controllers\UserController@logout | api,auth:sanctum,lang |
| 113 | GET|HEAD | api/v1/me |  | Marvel\Http\Controllers\UserController@me | api,auth:sanctum,throttle:admin,lang |
| 114 | POST | api/v1/forget-password |  | Marvel\Http\Controllers\UserController@forgetPassword | api,throttle:sensitive,lang |
| 115 | POST | api/v1/verify-forget-password-token |  | Marvel\Http\Controllers\UserController@verifyForgetPasswordToken | api,throttle:sensitive,lang |
| 116 | POST | api/v1/reset-password |  | Marvel\Http\Controllers\UserController@resetPassword | api,throttle:sensitive,lang |
| 117 | POST | api/v1/send-otp-code |  | Marvel\Http\Controllers\UserController@sendUserOtp | api,throttle:otp,lang |
| 118 | POST | api/v1/otp-login |  | Marvel\Http\Controllers\UserController@otpLogin | api,throttle:otp,lang |
| 119 | POST | api/v1/change-password |  | Marvel\Http\Controllers\UserController@changePassword | api,auth:sanctum,lang |
| 120 | POST | api/v1/update-contact |  | Marvel\Http\Controllers\UserController@updateContact | api,auth:sanctum,lang |
| 121 | GET|HEAD | api/v1/address | address.index | Marvel\Http\Controllers\AddressController@index | api,auth:sanctum,lang |
| 122 | POST | api/v1/address | address.store | Marvel\Http\Controllers\AddressController@store | api,auth:sanctum,lang |
| 123 | GET|HEAD | api/v1/address/{address} | address.show | Marvel\Http\Controllers\AddressController@show | api,auth:sanctum,lang |
| 124 | PUT|PATCH | api/v1/address/{address} | address.update | Marvel\Http\Controllers\AddressController@update | api,auth:sanctum,lang |
| 125 | DELETE | api/v1/address/{address} | address.destroy | Marvel\Http\Controllers\AddressController@destroy | api,auth:sanctum,lang |
| 126 | POST | api/v1/contacts/{id}/reply |  | Marvel\Http\Controllers\ContactController@sendReply | api,throttle:sensitive,lang,permission:update-contact |
| 127 | POST | api/v1/contact-us |  | Marvel\Http\Controllers\ContactController@store | api,throttle:sensitive,lang |
| 128 | DELETE | api/v1/contacts/delete-all |  | Marvel\Http\Controllers\ContactController@deleteAll | api,throttle:sensitive,lang,permission:delete-contact |
| 129 | DELETE | api/v1/contacts/delete-all-read |  | Marvel\Http\Controllers\ContactController@deleteAllReadContacts | api,throttle:sensitive,lang,permission:delete-read-contacts |
| 130 | GET|HEAD | api/v1/contacts | contacts.index | Marvel\Http\Controllers\ContactController@index | api,throttle:sensitive,lang,permission:view-contacts |
| 131 | POST | api/v1/contacts | contacts.store | Marvel\Http\Controllers\ContactController@store | api,throttle:sensitive,lang |
| 132 | GET|HEAD | api/v1/contacts/{contact} | contacts.show | Marvel\Http\Controllers\ContactController@show | api,throttle:sensitive,lang,permission:update-contact |
| 133 | DELETE | api/v1/contacts/{contact} | contacts.destroy | Marvel\Http\Controllers\ContactController@destroy | api,throttle:sensitive,lang,permission:delete-contact |
| 134 | GET|HEAD | api/v1/settings |  | Marvel\Http\Controllers\SettingsController@index | api,auth:sanctum,throttle:admin,lang,permission:view-settings|update-settings,permission:view-settings |
| 135 | PUT | api/v1/settings |  | Marvel\Http\Controllers\SettingsController@update | api,auth:sanctum,throttle:admin,lang,permission:update-settings |
| 136 | GET|HEAD | api/v1/logs/activity | admin.activity-log.index | Marvel\Http\Controllers\ActivityLogController@index | api,auth:sanctum,throttle:admin,lang,permission:view-activity-log |
| 137 | GET|HEAD | api/v1/fast-shipping/settings |  | Marvel\Http\Controllers\FastShippingController@getSettings | api,auth:sanctum,throttle:admin,lang,permission:view-fast-shipping |
| 138 | PUT | api/v1/fast-shipping/settings |  | Marvel\Http\Controllers\FastShippingController@updateSettings | api,auth:sanctum,throttle:admin,lang,permission:update-fast-shipping |
| 139 | POST | api/v1/admin-users/add |  | Marvel\Http\Controllers\UserController@adminAddUsers | api,auth:sanctum,throttle:admin,lang,permission:create-user |
| 140 | PUT | api/v1/admin-users/update-activation |  | Marvel\Http\Controllers\UserController@adminUpdateActivationUsers | api,auth:sanctum,throttle:admin,lang,permission:edit-user |
| 141 | DELETE | api/v1/admin-users/delete/{id} |  | Marvel\Http\Controllers\UserController@adminDeleteUsers | api,auth:sanctum,throttle:admin,lang,permission:delete-user |
| 142 | PUT | api/v1/admin-users/restore/{id} |  | Marvel\Http\Controllers\UserController@adminRestoreUser | api,auth:sanctum,throttle:admin,lang,permission:restore-user |
| 143 | GET|HEAD | api/v1/admin-users/trashed |  | Marvel\Http\Controllers\UserController@adminTrashedUsers | api,auth:sanctum,throttle:admin,lang,permission:view-users |
| 144 | DELETE | api/v1/admin-users/delete-forever/{id} |  | Marvel\Http\Controllers\UserController@adminDeleteUsersForever | api,auth:sanctum,throttle:admin,lang,permission:delete-user |
| 145 | POST | api/v1/brands/import | admin.brands.import | Marvel\Http\Controllers\BrandImportController@import | api,auth:sanctum,throttle:admin,lang,permission:import-brand |
| 146 | GET|HEAD | api/v1/brands/import/sample | admin.brands.import.sample | Marvel\Http\Controllers\BrandImportController@downloadSample | api,auth:sanctum,throttle:admin,lang,permission:import-brand |
| 147 | GET|HEAD | api/v1/brands/import/{id} | admin.brands.import.status | Marvel\Http\Controllers\BrandImportController@status | api,auth:sanctum,throttle:admin,lang,permission:import-brand |
| 148 | POST | api/v1/brands/import/{id}/cancel | admin.brands.import.cancel | Marvel\Http\Controllers\BrandImportController@cancel | api,auth:sanctum,throttle:admin,lang,permission:import-brand |
| 149 | GET|HEAD | api/v1/brands/import/{id}/download-errors | admin.brands.import.download-errors | Marvel\Http\Controllers\BrandImportController@downloadErrors | api,auth:sanctum,throttle:admin,lang,permission:import-brand |
| 150 | GET|HEAD | api/v1/brands/export | admin.brands.export | Marvel\Http\Controllers\BrandExportController@export | api,auth:sanctum,throttle:admin,lang,permission:export-brand |
| 151 | POST | api/v1/brands/export | admin.brands.export.post | Marvel\Http\Controllers\BrandExportController@export | api,auth:sanctum,throttle:admin,lang,permission:export-brand |
| 152 | GET|HEAD | api/v1/brands/export/{id} | admin.brands.export.status | Marvel\Http\Controllers\BrandExportController@status | api,auth:sanctum,throttle:admin,lang,permission:export-brand |
| 153 | GET|HEAD | api/v1/brands/export/{id}/download | admin.brands.export.download | Marvel\Http\Controllers\BrandExportController@download | api,auth:sanctum,throttle:admin,lang,permission:export-brand |
| 154 | POST | api/v1/brands/export/{id}/cancel | admin.brands.export.cancel | Marvel\Http\Controllers\BrandExportController@cancel | api,auth:sanctum,throttle:admin,lang,permission:export-brand |
| 155 | PUT | api/v1/brands/reorder |  | Marvel\Http\Controllers\BrandController@reorder | api,auth:sanctum,throttle:admin,lang,permission:update-brand |
| 156 | GET|HEAD | api/v1/brands | brands.index | Marvel\Http\Controllers\BrandController@index | api,auth:sanctum,throttle:admin,lang,permission:view-brands|view-brand|create-brand|update-brand|delete-brand|import-brand|export-brand,permission:view-brands |
| 157 | POST | api/v1/brands | brands.store | Marvel\Http\Controllers\BrandController@store | api,auth:sanctum,throttle:admin,lang,permission:view-brands|view-brand|create-brand|update-brand|delete-brand|import-brand|export-brand,permission:create-brand |
| 158 | GET|HEAD | api/v1/brands/{brand} | brands.show | Marvel\Http\Controllers\BrandController@show | api,auth:sanctum,throttle:admin,lang,permission:view-brands|view-brand|create-brand|update-brand|delete-brand|import-brand|export-brand,permission:view-brands |
| 159 | PUT|PATCH | api/v1/brands/{brand} | brands.update | Marvel\Http\Controllers\BrandController@update | api,auth:sanctum,throttle:admin,lang,permission:view-brands|view-brand|create-brand|update-brand|delete-brand|import-brand|export-brand,permission:update-brand |
| 160 | DELETE | api/v1/brands/{brand} | brands.destroy | Marvel\Http\Controllers\BrandController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-brands|view-brand|create-brand|update-brand|delete-brand|import-brand|export-brand,permission:delete-brand |
| 161 | PATCH | api/v1/sliders/change-status |  | Marvel\Http\Controllers\SliderController@changeStatus | api,auth:sanctum,throttle:admin,lang,permission:update-slider |
| 162 | PUT | api/v1/sliders/reorder |  | Marvel\Http\Controllers\SliderController@reorder | api,auth:sanctum,throttle:admin,lang,permission:update-slider |
| 163 | GET|HEAD | api/v1/sliders | sliders.index | Marvel\Http\Controllers\SliderController@index | api,auth:sanctum,throttle:admin,lang,permission:view-slider|create-slider|update-slider|delete-slider,permission:view-slider |
| 164 | POST | api/v1/sliders | sliders.store | Marvel\Http\Controllers\SliderController@store | api,auth:sanctum,throttle:admin,lang,permission:view-slider|create-slider|update-slider|delete-slider,permission:create-slider |
| 165 | GET|HEAD | api/v1/sliders/{slider} | sliders.show | Marvel\Http\Controllers\SliderController@show | api,auth:sanctum,throttle:admin,lang,permission:view-slider|create-slider|update-slider|delete-slider,permission:view-slider |
| 166 | PUT|PATCH | api/v1/sliders/{slider} | sliders.update | Marvel\Http\Controllers\SliderController@update | api,auth:sanctum,throttle:admin,lang,permission:view-slider|create-slider|update-slider|delete-slider,permission:update-slider |
| 167 | DELETE | api/v1/sliders/{slider} | sliders.destroy | Marvel\Http\Controllers\SliderController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-slider|create-slider|update-slider|delete-slider,permission:delete-slider |
| 168 | PUT | api/v1/categories/feature |  | Marvel\Http\Controllers\CategoryController@addOrRemoveCategoryFromFeature | api,auth:sanctum,throttle:admin,lang,permission:update-category |
| 169 | POST | api/v1/categories/import | admin.categories.import | Marvel\Http\Controllers\CategoryImportController@import | api,auth:sanctum,throttle:admin,lang,permission:import-category |
| 170 | GET|HEAD | api/v1/categories/import/sample | admin.categories.import.sample | Marvel\Http\Controllers\CategoryImportController@downloadSample | api,auth:sanctum,throttle:admin,lang,permission:import-category |
| 171 | GET|HEAD | api/v1/categories/import/{id} | admin.categories.import.status | Marvel\Http\Controllers\CategoryImportController@status | api,auth:sanctum,throttle:admin,lang,permission:import-category |
| 172 | POST | api/v1/categories/import/{id}/cancel | admin.categories.import.cancel | Marvel\Http\Controllers\CategoryImportController@cancel | api,auth:sanctum,throttle:admin,lang,permission:import-category |
| 173 | GET|HEAD | api/v1/categories/import/{id}/download-errors | admin.categories.import.download-errors | Marvel\Http\Controllers\CategoryImportController@downloadErrors | api,auth:sanctum,throttle:admin,lang,permission:import-category |
| 174 | GET|HEAD | api/v1/categories/export | admin.categories.export | Marvel\Http\Controllers\CategoryExportController@export | api,auth:sanctum,throttle:admin,lang,permission:export-category |
| 175 | GET|HEAD | api/v1/categories/export/{id} | admin.categories.export.status | Marvel\Http\Controllers\CategoryExportController@status | api,auth:sanctum,throttle:admin,lang,permission:export-category |
| 176 | GET|HEAD | api/v1/categories/export/{id}/download | admin.categories.export.download | Marvel\Http\Controllers\CategoryExportController@download | api,auth:sanctum,throttle:admin,lang,permission:export-category |
| 177 | POST | api/v1/categories/export/{id}/cancel | admin.categories.export.cancel | Marvel\Http\Controllers\CategoryExportController@cancel | api,auth:sanctum,throttle:admin,lang,permission:export-category |
| 178 | POST | api/v1/categories/bulk-delete | admin.categories.bulk-delete | Marvel\Http\Controllers\CategoryController@bulkDelete | api,auth:sanctum,throttle:admin,lang,permission:delete-category |
| 179 | GET|HEAD | api/v1/categories/bulk-delete/{id} | admin.categories.bulk-delete.status | Marvel\Http\Controllers\CategoryController@bulkDeleteStatus | api,auth:sanctum,throttle:admin,lang,permission:delete-category |
| 180 | POST | api/v1/categories/bulk-delete/{id}/cancel | admin.categories.bulk-delete.cancel | Marvel\Http\Controllers\CategoryController@cancelBulkDelete | api,auth:sanctum,throttle:admin,lang,permission:delete-category |
| 181 | GET|HEAD | api/v1/categories | categories.index | Marvel\Http\Controllers\CategoryController@index | api,auth:sanctum,throttle:admin,lang,permission:view-categories|view-category|create-category|update-category|delete-category,permission:view-categories |
| 182 | POST | api/v1/categories | categories.store | Marvel\Http\Controllers\CategoryController@store | api,auth:sanctum,throttle:admin,lang,permission:view-categories|view-category|create-category|update-category|delete-category,permission:create-category |
| 183 | GET|HEAD | api/v1/categories/{category} | categories.show | Marvel\Http\Controllers\CategoryController@show | api,auth:sanctum,throttle:admin,lang,permission:view-categories|view-category|create-category|update-category|delete-category,permission:view-categories |
| 184 | PUT|PATCH | api/v1/categories/{category} | categories.update | Marvel\Http\Controllers\CategoryController@update | api,auth:sanctum,throttle:admin,lang,permission:view-categories|view-category|create-category|update-category|delete-category,permission:update-category |
| 185 | DELETE | api/v1/categories/{category} | categories.destroy | Marvel\Http\Controllers\CategoryController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-categories|view-category|create-category|update-category|delete-category,permission:delete-category |
| 186 | GET|HEAD | api/v1/pickup-locations | pickup-locations.index | Marvel\Http\Controllers\PickupLocationController@index | api,auth:sanctum,throttle:admin,lang,permission:view-pickup-locations|create-pickup-location|update-pickup-location|delete-pickup-location,permission:view-pickup-locations |
| 187 | POST | api/v1/pickup-locations | pickup-locations.store | Marvel\Http\Controllers\PickupLocationController@store | api,auth:sanctum,throttle:admin,lang,permission:view-pickup-locations|create-pickup-location|update-pickup-location|delete-pickup-location,permission:create-pickup-location |
| 188 | GET|HEAD | api/v1/pickup-locations/{pickup_location} | pickup-locations.show | Marvel\Http\Controllers\PickupLocationController@show | api,auth:sanctum,throttle:admin,lang,permission:view-pickup-locations|create-pickup-location|update-pickup-location|delete-pickup-location,permission:view-pickup-locations |
| 189 | PUT|PATCH | api/v1/pickup-locations/{pickup_location} | pickup-locations.update | Marvel\Http\Controllers\PickupLocationController@update | api,auth:sanctum,throttle:admin,lang,permission:view-pickup-locations|create-pickup-location|update-pickup-location|delete-pickup-location,permission:update-pickup-location |
| 190 | DELETE | api/v1/pickup-locations/{pickup_location} | pickup-locations.destroy | Marvel\Http\Controllers\PickupLocationController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-pickup-locations|create-pickup-location|update-pickup-location|delete-pickup-location,permission:delete-pickup-location |
| 191 | GET|HEAD | api/v1/attributes | attributes.index | Marvel\Http\Controllers\AttributeController@index | api,auth:sanctum,throttle:admin,lang,permission:view-attributes|create-attribute|update-attribute|delete-attribute,permission:view-attributes |
| 192 | POST | api/v1/attributes | attributes.store | Marvel\Http\Controllers\AttributeController@store | api,auth:sanctum,throttle:admin,lang,permission:view-attributes|create-attribute|update-attribute|delete-attribute,permission:create-attribute |
| 193 | GET|HEAD | api/v1/attributes/{attribute} | attributes.show | Marvel\Http\Controllers\AttributeController@show | api,auth:sanctum,throttle:admin,lang,permission:view-attributes|create-attribute|update-attribute|delete-attribute,permission:view-attributes |
| 194 | PUT|PATCH | api/v1/attributes/{attribute} | attributes.update | Marvel\Http\Controllers\AttributeController@update | api,auth:sanctum,throttle:admin,lang,permission:view-attributes|create-attribute|update-attribute|delete-attribute,permission:update-attribute |
| 195 | DELETE | api/v1/attributes/{attribute} | attributes.destroy | Marvel\Http\Controllers\AttributeController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-attributes|create-attribute|update-attribute|delete-attribute,permission:delete-attribute |
| 196 | GET|HEAD | api/v1/orders | orders.index | Marvel\Http\Controllers\Order\OrderController@index | api,auth:sanctum,throttle:admin,lang,permission:view-orders|view-order,permission:view-orders |
| 197 | GET|HEAD | api/v1/orders/{id} | orders.show | Marvel\Http\Controllers\Order\OrderController@show | api,auth:sanctum,throttle:admin,lang,permission:view-order|view-orders,permission:view-order |
| 198 | PATCH | api/v1/orders/status | orders.update-status-batch | App\Http\Controllers\Api\General\OrderStatusBatchController@update | api,auth:sanctum,throttle:admin,lang,permission:update-order-status |
| 199 | PATCH | api/v1/orders/{id}/status | orders.update-status | Marvel\Http\Controllers\Order\OrderController@updateStatus | api,auth:sanctum,throttle:admin,lang,permission:update-order-status |
| 200 | GET|HEAD | api/v1/orders/{id}/statuses | orders.statuses | App\Http\Controllers\Api\General\OrderStatusOptionsController@index | api,auth:sanctum,throttle:admin,lang |
| 201 | PUT | api/v1/banner/change-status |  | Marvel\Http\Controllers\BannerController@changeStatus | api,auth:sanctum,throttle:admin,lang,permission:update-banners |
| 202 | POST | api/v1/banner/reorder |  | Marvel\Http\Controllers\BannerController@reorder | api,auth:sanctum,throttle:admin,lang,permission:update-banners |
| 203 | GET|HEAD | api/v1/banners | banners.index | Marvel\Http\Controllers\BannerController@index | api,auth:sanctum,throttle:admin,lang,permission:view-banners|create-banners|update-banners|delete-banners,permission:view-banners |
| 204 | POST | api/v1/banners | banners.store | Marvel\Http\Controllers\BannerController@store | api,auth:sanctum,throttle:admin,lang,permission:view-banners|create-banners|update-banners|delete-banners,permission:create-banners |
| 205 | GET|HEAD | api/v1/banners/{banner} | banners.show | Marvel\Http\Controllers\BannerController@show | api,auth:sanctum,throttle:admin,lang,permission:view-banners|create-banners|update-banners|delete-banners,permission:view-banners |
| 206 | PUT|PATCH | api/v1/banners/{banner} | banners.update | Marvel\Http\Controllers\BannerController@update | api,auth:sanctum,throttle:admin,lang,permission:view-banners|create-banners|update-banners|delete-banners,permission:update-banners |
| 207 | DELETE | api/v1/banners/{banner} | banners.destroy | Marvel\Http\Controllers\BannerController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-banners|create-banners|update-banners|delete-banners,permission:delete-banners |
| 208 | GET|HEAD | api/v1/countries | countries.index | Marvel\Http\Controllers\CountryController@index | api,auth:sanctum,throttle:admin,lang,permission:view-country|create-country|update-country|delete-country,permission:view-country |
| 209 | POST | api/v1/countries | countries.store | Marvel\Http\Controllers\CountryController@store | api,auth:sanctum,throttle:admin,lang,permission:view-country|create-country|update-country|delete-country,permission:create-country |
| 210 | GET|HEAD | api/v1/countries/{country} | countries.show | Marvel\Http\Controllers\CountryController@show | api,auth:sanctum,throttle:admin,lang,permission:view-country|create-country|update-country|delete-country,permission:view-country |
| 211 | PUT|PATCH | api/v1/countries/{country} | countries.update | Marvel\Http\Controllers\CountryController@update | api,auth:sanctum,throttle:admin,lang,permission:view-country|create-country|update-country|delete-country,permission:update-country |
| 212 | DELETE | api/v1/countries/{country} | countries.destroy | Marvel\Http\Controllers\CountryController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-country|create-country|update-country|delete-country,permission:delete-country |
| 213 | GET|HEAD | api/v1/countries/{id}/governorates |  | Marvel\Http\Controllers\CountryController@governorates | api,auth:sanctum,throttle:admin,lang,permission:view-country |
| 214 | POST | api/v1/countries/change-status |  | Marvel\Http\Controllers\CountryController@bulkStatus | api,auth:sanctum,throttle:admin,lang,permission:update-country |
| 215 | PUT | api/v1/governorates/change-status |  | Marvel\Http\Controllers\GovernorateController@bulkStatus | api,auth:sanctum,throttle:admin,lang,permission:update-governorate |
| 216 | PUT | api/v1/governorates/{id}/fast-shipping |  | Marvel\Http\Controllers\GovernorateController@toggleFastShipping | api,auth:sanctum,throttle:admin,lang,permission:update-governorate |
| 217 | GET|HEAD | api/v1/governorates/{id}/cities |  | Marvel\Http\Controllers\GovernorateController@cities | api,auth:sanctum,throttle:admin,lang,permission:view-governorate |
| 218 | GET|HEAD | api/v1/governorates | governorates.index | Marvel\Http\Controllers\GovernorateController@index | api,auth:sanctum,throttle:admin,lang,permission:view-governorate|create-governorate|update-governorate|delete-governorate,permission:view-governorate |
| 219 | POST | api/v1/governorates | governorates.store | Marvel\Http\Controllers\GovernorateController@store | api,auth:sanctum,throttle:admin,lang,permission:view-governorate|create-governorate|update-governorate|delete-governorate,permission:create-governorate |
| 220 | GET|HEAD | api/v1/governorates/{governorate} | governorates.show | Marvel\Http\Controllers\GovernorateController@show | api,auth:sanctum,throttle:admin,lang,permission:view-governorate|create-governorate|update-governorate|delete-governorate,permission:view-governorate |
| 221 | PUT|PATCH | api/v1/governorates/{governorate} | governorates.update | Marvel\Http\Controllers\GovernorateController@update | api,auth:sanctum,throttle:admin,lang,permission:view-governorate|create-governorate|update-governorate|delete-governorate,permission:update-governorate |
| 222 | DELETE | api/v1/governorates/{governorate} | governorates.destroy | Marvel\Http\Controllers\GovernorateController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-governorate|create-governorate|update-governorate|delete-governorate,permission:delete-governorate |
| 223 | GET|HEAD | api/v1/cities | cities.index | Marvel\Http\Controllers\CityController@index | api,auth:sanctum,throttle:admin,lang,permission:view-city|create-city|update-city|delete-city,permission:view-city |
| 224 | POST | api/v1/cities | cities.store | Marvel\Http\Controllers\CityController@store | api,auth:sanctum,throttle:admin,lang,permission:view-city|create-city|update-city|delete-city,permission:create-city |
| 225 | GET|HEAD | api/v1/cities/{city} | cities.show | Marvel\Http\Controllers\CityController@show | api,auth:sanctum,throttle:admin,lang,permission:view-city|create-city|update-city|delete-city,permission:view-city |
| 226 | PUT|PATCH | api/v1/cities/{city} | cities.update | Marvel\Http\Controllers\CityController@update | api,auth:sanctum,throttle:admin,lang,permission:view-city|create-city|update-city|delete-city,permission:update-city |
| 227 | DELETE | api/v1/cities/{city} | cities.destroy | Marvel\Http\Controllers\CityController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-city|create-city|update-city|delete-city,permission:delete-city |
| 228 | GET|HEAD | api/v1/shipping-prices | shipping-prices.index | Marvel\Http\Controllers\ShippingPriceController@index | api,auth:sanctum,throttle:admin,lang,permission:manage-shipping-prices |
| 229 | POST | api/v1/shipping-prices | shipping-prices.store | Marvel\Http\Controllers\ShippingPriceController@store | api,auth:sanctum,throttle:admin,lang,permission:manage-shipping-prices |
| 230 | GET|HEAD | api/v1/shipping-prices/{shipping_price} | shipping-prices.show | Marvel\Http\Controllers\ShippingPriceController@show | api,auth:sanctum,throttle:admin,lang,permission:manage-shipping-prices |
| 231 | PUT|PATCH | api/v1/shipping-prices/{shipping_price} | shipping-prices.update | Marvel\Http\Controllers\ShippingPriceController@update | api,auth:sanctum,throttle:admin,lang,permission:manage-shipping-prices |
| 232 | DELETE | api/v1/shipping-prices/{shipping_price} | shipping-prices.destroy | Marvel\Http\Controllers\ShippingPriceController@destroy | api,auth:sanctum,throttle:admin,lang,permission:manage-shipping-prices |
| 233 | PATCH | api/v1/reviews/{id}/toggle-approve |  | Marvel\Http\Controllers\ReviewController@toggleApproveReview | api,auth:sanctum,throttle:admin,lang,permission:approve-reviews |
| 234 | GET|HEAD | api/v1/reviews | reviews.index | Marvel\Http\Controllers\ReviewController@index | api,auth:sanctum,throttle:admin,lang,permission:view-contacts|update-contact|delete-contact|approve-reviews|delete-reviews |
| 235 | GET|HEAD | api/v1/reviews/{review} | reviews.show | Marvel\Http\Controllers\ReviewController@show | api,auth:sanctum,throttle:admin,lang,permission:view-contacts|update-contact|delete-contact|approve-reviews|delete-reviews |
| 236 | DELETE | api/v1/reviews/{review} | reviews.destroy | Marvel\Http\Controllers\ReviewController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-contacts|update-contact|delete-contact|approve-reviews|delete-reviews,permission:delete-reviews |
| 237 | GET|HEAD | api/v1/site-reviews |  | Marvel\Http\Controllers\SiteReviewController@index | api,auth:sanctum,throttle:admin,lang,permission:view-site-reviews |
| 238 | GET|HEAD | api/v1/site-reviews/{id} |  | Marvel\Http\Controllers\SiteReviewController@show | api,auth:sanctum,throttle:admin,lang,permission:view-site-reviews |
| 239 | PATCH | api/v1/site-reviews/{id}/approve |  | Marvel\Http\Controllers\SiteReviewController@approve | api,auth:sanctum,throttle:admin,lang,permission:approve-site-reviews |
| 240 | PATCH | api/v1/site-reviews/{id}/reject |  | Marvel\Http\Controllers\SiteReviewController@reject | api,auth:sanctum,throttle:admin,lang,permission:reject-site-reviews |
| 241 | POST | api/v1/currencies/{id}/set-base |  | Marvel\Http\Controllers\CurrencyController@setBase | api,auth:sanctum,throttle:admin,lang,permission:set-base-currency |
| 242 | POST | api/v1/currencies/{id}/set-catalog |  | Marvel\Http\Controllers\CurrencyController@setCatalog | api,auth:sanctum,throttle:admin,lang,permission:set-catalog-currency |
| 243 | PATCH | api/v1/currencies/{id}/rate-mode |  | Marvel\Http\Controllers\CurrencyController@setRateMode | api,auth:sanctum,throttle:admin,lang,permission:update-currency |
| 244 | GET|HEAD | api/v1/currencies | currencies.index | Marvel\Http\Controllers\CurrencyController@index | api,auth:sanctum,throttle:admin,lang,permission:view-currencies|create-currency|update-currency|delete-currency,permission:view-currencies |
| 245 | POST | api/v1/currencies | currencies.store | Marvel\Http\Controllers\CurrencyController@store | api,auth:sanctum,throttle:admin,lang,permission:view-currencies|create-currency|update-currency|delete-currency,permission:create-currency |
| 246 | GET|HEAD | api/v1/currencies/{currency} | currencies.show | Marvel\Http\Controllers\CurrencyController@show | api,auth:sanctum,throttle:admin,lang,permission:view-currencies|create-currency|update-currency|delete-currency,permission:view-currencies |
| 247 | PUT|PATCH | api/v1/currencies/{currency} | currencies.update | Marvel\Http\Controllers\CurrencyController@update | api,auth:sanctum,throttle:admin,lang,permission:view-currencies|create-currency|update-currency|delete-currency,permission:update-currency |
| 248 | DELETE | api/v1/currencies/{currency} | currencies.destroy | Marvel\Http\Controllers\CurrencyController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-currencies|create-currency|update-currency|delete-currency,permission:delete-currency |
| 249 | GET|HEAD | api/v1/currency-rates | currency-rates.index | Marvel\Http\Controllers\CurrencyRateController@index | api,auth:sanctum,throttle:admin,lang,permission:view-exchange-rates|create-exchange-rate|update-exchange-rate|delete-exchange-rate,permission:view-exchange-rates |
| 250 | POST | api/v1/currency-rates | currency-rates.store | Marvel\Http\Controllers\CurrencyRateController@store | api,auth:sanctum,throttle:admin,lang,permission:view-exchange-rates|create-exchange-rate|update-exchange-rate|delete-exchange-rate,permission:create-exchange-rate |
| 251 | GET|HEAD | api/v1/currency-rates/{currency_rate} | currency-rates.show | Marvel\Http\Controllers\CurrencyRateController@show | api,auth:sanctum,throttle:admin,lang,permission:view-exchange-rates|create-exchange-rate|update-exchange-rate|delete-exchange-rate,permission:view-exchange-rates |
| 252 | PUT|PATCH | api/v1/currency-rates/{currency_rate} | currency-rates.update | Marvel\Http\Controllers\CurrencyRateController@update | api,auth:sanctum,throttle:admin,lang,permission:view-exchange-rates|create-exchange-rate|update-exchange-rate|delete-exchange-rate,permission:update-exchange-rate |
| 253 | DELETE | api/v1/currency-rates/{currency_rate} | currency-rates.destroy | Marvel\Http\Controllers\CurrencyRateController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-exchange-rates|create-exchange-rate|update-exchange-rate|delete-exchange-rate,permission:delete-exchange-rate |
| 254 | POST | api/v1/products/bulk-delete |  | Marvel\Http\Controllers\ProductController@destroyBulk | api,auth:sanctum,throttle:admin,lang,permission:delete-all-products|delete-product,permission:delete-product |
| 255 | DELETE | api/v1/products/all |  | Marvel\Http\Controllers\ProductController@destroyAll | api,auth:sanctum,throttle:admin,lang,permission:delete-all-products |
| 256 | GET|HEAD | api/v1/products/import/sample | admin.products.import.sample | Marvel\Http\Controllers\ProductImportController@downloadSample | api,auth:sanctum,throttle:admin,lang,permission:import-product |
| 257 | GET|HEAD | api/v1/products/export | admin.products.export | Marvel\Http\Controllers\ProductExportController@export | api,auth:sanctum,throttle:admin,lang,permission:export-product |
| 258 | POST | api/v1/products/export | admin.products.export.post | Marvel\Http\Controllers\ProductExportController@export | api,auth:sanctum,throttle:admin,lang,permission:export-product |
| 259 | GET|HEAD | api/v1/products/export/{id} | admin.products.export.status | Marvel\Http\Controllers\ProductExportController@status | api,auth:sanctum,throttle:admin,lang,permission:export-product |
| 260 | GET|HEAD | api/v1/products/export/{id}/download | admin.products.export.download | Marvel\Http\Controllers\ProductExportController@download | api,auth:sanctum,throttle:admin,lang,permission:export-product |
| 261 | POST | api/v1/products/export/{id}/cancel | admin.products.export.cancel | Marvel\Http\Controllers\ProductExportController@cancel | api,auth:sanctum,throttle:admin,lang,permission:export-product |
| 262 | POST | api/v1/products/import | admin.products.import | Marvel\Http\Controllers\ProductImportController@import | api,auth:sanctum,throttle:admin,lang,permission:import-product |
| 263 | GET|HEAD | api/v1/products/import/{id} | admin.products.import.status | Marvel\Http\Controllers\ProductImportController@status | api,auth:sanctum,throttle:admin,lang,permission:import-product |
| 264 | POST | api/v1/products/import/{id}/cancel | admin.products.import.cancel | Marvel\Http\Controllers\ProductImportController@cancel | api,auth:sanctum,throttle:admin,lang,permission:import-product |
| 265 | GET|HEAD | api/v1/products/import/{id}/download-errors | admin.products.import.download-errors | Marvel\Http\Controllers\ProductImportController@downloadErrors | api,auth:sanctum,throttle:admin,lang,permission:import-product |
| 266 | GET|HEAD | api/v1/products | products.index | Marvel\Http\Controllers\ProductController@index | api,auth:sanctum,throttle:admin,lang,permission:view-products|view-product|create-product|update-product|delete-product,permission:view-products |
| 267 | POST | api/v1/products | products.store | Marvel\Http\Controllers\ProductController@store | api,auth:sanctum,throttle:admin,lang,permission:view-products|view-product|create-product|update-product|delete-product,permission:create-product |
| 268 | GET|HEAD | api/v1/products/{product} | products.show | Marvel\Http\Controllers\ProductController@show | api,auth:sanctum,throttle:admin,lang,permission:view-products|view-product|create-product|update-product|delete-product,permission:view-products |
| 269 | PUT|PATCH | api/v1/products/{product} | products.update | Marvel\Http\Controllers\ProductController@update | api,auth:sanctum,throttle:admin,lang,permission:view-products|view-product|create-product|update-product|delete-product,permission:update-product |
| 270 | DELETE | api/v1/products/{product} | products.destroy | Marvel\Http\Controllers\ProductController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-products|view-product|create-product|update-product|delete-product,permission:delete-product |
| 271 | GET|HEAD | api/v1/products/{product}/digital-assets |  | Marvel\Http\Controllers\DigitalAssetController@index | api,auth:sanctum,throttle:admin,lang,permission:view-products |
| 272 | POST | api/v1/products/{product}/digital-assets | admin.products.digital-assets.store | Marvel\Http\Controllers\DigitalAssetController@store | api,auth:sanctum,throttle:admin,lang,permission:create-product |
| 273 | GET|HEAD | api/v1/digital-assets/{uuid} | admin.digital-assets.show | Marvel\Http\Controllers\DigitalAssetController@show | api,auth:sanctum,throttle:admin,lang,permission:view-products |
| 274 | PUT | api/v1/digital-assets/{uuid} | admin.digital-assets.update | Marvel\Http\Controllers\DigitalAssetController@update | api,auth:sanctum,throttle:admin,lang,permission:update-product |
| 275 | DELETE | api/v1/digital-assets/{uuid} | admin.digital-assets.destroy | Marvel\Http\Controllers\DigitalAssetController@destroy | api,auth:sanctum,throttle:admin,lang,permission:delete-product |
| 276 | POST | api/v1/digital-assets/{uuid}/replace | admin.digital-assets.replace | Marvel\Http\Controllers\DigitalAssetController@replace | api,auth:sanctum,throttle:admin,lang,permission:update-product |
| 277 | POST | api/v1/digital-assets/{uuid}/license-keys | admin.digital-assets.license-keys.store | Marvel\Http\Controllers\DigitalAssetController@storeLicenseKeys | api,auth:sanctum,throttle:admin,lang,permission:manage-digital-licenses |
| 278 | GET|HEAD | api/v1/digital-entitlements | admin.digital-entitlements.index | Marvel\Http\Controllers\DigitalEntitlementController@index | api,auth:sanctum,throttle:admin,lang,permission:manage-digital-access,permission:view-orders |
| 279 | GET|HEAD | api/v1/digital-entitlements/{uuid} | admin.digital-entitlements.show | Marvel\Http\Controllers\DigitalEntitlementController@show | api,auth:sanctum,throttle:admin,lang,permission:manage-digital-access,permission:view-orders |
| 280 | PATCH | api/v1/digital-entitlements/{uuid}/limit | admin.digital-entitlements.limit | Marvel\Http\Controllers\DigitalEntitlementController@setLimit | api,auth:sanctum,throttle:admin,lang,permission:manage-digital-access |
| 281 | POST | api/v1/digital-entitlements/{uuid}/revoke | admin.digital-entitlements.revoke | Marvel\Http\Controllers\DigitalEntitlementController@revoke | api,auth:sanctum,throttle:admin,lang,permission:manage-digital-access |
| 282 | POST | api/v1/digital-entitlements/{uuid}/restore | admin.digital-entitlements.restore | Marvel\Http\Controllers\DigitalEntitlementController@restore | api,auth:sanctum,throttle:admin,lang,permission:manage-digital-access |
| 283 | PUT | api/v1/flash-sale/reorder |  | Marvel\Http\Controllers\FlashSaleController@reorder | api,auth:sanctum,throttle:admin,lang,permission:update-flash-sale |
| 284 | GET|HEAD | api/v1/flash-sale | flash-sale.index | Marvel\Http\Controllers\FlashSaleController@index | api,auth:sanctum,throttle:admin,lang,permission:view-flash-sale|create-flash-sale|update-flash-sale|delete-flash-sale,permission:view-flash-sale |
| 285 | POST | api/v1/flash-sale | flash-sale.store | Marvel\Http\Controllers\FlashSaleController@store | api,auth:sanctum,throttle:admin,lang,permission:view-flash-sale|create-flash-sale|update-flash-sale|delete-flash-sale,permission:create-flash-sale |
| 286 | GET|HEAD | api/v1/flash-sale/{flash_sale} | flash-sale.show | Marvel\Http\Controllers\FlashSaleController@show | api,auth:sanctum,throttle:admin,lang,permission:view-flash-sale|create-flash-sale|update-flash-sale|delete-flash-sale,permission:view-flash-sale |
| 287 | PUT|PATCH | api/v1/flash-sale/{flash_sale} | flash-sale.update | Marvel\Http\Controllers\FlashSaleController@update | api,auth:sanctum,throttle:admin,lang,permission:view-flash-sale|create-flash-sale|update-flash-sale|delete-flash-sale,permission:update-flash-sale |
| 288 | DELETE | api/v1/flash-sale/{flash_sale} | flash-sale.destroy | Marvel\Http\Controllers\FlashSaleController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-flash-sale|create-flash-sale|update-flash-sale|delete-flash-sale,permission:delete-flash-sale |
| 289 | GET|HEAD | api/v1/product-flash-sale-info |  | Marvel\Http\Controllers\FlashSaleController@getFlashSaleInfoByProductID | api,auth:sanctum,throttle:admin,lang,permission:view-flash-sale|view-products |
| 290 | PUT | api/v1/faqs/reorder |  | Marvel\Http\Controllers\FaqsController@reorder | api,auth:sanctum,throttle:admin,lang,permission:update-faq |
| 291 | GET|HEAD | api/v1/faqs | faqs.index | Marvel\Http\Controllers\FaqsController@index | api,auth:sanctum,throttle:admin,lang,permission:view-faqs|create-faq|update-faq|delete-faq,permission:view-faqs |
| 292 | POST | api/v1/faqs | faqs.store | Marvel\Http\Controllers\FaqsController@store | api,auth:sanctum,throttle:admin,lang,permission:view-faqs|create-faq|update-faq|delete-faq,permission:create-faq |
| 293 | GET|HEAD | api/v1/faqs/{faq} | faqs.show | Marvel\Http\Controllers\FaqsController@show | api,auth:sanctum,throttle:admin,lang,permission:view-faqs|create-faq|update-faq|delete-faq,permission:view-faqs |
| 294 | PUT|PATCH | api/v1/faqs/{faq} | faqs.update | Marvel\Http\Controllers\FaqsController@update | api,auth:sanctum,throttle:admin,lang,permission:view-faqs|create-faq|update-faq|delete-faq,permission:update-faq |
| 295 | DELETE | api/v1/faqs/{faq} | faqs.destroy | Marvel\Http\Controllers\FaqsController@destroy | api,auth:sanctum,throttle:admin,lang,permission:view-faqs|create-faq|update-faq|delete-faq,permission:delete-faq |
| 296 | GET|HEAD | api/v1/coupons/{coupon}/assignments |  | Marvel\Http\Controllers\CouponAssignmentController@index | api,auth:sanctum,throttle:admin,lang,permission:view-coupon-assignments |
| 297 | POST | api/v1/coupons/{coupon}/assignments |  | Marvel\Http\Controllers\CouponAssignmentController@store | api,auth:sanctum,throttle:admin,lang,permission:create-coupon-assignment |
| 298 | GET|HEAD | api/v1/coupons/{coupon}/assignments/{assignment} |  | Marvel\Http\Controllers\CouponAssignmentController@show | api,auth:sanctum,throttle:admin,lang,permission:view-coupon-assignments |
| 299 | PUT | api/v1/coupons/{coupon}/assignments/{assignment} |  | Marvel\Http\Controllers\CouponAssignmentController@update | api,auth:sanctum,throttle:admin,lang,permission:update-coupon-assignment |
| 300 | DELETE | api/v1/coupons/{coupon}/assignments/{assignment} |  | Marvel\Http\Controllers\CouponAssignmentController@destroy | api,auth:sanctum,throttle:admin,lang,permission:delete-coupon-assignment |
| 301 | GET|HEAD | api/v1/coupons/rules | api.admin.coupons.rules | App\Http\Controllers\Api\Admin\CouponRulesController@show | api,auth:sanctum,throttle:admin,lang |
| 302 | GET|HEAD | api/v1/coupons | coupons.index | Marvel\Http\Controllers\CouponController@index | api,auth:sanctum,throttle:admin,lang,permission:view-coupons |
| 303 | POST | api/v1/coupons | coupons.store | Marvel\Http\Controllers\CouponController@store | api,auth:sanctum,throttle:admin,lang,permission:create-coupon |
| 304 | GET|HEAD | api/v1/coupons/{coupon} | coupons.show | Marvel\Http\Controllers\CouponController@show | api,auth:sanctum,throttle:admin,lang,permission:view-coupons |
| 305 | PUT|PATCH | api/v1/coupons/{coupon} | coupons.update | Marvel\Http\Controllers\CouponController@update | api,auth:sanctum,throttle:admin,lang,permission:update-coupon |
| 306 | DELETE | api/v1/coupons/{coupon} | coupons.destroy | Marvel\Http\Controllers\CouponController@destroy | api,auth:sanctum,throttle:admin,lang,permission:delete-coupon |
| 307 | POST | api/v1/coupons/validate-configuration | api.admin.coupons.validate-config | App\Http\Controllers\Api\Admin\CouponConfigurationController@validateConfiguration | api,auth:sanctum,throttle:admin,lang,permission:view-coupons|update-coupon|create-coupon |
| 308 | GET|HEAD | api/v1/coupons/{id}/usage-info | api.admin.coupons.usage-info | App\Http\Controllers\Api\Admin\CouponConfigurationController@getUsageInfo | api,auth:sanctum,throttle:admin,lang,permission:view-coupons|update-coupon|create-coupon |
| 309 | POST | api/v1/coupons/{id}/suggest-fix | api.admin.coupons.suggest-fix | App\Http\Controllers\Api\Admin\CouponConfigurationController@suggestFix | api,auth:sanctum,throttle:admin,lang,permission:view-coupons|update-coupon|create-coupon |
| 310 | GET|HEAD | api/v1/coupons/{id}/targeting | api.admin.coupons.targeting.show | App\Http\Controllers\Api\Admin\CouponTargetingController@show | api,auth:sanctum,throttle:admin,lang,permission:view-coupons|update-coupon|create-coupon |
| 311 | PUT | api/v1/coupons/{id}/targeting | api.admin.coupons.targeting.upsert | App\Http\Controllers\Api\Admin\CouponTargetingController@upsert | api,auth:sanctum,throttle:admin,lang,permission:update-coupon|create-coupon |
| 312 | DELETE | api/v1/coupons/{id}/targeting | api.admin.coupons.targeting.destroy | App\Http\Controllers\Api\Admin\CouponTargetingController@destroy | api,auth:sanctum,throttle:admin,lang,permission:update-coupon|create-coupon |
| 313 | POST | api/v1/coupons/{id}/distribute |  | App\Http\Controllers\Api\Admin\CouponDistributionAdminController@distribute | api,auth:sanctum,throttle:admin,lang,permission:update-coupon |
| 314 | GET|HEAD | api/v1/coupons/{id}/distributions |  | App\Http\Controllers\Api\Admin\CouponDistributionAdminController@index | api,auth:sanctum,throttle:admin,lang,permission:update-coupon |
| 315 | GET|HEAD | api/v1/coupons/{id}/distributions/{runId} |  | App\Http\Controllers\Api\Admin\CouponDistributionAdminController@show | api,auth:sanctum,throttle:admin,lang,permission:update-coupon |
| 316 | PATCH | api/v1/wishlists/toggle |  | Marvel\Http\Controllers\WishlistController@toggle | api,auth:sanctum,throttle:admin,lang |
| 317 | GET|HEAD | api/v1/wishlists | wishlists.index | Marvel\Http\Controllers\WishlistController@index | api,auth:sanctum,throttle:admin,lang |
| 318 | POST | api/v1/wishlists | wishlists.store | Marvel\Http\Controllers\WishlistController@store | api,auth:sanctum,throttle:admin,lang |
| 319 | GET|HEAD | api/v1/wishlists/in_wishlist/{product_id} |  | Marvel\Http\Controllers\WishlistController@in_wishlist | api,auth:sanctum,throttle:admin,lang |
| 320 | GET|HEAD | api/v1/promotions | promotions.index | Marvel\Http\Controllers\PromotionController@index | api,auth:sanctum,throttle:admin,lang,permission:view-promotion |
| 321 | POST | api/v1/promotions | promotions.store | Marvel\Http\Controllers\PromotionController@store | api,auth:sanctum,throttle:admin,lang,permission:create-promotion |
| 322 | GET|HEAD | api/v1/promotions/{promotion} | promotions.show | Marvel\Http\Controllers\PromotionController@show | api,auth:sanctum,throttle:admin,lang,permission:view-promotion |
| 323 | PUT|PATCH | api/v1/promotions/{promotion} | promotions.update | Marvel\Http\Controllers\PromotionController@update | api,auth:sanctum,throttle:admin,lang,permission:update-promotion |
| 324 | DELETE | api/v1/promotions/{promotion} | promotions.destroy | Marvel\Http\Controllers\PromotionController@destroy | api,auth:sanctum,throttle:admin,lang,permission:delete-promotion |
| 325 | GET|HEAD | api/v1/tags | tags.index | Marvel\Http\Controllers\TagController@index | api,auth:sanctum,throttle:admin,lang,permission:view-tags |
| 326 | POST | api/v1/tags | tags.store | Marvel\Http\Controllers\TagController@store | api,auth:sanctum,throttle:admin,lang,permission:create-tags |
| 327 | GET|HEAD | api/v1/tags/{tag} | tags.show | Marvel\Http\Controllers\TagController@show | api,auth:sanctum,throttle:admin,lang,permission:view-tags |
| 328 | PUT|PATCH | api/v1/tags/{tag} | tags.update | Marvel\Http\Controllers\TagController@update | api,auth:sanctum,throttle:admin,lang,permission:update-tags |
| 329 | DELETE | api/v1/tags/{tag} | tags.destroy | Marvel\Http\Controllers\TagController@destroy | api,auth:sanctum,throttle:admin,lang,permission:delete-tags |
| 330 | GET|HEAD | api/v1/roles |  | Marvel\Http\Controllers\RoleAndPermissionController@getAllRoles | api,auth:sanctum,throttle:admin,lang,permission:view-roles |
| 331 | GET|HEAD | api/v1/roles/{id} |  | Marvel\Http\Controllers\RoleAndPermissionController@showRole | api,auth:sanctum,throttle:admin,lang,permission:view-role |
| 332 | POST | api/v1/roles |  | Marvel\Http\Controllers\RoleAndPermissionController@addRole | api,auth:sanctum,throttle:admin,lang,permission:create-roles |
| 333 | PUT | api/v1/roles/{id} |  | Marvel\Http\Controllers\RoleAndPermissionController@updateRole | api,auth:sanctum,throttle:admin,lang,permission:update-roles |
| 334 | DELETE | api/v1/roles/{id} |  | Marvel\Http\Controllers\RoleAndPermissionController@destroyRole | api,auth:sanctum,throttle:admin,lang,permission:delete-roles |
| 335 | PATCH | api/v1/users/{userId}/assign-role |  | Marvel\Http\Controllers\RoleAndPermissionController@assignRole | api,auth:sanctum,throttle:admin,lang,permission:assign-role |
| 336 | PATCH | api/v1/users/{userId}/remove-role |  | Marvel\Http\Controllers\RoleAndPermissionController@removeRoleFromUser | api,auth:sanctum,throttle:admin,lang,permission:remove-role |
| 337 | GET|HEAD | api/v1/permissions |  | Marvel\Http\Controllers\RoleAndPermissionController@getAllPermissions | api,auth:sanctum,throttle:admin,lang,role:super_admin |
| 338 | POST | api/v1/roles/{roleId}/permissions |  | Marvel\Http\Controllers\RoleAndPermissionController@assignPermissionToRole | api,auth:sanctum,throttle:admin,lang,role:super_admin |
| 339 | POST | api/v1/users/{userId}/permissions |  | Marvel\Http\Controllers\RoleAndPermissionController@givePermission | api,auth:sanctum,throttle:admin,lang,role:super_admin |
| 340 | PUT | api/v1/users/{userId}/permissions |  | Marvel\Http\Controllers\RoleAndPermissionController@syncPermissions | api,auth:sanctum,throttle:admin,lang,role:super_admin |
| 341 | DELETE | api/v1/users/{userId}/permissions |  | Marvel\Http\Controllers\RoleAndPermissionController@removePermission | api,auth:sanctum,throttle:admin,lang,role:super_admin |
| 342 | POST | api/v1/content-pages/{content_page}/attach-sections |  | Marvel\Http\Controllers\ContentPageController@attachSections | api,auth:sanctum,throttle:admin,lang,permission:update-content-pages |
| 343 | PATCH | api/v1/content-pages/{content_page}/toggle-active |  | Marvel\Http\Controllers\ContentPageController@toggleActive | api,auth:sanctum,throttle:admin,lang,permission:update-content-pages |
| 344 | GET|HEAD | api/v1/content-pages | content-pages.index | Marvel\Http\Controllers\ContentPageController@index | api,auth:sanctum,throttle:admin,lang,permission:view-content-pages |
| 345 | POST | api/v1/content-pages | content-pages.store | Marvel\Http\Controllers\ContentPageController@store | api,auth:sanctum,throttle:admin,lang,permission:create-content-pages |
| 346 | GET|HEAD | api/v1/content-pages/{content_page} | content-pages.show | Marvel\Http\Controllers\ContentPageController@show | api,auth:sanctum,throttle:admin,lang,permission:view-content-pages |
| 347 | PUT|PATCH | api/v1/content-pages/{content_page} | content-pages.update | Marvel\Http\Controllers\ContentPageController@update | api,auth:sanctum,throttle:admin,lang,permission:update-content-pages |
| 348 | DELETE | api/v1/content-pages/{content_page} | content-pages.destroy | Marvel\Http\Controllers\ContentPageController@destroy | api,auth:sanctum,throttle:admin,lang,permission:delete-content-pages |
| 349 | PUT | api/v1/sections/reorder |  | Marvel\Http\Controllers\SectionController@reorder | api,auth:sanctum,throttle:admin,lang,permission:update-sections |
| 350 | GET|HEAD | api/v1/sections/types |  | Marvel\Http\Controllers\SectionController@getTypeSection | api,auth:sanctum,throttle:admin,lang,permission:view-sections |
| 351 | PATCH | api/v1/sections/{section}/toggle-active |  | Marvel\Http\Controllers\SectionController@toggleStatus | api,auth:sanctum,throttle:admin,lang,permission:update-sections |
| 352 | GET|HEAD | api/v1/sections | sections.index | Marvel\Http\Controllers\SectionController@index | api,auth:sanctum,throttle:admin,lang,permission:view-sections |
| 353 | POST | api/v1/sections | sections.store | Marvel\Http\Controllers\SectionController@store | api,auth:sanctum,throttle:admin,lang,permission:create-sections |
| 354 | GET|HEAD | api/v1/sections/{section} | sections.show | Marvel\Http\Controllers\SectionController@show | api,auth:sanctum,throttle:admin,lang,permission:view-sections |
| 355 | PUT|PATCH | api/v1/sections/{section} | sections.update | Marvel\Http\Controllers\SectionController@update | api,auth:sanctum,throttle:admin,lang,permission:update-sections |
| 356 | DELETE | api/v1/sections/{section} | sections.destroy | Marvel\Http\Controllers\SectionController@destroy | api,auth:sanctum,throttle:admin,lang,permission:delete-sections |
| 357 | GET|HEAD | api/v1/section-types | section-types.index | Marvel\Http\Controllers\SectionTypeController@index | api,auth:sanctum,throttle:admin,lang,permission:view-section-types |
| 358 | GET|HEAD | api/v1/section-types/{section_type} | section-types.show | Marvel\Http\Controllers\SectionTypeController@show | api,auth:sanctum,throttle:admin,lang,permission:view-section-types |
| 359 | POST | api/v1/section-types/{type}/settings |  | Marvel\Http\Controllers\SectionTypeController@updateSettings | api,auth:sanctum,throttle:admin,lang,permission:update-section-types |
| 360 | GET|HEAD | api/v1/section-types/{type}/settings |  | Marvel\Http\Controllers\SectionTypeController@settings | api,auth:sanctum,throttle:admin,lang,permission:view-section-types |
| 361 | GET|HEAD | api/v1/static-pages |  | Marvel\Http\Controllers\StaticPageController@index | api,auth:sanctum,throttle:admin,lang,permission:view-static-pages |
| 362 | GET|HEAD | api/v1/static-pages/{static_page} |  | Marvel\Http\Controllers\StaticPageController@show | api,auth:sanctum,throttle:admin,lang,permission:view-static-pages |
| 363 | PUT | api/v1/static-pages/{static_page} |  | Marvel\Http\Controllers\StaticPageController@update | api,auth:sanctum,throttle:admin,lang,permission:update-static-pages |
| 364 | POST | api/v1/static-pages/{static_page}/sections |  | Marvel\Http\Controllers\StaticPageController@storeSection | api,auth:sanctum,throttle:admin,lang,permission:create-static-sections |
| 365 | PATCH | api/v1/static-pages/{static_page}/sections/reorder |  | Marvel\Http\Controllers\StaticPageController@reorderSections | api,auth:sanctum,throttle:admin,lang,permission:update-static-sections |
| 366 | PUT | api/v1/static-pages/{static_page}/sections/{static_section} |  | Marvel\Http\Controllers\StaticPageController@updateSection | api,auth:sanctum,throttle:admin,lang,permission:update-static-sections |
| 367 | DELETE | api/v1/static-pages/{static_page}/sections/{static_section} |  | Marvel\Http\Controllers\StaticPageController@destroySection | api,auth:sanctum,throttle:admin,lang,permission:delete-static-sections |
| 368 | PATCH | api/v1/users/block-user |  | Marvel\Http\Controllers\UserController@banUser | api,auth:sanctum,throttle:admin,lang,permission:ban-user |
| 369 | PATCH | api/v1/users/unblock-user |  | Marvel\Http\Controllers\UserController@activeUser | api,auth:sanctum,throttle:admin,lang,permission:activate-user |
| 370 | GET|HEAD | api/v1/users | users.index | Marvel\Http\Controllers\UserController@index | api,auth:sanctum,throttle:admin,lang,permission:view-users |
| 371 | GET|HEAD | api/v1/users/{user} | users.show | Marvel\Http\Controllers\UserController@show | api,auth:sanctum,throttle:admin,lang,permission:view-users |
| 372 | PUT|PATCH | api/v1/users/{user} | users.update | Marvel\Http\Controllers\UserController@update | api,auth:sanctum,throttle:admin,lang,permission:edit-user |
| 373 | GET|HEAD | api/v1/product-type |  | Closure | api,auth:sanctum,throttle:admin,lang |
| 374 | GET|HEAD | api/v1/cart |  | Marvel\Http\Controllers\CartController@index | api,auth:sanctum,throttle:cart,lang |
| 375 | POST | api/v1/cart |  | Marvel\Http\Controllers\CartController@store | api,auth:sanctum,throttle:cart,lang |
| 376 | GET|HEAD | api/v1/cart/{id} |  | Marvel\Http\Controllers\CartController@show | api,auth:sanctum,throttle:cart,lang |
| 377 | POST | api/v1/cart/bulk-items |  | Marvel\Http\Controllers\CartController@pluckItemsToCart | api,auth:sanctum,throttle:cart,lang |
| 378 | PUT | api/v1/cart/update-item |  | Marvel\Http\Controllers\CartController@update | api,auth:sanctum,throttle:cart,lang |
| 379 | DELETE | api/v1/cart/delete-item/{itemId} |  | Marvel\Http\Controllers\CartController@deleteItemFromCart | api,auth:sanctum,throttle:cart,lang |
| 380 | DELETE | api/v1/cart/delete-items |  | Marvel\Http\Controllers\CartController@destroy | api,auth:sanctum,throttle:cart,lang |
| 381 | GET|HEAD | api/v1/dashboard/overview |  | App\Http\Controllers\Api\General\DashboardController@overview | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 382 | GET|HEAD | api/v1/dashboard/revenue |  | App\Http\Controllers\Api\General\DashboardController@revenue | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 383 | GET|HEAD | api/v1/dashboard/order-stats |  | App\Http\Controllers\Api\General\DashboardController@orderStats | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 384 | GET|HEAD | api/v1/dashboard/recent-orders |  | App\Http\Controllers\Api\General\DashboardController@recentOrders | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 385 | GET|HEAD | api/v1/dashboard/top-products |  | App\Http\Controllers\Api\General\DashboardController@topProducts | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 386 | GET|HEAD | api/v1/dashboard/category-stats |  | App\Http\Controllers\Api\General\DashboardController@categoryStats | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 387 | GET|HEAD | api/v1/dashboard/low-stock |  | App\Http\Controllers\Api\General\DashboardController@lowStock | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 388 | GET|HEAD | api/v1/dashboard/sales |  | App\Http\Controllers\Api\General\DashboardController@salesAnalytics | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 389 | GET|HEAD | api/v1/dashboard/customers |  | App\Http\Controllers\Api\General\DashboardController@customerAnalytics | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 390 | GET|HEAD | api/v1/dashboard/products |  | App\Http\Controllers\Api\General\DashboardController@productAnalytics | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 391 | GET|HEAD | api/v1/dashboard/orders |  | App\Http\Controllers\Api\General\DashboardController@orderAnalytics | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 392 | GET|HEAD | api/v1/dashboard/categories |  | App\Http\Controllers\Api\General\DashboardController@categoryAnalytics | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 393 | GET|HEAD | api/v1/dashboard/coupons |  | App\Http\Controllers\Api\General\DashboardController@couponAnalytics | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 394 | GET|HEAD | api/v1/dashboard/cart |  | App\Http\Controllers\Api\General\DashboardController@cartAnalytics | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 395 | GET|HEAD | api/v1/dashboard/finance |  | App\Http\Controllers\Api\General\DashboardController@financeAnalytics | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 396 | GET|HEAD | api/v1/dashboard/reconciliation |  | App\Http\Controllers\Api\General\DashboardController@reconciliation | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 397 | GET|HEAD | api/v1/dashboard/time-series | api.admin.analytics.time-series | App\Http\Controllers\Api\Admin\AnalyticsController@timeSeries | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 398 | GET|HEAD | api/v1/dashboard/top-customers | api.admin.analytics.top-customers | App\Http\Controllers\Api\Admin\AnalyticsController@topCustomers | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 399 | GET|HEAD | api/v1/dashboard/customer-segmentation | api.admin.analytics.segmentation | App\Http\Controllers\Api\Admin\AnalyticsController@customerSegmentation | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 400 | GET|HEAD | api/v1/dashboard/performance | api.admin.analytics.performance | App\Http\Controllers\Api\Admin\AnalyticsController@performance | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 401 | POST | api/v1/dashboard/clear-cache | api.admin.analytics.clear-cache | App\Http\Controllers\Api\Admin\AnalyticsController@clearCache | api,auth:sanctum,throttle:analytics,permission:view-analytics,lang |
| 402 | GET|HEAD | api/v1/admin/notifications |  | Marvel\Http\Controllers\NotificationController@index | api,auth:sanctum,permission:view-notifications,lang |
| 403 | GET|HEAD | api/v1/admin/notifications/unread |  | Marvel\Http\Controllers\NotificationController@unread | api,auth:sanctum,permission:view-notifications,lang |
| 404 | PATCH | api/v1/admin/notifications/{id}/read |  | Marvel\Http\Controllers\NotificationController@markAsRead | api,auth:sanctum,permission:manage-notifications,lang |
| 405 | PATCH | api/v1/admin/notifications/read-all |  | Marvel\Http\Controllers\NotificationController@markAllAsRead | api,auth:sanctum,permission:manage-notifications,lang |
| 406 | DELETE | api/v1/admin/notifications/{id} |  | Marvel\Http\Controllers\NotificationController@destroy | api,auth:sanctum,permission:manage-notifications,lang |
| 407 | DELETE | api/v1/admin/notifications |  | Marvel\Http\Controllers\NotificationController@destroyAll | api,auth:sanctum,permission:manage-notifications,lang |
| 408 | GET|HEAD | api/v1/notifications |  | Marvel\Http\Controllers\NotificationController@index | api,auth:sanctum,lang |
| 409 | GET|HEAD | api/v1/notifications/unread |  | Marvel\Http\Controllers\NotificationController@unread | api,auth:sanctum,lang |
| 410 | GET|HEAD | api/v1/notifications/{id} |  | Marvel\Http\Controllers\NotificationController@show | api,auth:sanctum,lang |
| 411 | PATCH | api/v1/notifications/{id}/read |  | Marvel\Http\Controllers\NotificationController@markAsRead | api,auth:sanctum,lang |
| 412 | POST | api/v1/notifications/read-all |  | Marvel\Http\Controllers\NotificationController@markAllAsRead | api,auth:sanctum,lang |
| 413 | DELETE | api/v1/notifications/{id} |  | Marvel\Http\Controllers\NotificationController@destroy | api,auth:sanctum,lang |
| 414 | GET|HEAD | api/v1/refunds | refunds.index | Marvel\Http\Controllers\RefundController@index | api,auth:sanctum,throttle:refunds,lang |
| 415 | POST | api/v1/refunds | refunds.store | Marvel\Http\Controllers\RefundController@store | api,auth:sanctum,throttle:refunds,lang |
| 416 | GET|HEAD | api/v1/refunds/{refund} | refunds.show | Marvel\Http\Controllers\RefundController@show | api,auth:sanctum,throttle:refunds,lang |
| 417 | PUT|PATCH | api/v1/refunds/{refund} | refunds.update | Marvel\Http\Controllers\RefundController@update | api,auth:sanctum,throttle:refunds,lang |
| 418 | DELETE | api/v1/refunds/{refund} | refunds.destroy | Marvel\Http\Controllers\RefundController@destroy | api,auth:sanctum,throttle:refunds,lang |
| 419 | GET|HEAD | api/v1/shipments |  | App\Http\Controllers\Api\ShipmentController@index | api,auth:sanctum,lang,permission:view-shipments |
| 420 | GET|HEAD | api/v1/shipments/uuid/{uuid} |  | App\Http\Controllers\Api\ShipmentController@showByUuid | api,auth:sanctum,lang,permission:view-shipment |
| 421 | GET|HEAD | api/v1/shipments/{id} |  | App\Http\Controllers\Api\ShipmentController@show | api,auth:sanctum,lang,permission:view-shipment |
| 422 | POST | api/v1/shipments |  | App\Http\Controllers\Api\ShipmentController@store | api,auth:sanctum,lang,permission:create-shipment |
| 423 | PUT | api/v1/shipments/{id}/status |  | App\Http\Controllers\Api\ShipmentController@updateStatus | api,auth:sanctum,lang,permission:update-shipment |
| 424 | PUT | api/v1/shipments/{id} |  | App\Http\Controllers\Api\ShipmentController@update | api,auth:sanctum,lang,permission:update-shipment |
| 425 | GET|HEAD | api/v1/invoices |  | App\Http\Controllers\Api\InvoiceController@index | api,auth:sanctum,lang,permission:view-invoices |
| 426 | GET|HEAD | api/v1/invoices/{uuid}/download |  | App\Http\Controllers\Api\InvoiceController@download | api,auth:sanctum,lang,throttle:30,1 |
| 427 | GET|HEAD | api/v1/invoices/{uuid}/view |  | App\Http\Controllers\Api\InvoiceController@view | api,auth:sanctum,lang,throttle:30,1 |
| 428 | GET|HEAD | api/v1/invoices/{id} |  | App\Http\Controllers\Api\InvoiceController@show | api,auth:sanctum,lang,permission:view-invoice |
| 429 | POST | api/v1/invoices/{id}/regenerate |  | App\Http\Controllers\Api\InvoiceController@regenerate | api,auth:sanctum,lang,permission:regenerate-invoice |
| 430 | POST | api/v1/invoices/{id}/correct |  | App\Http\Controllers\Api\InvoiceController@correct | api,auth:sanctum,lang,permission:correct-invoice |
| 431 | POST | api/v1/invoices/{id}/cancel |  | App\Http\Controllers\Api\InvoiceController@cancel | api,auth:sanctum,lang,permission:cancel-invoice |
| 432 | POST | api/v1/invoices/{id}/debit-note |  | App\Http\Controllers\Api\InvoiceController@issueDebitNote | api,auth:sanctum,lang,permission:issue-debit-note |
| 433 | GET|HEAD | api/v1/invoices/verify/{uuid} |  | App\Http\Controllers\Api\InvoiceController@verify | api,auth:sanctum,lang,throttle:5,1 |
| 434 | GET|HEAD | api/v1/invoices/uuid/{uuid} |  | App\Http\Controllers\Api\InvoiceController@showByUuid | api,auth:sanctum,lang,permission:view-invoice |
| 435 | POST | api/v1/admin/analytics/export/orders | api.admin.analytics.export.orders | App\Http\Controllers\Api\Admin\AnalyticsExportController@exportOrders | api,auth:sanctum,throttle:admin,permission:export-analytics |
| 436 | POST | api/v1/admin/analytics/export/customer-ltv | api.admin.analytics.export.ltv | App\Http\Controllers\Api\Admin\AnalyticsExportController@exportCustomerLTV | api,auth:sanctum,throttle:admin,permission:export-analytics |
| 437 | POST | api/v1/admin/analytics/export/performance | api.admin.analytics.export.performance | App\Http\Controllers\Api\Admin\AnalyticsExportController@exportPerformance | api,auth:sanctum,throttle:admin,permission:export-analytics |
| 438 | GET|HEAD | api/v1/admin/payment-gateways | api.admin.payment-gateways.index | App\Http\Controllers\Api\Admin\PaymentGatewaySettingsController@index | api,auth:sanctum,throttle:admin,lang,permission:view-settings|update-settings |
| 439 | PUT | api/v1/admin/payment-gateways/{code} | api.admin.payment-gateways.update | App\Http\Controllers\Api\Admin\PaymentGatewaySettingsController@update | api,auth:sanctum,throttle:admin,lang,permission:update-settings |
| 440 | GET|HEAD | api/v1/admin/orders/{orderId}/shipment | api.admin.orders.shipment.show | App\Http\Controllers\Api\Admin\ShipmentController@show | api,auth:sanctum,throttle:admin,permission:view-shipment|view-shipments|view-orders|view-order |
| 441 | POST | api/v1/admin/orders/{orderId}/shipment/update-status | api.admin.orders.shipment.update-status | App\Http\Controllers\Api\Admin\ShipmentController@updateStatus | api,auth:sanctum,throttle:admin,permission:update-shipment|create-shipment |
| 442 | POST | api/v1/admin/orders/{orderId}/cancel | api.admin.orders.cancel | App\Http\Controllers\Api\Admin\Wms\OrderCancellationController@cancel | api,auth:sanctum,throttle:admin,permission:order.cancel-during-fulfillment |
| 443 | GET|HEAD | api/v1/admin/order-statuses | api.admin.order-statuses.index | App\Http\Controllers\Api\Admin\OrderStatusCatalogController@index | api,auth:sanctum,throttle:admin,permission:view-order-flows|view-orders|view-order |
| 444 | GET|HEAD | api/v1/admin/order-statuses/{id} | api.admin.order-statuses.show | App\Http\Controllers\Api\Admin\OrderStatusCatalogController@show | api,auth:sanctum,throttle:admin,permission:view-order-flows|view-orders|view-order |
| 445 | PUT | api/v1/admin/order-statuses/{id} | api.admin.order-statuses.update | App\Http\Controllers\Api\Admin\OrderStatusCatalogController@update | api,auth:sanctum,throttle:admin,permission:update-order-flows|update-order-status |
| 446 | GET|HEAD | api/v1/admin/order-flows | api.admin.order-flows.index | App\Http\Controllers\Api\Admin\OrderFlowController@index | api,auth:sanctum,throttle:admin,permission:view-order-flows|view-orders|view-order |
| 447 | POST | api/v1/admin/order-flows | api.admin.order-flows.store | App\Http\Controllers\Api\Admin\OrderFlowController@store | api,auth:sanctum,throttle:admin,permission:create-order-flows|update-order-status |
| 448 | GET|HEAD | api/v1/admin/order-flows/{id} | api.admin.order-flows.show | App\Http\Controllers\Api\Admin\OrderFlowController@show | api,auth:sanctum,throttle:admin,permission:view-order-flows|view-orders|view-order |
| 449 | PUT | api/v1/admin/order-flows/{id} | api.admin.order-flows.update | App\Http\Controllers\Api\Admin\OrderFlowController@update | api,auth:sanctum,throttle:admin,permission:update-order-flows|update-order-status |
| 450 | GET|HEAD | api/v1/admin/order-flows/{flowId}/inputs | api.admin.order-flows.inputs.index | App\Http\Controllers\Api\Admin\FlowInputController@index | api,auth:sanctum,throttle:admin,permission:view-order-flows|view-orders|view-order |
| 451 | POST | api/v1/admin/order-flows/{flowId}/inputs | api.admin.order-flows.inputs.store | App\Http\Controllers\Api\Admin\FlowInputController@store | api,auth:sanctum,throttle:admin,permission:manage-order-flow-inputs|update-order-status |
| 452 | PUT | api/v1/admin/order-flow-inputs/{id} | api.admin.order-flow-inputs.update | App\Http\Controllers\Api\Admin\FlowInputController@update | api,lang,auth:sanctum,throttle:admin,permission:manage-order-flow-inputs|update-order-status |
| 453 | DELETE | api/v1/admin/order-flow-inputs/{id} | api.admin.order-flow-inputs.destroy | App\Http\Controllers\Api\Admin\FlowInputController@destroy | api,lang,auth:sanctum,throttle:admin,permission:manage-order-flow-inputs|update-order-status |
| 454 | GET|HEAD | api/v1/admin/refunds | api.admin.refunds.index | App\Http\Controllers\Api\Admin\RefundController@index | api,auth:sanctum,throttle:admin,lang,permission:view-refunds |
| 455 | GET|HEAD | api/v1/admin/refunds/{refund} | api.admin.refunds.show | App\Http\Controllers\Api\Admin\RefundController@show | api,auth:sanctum,throttle:admin,lang,permission:view-refunds |
| 456 | POST | api/v1/admin/refunds/{refund}/approve | api.admin.refunds.approve | App\Http\Controllers\Api\Admin\RefundController@approve | api,auth:sanctum,throttle:admin,lang,permission:payments.refund |
| 457 | POST | api/v1/admin/refunds/{refund}/reject | api.admin.refunds.reject | App\Http\Controllers\Api\Admin\RefundController@reject | api,auth:sanctum,throttle:admin,lang,permission:payments.refund |
| 458 | GET|HEAD | api/v1/admin/warehouses | api.admin.warehouses.index | App\Http\Controllers\Api\Admin\Wms\WarehouseController@index | api,auth:sanctum,throttle:admin,permission:view-warehouse |
| 459 | POST | api/v1/admin/warehouses | api.admin.warehouses.store | App\Http\Controllers\Api\Admin\Wms\WarehouseController@store | api,auth:sanctum,throttle:admin,permission:manage-warehouse |
| 460 | GET|HEAD | api/v1/admin/warehouses/{id} | api.admin.warehouses.show | App\Http\Controllers\Api\Admin\Wms\WarehouseController@show | api,auth:sanctum,throttle:admin,permission:view-warehouse |
| 461 | PUT | api/v1/admin/warehouses/{id} | api.admin.warehouses.update | App\Http\Controllers\Api\Admin\Wms\WarehouseController@update | api,auth:sanctum,throttle:admin,permission:manage-warehouse |
| 462 | POST | api/v1/admin/warehouses/{id}/set-default | api.admin.warehouses.set-default | App\Http\Controllers\Api\Admin\Wms\WarehouseController@setDefault | api,auth:sanctum,throttle:admin,permission:manage-warehouse |
| 463 | POST | api/v1/admin/warehouses/{id}/activate | api.admin.warehouses.activate | App\Http\Controllers\Api\Admin\Wms\WarehouseController@activate | api,auth:sanctum,throttle:admin,permission:manage-warehouse |
| 464 | POST | api/v1/admin/warehouses/{id}/deactivate | api.admin.warehouses.deactivate | App\Http\Controllers\Api\Admin\Wms\WarehouseController@deactivate | api,auth:sanctum,throttle:admin,permission:manage-warehouse |
| 465 | DELETE | api/v1/admin/warehouses/{id} | api.admin.warehouses.destroy | App\Http\Controllers\Api\Admin\Wms\WarehouseController@destroy | api,auth:sanctum,throttle:admin,permission:manage-warehouse |
| 466 | GET|HEAD | api/v1/admin/locations | api.admin.locations.index | App\Http\Controllers\Api\Admin\Wms\LocationController@index | api,auth:sanctum,throttle:admin,permission:view-location |
| 467 | POST | api/v1/admin/locations | api.admin.locations.store | App\Http\Controllers\Api\Admin\Wms\LocationController@store | api,auth:sanctum,throttle:admin,permission:manage-location |
| 468 | GET|HEAD | api/v1/admin/locations/{id} | api.admin.locations.show | App\Http\Controllers\Api\Admin\Wms\LocationController@show | api,auth:sanctum,throttle:admin,permission:view-location |
| 469 | PUT | api/v1/admin/locations/{id} | api.admin.locations.update | App\Http\Controllers\Api\Admin\Wms\LocationController@update | api,auth:sanctum,throttle:admin,permission:manage-location |
| 470 | POST | api/v1/admin/locations/{id}/activate | api.admin.locations.activate | App\Http\Controllers\Api\Admin\Wms\LocationController@activate | api,auth:sanctum,throttle:admin,permission:manage-location |
| 471 | POST | api/v1/admin/locations/{id}/deactivate | api.admin.locations.deactivate | App\Http\Controllers\Api\Admin\Wms\LocationController@deactivate | api,auth:sanctum,throttle:admin,permission:manage-location |
| 472 | DELETE | api/v1/admin/locations/{id} | api.admin.locations.destroy | App\Http\Controllers\Api\Admin\Wms\LocationController@destroy | api,auth:sanctum,throttle:admin,permission:manage-location |
| 473 | DELETE | api/v1/admin/locations/{location}/products | api.admin.locations.products.destroy | App\Http\Controllers\Api\Admin\Wms\LocationController@emptyProducts | api,auth:sanctum,throttle:admin,permission:manage-location |
| 474 | DELETE | api/v1/admin/wms/locations/{location}/products | api.admin.wms.locations.products.destroy | App\Http\Controllers\Api\Admin\Wms\LocationController@emptyProducts | api,auth:sanctum,throttle:admin,permission:manage-location |
| 475 | GET|HEAD | api/v1/enum-types |  | Closure | api |
| 476 | GET|HEAD | api/v1/admin/fulfillments | api.admin.fulfillments.index | App\Http\Controllers\Api\Admin\Wms\FulfillmentController@index | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 477 | POST | api/v1/admin/fulfillments/release | api.admin.fulfillments.release | App\Http\Controllers\Api\Admin\Wms\FulfillmentController@release | api,auth:sanctum,throttle:admin,permission:fulfillment.create |
| 478 | GET|HEAD | api/v1/admin/fulfillments/{id} | api.admin.fulfillments.show | App\Http\Controllers\Api\Admin\Wms\FulfillmentController@show | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 479 | POST | api/v1/admin/fulfillments/{id}/cancel | api.admin.fulfillments.cancel | App\Http\Controllers\Api\Admin\Wms\FulfillmentController@cancel | api,auth:sanctum,throttle:admin,permission:fulfillment.cancel |
| 480 | POST | api/v1/admin/fulfillments/{id}/assign | api.admin.fulfillments.assign | App\Http\Controllers\Api\Admin\Wms\FulfillmentController@assign | api,auth:sanctum,throttle:admin,permission:manage-fulfillment |
| 481 | POST | api/v1/admin/fulfillments/{id}/create-tasks | api.admin.fulfillments.create-tasks | App\Http\Controllers\Api\Admin\Wms\PickingController@createTasks | api,auth:sanctum,throttle:admin,permission:picking-execute |
| 482 | POST | api/v1/admin/fulfillments/{id}/complete-picking | api.admin.fulfillments.complete-picking | App\Http\Controllers\Api\Admin\Wms\PickingController@completePicking | api,auth:sanctum,throttle:admin,permission:manage-fulfillment |
| 483 | POST | api/v1/admin/fulfillment-items/{id}/assign-placement | api.admin.fulfillment-items.assign-placement | App\Http\Controllers\Api\Admin\Wms\PickingController@assignPlacement | api,auth:sanctum,throttle:admin,permission:manage-fulfillment |
| 484 | GET|HEAD | api/v1/admin/picking-tasks | api.admin.picking-tasks.index | App\Http\Controllers\Api\Admin\Wms\PickingController@index | api,auth:sanctum,throttle:admin,permission:picking-execute |
| 485 | GET|HEAD | api/v1/admin/picking-tasks/{id} | api.admin.picking-tasks.show | App\Http\Controllers\Api\Admin\Wms\PickingController@show | api,auth:sanctum,throttle:admin,permission:picking-execute |
| 486 | POST | api/v1/admin/picking-tasks/{id}/claim | api.admin.picking-tasks.claim | App\Http\Controllers\Api\Admin\Wms\PickingController@claim | api,auth:sanctum,throttle:admin,permission:picking-execute |
| 487 | POST | api/v1/admin/picking-tasks/{id}/release | api.admin.picking-tasks.release | App\Http\Controllers\Api\Admin\Wms\PickingController@releaseClaim | api,auth:sanctum,throttle:admin,permission:picking-execute |
| 488 | POST | api/v1/admin/picking-tasks/{id}/confirm | api.admin.picking-tasks.confirm | App\Http\Controllers\Api\Admin\Wms\PickingController@confirm | api,auth:sanctum,throttle:admin,permission:picking-execute |
| 489 | POST | api/v1/admin/picking-tasks/{id}/record-pick | api.admin.picking-tasks.record-pick | App\Http\Controllers\Api\Admin\Wms\PickingController@recordPick | api,auth:sanctum,throttle:admin,permission:picking-execute |
| 490 | POST | api/v1/admin/picking-tasks/{id}/skip | api.admin.picking-tasks.skip | App\Http\Controllers\Api\Admin\Wms\PickingController@skip | api,auth:sanctum,throttle:admin,permission:picking-execute |
| 491 | POST | api/v1/admin/picking-tasks/{id}/reallocate | api.admin.picking-tasks.reallocate | App\Http\Controllers\Api\Admin\Wms\PickingController@reallocate | api,auth:sanctum,throttle:admin,permission:picking-execute |
| 492 | GET|HEAD | api/v1/admin/batches | api.admin.batches.index | App\Http\Controllers\Api\Admin\Wms\BatchController@index | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 493 | POST | api/v1/admin/batches | api.admin.batches.store | App\Http\Controllers\Api\Admin\Wms\BatchController@store | api,auth:sanctum,throttle:admin,permission:batch.manage |
| 494 | GET|HEAD | api/v1/admin/batches/pending-fulfillments | api.admin.batches.pending-fulfillments | App\Http\Controllers\Api\Admin\Wms\BatchController@pendingFulfillments | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 495 | GET|HEAD | api/v1/admin/batches/{id} | api.admin.batches.show | App\Http\Controllers\Api\Admin\Wms\BatchController@show | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 496 | GET|HEAD | api/v1/admin/batches/{id}/next-task | api.admin.batches.next-task | App\Http\Controllers\Api\Admin\Wms\BatchController@nextTask | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 497 | POST | api/v1/admin/batches/{id}/assign | api.admin.batches.assign | App\Http\Controllers\Api\Admin\Wms\BatchController@assign | api,auth:sanctum,throttle:admin,permission:batch.operate |
| 498 | POST | api/v1/admin/batches/{id}/start | api.admin.batches.start | App\Http\Controllers\Api\Admin\Wms\BatchController@start | api,auth:sanctum,throttle:admin,permission:batch.operate |
| 499 | POST | api/v1/admin/batches/{id}/refresh-progress | api.admin.batches.refresh-progress | App\Http\Controllers\Api\Admin\Wms\BatchController@refreshProgress | api,auth:sanctum,throttle:admin,permission:batch.operate |
| 500 | POST | api/v1/admin/batches/{id}/complete | api.admin.batches.complete | App\Http\Controllers\Api\Admin\Wms\BatchController@complete | api,auth:sanctum,throttle:admin,permission:batch.operate |
| 501 | POST | api/v1/admin/batches/{id}/cancel | api.admin.batches.cancel | App\Http\Controllers\Api\Admin\Wms\BatchController@cancel | api,auth:sanctum,throttle:admin,permission:batch.manage |
| 502 | POST | api/v1/admin/batches/{id}/retry | api.admin.batches.retry | App\Http\Controllers\Api\Admin\Wms\BatchController@retry | api,auth:sanctum,throttle:admin,permission:batch.manage |
| 503 | POST | api/v1/admin/fulfillments/{id}/create-packing-task | api.admin.fulfillments.create-packing-task | App\Http\Controllers\Api\Admin\Wms\PackingController@createTask | api,auth:sanctum,throttle:admin,permission:packing-execute |
| 504 | GET|HEAD | api/v1/admin/packing-tasks | api.admin.packing-tasks.index | App\Http\Controllers\Api\Admin\Wms\PackingController@index | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 505 | GET|HEAD | api/v1/admin/packing-tasks/{id} | api.admin.packing-tasks.show | App\Http\Controllers\Api\Admin\Wms\PackingController@show | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 506 | POST | api/v1/admin/packing-tasks/{id}/assign | api.admin.packing-tasks.assign | App\Http\Controllers\Api\Admin\Wms\PackingController@assign | api,auth:sanctum,throttle:admin,permission:packing-execute |
| 507 | POST | api/v1/admin/packing-tasks/{id}/start | api.admin.packing-tasks.start | App\Http\Controllers\Api\Admin\Wms\PackingController@start | api,auth:sanctum,throttle:admin,permission:packing-execute |
| 508 | POST | api/v1/admin/packing-tasks/{id}/pack | api.admin.packing-tasks.pack | App\Http\Controllers\Api\Admin\Wms\PackingController@pack | api,auth:sanctum,throttle:admin,permission:packing.complete |
| 509 | POST | api/v1/admin/packing-tasks/{id}/verify | api.admin.packing-tasks.verify | App\Http\Controllers\Api\Admin\Wms\PackingController@verify | api,auth:sanctum,throttle:admin,permission:packing.complete |
| 510 | POST | api/v1/admin/packing-tasks/{id}/cancel | api.admin.packing-tasks.cancel | App\Http\Controllers\Api\Admin\Wms\PackingController@cancel | api,auth:sanctum,throttle:admin,permission:manage-fulfillment |
| 511 | GET|HEAD | api/v1/admin/packing-stations | api.admin.packing-stations.index | App\Http\Controllers\Api\Admin\Wms\PackingStationController@index | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 512 | POST | api/v1/admin/packing-stations | api.admin.packing-stations.store | App\Http\Controllers\Api\Admin\Wms\PackingStationController@store | api,auth:sanctum,throttle:admin,permission:manage-warehouse |
| 513 | GET|HEAD | api/v1/admin/packing-stations/{id} | api.admin.packing-stations.show | App\Http\Controllers\Api\Admin\Wms\PackingStationController@show | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 514 | PUT | api/v1/admin/packing-stations/{id} | api.admin.packing-stations.update | App\Http\Controllers\Api\Admin\Wms\PackingStationController@update | api,auth:sanctum,throttle:admin,permission:manage-warehouse |
| 515 | DELETE | api/v1/admin/packing-stations/{id} | api.admin.packing-stations.destroy | App\Http\Controllers\Api\Admin\Wms\PackingStationController@destroy | api,auth:sanctum,throttle:admin,permission:manage-warehouse |
| 516 | GET|HEAD | api/v1/admin/packages | api.admin.packages.index | App\Http\Controllers\Api\Admin\Wms\PackageController@index | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 517 | POST | api/v1/admin/packages | api.admin.packages.store | App\Http\Controllers\Api\Admin\Wms\PackageController@store | api,auth:sanctum,throttle:admin,permission:packing-execute |
| 518 | GET|HEAD | api/v1/admin/packages/{id} | api.admin.packages.show | App\Http\Controllers\Api\Admin\Wms\PackageController@show | api,auth:sanctum,throttle:admin,permission:view-fulfillment |
| 519 | POST | api/v1/admin/packages/{id}/add-item | api.admin.packages.add-item | App\Http\Controllers\Api\Admin\Wms\PackageController@addItem | api,auth:sanctum,throttle:admin,permission:packing-execute |
| 520 | POST | api/v1/admin/packages/{id}/seal | api.admin.packages.seal | App\Http\Controllers\Api\Admin\Wms\PackageController@seal | api,auth:sanctum,throttle:admin,permission:packing-execute |
| 521 | POST | api/v1/admin/packages/{id}/void | api.admin.packages.void | App\Http\Controllers\Api\Admin\Wms\PackageController@void | api,auth:sanctum,throttle:admin,permission:manage-fulfillment |
| 522 | POST | api/v1/admin/fulfillments/{id}/shipments | api.admin.fulfillments.shipments.store | App\Http\Controllers\Api\Admin\Wms\ShipmentController@store | api,auth:sanctum,throttle:admin,permission:create-shipment |
| 523 | GET|HEAD | api/v1/admin/shipments | api.admin.shipments.index | App\Http\Controllers\Api\Admin\Wms\ShipmentController@index | api,auth:sanctum,throttle:admin,permission:view-shipment |
| 524 | GET|HEAD | api/v1/admin/shipments/{id} | api.admin.shipments.show | App\Http\Controllers\Api\Admin\Wms\ShipmentController@show | api,auth:sanctum,throttle:admin,permission:view-shipment |
| 525 | POST | api/v1/admin/shipments/{id}/dispatch | api.admin.shipments.dispatch | App\Http\Controllers\Api\Admin\Wms\ShipmentController@dispatchShipment | api,auth:sanctum,throttle:admin,permission:update-shipment |
| 526 | POST | api/v1/admin/shipments/{id}/deliver | api.admin.shipments.deliver | App\Http\Controllers\Api\Admin\Wms\ShipmentController@markDelivered | api,auth:sanctum,throttle:admin,permission:update-shipment |
| 527 | POST | api/v1/admin/shipments/{id}/cancel | api.admin.shipments.cancel | App\Http\Controllers\Api\Admin\Wms\ShipmentController@cancelShipment | api,auth:sanctum,throttle:admin,permission:update-shipment |
| 528 | POST | api/v1/admin/locations/{location}/stock-receipts | api.admin.locations.stock-receipts | App\Http\Controllers\Api\Admin\Wms\InventoryController@receive | api,auth:sanctum,throttle:admin,permission:inventory-adjust |
| 529 | POST | api/v1/admin/stock-transfers | api.admin.stock-transfers | App\Http\Controllers\Api\Admin\Wms\InventoryController@transfer | api,auth:sanctum,throttle:admin,permission:inventory-adjust |
| 530 | GET|HEAD | api/v1/admin/products/{product}/placements | api.admin.products.placements | App\Http\Controllers\Api\Admin\Wms\InventoryController@productPlacements | api,auth:sanctum,throttle:admin,permission:view-location |
| 531 | GET|HEAD | api/v1/admin/inventory/overview | api.admin.inventory.overview | App\Http\Controllers\Api\Admin\Wms\InventoryController@overview | api,auth:sanctum,throttle:admin,permission:view-location |
| 532 | GET|HEAD | api/v1/admin/inventory/operations | api.admin.inventory.operations.index | App\Http\Controllers\Api\Admin\Wms\InventoryController@operations | api,auth:sanctum,throttle:admin,permission:view-location |
| 533 | GET|HEAD | api/v1/admin/inventory/operations/{key} | api.admin.inventory.operations.show | App\Http\Controllers\Api\Admin\Wms\InventoryController@operationStatus | api,auth:sanctum,throttle:admin,permission:view-location |