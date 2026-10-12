# Shipping Flow API Contracts (verified implementation)

Conventions below are verified against the listed source files and the
passing tests in `tests/Feature/OrderFlow/MultiFlowPerTypeTest.php`.
Envelope: Marvel `apiResponse` → `{success, message, data?, meta?}` for
admin/definition endpoints; checkout errors for domain failures use
`{success:false, message}` with the HTTP status shown.

## 1. Admin — list flows

- `GET /api/v1/admin/order-flows` — auth `sanctum`, permission
  `view-order-flows|view-orders|view-order`.
- Query: `shipping_type` (filter, exact), `is_active` (bool), `search`
  (code/name LIKE), `per_page` (1–200, default 50).
- 200 `data`: `OrderFlowResource[]` (`id, code, name{en,ar}, shipping_type,
  is_default, is_active, statuses[] when loaded, created_at, updated_at`),
  `meta: {current_page, per_page, total}`.
- Source: `app/Http/Controllers/Api/Admin/OrderFlowController.php:index`,
  routes `packages/marvel/src/Rest/Routes.php:521-525`. Unchanged by this task.

## 2. Admin — show flow

- `GET /api/v1/admin/order-flows/{id}` — same auth as list.
- 200 `data`: flow resource + `inputs: FlowInputResource[]`.
- 404 unknown id. Unchanged.

## 3. Admin — create flow (multi-flow capable)

- `POST /api/v1/admin/order-flows` — permission
  `create-order-flows|update-order-status`.
- Body (verified): `code` (string ≤50, UNIQUE), `name` (object
  `{en required ≤100, ar nullable}` or legacy string → `{en}`),
  `shipping_type` (required, MUST be `local|international` — arbitrary strings
  422), `status_ids` (required non-empty ordered id array; order IS sort_order),
  `is_default` (bool, default false), `is_active` (bool, default true).
- **New:** a second flow with an already-used `shipping_type` returns **201**
  (verified test: `local_simplified` on `local`). Duplicate `code` → 422.
  Unsupported type (e.g. `same_day`) → 422, nothing persisted.
- 201 `data`: created flow resource. 422 validation / invalid statuses.
- Source: `OrderFlowController:store`, `OrderFlowUpsertRequest`.

## 4. Admin — update flow

- `PUT /api/v1/admin/order-flows/{id}` — permission
  `update-order-flows|update-order-status`.
- All fields optional (`sometimes`). **New:** `shipping_type` MAY change to
  another supported type (validated allow-list); existing orders keep their
  stored `flow_id` and are never rewritten (verified test).
  Updating without changing the type succeeds (no self-collision: `code`
  uniqueness uses `ignore(id)`; no type uniqueness remains).
- Deactivation guard unchanged: deactivating the LAST active flow of a type →
  422 `Cannot deactivate the last active flow for this shipping type.`
  (concurrent writers serialized via `lockForUpdate`).
- `is_default: true` demotes the previous default of the same type (most
  recent wins). 200 `data`: updated flow resource. 404 unknown id.

## 5. Storefront — discover flows

- `GET /api/v1/general/order-flows/available` — guest-safe, no auth. Returns
  every ACTIVE flow: `{flows: [{shipping_type, code, name{en,ar},
  statuses[{code,name,sort_order}], inputs[{key,label,type,...}]}]}`.
  Internal `id/is_default/is_active` never exposed (verified resource).
- `GET /api/v1/general/order-flows/by-shipping-type/{type}` — guest-safe.
  Returns the type's **DEFAULT** flow in the same sanitized shape (backward
  compatible). Unknown type → 422. Multiple active flows with no single
  default → 422 ambiguous (fail-closed).
- Source: `FlowDefinitionController`, `AvailableOrderFlowResource`.

## 6. Storefront — checkout with explicit flow

- `POST /api/v1/general/checkout` — auth `sanctum`. Existing fields unchanged.
- **New optional fields:** `flow_code` (string ≤50, `exists:order_flows,code`),
  `flow_id` (integer, `exists:order_flows,id`). Prefer `flow_code`.
- Resolution (verified): explicit flow must be active (`flow_inactive` → 422);
  with `shipping_type` also supplied it must match (`flow_mismatch` → 422);
  unknown code/id → 422. Type-only/omitted (→`local`) resolves the default:
  single active flow → it; several → active default; none/ambiguous → 422 and
  NO order is created.
- The validated flow id is pinned into creation (TOCTOU-safe); `flow_values`
  are validated against that exact flow; the order stores `flow_id` +
  `shipping_type` permanently. Pending-order retry with a different explicit
  flow → 422, original order untouched.
- Verified example — two local flows, order bound to the second:
  request `{..., shipping_type:"local", flow_code:"local_simplified"}` →
  200; order row `flow_id=<simplified id>`, `shipping_type="local"`,
  `status="pending"`.
- Pricing unchanged: server-side governorate rates; client never supplies price.

## 7. Frontend integration (external clients)

1. `GET available` → group by `shipping_type`, render each flow's `name`
   (NOT the type) with `code` as the React/Vue `key` and selection value.
2. Submit `flow_code` (+ `shipping_type` for mismatch protection) at checkout.
3. Never key options by `shipping_type` alone; never cache one-flow-per-type.
4. Handle 422 (`flow_ambiguous` → force explicit choice; `flow_mismatch`,
   `flow_inactive` → refresh `available` and re-render).
5. No admin metadata is exposed storefront-side; nothing to strip.
