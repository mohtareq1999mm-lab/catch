# STRIPE + PAYPAL — MYSQL (`catch`) FULL E2E VERIFICATION FINAL REPORT

Date (UTC): 2026-09-27. Repo: `D:\work\meem`. Stack: Laravel 10.30.1 / PHP 8.2 / MySQL 8.4.3.
No secret, token, card number, or credential value appears anywhere in this report (presence only).

---

## PHASE GATES

- PHASE 0 Discovery — STATUS: PASS. Payment implementation, DB config, test setup all inspected; no code changed during discovery.
- PHASE 1 MySQL `catch` verification — STATUS: PASS. `DB_CONNECTION=mysql`, `DB_DATABASE=catch`, `SELECT DATABASE()=catch`, server `8.4.3`.
- PHASE 2 Schema audit — STATUS: PASS. 126 tables; money columns mapped; `transactions.amount DECIMAL(10,2)` noted (became a finding in PHASE 5).
- PHASE 3/4 Seeder + seed — STATUS: PASS (after 2 seeder-only fixes for real unique constraints). Idempotent: second run creates zero rows.
- PHASE 5 Automated tests on MySQL — STATUS: PASS with 1 genuine BUG found and fixed (`transactions.amount` 2dp truncation). Final: 149/149.
- PHASE 6/7 Config verification — STATUS: PASS (verified ABSENT/disabled → real E2E correctly BLOCKED, not broken).
- PHASE 8–17 Real sandbox E2E — STATUS: BLOCKED (external: no canonical credentials). Attempted to the exact gate; documented below.
- PHASE 18 Concurrency — STATUS: PASS (MySQL InnoDB, suite + code).
- PHASE 19 Idempotency — STATUS: PASS (suite on MySQL + live synthetic replay on `catch`: `Processed` then `IdempotentReplay`).
- PHASE 20 Fail-closed — STATUS: PASS (suite on MySQL).
- PHASE 21 Enable/disable — STATUS: PASS (suite on MySQL + live `canInitiate`/`canVerify` probe on `catch`).
- PHASE 22 Regression — STATUS: PASS (MySQL suite 149/149; sqlite spot-check green; pre-existing unrelated failures unchanged).
- PHASE 23 Report — this file.

---

## 1. Executive Summary

- The Stripe + PayPal implementation is **correctly wired and proven on the real MySQL 8.4 engine**: payment suite **149/149 (1050 assertions) on MySQL**, plus currency/payment suites green, plus a live synthetic completion (`Processed` → `IdempotentReplay`) on `catch` itself.
- One **genuine MySQL-only application bug was found, fixed, and re-verified**: `transactions.amount DECIMAL(10,2)` silently rounded KWD 13.255 → 13.26 (SQLite masked it). Widened to `DECIMAL(10,3)` via new migration; suite went 148/149 → 149/149 on MySQL. The same truncation had shifted `PaymentRefundService` minor-unit math (13260 vs 13255) — now exact.
- **Real sandbox E2E remains BLOCKED (external, not a code failure)**: canonical `STRIPE_SECRET_KEY`, `PAYPAL_CLIENT_ID/SECRET` are absent and both gateways disabled, so the app fail-closes before any provider call. No real provider ID is fabricated anywhere in this report.
- `catch` data was preserved: zero pre-existing rows modified/deleted; only additive E2E marker rows were created.

## 2. Environment

- Laravel 10.30.1 (`php artisan --version`), PHP 8.2, MySQL 8.4.3, InnoDB.
- `.env`: `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=catch`, `DB_USERNAME=root`, `DB_PASSWORD` EMPTY. No `.env.testing`. `phpunit.xml` forces `sqlite :memory:` (intentional, safe default — left untouched).
- `phpunit.mysql.xml` (pre-existing, tracked) pointed at `meem_test:3307` (not present here) → retargeted to `catch_verify:3306` on the live server (documented change, §28).

