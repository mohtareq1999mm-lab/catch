# Settings Module — Backend Architecture

> Admin `GET|PUT /api/v1/settings` (`SettingsController` `packages/marvel/src/Http/Controllers/SettingsController.php`) + public `GET /api/v1/general/settings` (`SettingController` `app/Http/Controllers/Api/General/SettingController.php` — `settings.front`). Verified 2026-09-13 vs `SettingsRequest`, `SettingResource`, `Settings` model.

## Endpoints

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| `GET` | `/api/v1/general/settings` | Public | — | Public settings (`settings.front`) — `throttle:public-api` only |
| `GET` | `/api/v1/settings` | Sanctum | `view-settings` | Admin fetch — `auth:sanctum`,`throttle:admin` |
| `PUT` | `/api/v1/settings` | Sanctum | `update-settings` | Admin partial update — `SettingsRequest` `sometimes`, media via `MediaManager` |
| `GET` | `/api/v1/fast-shipping/settings` | Sanctum | `view-fast-shipping` | Fast shipping config from `options.fast_shipping` |
| `PUT` | `/api/v1/fast-shipping/settings` | Sanctum | `update-fast-shipping` | Merge into `options.fast_shipping` + `lockForUpdate` + forget cache |

## Route Definitions

### Admin Routes (inside `Route::middleware(['auth:sanctum','throttle:admin'])`)

**File:** `packages/marvel/src/Rest/Routes.php:118-122`

```php
Route::get('settings', [SettingsController::class, 'index']);                         // 118 — view-settings
Route::put('settings', [SettingsController::class, 'update']);                        // 119 — update-settings
Route::get('fast-shipping/settings', [FastShippingController::class, 'getSettings']);    // 121 — view-fast-shipping
Route::put('fast-shipping/settings', [FastShippingController::class, 'updateSettings']); // 122 — update-fast-shipping
```

Middleware `auth:sanctum` + `throttle:admin` wraps the group (line `Route::middleware(['auth:sanctum','throttle:admin'])->group` around line 114). Permissions via controller `__construct`:

```php
// SettingsController.php:25-30
public function __construct(SettingsRepository $repository) {
  $this->repository=$repository;
  $this->middleware("permission:".Permission::VIEW_SETTINGS,   ["only"=>["index"]]);
  $this->middleware("permission:".Permission::UPDATE_SETTINGS, ["only"=>["update"]]);
}
// destroy() throws MarvelException(ACTION_NOT_VALID) — no delete endpoint
```

### Public Routes (inside `Route::prefix('v1/general')->middleware(['api','throttle:public-api'])`)

**File:** `routes/api.php:92`

```php
Route::get('settings', [SettingController::class, 'index'])->name('settings.front');
```

No `auth:sanctum` — `throttle:public-api` only. Singleton, channel-agnostic.

## Middleware

| Endpoint | Middleware |
|----------|-----------|
| `GET /api/v1/general/settings` | `api`, `throttle:public-api` (no auth) |
| `GET /api/v1/settings` | `auth:sanctum`, `throttle:admin`, `permission:view-settings` (SettingsController ctor) |
| `PUT /api/v1/settings` | `auth:sanctum`, `throttle:admin`, `permission:update-settings` |
| `GET /api/v1/fast-shipping/settings` | `auth:sanctum`, `throttle:admin`, `permission:view-fast-shipping` |
| `PUT /api/v1/fast-shipping/settings` | `auth:sanctum`, `throttle:admin`, `permission:update-fast-shipping` |

## Controllers & Services

### SettingsController — `packages/marvel/src/Http/Controllers/SettingsController.php:32-100`

