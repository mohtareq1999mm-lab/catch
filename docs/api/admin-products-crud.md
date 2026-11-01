# Endpoint Name

Admin Products — Full Endpoint Surface (umbrella)

# Description

Umbrella/index for every REST endpoint registered in the products management family. The four CRUD verbs have **dedicated per-endpoint documents** (updated to full request/response detail):

| Verb | Per-endpoint document |
| --- | --- |
| `GET /api/v1/products` (list) | `docs/api/get-products.md` |
| `GET /api/v1/products/{product}` (show) | `docs/api/get-products-show.md` |
| `POST /api/v1/products` (create) | `docs/api/post-products.md` |
| `PUT|PATCH /api/v1/products/{product}` (update) | `docs/api/put-products.md` |

This umbrella keeps every OTHER surface of the family:

| # | Surface | Routes | Permission(s) |
| --- | --- | --- | --- |
| A5 | Delete one | 1 | `delete-product` |
| B | Bulk & mass delete | 2 | `delete-all-products\|delete-product` (bulk), `delete-all-products` (all) |
| C | Export | 5 | `export-product` |
| D | Import | 5 | `import-product` |
| E | Digital assets | 7 | `view-products` / `create-product` / `update-product` / `delete-product` (+ `manage-digital-licenses` keys) |
| F | WMS placements read model | 1 | `inventory.product-placements` |

**Explicitly out of this family** (contain "products" in the URL but are different surfaces): storefront `GET /api/v1/general/products*` (+reviews), `/api/v1/general/flash-sale-products*`, `/api/v1/general/brands-products`, `/api/v1/dashboard/products|top-products`, `/api/v1/admin/locations/{location}/products` (WMS composites), `GET /api/v1/product-flash-sale-info`, `digital-entitlements*`, `fast-shipping*`. Every URL/name/action in these documents was resolved with `php artisan route:list --path=products`.

## Shared transport facts (all routes below)

| Property | Value |
| --- | --- |
| Path composition | marvel provider `Route::prefix('api/v1')` wrapping `Rest/Routes.php` (`packages/marvel/src/Providers/RestAPIServiceProvider.php:29-31`) |
| Middleware for A/B/C/D/E | `['auth:sanctum', 'throttle:admin', 'lang']` (`packages/marvel/src/Rest/Routes.php:135`) |
| Middleware for F | `['api', 'auth:sanctum', 'throttle:admin']` + `permission:inventory.product-placements` (`Routes.php:710-712`; NO `lang` middleware — the `lang` header does not switch locale on F) |
| Rate limit | `throttle:admin` — 400/min keyed by `user?->id ?? ip` (`app/Providers/AppServiceProvider.php:133`) |
| Error contract | `app/Exceptions/Handler.php` → `{message, status: false, success: false[, errors]}` for ALL failures; success envelopes from `ApiResponse::apiResponse` → `{status, message, success, data}` (empty `data` omitted) |
| 401 | `Unauthenticated.` / `غير مصرح. يرجى تسجيل الدخول.` (`Handler.php:168-171`) |
| 403 (Spatie) | `User does not have the required permissions.` / `لا يمتلك المستخدم الصلاحيات المطلوبة.` (`PERMISSION_MISSING_PERMISSIONS`; display flags default OFF — no suffix) |
| 422 | `The given data was invalid.` / `البيانات المُدخلة غير صحيحة.` + `errors` (`Handler.php:178-186`) |
| 429 limiter | `Too many requests. Please try again later.` / `طلبات كثيرة جدًا. يرجى المحاولة لاحقًا.` |

---

# A5. `DELETE /api/v1/products/{product}` (`products.destroy`, no per-endpoint doc)

- Route OR-gate + constructor STRICT `delete-product` (`ProductController.php:112`).
- No body. `findOrFail` → `delete()` (per-row PRODUCTS-tag flush, `:510-511`) → **200**: message `Product deleted successfully` (en, no period) / `"تم حذف المنتج بنجاح."` — **no `data` key**.
- Errors: `401` / `403` (Spatie wording) / `404` (`Not found` / `غير موجود` — `MarvelException(NOT_FOUND)` branch, `Handler.php:202-203`) / `429`; unexpected delete failure (e.g. FK constraint) → `500` `Something went wrong.` / `حدث خطأ ما` (`:423-424`).

---

# B. Bulk & mass delete

Registered just above the apiResource (`Routes.php:253-254`) with dedicated permissions — separate surfaces from A5.

