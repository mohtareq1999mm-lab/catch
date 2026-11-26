# Settlement file storage — Phase 0 evidence (#185)

Scope: ticket-local evidence only. Risk identities, titles, and order are derived from the approved matrix in `docs/shipping-discovery/01-existing-architecture-audit.md:150-179` (Doc 1 §5.1) and `docs/shipping-discovery/07-implementation-backlog.md:26-42` (Doc 7 Phase 0), not from this ticket's provisional labels. No reviewer approval is claimed here; every risk remains `PENDING REVIEW` until its authoritative closure artifact is recorded in its own PR.

Required order: R7 → R4 → R1 → R2 → R3 → R5 → R6 → R8 → R10.

Final harness command (shared by every row below):

```powershell
& 'C:/Users/mohta/AppData/Local/Temp/opencode/run_wt_tests.ps1' -Worktree 'C:/Users/mohta/AppData/Local/Temp/opencode/wt-185-settlement-file-storage' -Tests @('C:/Users/mohta/AppData/Local/Temp/opencode/wt-185-settlement-file-storage/tests/Feature/Settlement/SettlementFileStorageTest.php','C:/Users/mohta/AppData/Local/Temp/opencode/wt-185-settlement-file-storage/tests/Feature/Settlement/Phase0EvidenceGateTest.php','C:/Users/mohta/AppData/Local/Temp/opencode/wt-185-settlement-file-storage/tests/Feature/Settlement/SettlementFileImportValidationTest.php') -DbPrefix 'catch_185fix'
```

Recorded harness result (fresh DB `catch_185fix_20261125110932_b2140e`): PASS for the ticket-local guards only — `SettlementFileStorageTest` 5 tests/67 assertions, `Phase0EvidenceGateTest` 1 test/177 assertions, `SettlementFileImportValidationTest` 7 tests/32 assertions. Authoritative Phase 0 closure artifacts remain outstanding as listed per risk below.

## R7 Single transition emitter

Command: shared `run_wt_tests.ps1` harness above with `-DbPrefix 'catch_185fix'`; evidence is `SettlementFileStorageTest::test_parser_is_read_only_and_does_not_mutate_financial_state`.
Environment: isolated worktree, branch `feat/185-settlement-file-storage`, APP_ENV=testing, MySQL `catch_185fix_*`, PHP 8.2.30, Laravel 10.30.1, Queue sync, synthetic CSV only; no provider traffic.
Result: PASS for ticket-local guard — import/parser emitted no domain events, no bus/queue jobs, and no financial row changes. Authoritative closure artifact is NOT PROVIDED here; status remains PENDING REVIEW; this ticket did not touch canonical shipment dispatch.
Closure requirement: timeline write inside the shipment transaction; `ShipmentStatusChanged` after commit with `shipment_id`, `timeline_event_id`, `order_id`, old/new status, `source`, actor; rollback and duplicate-listener dedupe evidence.
Gap: canonical-dispatch, rollback, after-commit, and dedupe tests remain PENDING REVIEW.
Classification: INFERRED
Evidence: `app/Contracts/Settlement/SettlementStatementParser.php`, `app/Services/Settlement/Parsers/CsvSettlementStatementParser.php`, `tests/Feature/Settlement/SettlementFileStorageTest.php`.
Status: PENDING REVIEW

## R4 Single mirror writer + canonical ETA + aggregate truth table

Command: shared `run_wt_tests.ps1` harness above with `-DbPrefix 'catch_185fix'`; guard runs cover import/parser financial snapshots.
Environment: isolated worktree, branch `feat/185-settlement-file-storage`, APP_ENV=testing, MySQL `catch_185fix_*`, PHP 8.2.30, Laravel 10.30.1, Queue sync; no provider traffic or adjacent lifecycle authority changes.
Result: PASS for ticket-local guard — no settlement import path wrote order, shipment, statement, or line state. Authoritative closure artifact is NOT PROVIDED here; status remains PENDING REVIEW; no truth-table or ETA work was part of this ticket.
Closure requirement: single `syncOrderMirror()` writer; amended truth table with pairwise/key-combination tests; ETA precedence and unavailability; `actual_delivery_at` set/clear; single-shipment customer contract.
Gap: truth-table matrix, ETA/mirror tests, and writer call-graph evidence remain PENDING REVIEW.
Classification: INFERRED
Evidence: `app/Services/Settlement/SettlementFileImportService.php`, `tests/Feature/Settlement/SettlementFileStorageTest.php`.
Status: PENDING REVIEW

