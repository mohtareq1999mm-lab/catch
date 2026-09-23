# COMMERCE PICKING FLOW (Phase 1 Lock — order + batch)

> Status: LOCKED. Model: one `picking_tasks` table, `batch_id nullable`
> (NULL = order picking; set = batch picking). No second engine. No wave tables in v1
> (future: `wave_id nullable`, not built).

## 1. Task fields (target)

`batch_id nullable, fulfillment_item_id, order_id/order_item_id (denorm), location_id,
quantity_to_pick, quantity_picked, status, claimed_by/at/expiry, scan_log (json), seq counter`.

## 2. Claim protocol (concurrency-safe)

`UPDATE … SET status='assigned', claimed_by=:u, claimed_at=now, claim_expires_at=now+lease
WHERE id=:t AND status='pending' AND claimed_by IS NULL` — exactly one winner, loser gets 409.
Lease (e.g. 15 min, configurable) heartbeat on scan; expiry sweeper releases to `pending`
(keeps `quantity_picked` for resume). Steal/override requires `fulfillment.override`.

## 3. Confirm protocol (idempotent)

Client `op_seq` per task; unique `(task_id, op_seq)` in scan log → replay ignored.
Tx: lock task → validate status/claimant → validate scans → `remaining = to_pick − picked` →
reject if qty > remaining → increment → fan-back item tally → maybe-complete batch →
audit row. Short-pick opens exception (expected vs actual, reason) instead of force-complete.

## 4. Batch lifecycle

`pending → assigned → picking → completed | cancelled`. Eligibility: released fulfillments,
same warehouse. Fan-in aggregates by (product, location); every unit fans back to its
`fulfillment_item_id`. Partial batch completion allowed; incomplete tasks return to pool on cancel.
