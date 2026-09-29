# Order Flow — Permissions

Convention: Spatie string permissions (`Marvel\Enums\Permission`),
`permission:` route middleware, seeded in `PermissionSeeder`
(super_admin receives all). New permissions are OR-ed with the legacy
order permission on each route, so existing admins keep working.

## Configuration permissions (new)

| Permission | Purpose | Who | Protects | Does NOT protect |
|---|---|---|---|---|
| `view-order-flows` | See flows, catalog, input definitions, public schema content | super_admin (default; grant as needed) | admin flow reads | order data, transitions, values |
| `create-order-flows` | Create flows | super_admin | `POST order-flows` | statuses/inputs edits, transitions |
| `update-order-flows` | Edit flows, catalog names, publish/archive (via `is_active`) | super_admin | `PUT order-flows`, catalog update | input definitions, transitions |
| `manage-order-flow-inputs` | Create/update/delete input definitions | super_admin | inputs CRUD | flow lifecycle, transitions |

There is intentionally NO `delete-order-flows`: flows deactivate
(`is_active=false`), never delete — `order_flow_statuses` and history
must stay intact. Publish/archive = `is_active` + `is_default`
transitions under `update-order-flows`.

## Transition permissions (granular, per target status) — IMPLEMENTED

Flow validity and actor authorization are separate gates that must BOTH
pass. The flow answers "can this order move there"; the granular
permission answers "may this actor move it there".

| Permission | Purpose | Who | Protects | Does NOT protect |
|---|---|---|---|---|
| `update-order-status` (general) | Use the status mutation API at all | staff/owner/super_admin (route middleware on PATCH) | PATCH entry | any specific target |
| `change-order-status.<code>` (×22, catalog-driven) | Move an order INTO `<code>` | granted per target (seed-compat backfills general-holders) | PATCH per target (asserted in controller + legacy repository path) | flow validity, inputs, payment |
| `payments.mark_paid` | Complete an UNPAID order | finance/admin | unpaid → `completed` (F-1, inside service) | anything else |

Enforcement order on PATCH: route middleware (general) → explicit
granular assert (`OrderFlowService::assertUserCanTransitionTo`, 403) →
service union transition guard (422) → input validation (422) → F-1 and
business rules (422) → mutation. System-driven callers (gateway
callbacks, expiry job, console) carry no user and skip only the granular
assert — their authority comes from provider verification / expiry
policy, and the transition/input/business gates still apply.

Compatibility (explicit, not silent): `OrderStatusPermissionSeeder`
additively grants missing granular targets to every role/user holding
`update-order-status`, so existing administrators keep exactly their
current access. New assignments must include the target permission(s);
general alone yields 403 on PATCH (options show `permitted: false`).

Future statuses: `OrderFlowService::syncTargetStatusPermissions()`
ensures `change-order-status.<new-code>` rows; it runs in
`OrderStatusPermissionSeeder` and as a hook in `OrderFlowSeeder`
(catalog expansion), so no code change is ever needed for new codes.

## Visibility vs modification vs transition

- **See:** `view-orders` / `view-order` (orders), `view-order-flows`
  (definitions). The public schema endpoint needs authentication only —
  it returns definitions, never values. The per-order options endpoint
  needs ownership or a view permission.
- **Modify configuration:** the four flow-config permissions above.
- **Transition an order:** `update-order-status` AND
  `change-order-status.<target>` (both required). Financial completion of
  unpaid orders additionally needs `payments.mark_paid` (F-1, unchanged).
- **Business operations:** shipment (`create/update-shipment`),
  fulfillment (`manage-fulfillment`, `picking/packing-execute`),
  warehouse (`manage-warehouse`), payments (`payments.*`) — all unchanged
  and separate.

## Status-specific permissions (decision — implemented as granular targets)

Per-target permissions ARE implemented:
`change-order-status.<catalog-code>` (one per catalog status, ensured by
`OrderFlowService::syncTargetStatusPermissions()` via
`OrderStatusPermissionSeeder`). A transition requires BOTH the general
`update-order-status` (route middleware) AND the matching
`change-order-status.<target>` (server-side assert, 403 otherwise).
General alone → 403. Target alone (no general) → 403 at the middleware.

```text
role permission (update-order-status + change-order-status.<target>)
  + flow transition (immediate-successor / supervised exits)
  + business authorization (F-1, owner scope, inventory/payment guards)
```

## Compatibility OR on flow-management routes (intentional, kept)

Admin flow/catalog/input routes accept the dedicated permission OR the
legacy order permission (e.g.
`permission:create-order-flows|update-order-status`). This is a deliberate
backward-compatibility bridge: existing administrators keep working
without a permission-migration flag-day, and `OrderStatusPermissionSeeder`
additively grants granular targets to general holders.

It is knowingly over-permissive (a staff holder of `update-order-status`
can reach flow management). Tightening to dedicated-only was evaluated
and DEFERRED: staff role definitions do not yet carry the dedicated
config permissions, and production role assignments cannot be verified
from the repository — removing the OR could lock legitimate
administrators out (see STOP analysis). Revisit trigger: a verified
production role audit + seeder migration granting
`create/update-order-flows` + `manage-order-flow-inputs` to the
intended admin roles first.

## Role → permission → flow → status → operation

```text
super_admin → all (*) → any flow → any valid transition → any operation
staff/owner → update-order-status (+ payments.*) → assigned orders →
  union-valid transitions → non-financial ops (no flow-config)
picker/packer → picking/packing-execute only → fulfillment tasks →
  NO order-status transitions (by design, Phase 13)
customer → create-order/track-order → own orders → checkout only
```

Warehouse roles were deliberately NOT granted flow-config or
`update-order-status` (least privilege preserved).
