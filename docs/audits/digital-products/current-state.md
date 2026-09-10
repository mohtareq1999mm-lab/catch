# Digital Products — Current-State Audit (Workstream 1)

- **Date:** 2026-08-24
- **Mode:** Read-only discovery. No application behavior was modified. No migrations, classes, routes, permissions, or translations were changed.
- **Verification method:** Every claim below was verified by direct file reads or targeted searches in this working tree. Claims that could not be reproduced are explicitly marked (see §10, G9).
- **Companion documents:** `architecture-gaps.md`, `error-ledger.md`, `master-todo.md`

---

## 1. Files Inspected

### Routes
| File | Relevance |
|---|---|
| `packages/marvel/src/Rest/Routes.php` | Admin digital-asset routes L218–222 inside admin group L115 |
| `routes/api.php` | Customer entitlement list L128; signed download route L155–161 |
| `packages/marvel/src/Providers/RestAPIServiceProvider.php` | Mounts package routes under `api/v1` |

### Controllers
| File | Relevance |
|---|---|
| `packages/marvel/src/Http/Controllers/DigitalAssetController.php` | Admin CRUD (read fully) |
| `app/Http/Controllers/Api/General/DigitalDownloadController.php` | Customer entitlement list + signed download (read fully, 158 lines) |

### Requests / Resources
| File | Relevance |
|---|---|
| `packages/marvel/src/Http/Requests/DigitalAssetCreateRequest.php` | Upload validation rules (read fully) |
| `packages/marvel/src/Http/Requests/DigitalAssetUpdateRequest.php` | Metadata update rules |
| `packages/marvel/src/Http/Resources/DigitalAssetResource.php` | Public asset contract (read fully) |
| `app/Http/Resources/Order/OrderResource.php` | `digital_downloads` exposure L61, resolver L79+ |

### Services / Policies
| File | Relevance |
|---|---|
| `app/Services/Digital/DigitalAssetService.php` | Store/update/delete + upload validation (read fully — defect evidence) |
| `app/Services/Digital/DigitalFulfillmentService.php` | Fulfillment + revoke (read fully) |
| `app/Services/Digital/ItemTypePolicy.php` | D5 item_type immutability (read fully) |

### Models
| File | Relevance |
|---|---|
| `app/Models/DigitalAsset.php` | Type constants, `$hidden = [path, disk]`, UUID boot |
| `app/Models/DigitalEntitlement.php` | Statuses, limit defaults, `currentAssets()` BD1 Option B (read fully) |
| `app/Models/DigitalDownloadLog.php` | Hashed IP/UA audit rows |
| `packages/marvel/src/Database/Models/Product.php` | `digitalAssets()` HasMany L455+, `item_type` fillable |
| `packages/marvel/src/Database/Models/Order.php` | `digitalEntitlements()` HasMany L135+ |
| `packages/marvel/src/Database/Models/OrderProduct.php` | `item_type` snapshot fillable |

### Migrations
| File | Table/change |
|---|---|
| `2026_08_23_105834_add_item_type_to_products_table.php` | products.item_type ENUM + index |
| `2026_08_23_120000_shrink_products_item_type_enum.php` | Enum shrunk to PHYSICAL\|DIGITAL |
| `2026_08_23_120100_add_item_type_to_order_products_table.php` | Snapshot column + `(order_id,item_type)` index |
| `2026_08_23_120200_create_digital_assets_table.php` | `digital_assets` (read fully) |
| `2026_08_23_120300_create_digital_entitlements_table.php` | `digital_entitlements` (read fully) |
| `2026_08_23_120400_create_digital_asset_entitlement_pivot_table.php` | Pivot w/ UNIQUE pair |
| `2026_08_23_120500_create_digital_download_logs_table.php` | Download audit log |
| `2026_08_23_130000_make_orders_address_nullable.php` | D4 digital-only checkout |