```php
use ApiResponse, MediaManager, HasCache; // + FrontendResource::SETTINGS, CurrencyService, SettingsRequest, SettingResource

index(Request $request) {
  $settings=Settings::first(); // singleton, no pagination
  $settingCache=$this->remember(FrontendResource::SETTINGS->value, md5($request->fullUrl()), $settings); // tag settings, TTL 4h
  return $this->apiResponse(FETCH_DATA_SUCCESSFULLY,200,true, SettingResource::make($settingCache));
}
update(SettingsRequest $request) {
  $settings=Settings::first();
  $data=$request->only(['site_name','site_desc','meta_desc','site_copy_right','site_email','email_support','facebook','instagram','linkedin','promotion_video_url','youtube','tiktok','snapchat','phone','fast_shipping_page_publish','options','minimum_order_amount','order_tax_enabled','order_tax_rate']);
  if($request->has('currency_selection_enabled')) {
    $options=array_merge($settings->options ?? [], $data['options'] ?? []);
    $options['currency_selection_enabled']=$request->boolean('currency_selection_enabled');
    $data['options']=$options;
  }
  $settings->update($data);
  if($request->has('currency_selection_enabled')) app(CurrencyService::class)->forgetEffectiveCode();
  if($request->has('logo')       && !$this->updateSingleImage($request,'logo',$settings,'logo-setting','settings')) throw new HttpException(422, __('message.ERROR.LOGO_UPLOAD_FAILED'));
  if($request->has('footer_logo')&& !$this->updateSingleImage($request,'footer_logo',$settings,'footer_logo-setting','settings')) throw new HttpException(422, __('message.ERROR.FOOTER_LOGO_UPLOAD_FAILED'));
  if($request->has('favicon')    && !$this->updateSingleImage($request,'favicon',$settings,'favicon-setting','settings')) throw new HttpException(422, __('message.ERROR.FAVICON_UPLOAD_FAILED'));
  $this->flushTag(FrontendResource::SETTINGS->value); // invalidates admin+public cache (shared tag)
  return $this->apiResponse(SETTINGS_UPDATED_SUCCESSFULLY,200,true, SettingResource::make(Settings::first()));
}
```

### SettingController (storefront) — `app/Http/Controllers/Api/General/SettingController.php:12-25`

```php
use ApiResponse, HasCache; private SettingService $settingService;
public function index() {
  $setting=$this->settingService->getSetting(); // App\Services\General\SettingService::getSetting() → Settings::first()
  $settingCache=$this->remember(FrontendResource::SETTINGS->value, md5(request()->fullUrl()), $setting);
  return $this->apiResponse(FETCH_DATA_SUCCESSFULLY,200,true, SettingResource::make($settingCache));
}
```

Same `SettingResource` and tag `settings` (shared cache namespace — `PUT` flush benefits public GET).

## Requests

### SettingsRequest — `packages/marvel/src/Http/Requests/SettingsRequest.php:27-58`

```php
authorize(): true // permission via controller
rules(): [
  "site_name" => ['sometimes','array'], "site_name.*" => ['sometimes','string','min:3','max:200'],
  "site_desc" => ['sometimes','array'], "site_desc.*" => ['sometimes','string','min:3','max:2000'],
  "meta_desc" => ['sometimes','array'], "meta_desc.*" => ['sometimes','string','min:3','max:2000'],
  "site_copy_right" => ['sometimes','array'], "site_copy_right.*" => ['sometimes','string','min:3','max:200'],
  "logo" => ['sometimes','image','mimes:jpeg,png,jpg,gif,svg','max:2048'],
  "footer_logo" => ['sometimes','image','mimes:jpeg,png,jpg,gif,svg','max:2048'],
  "favicon" => ['sometimes','image','mimes:jpeg,png,jpg,gif,svg','max:2048'],
  "site_email" => ['sometimes','email'], "email_support" => ['sometimes','email'],
  "facebook" => ['sometimes','url'], "instagram" => ['sometimes','url'], "linkedin" => ['sometimes','url'],
  "promotion_video_url" => ['sometimes','url'], 'youtube' => ['sometimes','url'],
  'tiktok' => ['sometimes','url'], 'snapchat' => ['sometimes','url'],
  'phone' => ['sometimes','string'], 'fast_shipping_page_publish' => ['sometimes','in:0,1'],
  'minimum_order_amount' => ['sometimes','numeric','min:0'],
  'currency_selection_enabled' => ['sometimes','boolean'],
  'order_tax_enabled' => ['sometimes','boolean'],
  'order_tax_rate' => ['nullable','numeric','min:0','max:100'],
  'options' => ['sometimes','array'],
]
failedValidation: HttpResponseException 422 json errors
```

All `sometimes` — `PUT` is partial-update; missing fields leave stored values untouched.

## Resource

### SettingResource — `Marvel\Http\Resources\SettingResource.php:15-52`

