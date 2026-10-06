# Phase 01 — Complete Checkout Flow

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** The checkout flow is the most hardened path in the codebase: single lifecycle authority (`OrderService::changeOrderStatus`), canonical payment completion service with token-based idempotency, order-owned inventory reservations, fail-closed coupon consumption, flow-gated creation, and extensive test coverage (13+ dedicated test files). All three historically documented checkout bugs verifiable in this phase (BUG-4 error-callback, CONC-3 missing reaper, CPN-1 stale coupon) are **fixed in current code**. The production manual for this phase (2026-08-02) is, however, **substantially drifted** — it describes an architecture that no longer exists (cart-owned reservations, synchronous in-transaction events, hardcoded EGP, no flow system, no idempotency tokens). Residual risks center on unproven runtime concurrency, a parallel fast-shipping creation path, and invoice-on-cancellation semantics.

## 2. Phase Objective

Per `PHASE-01-COMPLETE-CHECKOUT-FLOW.md` (source: `HEAD:docs/production-manual/PHASE-01-COMPLETE-CHECKOUT-FLOW.md`): trace every line of code executed from "Customer clicks Checkout" to completion — cart validation, inventory reservation, price refresh, promotion/coupon application, order creation, transaction creation, payment gateway interaction, callback handling, inventory finalization, invoice generation, and notifications — and verify each step's correctness, failure handling, and production readiness.

## 3. Scope

**In scope:** `POST /api/v1/general/checkout`, `POST /api/v1/general/fast-shipping/checkout` (parallel path discovered — not in manual), payment routing (online/COD/cashier/zero-value), `checkout/callback`, `checkout/error-callback`, Stripe/PayPal webhooks (completion entry points), `markCodAsPaid`/`markCashierPaid`, `PaymentCompletionService`, coupon/promotion finalization at completion, inventory reserve/commit/release ownership, invoice triggering from checkout, `orders:cancel-unpaid` reaper as it affects checkout-created orders.

**Out of scope (neighbor phases):** cart mutation APIs (Phase 02), coupon claim/distribution systems (Phase 03), promotion eligibility engine internals (Phase 04), full order-lifecycle transition matrix (Phase 05), gateway adapter internals and reconciliation (Phase 06), invoice document internals (Phase 07), shipment creation (Phase 09). Cross-referenced where checkout touches them.

## 4. What Was Supposed to Be Implemented

The manual claims the checkout flow works as follows (all references to the 2026-08-02 manual):

1. `POST /api/v1/general/checkout` (`routes/api.php:76`), `auth:sanctum`, `OrderController::checkout()` with `OrderCreateRequest` validation (manual §1).
2. `getActiveCartForUser` → `ensureCartReservation` (cart-owned reservation sync with per-item `lockForUpdate`, 3-day expiry) → `addItemsInOrder` → `PaymentCheckoutHandler` (manual §1, Steps 1–5).
3. Order creation inside one DB transaction: price refresh, locked coupon validation (invalid coupon silently cleared), promotion + coupon totals, minimum-order check, governorate shipping, `OrderCreationService::createOrder/createOrderItems/finalizeOrder` with `OrderCreated` **dispatched synchronously from finalizeOrder inside the transaction** (manual Step 4).
4. Online/COD/cashier routing with `Transaction::create(status=pending)`; COD/cashier hardcode `currency='EGP'` (manual Step 5).
5. Callback: transaction lookup by `gateway_transaction_id`/`invoice_id`, `verifyPayment`, amount check `abs(diff) > 0.01`, currency check against `config('payment.default_currency')`, finalization transaction with row locks, `changeOrderStatus(invoiceId, 'completed')`, `PaymentSucceeded` after commit (manual §2, Path C).
6. **Known bugs table** (manual §10): BUG-4 (error callback always marks failed), BUG-10 (dual event system loses notifications), CPN-1 (stale coupon), CONC-3 (**no CancelUnpaidOrders command exists**), CONC-5 (duplicate pending orders possible).
7. Invoice generated **only** via queued `GenerateInvoiceListener` on `PaymentSucceeded` (manual §5).

## 5. What Actually Exists

The current implementation preserves the manual's skeleton but differs in architecture at almost every step:

- Reservation ownership moved from **cart-owned** (`ensureCartReservation` no longer exists) to **order-owned** (`OrderReservationService::reserveForOrder/commit/release`).
- `OrderCreated` dispatched **after commit** (`OrderService::addItemsInOrder`, `OrderService.php:385`), and the event class implements `ShouldDispatchAfterCommit` (`app/Events/OrderCreated.php:17`).
- New canonical completion authority: `PaymentCompletionService::completeLocked` (`app/Services/Payment/PaymentCompletionService.php:48`) with idempotency-token primary defense (`transactions.idempotency_key`, migration `2026_09_25_000001_add_idempotency_key_to_transactions.php`), x1000 3dp-safe amount comparison, per-order currency resolution (no hardcoded EGP), provider-ref binding, D-06 duplicate-hold via `payment_reconciliation_results`.
- BUG-4 **fixed**: `checkoutErrorCallback` honors gateway success (`OrderController.php:672`).
- CONC-3 **fixed**: `orders:cancel-unpaid` exists and is scheduled every 5 minutes (`app/Console/Kernel.php:29`), with per-order `lockForUpdate` + re-check + gateway pre-check (`CancelUnpaidOrders.php:62-140`).
- CPN-1 **fixed**: `$cart->refresh()` after coupon clear (`OrderService.php:240,246`).
- Order Flow program grafted on: `shipping_type` selector, `flow_values` validation, flow-gated creation (`OrderCreationService.php:117-148`), flow-gated transitions in `changeOrderStatus` (`OrderService.php:862-869`), `payments.mark_paid` authority (F-1, `OrderService.php:895-907`), delivery-completion invariant + force-deliver escape hatch (`OrderService.php:919-946`).
- Synchronous invoice generation on **first transition away from pending** inside `changeOrderStatus` (`OrderService.php:1127-1135`) — in addition to the queued listener (idempotent via existing-invoice lock, `InvoiceService.php:25-30`).
- D-05 zero-value online orders complete without gateway (`OrderController.php:222-270`).
- Parallel fast-shipping checkout path (`FastShippingService::createFastOrder`, `FastShippingService.php:59-230`) sharing the same authorities.
- `PaymentSucceeded`/`OrderCreated` are event-level `ShouldDispatchAfterCommit` (Phase 0 P1/P2 fixes); Marvel legacy fanout frozen (dormant listener set).

## 6. Architecture