### Events / Listeners / Notifications / Wiring
| File | Relevance |
|---|---|
| `app/Providers/EventServiceProvider.php` L135–143 | `PaymentSucceeded → …→ FulfillDigitalProducts`; `DigitalProductsDelivered → SendUserDigitalProductsAvailableNotification` |
| `packages/marvel/src/Providers/EventServiceProvider.php` L108–113 | `RefundApproved → RevokePendingDigitalEntitlements` (+ refund listeners) |
| `app/Listeners/FulfillDigitalProducts.php` | Queued `meem-high`, afterCommit, rethrow-retry, `failed()` admin alert (read fully) |
| `app/Listeners/RevokePendingDigitalEntitlements.php` | Queued `meem-medium`, afterCommit, PENDING-only revoke (read fully) |
| `app/Listeners/SendUserDigitalProductsAvailableNotification.php` | Delivery notification listener |
| `app/Notifications/UserDigitalProductsAvailableNotification.php` | Bilingual database/fcm/broadcast |
| `app/Notifications/AdminDigitalDeliveryFailedNotification.php` | Failure alert |
| `packages/marvel/src/Database/Repositories/RefundRepository.php` | D7 guard: delivered digitals block refund approval |
| `packages/marvel/src/Database/Repositories/ProductRepository.php` | ItemTypePolicy hook on product update |

### Config / Permissions / Translations
| File | Relevance |
|---|---|
| `config/digital.php` | Full config read — PDF-only MVP |
| `.env.example` | Searched for `DIGITAL_*` — **zero matches** (G8) |
| `packages/marvel/src/Enums/Permission.php` | L37/L145/L146 product permissions reused |
| `database/seeders/PermissionSeeder.php` | Product permissions present in role buckets |
| `resources/lang/en/message.php` L484–492 | 9 digital/ITEM_TYPE keys |
| `resources/lang/ar/message.php` L289–297 | Same 9 keys — **present** (valid UTF-8) |
| `resources/lang/de/message.php` L7–15 | Same 9 keys |
| `resources/lang/{en,ar}/notifications.php` | `digital.available.*`, `admin.digital_delivery_failed.*` |

### Tests
| File | Coverage area |
|---|---|
| `tests/Feature/Digital/DigitalFulfillmentTest.php` (~567 lines) | Fulfillment idempotency, mixed carts, queues, notifications, OrderResource |
| `tests/Feature/Digital/DigitalDownloadSecurityTest.php` (~461 lines) | Signed URLs, limits/race, IDOR, tamper, revoke, credit preservation, throttle |
| `tests/Feature/Digital/DigitalCartCheckoutTest.php` | Digital cart/inventory/shipping/COD-cashier |
| `tests/Feature/Digital/DigitalAssetAdminTest.php` (~207 lines) | Admin CRUD authz, MIME/size rejection, physical-product rejection, delete cleanup |
| `tests/Feature/ProductItemTypeTest.php` (adjacent) | item_type lifecycle + D5 immutability |

---

## 2. Routes Discovered

### Admin (asset management) — prefix `api/v1`
Source: `packages/marvel/src/Rest/Routes.php:219–222`. Group middleware (`Routes.php:115`): `auth:sanctum`, `throttle:admin`.

| Method | URI | Controller@action | Controller permission middleware | Name |
|---|---|---|---|---|
| GET | `products/{product}/digital-assets` | `DigitalAssetController@index` | `permission:view-products` | — |
| POST | `products/{product}/digital-assets` | `DigitalAssetController@store` | `permission:create-product` | `admin.products.digital-assets.store` |
| PUT | `digital-assets/{uuid}` | `DigitalAssetController@update` | `permission:update-product` | `admin.digital-assets.update` |
| DELETE | `digital-assets/{uuid}` | `DigitalAssetController@destroy` | `permission:update-product` | `admin.digital-assets.destroy` |

Constraints: `{product}` is `whereNumber`, `{uuid}` is `whereUuid`.

### Customer (access & delivery)
Source: `routes/api.php`.

| Method | URI | Middleware | Controller@action | Name |
|---|---|---|---|---|
| GET | `api/v1/general/digital/downloads` | `api, auth:sanctum, throttle:authenticated` (group L113) | `DigitalDownloadController@index` | — |
| GET | `api/v1/general/digital/download/{entitlement}/{asset}` | `signed`, `throttle:30,1`; both params `whereUuid` | `DigitalDownloadController@download` | `general.digital.download` |

**No other digital routes exist.** There is no SHOW endpoint for a single asset, no file-replacement endpoint, no admin entitlement-management endpoints (list/limit/revoke/restore), no license/access endpoints anywhere in the codebase.

---

## 3. Current Architecture

### 3.1 Domain model

```
Product (item_type: PHYSICAL|DIGITAL, immutable once ordered/assets attached — D5)
  └─ digitalAssets(): HasMany ──▶ DigitalAsset (type: FILE only active)
                                    ▲
DigitalEntitlement (1 per DIGITAL order line — UNIQUE order_product_id, D6)
  ├─ order(), orderItem(), user()
  ├─ assets(): BelongsToMany via pivot (fulfillment-time SNAPSHOT, audit only)
  └─ currentAssets(): PRODUCT-scoped live access (BD1 Option B)
DigitalDownloadLog (audit: entitlement_id, asset_id, ip_hash, ua_hash, downloaded_at)
```

