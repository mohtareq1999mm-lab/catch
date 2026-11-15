# Backend Recon Report — Meem Market Homepage Seeding
**Date:** 2026-11-15  
**Author:** Recon agent (read-only pass)  
**Status:** Stage 1 complete — awaiting Stage 2 approval  
**Workspace:** `d:\work\meem` (Laravel monolith)

---

## 1. Confirmed / Contradicted / Unknown Matrix

### [A] Unverified items from the seeding plan

| # | Claim in plan | Status | Evidence |
|---|---|---|---|
| A-01 | `settings` table has `whatsapp` field | **CONTRADICTED** | No `whatsapp` column in migration (`2020_06_02_051901_create_marvel_tables.php:381`). Social fields: facebook, instagram, linkedin, youtube, tiktok, snapchat only. |
| A-02 | `settings` options JSON holds `base_currency_code` / `catalog_currency_code` | **CONFIRMED** | `SettingController` reads and writes these in `options` column; Currency model derives `is_base`/`is_catalog` from them. |
| A-03 | `currency_selection_enabled` is a direct column on `currencies` | **CONTRADICTED** | Stored in `settings.options['currency_selection_enabled']`, not on `currencies` table. |
| A-04 | `currencies.is_base` / `currencies.is_catalog` are writable columns | **CONTRADICTED** | Derived at runtime from `settings.options` values. No such columns exist on the table. |
| A-05 | `categories` has `active_nav` column | **CONFIRMED** | Added via `2026_11_09_000001_add_active_nav_to_categories_table.php`; bool, default false, indexed. |
| A-06 | `categories.level` controls nav depth | **CONFIRMED** | `level` smallint default 1; `nav-data` enforces max depth 3. |
| A-07 | `categories` has media collections | **CONFIRMED** | Collections: `categories-desktop`, `categories-mobile` (confirmed from Media pattern). |
| A-08 | `products` has `pieces_per_carton`, `piece_barcode`, `carton_barcode` | **CONTRADICTED** | These live in separate `product_barcodes` table (one-to-one, nullable FK to `products.id`). Migration: `2026_10_31_000001_create_product_barcodes_table.php`. |
| A-09 | `products` has `sold_quantity` | **CONFIRMED** | Column exists, default 0, indexed; updated only by `OrderReservationService` / `InventoryRestoreService`. |
| A-10 | `products` has `discount_type`, `discount_amount`, `has_discount` | **CONFIRMED** | All present in migration. Also `discount_status`, `start_date`, `end_date`, `has_flash_sale`. |
| A-11 | `products.stock_quantity` / `reserved_quantity` exist | **CONFIRMED** | Both exist. `stock_quantity` = current available units, `reserved_quantity` = units held by open orders. |
| A-12 | `products` has `order` column for explicit ordering | **CONTRADICTED** | No `order` column. Products default-sorted by `created_at DESC`. |
| A-13 | `banners` has `start_date`/`end_date` | **CONTRADICTED** | No date fields. Columns: `title` (JSON), `slug`, `description` (JSON), `status` (bool), `order` (int), timestamps, soft_deletes. |
| A-14 | `coupons` has `min_spend` or `minimum_cart_value` | **CONTRADICTED** | No such column. Columns: `code`, `name` (JSON), `slug`, `discount_type`, `discount`, `max_discount_amount`, `start_date`, `end_date`, `limiter`, `used`, `status`, `border_color`, `borderless`, `is_public`. |
| A-15 | `sections` soft-deletes | **CONTRADICTED** | Sections **hard delete**. No `deleted_at` column in migration (`2026_06_01_135945_create_sections_table.php`). |
| A-16 | Plan assumed 5 payment gateways (stripe, myfatoorah, tap, tabby, tamara) | **CONTRADICTED** | 6 gateways confirmed in `config/payment.php`: `myfatoorah`, `stripe`, `paypal`, `tap`, `tabby`, `tamara`. |

---

### Gaps G-1 to G-12