```
POST /api/v1/general/checkout (auth:sanctum + throttle:authenticated)
  └─ OrderController::checkout (OrderController.php:140)
       ├─ getActiveCartForUser (read-only fetch, CartInventoryService.php:168)
       ├─ OrderService::addItemsInOrder (OrderService.php:207) — ONE DB::transaction
       │    ├─ cart lockForUpdate + scheduled-items eager load (:213-218)
       │    ├─ refreshCartItemPrices (:226) + assertCartProductsActive (:227)
       │    ├─ locked coupon revalidation, clear-if-invalid (:230-248)
       │    ├─ pending-order resolve + shipping_type conflict gate (:262, :301-315)
       │    ├─ calculateCheckoutTotals → withTaxes (:264-297)
       │    ├─ createOrder/updateOrder + createOrderItems/syncOrderItems (:355-369)
       │    │    └─ flow columns on INSERT + assignFlowToOrder (OrderCreationService.php:117-148)
       │    ├─ orderReservationService->reserveForOrder (:374) — ORDER owns stock
       │    └─ clearCheckedOutSlice (:378)
       ├─ finalizeOrder → OrderCreated::dispatch (after commit, :385)
       └─ PaymentCheckoutHandler (online/cod/cashier, PaymentCheckoutHandler.php:28-206)
            └─ coupon reserve BEFORE gateway invoice (Rule 9, :81-91); release on failure (:107, :115)

Gateway callback / error-callback / Stripe+PayPal webhooks (public + throttle)
  └─ OrderController::checkoutCallback (:318) / checkoutErrorCallback (:618)
       ├─ paymentId format validation (regex ^[A-Za-z0-9\-_]+$, :324)
       ├─ verifyPayment via factory (disabled gateways still verify, :344)
       ├─ failure → mark failed under lock + PaymentFailed (:372-415)
       ├─ unknown order → fail SAFE, never success UI (B4, :417-437)
       └─ success → PaymentCompletionService::completeLocked (token → pending → mismatch → commit)
            └─ commitLocked: txn paid → order markers → reservation commit →
               promotion finalize → changeOrderStatus(completed, emitPaymentSuccess=false)

OrderService::changeOrderStatus (OrderService.php:827) — SOLE lifecycle writer
  flow gate → flow-input gate → payments.mark_paid gate → delivered invariant →
  status/payment/fulfillment markers → legacy column sync → history row →
  invoice-on-first-leave-pending → completed side-effects (coupon/promotion/inventory/metrics) →
  OrderStatusChanged / OrderCancelled / OrderDelivered / PaymentSucceeded
```

## 7. Complete Execution Flow

Traced against current code (all paths verified by reading):

1. **Route**: `POST checkout` → `OrderController::checkout`, inside `auth:sanctum` + `throttle:authenticated` group (`routes/api.php:141-150`). Manual claimed `routes/api.php:76` — drifted.
2. **Validation**: `OrderCreateRequest` (Marvel, `packages/marvel/src/Http/Requests/OrderCreateRequest.php`): name/phone required; email nullable; address required-if-shipping; `payment_method ∈ {online,cod,pay_at_cashier}`; `gateway` free string ≤50 (resolved via factory/registry — unknown gateway → 422); `shipping_type ∈ {local,international}`; `flow_values` array; `governorate_id` required-if-delivery. Digital-only carts skip delivery data (D4).
3. **Cart fetch**: read-only `getActiveCartForUser` (no reservation work — manual Step 1–2 obsolete).
4. **COD+pickup rejected** 422 (`OrderController.php:156-158`).
5. **addItemsInOrder transaction** (`OrderService.php:212-381`): as diagrammed in §6. Failure mapping: `CartEmptyException` → 400 (`OrderController.php:169-171`); `FlowInputValidationException` → 422 with errors (:172-174); `InvalidArgumentException` → 422 (:175-177); any other exception → reported, `null` → 500 (:180-182, :392-395).
6. **Zero-value online** (≤0 after currency-exponent rounding): `completeZeroValueOnlineOrder` — gateway adapter still resolved (currency-support check only, no provider call), zero-amount `paid` transaction + canonical `changeOrderStatus(... completed ...)` with `assertPaymentAuthority=false` (`OrderController.php:222-270`).
7. **Online**: currency-support double-gate (registry then adapter, `PaymentCheckoutHandler.php:37-78`), coupon reservation first (Rule 9), `createInvoice`, `Transaction::create(status=pending)` with `_callback_type`, return redirect URL (:93-139).
8. **COD/cashier**: coupon reservation, `Transaction::create(status=pending)` with per-order currency (no longer hardcoded EGP, :142-206).
9. **Callback success path**: locks → `completeLocked` → `Processed` → `PaymentSucceeded($order->fresh())` with F-14 null-guard (`OrderController.php:587-599`) → mobile JSON vs web redirect (:601-614).
10. **Callback mismatch**: `PaymentMismatchException` → transaction marked `failed` in-lock, `PaymentFailed` event, failed redirect (:475-585).
11. **Callback coupon-blocked**: `CouponConsumptionException` rolls back completion; separate transaction stamps `_coupon_blocked_at/reason`, rotates `idempotency_key=null`, marks failed; `coupons:reconcile` surfaces it (:508-563).
12. **Error callback**: gateway success now completes via the same service (:672-804) — BUG-4 fixed; gateway failure marks failed under lock (:810-842).
13. **Mark-paid** (admin, `permission:payments.mark_paid`, `routes/api.php:157-158`): controller delegates to `OrderService::markCodAsPaid/markCashierPaid` (`OrderService.php:1401-1467`) — latest pending txn locked → paid → canonical `changeOrderStatus(completed, emitPaymentSuccess=true)` with audit reason/context (sanitized, 500-char cap, `OrderController.php:307-316`).
14. **Reaper**: `orders:cancel-unpaid` every 5 min (`Kernel.php:29`): expired order-owned reservations → lock + re-check + gateway pre-check → `changeOrderStatus(cancelled, skipPromotionDecrement, markPaymentFailed)` → fail pending txns → `PaymentFailed`.

## 8. Business Rules