Key semantics:
- **D4** — address nullable; digital-only carts skip shipping entirely.
- **D5** — `ItemTypePolicy::assertChangeable()` blocks `item_type` change when `order_products` rows OR `digital_assets` exist.
- **D6** — exactly-once entitlement via `UNIQUE(order_product_id)` + `firstOrCreate`.
- **D7** — refunds: approval blocked if any DELIVERED entitlement exists; PENDING ones revoked by listener.
- **BD1 Option B** — entitlement licenses the *product's* asset set, not a frozen snapshot; late-uploaded files automatically become available to existing purchasers.

### 3.2 Schema (verified from migrations)

**`digital_assets`** (2026_08_23_120200):
`id`, `uuid` UNIQUE, `product_id` FK→products CASCADE, `type` string(20) default `'FILE'` (LICENSE/ACTIVATION_CODE reserved, unimplemented), `disk` string(30) default `'private'`, `path` (NOT nullable — URL-type assets cannot be represented today), `original_name`, `mime` string(100), `size` unsignedBigInteger, `sort_order` unsignedInt default 0, timestamps.
Index: `digital_assets_product_sort_idx(product_id, sort_order)`.

**`digital_entitlements`** (2026_08_23_120300):
`id`, `uuid` UNIQUE, `order_id` FK→orders CASCADE, `order_product_id` UNIQUE FK→order_products CASCADE, `user_id` FK→users CASCADE, `status` string(20) default `'pending'`, `delivered_at` nullable, `download_limit` unsignedInt default 5, `download_count` unsignedInt default 0, `revoked_at` nullable, timestamps.
Indexes: `(user_id,status)`, `(order_id)`.
**No expiration column exists.**

**`digital_asset_entitlement`** pivot (120400): FK pair CASCADE, `granted_at` default CURRENT, `UNIQUE(digital_entitlement_id, digital_asset_id)`.

**`digital_download_logs`** (120500): FKs CASCADE to entitlement/asset, `ip_hash` string(64) null, `ua_hash` string(64) null, `downloaded_at` NOT NULL, index `(entitlement_id, downloaded_at)`. No updated_at.

### 3.3 Trace A — Admin asset upload (ROUTE → CONTROLLER → VALIDATION → SERVICE → DB → STORAGE → RESPONSE)

```
POST api/v1/products/{product}/digital-assets   (multipart)
 │ auth:sanctum → throttle:admin                Routes.php:115
 ├─▶ DigitalAssetController@store               DigitalAssetController.php:42
 │    permission:create-product                  :27 (constructor middleware)
 ├─▶ DigitalAssetCreateRequest                   rules L18–26:
 │    file: required|file|mimes:pdf|max:20480    (config-driven, PDF-only MVP)
 │    type/original_name/sort_order optional
 ├─▶ Product::findOrFail(product)                :45        → 404 if missing
 ├─▶ item_type !== DIGITAL → 422                 :47–49     translated msg
 └─▶ DigitalAssetService::store()                Service L20–51
      assertUploadAllowed()                      L68–88:
        - isValid()                              L70
        - extension ∈ ['pdf']                    L74–77     (config allowed_mimes)
        - mime ∈ pdf mime types                  L79–82     ⚠ client-supplied mime (G4)
        - size ≤ max_upload_kb                   L84–87
      DB::transaction:                            L24
        putFileAs("digital-assets/{pid}/{uuid}.pdf", disk=private)  L28–32
        sort_order = max+1                       L38
        DigitalAsset::create(...)                L40–49     ⚠ getClientMimeType persisted L46 (G4)
        ⚠ orphan-file risk if create() throws after successful write (G11a)
 ◀─ 201 ApiResponse + DigitalAssetResource       :57
      Resource exposes: id, uuid, type, original_name, mime, size,
      sort_order, created_at, updated_at         Resource L9–22
      Model $hidden hides path/disk              DigitalAsset L33–36
```

### 3.4 Trace B — Customer access & delivery (PURCHASE → FULFILLMENT → ENTITLEMENT → DELIVERY → LIMIT → REVOCATION)