## 3. Database Proof

Bootstrapped-Laravel probe (read-only):
- `Laravel effective DB = mysql`
- `Laravel effective database = catch`
- `SELECT DATABASE() = catch`
- `SELECT VERSION() = 8.4.3`
- 126 tables. Non-empty tables (35): users=3 (+4 E2E after seeding), settings=1, currencies=6 (AED,EUR,GBP,KWD,SAR,USD), currency_rates=6, coupons=20, promotions=20, roles/permissions intact, orders=0→4 (E2E only), transactions=0→1 (E2E only), products=0→1 (E2E only).
- `catch_verify` (new, empty, same server) hosts only the destructive-trait phpunit run — justification §5.

## 4. Existing Architecture

Live path (`app/`): `config/payment.php` → `OrderController::checkout` (amount = `roundForCurrency(order.total_price, forOrder)`) → `PaymentCheckoutHandler::handleOnlinePayment` (registry gate → adapter double-check → coupon reserve → `createInvoice` → `Transaction` row) → callback `checkoutCallback` / webhooks `PaymentWebhookController::stripe|paypal` (raw-body signature verification) → `gateway->verifyPayment()` → `PaymentCompletionService::completeLocked` (idempotency token → pending → amount×1000/currency/provider-ref checks → commit: txn paid, reservation commit, promotion finalize, `completed`). Stripe = Checkout Sessions; PayPal = Orders v2 CAPTURE with idempotent capture keys. Currency: catalog code → order snapshot (`currency_code = catalog_currency_code = catalog`) → resolver prefers stored snapshot → adapters. Legacy Marvel Stripe/PayPal (`packages/marvel`) has **zero routes and zero references from `app/`/`routes/`** — dead config; canonical keys remain the only live contract.

## 5. Seeder

Created `database/seeders/PaymentE2ESeeder.php` (NOT registered in `DatabaseSeeder`; run explicitly with `--class`). Marker `PAYMENT_E2E_20260927`. Creates via real `OrderCreationService`: 4 customers (one per scenario — required by `orders.idx_orders_user_pending_unique`), 1 product (slug `stripe-paypal-e2e-test-product`, stock 500), 1 address row (`address` table), 4 orders through the genuine creation flow (3× USD 100 stripe/paypal/generic-pending + 1× KWD 13.255 precision order with set/restore of catalog, finally-guaranteed). Idempotent (firstOrCreate + notes-marker lookup; verified: 2nd run = all-reuse, 0 inserts). During seeding, two real schema invariants were hit and respected (seeder-only fixes): `carts.user_id` UNIQUE and one-pending-order-per-user. Side discovery: `setCatalogCurrency()` needs tagged caches (fails on `CACHE_DRIVER=file`) — seeder overrides to `array` for its own process only (environment note, §27).

## 6. Automated Test Results (MySQL `catch_verify`)

- `tests/Feature/Payment` → **149 passed, 1050 assertions, 0 failed** (19 classes: StripeGatewayTest 16, PayPalGatewayTest 17, PaymentCompletionTest 14, CatalogCurrencyAuthorityTest, CurrencyPrecisionTest 7, GatewayDisableTest, GatewayRegistryTest, GatewaySettingsAdminTest, PaymentWebhookTest 10, PaymentSecurityTest 11, PaymentConcurrencyTest 9, PayPalCaptureIdempotencyTest 3, StripeFailureCorrelationTest 4, RefundCurrencyAndLedgerTest 4, ExternalRefundTest, BypassGateTest, Marvel* suites).
- `tests/Feature/Currency/PaymentCurrencyTest.php` → 5 passed. `GatewayCurrencySupportTest.php` → 5 passed.
- Initial MySQL run was 148/149 with the genuine `transactions.amount` bug (§27.1); after the migration fix, 149/149.
- sqlite regression spot-check (`CurrencyPrecisionTest` + full-suite history from prior verification): green; migration is driver-guarded (`return` unless mysql).
- Full `tests/Feature/Currency` dir was NOT re-run on MySQL (pre-existing sqlite failures there are currency-rate-format/audit-job issues unrelated to payments; payment-relevant files above are green on MySQL).

