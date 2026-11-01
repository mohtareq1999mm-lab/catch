# Warehouse Module & Workflow Configurable Control — Deep Discovery, Architecture Audit & Implementation Plan

**Status:** DISCOVERY COMPLETE — **PENDING APPROVAL. No source code, migration, config, test, route or (other) documentation files were modified.** This report is the only artifact created by this task.
**Date:** 2026-10-31
**Scope:** Warehouse module availability (master config → website setting), Warehouse Workflow enable/disable, per-stage enable/disable, and the integrity of the existing Order Lifecycle under every configuration combination.
**Method:** Read-only repository inspection. Every load-bearing claim below carries an exact file path + line reference and a confidence tag: **VERIFIED** (source read directly), **INFERRED** (multiple consistent signals, not runtime-tested), **UNKNOWN** (cannot be determined from the repo).

---

## Section 1 — Executive Summary

The repository already contains a substantial WMS implementation with a **partially configurable warehouse workflow**, but **no module-level master switch and no website-level warehouse control**. Concretely:

1. **Workflow stage control exists** as an append-only, immutable, *versioned* configuration system
   (`app/Services/Warehouse/WarehouseWorkflowService.php`, `app/Models/WarehouseWorkflowVersion.php`, table `warehouse_workflow_versions`). Stage flags: `picking_enabled`, `packing_enabled`, `shipping_enabled`, `order_received_enabled`. Two stages are deliberately **not disableable** (Picking, Packing — no safe fulfillment-DAG edge exists, Q-03), Quality Check is excluded (Q-01), Order Received is mandatory (Q-07), and Shipping **is** disableable with a locked-transaction gate inside `ShipmentService` (Q-05). Fulfillments pin their workflow version at creation (Q-04) and never change it, so publishing a new version is always safe for in-flight work.
2. **Module-level control does not exist.** There is no `config/warehouse.php`, no `WAREHOUSE_ENABLED`-style env key, and no website setting that turns the warehouse module off. The only de-facto availability mechanism is warehouse configuration state itself (active/default warehouse resolution at release time — `WarehouseService::resolveForNewFulfillment`), which fails a release only when no active warehouse exists.
3. **Website-level settings architecture exists** and already hosts feature toggles with two proven patterns: a dedicated boolean column (`order_tax_enabled`, validated, whitelisted, cached) and a namespaced JSON block under `Settings.options` with precise cache invalidation (`fast_shipping` — `packages/marvel/src/Database/Repositories/FastShippingRepository.php`). The warehouse control should follow the `fast_shipping` pattern.

**Recommended design (minimal, no second state machine):**

- **Master config:** new `config/warehouse.php` → `module_enabled` (env `WAREHOUSE_MODULE_ENABLED`, default `true`). Highest authority; boot-time (Laravel config) semantics documented.
- **Website settings:** `Settings.options.warehouse = { module_enabled: bool, workflow_enabled: bool }`, managed by a small `WarehouseSettingsService` following the `FastShippingRepository` pattern (transactional `lockForUpdate` merge + explicit `Cache::forget` on write; NOT the stale `cached_settings_{locale}` key).
- **Effective resolution:** one new centralized resolver (`WarehouseConfigurationService`) computing `module_available = config(warehouse.module_enabled) && settings(warehouse.module_enabled)` and `workflow_active = module_available && settings(warehouse.workflow_enabled)`. Single source of truth; no other code reads these raw values.
- **Enforcement point for the module/workflow switches:** `FulfillmentService::releaseForOrder()` is the **single release-to-warehouse authority** (all three automatic surfaces and the admin endpoint converge there with idempotency keys). The resolver assertion is placed there, so listeners, the hourly sweeper, admin bulk release, and any future caller are enforced by construction. Listeners/sweeper additionally skip early (no queue noise) and log.
- **Workflow-off semantics (recommended):** `workflow_enabled=false` → **automatic release is suppressed; orders no longer wait for warehouse workflow stages**. Module capabilities (warehouse/location/stock CRUD, dashboards, inventory, transfers) remain fully active. In-flight fulfillments and shipments continue under their pinned versions — no records touched, no duplicated work. Admin *manual* release with module ON remains possible (explicit human intent bends no guards) — flagged as decision D-3.
- **Stage controls:** unchanged from the existing Q-01..Q-08 design. The current stage configuration contract (Picking/Packing mandatory; Shipping optional-now; Order Received mandatory; QC excluded) is authoritative; the report does not propose changing it.
- **No new migration required for the recommended v1.** One optional migration (release-skip evidence stamping on `orders`) is identified only for an *optional, separately-approved* delivered-invariant relaxation (Decision D-2).

The single most important verified constraint driving the whole scenario analysis: **the Order Flow (`OrderFlowService`) plus `OrderService::changeOrderStatus` is the sole order-status writer**, and inventory reservation/commit/release/restore is **order-level** (`OrderService` at `completed`/`cancelled`), entirely independent of warehouse availability. Disabling warehouse functionality therefore cannot corrupt stock, but it *does* interact with the Phase-8 delivered-completion invariant (`orderSatisfiesDeliveryCompletion` requires ≥1 fulfillment, all delivered) — analyzed in Sections 7–8 and gated on Decision D-2.

---

## Section 2 — Existing Architecture

### 2.1 Configuration layer

| Artifact | Evidence | Role |
|---|---|---|
| `config/fulfillment.php` (L1–20) | `claim_lease_minutes` env `FULFILLMENT_CLAIM_LEASE_MINUTES` (15), `sweep_limit` env `FULFILLMENT_SWEEP_LIMIT` (100) | Only fulfillment-adjacent config file. Precedent for `config/<domain>.php` + env. Read by `PickingExecutionService` L38, L296 and asserted in `tests/Unit/Services/Warehouse/WarehouseDefaultTest.php:81`. **No warehouse/fulfillment module on/off key exists anywhere in `config/`.** VERIFIED |
| Config files present | `config/` contains 38 files (activitylog … view, incl. `cart.php`, `digital.php`, `payment.php`, `fulfillment.php`); none contain a module enable/disable flag for warehouse | Gap confirmed. VERIFIED (directory listing + grep for `WAREHOUSE_ENABLED`, `warehouse_enabled` → 0 matches in config) |
| `.env.example` | No warehouse-related keys (grep `WAREHOUSE|FULFILLMENT|WORKFLOW` → 0) | Gap confirmed. VERIFIED |
| Laravel config caching | Standard Laravel; production runs `config:cache` (must be re-run/cleared for env changes) — see Risks R-01 | INFERRED (framework behavior; no custom code overrides) |

> Note: `config/rabbitmq.php` exists as a historical leftover although RabbitMQ was removed — irrelevant here but evidence that config leftovers are possible; the new file should be self-contained.

### 2.2 Website settings layer

| Component | Evidence | Details |
|---|---|---|
| Storage model | `packages/marvel/src/Database/Models/Settings.php` L14–72 | **Single-row** `settings` table (`Settings::first()`). `$fillable` includes `options` (array cast L51) and `order_tax_enabled` (bool cast). `Settings::getData($language)` caches the whole row under `cached_settings_{locale}` for **86400 s** (L58–72) |
| Staleness risk (verified) | `tests/Feature/Settings/SettingsRegressionTest.php:102–114` | Proves the `cached_settings_*` entry survives mutation *and even `Settings::truncate()`* — i.e. **there is no invalidation of `cached_settings_{en,ar}` on update**. Tests elsewhere (`tests/Feature/Tax/SimpleTaxTest.php:77–78`) work around it with manual `Cache::forget('cached_settings_en'); Cache::forget('cached_settings_ar');` |
| Write path | `packages/marvel/src/Http/Controllers/SettingsController.php` L43–125 | `PUT settings` guarded by `permission:update-settings` (constructor L28–30); explicit `$request->only([...])` whitelist L64–77; special-case merge for `currency_selection_enabled` **into `options` JSON** L79–85; media handling; `$this->flushTag(FrontendResource::SETTINGS->value)` L110; **audit trail** via `ActivityAuditService::recordModel(...'settings_updated'...)` L112–118; repository `SettingsRepository::updateSetting()` runs in DB transaction |
| Request validation | `packages/marvel/src/Http/Requests/SettingsRequest.php` L54 | `order_tax_enabled => ['sometimes','boolean']` — precedent for boolean settings validation |
| Read path (runtime) | `app/Services/General/OrderService.php:813–818` | Order tax: `Schema::hasColumn('settings','order_tax_enabled')` guard then `Settings::first()` + boolean read — the precedence-style runtime read of a website toggle inside a pricing/consistency-critical path. (Note: this specific path reads fresh, not from the stale cache.) |
| **Namespaced JSON toggle precedent (the pattern to reuse)** | `packages/marvel/src/Database/Repositories/FastShippingRepository.php` (whole file) | `SETTINGS_KEY = 'fast_shipping'`; `isGloballyEnabled()` L14–16; `getSettings()` L48–53 uses `Cache::remember('fast_shipping_settings', 3600, ...)` reading `data_get($settings, 'options.fast_shipping')` against `defaults()`; `updateSettings()` L55–70 = DB transaction, `Settings::lockForUpdate()`, defaults-merge into `$options['fast_shipping']`, update, then **`Cache::forget('fast_shipping_settings')`** |
| Admin API for that feature | `packages/marvel/src/Http/Controllers/FastShippingController.php` L18–45 + `packages/marvel/src/Rest/Routes.php` L143–144 `GET/PUT fast-shipping/settings` | Permission-gated thin controller (`VIEW_FAST_SHIPPING` / `UPDATE_FAST_SHIPPING`), request `validate([...])[ 'enabled' => ['sometimes','boolean'], ...]`, then `repository->updateSettings($validated)` + `flushTag(FrontendResource::FAST_SHIPPING_SETTINGS)`. **This is the closest existing shape for “module settings with an `enabled` flag”.** |
| Frontend exposure | `packages/marvel/src/Http/Resources/SettingResource.php` L36–83 | Exposes `options` wholesale (L82) and derives per-feature booleans from it (`currency_selection_enabled` L81, `order_tax_enabled` L79); public `GET settings` at `routes/api.php:96` via `app/Http/Controllers/Api/General/SettingController.php` (tag-cached, L29–33) |
| Section-type settings | `app/Observers/SectionTypeSettingObserver.php`, `section_type_settings` table (migration `2026_06_17_000010`) | Separate per-section-type settings store — not relevant to a global warehouse switch |

