# Round 13 open decisions are closed by recorded recommendations

Date: 2026-11-25  
Approved by: `mohtareq1999mm-lab` (repository owner), by direct instruction to record the recommended decisions.  
Approval evidence: owner instruction in the implementation session on 2026-11-25; owner comments recorded on [#132](https://github.com/mohtareq1999mm-lab/catch/issues/132#issuecomment-7163243615), [#139](https://github.com/mohtareq1999mm-lab/catch/issues/139#issuecomment-7163244202), [#140](https://github.com/mohtareq1999mm-lab/catch/issues/140#issuecomment-7163244777), [#141](https://github.com/mohtareq1999mm-lab/catch/issues/141#issuecomment-7163245365), and [#142](https://github.com/mohtareq1999mm-lab/catch/issues/142#issuecomment-7163246058).  
Tickets: #132 (parent), #139, #140, #141, #142.  
Status: Accepted as decision records. Implementation of affected scopes remains ticket-scoped.

## Context

Round 13 was not implementation-ready because eight decision-shaped scopes were open. The repository owner directed the agent to record the existing recommendations instead of leaving the decisions blocked. This ADR is the durable decision record; it does not itself authorize any implementation beyond the tests that prove the record exists.

R13-Q1 — Short-Ship / partial delivery — remains cancelled entirely. No partial delivery, retry, correction, table, permission, design, or task may be introduced.

## Decision

### R13-Q2 — Replacement eligibility, relationship semantics, and aggregate exclusion (#139)

**Approved decision**

- A replacement is allowed for the same fulfillment after its shipment reaches `returned`.
- The new shipment owns an explicit `replacement_for_shipment_id`.
- The relationship is one-to-one: one returned shipment may have at most one non-cancelled replacement, enforced by a DB unique constraint on the replacement link.
- A returned shipment is excluded from the outstanding aggregate `R` only when it has a linked non-cancelled replacement.
- Replacement creation remains through the existing fulfillment/shipment authority; restocking remains owned by `ReturnService` / `InventoryRestoreService`.
- Provider confirmation is required before any carrier-side replacement operation.
- Operations and finance approval are required before the replacement implementation ships.
- An audited fulfillment return/re-plan transition may be added only in the implementation ticket for this rule.

**Not approved**: generic re-shipping, multiple replacements per returned shipment, direct inventory writes, or using this decision to bypass state-machine guards.

### R13-Q3 — Finance personnel, break-glass, and permission names (#140)

**Approved decision**

- No finance user is granted authority automatically. Finance authority is assigned explicitly through the dedicated `finance` role.
- At launch, assignment follows this process: Security approves the candidate, Finance/HR confirms business authority, an authorized administrator assigns the `finance` role, and the assignment is audited. No default user is granted finance authority.
- Break-glass access is a separate, explicit mechanism with an actor, reason, bounded expiry, and audit record. Expired break-glass access must deny even if a stale permission pivot remains.
- Exact permission names use the existing dotted finance convention:
  - `cod-settlement.view`
  - `cod-settlement.match`
  - `cod-settlement.accept`
  - `cod-settlement.close`
  - `cod-adjustment.approve`
- Finance permissions never include `payments.*` or any direct payment status mutation.
- Broad super-admin seeding excludes finance permissions. Super admin does not automatically receive finance authority.
- General seeder convergence remains a separate reviewed change; it is not authorized implicitly by this decision.

**Not approved**: HR/Finance personnel rosters in source control, broad role inheritance, or unexpired break-glass access.

### R13-Q4 — COD adjustment limits, second approval, and settlement tolerance (#141)

**Approved decision**

- COD adjustment limits are configuration, not code constants.
- Limits are per currency and expressed in minor units.
- Until Finance approves a positive per-currency single-approver limit, the effective threshold is `0`: every COD adjustment requires two distinct approvals, and the creator must not be either approver.
- A positive threshold, when approved, must be recorded with Finance evidence, effective dates, currency, and audit context.
- Rejected adjustment rows are retained as evidence.
- Limits and approval tiers never change payment completion authority. Manual COD completion remains the authoritative payment path.

**Not approved**: hardcoded thresholds, currency inference beyond the order's recorded currency, or allowing limits to mark an order paid.

### R13-Q5 — Unknown-status ownership/SLA and the `delayed` constant

**Approved decisions**

- Unknown provider statuses are owned by the named operational owner team `shipping-operations` by default. A provider may have a narrower named owner in deployment configuration; absent an override, `shipping-operations` owns its unknown-status queue.
- The SLA is one business day from exception creation. Missing owner or SLA fields is a fail-closed configuration error.
- Shipment exceptions carry `owner_id` and `sla_due_at`; ownership and SLA are visible to operations and auditable.
- The canonical order-mirror constant is `Order::SHIPMENT_STATUS_DELAYED`. It may be added only in a ticket that owns mirror synchronization.

**Not approved**: an unowned unknown-status queue or adding the mirror constant outside mirror-synchronization scope.

### R13-Q6 — Guest notification consent and registered-user preferences

**Approved decision**

- Guest shipment notifications remain disabled pending legal sign-off. No guest delivery notification may be enabled by assumption.
- When guest transactional shipment updates are later approved, they must be limited to transactional shipment templates and the order-bound contact; consent/opt-out must be recorded and honored per channel.
- Registered-user notification preferences apply to shipment notifications.

**Not approved**: guest marketing notifications or inferred consent.

### R13-Q7 — Public tracking item names and prices

**Approved decision**

- Public order-number tracking must not expose item names or prices.
- It may expose a count of items.
- Item names and prices remain available only to the authenticated owner or a valid guest-token detail surface.

**Not approved**: public price/item payloads.

### R13-Q8 — Currency-specific settlement variance threshold

**Approved decision**

- Settlement variance tolerance remains zero by default. Any nonzero tolerance is per currency, Finance-approved, evidence-backed, audited, and never applies to unmatched lines.

**Not approved**: a nonzero tolerance without dated Finance evidence and audit context.

## Consequences

- Blocked Round 13 tickets can now be closed as decision records without inventing implementation behavior.
- Affected implementation tickets must cite this ADR and remain within the approved boundary.
- R13-Q1 remains closed and must not be reopened by derived work.
- Any contradiction between this ADR and future implementation requires a new, explicitly approved ADR.

## Verification

The static decision-record test asserts this file records the approved owner, date, permission names, zero default tolerance, delayed constant ownership, guest notification restriction, and public-tracking privacy boundary. It contains no runtime code change.

This ADR is a consolidation record. Individual alternatives were already considered in the Round 13 decision items; this file records the accepted decision and its boundaries rather than repeating every alternative.