## B1. `POST /api/v1/products/bulk-delete` (`destroyBulk`, `ProductController.php:474-505`)

- Route permission: `permission:delete-all-products|delete-product`; constructor adds `delete-product` (`:112`).
- Body (`BulkDeleteProductsRequest`): `ids` **required**, array, `min:1`; `ids.*` required per-row: integer, `min:1`, `distinct`, exists:`products,id`. Optional free-form `reason` (audit field).
- Flow: chunked per-row `delete()` (each row flushes the PRODUCTS tag) + batch audit record (`ActivityAuditService::recordBatch` with `batch_uuid`, `source: api`).
- Success **200**: `Products deleted successfully` / `"تم حذف المنتجات بنجاح"`, `data:

```json
{ "deleted_ids": [3, 9, 12], "batch_uuid": "d81c4f5e-…-e2" }
```

`deleted_ids` echoes the REQUESTED ids (not a recount — may include ids whose rows no longer existed).

## B2. `DELETE /api/v1/products/all` — delete EVERY product (`destroyAll`, `:429-463`) — dangerous action

- Route permission: `permission:delete-all-products` ONLY (constructor `:113`).
- Body: `confirm` boolean required-by-logic — missing/false → **422** message `Confirmation is required to delete all products.` / `"مطلوب تأكيد لحذف جميع المنتجات."` (`apiResponse`, no `errors` key); optional `reason` for the audit trail.
- Flow: pre-count → chunked(100) per-row delete + per-row flush → ONE audit batch (`destroy_all`, `affected_count`).
- Success **200**: `Products deleted successfully` / ar; `data: {deleted_count: 42, batch_uuid: "…"}` — `deleted_count` is the PRE-delete count.
- Errors: `401`/`403`/`422` (confirmation refusal) / `429` / `500` (`Something went wrong.`, `:461`).

---

# C. Export family

All five routes carry `permission:export-product` (route-level `Routes.php:261-262` + constructor-wide `ProductExportController:24-28` with its own `auth:sanctum`). Operations write `import` records (`type = PRODUCT_EXPORT`) and run as async queue jobs (`ExportProductsJob`) with Pusher progress events.

## C1. `GET|POST /api/v1/products/export` — queue an export (`export`, `:64-126`)

Filters (`ProductExportRequest`, verified): `status` (sometimes|boolean), `product_type` (`simple|variable`), `item_type` (`PHYSICAL|DIGITAL`), `category_id` (exists), `brand_id` (exists). Cumbersome direct validator path returns a slightly different 422 — `{success:false, message:"Validation failed", errors:{…}}` (literal English, `:69-74`).

Header `Idempotency-Key` (or `X-Idempotency-Key`) replays the ORIGINAL export for 24 h (per-user, file cache `:77-103`).

Responses: queued → **202** `Export started successfully` / `"بدأ التصدير بنجاح"`, `data: {export_id, status:"pending"}`; cache hit → **202** with the EXISTING id.

## C2. `GET /api/v1/products/export/{id}` — status (`status`, `:128-166`)

Ownership: non-super-admin confined to own records (`created_by` filter) + policy `view`. No-cache headers forced. Success **200** — message key reuses the BRAND export wording: `Brand export status fetched successfully.` / `"تم جلب حالة تصدير العلامات التجارية بنجاح."` (message.php:292/344 — intentional reuse, not a bug). `data`:

| Field | Meaning |
| --- | --- |
| `id` | Export id |
| `status` | `pending`/`processing`/`cancelling`/`completed`/`completed_with_errors`/`failed`/`cancelled` |
| `total_rows`, `processed_rows`, `successful_rows`, `failed_rows` | Counters |
| `errors` / `error_count` | Stored row errors + count |
| `created_at` / `completed_at` | ISO-8601 (completed_at = `updated_at` once terminal, else null) |

## C3. `GET /api/v1/products/export/{id}/download` (`download`, `:168-192`)

Ownership + policy `download`; requires `status = completed` + stored file → binary `.xlsx` (`Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`). Not ready → **409** `Export file is not ready yet` / `"ملف التصدير غير جاهز بعد"`.

## C4. `POST /api/v1/products/export/{id}/cancel` (`cancel`, `:194-250`)

Ownership + policy `cancel`. Terminal → **409** `Import cannot be cancelled in its current state` / `"لا يمكن إلغاء الاستيراد في حالته الحالية"` (shared import key — verified). Cancels via signal file + conditional status update (`pending|processing → cancelled`) + terminal broadcast. Success **200**: `Import cancelled successfully` / `"تم إلغاء الاستيراد بنجاح"`, `data: {export_id, status:"cancelled"}`.

---

# D. Import family

All five routes carry `permission:import-product` (route + constructor `ProductImportController:25-29`).

## D1. `GET /api/v1/products/import/sample` (`downloadSample`, `:397-414`)

Binary `.xlsx` (`product-import-sample.xlsx`) from `config('marvel.import.samples.product')`; missing file → **404** `Sample file not found` / `"ملف العينة غير موجود"`. Not JSON.

## D2. `POST /api/v1/products/import` (`import`, `:66-173`)

Body (`ProductImportRequest`): `file` **required**, `mimes:xlsx,xls,ods`, `max:20480` KB (20 MB). Translated per-field messages: `An Excel file is required` / `ملف Excel مطلوب`; `The file must be a valid Excel file (xlsx, xls, or ods)` / `يجب أن يكون الملف بصيغة Excel صالحة (xlsx, xls, or ods)`; `The file size must not exceed 20 MB` / `حجم الملف يجب ألا يتجاوز 20 ميغابايت`.

Dedupe (code-verified): (1) `Idempotency-Key` header + lock — 24 h per-user replay; (2) same-user + same-file sha256 within 10 min while a pending/processing import exists → same 202 replay.

Success → **202** `Import started successfully` / `"بدأ الاستيراد بنجاح"`, `data: {import_id, status:"pending"}`.

## D3. `GET /api/v1/products/import/{id}` — status + progress (`status`, `:207-280`)

Ownership + policy `view`; no-cache headers. Progress: signal-file counters CLAMPED to `total_rows`; honest derived progress (`processed/total × 99`) during processing; 100 on terminal success. Invariant enforcement comment `:239-257`.

Success **200** `Import status fetched successfully` / `"تم جلب حالة الاستيراد"`. `data` fields: `id`, `status` (`pending`/`processing`/`cancelling`/`completed`/`completed_with_errors`/`failed`/`cancelled`), `total_rows`, `processed_rows`, **both `successful_rows` AND `success_rows` (compat pair, `:269-270`)**, `failed_rows`, `progress` (float 0–100), `errors` (`{sheet, row, sku, error_message}` rows), `error_count`.

## D4. `POST /api/v1/products/import/{id}/cancel` (`cancel`, `:336-395`)

Identical contract to C4 (terminal → 409; success → 200 `Import cancelled successfully` / ar, `data: {import_id, status:"cancelled"}`).

## D5. `GET /api/v1/products/import/{id}/download-errors` (`downloadErrors`, `:282-334`)

Ownership + policy `view`. No errors → **404** `No errors found` / `"لم يتم العثور على أخطاء"`. Errors → temp `failed_import_rows_{id}.xlsx` streamed (`Sheet, Row, SKU, Error Message` columns), deleted after send. Not JSON.

---

# E. Digital assets (product files / URL / LICENSE / ACCESS)

Registry-driven validation (`app/Services/Digital/AssetTypeRegistry` + `config/digital.php`): ACTIVE upload surface = **PDF only** (`active_extensions: ['pdf']`), global cap `max_upload_kb` = 20480 KB; all four types enabled by default (`FILE`, `URL`, `LICENSE`, `ACCESS` — `App\Enums\DigitalAssetType`).

Route + constructor gate the same permission on E1–E6 (`view-products` read E1/E3, `create-product` store E2, `update-product` update/replace E4/E6, `delete-product` destroy E5 — `Routes.php:270-275`, constructor `DigitalAssetController:27-32`).

## E1. `GET /api/v1/products/{product}/digital-assets` (`index`, `:45-54`)

`{product}` numeric (`whereNumber`); full collection ordered `sort_order, id` (no pagination). Success **200** `Data fetched successfully` / ar; `data` = `DigitalAssetResource` rows. Unknown product id → plain empty collection (no 404).

## E2. `POST /api/v1/products/{product}/digital-assets` (`store`, `:56-81`)

`DigitalAssetCreateRequest` (registry-driven): `type` ∈ creatable types (default `FILE`); FILE → `file` required (`pdf`, ≤20 MB); URL → `file` prohibited + `external_url` required (≤2048); ACCESS → `file` prohibited + `secret` required (≤2000); `original_name` ≤255, `sort_order` int ≥0. Product must be `item_type=DIGITAL` → else **422** `The uploaded file must be a valid PDF document.` / `"يجب أن يكون الملف المرفوع مستند PDF صالحاً."` (`ERROR.DIGITAL_ASSET_INVALID_FILE` — the same key doubles as the non-digital refusal). LICENSE type is not creatable here (keys via E7). Success **201** `Data created successfully` / `"تم إنشاء البيانات بنجاح"`.

## E3. `GET /api/v1/digital-assets/{uuid}` (`show`, `:38-43`)

UUID lookup (`whereUuid`); unknown → 404 (ModelNotFound branch → `Resource Not Found` / `المورد غير مورد» see below` — actual string `المورد غير موجود.`).

## E4. `PUT /api/v1/digital-assets/{uuid}` (`update`, `:103-110`)

`DigitalAssetUpdateRequest`: `original_name` (sometimes|required ≤255), `display_name` (sometimes|nullable ≤255), `sort_order` (int ≥0), `status` (`active|inactive` — admin-settable ONLY between these; `revoked`/`expired` system-reserved), `metadata` (sometimes|nullable array ≤20, values ≤1000). **File bytes IMMUTABLE — replacement is E6.** Success **200** `Data updated successfully` / `"تم تحديث البيانات بنجاح"`.

## E5. `DELETE /api/v1/digital-assets/{uuid}` (`destroy`, `:127-133`)

No body. Success **200** `Data deleted successfully` / `"تم حذف البيانات بنجاح"`, no `data` key.

## E6. `POST /api/v1/digital-assets/{uuid}/replace` (`replace`, `:118-125`) — FILE assets only

`ReplaceDigitalAssetRequest`: `file` required (registry extensions/size), `display_name` optional ≤255. Non-FILE asset → field `file` validation error `Only file assets can be replaced.` / `"يمكن استبدال أصول الملفات فقط."` (`:37-38`). Success **200** (same UPDATE_DATA envelope) — uuid/metadata survive; old file removed only after new state committed.

## E7. `POST /api/v1/digital-assets/{uuid}/license-keys` (`storeLicenseKeys`, `:87-101`) — LICENSE provisioning

Extra gate: `permission:manage-digital-licenses` (route `Routes.php:281` + constructor `:31`). Body (`StoreLicenseKeysRequest`): `keys` required array (`min:1`, `max:500` — `config('digital.licenses.max_batch_keys')`), `keys.*` string 1–2000. Intra-batch duplicates rejected in validation; pool duplicates SKIPPED with counts. Success **201** `Data created successfully`, `data: {asset_uuid, created, skipped_blank, skipped_duplicate}`. Plaintext keys NEVER return.

## DigitalAssetResource row (`packages/marvel/src/Http/Resources/DigitalAssetResource.php:9-34`)

| Field | Type | Presence |
| --- | --- | --- |
| `id` | int | Always |
| `uuid` | string | Always (lookup key E3–E7) |
| `type` | string | `FILE`/`URL`/`LICENSE`/`ACCESS` |
| `original_name`, `display_name` | string/null | Always |
| `mime` | string | — |
| `size` | int | Bytes |
| `sort_order` | int | — |
| `external_url` | string | ONLY for `type=URL` — storage internals and LICENSE/ACCESS secrets NEVER serialized |
| `created_at`, `updated_at` | ISO-8601 \| null | — |

Baseline errors for E: `401`/`403` (Spatie) / `404` (unknown uuid → `Resource Not Found` / `المورد غير موجود.`, `Handler.php:107-109`) / `422` / `429`; service crash on store → `500` `Something went wrong.` (`:78-79`).

---

# F. WMS multi-location read model

## GET `/api/v1/admin/products/{product}/placements` (`api.admin.products.placements`)

`Routes.php:710-712` — dedicated `admin/products` prefix group (NO `lang` middleware): `['api','auth:sanctum','throttle:admin']` + `permission:inventory.product-placements`; `{product}` numeric.

Controller `App\Http\Controllers\Api\Admin\Wms\InventoryController::productPlacements` (`:169-223`).

Query parameters:

| Field | Behavior |
| --- | --- |
| `warehouse_id` | `manage-warehouse` holders may narrow; foreign scope → 403 (anti-enumeration `authorizeWarehouseScope(…, 'view-location', true)`). Warehouse-scoped users confined to own warehouse. |
| `product_variant_id` | Narrows to one SKU. Unknown variant → **404** `Product variant not found.` / `"متغير المنتج غير موجود."`; variant of another product → **422** `Variant #:variant does not belong to product #:product.` / `"المتغير #:variant لا ينتمي إلى المنتج #:product."` |

