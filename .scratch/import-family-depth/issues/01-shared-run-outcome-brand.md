# 01: Shared run outcome for brand importers

**What to build:** The brand import runs on the same family-neutral run outcome the product import uses — counters, the processed = success + failed invariant clamped to the known total, status derivation, one error cap and one import-row write shape — with `BrandImportService` as a thin adapter feeding its own row semantics. Characterization suites taken before any code moves.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] Characterization suites for brand import (broadcast, progress, cancellation, lifecycle) pass unchanged before the port.
- [ ] The generalized outcome module owns counters, invariant, cap, status and the row-write shape; BrandImportService delegates.
- [ ] Brand import status/counters/errors behave exactly as before (verified against characterization).
- [ ] Family-neutral outcome unit tests cover clamping, status derivation, cap and warning ordering.
- [ ] BrandImportService shrinks behind its own row/taxonomy collaborators (mirroring the product facade).
- [ ] No bug fix bundled; any defect found is split out.
