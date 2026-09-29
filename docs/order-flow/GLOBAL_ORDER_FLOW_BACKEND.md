# Global Order Flow — Backend (FINAL)

Final identity decisions (authoritative; code, tests, and API agree):

- `shipping_type` is the business/routing Flow identity. Supported values
  are CONTROLLED: `local` | `international`
  (`OrderFlowService::SUPPORTED_SHIPPING_TYPES`, mirrored by
  `OrderCreateRequest` validation). Shipping types are NOT creatable by
  admins; there is no dynamic-type mechanism.
- `code` is a human/admin-readable Flow label (unique for admin
  identification). Flows are NEVER resolved by `code`.
- `is_default` is preserved for backward compatibility but is NOT read by
  resolution and must not become a second selection mechanism.
- Scope is GLOBAL: no tenant/store/shop/country scoping on flows.
- Exactly one ACTIVE flow per `shipping_type` (DB unique); resolution
  never falls back across types and never resolves by `is_default`.

Authority chain (every mutation follows this order):

```text
Request
  ↓
Authentication (auth:sanctum)
  ↓
Permission (spatie middleware, route-level)
  ↓
Order authorization (owner scope / admin perm / F-1 payment gate)
  ↓
Flow resolution (shipping_type → active OrderFlow, fail-closed)
  ↓
Transition validation (legacy map UNION flow immediate-successor)
  ↓
Input validation (FlowInputValidator, checkout | transition:<status>)
  ↓
Business validation (stock, coupon, tax, payment)
  ↓
Database transaction
  ↓
Persistence (domain-owned columns + order_flow_values audit)
  ↓
Status mutation (orders.status mirrors current_status_id)
  ↓
Existing side effects (invoice, inventory, coupon/promotion, notifications)
  ↓
Events/jobs (OrderStatusChanged, PaymentSucceeded, fulfillment listeners)
```

## Checkout flow

1. `POST /api/v1/general/checkout` with optional `shipping_type` and
   optional `flow_values: {key: value}`.
2. `shipping_type` is CONTROLLED (`local` | `international`,
   `OrderFlowService::SUPPORTED_SHIPPING_TYPES`). Omitted/empty defaults
   to `local` so existing clients keep working. Unknown types fail closed
   (422) at request validation; supported types without an ACTIVE flow
   fail closed (422) at resolution. No silent fallback across types.
2. `OrderService::addItemsInOrder()` resolves the flow for `shipping_type`
   (default `local`) and validates `flow_values` in context `checkout`
   BEFORE any row exists. Failure → 422, nothing persisted.
3. `OrderCreationService::createOrder()` creates the order, then
   `assignFlowToOrder()` pins `shipping_type/flow_id/current_status_id`.
4. Validated values persist: business copies to owning columns
   (`origin_country_id`, `destination_country_id`, `customs_reference`)
   + immutable rows in `order_flow_values` (context `checkout`).
5. Payment retry reuses the pending order WITHOUT re-validating or
   changing its flow — AND the requested `shipping_type` must match the
   stored one. Same type (or omitted, defaulting to local) reuses;
   different type fails closed with
   `checkout.pending_order_shipping_type_conflict` (422). A pending
   order can never silently switch flow/shipping type. Fast Shipping
   enforces the same invariant (`fast_pending_order_conflict`).

## Status transition flow

1. `PATCH /api/v1/orders/{id}/status` with `status` + optional `flow_values`.
2. `OrderService::changeOrderStatus(..., $flowValues)`:
   transition guard → input gate (`transition:<status>`) → F-1 payment
   guard → mutation, all inside one DB transaction.
3. Example: `arrived_at_destination_country → customs_clearance` requires
   `customs_reference`. Missing → 422, status unchanged.
4. Validated transition values persist the same way (context
   `transition:<status>`).

## Payment interaction

Payment stays separate. `completed` still requires `payments.mark_paid`
for unpaid orders (F-1); gateway callbacks pass
`$assertPaymentAuthority=false`. Flow inputs never bypass this.

## Shipment / fulfillment interaction

Separate machines (`Shipment::allowedTransitions()`, fulfillment map).
Flows reference them only through input *sources*
(`warehouses`, `pickup_locations`) and shared milestones
(`failed_delivery`, `returned`).

## International / customs flow

Seeded international flow has 11 lifecycle steps (customs included).
Data is carried by inputs: `from_country` + `to_country`
(`select → countries`, required at checkout) and `customs_reference`
(`text`, required at `transition:customs_clearance`), stored on
`orders.origin_country_id / destination_country_id / customs_reference`.

## Permission flow

See PERMISSIONS.md. Reads ride on `view-*`; flow configuration on
`view/create/update-order-flows` + `manage-order-flow-inputs` (each
OR-ed with the legacy order permission for compatibility); transitions
on `update-order-status` (+ `payments.mark_paid` for unpaid completion).

## Localization flow

Status and flow display `name`s are bilingual `{en, ar}` (Spatie
HasTranslations on `order_statuses`/`order_flows`, same convention as
`countries.name`); `code` stays the stable identifier. Input
labels/placeholders/help are admin-authored `{en, ar}` JSON. Validation
messages come from `resources/lang/{en,ar}/checkout.php`. The definition
endpoint returns `{en, ar}` objects; no new localization structure was
invented. Legacy plain-string admin writes are accepted and stored as
`en`.
