# PAYMENT_SYSTEM_FINAL_AUDIT.md

> Forensic post-implementation audit. Evidence-first: every claim cites a command, file:line,
> or measured output. No commit / reset / destructive git action was performed during this audit.

## 1. Executive Summary

The payment system safely handles real money within the verified scope. This audit re-proved:
catalog-currency authority (code + tests), exponent-correct money (KWD×1000 measured),
registry initiation-vs-verify (§46 green), canonical completion, signed webhooks, validated
refunds, dedicated payment permissions incl. the legacy status path, and idempotency.
Two REAL defects were found and fixed with measured proof: (F-AUDIT-01) refund/callback
MySQL 1213 deadlock from inverted lock order — reproduced with parallel processes, eliminated
by unifying to Transaction→Order, re-proven with both sides committing; (F-AUDIT-02) Marvel
refund-approval double-spend race + ledger blindness vs the admin cap — atomic claim +
cross-path cap + shared ledger note added with tests. Live MyFatoorah sandbox connectivity +
key acceptance were verified with a read-only probe (no charge created). Stripe/PayPal live
runs remain credential-blocked. Full payment suite: **149 passed**.

## 2. Repository/Git Forensics

- Branch `main`, HEAD `70f0bd8`; `git log` shows coupon-work commits on top; payment work is
  uncommitted (worktree + staged index from a `git add -A` by an unknown actor — DO NOT commit
  per instructions).
- `git status`: 184 entries. **71 deletions, ALL `*.md` docs, ALL worktree-side** (nothing
  committed: `git log --diff-filter=D` empty; every file recoverable from HEAD, spot-verified
  `git show HEAD:COMMERCE_PAYMENT_ARCHITECTURE.md`). Zero non-md deletions — no source lost.
- Classification: deleted = `COMMERCE_*`, `COUPON_*`, `AREA_IN_*`, `GOVERNORATE_*`,
  `PHASE3_*`, `QATAR_*` (other workstreams) + untracked `PAYMENT_FLOW_DISCOVERY_REPORT.md`
  (previous turn; substance preserved in `PAYMENT_DISCOVERY_REPORT.md` + this audit).
  Intent: **UNKNOWN** (bulk root-doc cleanup pattern, no commit/message/owner) →
  **BLOCKED — INTENT UNKNOWN**: not restored (could conflict with another session), not
  ignored (reported here). Safe restore if the user confirms: `git checkout -- <paths>`.
- `stash@{0}` (subagent backup): only early-phase files (resolver-era handler/factory/gateway/
  contract/payment.php/currency tests) — fully superseded by the worktree; contains no docs and
  nothing missing. Left untouched.
