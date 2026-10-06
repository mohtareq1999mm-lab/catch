# Phase 11 — Return Lifecycle

## 1. Executive Verdict

**NEEDS ATTENTION.** The manual's headline claim ("no dedicated return system exists") is now half-obsolete: a well-guarded return service + models + tables exist (request → approve/reject → receive → inspect → restock → complete/cancel, with RMA-style numbers, warehouse-scoped restocking, duplicate-restock protection, and central-restore integration). But the system is **unreachable in production**: no routes, no controllers, no events, no notifications, and no callers anywhere in the codebase. Returns therefore still flow only through the refund system exactly as the manual describes. Additionally, completion moves no money (no refund/credit linkage), and the manual's economics (restocking fees, conditions, replacements, windows) are unimplemented. This phase is structurally built but operationally dark.

## 2. Phase Objective

Per `PHASE-11-RETURN-LIFECYCLE.md` (source: `HEAD:docs/production-manual/PHASE-11-RETURN-LIFECYCLE.md`, 788 lines): establish that no return system exists, describe the refund-only workaround, and specify a full return system (status machine, models, inspection, restocking, replacement, integration, edge cases, 5-phase roadmap).

## 3. Scope

**In scope:** return request/approval/receive/inspect/restock/complete/cancel mechanics, status guards, RMA numbering, warehouse-scoped restocking, central-restore integration, refund/money linkage (absent), API exposure (absent), events/notifications (absent).

**Out of scope:** refund approval mechanics (Phase 10), fulfillment internals (Phase 09), replacement-order fulfillment (no such flow exists), courier return labels (none exist).

## 4. What Was Supposed to Be Implemented

The manual claims: nothing exists; returns work only via refunds after out-of-band support contact; and specifies a 13-state machine, RMA sequences, inspection with condition economics and restocking fees, replacement orders, refund integration, and a 5-phase (~120h) roadmap.

## 5. What Actually Exists

A service-level return system with no operational surface:

- **Models**: `ReturnRequest` (fillable lifecycle fields + actor/timestamp columns, soft deletes, scopes per status, guards `canBeApproved/canBeReceived/canBeInspected/canBeRestocked/isComplete`) + `ReturnItem` (`isRestockable/isFullyRestocked`, quantity approved/restocked, condition free-string default `unknown`, location links).
- **Service**: `ReturnService` (401 lines) — `createReturnRequest` (requires shipped/delivered fulfillment; per-item rows), `approveReturnRequest` (approved-quantity capping), `rejectReturnRequest`, `markAsReceived`, `startInspection`, `inspectReturnItem`, `restockReturnItem` (cross-warehouse integrity pre-check, central `restoreLines` first, location placement, duplicate-restock refusal, approved-quantity cap), `completeReturnRequest` (all-restockable-items-restocked gate), `cancelReturnRequest`, `generateReturnNumber` (`RET-YYYYMMDD-XXXXXXXX` with collision loop), warehouse pending/stats queries.
- **Tables**: `return_requests` + `return_items` (migrations `2026_09_22_192635/43`).
- **Tests**: `ReturnRecoveryTest` (central per-line restore, duplicate-restock rejection, cancel-after-partial no-double-credit, operational-cancel task skipping).
- **Absent**: routes (zero matches across all route files), controllers (none), events/listeners, notifications, refund/credit creation on completion, replacement orders, restocking fees, condition enum/economics, return windows, RMA gapless sequencing, customer self-service, admin UI endpoints, scheduled jobs. **Zero callers** of `ReturnService` outside itself (verified by repository-wide search).

## 6. Architecture

```
(nothing calls this system; documented as-built for when it is wired)

ReturnService (service-only, transaction-wrapped writes, status-gated):
  create (pending; fulfillment shipped/delivered required)
    → approve (approved + per-item approved qty) / reject (rejected + reason)
    → receive (received) → inspect (inspecting; per-item condition free-text)
    → restock per item (restockable + not-fully-restocked + qty ≤ approved;
        warehouse-match pre-check → central restoreLines → location placement)
    → complete (all restockable restocked) | cancel (unless complete)

Actual production returns (unchanged from manual): support contact → refund request →
  approval → RefundApproved fan-out (Phase 10). No RMA, no inspection, no restock step.
```

