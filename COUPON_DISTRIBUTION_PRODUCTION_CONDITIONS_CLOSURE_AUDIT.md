# COUPON_DISTRIBUTION_PRODUCTION_CONDITIONS_CLOSURE_AUDIT.md

> Pre-modification audit for the FINAL SHIP VALIDATION. Reports re-read (implementation-final, readiness-audit, readiness-final) and re-traced against current source. No code changed to produce this file.
> Baseline: Distribution suite 89 passed / 1 skipped (live broker) / 259 assertions; 8 regression tests incl. graceful-consume; independent review SAFE TO SHIP.
> Environment (verified this session): Windows, non-admin user, PHP 8.2, no docker daemon, no Erlang/RabbitMQ binaries, Laragon MySQL 8.4.3 + 9.1.0 binaries present but mysqld NOT running, winget available (admin-gated installs expected to fail).

## Condition 1 — Real RabbitMQ (OPEN, provisioning attempted)

- No broker at `127.0.0.1:5672` (connection refused, re-proven). No docker. No Erlang.
- Non-admin → machine-wide Erlang/RabbitMQ installers unusable. Only legitimate path left: portable OTP zip + RabbitMQ Windows zip (no install, no service), time-boxed. A hand-rolled PHP AMQP stub is REJECTED (worse than Fake; violates §4).
- If provisioning fails: CONDITION 1 = OPEN — INFRASTRUCTURE UNAVAILABLE, with exact close commands preserved (`coupon:rabbitmq-setup`, `RabbitMqIntegrationTest` live, manual matrix §7–§14).
- Code-level broker contract already verified (topology/confirms/ACK/retry/DLQ/budgets in `RabbitMqCouponEventTransport` + `ConsumeCouponQueueCommand`); Fake-level failure matrix green. What a real broker uniquely proves: TTL ordering, header round-trip, prefetch backpressure, redelivery identity, restart recovery.

## Condition 2 — Real MySQL (feasible: Laragon mysqld)

- Laragon `mysql-8.4.3-winx64\mysqld.exe` + data dir `mysql-8.4` exist. Attempt: start mysqld as current user, create ISOLATED database (never production data), point a dedicated phpunit config at it.
- On success: EXPLAIN candidate/state/outbox/log queries; concurrency (10× duplicate distribute, parallel same-user evaluation, parallel notification.requested, parallel publishers incl. stale-lease, counter reconciliation, parallel maybeFinishRun); deadlock watch; 1k/5k/10k load with p50/p95/p99 + query counts.
- Schema risk: migrations target the app DB; test DB gets `migrate --database` isolated run + full teardown afterwards. If mysqld cannot start (permissions/port): CONDITION 2 = OPEN with exact close commands.

## Condition 3 — Alerting (implementable now)

- Existing infra to reuse (to verify): `coupon:rabbitmq-health`, structured `Log::warning/error` coupon channels, scheduler, supervisor conf. No metrics platform assumed — smallest useful: `coupons:monitor` artisan command emitting pending-age/count, sweep staleness, DLQ growth, failed rows with WARNING/CRITICAL thresholds + exit codes, covered by tests, wired into scheduler + runbook. No third-party platform.

## Safety constraints for this pass

- No architecture change; no new infra dependency for app runtime; no production data touched; isolated test DB only, dropped afterwards; no broker stub passed off as real; pre-existing failures (`claimed_rule`, BusinessRules 404s) stay separated; delivery language stays at-least-once (§37).

## Verdict to beat

Close each condition with evidence or mark OPEN with the exact close command. Final verdict follows the evidence, not the effort.
