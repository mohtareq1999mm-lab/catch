# Settings Module — Database (Public API)

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
| phone | varchar(255) | | Phone number |
| fast_shipping_page_publish | tinyint(1) | | Default: 1 |
| options | json | | Free-form JSON (includes `minimumOrderAmount`, `fast_shipping`, etc.) |
| created_at | timestamp | | |
| updated_at | timestamp | | |

**Indexes:**
- Primary: `id`

**Migrations:**
- `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`

## Query Patterns

### Fetch Settings
```sql
SELECT * FROM `settings` LIMIT 1;
```

## N+1 Prevention

- **Fetch settings:** 1 query, no relations loaded — no N+1 risk

## `minimumOrderAmount`

- Stored inside `options` JSON as `options.minimumOrderAmount`
- Exposed at top level in `SettingResource` via `$this->options['minimumOrderAmount'] ?? 0`
- Enforced in `CheckoutRepository::verify()` — throws 400 if cart total < minimum

## Performance

- **Fetch settings:** 1 query, <5ms
- **No caching** — singleton table hit on every request

## Update via Admin

```http
PUT /api/v1/settings
Authorization: Bearer <super_admin_token>
Content-Type: application/json

{
  "options": {
    "minimumOrderAmount": 100,
    ...other existing options...
  }
}
```

⚠️ Replaces entire `options` JSON — include all existing keys to avoid data loss.

## Media

- **Logo:** Collection `logo-setting`, disk `settings`
- **Favicon:** Collection `favicon-setting`, disk `settings`
- Managed via `Marvel\Traits\MediaManager`
