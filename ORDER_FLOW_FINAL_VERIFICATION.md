# ORDER FLOW FINAL VERIFICATION

Date (UTC): 2026-10-01. Baseline: all prior Order Flow sessions + this audit.

## Implemented (this audit)

1. Granular PATCH walk coverage: `StatusOptionsTest::test_granular_permissions_per_listed_status`
   (packed/shipped/out_for_delivery/delivered target perms, 403-then-200,
   terminal lock) + `test_cancelled_exit_with_target_permission`.
2. Canonical doc: `api-desc/order/flow.md` status-change section replaced
   with CURRENT runtime path; appended flow-system, definition, options,
   bulk-input, permission, and legacy-audit sections; pre-flow text kept
   under a HISTORICAL header; all other flows untouched.

## Already Correct (verified, not changed)

- Seeded flows == §5 exactly (`OrderFlowService::flowsSeed()`); catalog ==
  §6 verbatim (`ALL_STATUS_CODES`); §7 rules == `allowsFlowTransition()`
  line-for-line (successor/noop/completed/cancelled/failed/returned/terminal).
- Dynamic shipping_type (no enum, no allow-list, fail-closed); code ≠
  shipping_type responsibilities; seeds stay local/international only.
- Options/PATCH/granular/bulk/validator/ownership/localization subsystems.

## Modified

- `tests/Feature/OrderFlow/StatusOptionsTest.php` (+2 tests).
- `api-desc/order/flow.md` (status section + 6 new sections).

## Not Modified

Engines, sibling machines, migrations, seeders, routes, middleware,
checkout/transition runtime, `docs/api/*`.

## Tests

Suites: `OrderStatusFlowTest`, `OrderFlowMatrixTest`, `FlowInputsTest`,
`StatusOptionsTest` (+2), `DynamicShippingTypeTest`, `BulkFlowInputsTest`.
`php -l` clean on all touched files. **Execution: NOT RUN** — MySQL-only
project, no DB/Docker in this environment (sqlite unconfigured). CI must
run: `php artisan test --filter=OrderFlow` plus `AdminOrderTest` and coupon
regression suites. Results therefore UNVERIFIED at runtime; all claims
below are static-verification only.

## Failures

None observed (static). No test execution → no runtime failures to classify.

## Known Limitations

1. Tests unexecuted locally (environment, not code).
2. Two 422 shapes coexist (FormRequest errors object vs service envelope) —
   both pre-existing conventions, documented, not unified.
3. `{en,ar}` names are an intentional contract change (writes compatible).
4. Versioning deferred with in-flight guards; concurrent-admin unique
   collisions surface at DB constraints (integrity-safe).

## API Contract

Envelope `{status,message,success,data?}` throughout; bilingual
`{en,ar}` names; codes/keys/shipping_type untranslated; 401/403/404/422
semantics per `docs/order-flow/API.md` + `api-desc/order/flow.md`.

## Permission Matrix

General `update-order-status` (route) AND `change-order-status.<code>`
(asserted, 403) AND `payments.mark_paid` for unpaid→completed; compat
backfill documented; future codes auto-synced. Full table in
`docs/order-flow/PERMISSIONS.md` + `STATUS_MATRIX.md`.

## Status Mutation Audit

| Path | Type | Flow | Perm | Inputs | Safe | Action |
|---|---|---|---|---|---|---|
| `OrderService::changeOrderStatus` | user+system | yes | granular@entry | yes | yes | authority, kept |
| `OrderRepository::updateOrder` (+GraphQL) | user | yes | yes | n/a (no values path) | guarded | funneled, kept |
| `CancelUnpaidOrders` | system | mirror+history | policy | n/a | guarded | kept, documented |
| `RefundRepository` | system | untouched (legacy cols) | domain | n/a | yes | kept, separate domain |
| Callbacks/webhooks | system | yes | provider | yes | yes | kept |

## Final Verification Result

Static verification: PASS on all five passes (architecture, contract,
business, database, code-review). Runtime verification: PENDING CI
execution — explicitly not claimed. All §33 acceptance items hold except
test execution, which is reported, not hidden.