## 7. Stripe Configuration (presence only)

`STRIPE_SECRET_KEY`: configured NO (absent). `STRIPE_WEBHOOK_SECRET`: NO. `STRIPE_ENABLED`: false. `STRIPE_SUPPORTED_CURRENCIES`: USD,EUR,KWD,SAR,AED (loaded). `isConfigured()`: NO. Mode: none (no key). Consumed-by check: YES — `StripeGateway::isConfigured()` reads `payment.gateways.stripe.secret_key`, `client()` builds `StripeClient(secret)`. Legacy `STRIPE_API_KEY`/`STRIPE_WEBHOOK_SECRET_KEY`: SET in `.env` but **dead** (only `packages/marvel/config/shop.php` + omnipay reference them; no live code path reads them — verified by grep over `app/` and `routes/`).

## 8. Stripe Sandbox Authentication — BLOCKED (EXTERNAL)

No canonical secret exists in this environment, so no authentication was attempted (nothing to authenticate with; nothing printed). The application gate was executed live on `catch`: `canInitiate(stripe, online, USD|KWD)` → `NO:disabled`; `canVerify(stripe)` → `YES` (in-flight verifiable by design).

## 9. Stripe Real E2E — BLOCKED (EXTERNAL)

Flow stops at the registry gate (422, no transaction row — exactly the covered behavior `GatewayRegistryTest: stripe paypal known but not initiable by default`). No Checkout Session, payment, callback, or completion executed against Stripe. Synthetic completion on `catch` (order 4, clearly-fake ref `SYNTH-E2E-4-001`, NOT Stripe): `completeLocked` → `Processed` (order `completed`, `payment-success`, txn `paid`, `paid_at` set, token set) → replay → `IdempotentReplay`. Labeled SYNTHETIC, not provider evidence.

## 10. Stripe DB Correlation

| Field | Application | Provider | Match |
|---|---|---|---|
| All fields | (no real payment initiated) | BLOCKED — NO REAL PROVIDER ID | — |

## 11. PayPal Configuration (presence only)

`PAYPAL_CLIENT_ID`: NO. `PAYPAL_CLIENT_SECRET`: NO. `PAYPAL_MODE`: EMPTY (`.env` `PAYPAL_MODE=` empty overrides default; adapter coerces to `sandbox`). `PAYPAL_WEBHOOK_ID`: EMPTY (webhook endpoint correctly returns 503 until set — covered by test). `PAYPAL_ENABLED`: false. Supported: USD,EUR. `isConfigured()`: NO. Legacy `PAYPAL_*` keys: all EMPTY.

## 12. PayPal Sandbox Authentication — BLOCKED (EXTERNAL)

No credentials → no OAuth attempted, no token requested/printed. Live gate on `catch`: `canInitiate(paypal, …)` → `NO:disabled`; `canVerify(paypal)` → `YES`.

## 13. PayPal Real E2E — BLOCKED (EXTERNAL)

Same gate as §9. No PayPal order, buyer approval, capture, or webhook executed. Idempotent-capture race (`ORDER_ALREADY_CAPTURED` collapse) proven at suite level on MySQL (`PayPalCaptureIdempotencyTest` 3/3).

## 14. PayPal DB Correlation

| Field | Application | Provider | Match |
|---|---|---|---|
| All fields | (no real payment initiated) | BLOCKED — NO REAL PROVIDER ID | — |

## 15. Currency Verification — PROVEN (MySQL)

Catalog (USD) → snapshot → resolver → adapters, all on MySQL: seeded orders 1/3/4 resolve USD; order 5 (created under KWD) still resolves **KWD while catalog is USD** (live `catch` probe) — old-order immutability proven on the real database. `OrderCreateRequest` contains no `currency`/`amount`/`total_price` fields (client cannot submit pricing); `PaymentSecurityTest::checkout ignores forged pricing` passes on MySQL.