```
Checkout (POST v1/general/checkout)
 ├─ online  → gateway callback → changeOrderStatus(completed) → event(PaymentSucceeded)
 ├─ cod/cashier → markCodAsPaid/markCashierPaid → same canonical transition
 ▼
PaymentSucceeded  (fired once, after commit)          EventServiceProvider:135–140
 ▼
FulfillDigitalProducts listener                        Listener L12–33
   queue meem-high, afterCommit, retries via rethrow   L18–20, L26–32
   failed(): log + notify all super_admin users        L35–51
 ▼
DigitalFulfillmentService::fulfillOrder()              Service L20–74
   kill-switch check                                   L22–24
   filter order_items by snapshot item_type=DIGITAL    L28–30
   DB::transaction:                                     L38
     firstOrCreate(UNIQUE order_product_id) → pending   L40–49   (idempotent, D6)
     syncWithoutDetaching(pivot, all product asset ids) L52–56   (snapshot)
     pending → DELIVERED + delivered_at                 L58–63
   dispatch DigitalProductsDelivered (try/report)       L69–73
 ▼
SendUserDigitalProductsAvailableNotification           bilingual DB/FCM/broadcast
 ▼
Customer lists entitlements
   GET general/digital/downloads (auth:sanctum)        DigitalDownloadController@index L23–55
     where(user_id = caller) — ownership enforced HERE  L26
     signedUrl() minted ONLY for status=DELIVERED       L134–145, TTL config 30min
 ▼
Delivery: GET general/digital/download/{e}/{a} (signed, throttle:30,1)   L66–132
   1. kill-switch → 404                                L68–70
   2. entitlement exists → else 404                    L72–82
   3. status === DELIVERED → else 403 (revoke gate)    L86–88   ← REVOCATION enforced here
   4. asset.product_id === entitlement.orderItem.product_id → else 404  L91–96 (IDOR guard)
   5. file exists BEFORE consuming credit → else 404   L100–104 (credit preserved)
   6. ATOMIC conditional UPDATE:                        L108–115
      download_count+1 WHERE status='delivered' AND count < limit
      affected=0 → 403 DIGITAL_DOWNLOAD_LIMIT_REACHED  ← LIMIT, race-safe
   7. audit insert into digital_download_logs          L117–123
      ip_hash = sha256(ip|app.key); ua_hash likewise   (salted hashes, no raw IP stored)
   8. Storage::disk(private)->response(path, sanitized filename,
      Content-Disposition attachment, Cache-Control private,no-store)  L127–131
      filename sanitized: strip path chars, ≤120 chars L151–157
 ▼
REVOCATION paths
   - Manual/service revoke(): status→REVOKED + revoked_at (idempotent)  FulfillmentService L80–88
   - Refund APPROVED with DELIVERED entitlements → blocked at approval  RefundRepository (D7 guard, verified)
   - RefundApproved → RevokePendingDigitalEntitlements (meem-medium)    Listener L17–33, PENDING only
   - Revoked entitlement: step 3 above denies even with fresh signature
     (regression-tested: DigitalDownloadSecurityTest)
```

### 3.5 Trace C — Admin update/delete

- `update` (L63–70): `firstOrFail` by uuid → service updates ONLY `original_name`/`sort_order` (intersect keys, Service L55). File bytes are immutable today.
- `destroy` (L72–78): `firstOrFail` by uuid → transaction: `Storage::delete(path)` **then** row delete (Service L63–64) → 200.
  ⚠ Ordering defect — see G11b in ledger.

### 3.6 Config surface (`config/digital.php`, read fully)

| Key | Default | Consumer |
|---|---|---|
| `enabled` | env `DIGITAL_PRODUCTS_ENABLED` (true) | download gate, fulfillment kill-switch |
| `allowed_mimes` | `['pdf']` | FormRequest mimes rule + service extension whitelist |
| `allowed_mime_types` | `['application/pdf','application/x-pdf']` | service MIME whitelist |
| `max_upload_kb` | env `DIGITAL_MAX_UPLOAD_KB` (20480) | FormRequest max rule + service size check |
| `download_limit` | env `DIGITAL_DOWNLOAD_LIMIT` (5) | entitlement boot default |
| `signed_url_ttl_minutes` | env `DIGITAL_SIGNED_URL_TTL` (30) | signedUrl issuance |

⚠ None of the four env vars appear in `.env.example` (G8).

### 3.7 Permissions

