# WORKSTREAM 5 — SCOPE SNAPSHOT

- **Captured:** 2026-08-24 (independent verification pass entry point)
- **Commit:** `203844dad35b6ae9bf295f5e0cbd2f13d6dc5b5c` (branch: `main`)
- **Note:** the working tree carries a large PRE-EXISTING uncommitted backlog from earlier engagements (present since the W1 snapshot, before any Digital Products work). This snapshot classifies it once so every later W5 delta is measured against an honest baseline.

## Baseline test state at capture
- Full digital matrix (suites W1–W5): **120 tests / 435 assertions OK**
- W4 close-gate baseline: 104/311 + independent gate 13/48
- Pre-existing unrelated repo failures (coupons/checkout/device_tokens bootstrap etc.): classified separately in W2/W3 reports; unchanged by digital work

## Files classified as DIGITAL ENGAGEMENT (W1–W5)
Tracked-modified:
`config/digital.php`, `.env.example`,
`app/Models/DigitalAsset.php`, `app/Models/DigitalEntitlement.php`,
`app/Services/Digital/DigitalAssetService.php`, `app/Services/Digital/DigitalFulfillmentService.php`,
`packages/marvel/src/Http/Controllers/DigitalAssetController.php`,
`packages/marvel/src/Http/Requests/DigitalAssetCreateRequest.php`,
`packages/marvel/src/Http/Resources/DigitalAssetResource.php`,
`packages/marvel/src/Enums/Permission.php`, `packages/marvel/src/Rest/Routes.php`,
`packages/marvel/src/Database/Repositories/RefundRepository.php`, `packages/marvel/src/Database/Models/{Order,Product}.php`,
`app/Http/Controllers/Api/General/DigitalDownloadController.php`,
`app/Http/Resources/Order/{OrderResource,OrderItemResource}.php`,
`app/Services/General/OrderService.php`, `app/Services/Checkout/OrderCreationService.php`,
`database/migrations/2026_08_23_{105834,120000,120100,120200,120300,120400,120500,130000}_*.php`,
`routes/api.php`, `database/seeders/PermissionSeeder.php`,
`resources/lang/{en,ar,de}/message.php`, `resources/lang/{en,ar}/permissions.php`,
`tests/Concerns/CreatesTestTables.php`, `tests/Feature/ProductItemTypeTest.php`,
`docs/*` (digital docs + production history)

Untracked (created by engagement):
`app/Enums/DigitalAssetType.php`, `app/Enums/DigitalAssetCategory.php`,
`app/Models/DigitalLicenseKey.php`, `app/Services/Digital/AssetTypeRegistry.php`,
`app/Services/Digital/ExternalUrlValidator.php`,
`database/migrations/2026_08_24_120{100,200,300}_*.php`,
`packages/marvel/src/Http/Requests/StoreLicenseKeysRequest.php`,
`tests/Feature/Digital/*` (6 suites), `docs/audits/digital-products/*`

## Files classified as UNRELATED (pre-existing backlog — DO NOT TOUCH)
Invoices (`Invoice*`, `AdminInvoiceResource`), Fast Shipping (`FastShipping*`),
Coupons (`Coupon*`), Shipments, DeviceTokens, Brands/Tags import-export
(`Brand*`, `TagsSheetExport`, `products/product-import-sample.xlsx`),
`PurgeOldSoftDeletedProducts.php`, `error.md`, `api-desc/*`,
misc notification/auth controllers from prior engagements.

## Scope exclusions for THIS pass
Workstream 6+ · Import/Export · Payment/Orders business changes beyond existing integration · Notifications/FCM · Coupons · Fast Shipping · Categories/Brands · architecture redesign.
