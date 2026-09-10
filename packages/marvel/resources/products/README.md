# Product Import System — Full Flow

## Table of Contents

1. [Overview](#1-overview)
2. [The `imports` Database Table](#2-the-imports-database-table)
3. [Endpoints — User Story](#3-endpoints--user-story)
   - [POST /api/v1/products/import — Start an Import](#31-post-apiv1productsimport--start-an-import)
   - [GET /api/v1/products/import/{id} — Check Status](#32-get-apiv1productsimportid--check-import-status)
   - [GET /api/v1/products/import/{id}/download-errors — Download Error Report](#33-get-apiv1productsimportiddownload-errors--download-error-report)
4. [Backend Flow — How It Runs](#4-backend-flow--how-it-runs-detailed)
   - [Products Sheet](#41-products-sheet-processing-processproductrow)
   - [Variants Sheet](#42-variants-sheet-processing-processvariantrow)
   - [Images Sheet](#43-images-sheet-processing-processproductimage)
   - [Relations Sheets](#44-relations-sheets-categories-brands-flash_sales-sliders)
   - [Finalize Variants](#45-finalize-variants-finalizevariants)
5. [Queue Configuration](#5-queue-configuration)
6. [Frontend Integration Summary](#6-frontend-integration-summary)
7. [Error Handling](#7-error-handling)
8. [Testing](#8-testing)
9. [Troubleshooting](#9-troubleshooting)

---

## 1. Overview

The import feature lets an admin upload an Excel file (`.xlsx`, `.xls`, `.ods`) with **7 sheets** to bulk create/update products, variants, images, categories, brands, flash sales, and sliders. The import runs **asynchronously** via a Laravel queue job so the admin can continue working while the import processes.

**Files involved:**

| Layer | File |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/ProductImportController.php` |
| Request | `packages/marvel/src/Http/Requests/ProductImportRequest.php` |
| Job (Queue) | `packages/marvel/src/Jobs/ImportProductsJob.php` |
| Service | `packages/marvel/src/Services/Import/ProductImportService.php` |
| Excel Imports | `packages/marvel/src/Imports/ProductsImport.php` + 7 Sheet classes |
| Model | `packages/marvel/src/Database/Models/Import.php` |
| Enum | `packages/marvel/src/Enums/ImportStatus.php` |
| Migration | `database/migrations/2026_06_27_000001_create_imports_table.php` |

---

## 2. The `imports` Database Table

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Auto-increment |
| `type` | string | `'product'` (reserved for future types) |
| `file_path` | string | Path in `storage/app/public/imports/` |
| `file_name` | string | Original uploaded filename |
| `status` | string | `pending` → `processing` → `completed` / `completed_with_errors` / `failed` |
| `total_rows` | int | Total rows processed |
| `processed_rows` | int | Rows attempted |
| `success_rows` | int | Rows that succeeded |
| `failed_rows` | int | Rows that failed |
| `errors` | json | Array of `{sheet, row, sku, error_message}` |
| `created_by` | FK | → `users.id` |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

---

## 3. Endpoints — User Story

### 3.1. POST `/api/v1/products/import` — Start an Import

**Who:** Super Admin

**What they do:** Upload an Excel file via a form/modal in the dashboard.

**Request:**
```
POST /api/v1/products/import
Content-Type: multipart/form-data

file: products.xlsx   (required, max 20MB, types: xlsx/xls/ods)
```

**What happens immediately (synchronous):**
1. Validates the file (required, xlsx/xls/ods, max 20MB)
2. Stores the file in `storage/app/public/imports/`
3. Creates a new row in the `imports` table with `status = pending`
4. Dispatches `ImportProductsJob` to the `high` queue
5. Returns HTTP **202 Accepted**:

```json
{
    "success": true,
    "message": "Import started successfully",
    "data": {
        "import_id": 1,
        "status": "pending"
    }
}
```

**Frontend behavior:**
- Show a success toast: "Import started"
- Redirect to an **import status page** (or show a progress component)
- Start **polling** `GET /api/v1/products/import/{id}` every 2-3 seconds

---

### 3.2. GET `/api/v1/products/import/{id}` — Check Import Status

**Who:** Super Admin

**What they do:** Visit the import status page or the frontend polls automatically.

**Response:**
```json
{
    "success": true,
    "message": "Import status fetched",
    "data": {
        "id": 1,
        "status": "processing",
        "total_rows": 100,
        "processed_rows": 45,
        "success_rows": 40,
        "failed_rows": 5,
        "progress": 45,
        "errors": [
            {"sheet": "products", "row": 12, "sku": "PRD-023", "error_message": "Invalid price"}
        ]
    }
}
```

**Status values & what they mean:**

| Status | Meaning | Frontend Action |
|--------|---------|-----------------|
| `pending` | Job not picked up yet | Keep polling |
| `processing` | Job is actively processing rows | Show progress bar |
| `completed` | All rows succeeded | Show success message, stop polling |
| `completed_with_errors` | Some rows failed | Show warning + "Download Errors" button |
| `failed` | Everything failed or system error | Show error message, stop polling |

**Frontend behavior:**
- Show a **progress bar**: `progress` field (0-100%)
- Show counters: `success_rows ✓`, `failed_rows ✗`
- If `status` is `completed` → green success banner
- If `status` is `completed_with_errors` → yellow warning + **"Download Error Report"** button
- If `status` is `failed` → red error banner
- Display errors inline if few, or link to download full report

**Progress calculation:**
```
progress = round((processed_rows / total_rows) * 100, 2)
```
Minimum 0, maximum 100.

**Polling code example (JavaScript):**
```js
async function pollImportStatus(importId) {
    const poll = async () => {
        const res = await fetch(`/api/v1/products/import/${importId}`);
        const data = await res.json();
        
        updateProgressBar(data.data.progress);
        updateCounters(data.data.success_rows, data.data.failed_rows);
        
        if (['completed', 'completed_with_errors', 'failed'].includes(data.data.status)) {
            stopPolling();
            if (data.data.status === 'completed_with_errors') {
                showDownloadErrorsButton(importId);
            }
        }
    };
    
    const interval = setInterval(poll, 3000);
    poll(); // immediate first call
}
```

---

### 3.3. GET `/api/v1/products/import/{id}/download-errors` — Download Error Report

**Who:** Super Admin

**What they do:** Click the "Download Error Report" button.

**What happens:**
1. Finds the import record
2. If no errors exist → returns **404** with message "No errors to download"
3. Generates an Excel file on-the-fly with columns: `Sheet`, `Row`, `SKU`, `Error Message`
4. Returns the file as a download, then **deletes it from the server**

**Frontend behavior:**
- Trigger a file download
- The browser will save `failed_import_rows_{id}.xlsx`
- Open in Excel to see which rows failed and why

---

## 4. Backend Flow — How It Runs (Detailed)

### Step-by-step execution within `ImportProductsJob::handle()`:

```
Upload (Controller)
  │
  ▼
Store file, create import record (status=pending)
  │
  ▼
Dispatch ImportProductsJob to 'high' queue
  │
  ▼
[Queue Worker picks up the job]
  │
  ▼
Update status = 'processing'
  │
  ▼
Read file from storage
  │
  ▼
Create ProductImportService instance (shared across all sheets)
  │
  ▼
Run Excel::import() which processes 7 sheets IN ORDER:
  │
  ├── 1. products        → ProductImportService.processProductRow()
  ├── 2. product_variants → ProductImportService.processVariantRow()
  ├── 3. images           → ProductImportService.processProductImage()
  ├── 4. categories       → ProductImportService.syncCategories()
  ├── 5. brands           → ProductImportService.syncBrands()
  ├── 6. flash_sales      → ProductImportService.syncFlashSales()
  └── 7. sliders          → ProductImportService.syncSliders()
  │
  ▼
Collect success/failure counts from service
  │
  ▼
Update import record: status, total_rows, processed_rows, etc.
```

### 4.1. Products Sheet Processing (`processProductRow`)

For each row in the `products` sheet:

```
Row data (sku, name_en, price, ...)
  │
  ├── Look up product by SKU in DB
  │
  ├── If EXISTS → fill() + saveQuietly() (update)
  │
  └── If NEW → new Product() + saveQuietly() (create)
         │
         └── SKU auto-generated if empty: 'PRD-' . uuid
  │
  ▼
Calculate pricing via ProductPricingService (price_after_discount, price_after_flash_sale)
  │
  ▼
fill() + saveQuietly() with computed prices
  │
  ▼
DB::commit()
  │
  └── On error → DB::rollBack() → log error → record in failedRows[]
```

**Key design decisions:**
- Uses `saveQuietly()` instead of `save()` to prevent Laravel Scout from trying to sync to Algolia (which isn't installed). Also prevents all model events from firing.
- Each row is wrapped in its own DB transaction so one failed row doesn't block others.
- Pricing is calculated via `ProductPricingService` — same service used by the admin panel, no duplicated logic.

### 4.2. Variants Sheet Processing (`processVariantRow`)

```
Row data (product_sku, price, attributes, ...)
  │
  ├── Find parent product by product_sku
  ├── If not found → record error, skip
  │
  ▼
Find existing variant by matching (product_id, price, sale_price, dimensions)
  │
  ├── If EXISTS → fill() + saveQuietly() → delete old attribute relations
  │
  └── If NEW → new ProductVariant() + saveQuietly()
  │
  ▼
Attach attributes (auto-create Attribute + AttributeValue if needed)
  │
  ▼
Mark parent product as product_type = 'variable', saveQuietly()
  │
  ▼
DB::commit()
```

**Attributes format in Excel:**
```
Color|اللون:Red|احمر-Size|المقاس:L|كبير
```
Split by `-` → groups, split by `:` → name:value, split by `|` → en|ar translations.

### 4.3. Images Sheet Processing (`processProductImage`)

```
Row (product_sku, image_url)
  │
  ├── Find product by SKU → if not found, log warning and skip
  │
  ▼
Validate URL (must be public, non-private IP)
  │
  ▼
Normalize Google Drive URLs (if applicable):
  /file/d/FILE_ID/view → /uc?export=download&id=FILE_ID
  │
  ▼
Download image (max 5MB, timeout 30s) via UrlImageHandler
  │
  ├── Validate MIME type (jpeg, png, webp, gif, svg)
  │
  ▼
Save to storage/app/temp/
  │
  ▼
Attach to product via Spatie Media Library → toMediaCollection('products')
  │
  ▼
Cleanup temp file
```

### 4.4. Relations Sheets (categories, brands, flash_sales, sliders)

Each follows the same pattern:
```
Row (product_sku, slug)
  │
  ├── Find product by SKU → if not found, log warning and skip
  │
  ▼
Find related entity by slug
  │
  ▼
sync() the pivot table (replaces ALL existing relations)
```

### 4.5. Finalize Variants (`finalizeVariants`)

After all variant rows are processed:
- For each product that had variants in the Excel
- Delete any variant in the database that was NOT in the Excel
- This makes the Excel the "source of truth" for variants

---

## 5. Queue Configuration

| Setting | Value |
|---------|-------|
| Queue | `high` |
| Max retries | 3 |
| Backoff | 60s, 120s, 240s |
| Timeout | 3600s (1 hour) |

**To run the queue worker:**
```bash
php artisan queue:work --queue=high,default
```

If the `.env` has `QUEUE_CONNECTION=sync`, the job runs **synchronously** (immediately, no worker needed) — useful for development and testing.

---

## 6. Frontend Integration Summary

```
┌─────────────────────────────────────────────────────────┐
│  Dashboard — Import Page                                 │
│                                                          │
│  [Upload Excel File] ───► POST /api/v1/products/import   │
│         │                                                 │
│         ▼                                                 │
│  Response: { import_id: 1, status: "pending" }            │
│         │                                                 │
│         ▼                                                 │
│  Start polling every 3s:                                  │
│  GET /api/v1/products/import/1                           │
│         │                                                 │
│         ▼                                                 │
│  ┌─────────────────────────────────────┐                  │
│  │  Progress: ████████████░░░ 75%      │                  │
│  │  ✓ 75 succeeded                     │                  │
│  │  ✗ 5 failed                        │                  │
│  │                                     │                  │
│  │  [Download Error Report] ───► GET   │                  │
│  │   (only when completed_with_errors) │                  │
│  └─────────────────────────────────────┘                  │
│                                                          │
│  When status = "completed": green success banner          │
│  When status = "completed_with_errors": yellow warning    │
│  When status = "failed": red error banner                │
└─────────────────────────────────────────────────────────┘
```

---

## 7. Error Handling

**Per-row errors** are caught in `processProductRow` / `processVariantRow` and stored as:
```json
{
    "sheet": "products",
    "row": 12,
    "sku": "PRD-023",
    "error_message": "Invalid price value"
}
```

**System-level errors** (file not found, Excel parse error, etc.) are caught in the Job and stored as:
```json
{
    "sheet": "system",
    "row": 0,
    "sku": "",
    "error_message": "File format not supported"
}
```

**Image download failures** are logged as warnings (not stored in import errors).

**Relation sync failures** (categories, brands, etc.) are logged as warnings.

---

## 8. Testing

Run the import feature tests:
```bash
php artisan test tests/Feature/ProductImportTest.php
```

Tests cover:
- Unauthenticated access (401)
- File validation (422)
- Job dispatch (202)
- Status fetching (200)
- Error download (200)
- 404 on non-existent import
- 404 when no errors to download
- Product creation via `ProductImportService::processProductRow()`
- Product update via SKU upsert
- Empty SKU auto-generation
- Service success/failure counters

---

## 9. Troubleshooting

| Symptom | Likely Cause | Fix |
|---------|-------------|-----|
| Import never progresses past "pending" | Queue worker not running | Run `php artisan queue:work` or check `QUEUE_CONNECTION` in `.env` |
| All rows fail with "Algolia client" error | Scout driver set to `algolia` but package not installed | Set `SCOUT_DRIVER=database` or `SCOUT_DRIVER=collection` in `.env` |
| Products not found by SKU in later sheets | Product creation failed silently | Check `failed_rows` in import status; check `storage/logs/laravel.log` |
| Image import fails | URL not publicly accessible, or MIME type not allowed | Verify URL is public and points to jpeg/png/webp/gif/svg |
| File too large | Excel > 20MB | Split file or increase `max:20480` in `ProductImportRequest.php` |
| Unexpected column names | Excel headers don't match expected format | Download the sample file and follow its structure |
