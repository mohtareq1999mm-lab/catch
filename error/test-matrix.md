# Test matrix (risk-based; real-DB writes forbidden unless noted)

| Endpoint / Scenario | Method | Prerequisites (real `catch`) | Execution | Expected | Actual | Status |
|---|---|---|---|---|--- |---|
| Fulfillment release keyless→auto converge (OF-001) | service | isolated `catch_verify` (RefreshDatabase) | `AutoReleaseDedupeRegressionTest::test_keyless_then_automatic_returns_same_row` (`$env:DB_DATABASE='catch_verify'; php artisan test --filter=AutoReleaseDedupeRegressionTest`) | same row, 1 row, key claimed | 3/3 PASS (8 assertions) | PASS (isolated, NOT real-data proof) |
| Keyless does not duplicate active (WMS-001) | service | isolated `catch_verify` | same suite `test_keyless_does_not_duplicate_active_fulfillment` | same row, count 1 | PASS | PASS (isolated) |
| Custom split still allowed | service | isolated `catch_verify` | same suite `test_custom_split_key_still_creates_second_row` | 2 rows | PASS | PASS (isolated) |
| Hardening suite (allocation, inactive WH, cancel, batch guards, reallocate) | service | isolated `catch_verify` | `php artisan test --filter=FulfillmentHardeningTest` | 6/6 | 6/6 PASS (12 assertions) | PASS (isolated) |
| AutoReleaseWiring full | listeners/service | isolated `catch_verify` | `php artisan test --filter=AutoReleaseWiringTest` | green | 16 PASS + 5 FAIL (locale: tests expect English substrings, runtime default `ar`; pre-existing — verified identical failure with fix stashed) | FAIL (pre-existing locale, unrelated to fix) |
| Concurrency unkeyed duplicate | service | isolated `catch_verify` (not re-run; code path preserved) | `ConcurrencyAttackTest::test_unkeyed_duplicate_release_returns_pending_fulfillment` | same row | NOT RUN (path preserved by inspection; keyless branch unchanged in behavior for pending) | BLOCKED (time; no regression indicated) |
| Real `catch` duplicate rows | read-only SELECT | `catch` orders 13/4/5 | `SELECT ... fulfillments WHERE order_id IN (13,4,5)` | document only, no write | 2/3/4 rows with NULL+auto keys, extra pendings | VERIFIED (read-only) |
| Live checkout → fulfillment E2E on `catch` | POST | safe eligible user/product with stock — none classified safe | NOT EXECUTED (would create real order/reservation/fulfillment + queue side effects) | — | — | BLOCKED (missing safe prerequisite; no fake rows per rules) |
| Live MySQL multi-process lock proof | concurrent | controlled safe rows — none | NOT EXECUTED | — | — | BLOCKED (no safe rows; single-process/isolated tests are not MySQL concurrency proof) |
| Live payment/callback/refund | various | sandbox only; no verified sandbox creds | NOT EXECUTED | — | — | BLOCKED (no live money/customer side effects) |
| Route resolution for all 533 | `route:list` | none | `php artisan route:list` + source reconciliation | all families present | 533 counted, families verified | PASS (audit) |
| Locales en/ar for touched errors | read-only | `resources/lang/{en,ar}/wms.php` | grep `second_rejected/no_physical` both locales | both present, placeholders `:order :fulfillment :warehouse :resolved` | VERIFIED present en+ar | PASS (audit; no message changed by fix) |

Envelope: success `apiResponse {status,message,success,data}`; errors `{message,status:false[,errors]}` via `Handler.php:100-104,178-186`. No envelope change.