Success **200**: `Product placements fetched successfully.` / `"تم جلب تموضعات المنتج بنجاح."` (`wms.php:240/237`). `data` = `ProductPlacementsResource` (`app/Http/Resources/Wms/ProductPlacementsResource.php:25-72`):

```json
{
  "status": 200,
  "message": "Product placements fetched successfully.",
  "success": true,
  "data": {
    "product": {
      "id": 42, "sku": "WGT-001", "product_type": "variable", "has_variants": true,
      "stock_quantity": 0, "reserved_quantity": 0, "available_quantity": 0, "in_stock": false,
      "variants": [
        { "id": 88, "sku": "WGT-001-RED", "stock_quantity": 5, "reserved_quantity": 1,
          "available_quantity": 4, "in_stock": true,
          "attributes": [ { "attribute": "Color", "value": "Red" } ] }
      ]
    },
    "placements": [
      { "id": 71, "product_id": 42, "product_variant_id": 88, "sku": "WGT-001-RED",
        "variant_attributes": [ { "attribute": "Color", "value": "Red" } ],
        "location_id": 3, "location_code": "A-01", "warehouse_id": 1,
        "quantity": 12.0, "allocated_hint": 0, "allocated_hint_note": "informational-only" }
    ]
  }
}
```

Semantics IN the payload: stock is SKU-level (simple → `products.stock_quantity`; variant → `product_variants.stock_quantity`; variable parents with variants NOT summed); `placements[]` are **non-authoritative hints** and `allocated_hint` is `informational-only` (reservations live on authority rows via `OrderReservationService`).