| # | Rule | Implementation | Bypass? |
|---|---|---|---|
| R1 | One active cart per user; checkout consumes the SCHEDULED slice | `getActiveCartForUser` + `where(shipping_method,SCHEDULED)` (`OrderService.php:213-218`); fast path uses FAST slice (`FastShippingService.php:88-93`) | No — both slices covered; cart row survives as container |
| R2 | Pending order reuse on retry; different shipping_type fails closed 422 | `OrderService.php:301-320`; fast path rejects non-local pending (`FastShippingService.php:133-135`) | No |
| R3 | Invalid coupon at checkout is cleared, order proceeds without it | `OrderService.php:238-247` (+`$cart->refresh()`); fast path `FastShippingService.php:102-111` | No — revalidated again at completion (POLICY 4) |
| R4 | Coupon reservation precedes gateway invoice (Rule 9); released if invoice fails | `PaymentCheckoutHandler.php:81-91,107,115` | No |
| R5 | Coupon consumed ONLY at completion, fail-closed; quota never auto-returns (POLICY 5) | `recordCouponUsage` (`OrderService.php:1492-1637`); throws `CouponConsumptionException` | No — completion cannot proceed without it |
| R6 | Promotion usage increments once (`promotion_consumed`); unpaid cancel decrements (Rule 17); never-paid expiry cancel never decrements (ORD-1/D3) | `finalizePromotionUsageAfterPayment` (`OrderService.php:398-412`); cancel branch (`OrderService.php:1206-1214`) | No |
| R7 | Order owns inventory: `none→active` reserve, `active→committed` on payment, restore-on-paid-cancel, release-on-unpaid-cancel | `OrderReservationService` (`reserveForOrder:48`, `commit:89`, `release:121`); cancel branch (`OrderService.php:1187-1204`) | No — reaper/cancel/finalize all route through these |
| R8 | Completion requires `pending` + idempotency token absent; replays are no-ops | `PaymentCompletionService::completeLocked` (`:54-96`) | No |
| R9 | Amount (x1000 3dp-safe) + currency (per-order) + provider-ref must match or fail closed | `assertNoMismatch` (`PaymentCompletionService.php:157-264`) | Test-bypass only (B5 gate) |
| R10 | completing an UNPAID order needs `payments.mark_paid` | `changeOrderStatus` F-1 gate (`OrderService.php:895-907`) | System/gateway/zero-value paths only (authority established elsewhere) |
| R11 | `delivered` requires paid + all fulfillments delivered (or audited force-deliver) | Phase-8 D8-5 guard (`OrderService.php:919-946`) | `forceDelivered` + permission + reason (logged) |
| R12 | Invoice generated exactly once on first leave-from-pending (any target) | `changeOrderStatus` (`OrderService.php:1127-1135`) + idempotent service (`InvoiceService.php:25-30`) | See F-04 |
| R13 | Zero-value online orders complete locally, no provider call (D-05) | `completeZeroValueOnlineOrder` (`OrderController.php:222-270`) | Currency must still be gateway-supported |
| R14 | COD forbidden with pickup; pay_at_cashier forces pickup fulfillment | `OrderController.php:156`; `OrderCreateRequest` fulfillment_type rule | No |
| R15 | Tax computed once, authoritatively, after discounts; shipping never taxable | `withTaxes` (`OrderService.php:615+`); formula in `OrderCreationService.php:36-43` | No |
| R16 | Digital-only carts ship nothing; free-shipping threshold on physical subtotal only | `resolveShippingChargeForCart`/`physicalLinesSubtotal` (`OrderService.php:445-467`) | No |
| R17 | Minimum order amount enforced pre-creation | `OrderService.php:271-276` | No |
| R18 | Duplicate provider payment on second row → reconcile-hold, never double-complete (D-06) | `isDuplicatePayment/recordDuplicateHold` (`PaymentCompletionService.php:109-145`) | No |

## 9. Source of Truth / Authorities

- **Lifecycle**: `OrderService::changeOrderStatus` is the SOLE writer of `orders.status` (+ `current_status_id` mirror, legacy `order_status` sync, history row). Legacy map deprecated, never consulted (`OrderService.php:853-869`). Marvel `OrderRepository::updateOrder` funnels to it (P3-2 adapter; verification belongs to Phase 05).
- **Payment completion**: `PaymentCompletionService::completeLocked` single owner for all three entry points (success callback, error callback, webhooks) — file docblock `:18-32`.
- **Inventory**: `OrderReservationService` owns reserve/commit/release; cancel branch and reaper both delegate; `inventory_restored_at` is an observability marker, not a guard (P7-2, `OrderService.php:1192-1200`).
- **Coupons at checkout/completion**: `CouponOrchestrator::validate` (checkout) + `recordCouponUsage` + `CouponReservationService` reserve/consume/release (Rule 9, CP-08, POLICY 4).
- **Flow**: `OrderFlowService` (resolve/validate/assign/persist; creation guarantee P2 inside the INSERT path, `OrderCreationService.php:112-148`).
- **Invoice**: `InvoiceService::generateFromOrder` idempotent under `lockForUpdate` (`InvoiceService.php:22-30`).
- **Dormant/retired**: Marvel `PaymentSuccess`/`PaymentFailed` events (frozen fanout, `PaymentTrait.php:414-428`; Marvel ESP registers only `OrderCancelled`, `packages/marvel/src/Providers/EventServiceProvider.php:71-72`); `ensureCartReservation` (removed — cart no longer owns reservations).

## 10. Database Impact

Tables written by checkout (verified in code): `orders` (+`flow_id`,`current_status_id`,`shipping_type`,`flow_values...`,`inventory_state`,`reservation_expires_at`,`currency_*`,`order_tax_*`,`coupon_consumed`,`promotion_consumed`,`inventory_restored_at`,`cancelled_at`,`legacy order_status`), `order_products` (+currency snapshot cols, `item_type`, tax cols, `restored_quantity`), `transactions` (+`idempotency_key` UNIQUE-able token, `gateway_response._callback_type/_coupon_blocked_*`), `coupon_reservations` (reserve/consume/release), `coupon_usages` / `coupon_assignment_usages` (+`coupons.used`, `assignments.used`), `promotions.usage`, `products`/`product_variants` (`reserved_quantity`, `in_stock`), `cart_items` (deleted slice via `clearCheckedOutSlice`), `order_status_history` (immutable rows incl. flow metadata), `invoices` + `invoice_timeline` + `invoice_sequences.last_sequence`, `payment_reconciliation_results` (D-06 holds). Key constraints: `2026_08_31_130000_add_unique_pending_order_constraint` (+`2026_09_26_000001` repair), `transactions.idempotency_key` token discipline (application-enforced), `coupon_assignment_usages` check constraint (`2026_09_11_000003`), canonical coupon codes (`2026_09_27_000001_normalize_coupon_codes_canonical`).

## 11. API Surface