- Only existing product permissions are used: `view-products` (Permission enum L37), `create-product` (L145), `update-product` (L146).
- Enforcement: controller constructor `$this->middleware('permission:…')` (spatie PermissionMiddleware alias, registered in `ShopServiceProvider`).
- `PermissionSeeder.php`: these names exist in the flat permission list and multiple role buckets (lines 20, 117–118, 308–309, 451).
- **No digital-specific permission exists** (no manage-access/revoke/license capability). Customer downloads rely on Sanctum + signed URLs; admins have NO UI path today to adjust `download_limit`, revoke, or restore an entitlement (fillable allows it at code level, but nothing calls it).
- Super-admin failure alerts resolve recipients via `role('super_admin')` lookup (Listener L43).

### 3.8 Translations inventory (verified)

All nine feature message keys exist in ALL THREE locales:

| Key | en | ar | de |
|---|---|---|---|
| ERROR.ITEM_TYPE_IMMUTABLE_ORDERED | L484 | L289 | L7 |
| ERROR.ITEM_TYPE_IMMUTABLE_ASSETS | L485 | L290 | L8 |
| ERROR.DIGITAL_ASSET_INVALID_FILE | L486 | L291 | L9 |
| ERROR.DIGITAL_ASSET_INVALID_MIME | L487 | L292 | L10 |
| ERROR.DIGITAL_ASSET_TOO_LARGE | L488 | L293 | L11 |
| ERROR.DIGITAL_ASSET_UPLOAD_FAILED | L489 | L294 | L12 |
| ERROR.DIGITAL_ENTITLEMENT_NOT_ACCESSIBLE | L490 | L295 | L13 |
| ERROR.DIGITAL_DOWNLOAD_LIMIT_REACHED | L491 | L296 | L14 |
| ERROR.DIGITAL_NOT_REFUNDABLE_AFTER_DELIVERY | L492 | L297 | L15 |

Notification strings: `notifications.digital.available.*` and `notifications.admin.digital_delivery_failed.*` present in en and ar. Arabic file confirmed valid UTF-8 byte content (console mojibake was a display artifact of PowerShell codepage, not file corruption).

### 3.9 Cache / Queue / Notification integration

- **Cache:** zero cache usage anywhere in the digital code paths (searched `app/Services/Digital`, `app/Http/Controllers/Api/General`, package controllers). Reads rely on composite indexes + eager-load reuse (`currentAssets()` reuses loaded relation; `orderListRelations()` includes `digitalEntitlements.orderItem.product.digitalAssets`). Consistent with Phase-18 guidance not to add caching without need.
- **Queues:** fulfillment runs as queued LISTENERS (no dedicated jobs): `meem-high` (payment-critical, tries≈5/timeout 90 per supervisor spec), revocation + notifications on `meem-medium`, both `afterCommit`.
- **Notifications:** two classes, house-style bilingual payloads over database/fcm/broadcast channels; delivery-failure alert targets super_admin role users.

### 3.10 Purchase-flow integration points (verified)

- `PaymentSucceeded` emitted exactly once per completion: gateway callback path (`OrderController::checkoutCallback`) passes `emitPaymentSuccess=false` to `changeOrderStatus` then fires post-commit; COD/cashier go through the canonical transition which emits.
- Cart/inventory: digital lines bypass stock reservation and deduction (`CartInventoryService`), shipping resolves to 0 for digital-only carts, free-shipping threshold computed on physical subtotal only.
- `OrderResource` exposes additive `digital_downloads[]` (delivered-only; uuid/status/limit/count/delivered_at/assets incl. `original_name`) — storage paths never serialized.
- `order_products.item_type` snapshot written at creation (rolling-deploy safe) and used by fulfillment filtering — historical orders never reinterpret.

---

## 4. Existing Test Inventory

| Suite | Verified coverage highlights |
|---|---|
| `DigitalFulfillmentTest` | delivered entitlement created on PaymentSucceeded; duplicate-event idempotency; mixed/physical-only orders; revoked-not-redelivered; real USER notified (queue meem-medium asserted); permanent-failure super-admin alert; queue placement through real pipeline; OrderResource `digital_downloads` shape + MissingValue parity |
| `DigitalDownloadSecurityTest` | signed stream OK (Content-Type + streamed content); count increments; 403 past limit; concurrent race cannot exceed limit; tampered uuid 404; expired signature 403; unsigned 403; revoked loses access despite fresh signature; pending denied; index shows own only (IDOR); late-uploaded asset granted + downloadable (BD1); stored filename never leaks in Content-Disposition; missing file → 404 AND credit preserved AND no log; single increment + single audit row; salted hashes stored, raw IP absent; runtime throttle 429 on request 31 |
| `DigitalCartCheckoutTest` | digital add-to-cart w/ zero stock; no reservation held; physical behavior unchanged; mixed-cart decrements physical only; digital-only shipping 0; mixed shipping for physical lines; free-shipping threshold ignores digital subtotal; COD/cashier allowed for digital orders |
| `DigitalAssetAdminTest` | 401 unauthenticated; 403 view-only admin; authorized upload 201 + DB row + private-disk file + randomized name + no path leak; invalid MIME rejected; oversized rejected; PHYSICAL product rejects upload; metadata update + delete removes row and file |
| `ProductItemTypeTest` (adjacent) | defaults PHYSICAL; enum stability `[PHYSICAL, DIGITAL]`; D5 blocks change once ordered / once assets exist; import path validated |

