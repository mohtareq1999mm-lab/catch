# ORDER FLOW — SINGLE SOURCE OF TRUTH: PHASE-1 AUDIT REPORT

> Phase: DISCOVERY ONLY. No code modified, no migrations created, no refactoring.
> Scope: `D:\work\meem` (`app/`, `packages/marvel/`, `routes/`, `database/`, `tests/`).
> Confidence: HIGH = directly read file:line this session; MEDIUM = 4 parallel read-only
> subagent audits + prior-session context, cross-checked against each other.
> Working tree note: uncommitted changes from earlier sessions exist (fulfillment
> hardening + currency work, see §10). They were NOT touched and are NOT part of this audit's baseline.

## 1. Architecture BEFORE (actual, verified)

```
Order creation (TWO doors)
 ├─ App door: OrderCreationService::createOrder → assignFlowToOrder ✅ flow assigned
 └─ Marvel door: OrderRepository::storeOrder (admin POST /orders) ❌ NO flow (flow_id NULL)

Lifecycle writes (ONE canonical writer + documented/legacy bypasses)
 OrderService::changeOrderStatus (#1, app/Services/General/OrderService.php:789-933)
   guard = allowsFlowTransition()  OR  canTransitionOrderStatus()   ← UNION (the core violation)
   + Flow-Input gate + F-1 payments.mark_paid gate + lockForUpdate
   + mirror status→current_status_id + fulfillment_status map + history + side effects + events

Subsystem direction today (all read-verified, none bypass except listed):
 Payment commit/callbacks/mark-paid ──via #1──▶ Order        (guarded; only F-1 auth assert exempted)
 Shipment maybeCompleteOrder ──via #1──▶ delivered           (conditional, guarded)
 Fulfillment ops ──▶ fulfillments/* tables ONLY              (zero orders.* writes)
 Inventory/Coupon/Promotion ──▶ counters/reservations ONLY   (called FROM #1's txn)
 CancelUnpaidOrders ──DIRECT orders.* write                  (intentional bypass, ORD-1)
 Marvel RefundRepository ──DIRECT legacy order_status write  (full bypass)
 Marvel PaymentTrait non-success ──DIRECT legacy write       (full bypass)
 Marvel OrderRepository::updateOrder ──dual funnel           (own union guard, §3 gaps)
```

## 2. Status-writer matrix (complete, 5 scoped columns: status/current_status_id/flow_id/fulfillment_status/shipping_type)