| Method | URI | Auth | Controller | Validation | Responses / side effects |
|---|---|---|---|---|---|
| POST | `/api/v1/general/checkout` | sanctum + throttle:authenticated | `OrderController::checkout` | `OrderCreateRequest` (Marvel) | 200 order+payment routing; 400 cart; 422 validation/flow/minimum; 500 creation failure |
| POST | `/api/v1/general/fast-shipping/checkout` | sanctum (+group throttle) | `FastShippingController::checkout` | fast-shipping request | 200; 422 (incl. `fast_pending_order_conflict`); parallel creation path |
| GET | `/api/v1/general/checkout/promotions` | sanctum | `OrderController::eligiblePromotions` | — | eligible promotions payload |
| POST | `/api/v1/general/checkout/cod/{orderId}/mark-paid` | sanctum + `permission:payments.mark_paid` | `OrderController::markCodAsPaid` | reason (free text, sanitized) | 200; 422 no-pending-txn / flow / authority |
| POST | `/api/v1/general/checkout/cashier/{orderId}/mark-paid` | sanctum + `permission:payments.mark_paid` | `OrderController::markCashierPaid` | reason | 200; 422 |
| GET/POST | `/api/v1/general/checkout/callback` | public + `throttle:payment-callback` | `OrderController::checkoutCallback` | paymentId format; type ∈ {web,mobile} | redirect success/failed (web) or JSON (mobile) |
| GET/POST | `/api/v1/general/checkout/error-callback` | public + `throttle:payment-callback` | `OrderController::checkoutErrorCallback` | same | same; success honored (BUG-4 fixed) |
| POST | `/api/v1/general/checkout/webhooks/stripe` | public + `throttle:payment-webhook` | `PaymentWebhookController::stripe` | provider signature | completion via same service |
| POST | `/api/v1/general/checkout/webhooks/paypal` | public + `throttle:payment-webhook` | `PaymentWebhookController::paypal` | provider signature | completion via same service |

## 12. Authentication & Authorization

- Checkout/promotions: `auth:sanctum` (customer must own the cart — `getActiveCartForUser` scopes by `user_id`).
- Mark-paid: additionally `permission:payments.mark_paid` (route level, `routes/api.php:157-158`) AND in-service F-1 gate for unpaid→completed (`OrderService.php:895-907`, legacy `update-order-status` still recognized for transition, `OrderService.php:1046-1050`). Manual claimed `update-order-status` — superseded.
- Force-deliver: dedicated permission + mandatory reason + history metadata (`OrderService.php:920-940`).
- Callbacks/webhooks: public by necessity (gateway redirects), protected by paymentId format allowlist, throttle middleware, signature verification on webhooks (adapter-level; full audit in Phase 06), unknown-order fail-safe (B4), mismatch fail-closed.
- Ownership: `invoiceByOrderId` scopes by `user_id` (`OrderController.php:877-878`).

## 13. Validation

`OrderCreateRequest` (`packages/marvel/src/Http/Requests/OrderCreateRequest.php`): required name/phone; address/governorate conditional on physical lines + delivery (D4 rule implemented via `cartHasPhysicalItems()`); `payment_method` allowlist; `gateway` free-string (fail-closed at factory/registry, 422); `shipping_type` allowlist (local/international); `flow_values` array with unknown-key/missing-required rejection at service layer (422 `FlowInputValidationException`); `selected_promotion_id`/`selected_gift_product_id` existence checks. Manual reason field: stripped of tags, capped at 500 chars, never rejected (`OrderController.php:307-316`).

## 14. Transactions

Boundaries (all verified): `addItemsInOrder` single `DB::transaction` closure (`OrderService.php:212-381`); fast path manual begin/commit with single-rollback discipline (`FastShippingService.php:86-229`); callback finalization transaction with pre-fetched locks (`OrderController.php:449-507`); `changeOrderStatus` owns its transaction (`OrderService.php:829`) — nested callers (mark-paid, reaper) join it; `commitLocked` performs NO locking itself (contract documented, `PaymentCompletionService.php:23-27`) — caller must hold locks; invoice generation nested inside the status transaction with its own `DB::transaction` (savepoint) and never blocks (`OrderService.php:1127-1135`); coupon-blocked recovery in a separate transaction (`OrderController.php:519-538`); history/logging failures swallowed (`report` / try-catch) so observability never breaks transitions (`OrderService.php:1108-1118`).

## 15. Concurrency

| Resource | Protection | Classification |
|---|---|---|
| Cart row during checkout | `lockForUpdate` + re-check empty under lock (`OrderService.php:213-224`) | STRONGLY REASONED |
| Pending-order lookup | `lockForUpdate` in `findPendingOrderForUser` (`OrderCreationService.php:22-29`) + partial unique constraint (migrations `2026_08_31_130000`, `2026_09_26_000001`) | STRONGLY REASONED |
| Coupon row at checkout | `lockForUpdate` (`OrderService.php:232`) | STRONGLY REASONED |
| Coupon/assignment rows at completion | `lockForUpdate` before increment (`OrderService.php:1499,1525-1529`) | STRONGLY REASONED |
| Concurrent callbacks, same transaction | idempotency token stamped first under lock (`PaymentCompletionService.php:56-70`) | STRONGLY REASONED |
| Reservation stock rows | deterministic lock order, two-pass validate-then-increment (`OrderReservationService.php:48-88`) | STRONGLY REASONED |
| Reaper vs payment race | per-order lock + status/inventory/expiry re-check + gateway pre-check (`CancelUnpaidOrders.php:62-96`) | STRONGLY REASONED |
| Invoice double-create | existing-invoice `lockForUpdate` guard (`InvoiceService.php:25-30`) | STRONGLY REASONED |
| True parallel execution proof | — | **UNPROVEN — TRUE PARALLEL CONCURRENCY NOT PROVEN** (tests exist, e.g. `CheckoutConcurrencyStressTest`, `PaymentCallbackStressTest`, but were NOT executed in this environment) |

## 16. Async / Queues / Events

- `OrderCreated` (ShouldDispatchAfterCommit) → `SendUserOrderCreatedNotification`, `RecordOrderCreatedInTimeline` (`app/Providers/EventServiceProvider.php:155-159`).
- `PaymentSucceeded` → `SendPaymentSucceededNotification`, `GenerateInvoiceListener` (queue **high**, tries 5, backoff 10/30/60/120/300), `SendUserPaymentSucceededNotification` (`EventServiceProvider.php:173-176`).
- `PaymentFailed` → admin + user notifications + timeline recording (`EventServiceProvider.php:168-171`).
- `OrderStatusChanged/OrderCancelled/OrderDelivered` → notifications + timeline listeners.
- `AssignedCouponConsumed` via `DB::afterCommit` (`OrderService.php:1579-1589`); `CustomerMetricsUpdated` + metrics rebuild via `afterCommit` (never extends the order lock, `OrderService.php:1151-1169`).
- Coupons outbox publisher every minute; reconcile jobs hourly (`Kernel.php:38,83`); reaper every 5 min (`Kernel.php:29`); abandoned-cart/promotion/flash-sale notifiers scheduled.
- `GenerateInvoicePdfJob` (queue low) after invoice creation (manual §5 flow preserved; PDF ACP content belongs to Phase 07/08).

## 17. Error Handling