Test infrastructure notes: sqlite `:memory:`, `DatabaseTransactions` + manual schema bootstrap (`tests/Concerns/CreatesTestTables.php`), `Storage::fake('private')`, `Sanctum::actingAs`, spatie permissions firstOrCreate'd with guard `api`, rate-limiters disabled/overridden per suite, `QUEUE_CONNECTION=sync` in phpunit.xml (queue-placement tests use `Queue::fake` selectively). **No `tests/Fixtures/` directory exists** — real binary fixtures must be created for Workstream 10.

---

## 5. Findings Summary (G1–G12)

Full detail and classifications in `architecture-gaps.md`; defects in `error-ledger.md`.

| ID | One-line summary | Classification | Verification |
|---|---|---|---|
| G1 | PDF hardcoded across config/validation/messages | GAP (by design MVP) | Confirmed (config, service L68–88, translations) |
| G2 | Type taxonomy dormant: only FILE active | GAP | Confirmed (model constants, migration comment) |
| G3 | Missing columns: checksum/display_name/extension/status/metadata/external_url/expiry | GAP | Confirmed (migration read) |
| G4 | Client-supplied MIME trusted, no server-side content sniffing | **BUG (security)** | Confirmed (service L46, L79) |
| G5 | No SHOW endpoint; no replacement; no admin entitlement mgmt | GAP | Confirmed (route inventory) |
| G6 | Delivery layer assumes FILE attachment only | GAP | Confirmed (controller L127–131) |
| G7 | No license/secret handling whatsoever | GAP | Confirmed |
| G8 | `.env.example` lacks all four `DIGITAL_*` vars | **BUG (ops)** | **CONFIRMED — grep zero hits** |
| G9 | ar translations missing ITEM_TYPE_IMMUTABLE keys | — | **NOT REPRODUCED — keys present (ar L289–290)**; initial report wrong |
| G10 | No cache layer for digital | NOT APPLICABLE (acceptable) | Confirmed zero usage |
| G11 | Non-transactional FS ops inside DB transactions (orphan file/row edges) | **BUG (integrity)** | **CONFIRMED — service L63 before L64; store L28→L40** |
| G12 | Import/export unaware of digital asset metadata | GAP (deferred per A5) | Confirmed |

---

## 6. Locked-Decision Compatibility Snapshot (details in architecture-gaps.md)

| Decision | Current state | Compatible? |
|---|---|---|
| A1 software config-gated OFF | No SOFTWARE category exists at all | N/A yet — registry will introduce it gated |
| A2 encrypted license pool + one-time reveal | Nothing exists; `type=LICENSE` constant reserved but unusable (path NOT NULL) | Gap to build |
| A3 Range streaming A/V | Single attachment response path only | Gap to build |
| A4 hybrid permissions | Product perms reused for CRUD (matches hybrid baseline); no access/license perms yet | Partially aligned |
| A5 explicit deferrals | N/A (no AV/transcode/import-export coupling exists) | Aligned |

---

## 7. Workstream 1 Completion Status

- [x] All 14 inspection directives executed (§1–§4, plus config/permissions/translations/cache/queue/flow traces).
- [x] Full ROUTE→…→RESPONSE traces recorded (§3.3) and PURCHASE→…→REVOCATION trace recorded (§3.4).
- [x] G8/G9/G11 independently verified: G8 CONFIRMED, G11 CONFIRMED, G9 REFUTED (recorded honestly, not converted).
- [x] Four audit documents created; no application code touched.

---

## 8. W2 ADDENDUM — Asset Type Registry landed (2026-08-24)
Material state changes to the description above (all other sections remain accurate for the untouched subsystems):

