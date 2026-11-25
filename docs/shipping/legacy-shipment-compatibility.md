# Legacy Shipment Compatibility Shims (#166)

## Scope

This document covers legacy shipment endpoints retained as compatibility shims during Round 2:

- `GET /api/v1/shipments`
- `GET /api/v1/shipments/uuid/{uuid}`
- `GET /api/v1/shipments/{id}`
- `POST /api/v1/shipments`
- `PUT /api/v1/shipments/{id}/status`
- `PUT /api/v1/shipments/{id}`
- `GET /api/v1/admin/orders/{orderId}/shipment`
- `POST /api/v1/admin/orders/{orderId}/shipment/update-status`

Canonical shipment authority: `App\Services\Shipment\ShipmentService`.

## Compatibility contract

- Response envelopes, field names, and field types for the endpoints above are preserved for current consumers.
- Legacy write endpoints delegate to `ShipmentService`; controllers/resources do not mutate shipment/order shipment status directly.
- `ShipmentResource` keeps the legacy `service_level` and `courier` compatibility fields until the documented removal release.
- Admin order shipment adapter responses keep their existing `{success,data|message}` shape.

## Deprecation

- Status: **Deprecated compatibility shim**.
- Deprecated in: Round 2 (#166), branch `r2b-166`.
- New integrations must use the canonical/WMS shipment surfaces and `ShipmentService`-backed flows; do not add new consumers to legacy endpoints.

## Removal criteria

Legacy shipment endpoints may be removed only when all criteria are met:

1. **Release window:** at least one release has shipped with these endpoints marked deprecated and the deprecation communicated in release/API notes.
2. **Consumer migration:** all known internal and external consumers have migrated to canonical/WMS shipment endpoints, confirmed by integration owners.
3. **Telemetry/usage:** production access logs/metrics show no active consumer traffic for the legacy endpoints over an agreed observation window (minimum one release cycle after migration notice).
4. **Contract replacement:** canonical endpoints provide equivalent data/behavior for migrated consumers, including service-level representation split from legacy courier compatibility where applicable.
5. **Verification:** compatibility tests are replaced/updated with canonical endpoint tests, and regression tests pass in CI.

## Verification

Compatibility is guarded by `tests/Feature/Shipping/LegacyShipmentCompatibilityTest.php` and existing shipment authority/lifecycle tests. These tests assert response-shape compatibility and delegation to canonical authority.
