# 03: One owner for the operation heartbeat

**What to build:** One module owns the progress/cancel signal protocol for import and export jobs: path construction, write, read, cancel detection and cleanup. Jobs and controllers use it instead of rebuilding storage paths; dead readers are removed.

Today one JSON-file protocol has three path builders and four readers/writers across the product import service, the import job, the import controller and the brand/category importers. Some readers are dead, and the controller re-derives progress the service already computes.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] Progress and cancellation for product import, category import, brand import and their exports go through one heartbeat module.
- [ ] No job or controller constructs a signal file path directly.
- [ ] Cancellation is detected between chunks and before terminal status, as today.
- [ ] Signal files are cleaned up on success, failure, cancellation and retry as today.
- [ ] Tests assert heartbeat behaviour, not storage paths.
- [ ] Dead signal readers/writers are removed.