```php
toArray($request) => [
  "site_name" => request()->routeIs('settings.front') ? getTranslation('site_name', locale)
                                                       : ['ar'=>getTranslation('site_name','ar'),'en'=>getTranslation('site_name','en')],
  // same branching for site_desc, meta_desc, site_copy_right
  "logo" => getFirstMediaUrl('logo-setting'),
  "footer_logo" => getFirstMediaUrl('footer_logo-setting'),
  "favicon" => getFirstMediaUrl('favicon-setting'),
  "site_email","email_support","facebook","instagram","linkedin","promotion_video_url",'youtube','tiktok','snapchat','phone',
  "fast_shipping_page_publish" => $this->fast_shipping_page_publish,
  "minimumOrderAmount" => $this->minimum_order_amount, // decimal:2 string
  "order_tax_enabled" => (bool) $this->order_tax_enabled, // added 2026-09-13 — was missing, now SettingResource.php:44
  "order_tax_rate" => $this->order_tax_rate !== null ? (float) $this->order_tax_rate : null, // nullable 0..100 — SettingResource.php:45
  "currency_selection_enabled" => (bool) data_get($this->options,'currency_selection_enabled',false),
  "options" => $this->options ?? null,
]
```

Public vs admin branch: `routeIs('settings.front')` → single locale string; otherwise `{ar,en}` objects.

## Model

### Settings — `Marvel\Database\Models\Settings.php`

```php
table: settings; use HasTranslations, InteractsWithMedia;
translatable: [site_name, site_desc, meta_desc, site_copy_right];
fillable: [site_name, site_desc, meta_desc, site_copy_right, logo, footer_logo, favicon, site_email, email_support, facebook, instagram, linkedin, promotion_video_url, youtube, tiktok, snapchat, phone, fast_shipping_page_publish, options, minimum_order_amount, order_tax_enabled, order_tax_rate];
casts: [options=>array, minimum_order_amount=>decimal:2, order_tax_enabled=>boolean, order_tax_rate=>float];
getData(?lang): cached_settings_{lang} 86400s via Cache
```

## Fast Shipping Flow

`settings.options.fast_shipping = {enabled:bool, duration_minutes:int 1..1440, fee:numeric min0, start_hour:H:i, end_hour:H:i}` defaults `enabled:false, 120, 0, 08:00, 22:00`. `FastShippingController::getSettings` → `FastShippingRepository::getSettings` → `Cache::remember('fast_shipping_settings',3600)` → `data_get(options,'fast_shipping',defaults)`. Update: `DB::transaction` `lockForUpdate`, merge, `Cache::forget`.

## Caching

| Endpoint | Key | TTL | Flush |
|----------|-----|-----|-------|
| `GET /general/settings` | `HasCache::remember('settings', md5(fullUrl), Settings::first())` | 4h tag `settings` | `PUT /settings` `flushTag(settings)` |
| `GET /settings` | same tag/key | same | same |
| `GET /fast-shipping/settings` | `Cache::remember('fast_shipping_settings',3600)` | 1h | `PUT /fast-shipping/settings` `Cache::forget` |
| `currency_selection_enabled` toggle | — | — | also `CurrencyService::forgetEffectiveCode()` |

Fast-shipping and general settings share no cache key — flushing `settings` does not clear `fast_shipping_settings`.

## minimumOrderAmount & order_tax

| Field | Source | Type | Validation | Enforced |
|-------|--------|------|------------|----------|
| `minimumOrderAmount` (response) | `settings.minimum_order_amount` (`decimal:2` cast → string `"50.00"`) mirrored as `top.minimumOrderAmount` | string | `minimum_order_amount: sometimes|numeric|min:0` on `PUT` | `CheckoutRepository::verify()` throws 400 if cart total < decimal |
| `order_tax_enabled` | `settings.order_tax_enabled` `boolean` | bool | `sometimes|boolean` | checkout tax calc when true |
| `order_tax_rate` | `settings.order_tax_rate` `float` 0..100 | float `nullable` | `nullable|numeric|0..100` | multiplied when enabled |

## currency_selection_enabled & tiktok/snapchat & footer_logo

| Flag | Storage | Validation | Response |
|------|---------|------------|----------|
| `currency_selection_enabled` | `settings.options.currency_selection_enabled: bool` | `sometimes|boolean` on `PUT` (`true/false/0/1/"0"/"1"`; `2` or `"not-a-boolean"` → 422) — merged via `array_merge` preserving `fast_shipping` + other `options` keys | top-level bool + `options.currency_selection_enabled` |
| `tiktok`/`snapchat` | `settings.tiktok`/`snapchat` nullable string | `sometimes|url` | `string|null` on both public+admin GETs |
| `footer_logo` | media collection `footer_logo-setting` | `sometimes|image|mimes...|max:2048` | `getFirstMediaUrl('footer_logo-setting')` |

Consumer: `CurrencyService::isCurrencySelectionEnabled()` / `getEffectiveCode()`; when false effective currency = catalog code.
