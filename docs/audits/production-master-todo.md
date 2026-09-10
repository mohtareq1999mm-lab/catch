# Production Master TODO — E2E Validation Pass (2026-08-24)

Ordered by priority. Status: `[ ]` Not Started · `[~]` In Progress · `[x]` Done · `[!]` Blocked

---

TODO-E2E-001
Priority: ENV
Category: QUEUE/NOTIFICATION
Module: Auth OTP mail
Problem: OTP notification jobs retry indefinitely; external Resend API key invalid in local environment
Exact File: .env (MAIL/RESEND credentials); Marvel\Notifications\OneTimePasswordNotification
Required Action: provision valid mail API key per environment
Test Required: register → receive OTP → verify queue drains with 0 failed
Regression Required: no
Status: [!] Blocked — external credential

TODO-E2E-002
Priority: P3
Category: I18N
Module: Settings bootstrap
Problem: GET /general/settings returns 500 on migrated-but-unseeded database (settings row absent)
Exact File: app\Http\Resources\Setting path used by SettingController; SettingService::getSetting
Required Action: optional null-guard returning empty/default payload when settings row missing
Test Required: fresh migrate-only DB → GET /general/settings = 200
Regression Required: existing Settings suites must stay green
Status: [ ] Not Started (non-blocking; standard installs run seeders)

TODO-E2E-003
Priority: ARCH
Category: PAYMENT
Module: Refunds
Problem: refunds table has no migration; repository targets orders.customer_id/orders.amount which do not exist; GetSingleRefundResource expects legacy schema (GET /refunds/{id} always 500)
Files: packages/marvel/src/Database/Repositories/RefundRepository.php; packages/marvel/src/Http/Resources/GetSingleRefundResource.php; new refund-table migration required
Required Decision: define current refund data model against orders.user_id/total_price and order_products columns
Tests Required: create refund → persist → retrieve → approve/reject → DB assertions → authorization matrix
Regression Required: full refund + wallet + inventory listeners
Status: [!] Blocked — see error.md ERR-001

TODO-E2E-004
Priority: ARCH
Category: PAYMENT/GATEWAY
Module: bKash vendor wiring
Problem: vendor package registers public routes to never-published App controller; route:list reflection fails; activation would expose demo payment endpoints
Files: composer.json; vendor provider routes; missing app/Http/Controllers/BkashTokenizePaymentController.php
Required Decision: publish+integrate behind auth OR remove dependency / dont-discover
Tests Required: route:list green; gateway contract tests once integrated
Regression Required: payment suite
Status: [!] Blocked — see error.md ERR-002

TODO-E2E-005
Priority: ARCH
Category: PERM
Module: GraphQL surface
Problem: GraphQL mutators (Tag/FAQ/Attribute) execute controller actions bypassing constructor permission middleware
Files: packages/marvel/src/GraphQL/Mutations/*; packages/marvel/src/Shop.php
Required Action: adopt project-wide GraphQL authorization mechanism (inline checks or directives)
Tests Required: REST-vs-GraphQL permission parity tests per mutator
Regression Required: GraphQL query/mutation suite
Status: [ ] Not Started — see error.md ERR-003 (coupon approval bypass already closed inline)

TODO-E2E-006
Priority: ARCH
Category: PERM
Module: Permission registry naming
Problem: typo constants (VIEW_FlASH_SALE, *_NOTIFICATTIONS) and singular/plural duplicate slugs persisted in role assignments
Files: packages/marvel/src/Enums/Permission.php; database/seeders/PermissionSeeder.php
Required Action: coordinated slug normalization migration (rename in permissions + role sync)
Tests Required: RoleAndPermission suite + authorization matrix after migration
Regression Required: all permission-gated suites
Status: [ ] Not Started — see error.md ERR-004

TODO-E2E-007
Priority: P3
Category: SEARCH
Module: Meilisearch
Problem: search engine service not running in environment; index lifecycle unverifiable live
Files: scout/Meilisearch config
Required Action: provision Meilisearch in staging; run create→index→search→update→delete cycle
Tests Required: search integration suite
Status: [!] Blocked — environment

TODO-E2E-008
Priority: P3
Category: BROADCAST
Module: Pusher
Problem: real broadcasting unverified locally (external service)
Files: config/broadcasting.php, .env PUSHER_*
Required Action: staging credentials + end-to-end broadcast assertion on order events
Status: [!] Blocked — environment

---

Completed during this pass (reference): environment provisioning (MySQL audit DB + fresh migrations + Redis + database queue), 54-check E2E matrix green, export/import artifact validation, invoice PDF artifact validation, rate-limit proof, permission matrix proof.