## R1 Creation gate

Command: shared `run_wt_tests.ps1` harness above with `-DbPrefix 'catch_185fix'`; no HTTP route, controller, or public file URL was added by this ticket.
Environment: isolated worktree, branch `feat/185-settlement-file-storage`, APP_ENV=testing, MySQL `catch_185fix_*`, PHP 8.2.30, Laravel 10.30.1, Queue sync; no provider traffic.
Result: PASS for ticket-local guard — private storage metadata only, with no shipment creation path invoked. Authoritative closure artifact is NOT PROVIDED here; status remains PENDING REVIEW; the shipment route gate was not changed by this ticket.
Closure requirement: order-only `POST /shipments` removed or routed through `createForFulfillment()`; no shipment without `ready_to_ship`; idempotent create and WMS shapes preserved; `route:list` evidence.
Gap: creation-gate regression test and `route:list` evidence remain PENDING REVIEW.
Classification: INFERRED
Evidence: `config/settlement.php`, `app/Services/Settlement/SettlementFileImportService.php`, `tests/Feature/Settlement/SettlementFileImportValidationTest.php`.
Status: PENDING REVIEW

## R2 updateStatus / delivered convergence

Command: shared `run_wt_tests.ps1` harness above with `-DbPrefix 'catch_185fix'`; import/parser financial-snapshot assertions ran in isolation.
Environment: isolated worktree, branch `feat/185-settlement-file-storage`, APP_ENV=testing, MySQL `catch_185fix_*`, PHP 8.2.30, Laravel 10.30.1, Queue sync, synthetic CSV only.
Result: PASS for ticket-local guard — no order, statement, line, or payment state was mutated. Authoritative closure artifact is NOT PROVIDED here; status remains PENDING REVIEW; no `delivered` transition path was exercised.
Closure requirement: every `delivered` path converges through `markDelivered()` invariants; direct `updateStatus('delivered')` refused or routed; mirror/fulfillment/order assertions.
Gap: delivered-bypass convergence regression evidence remains PENDING REVIEW.
Classification: INFERRED
Evidence: `tests/Feature/Settlement/SettlementFileStorageTest.php`, `app/Services/Settlement/SettlementFileImportService.php`.
Status: PENDING REVIEW

## R3 Admin endpoint delegation

Command: shared `run_wt_tests.ps1` harness above with `-DbPrefix 'catch_185fix'`; settlement import asserts zero payment/order/shipment lifecycle side effects.
Environment: isolated worktree, branch `feat/185-settlement-file-storage`, APP_ENV=testing, MySQL `catch_185fix_*`, PHP 8.2.30, Laravel 10.30.1, Queue sync; no payment gateway calls.
Result: PASS for ticket-local guard — no admin shipment status endpoint or direct mirror write was added. Authoritative closure artifact is NOT PROVIDED here; status remains PENDING REVIEW; controller delegation was not changed by this ticket.
Closure requirement: admin shipment status endpoints delegate to `ShipmentService`; no direct mirror write; no duplicate transition dispatch.
Gap: controller delegation test proving one service call and no direct writes remains PENDING REVIEW.
Classification: INFERRED
Evidence: `app/Services/Settlement/SettlementFileImportService.php`, `tests/Feature/Settlement/SettlementFileStorageTest.php`.
Status: PENDING REVIEW

## R5 Delayed/failed paths