## 16. Precision Verification — PROVEN (MySQL, incl. bug fix)

- `toMinorUnits(13.255,KWD)=13255`, `formatForGateway→'13.255'`, USD 1000/JPY 10 — executed.
- Live `catch` row: order 5 `total_price=13.255 currency=KWD catalog=KWD`.
- `transactions.amount` is now `DECIMAL(10,3)` (was `(10,2)` → stored 13.26; bug §27.1). Post-fix suite asserts 13.255 exactly on MySQL.
- Note: `transactions.amount` is a storage column only; completion compares provider amount vs `orders.total_price DECIMAL(8,3)` — completion was never at risk; refund math and record fidelity were.

## 17. Security Verification — PROVEN (MySQL suite)

`PaymentSecurityTest` 11/11 on MySQL: forged payment IDs (400), forged Stripe signatures (400, untouched), PayPal missing/bad signatures (rejected), webhook replay (single completion), invalid gateway (422, no rows), user-B isolation, mark-paid permission boundary, forged pricing ignored, no secrets in responses, tampered verify fail-closed.

## 18. Fail-Closed Verification — PROVEN (MySQL suite)

`PaymentCompletionTest` mismatches (amount/currency/provider-ref → failed, never completed), adapter-level closed failures (Stripe intent cross-check, PayPal capture-total check), malformed responses, unknown-order paid callback fails SAFE. Real-provider mismatch NOT exercised (blocked, §8/§12).

## 19. Idempotency — PROVEN (MySQL suite + live `catch`)

Suites on MySQL: replay, dual-callback, callback+webhook race, webhook event-id dedupe, PayPal double-capture — single completion each. Live `catch`: `Processed` → `IdempotentReplay`, token SET, single `paid` row, order completed exactly once.

## 20. Callback — PROVEN (synthetic + suite; real delivery NOT executed)

`checkoutCallback`/`checkoutErrorCallback` paths fully covered on MySQL (`PaymentCompletionTest` incl. web-redirect and mobile-JSON variants). Real provider redirect to local callback NOT executed (no public URL, no credentials) — no real delivery claimed.

## 21. Webhook — PROVEN (synthetic; real delivery NOT executed)

Stripe (`checkout.session.completed` → complete; `payment_intent.payment_failed` → session resolution → mark failed; unknown → 200 ignored) and PayPal (`CAPTURE.COMPLETED` → complete; DENIED → failed; REFUNDED/REVERSED → ledger note; unverified → 401/400) covered on MySQL (`PaymentWebhookTest` 10/10, `StripeFailureCorrelationTest` 4/4). These are HMAC/transmission-verified **synthetic** requests, not real provider deliveries — stated as such.

## 22. Concurrency — PROVEN (MySQL InnoDB)

`PaymentConcurrencyTest` 9/9 on MySQL 8.4 InnoDB (dual callbacks, callback+webhook race, exactly-once inventory commit). Tables `orders`/`transactions` confirmed InnoDB on `catch`.

## 23. Gateway Disable/Enable — PROVEN

Suites on MySQL (`GatewayDisableTest`, `GatewaySettingsAdminTest`, `GatewayRegistryTest`): admin `{enabled}` persists (allowlisted keys only, secrets never stored/returned), disabled ⇒ new initiation 422 with zero rows, `canVerify` stays true after disable (`stripe completes after gateway disabled`). Live `catch` probe: both gateways `canInitiate=NO:disabled`, `canVerify=YES`.

## 24. Inventory/Promotion/Coupon Side Effects — PROVEN (suite; no-op on bare seeded orders)