| # | Location | Method | Mutation | Authority | Can Bypass Flow? | Runtime callers |
|---|---|---|---|---|---|---|
| 1 | `app/Services/General/OrderService.php:789-933` (guard 821-836) | `changeOrderStatus` — canonical writer | status, current_status_id (mirror 885-894), fulfillment_status (map 913-929), payment/completion/cancel columns | Flow ∪ legacy (union) + inputs + F-1 + lock | No (but union lets legacy leg authorize) | All of #9–#12 below; never a route directly |
| 2 | `OrderService.php:719-744` | `$allowedOrderTransitions` + `canTransitionOrderStatus` | Policy table (legacy leg of union) | Legacy | N/A (not a writer) | #1, #4, admin dropdown 752-782 |
| 3 | `OrderFlowService.php:357-370` | `assignFlowToOrder` (creation) | shipping_type+flow_id+current_status_id+status | Flow itself | No (IS assignment; fail-closed) | `OrderCreationService:124-133` (regular + fast-shipping checkout) |
| 4 | `packages/marvel/.../OrderRepository.php:448-467,516-559` | `updateOrder` → trait + `syncOrderStatusColumn` | Legacy order_status, then mirrors status+current_status_id (558) | Repo union re-check 524-539 + granular perm 476-484 | Partial: YES if flow_id NULL (guard skipped 524) or legacy-only code (early return 520-522, mirror untouched) | Marvel `OrderController:425-438` (PUT /orders/{id}), GraphQL `OrderMutator:20` |
| 5 | `packages/marvel/.../OrderManagementTrait.php:20-56` | `changeOrderStatus` (trait) | Legacy order_status only + children cascade | Legacy side-effects, no flow check | Relies on caller (#4); standalone would bypass | #4 only (prod) |
| 6 | `packages/marvel/.../RefundRepository.php:115-139` | `updateRefund` → private `changeOrderStatus` | Direct mass-update order_status+payment_status=REFUNDED (+children) | Direct write, no guard/mirror/history | YES — full bypass; status/current_status_id left stale | Admin refund approve |
| 7 | `packages/marvel/.../PaymentTrait.php:358-432` | `webhookSuccessResponse` | Success: pre-write payment markers then #1→completed (395). Non-success: direct legacy save (417-430) | Success: via #1. Non-success: direct | Success: No. Non-success: YES — full bypass | Legacy Marvel webhook consumers (modern Stripe/PayPal/MyFatoorah use #8 instead) |
| 8 | `app/Services/Payment/PaymentCompletionService.php:272-310` | `commitLocked` | payment_status/paid_at direct (288-297), status→completed via #1 (309) | Via #1 (assertPaymentAuthority=false exempt: provider verification precedes) | No | checkout callbacks (api.php:128-129), Stripe/PayPal webhooks (133-134) |
| 9 | `Api/General/OrderController.php:92-108,222-296,318-507` | cancel / zero-value-complete / mark-paid / checkoutCallback | All via #1 | Via #1 (F-1 enforced) | No | POST orders/{id}/cancel:165, POST checkout:147, mark-paid:154-155 |
| 10 | `OrderService.php:1178-1244` | `markCodAsPaid` / `markCashierPaid` | txn→paid direct; lifecycle via #1→completed | Via #1 | No | #9 endpoints |
| 11 | `OrderStatusBatchService.php:78-106` + batch controllers | `updateSingle/updateStatuses` → #1 per order | Via #1 | Via #1 + per-order granular perm (93) | No | PATCH /api/v1/orders/status; Marvel PATCH orders/{id}/status (adapter 84-125) |
| 12 | `ShipmentService.php:143-175` | `maybeCompleteOrder` → #1→delivered (170) | Conditional: completed+paid+all-fulfillments-delivered+≥1 fulfillment | Via #1 | No (no-op otherwise; digital-only excluded 163-168) | `markDelivered` ← dispatch/delivered chain |
| 13 | `app/Console/Commands/CancelUnpaidOrders.php:100-121` | Direct `$lockedOrder->update(status cancelled, current_status_id, payment failed, fulfillment cancelled, cancelled_at)` | Direct write (deliberately not #1, comment 124-128: avoid promotion decrement ORD-1) | Direct | YES — intentional, scoped to locked pending+expired+unpaid (re-checked 76-92, gateway-paid pre-check) | Scheduler only; emits events + history manually |
| 14 | `OrderCreationService.php:50-133` | `createOrder` (status+fulfillment pending at insert, then #3 overwrites) | Creation assignment | Via #3 (fail-closed pre-check 350-353) | No | App checkout, fast-shipping (`FastShippingService:182-197`, local-only) |
| 15 | `packages/marvel/.../OrderRepository.php:113-243` | `storeOrder` (Marvel admin create) | Legacy order_status/payment_status; NEVER flow columns | Legacy/direct at creation | YES — creates flow-less orders (flow_id NULL → legacy-only downstream) | Marvel `OrderController::store` (admin) |
| 16 | `OrderFlowService.php:532-577` | `persistValidatedValues` | Domain columns (origin/destination/customs) + order_flow_values audit; NEVER the 5 lifecycle columns | Flow (inside #1's txn) | No (not a lifecycle writer) | #1 (880-883), fast-shipping (203-206) |

`->update(['status'=>…])` on **orders**: only #13. All other hits are transactions/carts/jobs (out of scope). No controller writes lifecycle directly. Admin shipment controller fires events only.

## 3. Order Flow engine (verified)

- Authority: `OrderFlowService` (resolve/assign/allowsFlowTransition/nextStatus/permissions/inputs).
  Catalog: 22 codes (`ALL_STATUS_CODES:74-100`) ≡ DB ENUM (22, widened `2026_09_28_000002` + `2026_09_29_000001`) ≡ request validation. Seeded flows: local 6-stage, international 11-stage; 7 catalog codes are custom-flows-only.
- Semantics: linear immediate-successor + supervised exits (completed from any non-terminal; cancelled except from completed/delivered/cancelled; failed_delivery/returned around out_for_delivery); inactive targets fail closed; deterministic `sort_order` with unique(flow,sort_order).
- Dual gate: structural (Flow) AND actor (`change-order-status.<code>`, null actor = system passes) + F-1 money gate.
- Assignment: `shipping_type` discriminator (exactly local|international, fail-closed); `assignFlowToOrder` is the only creation writer — but ONLY the app door calls it. Marvel door (#15), seeders, dashboard, tests create flow-less rows.
- No flow guarantee at DB: `flow_id` + `current_status_id` NULLABLE, no membership CHECK/composite FK, backfill best-effort (unmapped → NULL + warning). Enforcement is application-level only.
- Immutability: no endpoint writes `flow_id`; shipping_type immutable; orders never migrate flows. NO versioning (only `is_active`/`is_default`; `order_flow_values` stores `flow_id` only, no `flow_version`). Structural edits affect all orders sharing the flow, mitigated by in-flight guards (status-in-use block, last-active-flow block) — but NOT by snapshots.
- Mirror discipline: `status` (string, source) + `current_status_id` (FK mirror) written together at creation (#3), canonical mutation (#1:885-894), expiry bypass (#13), legacy funnel (#4). `fulfillment_status` is a third legacy vocabulary, currently mirror-maintained inside #1.

## 4. Integrations (verified)

- Payment: modern paths guarded via #1 (only payment-marker pre-writes are direct — intentional). Legacy Marvel non-success webhook branch (#7) and legacy refund (#6) bypass. Modern refund service moves payment-state only, never lifecycle — correct, preserve.
- Fulfillment: zero `orders.*` writes (FulfillmentTransition has zero Order refs). Operational DAG only. Prior-session working-tree hardening (FK fix, active-warehouse gate, default service, batch same-scope guards, lease config, consistency checks, multi-package preserved, no auto-listener, no shipment redesign) observed in `git status` but UNCOMMITTED/UNVERIFIED by this audit — must be verified during implementation (§10).
- Shipment: only `maybeCompleteOrder` moves Order, via #1, conditional — compliant pattern (subsystem proposes, Flow disposes). Preserve.
- Inventory/Coupon/Promotion: counters/reservations only, called FROM #1's txn — direction correct, do not move.
- Events/listeners: notify/timeline/invoice/digital/coupon only. One-shot `MigrateInventoryReservations --write` is a migration-only bypass (not runtime).

## 5. Flow inputs (verified — infra EXISTS, gaps are small)

Implemented: `flow_inputs` table (key/label/type/source/required/required_at/sort_order/validation/is_active, uniques), `FlowInput` (6 types, 4 sources, `required_at` = checkout|transition:CODE grammar), `FlowInputValidator` (unknown-key/inactive/type/source/options fail-closed → 422), persistence (domain columns for from/to-country + customs_reference; everything snapshotted to `order_flow_values`; never system-of-record), guest discovery `GET available`, auth `by-shipping-type`, advisory `requires_inputs`, admin bulk CRUD with in-flight guards, checkout+transition gates.
Missing vs target: no flow versioning; no nested inputs on flow store/update; bulk-only input create; batch applies one common `flow_values` object to all orders; sources capped at 4; `by-shipping-type` requires auth; international seeds only (local: none by design).

## 6. Frontend/Admin contracts (verified)

- Frontend: `GET v1/general/order-flows/available` (guest, sanitized — no ids/flags) + `GET .../by-shipping-type/{type}` (auth). Sufficient for dynamic rendering; clients key on shipping_type/code/key.
- Admin: flow CRUD (code/name/is_default/is_active; shipping_type immutable), catalog update with in-flight block, input bulk CRUD with guards, per-order options advisory. No DRAFT/PUBLISHED/ARCHIVED — `is_active`+`is_default` only, no delete endpoint (deactivate, never delete).

## 7. Conflicts & gaps (Phase-1 verdicts)

| ID | Conflict/Gap | Severity | Proposed direction (§8 of plan) |
|---|---|---|---|
| C1 | Union guard: legacy leg independently authorizes (`OrderService:821-836`) | BLOCKER (the task's core) | Flow-only guard; legacy map deprecated |
| C2 | Marvel admin create (#15) + any direct `Order::create` → flow-less orders | BLOCKER | All creation assigns flow (default local); backfill + guard |
| C3 | `CancelUnpaidOrders` direct write (#13) | MUST-FIX (intentional, scoped) | Route via #1 with `skipPromotionDecrement` flag |
| C4 | Marvel `updateOrder` dual funnel (#4) + legacy-only early exits | MUST-FIX | Thin adapter → canonical #1; canonical syncs legacy `order_status` column too |
| C5 | RefundRepository direct write (#6) + PaymentTrait non-success (#7) | MUST-FIX | Canonical-path or payment-only scoping; legacy-only codes mapped or frozen |
| C6 | No DB guarantee (nullable flow cols, no membership check) | MUST-FIX | Backfill then NOT NULL + app guards (no destructive ops) |
| C7 | No flow versioning; edits affect in-flight orders | SHOULD-FIX (decision) | Strengthen in-flight guards now; versioning deferred (decision D7) |
| C8 | Batch `flow_values` common-object; 4 sources; auth-only by-type | NICE (deferred) | Per-order values + sources as follow-ups |
| C9 | International seed lacks `export_processing` (§18 wants it) | SHOULD-FIX (decision) | Align seed (decision D10) |
| C10 | `fulfillment_status` third vocabulary | SHOULD-FIX | Keep as mirror in #1; deprecate independent writes |
| C11 | Prior-session fulfillment hardening uncommitted/unverified | RISK | Verify + commit as prerequisite (decision D0) |

## 8. Decisions required (no guessing — approval gate)

D0: Verify/commit prior fulfillment hardening first? D1: delete or deprecate legacy map? D2: Marvel create assigns flow (default local)? D3: `skipPromotionDecrement` flag semantics (preserve ORD-1)? D4: Marvel adapter scope (keep legacy `order_status` column sync in canonical writer)? D5: legacy-only codes fate (map cancelled/refunded/failed vs freeze)? D6: NOT NULL migration after backfill (downtime/consent)? D7: versioning vs strengthened guards? D8: input follow-ups scope? D9: `fulfillment_status` future (mirror forever vs retire)? D10: seed `export_processing` into international flow?
