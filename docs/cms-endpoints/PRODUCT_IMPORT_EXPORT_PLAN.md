# Product Import/Export System — Implementation Plan

## Architecture

```
API Layer
  POST /api/v1/products/import  →  ProductImportController
  GET  /api/v1/products/export  →  ProductExportController
  GET  /api/v1/products/import/{id}/status
  GET  /api/v1/products/import/{id}/download-errors
  GET  /api/v1/samples/product-import

Job Layer
  ImportProductsJob  (ShouldQueue, chunk reading, retries)
  ExportProductsJob  (optional queue)

Excel Layer (Maatwebsite Laravel Excel)
  WithMultipleSheets — 7 sheets

  Import:
    Imports/ProductsImport.php          (WithMultipleSheets)
    Imports/Sheets/ProductsSheetImport.php
    Imports/Sheets/ProductVariantsSheetImport.php
    Imports/Sheets/ImagesSheetImport.php
    Imports/Sheets/CategoriesSheetImport.php
    Imports/Sheets/BrandsSheetImport.php
    Imports/Sheets/FlashSalesSheetImport.php
    Imports/Sheets/SlidersSheetImport.php

  Export:
    Exports/ProductsExport.php          (WithMultipleSheets)
    Exports/Sheets/ProductsSheetExport.php
    Exports/Sheets/ProductVariantsSheetExport.php
    Exports/Sheets/ImagesSheetExport.php
    Exports/Sheets/CategoriesSheetExport.php
    Exports/Sheets/BrandsSheetExport.php
    Exports/Sheets/FlashSalesSheetExport.php
    Exports/Sheets/SlidersSheetExport.php

Service Layer
  Services/Import/ProductImportService.php
  Services/Import/ImageHandlers/UrlImageHandler.php
```

---

## Excel Structure — 7 Sheets

### Sheet 1: `products`

UPSERT by `sku`. If SKU exists → update; if new → create.

| Column | Type | Required | Notes |
|--------|------|----------|-------|
| `sku` | string | Yes | Unique identifier. Used as join key across all sheets. |
| `name_en` | string | Yes | Stored as JSON translation `{"en": "..."}` |
| `name_ar` | string | No | Stored as JSON translation `{"ar": "..."}` |
| `description_en` | string | No | |
| `description_ar` | string | No | |
| `price` | float | Yes | |
| `product_type` | string | No | `simple` or `variable` (default: `simple`) |
| `quantity` | integer | No | Mapped to `stock_quantity` |
| `status` | boolean | No | See Boolean Values table |
| `in_stock` | boolean | No | |
| `has_discount` | boolean | No | |
| `discount_type` | string | No | `percentage` or `fixed_rate` |
| `discount_amount` | float | No | |
| `start_date` | date | No | Discount start |
| `end_date` | date | No | Discount end |
| `height` | string | No | |
| `width` | string | No | |
| `length` | string | No | |
| `weight` | string | No | |
| `pieces` | integer | No | |
| `has_flash_sale` | boolean | No | |

### Sheet 2: `product_variants`

One row = one variant. Attributes are provided in a single `attributes` column using a delimited string format.

| Column | Type | Required | Notes |
|--------|------|----------|-------|
| `product_sku` | string | Yes | Links to `products.sku` |
| `variant_sku` | string | No | External reference / export helper only |
| `price` | float | Yes | Used as matching field |
| `sale_price` | float | No | Used as matching field |
| `quantity` | integer | No | Mapped to `stock_quantity` |
| `in_stock` | boolean | No | |
| `height` | string | No | Used as matching field |
| `width` | string | No | Used as matching field |
| `length` | string | No | Used as matching field |
| `weight` | string | No | Used as matching field |
| `attributes` | string | No | Delimited string: `enName|arName:enValue|arValue-enName|arName:enValue|arValue` |

**Note:** `variant_sku` in Excel is only an external reference / export helper. It is **not stored or used as a database lookup key**. Variant matching uses only the existing database fields: `product_id`, `price`, `sale_price`, `height`, `width`, `length`, `weight`. No new columns are added to the schema.

#### Variant Matching Logic

When importing, the system identifies existing variants by the **existing database fields** — no `variant_sku` column is needed.