Layered and consistent: FormRequest 422s; domain `InvalidArgumentException` → 422 (minimum order, flow conflicts, inactive products, gateway unavailable); `CartEmptyException` → 400; `CouponConsumptionException` → completion rollback + visible failed marking + reconcile surfacing (never silent, never 500 to gateway); `PaymentMismatchException` → failed marking + `PaymentFailed`; gateway exceptions at initiation → reported + coupon reservation released + 500 `ERROR_CREATING_INVOICE` with no transaction row (`PaymentCheckoutHandler.php:102-110`); poison orders never abort the reaper run (`CancelUnpaidOrders.php:119-124`); all user-facing gateway strings sanitized (`strip_tags`, 500-char cap) and error-message content never leaks internals; frontend redirects carry only status/message/order_id.

## 18. Security

- paymentId allowlist regex + length cap on both callbacks (`OrderController.php:324,624`).
- `type` parameter allowlisted (web/mobile) with safe default (`:327-330`, `getCallbackType` `:894-907`).
- Test-gateway bypass (B5) requires apitest URLs in ALL configured URL trees + explicit flag + local/testing env (`OrderController.php:920-943`) — safe by construction; production enablement would be a critical misconfiguration (see F-07).
- Unknown-order callbacks fail safe (B4, `:417-437, :773-792`).
- Throttles: `payment-callback`, `payment-webhook`, `authenticated`, public-tracking (`routes/api.php:131-139`).
- Mass assignment: creation uses explicit `$orderDataForCreate` allowlist (`OrderCreationService.php:50-81`), not raw request data.
- `mark-paid` reason sanitized twice (controller + history metadata).
- No secrets in checkout code; gateway credentials via config/settings (Phase 06 scope).

## 19. Performance

- Checkout holds row locks across pricing/coupon/totals/creation in one transaction — correct but the transaction is long; metrics rebuild deliberately deferred to `afterCommit` to avoid extending the order lock (`OrderService.php:1148-1150`).
- Eager loading present on hot paths (cart items + flash_sales + variants + attributes); per-item N+1 avoided in `refreshCartItemPrices` via preloaded relations (`OrderService.php:547`).
- Callback does two transaction lookups pre-lock (by paymentId, then verified id) plus locked re-lookups — acceptable; gateway `verifyPayment` is a synchronous external call inside the request but OUTSIDE any DB transaction (good).
- Invoice generation synchronous inside the status transaction — bounded work (snapshot + validators + insert); queued listener remains as backup; PDF offloaded to low queue.
- `syncOrderItems` deletes + recreates all lines on retry (`OrderCreationService.php:427`) — O(n) writes per retry; acceptable for cart-scale n.

## 20. Tests & Verification

Existing checkout-related suites (all read at header/list level; **none executed — MySQL-only project, no DB in this environment**):

- `tests/Feature/CheckoutApiTest.php`, `CheckoutConcurrencyStressTest.php`, `CheckoutRegressionTest.php`, `CheckoutPendingOrderRedesignTest.php` (16 tests: pending reuse, 24h/168h expiry, reservation-without-deduction, cart-row reuse, COD idempotent double-paid, promotion/coupon timing).
- `tests/Feature/OrderCreationFlowTest.php`, `PromotionCheckoutTest.php`, `PaymentCheckoutTest.php`, `PaymentCallbackStressTest.php`, `WebhookPaymentCompletionTest.php`.
- `tests/Feature/Payment/PaymentCompletionTest.php` (canonical completion, D-05 zero-value, refund validation, response allowlisting).
- `tests/Feature/Coupon/CouponCheckoutRevalidationTest.php`, `tests/Feature/Digital/DigitalCartCheckoutTest.php`, `tests/Feature/OrderFlow/OrderCreationGuaranteeTest.php`.
- Manual §12 "Missing Tests" partially addressed: concurrent-checkout and duplicate-callback coverage now exist statically; error-callback-with-gateway-success and gateway-timeout paths need confirmation (see F-08).
- Static verification only: `php -l`-level cleanliness not re-run (read-only constraint respected; no code executed).

## 21. Edge Cases