Canonical commit order (txn→paid, reservation commit, promotion finalize, `completed`) executed on `catch` for the synthetic completion of item-less order 4 (no reservation/promotion existed → clean no-ops; status transitions + `paid_at` verified). With-items paths (single inventory commit, coupon reserve/release/refusal, promotion finalization) covered on MySQL by `PaymentConcurrencyTest`/`PaymentCompletionTest`. No stock/inventory tables were touched on `catch` (product still 500/0 reserved).

## 25. MySQL Locking — VERIFIED

`lockForUpdate()` used at every payment boundary: `OrderController` callbacks (txn+order), `PaymentWebhookController::completeFromProviderRef` (txn+order, incl. pre-lock replay ack), `PaymentRefundService` (txn+order, documented global lock ordering shared with callbacks/webhooks), `PaymentCompletionService` contract (caller holds both locks), `OrderReservationService`/`InventoryRestoreService` (product/variant rows), `GatewaySettingsService`/`CurrencyService` (settings row). No SQLite-only locking assumption found — the one behavioral divergence found (decimal storage) was fixed, not worked around.

## 26. Regression Results

- MySQL `catch_verify`: `tests/Feature/Payment` 149/149 (1050 assertions); `PaymentCurrencyTest` 5/5; `GatewayCurrencySupportTest` 5/5.
- sqlite: `CurrencyPrecisionTest` 7/7 post-migration (guard verified).
- Unrelated pre-existing failures (NOT touched, NOT caused by this task): `tests/Feature/Currency` sqlite run — 9 failures (`0.221000` vs `0.221` format assertions, `LogActivityJob` type error); working-tree OrderFlow/coupon-doc modifications pre-date this session.
- `catch` preserved: pre-existing users(3)/settings(1)/currencies(6)/coupons(20)/promotions(20) untouched; only E2E marker rows added (4 users, 1 product, 1 address, 4 orders, 1 txn).

## 27. Problems Found

1. **[BUG — found & fixed]** `transactions.amount DECIMAL(10,2)` truncated 3dp payments on MySQL (13.255→13.26), shifting refund minor-unit math (13260 vs 13255). SQLite masked it. Fixed by migration `2026_09_27_000001_widen_transactions_amount_to_3dp` (SAFE widening, reversible with noted 3dp-rounding caveat on downgrade). Verified: failing test → pass; full suite 149/149 on MySQL; sqlite still green.
2. **[ENVIRONMENT — noted, not changed]** `.env` `CACHE_DRIVER=file` lacks tag support, so `CurrencyService::setCatalogCurrency()` throws in this env (admin catalog-switch endpoint affected, pre-existing). Seeder works around it process-locally (`array` driver); tests use `array` via phpunit config. Recommend switching local cache to `redis`/`array` or documenting — no change made (out of payment scope).
3. **[CONFIGURATION — dead keys]** Legacy `STRIPE_API_KEY`/`STRIPE_WEBHOOK_SECRET_KEY` are SET but consumed by nothing live (unrouted Marvel config). False "Stripe has keys" impression — the exact claim that started this task. Recommend removal/documentation; not renamed/removed (needs explicit approval per policy).
4. **[CONFIGURATION — empty overrides]** `.env` `PAYPAL_MODE=` (empty) and `PAYPAL_WEBHOOK_ID=` (empty) override defaults with empty strings; adapter coerces mode safely and webhook correctly 503s. Set explicitly when configuring sandbox.
5. **[EXTERNAL BLOCKER]** No canonical Stripe/PayPal credentials in this environment → real sandbox E2E impossible. Not a code failure.
6. **[PRE-EXISTING, unrelated]** Currency-suite sqlite failures (§26) and working-tree OrderFlow changes — untouched.

## 28. Changes Made

