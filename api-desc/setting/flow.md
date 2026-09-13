# Request Flows — Settings Module

> Admin `GET /api/v1/settings` (`SettingsController@index`) + `PUT /api/v1/settings` (`SettingsController@update` via `SettingsRequest` — partial, `sometimes` per field, merges `currency_selection_enabled` into `options`) + public `GET /api/v1/general/settings` (`SettingController@index` — `settings.front`, `throttle:public-api`, no auth, single-locale rendering). Also `GET|PUT /api/v1/fast-shipping/settings`. Verified 2026-09-13.

## Flow 1: GET /api/v1/general/settings (Public) — Success

```
Client → GET /api/v1/general/settings   (no auth)
  → [throttle:public-api] (Route::prefix v1/general, routes/api.php:92, name settings.front)
  → App\Http\Controllers\Api\General\SettingController@index
    → SettingService::getSetting() → Settings::first() (singleton)
    → HasCache::remember(FrontendResource::SETTINGS->value, md5(fullUrl), $setting) // 4h tag settings
      ├── HIT  → return cached Setting model
      └── MISS → store then return
    → Marvel\Http\Resources\SettingResource::make($settingCache)
      // routeIs('settings.front') true → site_name/site_desc/meta_desc/site_copy_right as getTranslation(locale) single string
      // logo/footer_logo/favicon via getFirstMediaUrl('*-setting'), minimumOrderAmount string, currency_selection_enabled bool, options
  → 200 {status, message:"تم جلب البيانات بنجاح" (locale), success:true, data:{site_name:"موقعي" (single string), ..., footer_logo, tiktok:null, snapchat:null, minimumOrderAmount:"50.00", currency_selection_enabled:false, options:{...}}}
```

Notes: public, cached per full URL under same `settings` tag as admin (so `PUT /api/v1/settings` flush affects storefront). `tiktok/snapchat` nullable, `minimumOrderAmount` string.

## Flow 2: GET /api/v1/settings (Admin) — Success

```
Client → GET /api/v1/settings (Authorization: Bearer <sanctum>)
  → [auth:sanctum, throttle:admin] + permission:view-settings (SettingsController::__construct)
  → Marvel\Http\Controllers\SettingsController@index
    → Settings::first()
    → HasCache::remember(FrontendResource::SETTINGS->value, md5(fullUrl), $settings)
  → SettingResource::make($setting) // routeIs false → {ar,en} objects for translatable fields
  → 200 {status, message:"Data fetched successfully", success:true, data:{site_name:{ar,en}, site_desc:{ar,en}, meta_desc:{ar,en}, site_copy_right:{ar,en}, logo, footer_logo, favicon, ..., minimumOrderAmount:"50.00", currency_selection_enabled:false, options:{...}}}
```

Requires `view-settings`; otherwise `403`. Same cache tag as public.

## Flow 3: PUT /api/v1/settings (Admin) — Update (partial)

```
Client → PUT /api/v1/settings (auth + permission:update-settings)
  Content-Type: multipart/form-data when files (logo/footer_logo/favicon), else application/json
  Body: any subset of SettingsRequest fields (all sometimes) e.g. {site_name:{en,ar}, site_email, tiktok:"https://...", order_tax_enabled:true, order_tax_rate:14, minimum_order_amount:100, options:{...}, currency_selection_enabled:true}
  → SettingsRequest::rules() — 26 entries, all sometimes (site_name array|string 3..200, site_desc 3..2000, meta_desc 3..2000, site_copy_right 3..200, logo/footer_logo/favicon image mimes max2048, emails/url/phone, fast_shipping_page_publish in:0,1, minimum_order_amount numeric min0, currency_selection_enabled boolean, order_tax_enabled boolean, order_tax_rate nullable numeric 0..100, options array, tiktok/snapchat url)
    ├── validation fail → 422 {errors: {field: ["..."]}}
    └── pass
  → SettingsController@update:
    1. $settings=Settings::first()
    2. $data = $request->only([site_name,site_desc,meta_desc,site_copy_right,site_email,email_support,facebook,instagram,linkedin,promotion_video_url,youtube,tiktok,snapchat,phone,fast_shipping_page_publish,options,minimum_order_amount,order_tax_enabled,order_tax_rate]) // 19 keys (currency flag handled separately)
    3. if has('currency_selection_enabled'):
         $options=array_merge($settings->options ?? [], $data['options'] ?? []);
         $options['currency_selection_enabled']=$request->boolean('currency_selection_enabled');
         $data['options']=$options;
    4. $settings->update($data) // fillable 21 cols inc tiktok/snapchat/order_tax*, casts options:array, minimum_order_amount:decimal:2, order_tax_enabled:boolean, order_tax_rate:float
    5. if has('currency_selection_enabled'): app(CurrencyService::class)->forgetEffectiveCode() // memo cleared
    6. for logo/footer_logo/favicon if has(field): updateSingleImage(request,field,$settings,'*-setting','settings') or throw HttpException 422 (LOGO_UPLOAD_FAILED etc.)
    7. flushTag(FrontendResource::SETTINGS->value) // clears 4h cache for both admin+public
    8. $settings=Settings::first(); → SettingResource::make($settings) → 200
  → 200 {status, message:"Settings updated successfully", success:true, data:{... updated fields ...}}
```

Partial-update semantics: omitting `currency_selection_enabled` leaves stored value untouched and does not touch `options`; sending invalid `currency_selection_enabled` (`2`/`"not-a-boolean"`) or `tiktok:"not-a-url"` or `order_tax_rate:150` → `422`.

## Flow 4: GET /api/v1/fast-shipping/settings

```
Client → GET /api/v1/fast-shipping/settings (auth + view-fast-shipping)
  → FastShippingController@getSettings
    → FastShippingRepository@getSettings → Cache::remember('fast_shipping_settings',3600, fn=> data_get(Settings::first()->options,'fast_shipping', defaults))
      defaults: {enabled:false, duration_minutes:120, fee:0, start_hour:"08:00", end_hour:"22:00"}
  → 200 {status, message, success, data:{enabled, duration_minutes, fee, start_hour, end_hour}}
```

## Flow 5: PUT /api/v1/fast-shipping/settings

```
Client → PUT /api/v1/fast-shipping/settings (auth + update-fast-shipping)
  Body: {enabled?:boolean, duration_minutes?:int 1..1440, fee?:numeric min0, start_hour?:H:i, end_hour?:H:i } (inline sometimes validation)
  → FastShippingController@updateSettings → FastShippingRepository@updateSettings
    → DB::transaction: Settings::lockForUpdate()->first() → $options['fast_shipping']=array_merge(existing, validated) → $settings->update(['options'=>$options])
    → Cache::forget('fast_shipping_settings')
  → 200 {status, message:"Fast shipping settings updated successfully", success:true}
```

Share-not: `flushTag(settings)` (flow 3) does **not** clear `fast_shipping_settings` (separate key).