**Conclusion:** a website-level warehouse toggle belongs in the *existing* settings row using the `fast_shipping` namespaced-pattern (with correct invalidation), not a new settings architecture.

### 2.3 Warehouse / WMS subsystem

#### 2.3.1 Workflow configuration — what already exists

| Component | Evidence | Semantics |
|---|---|---|
| `WarehouseWorkflowService` | `app/Services/Warehouse/WarehouseWorkflowService.php` L11–196 | Declared “configuration authority”; docblock states the non-negotiation: *“No competing order state machine: all order writes stay in OrderService::changeOrderStatus via allowsFlowTransition. All fulfillment writes stay in FulfillmentTransition.”* (L16–21) |
| defaults | L29–37 | all stage flags true ⇒ legacy lifecycle when nothing published |
| `current()` / `currentOrDefaults()` | L39–66 | table-missing ⇒ legacy (pre-migration safety); DB errors propagate fail-closed |
| `publish()` | L81–134 | **Q-01**: `quality_check_enabled` rejected; **Q-07**: `order_received_enabled=false` rejected; **Q-03**: `picking_enabled=false` and `packing_enabled=false` rejected (“no safe transition exists”); `shipping_enabled=false` **is allowed**. Insert-only (append-only); concurrent publishes both persist as versions; current = latest id |
| Version pinning | `resolvePinnedVersionId()` L140–143; `createFromOrder()` pins at `app/Services/Fulfillment/FulfillmentService.php:232–241`; write-once enforcement in `app/Models/Fulfillment/Fulfillment.php:61–66` | Fulfillment carries `warehouse_workflow_version_id` from creation onward; never rewritten (model `updating` guard reverts changes) |
| Shipping gate | `isShippingEnabledForFulfillment()` L159–176 (pinned-but-missing version = corruption ⇒ fail-closed); `assertShipmentAllowedFor()` L185–196 | Called **inside ShipmentService transactions after locking the fulfillment, before mutation**: `app/Services/Shipment/ShipmentService.php:63` (generic create) and `:144` (`createForFulfillment`). Existing shipments unaffected; completion still requires shipment+delivery evidence |
| Model / immutability | `app/Models/WarehouseWorkflowVersion.php` L14–59 | `booted()` throws on `updating`/`deleting` (L42–52); `current()` = latest id (L55–58). Known residual risk: raw-DB operators can bypass (documented in the class docblock L37–41) |
| Permissions | `packages/marvel/src/Enums/Permission.php` L448–451: `MANAGE_WAREHOUSE_WORKFLOW`, `WAREHOUSE_WORKFLOW_SHOW`, `WAREHOUSE_WORKFLOW_HISTORY`, `WAREHOUSE_WORKFLOW_PUBLISH` | Enforced per-route in `packages/marvel/src/Rest/Routes.php:593–598` |
| Admin API | `app/Http/Controllers/Api/Admin/Wms/WarehouseWorkflowController.php` L19–84 | show/history/publish; thin; validation incl. rejection of `quality_check_enabled` at HTTP layer L72–74; `RuntimeException → 422` L78–80 |
| Translations | `resources/lang/en/wms.php` L304–318 | Full bilingual-capable message catalogue for workflow semantics |

#### 2.3.2 Module capabilities that are NOT gated by the workflow

All VERIFIED by route/controller/service inspection (`packages/marvel/src/Rest/Routes.php:557–598,614–641+`; controllers under `app/Http/Controllers/Api/Admin/Wms/`):

- Warehouse CRUD + activate/deactivate/set-default/users (`WarehouseController`, `admin/warehouses/*`, L557–574)
- Locations + occupancy + receipts/transfers (`LocationController`, `InventoryController`)
- Barcode scanning (`BarcodeScanController`, L588–591)
- Warehouse dashboard (`WarehouseDashboardService`)
- Fulfillment release (manual), assign, cancel; picking tasks; batches; packing tasks/stations; packages; shipments adapters (`FulfillmentController`, `PickingController`, `BatchController`, `PackingController`, `PackingStationController`, `PackageController`, `ShipmentController`)
- `WmsAdminController` base (L20, `app/Http/Controllers/Api/Admin/Wms/WmsAdminController.php`) abstracts warehouse-scope enforcement
- Inventory operations tables (`create_inventory_operations_table`, `2026_10_13_000001`) and receipt/transfer idempotency semantics (`wms.stock.*` keys)

#### 2.3.3 Fulfillment DAG and the *order* machine relationship

- Single fulfillment-transition authority: `app/Services/Fulfillment/FulfillmentTransition.php` (locks row, refuses illegal moves, stamps timestamps).
- DAG `app/Models/Fulfillment/Fulfillment.php:154–167`:
  `pending → picking|cancelled; picking → picked|packing|cancelled; picked → packing|cancelled; packing → ready_to_ship|cancelled; ready_to_ship → shipped|cancelled; shipped → delivered;`
- This DAG is **why** Picking and Packing cannot be disabled (Q-03 contract quoted in `WarehouseWorkflowService::publish` L98–107 — the docblock cites the absence of `picked→ready_to_ship` and `pending→…` skip edges) and why QC is excluded rather than an activatable stage.

### 2.4 Order lifecycle (the integration target)

#### 2.4.1 The order status machine

- **Flows:** `app/Services/OrderFlow/OrderFlowService.php` `catalogSeed()` L108–136 (status vocabulary incl. `processing, packed, shipped, out_for_delivery, delivered, failed_delivery, returned, completed, cancelled` + international/customs extras), `flowsSeed()` L143–176:
  - **local:** `pending → processing → packed → shipped → out_for_delivery → delivered`
  - **international:** `pending → processing → packed → export_processing → shipped → in_transit → arrived_at_destination_country → customs_clearance → customs_cleared → local_carrier → out_for_delivery → delivered`
- **Groups:** seeded DB tables (`order_status_flow_tables` migration `2026_09_28_000001`; `extend_orders_with_flow` `2026_09_28_000002`; widening `2026_09_29_000001`; multi-flow-per-type `2026_10_12_000001`; flow inputs `2026_09_30_*`). Orders carry `flow_id`, `current_status_id` (canonical, mirrored to `orders.status` only inside `changeOrderStatus` — L1087–1096).
- **Transition authority:** `allowsFlowTransition(?Order, from, to)` L488–535 — linear successor along the flow + supervised exits; **terminal absorption**: `completed → delivered` allowed; `delivered`/`cancelled` terminal; `cancelled` from anything except `completed`; **`completed` allowed from any state** (payment milestone).
- **Lost/side columns:** legacy `orders.order_status` synced via `mapToLegacyOrderStatus()` L934–944 (only fully-mappable codes: pending/processing/completed/cancelled/out_for_delivery/ready_for_pickup) — writes at L1148–1157; `orders.fulfillment_status` (Order-level coarse marker) mapped L1126–1142; `orders.shipment_status` + tracking/courier mirror written only by `ShipmentService::syncOrderMirror` L529–548 (never drives state).

#### 2.4.2 The single status writer and its guards

`app/Services/General/OrderService::changeOrderStatus()` **L946–1388** (verified end-to-end):

