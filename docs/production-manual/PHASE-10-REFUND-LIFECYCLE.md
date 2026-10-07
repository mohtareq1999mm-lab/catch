# Phase 10: Refund Lifecycle (Unified)

## Executive Summary

One refund domain, one write path. All refund business behavior converges on the canonical `App\Services\Refund\RefundService` (request → approve/reject). The lifecycle is `pending → approved | pending → rejected`. APPROVED means business-approved/recorded — never "money returned": this phase performs NO payment-provider call, creates NO wallet/balance movement, and never marks payment as refunded. Amounts are server-authoritative in minor units with locked remaining-refundable accounting, so concurrent approvals cannot over-refund. Inventory restores only on full refunds; reviews are never auto-deleted (the reviews table carries no order scope).

## State Machine

```text
PENDING ──┬──▶ APPROVED   (business approved/recorded; fan-out runs once)
          └──▶ REJECTED   (decision recorded; no side effects)
```

No `processing` state is written (the old atomic-claim intermediate is retired — approval is a single atomic `PENDING → APPROVED` transition under lock). No `completed`/`refunded`/`failed` states exist in this phase; provider execution (`APPROVED → PROCESSING → COMPLETED/FAILED`) is a deferred future phase and must not be faked.

`APPROVED` does NOT mean money moved. It must never be called COMPLETED or REFUNDED, and `payment_status` must never become `payment-refunded` on this path.

## Runtime Authority

`RefundService::request()` / `approve()` / `reject()` — the sole refund writer. Lock order `Transaction → Order → Refund` (the global payment ordering; the transaction row is read-locked as the paid-money authority and never written here).

- Request: validates ownership, paid total, `0 < amount ≤ remaining`, currency match; creates PENDING and stops.
- Approve: locks order + refund, re-validates under lock (PENDING, digital-delivery guard, `amount ≤ paid − approved-others`), atomically transitions, dispatches `RefundApproved` + `RefundProcessed` (both `ShouldDispatchAfterCommit`).
- Reject: locks, transitions to REJECTED, records the decision; no side effects.

Duplicate/replayed decisions fail closed (`Already refunded`, HTTP 400) with zero repeated side effects.

## Refundable Amount Authority

`remaining = paid − approved(table) − approved(legacy provider ledger, read-only)`, all in minor units via `CurrencyPrecision`. Paid prefers the latest paid transaction row, else the order total when payment is marked success. The client amount is never authoritative. Partial amount-only refunds are allowed and accumulate; the next request/approval is refused once remaining hits zero.

## Side-Effect Ownership (exactly once each)

| Effect | Owner | Rule |
|---|---|---|
| Inventory restore | `RestoreInventoryOnRefund` → `InventoryRestoreService::restore()` (state claim) | Full refunds only (cumulative approved covers paid). Single registration (Marvel ESP). |
| Credit note | `GenerateCreditNoteOnRefund` → `CreditNoteService::generateForRefund()` | Every approval, once per refund row (reason carries `Refund #id`, re-delivery skips). |
| Reviews | none (deregistered) | Never auto-deleted: reviews carry no order/item scope, so no refund can scope them. |
| Digital | `RevokePendingDigitalEntitlements` | Pending entitlements only; delivered-digital orders refuse approval (D7). |
| Timeline | `RecordRefundApprovedInTimeline` (`refund.approved`), `RecordRefundInTimeline` (`refund.processed`), direct `refund.requested` / `refund.rejected` rows | Every transition audited. |
| Notify | `SendUserOrderRefundedNotification` ("approved", never "money returned"), `RefundRequested`/`RefundUpdate` mails | Truthful wording only. |

## API Surface

| Method | URI | Auth | Behavior |
|---|---|---|---|
| POST | `/api/v1/refunds` | customer (owner) | Creates PENDING (201). |
| GET | `/api/v1/refunds` | customer | Own refunds + `{total,pending,approved,rejected,totals_by_currency}` summary. |
| GET | `/api/v1/refunds/{id}` | owner/staff | Owner-scoped read. |
| PUT | `/api/v1/refunds/{id}` | staff | Legacy decide route; delegates to the service (`approved`/`rejected`). |
| DELETE | `/api/v1/refunds/{id}` | staff | Pending rows only; decided rows are ledger history (422). |
| GET | `/api/v1/admin/refunds` | `view-refunds` | Paginated list + summary. |
| POST | `/api/v1/admin/refunds/{id}/approve` | `payments.refund` | Canonical approval. |
| POST | `/api/v1/admin/refunds/{id}/reject` | `payments.refund` | Canonical rejection. |

The obsolete direct gateway endpoint `POST /api/v1/admin/payments/{order}/refund` is removed. `PaymentRefundService::refund()` remains for the future provider phase but is unreachable via HTTP; inbound provider webhooks (`recordExternalRefund`) are untouched.

## Out of Scope / Deferred

Provider execution states, wallet/balance systems (no such tables exist), payment-marker changes, Order Flow, fulfillment/shipment, coupon architecture (consumed coupons never return), pricing/tax/currency math (reused, not changed).

## Residual Risks

1. True multi-process concurrency is proven by lock-order reasoning + sequential tests, not by parallel-process execution.
2. `RefundStatus`/`RefundPolicyStatus` enums still list `processing`; the refunds column no longer accepts it (writes fail loudly — intended).
3. Other App-namespaced classes still live under `packages/marvel/` outside refunds (same classmap fragility the refund event/listener had; untouched as out of scope).
4. `shipping_by_base_currency` groups by an alias shadowed by a real `orders.base_currency_code` column (same GROUP-BY-alias class as the fixed refund buckets; pre-existing, unproven impact).
