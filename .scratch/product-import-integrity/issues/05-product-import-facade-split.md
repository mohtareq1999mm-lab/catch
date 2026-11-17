# 05: Split the import service behind a narrow facade

**What to build:** A behaviour-preserving split of the import service into a narrow facade plus focused collaborators: a row writer (build, validate, persist a product), a taxonomy linker (queue, resolve, report, sync), and a progress reporter (counters, heartbeat, terminal state). Expand first, migrate call sites in batches, then remove the duplicated logic.

The service still owns row writing, pricing, taxonomy linking, progress signalling, cancellation and reporting. The recent integrity fix made the failure modes loud, but a change to one concern still touches a very large file.

**Blocked by:** 01, 02, 03 (they define the sheet, outcome and heartbeat boundaries)

**Status:** ready-for-agent

- [ ] Row writing, taxonomy linking and progress/counters each live behind one collaborator with a narrow interface.
- [ ] The facade exposes only `ingest(row)`, `finish()`, `report()` and `rollback()` (or an equivalent small set).
- [ ] Characterization tests from the lifecycle, invariant and integrity suites pass unchanged after each batch.
- [ ] Upsert-by-SKU, pricing, barcodes, variants, cancellation and error reporting behave exactly as before.
- [ ] No compatibility shim remains after the contract step.
- [ ] Any bug found during the refactor is split into a separate change.