**Matching fields:** `price`, `sale_price`, `height`, `width`, `length`, `weight`

```
For each variant row in Excel:
  1. Search ProductVariant table using:
     - product_id (resolved from product_sku)
     - price
     - sale_price
     - height
     - width
     - length
     - weight

  2. If match found:
     UPDATE the existing variant with new values

  3. If no match:
     CREATE a new variant
```

**Default behavior:**
- Existing variants matching the above fields are **updated**
- Variants in the database not present in the Excel are **NOT deleted**

This prevents accidental data loss during import.

**Optional sync mode** (can be added later):

Pass `sync_variants=true` to enable full replacement. Only when explicitly enabled:
- Delete variants missing from Excel
- The Excel becomes the **complete set** of variants for a product

#### Attribute Parsing

Attributes are stored in a single `attributes` column. Each attribute group uses a multilingual delimited string:

```
enName|arName:enValue|arValue-enName|arName:enValue|arValue
```

**Parsing rules:**

1. Split the string by `-` to get individual attribute groups:
   ```
   Color|لون:Black|أسود-Size|حجم:L|كبير  →  ["Color|لون:Black|أسود", "Size|حجم:L|كبير"]
   ```

2. Split each group by `:` to get name and value parts:
   ```
   Color|لون:Black|أسود  →  namePart = "Color|لون", valuePart = "Black|أسود"
   ```

3. Split each part by `|` to extract language translations:
   - Name: `en = "Color"`, `ar = "لون"`
   - Value: `en = "Black"`, `ar = "أسود"`

4. Find or create `attributes` record matching both `name->en` and `name->ar`
5. Find or create `attribute_values` record matching both `value->en` and `value->ar` for that attribute
6. Attach via `attribute_product` pivot

**Single-language fallback:** If no `|` separator is present in a part, the entire string is treated as the English (`en`) value. This ensures backward compatibility.

**Example (bilingual):**

Excel cell value:
```
Color|لون:Black|أسود-Size|حجم:L|كبير-Material|خامة:Cotton|قطن
```

Processing steps:
1. Split by `-`: `["Color|لون:Black|أسود", "Size|حجم:L|كبير", "Material|خامة:Cotton|قطن"]`
2. `Color|لون:Black|أسود` → find/create Attribute `{"en":"Color","ar":"لون"}`, then find/create AttributeValue `{"en":"Black","ar":"أسود"}`, attach via pivot
3. `Size|حجم:L|كبير` → find/create Attribute `{"en":"Size","ar":"حجم"}`, then find/create AttributeValue `{"en":"L","ar":"كبير"}`, attach via pivot
4. `Material|خامة:Cotton|قطن` → find/create Attribute `{"en":"Material","ar":"خامة"}`, then find/create AttributeValue `{"en":"Cotton","ar":"قطن"}`, attach via pivot

No changes to the database schema are needed — the existing `attributes`, `attribute_values`, and `attribute_product` tables are used as-is.

### Sheet 3: `images`

Each row represents a single image. Multiple images for the same product use multiple rows. No direct media table inserts.

| Column | Type | Notes |
|--------|------|-------|
| `product_sku` | string | Product to attach images to |
| `image` | string | Single image URL |

**Example:**

| product_sku | image                           |
|-------------|---------------------------------|
| PHONE-001   | `https://example.com/image1.jpg` |
| PHONE-001   | `https://example.com/image2.jpg` |

**Import logic:**
1. Get product by SKU.
2. Read `image` URL.
3. Attach using Spatie: `$product->addMediaFromUrl($imageUrl)->toMediaCollection('products')`

**Export logic:**
1. Collect all product media URLs from Spatie
2. Output one row per image URL

### Sheet 4: `categories`

| Column | Notes |
|--------|-------|
| `product_sku` | Links to `products.sku` |
| `category_slug` | Existing category slug |

`$product->categories()->sync($ids)` — full replacement.

### Sheet 5: `brands`

| Column | Notes |
|--------|-------|
| `product_sku` | Links to `products.sku` |
| `brand_slug` | Existing brand slug |

`$product->brands()->sync($ids)` — full replacement.

### Sheet 6: `flash_sales`

| Column | Notes |
|--------|-------|
| `product_sku` | Links to `products.sku` |
| `flash_sale_slug` | Existing flash sale slug |

