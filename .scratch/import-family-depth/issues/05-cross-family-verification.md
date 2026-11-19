# 05: Cross-family verification run

**What to build:** An isolated-database verification run proving product, category and brand imports agree on outcome semantics (invariant, cap, status, error reporting) after the shared-outcome port, with evidence attached to the ticket.

**Blocked by:** 01, 02, 03, 04

**Status:** ready-for-agent

- [ ] Run on dedicated databases, never the live catch database.
- [ ] Product, category and brand imports each produce consistent outcome rows (invariant holds, one cap, status rule identical).
- [ ] Image, taxonomy and filter behaviour verified per family.
- [ ] All suites green: existing characterization plus the new family-neutral outcome tests.
- [ ] Evidence attached to the ticket; databases discarded afterwards.