## 7. Complete Execution Flow

Service-level flow (as coded, unreachable via HTTP): create → approve/reject → receive → inspect → restock → complete/cancel with the guards above. Money movement: none — completion is stock-only; refunds require the separate Phase-10 flows (which re-run inventory restoration safely via the shared state claim, per `ReturnRecoveryTest`).

## 8. Business Rules

| # | Rule | Implementation | Reachable? |
|---|---|---|---|
| R1 | Returns only for shipped/delivered fulfillments | `createReturnRequest` guard | No (no API) |
| R2 | Linear status gates (pending→approved→received→inspecting→restocked→completed; reject/cancel exits) | Model guards enforced per transition | No |
| R3 | Restock capped at approved quantity; duplicates refused loudly | `restockReturnItem` guards | No |
| R4 | Restock confined to the request's warehouse (pre-write check) | P2-1 integrity block | No |
| R5 | Central authority moves before location projection | restore-then-place ordering | No |
| R6 | RMA-style numbers unique via collision loop | `generateReturnNumber` | No |
| R7 | Completion requires all restockable items restocked | `completeReturnRequest` gate | No |
| R8 | Production returns = refund-track only (manual flow preserved) | Phase 10 paths | **Yes (the only live flow)** |

## 9. Source of Truth / Authorities

- **Return state**: `ReturnService` + model guards (sole writer; no other callers, no API).
- **Restocked stock**: central `InventoryRestoreService::restoreLines` (authority) + `ProductLocation` placement (projection) — correct layering for when wired.
- **Money on returns**: none — refund tracks own it independently (Phase 10).
- **Live return behavior**: the refund system (unchanged from manual).

## 10. Database Impact

`return_requests` (RMA number, order/fulfillment/warehouse/customer links, actor + timestamp columns, notes/images/metadata, soft deletes) + `return_items` (order/fulfillment/product links, quantities requested/approved/restocked, condition free-text, location links). Tables are written only via the service (currently: only via tests/tinker). No constraints beyond FKs; RMA uniqueness is application-looped, not DB-unique (see F-05).

## 11. API Surface

