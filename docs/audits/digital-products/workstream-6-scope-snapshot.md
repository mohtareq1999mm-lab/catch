# WORKSTREAM 6 — SCOPE SNAPSHOT

- **Captured:** 2026-08-25
- **Commit:** `203844dad35b6ae9bf295f5e0cbd2f13d6dc5b5c` (branch `main`) — unchanged since W5 snapshot
- **Baseline tests:** digital matrix **120 tests / 435 assertions OK** (W5 close state)

## Pre-existing dirty/untracked classification
Identical split to `workstream-5-scope-snapshot.md`: large unrelated pre-existing backlog (invoices, fast-shipping, coupons, brands/tags import-export, device tokens, notification api-desc, `error.md`, `PurgeOldSoftDeletedProducts.php`). Untouched by W6.

## W6-owned files (expected delta)
- `packages/marvel/src/Enums/Permission.php` (+`MANAGE_DIGITAL_ACCESS`)
- `database/seeders/PermissionSeeder.php` (+bucket entries)
- `resources/lang/{en,ar}/permissions.php` (+labels)
- `packages/marvel/src/Rest/Routes.php` (+show / replace / entitlement routes)
- `packages/marvel/src/Http/Controllers/DigitalAssetController.php` (+show)
- NEW `packages/marvel/src/Http/Controllers/DigitalEntitlementController.php`
- NEW `packages/marvel/src/Http/Requests/{ReplaceDigitalAssetRequest,DigitalEntitlementLimitRequest}.php`
- NEW `packages/marvel/src/Http/Resources/DigitalEntitlementResource.php`
- `app/Services/Digital/DigitalAssetService.php` (widened update + replace + status validation)
- NEW `app/Services/Digital/DigitalEntitlementService.php`
- `app/Models/DigitalAsset.php` (status constants completion only)
- `app/Http/Controllers/Api/General/DigitalDownloadController.php` (unlimited sentinel SQL + inactive-asset gate)
- `app/Models/DigitalEntitlement.php` (currentAssets active-only filter)
- `tests/Feature/Digital/DigitalAdminManagementTest.php` (new suite)
- `storage/w3-audit/w6_concurrency_check.php`, `storage/w3-audit/w6_independent_check.php`

## Excluded modules
Import/Export · Payment internals · Notifications/FCM · Coupons · Fast Shipping · Categories/Brands · GraphQL layer · W7 DeliveryResolver/streaming/preview.

## W1–W5 closed contracts that must remain untouched
W4 byte-truth pipeline & compensation lifecycles; W5 SSRF no-fetch model, license pool allocation concurrency, reveal one-time semantics; W3 schema; D7 refund interlock; existing signed-download security model.