1. Row lock via invoice→order or orderId (L952–964).
2. **Flow gate (fail-closed)** L972–988 — `allowsFlowTransition`; no flow ⇒ refuse, never legacy-allow.
3. Same-state no-op L990–999.
4. **Flow Input gate** L1001–1016 (persisted values only after validation).
5. **F-1 payment authority** L1018–1037: completing an unpaid order requires `payments.mark_paid` (system/gateway paths exempt).
6. **Phase-8 delivered invariant** L1039–1076 (`orderSatisfiesDeliveryCompletion()` L1522–1535: paid + ≥1 fulfillment + **every** fulfillment `delivered`) — bypassable only via the audited **force-delivered** path (permission `update-order-status`, reason required, audit context L1050–1070).
7. Canonical status write + `current_status_id` mirror L1078–1096; payment/`paid_at`/`completed_at`/`cancelled_at` markers L1098–1124; `fulfillment_status` map L1126–1142; legacy `order_status` sync L1148–1157; immutable `order_status_history` L1161–1240; metrics L1242–1248.
8. **Invoice generated exactly once** on first transition out of `pending` L1250–1265.
9. On `completed`: coupon usage, promotion finalize, **`orderReservationService->commit($order)`** L1267–1300 (canonical inventory commit, idempotent), customer metrics (afterCommit).
10. On `cancelled`: **inventory restore if `INVENTORY_STATE_COMMITTED` else release** L1325–1340 (exactly-once claims; mutually exclusive), promotion decrement (unpaid-only) L1342–1350, coupon reservation release L1352–1355, **fulfillment cascade** `cancelOpenFulfillmentsForOrder()` L1357–1360 → L1411–1460 (through `FulfillmentService::cancelFulfillment`, atomic; status classes `pending|picking|picked|packing|ready_to_ship` cancellable; shipped/delivered surfaced as manual follow-up with live-shipment warning L1439–1459).
11. Events L1363–1384: `OrderStatusChanged`, `OrderCancelled`, `OrderDelivered`, `PaymentSucceeded` (exactly-once per completion; gateway callbacks suppress via `$emitPaymentSuccess=false`).

Verified **writer surface** (grep `changeOrderStatus(`): `PaymentCompletionService:309` (gateway callbacks), `OrderService:1563/1597` (`markCodAsPaid`/`markCashierPaid`), `CancelUnpaidOrders:98` (reaper), admin `OrderStatusBatchService:115` (also used by legacy marvel `PATCH orders/{id}/status` — `packages/marvel/src/Http/Controllers/Order/OrderController.php:92`, no independent logic), WMS `OrderCancellationController:50`, customer `OrderController:108` (cancel), `OrderController:263` (zero-value online completion), `FulfillmentOrderSyncService:139`, `ShipmentService:428` (delivered), `FastShippingService:154`, plus checkout supersede inside `OrderService:342`.

#### 2.4.3 Inventory: order-level, warehouse-independent

- `app/Services/Inventory/OrderReservationService.php`: `hasPhysicalLines()` L37, `commit()` L89, `release()` L121; `InventoryRestoreService::restore()` L29 `app/Services/Inventory/InventoryRestoreService.php`.
- `app/Console/Commands/MigrateInventoryReservations.php` + `orders.inventory_state` enum (`INVENTORY_STATE_NONE/ACTIVE/RELEASED/COMMITTED/RESTORED`, `packages/marvel/src/Database/Models/Order.php:28–32`; migrations `2026_08_26_100001`, `2026_10_07_000002`, `2026_08_31_120000`, `2026_07_14_103540`).
- COD/cashier **commit-at-creation** rule (evidenced in `FulfillmentService::assertReleasable` L182–198 and the supersede path `OrderService:332–351`).
- **No code path inside the reservation services requires a warehouse row** (reservations aggregate physical lines only; `DigitalFulfillmentService` entitlements handle digital separately). ⇒ ★ verified claim: stock consistency survives full warehouse-module disablement.

#### 2.4.4 Who creates fulfillments — the four release surfaces

All converge on `FulfillmentService::releaseForOrder(Order, ?warehouseId, ?idempotencyKey)` **L67–171** (verified):

1. **`ReleaseFulfillmentOnPayment`** (`app/Listeners/ReleaseFulfillmentOnPayment.php:24–105`) — `PaymentSucceeded` (wired `app/Providers/EventServiceProvider.php:179`), queued `high`, afterCommit, tries 5 / backoff [10,30,60,120,300]; skips digital-only; key = `FulfillmentService::automaticReleaseKey(orderId)` = `auto-release-order-{id}` (L32–35).
2. **`ReleaseFulfillmentOnCodPlacement`** (`app/Listeners/ReleaseFulfillmentOnCodPlacement.php:32–126`) — `OrderCreated` for `cod|pay_at_cashier` (wired L159; COD/cashier inventory already committed at creation, checked L83–90).
3. **`ReleaseReadyFulfillments`** sweeper (`app/Console/Commands/ReleaseReadyFulfillments.php:34–99`), scheduled **hourly** (`app/Console/Kernel.php:49`) — recovery backstop selecting every releasable order **missing a non-cancelled fulfillment** (L53–70) using the same predicate family as `assertReleasable`.
4. **Admin explicit release** — `FulfillmentController::release()` (`app/Http/Controllers/Api/Admin/Wms/FulfillmentController.php:124–241`; bulk mode L143–201), permission `FULFILLMENT_CREATE` (`Routes.php:616`), warehouse scope guard, `RuntimeException → 422` L231–233.

`assertReleasable()` **L176–207** is the CANONICAL gate: cancelled/delivered refused; online ⇒ `payment_status=SUCCESS` **and** `inventory_state=COMMITTED`; deferred (`cod|pay_at_cashier`) ⇒ `COMMITTED` (accepted `ACTIVE` legacy) regardless of payment. Creation itself (`createFromOrder` L209–290): warehouse resolution (inactive warehouses rejected; default resolution evidence in `tests/Unit/Services/Warehouse/WarehouseDefaultTest.php`), **workflow-version pinning** L232–241, physical-lines-only digital filter L257–313, zero-physical refusal L269–277, idempotency (UNIQUE key + pending/active reuse L72–147, split semantics L93, WMS-001 single-warehouse guard L102–111).

**Consequence for this design:** gating `releaseForOrder` itself covers *every* automatic and manual surface — no scattered checks at the four call sites.

#### 2.4.5 Fulfillment → Order convergence

`app/Services/Fulfillment/FulfillmentOrderSyncService.php` (verified whole file, 338 lines):

- **Matrix (docblock L23–29):** any live fulfillment ⇒ tier `processing`; all live ≥ `picked` ⇒ `packed`; all ≥ `shipped` ⇒ `shipped`; + shipments observed `out_for_delivery` ⇒ `out_for_delivery`; **delivered only via `ShipmentService::maybeCompleteOrder`** (completed + paid + every fulfillment delivered).
- Every order write goes **through `OrderService::changeOrderStatus`** (L58–60 safety note); walker L91–198 advances one legal successor at a time, illegal steps are logged skips (never forced) — incl. **L-01**: paid orders (`completed`) cannot display intermediate logistics steps (`completed` ∉ seeded flows ⇒ `completed→packed` illegal); **L-02**: international customs steps manual.
- Announcement path: `announce(orderId, milestone, fulfillmentId)` → `FulfillmentProgressed` event → listener `SyncOrderWithFulfillmentProgress` (`app/Listeners/SyncOrderWithFulfillmentProgress.php:26–80`, queued high, afterCommit) → `sync()`.
- `PaymentSucceeded` also wired to the same listener (L182–186 + L185) — payment landing after fulfillment recovers the delivered step.

#### 2.4.6 Shipments

`app/Services/Shipment/ShipmentService.php` (verified regions):

- `createForFulfillment()` L99–164: requires locked fulfillment `ready_to_ship` (L129–139), **Q-05 shipping gate** L140–146, no active shipment (D8-1, L555–561 + UNIQUE backstop), idempotency key scoped to (fulfillment,key) with cross-fulfillment 409 (L105–117), `label_created`.
- `dispatch()` L178–227: `label_created → picked_up`; fulfillment `ready_to_ship → shipped` via `FulfillmentTransition`; fulfillment items → `shipped`; `announce('shipped')`.
- `markDelivered()` L243–319: chain `picked_up → in_transit → out_for_delivery → delivered` (explicit; no jumps); fulfillment `→ delivered`; `maybeCompleteOrder()`; delivery-replay recompletes when payment landed late (L249–264).
- `maybeCompleteOrder()` L389–433: status must be `completed` + paid + **all** fulfillments delivered + **≥1 fulfillment exists** (L421–426: zero fulfillments ⇒ no auto-delivery — digital owned by payment path) ⇒ `changeOrderStatus(delivered)`.
- `updateStatus()` L449–476 canonical generic transition; `cancelShipment()` L334–387 (reason + audit, refuses fulfillment regression); `update()` L488–517 protects authority-owned fields.

### 2.5 Database evidence (schemas)

Key tables (migrations verified by name + columns where load-bearing):