`$product->flash_sales()->sync($ids)` — full replacement.

### Sheet 7: `sliders`

| Column | Notes |
|--------|-------|
| `product_sku` | Links to `products.sku` |
| `slider_slug` | Existing slider slug |

`$product->sliders()->sync($ids)` — full replacement.

---

## Boolean Values

| Input (case-insensitive) | Result |
|--------------------------|--------|
| `1`, `true`, `yes`, `publish`, `approved` | `true` |
| `0`, `false`, `no`, empty, anything else | `false` |

---

## Database Relationships

```
products
  |
  | 1:N
  |
product_variants
  |
  | M:N through attribute_product
  |
attribute_values
  |
  | N:1
  |
attributes
```

`attribute_product` is a pivot table:

| Column | References |
|--------|-----------|
| `attribute_value_id` | `attribute_values.id` (cascade delete) |
| `product_variant_id` | `product_variants.id` (cascade delete) |

```
products ──M:N──→ categories (via category_product)
products ──M:N──→ brands     (via brand_product)
products ──M:N──→ flash_sales (via flash_sale_products)
products ──M:N──→ sliders    (via slider_product)
products ──1:N──→ media      (Spatie, collection: "products")
```

No schema changes. No new columns. All existing relations are used as-is.

Do not describe `attribute_product` as 1:N.

---

## Import Transaction Strategy

Each product import operation uses `DB::transaction()`. The flow per product:

```
BEGIN TRANSACTION
  1. Create or update Product (UPSERT by sku)
  2. Create/update/delete ProductVariants
  3. Auto-generate Attribute + AttributeValue + attach via attribute_product
  4. Sync categories (via sync())
  5. Sync brands (via sync())
  6. Sync flash_sales (via sync())
  7. Sync sliders (via sync())
COMMIT

(Images are imported outside the transaction since they involve
external HTTP calls — failure there is non-critical)
```

If any step fails, the entire product transaction rolls back. No partial data is persisted for that product.

**Images note:** Images are imported outside the product transaction because:
- URL downloads can be slow/unreliable
- Image failures should not block the product from being created

---

## Image Handling

Each row represents one image with a single URL:

```
For each image row:
  Get image URL from "image" column
  $product->addMediaFromUrl(trim($imageUrl))->toMediaCollection('products')
```

### Image Source Options

The import request's `images_source` parameter:

| Value | Behavior |
|-------|----------|
| `url` | Downloads images from `image` column URL |
| `none` | Images skipped entirely |

---

## Import Flow

```
User uploads Excel (.xlsx)
  ProductImportController::import()
      → Validates file (mimes:xlsx,xls,ods, max:20MB)
      → Reads images_source (url|none)
      → Creates Import record (status: pending)
      → Dispatches ImportProductsJob (queue: high)
      → Returns 202 with import_id

ImportProductsJob (ShouldQueue, tries: 3, timeout: 3600s)
  Updates Import → status: processing
  Create ProductImportService (shared across all sheets)

  Excel::import() with WithMultipleSheets → in this order:

    Sheet 1: products
      ProductsSheetImport (WithChunkReading: 100)
        For each row: ProductImportService::processProductRow()
          → DB::transaction → UPSERT product by SKU

    Sheet 2: product_variants
      ProductVariantsSheetImport (WithChunkReading: 100)
        For each row: ProductImportService::processVariantRow()
          → Match existing variant by (price, height, width, length, weight)
          → Create/update variant
        → Parse attributes column (enName|arName:enValue|arValue)
           → Attach via attribute_product

    Sheet 3: images
      ImagesSheetImport
        For each row: ProductImportService::processProductImage()
          → Read single image URL from "image" column
          → Attach to Product via addMediaFromUrl()

    Sheet 4: categories
      CategoriesSheetImport
        Group by product_sku → syncCategories() via sync()

    Sheet 5: brands
      BrandsSheetImport
        Group by product_sku → syncBrands() via sync()

    Sheet 6: flash_sales
      FlashSalesSheetImport
        Group by product_sku → syncFlashSales() via sync()

    Sheet 7: sliders
      SlidersSheetImport
        Group by product_sku → syncSliders() via sync()

  Update Import → status: completed|completed_with_errors|failed
```

---

