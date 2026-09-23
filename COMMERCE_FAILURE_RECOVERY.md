# COMMERCE FAILURE RECOVERY (Phase 1 Lock — includes master diagram)

> Status: LOCKED.

## 1. Matrix (state → retry → idempotency → compensation → manual → audit)

| Failure | State | Retry | Idempotency | Compensation | Manual | Audit |
|---|---|---|---|---|---|---|
| Callback timeout after gateway paid | txn pending, order active | reconcile 15min re-verify | token+status | none (completes) | ops re-fire | PaymentSucceeded late |
| Fulfillment create failed | released, no row | queued retry w/ key | unique order+wh | none | supervisor trigger | create attempts |
| Allocation failed | fulfillment pending | retry; manual-assign item | recompute | none | assign location | allocation log |
| Pick fail / worker disconnect | task assigned, lease expires | auto-release → pool | op_seq dedupe | none | reassign | scan log |
| Cancel during picking | cancelling | claim fulfillment row first | terminal guard | unpicked void; picked → exception/re-putaway | supervisor | cancel audit |
| Seal ok, shipment create failed | ready_to_ship | retry w/ key | shipment key | none | create manually | shipment attempts |
| Dispatch failed | label_created | retry dispatch | carrier id | void label | new label | dispatch log |
| Duplicate webhook | terminal | ignored | event-id dedupe | none | — | replay counter |
| Payment failed / expired | failed / released | new attempt allowed | new attempt row | release reservation | — | fail reason |

## 2. Master end-to-end diagram (with failure branches)

```mermaid
flowchart TD
  C[Customer] --> Cart --> CO[Checkout] --> PR[Pricing/Promo/Coupon/Tax/Shipping]
  PR --> O[Order created: pending] --> R[Reserve → active]
  R --> PA[Payment Attempt → Provider]
  PA -->|verify ok| V[Verification: amount×currency, locked]
  PA -->|fail/timeout| PF[Payment failed] --> REL[Release reservation] --> CAN[Order cancelled]
  V -->|match| COM[Commit → completed]
  V -->|mismatch| PF
  COM --> RELW{Release to warehouse?}
  R -->|cod/cashier deferred| RELW
  RELW -->|online needs success; cod/cashier needs active| F[Fulfillment created]
  RELW -->|not releasable| HOLD[Hold / cancel path]
  F --> AL[Allocate: plan per location]
  AL -->|shortfall| MA[Manual-assign item]
  AL --> PK[Pick: claim→scan→confirm]
  PK -->|short/damage| EX[Exception flow]
  PK -->|cancel race| CXC[Cancel wins pre-pick; exception post-pick]
  PK --> PAK[Pack → Packages sealed]
  PAK -->|invariant fail| REJ[Reject + audit]
  PAK --> RTS[ready_to_ship] --> SH[Shipment created]
  SH --> DIS[Dispatch → in_transit → out_for_delivery]
  DIS -->|dispatch fail| RDIS[Retry dispatch]
  DIS --> DEL[Delivered] --> DONE[Order completion rule]
```