1. **New canonical layer:** `app/Services/Digital/AssetTypeRegistry` backed by `config/digital.php → asset_types`, with `app/Enums/DigitalAssetType` (FILE/URL/LICENSE/ACCESS) and `app/Enums/DigitalAssetCategory` (DOCUMENT/SPREADSHEET/PRESENTATION/ARCHIVE/AUDIO/VIDEO/IMAGE/SOFTWARE; TEXT folded into DOCUMENT by design).
2. **Two-layer model:** each FILE category declares its full target `extensions`/`mime_types`; an `active_extensions`/`active_mime_types` subset defines what the CURRENT pipeline accepts. Only DOCUMENT(pdf) is active → upload behavior byte-for-byte identical to the legacy PDF flow.
3. **Consumers rewired:** `DigitalAssetCreateRequest` (mimes/max/type rules via `creatableTypes()`) and `DigitalAssetService::assertUploadAllowed()` now delegate exclusively to the registry. Legacy `allowed_mimes`/`allowed_mime_types` keys are deprecated and unconsumed (grep-proven); `max_upload_kb` remains the live global size fallback.
4. **A1 software gate:** `digital.allow_software_assets` (env `DIGITAL_ALLOW_SOFTWARE_ASSETS`, default false) controls SOFTWARE *recognition* only; executables remain non-uploadable until W4 populates their active surface.
5. **DIG-008 FIXED:** all five `DIGITAL_*` env vars documented in `.env.example` with safe defaults, enforced by regression test (`test_env_example_declares_every_digital_variable_consumed_by_config`).
6. **New test suite:** `tests/Feature/Digital/DigitalAssetTypeRegistryTest.php` — 34 tests / 88 assertions green. Full digital regression re-verified post-change: 75 tests / 195 assertions OK (5 mandated suites + registry suite).

---

## 9. W3 ADDENDUM — Multi-type schema landed (2026-08-24)

1. **digital_assets** now carries display_name, extension, checksum(64), status(default ctive, backfilled), metadata JSON, external_url TEXT, secret TEXT (encrypted cast, hidden), expires_at; (product_id,status) index added; path is NULLable (URL/LICENSE/ACCESS representable; FILE flow unchanged).
2. **digital_license_keys** exists (A2 representation): uuid UNIQUE, asset FK CASCADE, encrypted_key NOT NULL (encrypted-at-rest), status pool lifecycle available/assigned/consumed/revoked, allocated_entitlement_id FK SET NULL + audit timestamps.
3. **digital_entitlements.expires_at** added (NULL = unchanged behavior; enforcement is future work).
4. Models updated for schema only (fillable/casts/hidden); new DigitalLicenseKey model is representation-only; secrets/keys cannot serialize by default.
5. Verified: fresh migrate 75/75 on MySQL 8.4.3 and SQLite; rollback+existing-data survival+double-fresh 94/94 both engines; capability smoke suite 13 tests/50 assertions; digital regression 88 tests/245 assertions OK; full-repo diff vs W2 baseline shows zero new failures. Evidence harness: storage/w3-audit/schema_check.php; details in workstream-3-final-report.md.

---

## 10. W4 ADDENDUM — Hardened upload pipeline (2026-08-24)

1. **Authoritative content inspection (DIG-004 FIXED):** DigitalAssetService::detectMime() runs info(FILEINFO_MIME_TYPE) over the real uploaded bytes; client MIME accessors are never consulted for validation or persistence. AssetTypeRegistry::resolveCompatibleCategory() enforces strict extension↔MIME agreement within one active category; mismatches/spoofs reject 422 (DIGITAL_ASSET_MIME_MISMATCH / DIGITAL_ASSET_INVALID_MIME).
2. **Consistency lifecycles (DIG-011 FIXED):** store = validate → checksum(sha256 of real bytes) → write file → persist row → compensate-delete on any persistence failure; delete = row-in-transaction, physical unlink after commit with drift warning. Failure-injection proofs cover storage-write failure, INSERT failure, duplicate-constraint failure, DELETE failure, post-commit unlink failure.
3. **New persisted fields per upload:** extension, checksum (64-hex sha256), status='active'; mime now server-detected.
4. **A1 gate double-enforced** in validation even if registry config changes.
5. Legacy AdminTest fixtures modernized to real PDF bytes (random-byte dummies were never valid PDFs and are now correctly rejected).
6. Verification: DigitalAssetUploadPipelineTest 16/16 (66 assertions); full digital+item-type matrix 104/104 (311 assertions); proof artifact storage/w3-audit/w4-http-proof.txt.

---