Covered in code: empty cart → 400; concurrent consumption → 400 `CartEmptyException`; coupon invalidated mid-flow → cleared, proceeds; pending-retry with changed items → `syncOrderItems` delete+recreate + reservation release/re-acquire + coupon-reservation release (`OrderService.php:322-332`); pending-retry with different shipping_type → 422; totals changed on retry (fast path) → `updateTransactionAmount` (main path creates fresh transactions instead — see F-09); gateway success for unknown order → failed UI, warning logged; non-pending order callback → silent idempotent return; duplicate provider payment → reconcile-hold; account deleted before completion → fail-closed `CouponConsumptionException`; inactive governorate → id kept, zero shipping; digital-only cart → zero shipping; zero total → D-05 local completion; COD+pickup → 422; pay_at_cashier forces pickup.

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The phase manual describes an architecture that no longer exists: cart-owned `ensureCartReservation` (removed), `OrderCreated` dispatched inside the transaction (now after-commit + `ShouldDispatchAfterCommit`), hardcoded `currency='EGP'` (now per-order resolution), no flow system, no idempotency tokens, `PaymentCheckoutHandler` 121 lines (now 226), `OrderService` 809 lines (now 1726), `OrderController` 461 lines (now 944), route at `routes/api.php:76` (now line 150 in a different middleware group).
#### Evidence
Manual §1 Steps 1–2 and source list vs `CartInventoryService.php:38-282` (no `ensureCartReservation`; `getActiveCartForUser` documented "Read-only: performs no reservation"), `OrderService.php:385`, `PaymentCheckoutHandler.php:130,163,196`, `routes/api.php:141-150`.
#### Why it matters
Operators/on-call following the manual will look for code paths that do not exist and miss the ones that do (flow gates, token idempotency, order-owned reservations).
#### Current behavior
Code is correct; documentation is stale.
#### Recommended future action
Regenerate or version-stamp the Phase 1 manual against current code (docs safety mode: only on explicit request).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: LOW
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Manual §10 lists BUG-4 (error callback always marks failed), CONC-3 (no reaper), CPN-1 (stale coupon) as open problems. All three are fixed in current code.
#### Evidence
BUG-4: `OrderController.php:672` (`if ($result->success)` completion path). CONC-3: `app/Console/Commands/CancelUnpaidOrders.php` + `Kernel.php:29` (every 5 min). CPN-1: `OrderService.php:239-240,245-246` (`$cart->refresh()` after clear).
#### Why it matters
Stale "known bugs" erode trust in the manual and may trigger duplicate work.
#### Current behavior
Correct behavior; stale report.
#### Recommended future action
Mark the three items fixed with fixing references; keep as regression history.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: MEDIUM
- Type: BUSINESS LOGIC
- Status: STRONGLY REASONED
#### Finding
An invoice is now generated synchronously on the FIRST transition away from `pending` **regardless of target status — including `cancelled`** (`OrderService.php:1127-1135`). A COD order cancelled by the reaper before any payment therefore gets a real invoice row (plus timeline/sequence consumption). The manual (§5, §11.5) frames invoices as a post-payment artifact.
#### Evidence
`OrderService.php:1120-1135` (comment: "regardless of the target status (processing / completed / cancelled)"); `InvoiceService.php:22-30` (idempotent creation, no status precondition on the order).
#### Why it matters
Downstream consumers (invoice lists, accounting exports, customer "my invoices") may surface invoices for never-paid cancelled orders. If intentional (audit completeness), it should be a stated rule; the manual states the opposite.
#### Current behavior
Invoice created on cancel-path transitions too; duplicates prevented by the existing-invoice lock.
#### Recommended future action
Confirm intended policy (invoice-on-cancel vs payment-only) and pin with a test; update Phase 07 accordingly.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-04
- Severity: LOW
- Type: ARCHITECTURE
- Status: STRONGLY REASONED
#### Finding
Three completion entry points (`checkoutCallback`, `checkoutErrorCallback`, Stripe/PayPal webhooks) share `completeLocked` but triplicate the surrounding orchestration (lookup, lock, mismatch marking, coupon-blocked recovery, event dispatch, mobile-vs-redirect rendering) across ~600 lines of controller code. Any future behavioral change must be applied three times consistently.
#### Evidence
`OrderController.php:318-616` vs `:618-865` (mirrored blocks with F-01/F-09/F-14 parity comments); webhook paths in `PaymentWebhookController` (structure to be audited in Phase 06).
#### Why it matters
The parity comments prove the team already feels this risk; drift between the three paths would create gateway-dependent completion semantics.
#### Current behavior
Paths are currently consistent (verified by reading both).
#### Recommended future action
Extract a shared callback-orchestration method when next touching this code; add a parity test asserting identical outcomes across entry points.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-05
- Severity: LOW
- Type: BUSINESS LOGIC
- Status: STRONGLY REASONED
#### Finding
Every checkout retry that reaches a payment handler creates a brand-new `pending` transaction row (`PaymentCheckoutHandler.php:123,157,190` — unconditional `Transaction::create`). Superseded pending rows linger until the reaper fails them at order cancel. `updateTransactionAmount` (pending-row amount sync) is wired ONLY into the fast-shipping path (`FastShippingService.php:162`), not the main path.
#### Evidence
`PaymentCheckoutHandler.php:123-133`; `OrderCreationService.php:434-444` (method exists); single caller `FastShippingService.php:162` (verified by search — no callers in the main checkout path).
#### Why it matters
Transaction-table hygiene and operator confusion (multiple pending rows per order); amount on the completed row is always correct (fresh row per attempt), so financial impact is nil, but reconciliation queries must be aware.
#### Current behavior
Correct amounts; redundant pending rows possible between retry and expiry-cancel.
#### Recommended future action
Document the "fresh row per attempt" policy; consider failing superseded pendings at reuse time.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-06
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap), UNPROVEN (runtime behavior)
#### Finding
No test in this environment can be executed (MySQL-only project, no DB/Docker). All concurrency protections in §15 are therefore STRONGLY REASONED at best. TRUE PARALLEL CONCURRENCY NOT PROVEN for: concurrent same-cart checkouts, concurrent duplicate callbacks, reaper-vs-callback races.
#### Evidence
`CheckoutConcurrencyStressTest.php`, `PaymentCallbackStressTest.php` exist statically (file listing verified); execution NOT RUN (environment limitation, consistent with `ORDERFLOW_IMPLEMENTATION_PLAN`/final-verification notes on file).
#### Why it matters
This is the highest-risk phase for race-induced financial error; static reasoning is not proof.
#### Current behavior
Protections are well-constructed (tokens, locks, re-checks); proof is missing.
#### Recommended future action
Execute the stress suites in CI with a real MySQL instance and record results; add a reaper-vs-callback race test if absent.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-07
- Severity: LOW
- Type: SECURITY
- Status: STRONGLY REASONED
#### Finding
The B5 test-gateway bypass (`OrderController.php:920-943`) silently ignores amount/currency mismatches when enabled. The gate (apitest URLs everywhere + explicit flag + local/testing env) is sound, but a staging environment pointed at apitest with the flag on would complete mismatched orders.
#### Evidence
`OrderController.php:920-943`; bypass consumed in `checkoutCallback.php:474`, `checkoutErrorCallback.php:687`, webhooks (to verify in Phase 06).
#### Why it matters
Silent mismatch acceptance is the single most dangerous knob in the payment path.
#### Current behavior
Fails closed unless all three conditions hold.
#### Recommended future action
Add a boot-time assertion/test that production config can never satisfy the bypass; alert on `test gateway bypass explicitly enabled` log lines outside local/testing.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-08
- Severity: INFO
- Type: LEGACY
- Status: PROVEN
#### Finding
Manual BUG-10 (dual event system) is retired in practice: Marvel `PaymentSuccess` has no registered listeners (Marvel ESP wires only `OrderCancelled`, `packages/marvel/src/Providers/EventServiceProvider.php:71-72`); the legacy fanout is explicitly frozen (`PaymentTrait.php:414-428`); live traffic uses App events with `ShouldDispatchAfterCommit`.
#### Evidence
As above; Marvel `PaymentSuccess` still fires from legacy trait paths (`OrderStatusManagerWithPaymentTrait.php:160`) into a dormant listener set.
#### Why it matters
Dead event classes on live-adjacent paths are a confusion and misfire risk, but no notification loss occurs today.
#### Current behavior
No lost notifications via this route.
#### Recommended future action
Remove or formally deprecate the Marvel payment events when the legacy paths are removed; keep the freeze comments until then.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Gateway timeout at `createInvoice`**: reported, coupon reservation released, 500, no transaction row, order stays pending — retry safe (verified `PaymentCheckoutHandler.php:102-110`).
- **Callback arrives before transaction commit**: impossible — transaction row is created before the redirect URL is returned; unknown-ref + verified payment → B4 fail-safe failed UI + warning log.
- **Customer pays twice (double redirect)**: second callback hits token-present → `IdempotentReplay`; different row + non-pending order → D-06 `DuplicateHold` + reconcile row.
- **Coupon expires between checkout and payment**: completion revalidates (POLICY 4); live reservation (≤30 min TTL) honors the checkout commitment; otherwise fail-closed `CouponConsumptionException` → order stays pending, `coupons:reconcile` surfaces it.
- **Reaper fires while customer is on the gateway page**: reservation-expiry-gated + gateway pre-check (`gatewayReportsPaid`) skips paid-at-gateway orders; lock + re-check defeats the TOCTOU.
- **Order cancelled after payment**: paid-cancel path restores inventory exactly once (`inventoryRestoreService->restore` state claim), keeps promotion usage (Rule 17), releases coupon reservation (post-payment = no-op), cascades to fulfillments atomically.
- **Invoice service down/slow at completion**: swallowed (`report`), status transition proceeds; queued listener retries 5×.
- **Mobile client**: parallel JSON contract preserved at every branch (`_callback_type` persisted on the transaction row).

