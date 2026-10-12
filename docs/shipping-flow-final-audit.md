# Shipping Flow Final Audit (post-implementation review)

Re-read all modified files and re-searched `shipping_type` / flow-selection
call sites after the change. Verdict per section.

## 1. UNIQUE removal — scoped correctly (PASS)

- Migration `2026_10_12_000001` drops ONLY the index covering exactly
  `(shipping_type)` (resolved by introspection; idempotent). `code` UNIQUE,
  `order_flows_shipping_active_idx`, all FKs (`order_flow_statuses` cascade,
  `orders.flow_id` restrict), rows, ids, memberships preserved — verified via
  `SHOW INDEX` before/after and rollback cycle.
- Rollback: aborts with a clear message when duplicates exist (verified live);
  restores the UNIQUE on clean DBs (verified live).
- `OrderFlowUpsertRequest`: type-uniqueness rule removed; `Rule::in` allow-list
  and `code` unique-ignore retained. No other validation weakened.

## 2. Identity — unique on `code` (PASS)

- `code` UNIQUE enforced at DB + validation + tested (duplicate → 422).
- No new identity columns added (none needed).

## 3. Selection queries — deterministic (PASS)

- `resolveFlowForShippingType`: single active → it; several → active default;
  else `flow_ambiguous` 422. No bare `first()` remains in the service.
- `resolveCheckoutFlow`: explicit code/id, active check, type-match check,
  fallback to default resolution. All checkout doors (`OrderService`,
  `OrderCreationService`, Marvel `Order::creating` backstop,
  `FastShippingService`, `OrderRepository` local door) route through it or a
  pinned id. `by-shipping-type` returns the default (documented).
- Remaining `where('shipping_type')` sites: admin filter (list), deactivation
  guard (last-active protection), `enforceSingleDefault` (demotion), seeder
  convergence, `FlowInputSeeder` (now code-first). Each re-verified correct
  under multi-flow.

## 4. Admin CRUD (PASS, tested)

Create ×2 same type 201; duplicate code 422; bad type 422; update same-type
200; update type-change 200 (orders keep `flow_id`); last-active deactivation
blocked; guest 401. No DELETE endpoint exists (unchanged) + `restrictOnDelete`
→ history cannot be orphaned via API.

## 5. Checkout/pricing/orders (PASS, tested)

Explicit `flow_code`/`flow_id` end-to-end; mismatch/inactive/ambiguous 422
with zero orders; default resolution; stored-flow immutability; pending-retry
flow guard; validated-flow pinning (TOCTOU). Pricing untouched
(governorate-based, server-recalculated). Order-status machine untouched
(`allowsFlowTransition` unchanged; supervised exits intact).

## 6. Types — controlled, no false support (PASS)

`SUPPORTED = [local, international]` unchanged in all three enforcement points
(service, both FormRequests). `pickup/express/same_day` remain 422
(`ControlledShippingTypeTest` 3/3). No fulfillment claims added.

## 7. Supported-type matrix (verified)

| Type | Backend accepts | Admin configurable | Checkout selectable | Pricing | Fulfillment | Status |
|---|---|---|---|---|---|---|
| `local` | yes | yes, N flows | yes (code or default) | governorate rates | existing flows | fully implemented |
| `international` | yes | yes, N flows | yes (code or default) | governorate rates | existing flows | fully implemented |
| `pickup` | no (422) | no | no | n/a | pickup-location fields exist but no flow type | not supported |
| `express` | no (422) | no | no | n/a | fast-shipping is a separate local-only fee machine | not supported |
| `same_day` | no (422) | no | no | n/a | no scheduling/eligibility implementation | not supported |

## 8. Worked example — `local_standard` + `local_simplified` (verified pattern)

Admin creates `local_simplified` (`shipping_type: local`) alongside seeded
`local` (= standard): both rows coexist (tested). `GET available` returns both
with distinct `code`/`name`; the client renders both names and submits
`flow_code: local_simplified`; the order stores that `flow_id` permanently
(tested). Type-only checkout follows `is_default` (tested); switching the
default never rewrites the old order (tested).

## 9. Remaining risks

1. Shared `catch` DB carries pre-seeded dev `flow_inputs` (intl) → 43
   pre-existing suite failures; unrelated to this change (baseline-proven).
2. External frontend must adopt `flow_code` selection (documented; no in-repo
   frontend found).
3. Two concurrent admins setting `is_default` can yield zero/two defaults →
   type-only checkout fails closed (422); explicit selection unaffected.
4. `update` type-change is admin-audited only via existing history; no extra
   audit trail added (out of scope).

## 10. Engineering verdict

**Partially verified PASS for the requested scope:** administrators can create
multiple independently identifiable flows per supported type, and the
application resolves, validates, exposes, prices, and assigns each flow
without ambiguity (17/17 focused tests + live migration up/down verification).
Full-suite green is NOT claimed (pre-existing environment failures documented
in the test report). No unrelated business behavior changed.
