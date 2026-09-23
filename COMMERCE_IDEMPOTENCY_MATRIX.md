# COMMERCE IDEMPOTENCY MATRIX (Phase 1 Lock)

> Status: LOCKED. Verified: `OrderController:308-340` (token+status), reservation
> conditional claims, commit/restore no-ops.

| Operation | Key | Duplicate behavior |
|---|---|---|
| Payment callback/webhook | `transactions.idempotency_key` + order status guard + provider event id | replay ignored; mismatch path audited |
| Reserve/commit/release/restore | locked conditional claim on `inventory_state` | non-matching state = safe no-op/false |
| Create fulfillment | unique `fulfillment_number` + request idempotency key | duplicate key returns existing; N per (order, warehouse) allowed |
| Allocate | deterministic recompute, zero writes | same plan |
| Claim task | conditional `pending→assigned where claimed_by NULL` | loser 409 |
| Confirm pick | unique `(task_id, op_seq)` + locked remaining check | replay ignored; over-pick rejected |
| Create package | client idempotency key unique | return existing |
| Add package item | unique `(package_id, fulfillment_item_id)` + locked invariant | reject/merge explicitly |
| Seal package | conditional `open→sealed` | no-op |
| Create shipment | idempotency key + `ready_to_ship` guard | return existing |
| Cancel fulfillment | terminal-state guard | no-op |
| Coupon-blocked payment (M2 fix) | carry-or-clear policy: keep key, store `blocked_reason`, allow reprocess after fix via privileged retry that rotates key | no permanent wedge |

Rule (§10): distinct key per operation domain — never one generic key.
Concurrency (§33): MySQL/TiDB lock proof required P14; sqlite never accepted as proof.
