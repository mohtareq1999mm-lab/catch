# 02: One owner for the import run outcome

**What to build:** One module owns the run outcome: per-category counters, the `processed = success + failed ≤ total` invariant, status derivation, the error cap and the single error-persistence path. Image errors merge before the terminal write, `download_available` is derived from the final error set, and aggregated warnings are never dropped.

Today the invariant is computed in three places with different tie-breaks, error persistence uses three different caps, image errors can be overwritten by the terminal write when the queue is synchronous, and `download_available` is decided before image work finishes.

**Blocked by:** 01 (both change the import job; sequence the job edits)

**Status:** ready-for-agent

- [ ] One module answers: processed/success/failed counts, status, and the full error list.
- [ ] Image failures appear in the final import report, including on a synchronous queue.
- [ ] `download_available` is true exactly when the final report is non-empty.
- [ ] One error cap is applied consistently; aggregated warnings survive it.
- [ ] The invariant `processed = success + failed` and the clamp to `total_rows` hold under partial failure, cancellation and retries.
- [ ] Characterization tests cover success, partial, permanent failure, retry, cancellation and image-only failure.
- [ ] The existing lifecycle/invariant suites stay green.