- Payment migrations added by this program: **none** (`git status -- database/migrations`
  shows only other workstreams' files). No destructive migration risk.
- Payment file inventory verified present: all 15 services/gateways, 3 payment controllers,
  2 admin controllers, contract, exception, 18 Payment test files.

## 3. Payment Architecture Verification

`Contract → Registry → Adapter → Provider` re-verified by grep: adapters are instantiated only
inside themselves (lazy fallbacks) and resolved via registry/factory; `createInvoice` has exactly
one caller (`PaymentCheckoutHandler:96`); `refund` exactly one admin caller
(`PaymentRefundService:173`) plus the Marvel approval path (audited §10). Fast-shipping checkout
delegates to the same gated handler. Webhook controller resolves adapters through the factory
(verify path, enabled-agnostic). No initiation bypass exists. Completion is centralized:
callbacks, error-callbacks, webhooks, zero-value, and mark-paid all converge on
`PaymentCompletionService::completeLocked` or `OrderService::changeOrderStatus`.

## 4. Currency Verification

- `app/Services/Payment/*`: **zero** `base_currency`/`getBaseCode`/`getEffectiveCode` references
  (grep-verified). Gateway dir: 4 hardcoded-code hits, all classified legitimate (ISO-4217
  exponent tables; srmklive allowlist mirror; null-safe fallbacks that fail closed downstream —
  Stripe `currency:null` and PayPal client-default analyses in audit notes).
- Chain proven by test (re-run green): `CatalogCurrencyAuthorityTest` 3/3 (KWD catalog + USD
  preference → KWD everywhere; KWD→SAR switch: old stays/new moves; USD rejection + resolver
  fallbacks). `CurrencyPrecisionTest` 7/7 (KWD 13.255 snapshot→verify→complete; Stripe 13255;
  PayPal '13.255').

## 5. Gateway Verification

- MyFatoorah: factory seam (`factory->make` delegation) green; refund requires positive
  `RefundStatus` (code-read); callback/error-callback share canonical completion; **sandbox
  connectivity + key acceptance VERIFIED LIVE** via read-only `GetPaymentStatus(Key=0)` probe:
  HTTPS round-trip to `apitest.myfatoorah.com` returned provider business error
  `400 "No data match the provided values"` (log-timed 2026-09-26) — transport/auth OK, no
  charge created. Full matrix still needs payer interaction: NOT VERIFIED.
- Stripe (SDK v13.1.0 confirmed via composer): Checkout Sessions, ISO-4217 minor units,
  PI cross-check, idempotent capture n/a (sessions), succeeded-only refunds, HMAC webhooks +
  PI→session resolution, event dedupe. All mocked: `StripeGatewayTest` 16/16,
  `StripeFailureCorrelationTest`, webhook tests. Live: **BLOCKED — no keys** (`.env` Stripe
  secret empty, verified name-only).
- PayPal (SDK 3.0.19 confirmed): CAPTURE intent, capture-on-verify with `PayPal-Request-Id`
  + already-captured recovery, COMPLETED-only success/refunds, SDK webhook verify. All mocked:
  17/17 + capture-idempotency tests. Live: **BLOCKED — no credentials** (sandbox client id
  empty, verified name-only).
- Legacy `packages/marvel/src/Payment/*`: **deprecated-dead for money movement** — reachable
  only via `Marvel\Facades\Payment` from card-vault code (`PaymentMethodController`,
  no txn/order writes — grep-verified) and an unrouted `WebHookController` (no routes in
  `Rest/Routes.php`); old `OrderController` with payment traits is unrouted (only
  `Order\OrderController` is routed). GraphQL `PaymentIntentMutator` reaches card-saving only.
  Not deleted per no-rebuild rule.

## 6. Admin Settings Verification

`GatewaySettingsService` read/write allowlist re-verified by code read; admin GET/PUT routes
present (`route:list`); secrets excluded (test-asserted); enable/disable semantics proven by
`GatewayDisableTest` 10/10 (§46: create-enabled → disable → verify completes → new 422).
Permissions `payments.mark_paid/refund/verify/reconcile` present in `PermissionSeeder` master +
staff lists (grep-verified lines 95-98, 132-138, 335-341); deploy = `db:seed --class=PermissionSeeder`.

## 7. Security Verification

`PaymentSecurityTest` 11/11 re-run green (full suite). F-1 legacy PATCH path re-proven:
`MarvelStatusAuthorityTest` 4/4 (unpaid+perm-less → 422 untouched; with perm → 200; paid →
200; non-completing → 200). Callback paymentId allowlist, mass-assignment, IDOR, leakage
scans all covered. Marvel refund approval requires super_admin/shop-role (`hasPermission`,
code-read) — second-path authorization intact.

## 8. Idempotency Verification

Token-primary + status-secondary under `lockForUpdate` (code-read in callbacks/webhooks/
completion); ledger replay keys (refunds); event-id dedupe (webhooks, cap 20); refund ledger
cap 100. Refund-claim (`PENDING→PROCESSING`) added for Marvel approvals (F-AUDIT-02).

## 9. Concurrency Verification

- **REAL parallel proof (new, MySQL/InnoDB, two OS processes)**: identical callback-prologue
  scripts raced on one txn row → exactly one `WINNER`, one `LOSER`; token `race-token`
  persisted. Fixtures created and fully removed afterwards; probe scripts deleted.
- **Deadlock proof + fix (F-AUDIT-01)**: opposing lock-order scripts reproduced
  `SQLSTATE[40001] 1213 Deadlock` (one side failed, logged verbatim). Root cause: manual
  `refund()` locked Order→Transaction while callbacks lock Transaction→Order. Fix: refund
  now locks Transaction→Order (validation semantics byte-identical — same checks, same errors,
  same order). Re-ran opposing scripts under unified order → **both committed**. Refund suites
  green (`GatewayDisableTest` 10/10, `RefundCurrencyAndLedgerTest` 4/4).
- Residual: provider `refund()` network call still executes inside the refund DB transaction
  (locks held ≤30s; pre-existing design, kept to avoid restructuring risk); true load/burst
  testing NOT run.

## 10. Refund Verification

- Admin path: validation matrix, ledger cap, idempotent replay, disabled-gateway block,
  post-catalog-switch currency auth — green.
- Marvel approval path (F-AUDIT-02 findings, all addressed): (a) double-approval race →
  atomic `PENDING→PROCESSING` claim, concurrent loser 400s; (b) cross-path over-refund →
  ledger-remaining cap check at claim (rejects + releases claim); (c) ledger blindness →
  `noteProviderRefund` shared note (idempotent by event, over-cap flagged not hidden);
  (d) gateway-failure reset → PENDING (retryable). Tests: `MarvelRefundInterplayTest` 4/4.
- **Pre-existing marketplace-schema gap (new finding, NOT introduced here)**: migrated schema
  lacks `orders.parent_id` and `wallets`/`balances` tables (confirmed absent in migrations AND
  live dev DB), so the Marvel approval *transaction* cannot complete on current schema —
  approvals fail safely (409/pending, provider outcome preserved in ledger) but staff retries
  re-invoke the provider. Recommendation: create the marketplace refund schema migration
  (separate workstream decision) — until then, online-gateway refunds should go through the
  admin endpoint. No silent money loss in any path (every provider movement is ledger-noted).
- Policy note: disabled gateway blocks NEW admin refunds (new outbound call) but never
  in-flight verification — verified by code read + test.

## 11. Regression Verification

- `tests/Feature/Payment`: **149 passed (1050 assertions)** — includes 4 new interplay tests.
- Adjacent (re-run this audit where affected): refund suites above; `AdminOrderTest` 58/58 and
  `CartOrderLifecycleTest` 38/38 (both updated to the hardened permission contract in the
  implementation phase); `MarvelStatusReachabilityTest` flipped to 422 as its own docblock
  required. Currency dir: 190 passed + same 5 pre-existing `LogActivityJob`-drift fails.
- Pre-existing failures elsewhere (hand-built legacy schemas, view drift) unchanged in kind;
  none in payment logic. Legacy suites encoding the old F-1 contract were migrated, not hidden.

## 12. Database Verification

Live MySQL `catch` inspected read-only (`db:table`): `transactions.amount` DECIMAL, `currency`
string, `idempotency_key` UNIQUE (+ one redundant secondary index — harmless),
`gateway_transaction_id` NOT unique (known/documented), FK `order_id→orders` cascade;
`orders` has full currency snapshot decimals, payment/fulfillment states, `paid_at`,
consumed flags. No payment migration added → nothing to audit for up/down/data-compat.

## 13. Environment Verification

`.env.example` contains all required keys (verified by name): MyFatoorah key/URL/currencies +
bypass flag; Stripe key/webhook/currencies; PayPal id/secret/webhook/currencies. Legacy Marvel
keys annotated (still read by `shop.php`). Live `.env` (names only): MyFatoorah key SET +
apitest host; Stripe/PayPal secrets EMPTY → matches BLOCKED claims. No secret values printed
anywhere in this audit; gateway allowlists + secret scan green.

## 14. Live Provider Verification

- MyFatoorah: connectivity + key acceptance LIVE-VERIFIED (read-only probe, §5). Full payment
  matrix: NOT VERIFIED (needs payer interaction).
- Stripe / PayPal: **BLOCKED — LIVE PROVIDER TEST** (no credentials). All mocked evidence listed §5.

## 15. Known Limitations

True load/burst untested; provider call inside refund txn (lock hold); Marvel approval needs
marketplace schema migration (separate decision); legacy `Payment/*` deprecated-not-removed;
`charge.refunded` intentionally unhandled; intra-handler disable window documented fail-safe.

## 16. Remaining Risks

Residual concurrency: unified lock order removes the measured deadlock; unknown third-party
lockers (analytics reads don't lock; shipment locks order-only) present no cycle by inspection.
Residual financial: Marvel-approval retry on broken schema re-invokes provider (ledger-visible,
capped post-migration); admin over-cap impossible (fail-closed + flagged recording).

## 17. Files Changed During This Audit

- `app/Services/Payment/PaymentRefundService.php`: lock order Transaction→Order (F-AUDIT-01);
  `+ledgerRemaining()`, `+noteProviderRefund(..., $allowOverCap)` (F-AUDIT-02).
- `packages/marvel/src/Http/Controllers/RefundController.php`: approval claim, cap check,
  failure reset, ledger note (F-AUDIT-02). Only Marvel file touched — justified by proven
  double-spend race; no workflow redesign.
- `tests/Feature/Payment/MarvelRefundInterplayTest.php`: 4 new tests.
- Docs: this audit rewritten; `PAYMENT_TEST_MATRIX.md`/`MASTER_TODO` counts → 149 (below).

## 18. Exact Commands Executed

`git status/branch/log/diff --cached --stat/stash list/ls-files/show HEAD:<doc>`; route lists
(checkout/webhooks/admin-payment/refunds); `composer show stripe/stripe-php, srmklive/paypal`;
`php artisan test tests/Feature/Payment` (149✓) + targeted suites (all cited inline);
`db:show`, `db:table transactions/orders/users`; tinker probes (MyFatoorah GetPaymentStatus
dummy → provider 400; race fixtures; cleanup verified `txn=0 order=0 user=0`); parallel
`race.php` (WINNER/LOSER), `dl_a/dl_b` (1213 deadlock), `dl_c/dl_b` (both commit); `.env`
name-only presence checks; `php -l` on touched files. Temp scripts deleted.

## 19. Test Results

Payment 149/149; Security 11/11 (in-dir); Currency authority/precision/disable/external-refund/
refund suites green as cited; adjacent suites green (§11). Pre-existing fails unchanged.
New: `MarvelRefundInterplayTest` 4/4. Empirical: parallel claim race 1-winner/1-loser;
pre-fix deadlock 1213 reproduced; post-fix both-commit.

## 20. Final Status

**PASS WITH DOCUMENTED NON-BLOCKING LIMITATIONS**

No code-level payment defect remains (two found → fixed → measured). Live Stripe/PayPal runs
and payer-driven MyFatoorah matrix are credential-blocked and explicitly listed; no such claim
is made. Docs restored-or-current except the unrelated bulk doc deletions, which are reported
as BLOCKED — INTENT UNKNOWN with a safe-restore command, not silently dropped.