| Gap | Question | Status | Finding |
|---|---|---|---|
| G-1 | Is there a WhatsApp/contact field in settings? | **CONFIRMED ABSENT** | No `whatsapp` column. Store uses `phone` field only. |
| G-2 | Delivery promise, returns window, shipping fee fields in settings? | **CONFIRMED ABSENT** | No columns. `options` JSON has no standard keys for them. Frontend-only or custom `options` keys needed. |
| G-3 | `min_spend` on coupons? | **CONFIRMED ABSENT** | No `min_spend`. `limiter` = usage count cap, NOT cart value threshold. |
| G-4 | Weekly-offers section type and scheduling? | **CONFIRMED ABSENT** | No `weekly_offers` section type. Sections have no `start_date`/`end_date`. Scheduling only via `flash_sales`. |
| G-5 | Product `order` column? | **CONFIRMED ABSENT** | No `order` column. Default sort: `created_at DESC`. |
| G-6 | Banner date fields? | **CONFIRMED ABSENT** | No `start_date`/`end_date` on banners. Status-toggle only. |
| G-7 | Hero admin CRUD? | **CONTRADICTED — full CRUD confirmed** | REST CRUD at `/api/v1/hero`. Collections: `hero-image`, `hero-video` (disk: `heroes`). Both `section.setting.front` AND `/general/hero` are active sources. |
| G-8 | Is public category list capped at 100? | **CONFIRMED** | `CategoryService::getLimit()` enforces `min($limit, 100)`. |
| G-9 | Is `available` on payment gateways seedable? | **CONFIRMED NOT SEEDABLE** | Derived at runtime: `enabled && configured && supports_catalog_currency`. Only `enabled` can be seeded. |
| G-10 | `best_product_sales` ranking logic? | **CONFIRMED** | `ProductService::getBestProductSales` → `ORDER BY sold_quantity DESC`. |
| G-11 | `/general/footer` endpoint? | **CONFIRMED 404** | No route exists. Footer assembled from `settings` on frontend. No `Footer` model. |
| G-12 | Backend conventions (autoload, naming, existing seeders)? | **CONFIRMED** | `Database\Seeders\Meem\` works under existing autoload. `MeemSeeder.php` exists and must be preserved. See §4. |

---

## 2. Corrected Schema Inventory

### 2.1 `settings` table
**Migration:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php:381`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | auto-increment |
| site_name | string | translatable JSON |
| site_desc | text nullable | translatable JSON |
| meta_desc | text nullable | translatable JSON |
| site_copy_right | string nullable | translatable JSON |
| logo | text nullable | media path |
| footer_logo | text nullable | media path |
| favicon | text nullable | media path |
| site_email | string nullable | |
| email_support | string nullable | |
| facebook | string nullable | |
| instagram | string nullable | |
| linkedin | string nullable | |
| promotion_video_url | string nullable | |
| youtube | string nullable | |
| tiktok | string nullable | |
| snapchat | string nullable | |
| phone | string nullable | |
| minimum_order_amount | decimal default 50 | |
| fast_shipping_page_publish | bool default true | |
| options | JSON nullable | see §2.1a |

> **NO whatsapp, NO delivery_promise, NO returns_window, NO shipping_fee columns.**

**2.1a options JSON known keys:**
```json
{
  "currency": "SAR",
  "base_currency_code": "SAR",
  "catalog_currency_code": "SAR",
  "currency_selection_enabled": false,
  "payment_gateways": {
    "myfatoorah": { "enabled": true },
    "stripe":     { "enabled": false },
    "paypal":     { "enabled": false },
    "tap":        { "enabled": false },
    "tabby":      { "enabled": false },
    "tamara":     { "enabled": false }
  }
}
```

**Cache:** key = `cached_settings_{language}`, TTL = 86400s (24h). Must be flushed after seeding.

---

### 2.2 `currencies` / `currency_rates` tables

**`currencies`** (`database/migrations/2026_08_10_000002_create_currencies_table.php`):

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| code | char(3) UNIQUE | e.g. `SAR` |
| name | JSON | translatable |
| symbol | JSON | translatable |
| country_name | JSON | translatable |
| deleted_at | timestamp nullable | soft delete |

**`currency_rates`** (`database/migrations/2026_08_10_000003_create_currency_rates_table.php`):

| Column | Type | Notes |
|---|---|---|
| currency_id | FK → currencies.id | |
| exchange_rate | decimal | |
| effective_date | date | |
| rate_mode | enum(MANUAL, PROVIDER) | |
| unique key | [currency_id, effective_date] | |

> **`is_base` and `is_catalog` are NOT columns** — derived from `settings.options`.

---

### 2.3 `categories` table

| Column | Type | Notes |
|---|---|---|
| name | JSON | translatable, slug auto from `name.en` |
| slug | string UNIQUE | |
| details | JSON nullable | |
| parent_id | bigint nullable FK self | |
| status | bool | |
| is_featured | bool | |
| level | smallint default 1 | |
| active_nav | bool default false indexed | added via 2026-11-09 migration |
| deleted_at | timestamp nullable | soft delete |

**Media collections:** `categories-desktop`, `categories-mobile`  
**Public list cap:** 100 (`CategoryService::getLimit`)

---

### 2.4 `brands` table