## 11. W5 ADDENDUM - External URL & License/Access assets live (2026-08-24)

1. URL assets: created via the same admin endpoint (type=URL + external_url); SSRF-safe static validation + one-time all-records-public DNS resolution (ExternalUrlValidator); no path/checksum fabrication; server never fetches. Customer disclosure ONLY on authorized (delivered, unexpired) entitlement listing.
2. LICENSE assets: pool container + bulk key import endpoint gated by NEW permission manage-digital-licenses (enum+seeder+labels en/ar); locked idempotent allocation inside the fulfillment transaction; customer reveal endpoint (auth-scoped, ownership+delivered+expiry gates, config-driven one-time reveal); ciphertext at rest, plaintext only in the single reveal response.
3. ACCESS assets: single encrypted credential on the asset row, re-revealable through the same endpoint.
4. Entitlement listing payload gained additive fields: expires_at, per-asset external_url / reveal metadata. Download gate now also refuses expired entitlements (NULL expiry unchanged).
5. Verification: W5 suite 16/16 (118 assertions); SSRF probe 20/20; REAL MySQL concurrency 11/11 (8-worker single-order race; 12-worker scarce-pool race); full digital matrix 120/120 (435 assertions).

---

## 13. W7 ADDENDUM - DeliveryResolver / streaming / preview (2026-08-25)

1. **Single delivery chokepoint:** app/Services/Digital/DeliveryResolver.php owns the entire 6-gate chain (kill-switch, existence, status+expiry, product binding, file/inactive, atomic limit) for every customer-facing delivery. DigitalDownloadController is now thin wrappers.
2. **Range streaming:** FILE-family deliveries use Symfony BinaryFileResponse over the real local path - native HTTP Range (206/Content-Range/416) with chunked disk reads (no whole-binary memory). Verified byte-exact across a 12-case matrix against deterministic fixtures.
3. **AUDIO/VIDEO activated (A3):** registry active surfaces opened; uploads accept detected audio/mpeg + video/mp4 families; streamable+previewable flags true.
4. **Preview mode:** ?mode=preview on the signed route delivers inline WITHOUT consuming download credits (spec does not authorise preview consumption); every authorization gate still applies. Unknown modes fall back to normal download behaviour.
5. **URL audited redirect:** GET general/digital/url/{e}/{a} (auth-scoped) redirects to the stored normalized URL after full gating and writes an audit row to digital_download_logs without consuming credits.
6. **LICENSE/ACCESS:** reveal logic relocated into resolver->revealCredential(); W5 one-time semantics unchanged; secrets still decrypt only in the reveal response.
7. **Customer listing additive field:** delivery_type per asset (download|redirect|reveal).
8. **Verification:** dedicated suite 6 tests / 57 assertions (12-case range matrix vs deterministic bytes); independent black-box checker 14/14; MySQL concurrency harness re-run 5/5 post-refactor; full digital matrix 141 tests / 575 assertions OK; route cache passes.

---

## 14. W8 ADDENDUM - Production hardening & closure (2026-08-25)

1. Closure battery suite (10 tests / 171 assertions): security negatives (traversal filename sanitization, oversize HTTP-boundary rejection, malformed-content rejection, executables E2E rejection, deleted-asset credit preservation, no public storage URLs ever emitted); consolidated customer lifecycle E2E through the REAL event pipeline incl. persisted bilingual delivery notification, cap->unlimited sentinel flow, revoke/restore round-trip; performance evidence (listing query count bounded <=12 on grown dataset - no N+1); 19-key translation lock x3 locales with Arabic glyph assertions; permission chain audit (enum->DB->labels->middleware 403).
2. Independent final gate (w8_final_gate.php): 25/25 - schema, registry activation state, permission rows, multipart upload -> raw filesystem/checksum agreement, event-pipeline fulfillment, byte-exact download, credit accounting, audit row, header leakage absence, x3 translation probes.
3. Evidence tree created under storage/e2e/digital-products/ (final gate, suites, W5+W6 MySQL concurrency re-runs 11/11 and 5/5, queue proof re-run 5/5, translation audit, final regression).
4. MAJOR environment finding: a stale bootstrap/cache/config.php (captured without Pusher credentials) had been silently breaking broadcast notifications AND contributing to large classes of pre-existing full-suite failures. Removal dropped full-repo unique failures from 345 to 235 with ZERO new failures and restored broadcast-dependent tests to green.
5. Final digital matrix: 151 tests / 746 assertions OK across all suites (W1-W8).