## Export Flow

```
GET /api/v1/products/export
  ProductExportController::export()
      → Read filters from query params
      → Create ProductsExport (WithMultipleSheets)
      → Excel::download() → 7 sheets:

        1. products          → ProductsSheetExport (FromQuery)
        2. product_variants  → ProductVariantsSheetExport (FromQuery + rebuild `attributes` column from pivot)
        3. images            → ImagesSheetExport (FromCollection + one row per image URL)
        4. categories        → CategoriesSheetExport (FromCollection)
        5. brands            → BrandsSheetExport (FromCollection)
        6. flash_sales       → FlashSalesSheetExport (FromCollection)
        7. sliders           → SlidersSheetExport (FromCollection)
```

Export must be import-compatible — the generated file can be re-imported without modification.

---

## Queue Configuration

| Job | Queue | Tries | Timeout | Backoff |
|-----|-------|-------|---------|---------|
| ImportProductsJob | `high` | 3 | 3600s | 60, 120, 240 |
| ExportProductsJob | `default` | 2 | 600s | — |

---

## Error Handling

Errors tracked per-row:

```json
[
  {"sheet": "products", "row": 5, "sku": "PROD-005", "error_message": "..."}
]
```

Stored in `imports.errors` (JSON column).

**Download error report:** `GET /api/v1/products/import/{id}/download-errors`
Returns Excel: `Sheet`, `Row`, `SKU`, `Error Message`.

**Final statuses:**
- `completed` — all rows successful
- `completed_with_errors` — some rows failed
- `failed` — all rows failed (or system error)

---

## Implementation Steps

### Step 1: Create Import Sheet Classes
Create `Imports/Sheets/` directory with 7 classes (one per sheet). Each receives a shared `ProductImportService` instance.

### Step 2: Rewrite ProductsImport
Use `WithMultipleSheets` to route each sheet to its import class.

### Step 3: Rewrite ProductImportService
- `processProductRow()` — UPSERT product by SKU in DB::transaction
- `processVariantRow()` — match variant by `(price, height, width, length, weight)`; create/update; parse `attributes` column (`Name:Value-Name:Value`) and attach via `attribute_product` pivot; all in DB::transaction
- `processImageRow()` — read single `image` column URL, attach to Product via `addMediaFromUrl()` (outside transaction)
- `syncCategories/Brands/FlashSales/Sliders()` — sync relations (inside product transaction)

### Step 4: Update Export
- Remove `AttributesSheetExport` (7 sheets total)
- `ProductVariantsSheetExport` — rebuild `attributes` column (`enName|arName:enValue|arValue`) from `attribute_product` pivot data

### Step 5: Update ImportProductsJob
Pass shared `ProductImportService` to all sheet imports via `WithMultipleSheets`.

### Step 6: Delete Old Files
- Delete `Exports/Sheets/AttributesSheetExport.php`

### Step 7: Update Sample Excel
Regenerate with 7 sheets and `attributes` column in variants sheet.

---

## Attribute Parsing Logic

The importer parses the single `attributes` column. No fixed `attribute_*` columns.

```php
$attributesString = $row['attributes'] ?? '';

if (empty(trim($attributesString))) {
    return;
}

foreach (explode('-', $attributesString) as $group) {
    $group = trim($group);
    if (empty($group)) {
        continue;
    }

    $parts = explode(':', $group, 2);
    if (count($parts) !== 2) {
        continue;
    }

    $namePart = trim($parts[0]);
    $valuePart = trim($parts[1]);

    if (empty($namePart) || empty($valuePart)) {
        continue;
    }

    $nameLanguages = explode('|', $namePart, 2);
    $valueLanguages = explode('|', $valuePart, 2);

    $enName = trim($nameLanguages[0]);
    $arName = trim($nameLanguages[1] ?? '');
    $enValue = trim($valueLanguages[0]);
    $arValue = trim($valueLanguages[1] ?? '');

    if (empty($enName)) {
        continue;
    }

    $attribute = Attribute::where('name->en', $enName)
        ->when($arName, fn($q) => $q->where('name->ar', $arName))
        ->first();

    if (!$attribute) {
        $name = ['en' => $enName];
        if ($arName) {
            $name['ar'] = $arName;
        }
        $attribute = Attribute::create(['name' => $name]);
    }

    $attributeValue = AttributeValue::where('attribute_id', $attribute->id)
        ->where('value->en', $enValue)
        ->when($arValue, fn($q) => $q->where('value->ar', $arValue))
        ->first();

    if (!$attributeValue) {
        $value = ['en' => $enValue];
        if ($arValue) {
            $value['ar'] = $arValue;
        }
        $attributeValue = AttributeValue::create([
            'attribute_id' => $attribute->id,
            'value' => $value,
        ]);
    }

    AttributeProduct::firstOrCreate([
        'product_variant_id' => $variant->id,
        'attribute_value_id' => $attributeValue->id,
    ]);
}
```