## 24. Documentation Drift

Comprehensive (manual dated 2026-08-02; code evolved through orderflow, fulfillment, coupon-claim, idempotency, currency, and tax programs):

1. Reservation model inverted (cart-owned → order-owned); `ensureCartReservation`/`syncCartItemReservation` gone.
2. `OrderCreated` dispatch timing (in-transaction → after-commit + `ShouldDispatchAfterCommit`).
3. Currency handling (hardcoded EGP → per-order `PaymentCurrencyResolver` + 3dp `CurrencyPrecision`).
4. Amount comparison (`abs>0.01` → x1000 integer comparison).
5. BUG-4/CONC-3/CPN-1 fixed but listed open (§10 table).
6. Mark-paid permission (`update-order-status` → `payments.mark_paid` + F-1 in-service gate).
7. New subsystems absent from manual: Order Flow (shipping_type/flow_values), `PaymentCompletionService` + idempotency tokens + D-06 holds, coupon reservations (Rule 9/CP-08/POLICY 4/5), zero-value D-05 path, fast-shipping parallel checkout, invoice-on-first-leave-pending, force-deliver hatch, structured logging/metrics (P2-5), mobile/web callback duality, gateway pre-check in reaper, `markPaymentFailed` reaper parity.
8. File sizes/line numbers throughout §1 source list and route references (`routes/api.php:76` → `:150`; COD/cashier routes new at `:157-158`).

## 25. Dependencies

- **Depends on**: Phase 02 (cart container + slices), Phase 03 (coupon validate/reserve/consume), Phase 04 (promotion apply/finalize), Phase 05 (flow authority + `changeOrderStatus`), Phase 06 (gateways, verification, webhooks), Phase 07 (invoice service), Phase 12 (notifications), currency/tax services.
- **Consumed by**: Phase 05 (orders created here), Phase 06 (transactions created here), Phase 07 (invoice trigger), Phase 09 (fulfillment auto-release listeners on `PaymentSucceeded`/COD), Phase 10 (refund source), Phase 15 (timeline backbone).
- **Shared tables**: `orders`, `order_products`, `transactions`, `carts`, `cart_items`, `coupon_reservations`, `order_status_history`, `invoices`.
- **Shared services**: `OrderService`, `OrderCreationService`, `OrderReservationService`, `CouponOrchestrator`, `PaymentGatewayFactory`, `OrderFlowService`, `InvoiceService`.

## 26. Out of Scope

Gateway adapter internals (MyFatoorah/Stripe/PayPal verify/create semantics, signature verification), reconciliation job internals, invoice PDF rendering, fulfillment release implementation, refund/return flows, admin order management, guest checkout (none exists — auth required), multi-warehouse allocation at checkout.

## 27. Residual Risks

