# Test Strategy — Group 1 (Public Catalog, /api/v1/general/*)

## Environment (VERIFIED)

- Target: `http://localhost:8000` (`php artisan serve --host=127.0.0.1 --port=8000`), `APP_ENV=local`.
- DB: MySQL 8.4.3 `catch` at 127.0.0.1:3306 (Docker Desktop-hosted container), schema 128 tables, content empty (users=2) at campaign start. Read-only access only. **No `php artisan test`** (this repo's PHPUnit suite wires `RefreshDatabase` against the live DB; documented data-loss incident).
- Load generator: Node.js v24 (native fetch). PHP lacks curl; k6/JMeter/Locust not installed.
- Cache: file cache (shared with rate limiter). `php artisan cache:clear` between cold/warm measurements (cache only; DB untouched).

## Safety limits (enforced by harness)

- Absolute base-URL guard: refuse to run unless base is 127.0.0.1/localhost.
- Hard caps: max concurrency 32; per-run duration ≤ 20 min; cumulative requests per stage ≤ 25k; timeout 8s per request; abort if error rate > 50% for 30s steady.
- All stages run from one IP → 429 above 60/min is **expected** for the by-design limiter; measured and reported separately (`non429` stats and `429` stats). Limiter is never disabled or bypassed.

## Scenario matrix

| Stage | Purpose | Rate | Duration | Acceptance |
|-------|---------|------|----------|------------|
| A SMOKE | connectivity + contract | 1 rps | 60s | all status codes within contract; zero 5xx |
| B BASELINE | latency floor per endpoint | ~2 rps | 120s | p95 recorded; envelope validated on every response |
| C TARGET | requested 400 req/min aggregate | 6.67 rps | 5 min | sustained; report non-429 share, p95 of allowed reqs |
| D SUSTAINED | long-run stability | 6.67 rps | 15 min | no memory growth signals (client-observable), no 5xx, no conn leaks; stable p95 drift |
| E BURST | bounded spike ×3 (20 rps) | 20 rps | 30s + recovery | post-burst recovers to baseline p95 ±30% in 60s |
| F STRESS | steps 8→10→12→16→20 rps | steps 60s each | ≤ 8 min | recorded sustainable ceiling; stop on abort threshold |
| G RECOVERY | back to 2 rps | 2 rps | 120s | error-rate 0, p95 near baseline |
| H COMBINED | realistic catalog mix: 55% lists, 20% slug items (incl. 404), 10% nav, 10% brands-products, 5% coupons | 6.67 rps | 5 min | total ≈ 400/min distributed; per-endpoint shares recorded |

- Cache modes: `warm` (default), plus one `cold` run for the two heaviest endpoints (nav-data, brands-products) after `cache:clear`.
- Every functional assertion validated concurrently with load (envelope + status) — semantic correctness during load, not only after.

## Functional/negative suite (before load)

Per endpoint: valid request; missing-slug 404; invalid/absent params; oversized/absurd values; unknown fields (mass-assignment probes are read-only GETs — server must ignore); auth paths for coupons (`/mine`, invalid bearer → 401); rate-limiter boundary (blast at 61+ rpm once to capture 429 envelope + Retry-After semantics, measured, then stop channel); channel/lang headers honored or rejected per middleware contract.
Repeat flaky-prone assertions 3× to detect nondeterminism.

## Acceptance criteria (PROPOSED — no project SLOs found)

- Correctness: 100% of executed requests conform to envelope; zero 5xx in normal stages; status contracts match inventory table.
- Performance: record p50/p90/p95/p99 per endpoint & stage; p95 ≤ 1200 ms and p99 ≤ 2500 ms measured on PHP built-in server (single-process dev server caveat recorded — not a production fidelity claim).
- Reliability: 0 timeouts/connection errors during C & D; burst recovery; no dependency break.
- Security: no stack traces/secrets in bodies; 401/404/403 boundaries hold under load; limiter enforces 60/min/IP with the documented envelope.

## Confirmed limits & assumptions

- 400 req/min sustained from one IP is not achievable by design (limiter 60/min/IP) → target stage measures limiter behavior + real (non-429) throughput share. This is a VERIFIED by-design outcome, not a performance failure.
- PHP built-in dev server ≠ production runtime (Render/Docker: php-cli + supervisor). Numbers are for local dev validation only.
- DB is empty; all "hot" path timings exercise empty-set queries + caches. Load results documented as "empty-baseline capacity", not production-fidelity.
- DB probes are strictly SELECT/COUNT (no writes, no migrations, no seeders, no `php artisan test`).
