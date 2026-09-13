# Settings Module — Database

> Singleton `settings` (one row, id=1). Shared by admin `PUT /api/v1/settings` and public `GET /api/v1/general/settings`. See `Settings.php` + `SettingsRequest` + `SettingResource`.

## Tables

### `settings`

| Column | Type | Translatable | Nullable | Cast | Description |
|--------|:----:|--------------|----------|------|-------------|
| `id` | `bigint(20) UNSIGNED PK` |  |  |  | singleton |
| `site_name` | `varchar(255)` | ✓ |  |  | `{ar,en}` via `HasTranslations` |
| `site_desc` | `text` | ✓ |  |  | `{ar,en}` |
| `meta_desc` | `text` | ✓ |  |  | `{ar,en}` |
| `site_copy_right` | `varchar(255)` | ✓ |  |  | `{ar,en}` |
| `logo` | `varchar` |  | ✓ |  | media collection `logo-setting` (Spatie) |
| `footer_logo` | `varchar` |  | ✓ |  | media collection `footer_logo-setting` |
| `favicon` | `varchar` |  | ✓ |  | media collection `favicon-setting` |
| `site_email` | `varchar` |  | ✓ |  |  |
| `email_support` | `varchar` |  | ✓ |  |  |
| `facebook` | `varchar` |  | ✓ |  | URL |
| `instagram` | `varchar` |  | ✓ |  | URL |
| `linkedin` | `varchar` |  | ✓ |  | URL |
| `promotion_video_url` | `varchar` |  | ✓ |  | URL |
| `youtube` | `varchar` |  | ✓ |  | URL |
| `tiktok` | `varchar` |  | ✓ |  | URL `sometimes|url` nullable |
| `snapchat` | `varchar` |  | ✓ |  | URL `sometimes|url` nullable |
| `phone` | `varchar` |  | ✓ |  |  |
| `fast_shipping_page_publish` | `tinyint(1)` |  | ✓ |  | `0|1` via `sometimes|in:0,1` |
| `options` | `json` |  | ✓ | `array` | `minimumOrderAmount`, `currency_selection_enabled`, `fast_shipping` etc. |
| `minimum_order_amount` | `decimal(10,2)` |  | ✓ | `decimal:2` | returned as `minimumOrderAmount` string |
| `order_tax_enabled` | `tinyint(1)` |  | ✓ | `boolean` | `sometimes|boolean` |
| `order_tax_rate` | `decimal(5,2)` |  | ✓ | `float` | `nullable|numeric|0..100` |
| `created_at`/`updated_at` | `timestamp` |  |  |  |  |

**Fillable:** all above per `Settings.php:10-27` (21 keys). **Translatable:** `site_name,site_desc,meta_desc,site_copy_right`. **Media:** `InteractsWithMedia` 3 collections.

**Migrations:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` (initial) + later `add_tiktok_snapchat`, `add_order_tax`, `add_footer_logo` migrations.

**Indexes:** `PRIMARY id` (no secondary indexes — singleton scan is `SELECT * FROM settings LIMIT 1`).

## JSON Structure: `options` (cast `array`)

```json
{
  "minimumOrderAmount": "50.00",
  "currency_selection_enabled": false,
  "currency": "USD",
  "base_currency_code": "USD",
  "catalog_currency_code": "USD",
  "fast_shipping": {"enabled": false, "duration_minutes": 120, "fee": 0, "start_hour": "08:00", "end_hour": "22:00"}
}
```

| Key | Type | Default | Managed via |
|-----|------|---------|-------------|
| `minimumOrderAmount` | string `decimal:2` | `"0.00"` | `PUT /settings` `minimum_order_amount` (also top-level column) — mirrored |
| `currency_selection_enabled` | `boolean` | `false` | `PUT /settings` `currency_selection_enabled: sometimes|boolean` — merged into `options` without dropping other keys, `CurrencyService::forgetEffectiveCode()` |
| `fast_shipping` | object | `{enabled:false, duration_minutes:120, fee:0, start_hour:"08:00", end_hour:"22:00"}` | `PUT /fast-shipping/settings` (merged under `options.fast_shipping`) |
| derived `currency` keys | string | `"USD"` | seeded via `SettingSeeder` |

## Query Patterns

### Fetch singleton

```sql
SELECT * FROM `settings` LIMIT 1; -- via Settings::first(), cached per request (HasCache 4h tag settings + Setting::getData per-locale 86400s)
```

Via `Settings::getData(?string $lang)` → `Cache::remember('cached_settings_'.$lang, 86400)`.

### Update (partial)

```php
$settings->update($request->only(['site_name','site_desc','meta_desc','site_copy_right','site_email','email_support','facebook','instagram','linkedin','promotion_video_url','youtube','tiktok','snapchat','phone','fast_shipping_page_publish','options','minimum_order_amount','order_tax_enabled','order_tax_rate']));
// currency_selection_enabled merged separately:
// $options = array_merge($settings->options ?? [], $data['options'] ?? []);
// $options['currency_selection_enabled'] = $request->boolean('currency_selection_enabled');
// $data['options'] = $options;
```

Fast-shipping update uses `DB::transaction` + `SELECT ... LOCK FOR UPDATE`.

## N+1 Prevention

Singleton — no relations, no pagination, no eager load needed. MediaLibrary relations are per-model polymorphic but only 3 collections on one row — 1+3 queries max.

## Performance

- Singleton fetch `LIMIT 1` — <5ms DB, 4h `HasCache` tag `settings` shared by `GET /settings` + `GET /general/settings` (key `md5(fullUrl)`).
- `Setting::getData(locale)` per-locale 86400s cache layer.
- `PUT /settings` → `flushTag(settings)` + `forgetEffectiveCode()` when flag toggled.
- Fast-shipping `Cache::remember('fast_shipping_settings',3600)` separate namespace.