## Export Rebuild Logic

When exporting variants, rebuild the `attributes` column from pivot data:

```php
$attributesString = $variant->attributeProducts->map(function ($pivot) {
    $value = $pivot->attributeValue;
    $attribute = $value->attribute;

    $nameTranslations = $attribute->getTranslations('name');
    $valueTranslations = $value->getTranslations('value');

    $enName = $nameTranslations['en'] ?? '';
    $arName = $nameTranslations['ar'] ?? '';
    $enValue = $valueTranslations['en'] ?? '';
    $arValue = $valueTranslations['ar'] ?? '';

    if (empty($enName) || empty($enValue)) {
        return null;
    }

    $namePart = $arName ? "{$enName}|{$arName}" : $enName;
    $valuePart = $arValue ? "{$enValue}|{$arValue}" : $enValue;

    return "{$namePart}:{$valuePart}";
})->filter()->implode('-');
```

Example output: `Color|لون:Black|أسود-Size|حجم:L|كبير`

The exported file is import-compatible — the same `attributes` column can be parsed back into the database without data loss.

---

## Files Summary

### Modified Files
| File | Changes |
|------|---------|
| `Imports/ProductsImport.php` | Rewrite: `WithMultipleSheets` |
| `Services/Import/ProductImportService.php` | Rewrite: variant matching by fields, `attributes` column parsing (`Name:Value-Name:Value`), DB::transaction |
| `Exports/ProductsExport.php` | Remove `AttributesSheetExport` |
| `Exports/Sheets/ProductVariantsSheetExport.php` | Rebuild `attributes` column from pivot instead of `attribute_*` columns |
| `Exports/Sheets/ImagesSheetExport.php` | Rebuild as one row per image URL |
| `Imports/Sheets/ImagesSheetImport.php` | Read single `image` column URL, attach via `addMediaFromUrl()` |
| `Services/Import/ProductImportService.php` | Rename `processProductImages()` → `processProductImage()`, accept single URL |
| `Services/Import/ImageHandlers/UrlImageHandler.php` | Update for single `image` column, remove ZIP references |
| `Jobs/ImportProductsJob.php` | Update for new sheet-based import |

### New Files
| File | Purpose |
|------|---------|
| `Imports/Sheets/ProductsSheetImport.php` | Import products sheet |
| `Imports/Sheets/ProductVariantsSheetImport.php` | Import variants + attributes |
| `Imports/Sheets/ImagesSheetImport.php` | Import images — single `image` column URL per row, attach via `addMediaFromUrl()` |
| `Imports/Sheets/CategoriesSheetImport.php` | Import categories |
| `Imports/Sheets/BrandsSheetImport.php` | Import brands |
| `Imports/Sheets/FlashSalesSheetImport.php` | Import flash sales |
| `Imports/Sheets/SlidersSheetImport.php` | Import sliders |
| `resources/products/product-import-sample.xlsx` | Sample Excel (7 sheets) |

### Deleted Files
| File | Reason |
|------|--------|
| `Exports/Sheets/AttributesSheetExport.php` | No longer needed |
| `Services/Import/ImageHandlers/ZipImageHandler.php` | ZIP mode removed |

### No Changes To
- Database schema — no new tables, columns, or migrations
- Database models — no new fillable fields beyond existing schema
- Routes — already registered
- Controllers — already implemented
- Form requests — already implemented
- Enums — already implemented
- Import model — already implemented
- Image handlers — update UrlImageHandler for new `image` column format
- ProductVariant model — sku auto-generation not used (variants matched by price/dimensions)
