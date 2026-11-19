# 02: Shared run outcome for category importers

**What to build:** The category import (the largest remaining service) runs on the same family-neutral run outcome as product and brand — counters, invariant, cap, status and row-write shape — with `CategoryImportService` as a thin adapter feeding its own row and hierarchy semantics. Behaviour-preserving port behind the characterization suites.

**Blocked by:** 01 (the generalized outcome module lands with the brand port)

**Status:** ready-for-agent

- [ ] Characterization suites for category import (broadcast, progress, cancellation, lifecycle, hierarchy) pass unchanged before the port.
- [ ] The category importer delegates counters, invariant, cap, status and the row-write shape to the shared outcome.
- [ ] Category import status/counters/errors behave exactly as before.
- [ ] CategoryImportService splits behind its own row/hierarchy collaborators; no 800+ line service remains in the family.
- [ ] Family-neutral outcome unit tests remain the contract.
- [ ] No bug fix bundled.