Command: shared `run_wt_tests.ps1` harness above with `-DbPrefix 'catch_185fix'`; no shipment transition matrix was executed by this ticket.
Environment: isolated worktree, branch `feat/185-settlement-file-storage`, APP_ENV=testing, MySQL `catch_185fix_*`, PHP 8.2.30, Laravel 10.30.1, Queue sync; synthetic statement rows only.
Result: PASS for ticket-local guard — no WMS status transition or shipment state mutation occurred. Authoritative closure artifact is NOT PROVIDED here; status remains PENDING REVIEW; delayed/failed WMS paths were not changed.
Closure requirement: `delayed → in_transit|out_for_delivery` and `failed_delivery → out_for_delivery|returned` are reachable; terminal states cannot regress.
Gap: extended transition-matrix governance test remains PENDING REVIEW.
Classification: INFERRED
Evidence: `tests/Feature/Settlement/SettlementFileStorageTest.php`, `app/Services/Settlement/SettlementFileImportService.php`.
Status: PENDING REVIEW

## R6 Per-item warehouse authorization

Command: shared `run_wt_tests.ps1` harness above with `-DbPrefix 'catch_185fix'`; evidence is mode/account/statement-scoped import identity plus validation rejection tests.
Environment: isolated worktree, branch `feat/185-settlement-file-storage`, APP_ENV=testing, MySQL `catch_185fix_*`, PHP 8.2.30, Laravel 10.30.1, Queue sync; no provider traffic.
Result: PASS for ticket-local guard — import scope is sandboxed by statement identity, and rejected inputs leave no files or rows. Authoritative closure artifact is NOT PROVIDED here; status remains PENDING REVIEW; bulk WMS authorization was not changed.
Closure requirement: every bulk create/dispatch/deliver validates each fulfillment/shipment warehouse; cross-warehouse id returns 403 or masked 404.
Gap: cross-warehouse bulk tests remain PENDING REVIEW.
Classification: INFERRED
Evidence: `app/Services/Settlement/SettlementFileImportService.php`, `tests/Feature/Settlement/SettlementFileStorageTest.php`, `tests/Feature/Settlement/SettlementFileImportValidationTest.php`.
Status: PENDING REVIEW

## R8 Event namespace/listeners

Command: shared `run_wt_tests.ps1` harness above with `-DbPrefix 'catch_185fix'`; evidence is the event/bus/queue purity guard plus a repository search for settlement event/listener additions.
Environment: isolated worktree, branch `feat/185-settlement-file-storage`, APP_ENV=testing, MySQL `catch_185fix_*`, PHP 8.2.30, Laravel 10.30.1, Queue sync; no provider traffic.
Result: PASS for ticket-local guard — parser/import dispatched no events or jobs and added no event/listener registration. Authoritative closure artifact is NOT PROVIDED here; status remains PENDING REVIEW; namespace and listener registration audits for shipping remain PENDING REVIEW.
Closure requirement: canonical events use `App\Events\...`; listener registration verified in both providers; listener errors are not swallowed.
Gap: dispatch/registration test, listener-failure visibility, and namespace audit output remain PENDING REVIEW.
Classification: INFERRED
Evidence: `app/Contracts/Settlement/SettlementStatementParser.php`, `tests/Feature/Settlement/SettlementFileStorageTest.php`.
Status: PENDING REVIEW

## R10 ReturnStatus ownership

Command: shared `run_wt_tests.ps1` harness above with `-DbPrefix 'catch_185fix'`; evidence is the read-only import model/migration review and financial-snapshot guard.
Environment: isolated worktree, branch `feat/185-settlement-file-storage`, APP_ENV=testing, MySQL `catch_185fix_*`, PHP 8.2.30, Laravel 10.30.1, Queue sync; synthetic CSV only.
Result: PASS for ticket-local guard — settlement evidence rows have no lifecycle state or transition owner. Authoritative closure artifact is NOT PROVIDED here; status remains PENDING REVIEW; return status ownership was not changed.
Closure requirement: one authoritative `ReturnStatus`; legacy values read-compatible; no new `restocked` transition.
Gap: enum/transition test and legacy-row read test remain PENDING REVIEW.
Classification: INFERRED
Evidence: `app/Models/Settlement/SettlementImportFile.php`, `database/migrations/2026_11_25_000001_create_settlement_import_files_table.php`, `tests/Feature/Settlement/SettlementFileStorageTest.php`.
Status: PENDING REVIEW