| Column | Type | Notes |
|---|---|---|
| name | JSON | translatable |
| slug | string UNIQUE | |
| details | text nullable | |
| status | bool | |
| order | int default 0 | sortable |
| deleted_at | timestamp nullable | soft delete |

**Media collections:** `brands-desktop`, `brands-mobile`

---

### 2.5 `products` — key columns

| Column | Type | Notes |
|---|---|---|
| sku | string nullable | auto-generated `PRD-{max_id+1}` if not provided |
| stock_quantity | int | available units |
| reserved_quantity | int | units on hold |
| sold_quantity | int default 0 | **NOT writable via API** |
| pieces | int | units per consumer pack |
| has_discount / discount_type / discount_amount / discount_status | mixed | |
| start_date / end_date | timestamp nullable | discount window |
| has_flash_sale | bool | |
| deleted_at | timestamp nullable | soft delete |

**`product_barcodes`** (one-to-one, migration `2026_10_31_000001`):

| Column | Notes |
|---|---|
| product_id | FK unique |
| pieces_per_carton | int |
| piece_barcode | string |
| carton_barcode | string |

**`product_locations`** (migration `2026_09_22_081825`):

| Column | Notes |
|---|---|
| product_id | FK |
| location_id | FK → locations.id |
| quantity | int |

> **No `order` column** on products. `sold_quantity` updated by Order services only.

---

### 2.6 `sections` table

| Column | Type | Notes |
|---|---|---|
| type | string | FK to `section_types.type` |
| title | JSON | translatable |
| order | int | |
| endpoint | string nullable | **NOT writable via API PUT** |
| content_page_id | bigint nullable FK | **NOT writable via API PUT** |
| is_active | bool | |
| title_visible | bool | |
| setting | JSON | stores product IDs, category IDs, etc. |

> **NO soft delete.** Hard delete.  
> `endpoint` and `content_page_id` must be written directly to DB (as existing `MeemSeeder.php` does).

---

### 2.7 `coupons` table

| Column | Type | Notes |
|---|---|---|
| code | string UNIQUE | generated: `coupon_{7 random uppercase chars}` — plan said `COUPON_XXXXXXX`, **wrong** |
| name | JSON | translatable |
| discount_type / discount / max_discount_amount | mixed | |
| start_date / end_date | timestamp nullable | |
| limiter | int nullable | **usage count cap**, NOT min_spend |
| used | int default 0 | |
| status / border_color / borderless / is_public | mixed | |

> **No `min_spend` field.** Hard delete (no soft delete).

---

### 2.8 `banners` table

| Column | Type | Notes |
|---|---|---|
| title | JSON | translatable |
| slug | string UNIQUE | |
| description | JSON nullable | translatable |
| status | bool | |
| order | int default 0 | |
| deleted_at | timestamp nullable | soft delete |

> **No `start_date`/`end_date`.**

---

### 2.9 `flash_sales` table

| Column | Notes |
|---|---|
| start_date / end_date | datetime — scheduling supported |
| status / type / discount / max_discount_amount / order | mixed |
| deleted_at | soft delete |

---

## 3. Gateway Reconciliation

| Gateway code | Plan | Recon | Decision |
|---|---|---|---|
| `myfatoorah` | ✓ | ✓ | Include |
| `stripe` | ✓ | ✓ | Include |
| `tap` | ✓ | ✓ | Include |
| `tabby` | ✓ | ✓ | Include |
| `tamara` | ✓ | ✓ | Include |
| `paypal` | ✗ **missing from plan** | ✓ **in `config/payment.php`** | **Add to seeder** |

`MeemPaymentSeeder` must handle all 6 codes.

---

## 4. Seeder Convention Findings

