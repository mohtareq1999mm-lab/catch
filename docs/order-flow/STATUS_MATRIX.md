# Order Status Matrix

Source: `OrderFlowService::ALL_STATUS_CODES` + seeded flows + union guard
(`OrderService`) + `Shipment::allowedTransitions()`. Seeded membership:
local 6 steps; international 11 steps; other catalog codes are
custom-flows-only vocabulary. `Permission` column: every transition row requires `update-order-status` AND `change-order-status.<that row's code>` (e.g. `processing` → `change-order-status.processing`); `completed` additionally needs `payments.mark_paid` when the order is unpaid.

| Status | Flow(s) | Can enter from | Can exit to | Terminal | Required input | Permission | Side effects |
|---|---|---|---|---|---|---|---|
| pending | both (first) | — (creation) | processing, completed, cancelled | no | from/to_country at checkout (intl) | checkout (customer) | history row, reservation |
| processing | both | pending | packed (flow) + completed/cancelled | no | — | update-order-status + change-order-status.`<this status>` | fulfillment→processing, invoice (first leave-pending) |
| packed | both | processing | shipped (flow) + completed/cancelled | no | — | update-order-status + change-order-status.`<this status>` | history |
| shipped | both | packed | in_transit (intl) / out_for_delivery (local) + completed/cancelled | no | — | update-order-status + change-order-status.`<this status>` | history |
| in_transit | intl | shipped | arrived_at_destination_country + exits | no | — | update-order-status + change-order-status.`<this status>` | history |
| arrived_at_destination_country | intl | in_transit | customs_clearance + exits | no | — | update-order-status + change-order-status.`<this status>` | history |
| customs_clearance | intl | arrived… | customs_cleared + exits | no | customs_reference (transition) | update-order-status + change-order-status.`<this status>` | history, customs_reference stored |
| customs_hold | custom only | (flow-defined) | (flow-defined) | no | — | update-order-status + change-order-status.`<this status>` | history |
| customs_cleared | intl | customs_clearance | local_carrier + exits | no | — | update-order-status + change-order-status.`<this status>` | history |
| export/import_processing | custom only | (flow-defined) | (flow-defined) | no | — | update-order-status + change-order-status.`<this status>` | history |
| local_carrier | intl | customs_cleared | out_for_delivery + exits | no | — | update-order-status + change-order-status.`<this status>` | history |
| out_for_delivery | both | shipped/local_carrier | delivered, failed_delivery, returned + completed/cancelled | no | — | update-order-status + change-order-status.`<this status>` | history |
| delivered | both | out_for_delivery (+completed legacy) | — (terminal source) | YES (source) | — | update-order-status + change-order-status.`<this status>` | fulfillment→delivered, OrderDelivered, notifications |
| failed_delivery | exits | out_for_delivery | out_for_delivery (re-drive), returned | no | — | update-order-status + change-order-status.`<this status>` | history |
| returned | exits | failed_delivery/out_for_delivery | — | functional terminal | — | update-order-status + change-order-status.`<this status>` | history, return flow (separate domain) |
| completed | milestone | any non-terminal | delivered | YES (source, except →delivered) | — (inputs allowed but none seeded) | update-order-status + payments.mark_paid if unpaid | payment=success, inventory commit, coupon/promo finalize, PaymentSucceeded |
| cancelled | exit | any except completed/delivered/cancelled | — | YES | — | update-order-status + change-order-status.`<this status>` | release OR restore inventory, coupon release, OrderCancelled |
| confirmed, ready_to_ship, ready_for_pickup, picked_up | custom only | (flow-defined) | (flow-defined) | no | definable per flow | update-order-status + change-order-status.`<this status>` | history only (ORDER-compatible, no financial side effects by design) |

Notes:

- `completed → delivered` is the only post-terminal move (legacy
  fulfillment tail). `cancelled` is absorbing. `from == to` no-ops pass.
- Payment (`payment_status`), shipment (`shipments.status`), fulfillment
  (`fulfillment_status`, `fulfillments`), inventory (`inventory_state`),
  refund/return states are SEPARATE and intentionally absent here.
