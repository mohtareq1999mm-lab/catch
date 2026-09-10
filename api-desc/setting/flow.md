# Request Flows — Settings Module (Admin API)

## Flow 1: GET /api/v1/general/settings (Public) — Success

```
Client → GET /api/v1/general/settings   (no auth)
         ↓
     [throttle:public-api] middleware group
          ↓
     App\Http\Controllers\Api\General\SettingController@index
          ↓
     Settings::first()
          ↓
     HasCache::remember(FrontendResource::SETTINGS->value, md5(request()->fullUrl()), $setting)
          ├── Cache HIT → return cached
          └── Cache MISS → store & return
          ↓
     SettingResource::make($settingCache)
          ↓
     Response: 200 with settings object

> **Note:** The public endpoint `GET /api/v1/general/settings` does not require Sanctum authentication. It uses the `throttle:public-api` middleware only. The translatable fields (`site_name`, `site_desc`, `meta_desc`, `site_copy_right`) are returned as a **single locale string** instead of `{ar, en}` objects. The response includes `tiktok: null`, `snapchat: null`, and `minimumOrderAmount` as a string value (e.g., `"50.00"`). The `currency_selection_enabled` flag and `options` JSON structure are included, matching the admin endpoint format.
```

## Flow 2: GET /api/v1/settings (Admin) — Success

```
Client → GET /api/v1/settings   (auth: sanctum + permission:view-settings)
         ↓
     [auth:sanctum, throttle:admin] middleware group
          ↓
     Marvel\Http\Controllers\SettingsController@index
          ↓
     Settings::first()
          ↓
     HasCache::remember('settings', md5(fullUrl), $settings, 4h)
          ├── Cache HIT → return cached
          └── Cache MISS → store & return
          ↓
     SettingResource::make($setting)
          ↓
     Response: 200 with settings object

> **Note:** The admin endpoint `GET /api/v1/settings` requires `auth:sanctum` and `throttle:admin` with `view-settings` permission. The translatable fields are returned as `{ar, en}` objects. The response includes `tiktok: null`, `snapchat: null` (nullable strings), and `minimumOrderAmount` as a string (e.g., `"50.00"`). The `currency_selection_enabled` flag appears both at top level and inside `options`.
```

## Flow 3: PUT /api/v1/settings (Admin) — Update

```
Client → PUT /api/v1/settings (auth: sanctum + permission:update-settings)
         ↓
     SettingsRequest validation   [currency_selection_enabled => sometimes|boolean; tiktok/snapchat => sometimes|url]
          ↓
     Marvel\Http\Controllers\SettingsController@update
          ↓
     $settings = Settings::first()
          ↓
     $data = $request->only([...all fields..., 'options', 'minimum_order_amount'])
          ↓
     if (request has currency_selection_enabled):
          options = array_merge(existing options, request options)
          options['currency_selection_enabled'] = request->boolean('currency_selection_enabled')
          data['options'] = options
          ↓
     $settings->update($data)
          ↓
     if (request has currency_selection_enabled):
          app(CurrencyService::class)->forgetEffectiveCode()
          ↓
     if (request has 'logo') or (request has 'footer_logo') or (request has 'favicon'):
          Image upload & update
          ↓
     flushTag(FrontendResource::SETTINGS->value)        [settings cache cleared]
          ↓
     $settings = Settings::first()  (fresh)
     SettingResource::make($settings)
          ↓
     Response: 200 "Settings updated successfully"

> **Note:** The admin `PUT /api/v1/settings` endpoint is inside the `auth:sanctum + throttle:admin` middleware group and requires `update-settings` permission. It handles image uploads for logo, footer_logo, and favicon fields. The `currency_selection_enabled` flag is merged into `options` without dropping other keys.
```

## Flow 4: GET /api/v1/fast-shipping/settings

```
Client → GET /api/v1/fast-shipping/settings (auth: sanctum + permission:view-fast-shipping)
         ↓
     FastShippingController@getSettings
          ↓
     FastShippingRepository@getSettings
          ↓
     Cache::remember('fast_shipping_settings', 3600, ...)
          ├── Cache HIT → return cached
          └── Cache MISS → Settings::first() → data_get(options, 'fast_shipping', defaults)
          ↓
     Response: 200 { enabled, duration_minutes, fee, start_hour, end_hour }
```

## Flow 5: PUT /fast-shipping/settings

```
Client → PUT /api/v1/fast-shipping/settings (auth: sanctum + permission:update-fast-shipping)
         ↓
     Inline validation (enabled, duration_minutes, fee, start_hour, end_hour)
          ↓
     FastShippingController@updateSettings
          ↓
     FastShippingRepository@updateSettings
          ↓
     DB::transaction:
         Settings::lockForUpdate()->first()
         Merge data into $options['fast_shipping']
         $settings->update(['options' => $options])
          ↓
     Cache::forget('fast_shipping_settings')
          ↓
     Response: 200 "Fast shipping settings updated successfully"
```