| Table | Migration | Load-bearing columns |
|---|---|---|
| `settings` | Marvel-managed single row; columns added via e.g. `2026_09_09_000001` (`order_tax_enabled` L41–44) | `options` JSON, taxa flags |
| `orders` | base + `2026_07_27_081603`, `2026_08_19`, `2026_09_22_000001`, `2026_10_02_000001` (legacy `order_status`), `2026_09_28_000002` (flow columns), `2026_08_26_100001` (inventory_state), `2026_09_21_*` (cancellation/shipment tracking), `2026_10_02_000001` (+ parent) | `status`, `current_status_id`, `flow_id`, `fulfillment_status`, `inventory_state`, `shipment_status`, `order_status` (legacy sync) |
| `warehouses` / `locations` / `product_locations` | `2026_09_22_081818/081824/081825`; hardening `2026_10_03_000001`; soft deletes `2026_10_06_000001`; `2026_10_15_000001` variants; `2026_09_22_100001` outbox… (coupon unrelated) | active flags, default-warehouse identity |
| `fulfillments` | `2026_09_22_130803` (status enum string `pending, picking, packing, shipped, delivered, cancelled` L19), `2026_09_23_000001` (WMS fields), `2026_10_07_000001` (cancel audit), `2026_10_13_000001` (batch idempotency), `2026_10_16_000002` (**`warehouse_workflow_version_id`** nullable + FK, L18–24) | status, idempotency_key, warehouse snapshot write-once |
| `fulfillment_items` | `2026_09_22_130826` | `product_location_id` (NOT location id — see `FulfillmentService:340–343` note), `quantity_picked`, status `pending, picking, picked, packed, cancelled` |
| `fulfillment_batches` | `2026_09_22_190340` (+ idempotency `2026_10_13_000001`) | batching + assignment |
| `picking_tasks` / claims | `2026_09_22_190356`, claims `2026_09_23_000003` | claim lease (`config/fulfillment.claim_lease_minutes`), sweeper `picking:sweep-expired-claims` (`app/Console/Kernel.php:36`) |
| `packing_stations` / `packing_tasks` / packages | `2026_09_22_190932/190957`, `2026_09_23_000004` | pack → seal → verify → `ready_to_ship` |
| `shipments` | `2026_07_28_000004`, fulfillment fields `2026_09_22_191253`, idempotency `2026_09_23_000005`, cardinality/cancel-audit `2026_10_08_000001` | `label_created → picked_up → in_transit → out_for_delivery → delivered` + terminal `cancelled/returned/failed_delivery` |
| `warehouse_workflow_versions` | `2026_10_16_000001` (whole file) | four stage booleans, `created_by`, `published_at`, `notes`; append-only |
| `inventory_operations` | `2026_10_13_000001` | stock operations ledger/idempotency |

**No schema change is needed by the recommended design.** (An optional evidence-column migration exists only under Decision D-2b; see §6/§12.)

### 2.6 Test architecture