1. Runtime concurrency unproven (F-06) — highest residual risk in the highest-risk phase.
2. Invoice-on-cancel policy unconfirmed (F-03).
3. Triplicated callback orchestration may drift (F-04).
4. Fast-shipping path is a second creation funnel requiring parity maintenance (same authorities today, verified).
5. Test-bypass knob safety depends on config discipline (F-07).
6. Manual is misleading as an ops reference until regenerated (F-01/F-02).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-01-COMPLETE-CHECKOUT-FLOW.md` (577 lines, via temp extract — on-disk copy deleted externally, see README). Code read: `app/Http/Controllers/Api/General/OrderController.php` (:140-209 checkout, :222-270 zero-value, :272-316 mark-paid, :318-616 callback, :618-865 error-callback, :894-943 bypass gate); `app/Services/General/OrderService.php` (:207-396 addItemsInOrder, :398-412 promotion finalize, :445-519 shipping, :521-573 coupon/price refresh, :575-608 totals, :827-1251 changeOrderStatus, :1275+ cancel cascade, :1401-1467 mark-paid, :1492-1726 coupon usage); `app/Services/Checkout/OrderCreationService.php` (:22-29 pending lookup, :31-178 create, :180-261 update, :263-300 items, :427 sync, :434-444 txn amount, :569 finalize); `app/Services/General/FastShippingService.php` (:59-230 createFastOrder); `app/Services/Payment/PaymentCheckoutHandler.php` (full, 226 lines); `app/Services/Payment/PaymentCompletionService.php` (full, 311 lines); `app/Services/Inventory/OrderReservationService.php` (:37-130 reserve/commit/release); `app/Events/OrderCreated.php`, `PaymentSucceeded.php` (+`PaymentFailed.php` referenced); `app/Providers/EventServiceProvider.php` (:146-176 wiring); `app/Listeners/GenerateInvoiceListener.php`; `app/Services/Invoice/InvoiceService.php` (:22-30 idempotency); `app/Console/Commands/CancelUnpaidOrders.php` (full); `app/Console/Kernel.php` (schedule); `packages/marvel/src/Http/Requests/OrderCreateRequest.php` (rules); `packages/marvel/src/Database/Repositories/OrderRepository.php` (:113 storeOrder, :652-656 flow); `packages/marvel/src/Traits/PaymentTrait.php` (:414-428 freeze); `packages/marvel/src/Traits/OrderStatusManagerWithPaymentTrait.php` (:155-175 legacy fanout); `packages/marvel/src/Providers/EventServiceProvider.php` (:71-72); `routes/api.php` (:118-160). Tests listed (not executed): `CheckoutApiTest`, `CheckoutConcurrencyStressTest`, `CheckoutRegressionTest`, `CheckoutPendingOrderRedesignTest` (16 tests), `OrderCreationFlowTest`, `PromotionCheckoutTest`, `PaymentCheckoutTest`, `PaymentCallbackStressTest`, `WebhookPaymentCompletionTest`, `Payment/PaymentCompletionTest`, `Coupon/CouponCheckoutRevalidationTest`, `Digital/DigitalCartCheckoutTest`, `OrderFlow/OrderCreationGuaranteeTest`. Migrations: `2026_09_25_000001` (idempotency_key), `2026_08_31_130000` + `2026_09_26_000001` (pending unique), `2026_08_31_120100` (coupon_reservations), `2026_09_11_000001` (history), `2026_09_28_000002`/`2026_10_04_000001` (flow).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The checkout flow is architecturally sound and materially stronger than its manual describes: single lifecycle authority, canonical idempotent completion, order-owned reservations, fail-closed money handling, and layered failure recovery all verified in current code with precise file:line evidence. The three historically recorded checkout defects are fixed. The verdict stops at PASS WITH RESIDUAL RISKS (not PASS) because: (a) true parallel concurrency is unproven — tests exist but cannot run here; (b) the invoice-on-cancel policy needs explicit confirmation; (c) the phase manual is sufficiently drifted to mislead operators. No blocking defect was found in the checkout path itself.

## 30. Addendum — Phase 3 Fulfillment Release implementation (2026-10-05)

Subsequent to this audit, the Phase 3 repair task changed fulfillment-release behavior that this phase consumes. Recorded here so the audit stays truthful; the verdict above is unchanged (all changes strengthen the audited guarantees):

1. **Cashier auto-release trigger added.** `ReleaseFulfillmentOnCodPlacement` previously fired only for `payment_method === 'cod'`; it now covers `pay_at_cashier` as well (same OrderCreated trigger, same ACTIVE-reservation + physical-lines gates, same deterministic `auto-release-order-{id}` key, converging with later mark-paid via idempotent reuse). Proven by `Phase3ReleaseHardeningTest` (cashier placement, replay, mark-paid convergence) including an HTTP end-to-end COD checkout test.
2. **Fulfillment items are now physical-lines-only (D1).** `FulfillmentService::createFromOrder` skips digital order lines using the same predicate as the reservation authority (`aggregatePhysicalLines`); an order with zero physical lines now fails loudly instead of producing an empty fulfillment. Mixed orders previously embedded digital lines as unpickable null-placement items, which blocked picking completion (`completePicking` / batch advancement require every item picked). No checkout-path changes were needed.
3. **Bounded retries + recovery sweeper.** Both release listeners declare `tries = 5` with `[10,30,60,120,300]` backoff (previously deployment-default); new hourly `fulfillment:release-ready` command releases releasable orders the event path missed (same gates, same auto key, poison-safe). No checkout-path changes were needed.
4. **Dead `FulfillmentCompleted` event removed** (zero references); `maybeCompleteOrder` locking contract documented. No behavior change.
5. **Regression evidence (executed 2026-10-05, MySQL `phase3_verify`):** new suite 15/15; Fulfillment/ 229/229; Wms/ 178/178; Warehouse 10/10; OrderFlow/ 182/182; Payment/ 168/168; plus checkout/coupon/digital/refund/event suites. Pre-existing failures unrelated to checkout/fulfillment were observed and left untouched (variant pricing, USD invoice allowlist, missing `variation_options` table, gateway-constructor drift, stale Marvel-event expectation, callback stress-suite MySQL fragility, missing model factories) — see the Phase 3 final report for the exact list.

## 31. Addendum — Phase 3 inventory-commit correction (2026-10-05)

Approved business decision: COD/cashier secure inventory AT ORDER CREATION via the canonical commit, exactly as a paid online order does. Manual payment no longer means merely-held stock. This supersedes the §30.1 description (release gate was ACTIVE-reservation) and several §8/§13/§15 statements that pinned reserve-at-creation for manual methods. The verdict above is unchanged (the change implements an approved business rule through existing authorities; all guarantees re-proven below):

1. **Commit at creation (both funnels).** `OrderService::addItemsInOrder` and `FastShippingService::createFastOrder` call `OrderReservationService::commit()` in the creation transaction when `payment_method ∈ {cod, pay_at_cashier}` and the order has physical lines (canonical `aggregatePhysicalLines` predicate; digital-only keeps today's ACTIVE state). Later completion (mark-paid → completed, online callbacks) re-commits as an idempotent active→committed-claim no-op — one deduction, never two. Fulfillment performs zero inventory math (§2 holds: no `stock_quantity` writes outside the two inventory authorities).
2. **Fail-safe creation.** `reserveForOrder` still throws on insufficient stock before commit, aborting the whole transaction: no order, no fulfillment (finalizeOrder dispatches only after commit), counters untouched — proven by test.
3. **State-keyed cancel (required consequence).** `changeOrderStatus` cancelled-branch now routes on `inventory_state === COMMITTED → InventoryRestoreService::restore()` regardless of `payment_status` (previously `paid && committed`). Without this, committed-but-unpaid COD cancels would hit `release()` (active→released claim, no-op on committed) and leak stock. Paid+committed behavior is identical; unpaid+active behavior is identical.
4. **Reaper covers committed-unpaid expiry.** `orders:cancel-unpaid` selects `inventory_state ∈ {ACTIVE, COMMITTED}` (was ACTIVE-only) with the same lock re-check; routes through the canonical writer. Otherwise committed COD pendings would strand past their 7-day window.
5. **Release follows commit.** `ReleaseFulfillmentOnCodPlacement` gate and `FulfillmentService::assertReleasable` deferred branch and the `fulfillment:release-ready` manual branch accept `ACTIVE or COMMITTED` (COMMITTED is the new normal; ACTIVE covers legacy orders placed before this rule). Order/COD: creation-txn commit → after-commit OrderCreated → keyed release; retries/sweeper converge on the same auto key without re-committing (commit is claim-guarded).
6. **Retry supersede (forced consequence, documented).** A committed pending cannot be re-reserved (no committed→active transition exists, by design). When checkout finds a COMMITTED pending, it supersedes it via canonical cancel (restore + fulfillment cascade + coupon release, `skipPromotionDecrement` as never-paid) and creates fresh. Online ACTIVE-pending reuse is untouched. Shipping-type conflict still fails closed BEFORE any mutation.
7. **Regression evidence (executed 2026-10-05, MySQL 8.4.3 `phase3_verify`/`phase3_verify2`):** new `Phase3InventoryCommitTest` 14/14; `OrderReservationLifecycleTest` 24/24; `CartOrderLifecycleTest` 38/38; `OrderFlow/` 182/182; `Fulfillment/` 230/230; `Payment/` 168/168; `Wms/` 178/178; `CheckoutApiTest` 13/13; `DigitalCartCheckoutTest` 9/9; `CouponCheckoutRevalidationTest` 15/15; `OrderStatusLifecycleTest` 15/15; `MarvelRefundInterplayTest` 4/4; `CartPhase02FixesTest` 19/19. Old-contract pins updated to the approved rule (13 lifecycle + 6 flow + 4 reservation-lifecycle + 5 redesign + 1 checkout-API + 1 customer-contract tests). Remaining failures verified IDENTICAL at stashed baseline and left untouched: `CheckoutPendingOrderRedesignTest` 14/16 (USD invoice allowlist ×2), `EventSystemTest` 39/48 (gateway-ctor ×3, activity ×4, stale Marvel ×1, restore-qty ×1 — the restore case fails identically at HEAD because its fixture never enters COMMITTED), `CartApiTest` 79/80 (gift promotion_id=999 full-schema FK).