- CREATED `database/seeders/PaymentE2ESeeder.php` (idempotent E2E seeder; standalone, not registered in `DatabaseSeeder`).
- CREATED `database/migrations/2026_09_27_000001_widen_transactions_amount_to_3dp.php` (the §27.1 fix; driver-guarded; applied to `catch`; auto-applied on `catch_verify` via RefreshDatabase).
- MODIFIED `phpunit.mysql.xml` (pre-existing tracked file): retargeted `meem_test:3307` → `catch_verify:3306` so the MySQL suite runs against the verified-live server instead of a nonexistent DB.
- MODIFIED database state only: ran two pre-existing pending migrations on `catch` (incl. `2026_09_29 widen_order_status_enum`, not mine) + seeded E2E marker rows. No pre-existing row modified or deleted.
- Application business logic: ZERO changes. No gateway/architecture/refactor touch.

## 29. Final Evidence Matrix

| Verification | Stripe | PayPal |
|---|---|---|
| Source | PROVEN | PROVEN |
| MySQL execution | PROVEN (149-suite) | PROVEN (149-suite) |
| Configuration | BLOCKED (secret absent, disabled) | BLOCKED (id/secret absent, disabled) |
| Authentication | BLOCKED | BLOCKED |
| Real payment creation | BLOCKED | BLOCKED |
| Real provider payment | BLOCKED | BLOCKED |
| Callback | PROVEN synthetic / real NOT EXECUTED | PROVEN synthetic / real NOT EXECUTED |
| Real webhook | NOT EXECUTED | NOT EXECUTED |
| Amount | PROVEN (math + MySQL storage) / provider echo BLOCKED | PROVEN / provider echo BLOCKED |
| Currency | PROVEN | PROVEN |
| Precision | PROVEN (13255 / 13.255 + column fix) | PROVEN ('13.255' + column fix) |
| DB persistence | PROVEN synthetic on `catch` | PROVEN synthetic on `catch` |
| Completion | PROVEN synthetic on `catch` | PROVEN synthetic on `catch` |
| Idempotency | PROVEN (suite + live replay) | PROVEN (suite) |
| Concurrency | PROVEN (InnoDB suite) | PROVEN (InnoDB suite) |
| Fail-closed | PROVEN (suite) / real NOT EXECUTED | PROVEN (suite) / real NOT EXECUTED |
| Refund | PROVEN (suite, math fixed) | PROVEN (suite, math fixed) |

## 30. Final Verdict

- **STRIPE: BLOCKED — EXTERNAL REQUIREMENT** (needs `STRIPE_SECRET_KEY` test key + `STRIPE_WEBHOOK_SECRET` + `STRIPE_ENABLED=true`; code + MySQL behavior PROVEN).
- **PAYPAL: BLOCKED — EXTERNAL REQUIREMENT** (needs `PAYPAL_CLIENT_ID/SECRET` sandbox + `PAYPAL_MODE=sandbox` + `PAYPAL_WEBHOOK_ID` + `PAYPAL_ENABLED=true`; code + MySQL behavior PROVEN).
- Everything executable without provider credentials is **PROVEN on MySQL**, including one genuine bug found and fixed. Nothing mocked was labeled real; no provider ID was fabricated.

### Git state (this session vs pre-existing dirt)

- FILES CREATED (this session): `database/seeders/PaymentE2ESeeder.php`, `database/migrations/2026_09_27_000001_widen_transactions_amount_to_3dp.php`, `docs/verification/STRIPE_PAYPAL_MYSQL_CATCH_E2E_FINAL_REPORT.md` (this file).
- FILES MODIFIED (this session): `phpunit.mysql.xml` (DB retarget only).
- Pre-existing working-tree modifications NOT mine (OrderFlow files, coupon docs, `CancelUnpaidOrders`, `OrderService`, `2026_09_28` migration, `DatabaseSeeder`, Marvel Order files, `nul`, `.phpunit.cache`): left untouched.
- `catch_verify` database (new, empty, same MySQL server): hosts the destructive-trait suite run; justified because `RefreshDatabase` (`migrate:fresh`) would otherwise drop all 126 tables of `catch`, violating the preserve-data rule.