Errors: `401`/`403` (Spatie; no `lang` middleware → app default locale governs message wording) / `404` unknown product (`Product not found.` / `"المنتج غير موضوع"`) or variant / `422` variant mismatch / `429`.

---

# Invariants & notes (cross-surface)

- **Authorization ladders**: CRUD has route-OR + constructor-strict double gates; B uses `delete-all-products` (plus `delete-product` OR for bulk); C/D single-permission (`export-product`/`import-product`) enforced in route AND constructor; E7 needs `manage-digital-licenses`; F needs `inventory.product-placements` (+ `view-location` when narrowing scope).
- **Cache coherence**: A/B family flushes `FrontendResource::PRODUCTS` per mutation (per-row during chunked deletes). C/D/E mutations do NOT touch the products tag (no catalog row changes).
- **Async file operations**: C/D are queue jobs with broadcast lifecycle events; cancellation is cooperative (signal file + conditional update) — a job may still finish terminal; 409 covers races.
- **Idempotency**: C1/D2 `Idempotency-Key` (24 h, per-user); D2 also dedupes by file sha256 for 10 min while pending/processing; both replay **202 with the SAME id**.
- **Ownership scoping**: C2–C4/D3–D5 confine non-super-admins to own operations (DB `created_by` + policy).
- **Secrets**: none stored or returned — license endpoint returns counts only.
- **Message-key reuse honesty**: `BRAND_EXPORT_STATUS_FETCHED` reused by the product export status; import cancel messages shared across C4/D4 — strings are the ACTUAL ones served.
- **In-flight product work**: barcode/WMS placement rules are uncommitted work of a parallel session; documents reflect the CURRENT working tree (2026-11-01) — re-verify when that session lands.

# Verification source

`php artisan route:list --path=products`; `packages/marvel/src/Rest/Routes.php` (:253-281, :710-712, :135); `RestAPIServiceProvider.php:29-31`; `Marvel\Http\Controllers\ProductController.php` (:97-515); `ProductExportController.php` (:24-251); `ProductImportController.php` (:25-415); `DigitalAssetController.php` (:24-134); `app/Http/Controllers/Api/Admin/Wms/InventoryController.php` (:169-223); FormRequests: ProductCreate/Update, BulkDeleteProducts, ProductExport, ProductImport, DigitalAssetCreate/Update, ReplaceDigitalAsset, StoreLicenseKeys; resources: product/ProductCollection, product/ProductResource, DigitalAssetResource, Wms/ProductPlacementsResource; `app/Exceptions/Handler.php` (:105-237, :303-358); `packages/marvel/config/constants.php`; `app/Services/Digital/AssetTypeRegistry.php` + `config/digital.php`; `packages/marvel/config/permission.php`; `resources/lang/en|ar/message.php` + `resources/lang/en|ar/wms.php`.
