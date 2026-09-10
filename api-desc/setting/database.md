# Settings Module — Database (Admin API)

## Tables

### `settings`

| Column | Type | Translatable | Description |
|--------|------|:---:|-------------|
| id | bigint(20) UNSIGNED | | Primary key |
| site_name | varchar(255) | ✓ | Website name |
| site_desc | text | ✓ | Website description |
| meta_desc | text | ✓ | SEO meta description |
| site_copy_right | varchar(255) | ✓ | Copyright text |
| logo | varchar(255) | | Logo file path |
| favicon | varchar(255) | | Favicon file path |
| site_email | varchar(255) | | Contact email |
| email_support | varchar(255) | | Support email |
| facebook | varchar(255) | | Facebook URL |
| instagram | varchar(255) | | Instagram URL |
| linkedin | varchar(255) | | LinkedIn URL |
| promotion_video_url | varchar(255) | | Promotional video URL |
| youtube | varchar(255) | | YouTube URL |
| tiktok | varchar(255) | | TikTok URL (nullable) |
| snapchat | varchar(255) | | Snapchat URL (nullable) |
| phone | varchar(255) | | Phone number |
| fast_shipping_page_publish | tinyint(1) | | Default: 1 |
| options | json | | Free-form JSON |
| created_at | timestamp | | |
| updated_at | timestamp | | |

**Indexes:**
- Primary: `id`

**Migrations:**
- `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`

## JSON Structure: `options`

```json
{
    "minimumOrderAmount": 100,
    "currency_selection_enabled": false,
    "fast_shipping": {
        "enabled": true,
        "duration_minutes": 120,
        "fee": 0,
        "start_hour": "08:00",
        "end_hour": "22:00"
    }
}
```

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `minimumOrderAmount` | number | 0 | Checkout minimum order total |
| `currency_selection_enabled` | boolean | `false` | Allow storefront currency selection; when `false` the effective currency always resolves to the catalog code |
| `fast_shipping` | object | defaults | Fast shipping config (managed via fast-shipping endpoints) |

## Query Patterns

### Fetch Settings
```sql
SELECT * FROM `settings` LIMIT 1;
```

### Update minimumOrderAmount
```sql
UPDATE `settings`
SET `options` = JSON_SET(`options`, '$.minimumOrderAmount', 100)
WHERE `id` = 1;
```

## N+1 Prevention

- Singleton table — no relations, no N+1 risk

## Performance

- **Fetch settings:** 1 query, <5ms (cached 4h under `settings` tag on admin + public endpoints)
- **Fast shipping settings:** Cached 1 hour
- **Settings update:** `lockForUpdate()` transaction + `flushTag('settings')`
