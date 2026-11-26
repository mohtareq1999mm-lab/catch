# Round 12 — Phase 0 Closure Matrix (repository-evidence gaps)

**Scope:** Doc 1 §5.1 Phase 0 risks for the amended aggregate, isolation, and customer tracking (parent #131, ticket #193).
**Rule:** This matrix records **required evidence additions**. Recording a row is **not** risk closure; each risk stays OPEN until the named evidence is executed and linked here.

| Risk | Current repository evidence (verified) | Evidence gap | Required addition | Status |
|---|---|---|---|---|
| R4 — amended aggregate truth table, ETA-unavailable, `actual_delivery_at` clearing | `app/Services/Shipment/ShipmentService.php` (shipment transitions/timeline), `app/Models/Shipment.php` (`TERMINAL_STATUSES`), `app/Services/Tracking/TrackingAggregateService.php` (read-only Round 12 sets & ETA projection) | No persisted authoritative aggregate snapshot/mirror in this checkout; no failing-then-passing gate proving all eight truth-table cases, the failed-delivery ETA freshness rule, or that `completed_at` / order status are untouched while `actual_delivery_at` clears | Link an executable matrix test run (returned + active, returned + delivered, all-cancelled, all-returned, delivered, outstanding precedence, failed_delivery stale/fresh ETA, no-shipment) plus a schema/migration proof for the canonical aggregate owner | **OPEN — evidence required** |
| R1 — route inventory | `routes/api.php` contains the public, guest-token, authenticated, and admin tracking routes; `storage/route-inventory.json` / `error/route-inventory-full-table.md` are stale snapshots | No fresh, generated route inventory for this revision; snapshots predate the Round 12 surfaces | Attach a `route:list` output generated at the reviewed commit, including method, URI, name, middleware and controller action for every tracking/notification-history route | **OPEN — evidence required** |
| R7 — canonical dispatch and rollback/after-commit wiring | `app/Providers/EventServiceProvider.php` registers shipment notification listeners; `app/Services/Shipment/ShipmentNotificationRecorder.php` writes source rows | No event/listener trace proving a single canonical dispatch path, nor proof that external side effects follow commit and are not lost on rollback | Attach a runtime event/trace capture (dispatch → listener → ledger) for success and rollback, plus a grep/test gate showing no duplicate legacy dispatch path | **OPEN — evidence required** |
| R2 — delivered-bypass regression | `app/Services/Shipment/ShipmentService.php` transition guards and `app/Models/Shipment.php::allowedTransitions()` enumerate a one-way `delivered` state | No dedicated adversarial regression run proving a bypass cannot move a delivered shipment or write a delivery timestamp for a non-delivered aggregate | Link a failing-then-passing regression test (direct service call, webhook replay, admin transition) asserting no state/timestamp mutation on bypass attempts | **OPEN — evidence required** |

## Customer-tracking evidence added in ticket #193

- Public order-number lookup is serialized by `PublicTrackingAggregateResource` / `PublicTrackingStatusResource`: no internal order/shipment/provider id, item, price, payload, exception, settlement or representative field.
- Per-shipment rows are serialized by `CustomerShipmentDetailResource` and only reached through the authenticated-owner or guest-token surfaces.
- `TrackingAggregateService::estimatedDelivery()` returns `null` when no genuine ETA exists; `created_at + N days` was removed from tracking.
- Unified notification history is read-only, recipient-scoped, source-discriminated (`order` / `shipment`), deduplicated by `(timeline_event_id, channel, recipient_id)` for shipment rows, and ordered by `created_at DESC, id DESC`.

These additions prove the privacy/serialisation boundary only; they do not close R1/R2/R4/R7.
