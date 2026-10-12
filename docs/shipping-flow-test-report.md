# Shipping Flow Test Report

Environment: Windows, PHP 8.2.30, PHPUnit 10.0.13, MySQL `catch` @127.0.0.1
(shared dev+test database per `phpunit.xml`; `DatabaseTransactions` isolates
each test). Suite locale `en`.

## 1. New suite: MultiFlowPerTypeTest (14 tests, 51 assertions — PASS)

Command: `php vendor\bin\phpunit --filter MultiFlowPerTypeTest`
Result: `OK (14 tests, 51 assertions)`.

| Test | Proves |
|---|---|
| Two flows can share one shipping type | POST second `local` flow → 201, coexistence |
| Duplicate flow code is rejected | `code` UNIQUE still enforced (422) |
| Update without changing type succeeds | No self-collision on update (200) |
| Update type change to supported type succeeds | `local`→`international` (200), orders untouched by design |
| Unsupported type still rejected | `same_day` 422 admin-side, 0 rows |
| Checkout with explicit flow code assigns that flow | Order `flow_id` = selected flow, `shipping_type` kept, `pending` |
| Checkout with explicit flow id assigns that flow | Same via `flow_id` |
| Checkout mismatched flow and type is rejected | intl flow + `local` → 422, 0 orders |
| Checkout inactive flow is rejected | Deactivated flow → 422, 0 orders |
| Type only checkout resolves the default flow | Default pin honored |
| Type only checkout without default is ambiguous | Two actives, no default → 422, 0 orders |
| Existing order keeps flow after default switch | Stored `flow_id` immutable |
| Pending retry with different flow is rejected | 422, 1 order, original flow kept |
| Guest cannot manage flows | Unauthenticated POST → 401, 0 rows |

## 2. Updated contract test (PASS)

`OrderStatusFlowTest::test_second_flow_for_same_shipping_type_allowed`
(renamed from `..._rejected`, now asserts 201 + coexistence). Class result:
23 tests, 1 error + 4 failures — all 5 verified pre-existing (see §4).

## 3. Regression suites

- `ControlledShippingTypeTest`: 3/3 PASS (`local|international` resolve;
  `same_day/express/pickup/hyperloop` 422; local checkout works).
- Combined `MultiFlowPerTypeTest|ControlledShippingTypeTest`: **17/17 PASS
  (64 assertions)** after the final TOCTOU-hardening edit.
- Full `--filter OrderFlow` (202 tests): 19 errors + 25 failures. Baseline
  comparison via `git stash -u` (identical command on unmodified tree):
  **43 failures before vs 44 after; the single delta is the old-contract
  `..._rejected` test** (since updated). All other 43 are pre-existing
  environment issues, notably: (a) pre-seeded dev `flow_inputs` rows
  (ids 1–3, `from_country/to_country/customs_reference` on intl flow) in the
  shared `catch` DB colliding with suite setUp inserts (proven: identical
  `BulkFlowInputsTest` FFEF.F signature with and without my changes);
  (b) `Unsupported currency: USD` config-dependent cases.

## 4. Migration verification (MySQL, `catch` — local dev DB, non-production)

- `php artisan migrate` → `2026_10_12_000001... DONE`; `SHOW INDEX FROM
  order_flows` = `PRIMARY, order_flows_code_unique,
  order_flows_shipping_active_idx` (type-unique gone, identity + active idx kept).
- Rollback with duplicate types present → aborts:
  `Cannot restore order_flows.shipping_type UNIQUE: duplicate flows share
  type(s) [local]` (data preserved — verified).
- Rollback clean → `DONE`, `order_flows_shipping_type_unique` restored.
- `migrate` again → `DONE`, unique dropped. Final state: migrated.
- Migration is idempotent (no-op success when the index is already absent,
  e.g. after the test-helper heal).

## 5. Static checks

`php -l` clean on all 11 touched/new PHP files. No new static-analysis
tooling exists in the repo (no phpstan/psalm config); none added.

## 6. Failures fixed during the task

1. Test-helper schema still created `shipping_type->unique()` → dropped it +
   added a data-preserving heal (`dropLegacySingleFlowUniqueIndex`) for
   test DBs created before the migration. (Side effect found: DDL in `setUp`
   implicit-commits MySQL transactions; handled by running the migration on
   the shared DB + force-deleting the soft-deleted leftover users.)
2. Pending-retry test got 400 (consumed cart) → re-create cart before retry.
3. Post-implementation review found a default-switch TOCTOU (inputs validated
   against flow A, creation could bind flow B) → pin validated `flow_id`
   into `createOrder`; re-ran 17/17 PASS.

## 7. Not verified / limitations

- Full 202-test OrderFlow filter is NOT green (43 pre-existing env failures;
  only the 1 behavior delta was mine, fixed via the mandated contract update).
- No production run; no external frontend to integration-test (headless API;
  contracts documented for external clients).
- Concurrent-admin races covered by existing locks/guards, not by new
  stress tests (admin-only, low frequency; ambiguous states fail closed).
