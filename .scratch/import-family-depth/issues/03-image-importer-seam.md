# 03: One image importer seam

**What to build:** One image importer module owns the per-row image download — handler reuse across the run, duplicate-media check, URL validation, failure reporting into the import report — and the image job calls it directly instead of re-instantiating the whole import service per chunk. The error-merge rule moves into the report (already idempotent) instead of living in the job.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] One module owns per-row image download and reporting; the image job dispatches and persists only.
- [ ] Handler reuse across chunks (no full service re-instantiation per chunk).
- [ ] Image error merge is unit-tested (no duplication, no loss, capped).
- [ ] The async image path stores every image (existing media test stays green).
- [ ] Image failures appear in the final report on a synchronous queue (existing behaviour preserved).