### 4.1 Autoload
`Database\Seeders\Meem\` works automatically under `database/seeders/Meem/`. No `composer.json` changes needed.

### 4.2 Existing seeders
| File | Action |
|---|---|
| `database/seeders/DatabaseSeeder.php` | Preserve — do not modify |
| `database/seeders/MeemSeeder.php` | Preserve — new orchestrator coexists |
| Marvel package seeders | Ignore — no conflict with upsert approach |

### 4.3 Idempotency strategy (natural keys)
| Resource | Key | Strategy |
|---|---|---|
| Settings | singleton | `first()->update()` |
| Currencies | `code` | `withTrashed()->updateOrCreate` |
| Currency rates | `[currency_id, effective_date]` | `updateOrCreate` |
| Categories | `slug` | `updateOrCreate` |
| Brands | `slug` | `withTrashed()->updateOrCreate` |
| Products | `sku` | `withTrashed()->updateOrCreate` |
| Product barcodes | `product_id` | `updateOrCreate` |
| Product locations | `[product_id, location_id]` | `updateOrCreate` |
| Sections | `[content_page_id, type, order]` | `updateOrCreate` (direct DB) |
| Coupons | `code` via `byCode` scope | `update()` only — **never create** |
| Banners | `slug` | verify only |
| Flash sales | `slug` | verify only |
| Warehouses | `code` | `updateOrCreate` |

### 4.4 Truncate safety matrix
| Table | Truncate | Condition |
|---|---|---|
| `sections` | ✓ | Local + `--fresh` only |
| `section_types` | ✓ | Local + `--fresh` only |
| `section_type_settings` | ✓ | Local + `--fresh` only |
| `categories` / `brands` / `products` / `coupons` / `banners` / `flash_sales` | ✗ never | — |

### 4.5 `seeding_batches` / `seeding_batch_rows`
Do not exist. Will not be created. Batch tracking via `SEEDING_BATCH_ID` env var + console output only.

---

## 5. Manifest Strategy

**Location:** `docs/seeding/homepage.seed.json`  
**Consumed by:** Laravel seeders (PHP) and storefront API test runner (Node.js)

**REQUIRED_* validation:** `MeemBaseSeeder::validateNoRequired()` recursively scans all string values. Any `REQUIRED_` prefix → skip dataset (warn) or abort (production profile).

**Gate checks:** `MeemBaseSeeder::isGateOpen('D1_...')` reads `meta.governance`. False gate → skip + INFO log.

**No hardcoded IDs:** All cross-references resolved at runtime by stable slug/sku/code → DB id.

---

## 6. Required Edits to the Seeding Plan

| # | Edit |
|---|---|
| E-1 | Remove `whatsapp` from settings payload |
| E-2 | Remove `is_base`/`is_catalog` from currencies seeder — set via `settings.options` only |
| E-3 | Remove `currency_selection_enabled` from currencies table seeder — store in `settings.options` |
| E-4 | Move `pieces_per_carton`, `piece_barcode`, `carton_barcode` to `product_barcodes` upsert |
| E-5 | Add `paypal` as 6th gateway code |
| E-6 | Coupon code format: match by `Coupon::byCode()` scope (case-insensitive), not by `COUPON_XXXXXXX` |
| E-7 | Remove `min_spend` from coupon seeder |
| E-8 | Remove `order` from product seeder fields |
| E-9 | Remove `start_date`/`end_date` from banner seeder |
| E-10 | Hero seeder: plan said no CRUD — full CRUD confirmed at `/api/v1/hero` |
| E-11 | Sections `endpoint` not writable via API PUT — write directly to DB |
| E-12 | Sections hard delete confirmed — orphan cleanup is permanent |
| E-13 | `weekly_offers` section type does not exist — remove or create as new `section_types` entry |
| E-14 | Do NOT set `sold_quantity` from seeder |
| E-15 | Category cap 100 confirmed — pagination notes are correct |
| E-16 | Locale: `lang` header only; missing → `en` default (not `ar`) |
| E-17 | Nav-data cache TTL is 120s (not 86400s) |
| E-18 | `/general/footer` does not exist — remove from verification steps |

---

## 7. Open Questions for Human Decision

| # | Question | Impact |
|---|---|---|
| D1 | SAR currency approved? | Blocks `MeemCurrencySeeder` |
| D2 | Hero/weekly-offer copy approved? | Blocks hero section seeding |
| D3 | Navigation model approved? | Blocks `MeemCategorySeeder` |
| D4 | Payment gateway decision approved? | Blocks `MeemPaymentSeeder` |
| D5 | Contact and delivery settings approved? | Blocks `MeemSettingsSeeder`, `MeemGeographySeeder` |
| D6 | Production scope approved? | Blocks production-profile run |
| D7 | New `MeemOrchestrator` replace or extend existing `MeemSeeder.php`? | Recommended: coexist |
| D8 | Introduce `weekly_offers` as new section type? | Needs schema + UI work |
| D9 | Shipping price for Riyadh (SAR)? | Blocks geography seeder |
| D10 | Min order amount (SAR)? | Blocks settings seeder |
| D11 | Flush nav-data cache after category seeding? | Recommended: yes |
| D12 | Should `paypal` be enabled or disabled initially? | Manifest must specify |

---

## 8. Summary Counts

| Category | Count |
|---|---|
| **Confirmed** (plan was correct) | 9 |
| **Contradicted** (plan needed correction) | 10 |
| **Unknown** | 0 |
| **Edits required to seeding plan** | 18 |
| **Open decisions for human** | 12 |

---

*End of Stage 1 Recon Report. Stage 2 (seeders) begins only after explicit approval.*