- Runner: `phpunit.xml` — **MySQL** (`DB_CONNECTION=mysql`, `DB_DATABASE=catch`), `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`. WMS suites are explicitly **“MySQL-only, sequential (shared MySQL), RefreshDatabase (real migrations)”** (`tests/Feature/Wms/WarehouseWorkflowTest.php:22`).
- Workflow suite (`tests/Feature/Wms/WarehouseWorkflowTest.php`) already covers: auth (94), forbidden (100), legacy defaults w/o publication (107), publish+history (116), Q-07 (128), Q-01 (137), Q-03 picking/packing refuse (146), immutability (162), delete-refusal (170), corrupt pin fails-closed (183), concurrent publish (206), **pin-current / existing-retain** (218), missing-config legacy (244), **shipping-disabled blocks NEW shipments, existing continue** (255), physical completion refuses without shipment evidence (305), enabled physical E2E (323), digital-only independence (344), mixed-order independence (368).
- Concurrency + HTTP E2E + permission seeder variants: `WarehouseWorkflowConcurrencyTest`, `WarehouseWorkflowHttpE2ETest`, `WarehouseWorkflowPermissionSeederTest`.
- Release-path suites: `AutoReleaseWiringTest`, `AutoReleaseDedupeRegressionTest`, `Phase3ReleaseHardeningTest`, `SingleWarehouseReleaseTest`, `FulfillmentLifecycleTest` (Feature/Fulfillment/*).
- Sync suites: `WmsFulfillmentOrderSyncTest`, `WmsShipmentTest`, `WmsOrderCancellationTest`, `ShipmentPhase8*`.
- Inventory suites: `OrderReservationLifecycleTest`, `Phase3InventoryCommitTest`, `StockOperationsTest`, `GiftAndReconciliationTest`.
- Settings suites: `SettingsAuthenticationTest`, `SettingsCrudTest`, `SettingsRegressionTest`, `SettingsValidationTest`; **`FastShippingRepositoryTest`** + `FastShippingScopeTest` (unit) — direct templates for the new warehouse settings service test.

---

## Section 3 — Current Execution Flow (chart — only existing relationships)

```text
Checkout / OrderCreationService::finalizeOrder (app/Services/Checkout/OrderCreationService.php:571)
   │  flow assigned (OrderFlowService::resolveCheckoutFlow:265); reservation created at creation;
   │  COD / pay_at_cashier COMMIT inventory at creation (Phase-3 addendum)
   ▼
OrderCreated event ──────────► ReleaseFulfillmentOnCodPlacement (queued, COD|cashier only)
   │                              └──► FulfillmentService::releaseForOrder ──┐
   ▼                                                                          │
Online payment                                                              │
   PaymentCompletionService:309 ─► OrderService::changeOrderStatus('completed')  ◄── also markCodAsPaid/markCashierPaid:1563/1597, OrderStatusBatchService:115 (admin), OrderController:263 (zero-value)
   │  ├─ flow gate allowsFlowTransition (OrderFlowService:488)
   │  ├─ F-1 payment guard (unpaid completion needs payments.mark_paid)
   │  ├─ inventory COMMIT via OrderReservationService::commit  (idempotent; L1274)
   │  ├─ filled payment_status=success, paid_at, completed_at; invoice once (1250)
   │  └─ PaymentSucceeded event
   │        ├─ FulfillDigitalProducts (digital entitlements path — no WMS)
   │        ├─ ReleaseFulfillmentOnPayment (queued w/ automatic key)
   │        │        └──► FulfillmentService::releaseForOrder (assertReleasable:
   │        │                online: paid+COMMITTED; cod/cashier: COMMITTED|ACTIVE legacy)
   │        └─ SyncOrderWithFulfillmentProgress (recover delivered step)
   │
   ├─► FulfillmentService::releaseForOrder ► createFromOrder (pin workflow version @creation; digital lines excluded;
   │        warehouse resolution; UNIQUE idempotency) ► Fulfillment(status=pending, items w/ location allocations)
   │        └──► FulfillmentOrderSyncService::announce('released') — order nudged toward tiers
   │
   ▼
Hourly sweeper ReleaseReadyFulfillments (fulfillment:release-ready) ── same releaseForOrder path (recovery backstop)
   │
   ▼
Picking: admin FulfillmentController{create-tasks|complete-picking} / BatchController / PickingController
   claim ► record-pick ► fulfillment pending→picking→picked (FulfillmentTransition; single authority)
   │        └ announce(...) ► SyncOrderWithFulfillmentProgress ► tier 'processing'/'packed' (legal-successor-only; L-01 paid-order intermediates skipped w/ log; L-02 customs manual)
   ▼
Packing: PackingController tasks/packages/seal/verify ► fulfillment picked/packing→ready_to_ship
   ▼
Shipment: ShipmentService::createForFulfillment
   │  ├─ requires fulfillment ready_to_ship
   │  ├─ Q-05 WarehouseWorkflowService::assertShipmentAllowedFor (pinned version shipping_enabled)
   │  └─ no-active-shipment guard + idempotency ► label_created ► syncOrderMirror (orders.shipment_status/tracking/courier)
   ▼
dispatch: label_created→picked_up; fulfillment ready_to_ship→shipped; items→shipped; announce('shipped') ► tier 'shipped'
   ▼
markDelivered: picked_up→in_transit→out_for_delivery→delivered; fulfillment→delivered;
   maybeCompleteOrder: order completed + paid + ALL fulfillments delivered + ≥1 ► changeOrderStatus('delivered') ► OrderDelivered
   ▼
Cancellation path (anytime before terminal): changeOrderStatus('cancelled')
   ├─ inventory: COMMITTED ► RestoreService::restore ; else Active ► reservation release
   ├─ promotion decrement (unpaid only), coupon reservation release
   └─ cancelOpenFulfillmentsForOrder ► FulfillmentService::cancelFulfillment (refuses sealed custody/packed work/live shipment;
       whole cancel rolls back atomically; shipped+ fulfillments left open w/ ops warning)
```

*(Only verified relationships included; digital-only orders bypass the warehouse column entirely — `test_digital_only_needs_no_fulfillment_or_shipment`, `WarehouseWorkflowTest.php:344`.)*

---

## Section 4 — Current Configuration Mechanism

1. **Domain config files** — `config/<domain>.php` + env with in-file comments and defaults (`config/fulfillment.php`). Effective via Laravel config; changes require `config:cache` rebuild in production (see R-01).
2. **Website settings (single row)** — `Settings` model + white-listed `PUT settings` + `SettingResource`; two toggle styles exist:
   - dedicated boolean column (`order_tax_enabled`) — migration + fillable + cast + whitelist + resource + runtime read;
   - namespaced JSON sub-key (`Settings.options.fast_shipping`) — dedicated repository w/ transactional defaults-merge + **dedicated cache key with explicit forget** (correct invalidation), thin permission-gated controller pair `GET/PUT fast-shipping/settings`.
   - **Defect to avoid (verified):** the row-wide `cached_settings_{locale}` cache (24 h) is *never* invalidated on update (`SettingsRegressionTest:102–114`), so any new control must use its own key + explicit invalidation.
3. **Workflow configuration** — append-only versioned rows in `warehouse_workflow_versions` + per-fulfillment pinning + locked-transaction gates in `ShipmentService`. Already centralized in `WarehouseWorkflowService`; publish validation embeds the safety rules (Q-01/03/05/07).
4. **Emergency operational levers that exist today** — warehouse activate/deactivate and default-warehouse promotion: `releaseForOrder` fails when no active warehouse exists (`wms.warehouse.inactive/.no_default` keys; `WarehouseDefault*` unit tests). Effective only for writing fulfillment to an *inactive* warehouse cohort; **not an application-level module switch**.
5. **Feature discovery endpoint** — nothing for warehouse; settings payloads surface `options` (frontend can read warehouse group after implementation).

---

## Section 5 — Dependency Analysis: every place Order lifecycle touches Warehouse operations

| # | Touchpoint | File:Line | Direction | Impact of config disablement |
|---|---|---|---|---|
| 5.1 | Order creation assigns a flow; **no** warehouse dependency | `OrderFlowService::resolveCheckoutFlow` L265–299; `OrderCreationService::finalizeOrder` L571 | none | none |
| 5.2 | Reservation creation (order-level) | `OrderReservationService` (aggregatePhysicalLines; cited from `FulfillmentService` docblock L252–256) | order→stock | none — must stay active (R-04) |
| 5.3 | Auto-release on payment success | `ReleaseFulfillmentOnPayment` (`EventServiceProvider:179`) | order→warehouse | **must be suppressed + logged** |
| 5.4 | Auto-release on COD/cashier placement | `ReleaseFulfillmentOnCodPlacement` (`EventServiceProvider:159`) | order→warehouse | **must be suppressed + logged** |
| 5.5 | Hourly release sweeper | `ReleaseReadyFulfillments` (`Kernel:49`) | warehouse→order | **must skip + count** (otherwise it re-creates fulfillments ≤1 h after any disablement — the single biggest “config silently defeated” hazard) |
| 5.6 | Admin explicit release | `FulfillmentController::release` L124–241 | human→warehouse | typed refusal mapped to HTTP (D-3 decision scope) |
| 5.7 | Release rules themselves | `FulfillmentService::assertReleasable` L176–207; single-warehouse WMS-001 L102–111; split keys L93 | internal | unchanged |
| 5.8 | Fulfillment→order tier sync | `FulfillmentOrderSyncService` matrix + walker L91–298 | warehouse→order | inactive when no fulfillments exist (L257–259 returns null) — no modification required |
| 5.9 | Shipping gate during shipment creation | `ShipmentService:63,144` → `WarehouseWorkflowService::assertShipmentAllowedFor` | stage config | unchanged (Q-05; pinned versions) |
| 5.10 | Delivery completion invariant | `OrderService::orderSatisfiesDeliveryCompletion` L1522–1535 + `ShipmentService::maybeCompleteOrder` L401–433 | warehouse→order | **with no fulfillment ⇒ delivered refused** (force-delivered audit path remains) — Decision D-2 |
| 5.11 | Cancellation cascade | `OrderService:1411–1460` | order→warehouse | unchanged; no live fulfillments exist ⇒ no-op |
| 5.12 | Leg legacy columns | `mapToLegacyOrderStatus` L934–944; mirror `syncOrderMirror` L529–548 | read-model | unchanged |
| 5.13 | Workflow pinning at fulfillment creation | `FulfillmentService:232–241` + `Fulfillment:61–66` | config | unchanged |
| 5.14 | Packing verify evidence | `wms.packing.*` (lang) + `PackingService` verify → `ready_to_ship` | stage | unchanged |
| 5.15 | Picking claim/lease | `PickingExecutionService` (`config/fulfillment.*`) + sweeper `picking:sweep-expired-claims` | ops | no order dependency |

★ **Verified independence:** inventory reservation commit/release/restore (5.2) and every financial/promotion/coupon side effect run in `changeOrderStatus` regardless of warehouse configuration — disabling warehouse never orphans stock *if release is the only thing gated*.

---

## Section 6 — Proposed Configuration Architecture

> Concept matches the user's hierarchy exactly; implementation reuses existing mechanisms. **No new settings architecture, no new order state machine, no second release path.**

### 6.1 Settings inventory

| Level | Setting name | Storage | Default | Owner |
|---|---|---|---|---|
| L0 — Master config | `warehouse.module_enabled` | new `config/warehouse.php` ⇒ `env('WAREHOUSE_MODULE_ENABLED', true)` | `true` | Deploy/environment |
| L1 — Website module | `warehouse.module_enabled` (group `warehouse`) | `Settings.options.warehouse.module_enabled` | `true` | Admin (RBAC) |
| L2 — Workflow | `warehouse.workflow_enabled` | `Settings.options.warehouse.workflow_enabled` | `true` | Admin (RBAC) |
| L3 — Stages | `picking_enabled`, `packing_enabled`, `shipping_enabled`, `order_received_enabled` *(QC excluded — Q-01)* | `warehouse_workflow_versions` (existing, immutable, pinned) | `true` (all) | Admin (`WAREHOUSE_WORKFLOW_PUBLISH`) |

**Naming rationale:** `module_enabled` and `workflow_enabled` are self-describing; the group key `warehouse` namespace-parallelizes `fast_shipping` (existing convention `Settings.options.{feature}` — Verified §2.2). The user's suggested `config/warehouse.php` name is adopted verbatim.

### 6.2 Effective resolution (single source of truth)

New `app/Services/Warehouse/WarehouseConfigurationService`:

```php
moduleAvailable(): bool      // config('warehouse.module_enabled') && settings-key
workflowActive(): bool       // moduleAvailable() && settings(warehouse.workflow_enabled) [new orders]
workflowForNewFulfillments() // == workflowActive() (release suppression semantics)
stageFlagsFor(Fulfillment)   // delegated to existing WarehouseWorkflowService (pinned version)
assertModuleAvailable(): void     // typed exception WarehouseModuleDisabledException
```

- **Precedence rules:** `L0 OFF ⇒ OFF` regardless of L1 (the AND is total; L1 cannot override L0 — implements the user's truth table 1:1). `L1 OFF + L0 ON ⇒ module unavailable ⇒ workflow irrelevant`. `L2 OFF + module ON ⇒ module available, order releases suppressed (workflow bypassed)`. `L3` operates per fulfillment as today.
- **Fail-open/fail-closed semantics:** config read cannot fail (static). Settings row missing ⇒ defaults `true` (matches legacy behavior of everything being enabled when unconfigured — consistent with `WarehouseWorkflowService::currentOrDefaults` and quick-ship `defaults()` patterns). Unexpected infrastructure errors while reading settings are reported and default to enabled (fail-open) because the risk of blocking revenue logistics on a cache bug is higher than the reverse — flagged R-08 for confirmation.
- **Caching:** own cache key `warehouse_settings` (TTL 3600) read in `getSettings()`-style; **`Cache::forget('warehouse_settings')` on every write** (fast_shipping pattern, Verified). Does **not** touch the defective `cached_settings_{locale}`. Config-level changes are boot-time: production requires `php artisan config:clear` + rebuild / redeploy (runbook; R-01).
- **Immutability interplay:** stage flips only via `publish()` (immutable new version + pinning) — properties of Scenario E/F are inherited unchanged.

### 6.3 What each level actually controls — verified capability split

| Capability | Belongs to | Gated by |
|---|---|---|
| Creating fulfillments (auto: payment/COD listeners; sweeper; admin endpoint) | Workflow + Module | `moduleAvailable()` ⇒ `workflowActive()`; `releaseForOrder` authority asserts; listeners/sweeper skip early |
| Picking/packing/shipping task machines | Workflow stages | pinned version per fulfillment (unchanged Q-03/Q-05) |
| New shipments | Stage `shipping_enabled` | existing gate only |
| Dispatch/deliver of existing shipments | Module lifecycle | unaffected by all new switches (preserve) |
| Inventory reserve/commit/release/restore | Order lifecycle (never warehouse) | **unaffected** (verified) |
| Warehouse/location/stock/transfer/barcode/dashboard admin | Module | **unaffected by any switch** (admin tool required to manage in-flight work and re-enablement) |
| Digital fulfillment | Payment path | unaffected |
| Batch creation | Workflow stage (works on pending fulfillments) | inactive naturally when no new fulfillments |

### 6.4 Admin API / UI surface

- Extend `GET/PUT settings` (existing, permission `view-settings`/`update-settings`) with a validated `warehouse` options group, **or** (mirroring `fast-shipping/settings`) add `GET/PUT admin/warehouse-settings` with reused `view-settings|update-settings` permissions to avoid a permission-migration — **Recommendation: dedicated controller under `app/Http/Controllers/Api/Admin/Wms/WarehouseSettingsController.php` + routes in `Rest/Routes.php` (same file the rest of WMS routes live in), reusing `view-settings|update-settings`; expose `module_available` + `workflow_active` computed values so the admin UI can show the effective state (and so the response visibly implements the user's truth table).**
- The **stage** UI already exists (`warehouse-workflow` show/history/publish).
- External admin app: **no admin SPA lives in this repo** (`resources/js` holds only utility JS — app.js/bootstrap.js/file-operations/cursor-pagination; `resources/views` holds emails/pdf/products templates) — the admin frontend consumes these APIs from a separate application, so Phase 6 ships backend contract + resource payloads only.

### 6.5 What NOT to do (anti-goals honored)

- No second order state machine (order writes stay exclusively in `changeOrderStatus`; fulfillment writes in `FulfillmentTransition` — enforced by existing design and preserved).
- No migration touching existing data (v1 has none).
- Not “UI hiding”: backend gates live in the release authority + job/console skips; admin surface stays available.
- Not “workflow-off disables inventory”: reservations/orders untouched.

---

## Section 7 — Scenario Matrix

Legend: **C** = master config, **W** = website module setting, **WF** = workflow switch, **S** = stage flags (published version). “Effective” = what the resolver returns.

| # | C | W | WF | Stages | Effective module | New orders (release) | Existing in-flight fulfillments | Pending ready_to_ship / shipments | Existing orders w/o fulfillment | Re-enable (redo C/W→ON) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | ON | ON | ON | all ON | **available** | released normally (as today) | unaffected | unaffected | — | — |
| 2 | ON | ON | ON | shipping OFF | available, shipping stage off | released; fulfillment pins new version | old versions keep own flags | new shipments blocked for that version; existing continue (verified test L255) | — | — |
| 3 | ON | ON | ON | picking/packing attempt OFF | publish refused (Q-03, verified L100–107) | — | — | — | — | — |
| 4 | ON | ON | OFF | any | **module available, workflow suppressed** | **automatic release skipped**; admin release allowed (D-3) | untouched; stages still run if released | shipments OK for existing | order progresses by admin usages; delivered needs D-2/force-delivered | workflow re-ON: sweeper + listeners resume; nothing replayed except missing relesable orders (safe, keyed) |
| 5 | ON | OFF | — | — | **unavailable** | release refused everywhere incl. sweeper; listeners skip | untouched (pinned) | shipments/dispatch/deliver continue for existing rows | order sticks (progression per §8); inventory intact | **sweeper auto-heals** — every skipped releasable order releases within ≤1 h of W→ON (existing behavior, keyed idempotent) |
| 6 | OFF | ON | — | — | **unavailable** (L0 authority) | same as #5 | same | same | same | config cache rebuild needed; then same as re-enable #5 |
| 7 | OFF | OFF | — | — | unavailable | same as #5 | same | same | same | same |
| 8 | ON | ON | ON | any | *stage-restricted* | released | — | shipping-OFF orders wait at ready_to_ship; re-publish ON resumes | — | — |
| 9 | ON→OFF **mid-flight** | — | — | — | unavailable | release refused (atomic) | untouched | dispatch/deliver unaffected (module lifecycle retained) | stayed orders later auto-released when back ON | n/a |
| 10 | WF ON→OFF with orders mid-picking/packing | — | — | — | module available | new releases stop being auto-created | **orders continue their tasks** (version-pinned; no mid-air change) — safe | ready_to_ship fulfillments still dispatchable; maybeCompleteOrder works | — | WF→ON: automatic releases resume; no replay of historical events |

Danger highlighted because evidence-driven: **#5/#6 with the sweeper** — without an explicit skip, `fulfillment:release-ready` would defeat the disablement hourly (R-05).

---

## Section 8 — Order Lifecycle Impact per Scenario

### Scenario A — Master config OFF (C=OFF, any W)
- Order statuses: unchanged mechanics — orders still created `pending`, flow assigned; payment still lands `completed` (F-1 etc.). Inventory commit at completion still runs (verified L1274). Cancellation restores/releases (L1325–1340).
- New orders: **no fulfillment ever** (release authority refuses; listeners skip; sweeper skips).
- Progress without warehouse: tier-sync inert (no fulfillments). Orders may therefore rest at `pending`/`processing`. **`delivered` is structurally blocked** by the Phase-8 invariant (`orderSatisfiesDeliveryCompletion` requires ≥1 fulfillment) unless the operator uses the existing audited force-delivered path (L1050–1070 + permission `update-order-status`) — or D-2b (approved relaxation) is implemented.
- No stuck-state corruption (no tasks, no packages, no shipments created). Legacy `order_status`/`fulfillment_status`/mirror columns behave per the canonical path.
- ★ **Physical-store finding:** normal admin linear flow (`getFlowAwareStatusTargets` L887–922 offers successor + exits incl. `completed`) lets ops move an order to `completed` — i.e., business-wise “fulfilled & paid” — with the delivered logistics state left unexercised. This is *already* how digital orders complete (see `WarehouseWorkflowTest:344`).

### Scenario B — Config ON, website setting OFF
Effective behavior equals Scenario A from the *application's* point of view (single resolver). Difference: changeable at runtime by an admin, so it interacts with concurrency:
- Release-time assertion happens inside the `releaseForOrder` transaction ⇒ no torn state from W flips mid-release; duplicate protection untouched (idempotency keys; pending reuse).
- Listeners that already passed the release and get refused surface rewrites as retryable `RuntimeException` (queue redeliver → terminal-state skip not applicable → they exhaust and are logged) — hence the **early skip** change in listeners/sweeper (Phase 3) so a flip doesn't churn the queue.
- Settings-level staleness is eliminated by the dedicated cache key (verified stale-cache defect in SettingsRegressionTest; fast_shipping pattern avoids it).

### Scenario C — Module ON, Workflow OFF (WF=OFF)
- Deliberately **not** identical to A: module remains available.
- Orders: automatic release suppressed ⇒ orders don't wait for stages; progression stays payment-driven (what the “Workflow Bypassed” branch means in this repo's vocabulary).
- Inventory: untouched (order-level).
- Fulfillment creation: skipped automatically; admin *may* still release explicitly (D-3; recommendation: allow) — any released fulfillment operates under its pinned version (stages as configured at publish time).
- Existing fulfillments: continue their DAG (dispatch/deliver/etc.); maybeCompleteOrder continues to work for orders already released — no re-purposed config rewires them.
- Shipments can still be created explicitly for those fulfillments (stage `shipping_enabled` continues to be read from their pinned age — Q-05 semantics preserved).
- Reversibility: flipping WF back ON means automatic releases resume. Because `releaseForOrder` is keyed/idempotent, orders that *did* get explicit releases are never duplicated; orders *without* releases are picked by the sweeper within the hour.

### Scenario D — Individual stage effects (with current contract)
| Stage | Before order reaches it | Order inside it | Dependency | Skip safety |
|---|---|---|---|---|
| Order Received | mandatory (publish refuses OFF) — Q-07 verified L94–96 | — | always on | not applicable |
| Picking | **cannot disable** — `pending→picking` is the only forward edge (model L157); publish refuses (verified) | tasks operate normally; DAG unaffected | required before `picked|packing` | unsafe — rejected at config time |
| Packing | **cannot disable** — `packing→ready_to_ship` is the only edge to a shippable state (verified L104–107) | packing/verify continues | required before `ready_to_ship` | unsafe — rejected |
| Quality Check | excluded from publish payload (Q-01 verified L84–86) | — | — | excluded by design |
| Shipping | disableable (verified) | **new shipments blocked** (clean, pre-mutation, in-transaction gate at ShipmentService:144), already-dispatched/delivered continue; ready_to_ship rows wait | fulfillment `ready_to_ship→shipped|delivered` path | safe by design; recovery = re-publish shipping ON (new version; older fulfillment keeps old version unless re-released — note: version pinning means re-publication applies to *new* fulfillments; existing pinned rows need re-navigation — that's the verified in-flight behavior, tests L255) |

Disabling a stage never creates duplicate execution: gates refuse *before mutation* and never fabricate completions (physically-shipped orders cannot be marked delivered without shipment evidence — verified tests L305–323).

### Scenario E — Configuration changed while orders exist
- **Publish of a new stage version** while orders are picking/packing/shipping: no effect on in-flight work (pinning Q-04, model write-once guard). Verified test `test_new_fulfillments_pin_current_existing_retain` L218.
- **Workflow OFF** while orders mid-pipeline: nothing running stops (no listener runs on stage transitions except sync — unrelated to WF). New payments do not release forever until re-enabled.
- **Module OFF** while orders mid-pipeline: dispatch/deliver are NOT gated by the new switches (they operate on existing rows) — intentional (preserve custody/lifecycle). Only *new* work stops.
- Auditable transition: our resolver logs a single `WARNING` per suppressed release attempt (order id + actor + reason) — enough for a reconciliation report; an optional one-off artisan dry-run command is proposed in Phase 7 (prevents silent abandonment, no auto-deletes, no releases).

### Scenario F — Re-enabling module or workflow
- **Do not replay historical events: verified by construction.** Listeners run on future events only; sweeper selects only *currently* releasable orders (L53–70); the keyed idempotency guarantees at-most-one fulfillment per automatic trigger.
- Existing orders missing fulfillment: healed automatically by sweeper (≤1 h) once module becomes available again — matches the user's “no silent duplication” requirement (the key + pending-reuse + UNIQUE backstop guarantee convergence, verified `AutoReleaseDedupeRegressionTest` exists precisely for this).
- No reconciliation writes are performed automatically beyond this existing behavior.

---

## Section 9 — Implementation Plan (adapted, ordered)

**Phase 1 — Configuration foundation (no behavior change)**
1. New `config/warehouse.php` (master `module_enabled`, documented header + env `WAREHOUSE_MODULE_ENABLED`, default true) — same declarative style as `config/fulfillment.php`.
2. `.env.example` additions.
3. Unit test asserting config exists and default true (pattern: `WarehouseDefaultTest`).

**Phase 2 — Centralized effective-configuration resolver**
4. `app/Services/Warehouse/WarehouseSettingsService` (read/write `Settings.options.warehouse`, defaults, `Cache::remember('warehouse_settings', 3600)` + `Cache::forget` on write; transactional row lock).
5. `app/Services/Warehouse/WarehouseConfigurationService` (L0×L1 ⇒ `module_available`; ⇒ workflow_active; typed exceptions `WarehouseModuleDisabledException`/`WarehouseWorkflowDisabledException`).
6. Exception translations (`resources/lang/{en,ar}/wms.php`) — note repo pattern of bilingual capability.

**Phase 3 — Warehouse service integration (release authority enforcement)**
7. `FulfillmentService::releaseForOrder` — assert `moduleAvailable()`; with `workflowActive()==false` skip **automatic** semantics only by *return contract*: implement via a service-level `getAutomaticReleasePolicy()` consulted by the three automatic surfaces (kept in ONE place to avoid scattered checks; see §6.1).
8. `ReleaseFulfillmentOnPayment` + `ReleaseFulfillmentOnCodPlacement` — early-skip when the policy is off (log with order_id and reason; count nothing silently).
9. `ReleaseReadyFulfillments` — extend the select/predicate (`whereNotExists` → also skip+count a `suppressed` counter when the policy is off; dry-run report stays identical).
10. `FulfillmentController::release` — map typed disabled/refusal exceptions to HTTP 409/423 + existing `unprocessable()` style; bulk summary surface stays (per-order failure reasons).
11. Settings write API for the two switches (permissions reused `view-settings|update-settings`), plus `SettingResource` exposure of `warehouse` group + **effective merged state**.

**Phase 4 — Order lifecycle integration (verification-only scope)**
12. Add feature tests proving: order completed → commit ran when module OFF; cancellation paths unaffected (reuse `Phase3InventoryCommitTest` patterns); sync listener does not misbehave with zero fulfillments (already covered by `WmsFulfillmentOrderSyncTest`).
13. **Only if Decision D-2b approved:** migration `orders.warehouse_release_state` (nullable varchar(24)) + stamping inside the suppression point + relaxation branch in `orderSatisfiesDeliveryCompletion` + dedicated tests (migration reversible, additive, no data back-fill).

**Phase 5 — Workflow & stage controls (mostly “document + verify”)**
14. No change to `WarehouseWorkflowService`/versions/Q-contracts (explicitly out of scope by design).
15. Extend `wms.php` translations to cover new disablement messages; add docs/API story **only on explicit request** (documentation safety mode off).

**Phase 6 — Admin settings UI/API**
16. `WarehouseSettingsController` (`GET/PUT admin/warehouse-settings`) + routes + tests + resource exposure of effective values; response documented for the external frontend team.

**Phase 7 — Existing-order reconciliation (optional, explicit)**
17. Optional artisan command `warehouse:config-reconciliation --dry-run` (read-only report of stuck releasable / suppressed orders); **no auto-fixes** beyond the existing sweeper. Decision D-4 gates whether to build it at all in v1.

**Phase 8 — Automated tests**
See §11 for the full matrix; new `WarehouseConfigurableControlsTest` (matrix #1–#10), plus updates to `AutoReleaseWiringTest`, `ReleaseReadyFulfillments` coverage, HTTP permission tests for the new route, and settings CRUD/validation tests for the new group.

**Phase 9 — Regression & verification**
18. Full targeted suites: `tests/Feature/Wms` (MySQL, sequential), `Release*`, `Inventory`, `OrderFlow`, `Settings`; static enum/permission checks (`PermissionCoverageStaticTest` pattern); final review + route existence assertions for every touched URL (`route:list` evidence, per verification policy).

---

## Section 10 — Exact file-level change plan

| # | File (actual path) | Current responsibility | Proposed change | Reason | Risk |
|---|---|---|---|---|---|
| 1 | `config/warehouse.php` *(new)* | — | master `module_enabled` + env | L0 authority | low |
| 2 | `.env.example` | env documentation | add `WAREHOUSE_MODULE_ENABLED=true` (+ example cache key for settings if needed) | discoverability | low |
| 3 | `app/Services/Warehouse/WarehouseSettingsService.php` *(new)* | — | read/write website switches w/ dedicated cache | L1/L2 storage | low |
| 4 | `app/Services/Warehouse/WarehouseConfigurationService.php` *(new)* | — | resolver + assertions + typed exceptions | single source of truth | low |
| 5 | `app/Services/Fulfillment/FulfillmentService.php` | release/creation authority; assertReleasable L176–207 | inject resolver; assert availability before any mutation (inside txn, after idempotent replay check so replays still converge) | enforce once for all four surfaces | medium (placement after replay-lookup to keep idempotency as first-class) |
| 6 | `app/Listeners/ReleaseFulfillmentOnPayment.php` | auto-release on payment | resolver check before work (skip+log) | queue hygiene on flips | low |
| 7 | `app/Listeners/ReleaseFulfillmentOnCodPlacement.php` | auto-release on placement | same | same | low |
| 8 | `app/Console/Commands/ReleaseReadyFulfillments.php` | recovery sweeper | policy-gated skip + `suppressed` counter reporting | prevents sweeper defeating config | low |
| 9 | `app/Http/Controllers/Api/Admin/Wms/FulfillmentController.php` | release endpoints (single+bulk) | typed mapping (409/423) + messages | admin clarity | low |
| 10 | `app/Http/Controllers/Api/Admin/Wms/WarehouseSettingsController.php` *(new)* | — | GET/PUT warehouse settings (`view-settings|update-settings`) | admin surface | low |
| 11 | `packages/marvel/src/Rest/Routes.php` | route registry (incl. WMS admin routes L557+) | register `admin/warehouse-settings` (GET/PUT), tag-cache integration like `fast-shipping/settings` | admin surface | low |
| 12 | `packages/marvel/src/Http/Resources/SettingResource.php` | settings serialization | add `warehouse` group + `warehouse_effective` computed values | frontend/UI visibility | low |
| 13 | `resources/lang/en/wms.php` (+ `ar` etc. — localization parity runbook) | WMS catalogue | new keys for disablement messages | localization discipline | low |
| 14 | **Optional (D-2b):** `database/migrations/xxxx_add_warehouse_release_state_to_orders_table.php` *(new)* | — | nullable evidence column | stamping for invariant relaxation | medium — requires approval |
| 15 | Tests *(new files)* | — | `tests/Feature/Wms/WarehouseConfigurableControlsTest.php`, `tests/Unit/Services/Warehouse/WarehouseConfigurationServiceTest.php`, `tests/Unit/Services/Warehouse/WarehouseSettingsServiceTest.php` + edits to `AutoRelease*` | coverage | low |

**Explicitly untouched (by design):** `WarehouseWorkflowService`, `WarehouseWorkflowVersion` model + its migration, `ShipmentService` gates, `FulfillmentTransition`, `OrderFlowService`, all existing settings schemas, `FulfillmentOrderSyncService`, inventory services, `OrderService` internals (except optional D-2b), all controllers' business rules (except release-mapping additions).

---

## Section 11 — Testing Strategy

**Environment constraints (verified):** MySQL `catch` DB; `CACHE_DRIVER=array`; `QUEUE_CONNECTION=sync`; WMS suites sequential + `RefreshDatabase` with real migrations. Config toggle in tests via `config(['warehouse.module_enabled' => false])` (array cache already isolates settings-cache tests).

### 11.1 Unit
1. `WarehouseConfigurationServiceTest`
   - L0 OFF ⇒ unavailable regardless of L1/L2 (truth-table parity — matrix #6/#7)
   - L0 ON, L1 ON/OFF ⇒ available/unavailable
   - workflow semantics: L2 OFF ⇒ workflowActive false + moduleAvailable true (#4)
   - settings row absent ⇒ defaults true (legacy parity)
   - cache: key written, forget on write (adapt `FastShippingRepositoryTest` pattern) — proves no 24 h staleness
2. `WarehouseSettingsServiceTest`
   - concurrent update merge via lockForUpdate (no lost updates)
   - validation shape mirrors `FastShippingController::updateSettings`
3. Resolver + sweeper predicate interplay: `ReleaseReadyCountersTest` (assert `suppressed` counter and DB untouched when off).

### 11.2 Feature (release surfaces, per matrix)
For each of matrix rows #1–#10:
- online paid order ⇒ `PaymentSucceeded` dispatched (queue sync) ⇒ release honored/skipped; `auto-release-order-{id}` key semantics unchanged (`AutoReleaseWiringTest` extensions).
- COD/cashier placement ⇒ listener skip/refusal.
- hourly sweeper with 3 orders (releasable/not releasable/suppressed) ⇒ counts + exit code; **no fulfillment rows for suppressed**.
- admin release: HTTP code + localized message; bulk mode: `failed[]` includes disablement reason; replay idempotency preserved (single row), and 200-replay distinction not confounded by disablement errors.
- in-flight protection: publish stage version #1 (shipping OFF), release orders, then publish #2 (shipping ON) ⇒ old fulfillment stays gated by #1 (extend `test_shipping_disabled_blocks_new_shipments_but_existing_continue` L255).
- workflow OFF ⇒ no automatic release, admin explicit release creates and stages run under pinned version(s) (D-3 accepted variant) — or refuses entirely (D-3 strict variant).
- order-integrity like current `test_physical_order_cannot_complete_without_shipment_evidence` (L305) must hold ⇒ keep invariant tests green (or explicit D-2b relaxation suite for stamped-only relaxation).
- inventory: `Phase3InventoryCommitTest` parity with module OFF; cancellation from committed state ⇒ restore; from active ⇒ release (matrix in existing suites).
- concurrency: settings flip racing with releaseForOrder transaction — exactly-one outcome (idempotency + row lock; adapt `ConcurrentPublishes`/`AssignmentRaceTest` style).
- re-enable convergence: module OFF → paid orders pending release; W→ON; run sweeper ⇒ released once, no duplicates (deterministic sweep minutes not asserted; rows only).
- config-level tests: default value true; env override reflection (adapt `WarehouseDefaultTest`).
- permission: GET/PUT settings route (admin-only) via `WmsAuthorizationGateTest` pattern + 403 matrix; stage publish remains RBAC-guarded.

### 11.3 Localization / regression battery
- locale parity test for new keys (existing `NestedAttributeLocalizationTest` style); permission static coverage runs (`PermissionCoverageStaticTest`).
- Order-flow regression: all `tests/Feature/OrderFlow` unchanged-green.

---

## Section 12 — Risks & Open Questions

### 12.1 Verified facts (already cited inline)
- The delivered-invariant refusal when zero fulfillments; force-delivered escape hatch exists (`update-order-status`, reason, audited).
- Sweeper auto-heals relesable orders hourly and is idempotent per automatic key.
- Settings row-wide cache staleness defect (24 h, no invalidation) — mitigated in our design by dedicated key.
- Picking/Packing unsafely unskippable (fulfillment DAG); publishing their disablement is refused.
- Inventory lifecycle is order-level and warehouse-independent.
- Stage versioning pins at creation; immutable; concurrent publishes safe.

### 12.2 Strongly inferred (multi-signal, not runtime-verified here)
- R-01: production runs `config:cache` — env changes need rebuild; resolver must not be mutated via config write (we don't).
- R-05: without Phase-3 sweeper gating, auto-release defeats disablement hourly. *(mitigation planned; cite `fulfillment:release-ready` schedule `app/Console/Kernel.php:49`)*
- R-03/233: queue churn on flips if listeners don't skip early (see Phase 3) — inferred from `tries=5` + transient rethrow patterns.
- Digital/payment listener separation assumptions (`FulfillmentDigitalProducts` distinct path) — verified by wiring but its deep behavior cited from docblocks.

### 12.3 Unknown (cannot be determined from repo)
- Deployment topology: hourly scheduler + queue workers presence on the target environments (commands exist; worker count unknown).
- Any reporting/jobs listing `orders` by fulfillment state that might *display* stuck orders in ways not covered here (dashboards are read-only anyway).
- Whether external frontends rely on `options` JSON strict schemas (additive-only change assumed safe).

### 12.4 Architectural risks (rated)
- R-08 config(criteria) fail-open on settings infra errors while gates have revenue implications — default true chosen deliberately; revisit if a rate-limit/OOM fault class appears (documented).
- R-09 workflow-ON semantics for admin manual release under WF=OFF requires explicit acceptance (D-3) — without it there is ambiguity in the admin contract.
- R-10 invariant relaxation (D-2b) is the only change touching a Phase-8 guard — highest-blast-radius single decision.

### 12.5 Decisions requiring explicit approval
| ID | Decision | Options |
|---|---|---|
| **D-1** | Config file name | (a) `config/warehouse.php` with `module_enabled` *(recommended)* (b) `enabled` (c) rebadging under `fulfillment.php` (rejected: module ≠ mechanics) |
| **D-2** | Behavior when orders cannot fulfill due to config disablement | (a) **Z — unchanged invariant + existing force-deliver** (recommend v1; zero risk) (b) **B — config-aware delivered relaxation using stamped evidence** (better ops UX; migration + guard change; requires explicit design review) |
| **D-3** | Admin manual release when WF=OFF (module ON) | (a) allowed (recommended; audited via existing logs) (b) refused for strict isolation |
| **D-4** | Build Phase-7 reconciliation command now | (a) skip; rely on sweeper + Db log (recommended) (b) build dry-run reporting command |
| **D-5** | Settings endpoint shape | (a) `admin/warehouse-settings` dedicated (recommended, settings-family precedent) (b) extend `PUT settings` whitelist |
| **D-6** | Permission set for the new settings endpoint | (a) reuse `view-settings|update-settings` (recommended, no new permission seeding) (b) new `WAREHOUSE_SETTINGS_*` permissions |
| **D-7** | Fail-open on settings-read infra errors | (a) default-true + `report()` (recommended for revenue paths) (b) fail-closed + dashboard alert |
| **D-8** | Workflow stage contract changes (e.g. allow skipping picking/packing via a new DAG edges) | **out of scope — v1 keeps Q-03 as-is** (current contract verified safe and previously user-approved via Q-03) |

---

## Section 13 — Final Recommendation

**Recommended architecture (why):**

- **One resolver, one enforcement point, no new machines.** `WarehouseConfigurationService` centralizes L0×L1×L2; `FulfillmentService::releaseForOrder` (the canonical release authority) enforces it; the three automatic surfaces skip early for queue hygiene; stages continue to live in the immutable versioned system with per-fulfillment pinning. This yields the user's truth table without touching `OrderService`, `OrderFlowService`, or any state machine.
- **Reuses the two proven patterns** (`FastShippingRepository` for site toggle storage/caching; WarehouseWorkflowService for stages) — every option has real precedent in this repo, minimizing new abstractions.
- **Safety characteristics inherited, not re-specified:** idempotent keyed release, append-only config, pinning, canonical order-writer, order-level inventory.
- **Scalable to future requirements** (per-warehouse/warehouse-group settings, marketplace tenancy) by expanding the resolver contract instead of adding call-site checks.

**Alternatives rejected:**
1. *Gate at the four call sites only* (no authority-level assertion) — future callers bypass silently; doesn't survive review for a single-truth design.
2. *Add stage-level skip edges to the fulfillment DAG* (pending→ready_to_ship when picking off) — violates the standing Q-03 contract, removes physical verification guarantees the workflow tests rely on.
3. *Represent workflow_off as a blunt "module off"* — conflates deliberately split concepts (the user's diagram).
4. *Warehouse-per-row settings* — no demand signal today; YAGNI.

### Verification gap to close post-implementation
`route:list` evidence for the new endpoints; `php artisan test` specific WMS/Inventory/OrderFlow/Settings suites per §9 Phase 9; cache self-test (`tests/performance/hascache-selftest.php` covers tag flush correctness).

---

## Section 14 — Final Gate — Summary for Approval Review

1. **Current implementation:** workflow stages configurable via immutable published versions + pinning + shipping gate (Q-01..Q-08); no master/website module switch; release authority centralized with 4 surfaces; sweeper is critical for disablement strategies; delivered invariant enforces shipment-evidence completion.
2. **Recommended architecture:** config/warehouse.php + Settings.options.warehouse via WarehouseSettingsService + WarehouseConfigurationService resolver + release-authority enforcement + skip behavior in listeners/sweeper + no migration required for v1.
3. **Files to modify:** §10 table (13 files + 4 new tests; 1 optional migration only for D-2b).
4. **Behavior per combination:** §7 matrix (10 rows) implemented wholesale by the resolver.
5. **Existing order protection:** in-flight work pinned/unaffected; no destructive reconciliation; re-enable = sweeper convergence; stage re-publish must not retro-apply (documented post-gate); release skips always logged; one optional stamped-evidence design (D-2b) strictly additive.
6. **Unresolved business decisions:** D-1..D-8 (§12.5) — D-2, D-3, D-4, D-5, D-6, D-7, D-8 are open for your call; D-8 is recommended to keep as-is.
7. **Implementation phases:** §9 (9 phases).

**STOP — awaiting explicit approval before any implementation begins.**