**None.** No customer return endpoints, no admin return endpoints, no RMA lookup, no return-status webhooks. (The manual's "no self-service portal" gap persists structurally, not just as UX.)

## 12. Authentication & Authorization

No surface → no auth model. When wired, the service takes `userId` parameters (requester/approver/receiver recorded) but performs no permission checks itself — authorization must be added at the controller layer (absent). Any future wiring must not expose `createReturnRequest` without ownership + window checks.

## 13. Validation

Service-level: fulfillment-state precondition, status gates, quantity caps, warehouse match, restockability. Absent: return-window validation, item-belongs-to-order verification (order_item_id accepted as given — future API must verify against the order), condition allowlist (free string), image/evidence handling, policy/reason linkage.

## 14. Transactions

`createReturnRequest` and `restockReturnItem` are transactional with pre-write guards; approve/receive/inspect/complete/cancel are single-row updates (non-transactional, acceptable — no multi-row invariants except completion's all-restocked check-then-set, which races under concurrent restock+complete; see F-06).

## 15. Concurrency

| Case | Protection | Classification |
|---|---|---|
| Duplicate restock | `isFullyRestocked` refusal + approved-qty cap | STRONGLY REASONED |
| Cross-warehouse placement | pre-write warehouse match | STRONGLY REASONED |
| Central double-credit | shared restore authority (tested: cancel-after-partial) | STRONGLY REASONED |
| Complete-vs-restock race | check-then-set without lock (F-06) | UNPROVEN |
| True parallel proof | `ReturnRecoveryTest` only | UNPROVEN — NOT EXECUTED |

## 16. Async / Queues / Events

None. No return events, no listeners, no notifications, no deadlines/schedulers. The manual's recommended event architecture (Requested/Authorized/Received/Inspected/Calculated/Replacement) does not exist.

## 17. Error Handling

Service throws `Exception` with descriptive messages on guard violations (callers — when they exist — must map to HTTP semantics; no exception hierarchy, no reason codes). Logging on lifecycle transitions (info/warning) with ids. No user-facing surface to evaluate.

## 18. Security

No attack surface exists (no routes). Latent requirements for wiring: ownership checks (requester owns order), window enforcement, item-membership verification, admin authorization, rate limiting, image-upload safety. None implemented — the service layer trusts its inputs' provenance.

## 19. Performance

`getPendingReturns`/`getReturnStatistics` are warehouse-scoped with eager loads (suitable for future admin lists). Restock is per-item transactional (fine at return scale). RMA generation loops on collision (vanishingly rare; acceptable).

## 20. Tests & Verification

`tests/Feature/Fulfillment/ReturnRecoveryTest.php` (4 tests: central restore, duplicate refusal, cancel interplay, task skipping). No API tests (no API), no status-guard unit tests found, no window/fee tests (no such logic). **Not executed** (environment).

## 21. Edge Cases

Covered in service: duplicate restock (refused), cross-warehouse placement (refused), over-approved quantity (capped/refused), non-restockable condition (refused), incomplete restock on complete (refused), cancel-after-complete (refused), cancel-after-partial (no double credit, tested). Not covered: return window, item-membership fraud, partial-refund linkage, replacement stockouts, lost return shipments, fee disputes (no fees exist), multi-batch returns (model supports multiple requests per order structurally).

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: ARCHITECTURE
- Status: PROVEN
#### Finding
The return system has no operational surface: zero routes, zero controllers, zero events, zero callers. It cannot be used by customers, admins, or warehouse staff through any interface. Production returns still follow the manual's refund-only workaround exactly.
#### Evidence
Route search across `routes/api.php`, `Rest/Routes.php`, `web.php` (zero return routes); controller glob (zero return controllers); repository-wide `ReturnService|createReturnRequest|approveReturnRequest` caller search (zero external callers).
#### Why it matters
A 400-line service + 2 tables + tests exist that do nothing in production; the phase's production purpose is unmet and the code will rot (callers, permissions, and UX were never designed).
#### Current behavior
Dead-but-tested code; live flow = refunds only.
#### Recommended future action
Either wire the Phase-7-style WMS/customer API (controllers + permissions + events + refund linkage) or formally mark the system experimental; do not let it drift.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: MEDIUM
- Type: BUSINESS LOGIC
- Status: PROVEN
#### Finding
`completeReturnRequest` moves no money: no refund row, no credit note, no event, no replacement order. A "completed" return restocks goods while the financial reversal must happen through a separate, unlinked refund flow (which re-handles inventory safely but duplicates process and has no return linkage for audit).
#### Evidence
`ReturnService.php:306-333` (status-only completion); no refund/credit/event references in the service (verified by search).
#### Why it matters
Goods and money diverge by process design; auditors cannot trace return → refund; partial-return economics have no home.
#### Current behavior
Stock-only completion.
#### Recommended future action
Implement the manual's return→refund integration (return completion proposes/links a refund via Track A/B with item amounts) before exposing any API.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: LOW
- Type: BUSINESS LOGIC
- Status: PROVEN
#### Finding
The manual's return economics are unimplemented: no restocking fees, no condition enum (free-text `unknown` default), no replacement flow, no return window, no RMA gapless sequence (uniqid loop instead), no policy linkage.
#### Evidence
`ReturnService.php` + `ReturnItem.php` (no fee/condition/window/replacement code); manual §§Inspection/Restocking/Replacement.
#### Why it matters
Even when wired, the system authorizes physical returns without the financial controls the manual specifies.
#### Current behavior
Physical-only workflow.
#### Recommended future action
Add condition enum + fee policy + window validation + replacement design before production wiring.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-04
- Severity: INFO
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The manual's "no dedicated return system" claim is now false at the code level (models/service/tables/tests exist) but true operationally (nothing reachable). Both halves need stating to avoid either "returns exist" or "nothing exists" misunderstandings.
#### Evidence
This phase's verification.
#### Why it matters
Planning against either half-truth misallocates work.
#### Current behavior
Built but dark.
#### Recommended future action
Update the manual to "service built, unwired" with the wiring checklist (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-05
- Severity: LOW
- Type: DATA INTEGRITY
- Status: PROVEN
#### Finding
RMA-style `return_number` uniqueness is enforced by a check-then-insert loop with no DB unique constraint — concurrent creations can collide (both pass the `exists()` check, both insert).
#### Evidence
`ReturnService.php:360-368`; no unique index asserted on `return_requests.return_number` (migration family review).
#### Why it matters
Duplicate RMA numbers corrupt warehouse paperwork identity.
#### Current behavior
Unlikely collision window (uniqid entropy), unguarded at DB level.
#### Recommended future action
Add a unique index + retry-on-violation (or reuse the locked `InvoiceNumberService` RMA series as the manual suggested).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-06
- Severity: LOW
- Type: CONCURRENCY
- Status: STRONGLY REASONED
#### Finding
`completeReturnRequest`'s all-restocked check-then-set runs outside a transaction/lock: a concurrent restock completing between the check and the status write is benign (re-check would pass anyway), but a concurrent restock *reversion* has no path — the real exposure is a concurrent `cancelReturnRequest` interleaving (cancel allowed unless complete; complete allowed unless restocked-gate fails) with no mutual exclusion.
#### Evidence
`ReturnService.php:306-355` (no locks/transactions on complete/cancel paths).
#### Why it matters
Low-traffic admin surface, but completion and cancellation should be mutually exclusive under lock when wired.
#### Current behavior
Unlocked single-row writes.
#### Recommended future action
Lock the request row (`lockForUpdate`) in complete/cancel/reject paths when building the API.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Return created then never processed**: no deadlines/schedulers — requests can sit in `pending` indefinitely (no aging alert).
- **Refund issued separately for returned goods**: safe inventory-wise (shared authority), but no linkage for audit (F-02).
- **Concurrent duplicate service calls** (future API double-submit): creation has no idempotency key; restock is duplicate-safe; completion is gate-safe; approval quantity writes are last-writer-wins.
- **Warehouse mismatch at restock**: loud refusal pre-write (no partial placement).
- **Return for digitally-delivered goods**: no digital guard at the return layer (refund layer has D7; return layer predates it).

## 24. Documentation Drift

Manual accurate for: the live refund-only reality, recommended architecture/state machine/model shapes (the built system follows them loosely — fewer states, no fees/replacement), integration risks (double-restore concern pre-solved by the shared authority), edge-case catalog (still valid as requirements). Obsolete: "nothing exists" (§§1–2), effort estimates predicated on greenfield, and any implication that wiring/notifications/policies exist.

## 25. Dependencies

- **Depends on**: Phase 09 (fulfillment states as return precondition), inventory restore authority, warehouse master data, (future) refund tracks, auth/permissions (future).
- **Consumed by**: nothing (unwired).
- **Shared tables**: `return_requests`, `return_items` (isolated); reads `orders/fulfillments/warehouses/locations/product_locations`.
- **Shared services**: `ReturnService` (isolated); `InventoryRestoreService::restoreLines`, `ProductLocationService` (integrations verified).

## 26. Out of Scope

Refund approval mechanics (Phase 10), replacement-order design beyond the manual's sketch, courier return labels, customer portal UX, restocking-fee policy design, vendor defective returns.

## 27. Residual Risks

1. System unwired (F-01) — phase purpose unmet.
2. Completion moves no money (F-02).
3. No return economics (F-03).
4. RMA uniqueness application-only (F-05).
5. Complete/cancel mutual exclusion unlocked (F-06).
6. Manual half-obsolete (F-04).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-11-RETURN-LIFECYCLE.md` (788 lines, read fully, temp extract). Code: `app/Services/Fulfillment/ReturnService.php` (full, 401 lines); `app/Models/Fulfillment/ReturnRequest.php` (guards/scopes verified) + `ReturnItem.php` (restock guards verified); migrations `2026_09_22_192635/43`; negative evidence (zero routes/controllers/callers/events — searched `routes/api.php`, `Rest/Routes.php`, `web.php`, controller trees, repository-wide service references); `InventoryRestoreService::restoreLines` (integration point); `ReturnRecoveryTest.php` (4 tests, listed, not executed).

## 29. Final Assessment

**Verdict: NEEDS ATTENTION.**

A competent service core exists and its inventory integration is correctly layered — but the phase delivers no production capability: no API, no callers, no money movement, no economics. The verdict reflects unmet production purpose, not code quality. Wire the API + refund linkage + economics before this phase can pass.
