# Coupon Queue Architecture (authoritative, verified 2026-09-26)

## Logical priorities vs physical names
`HIGH` / `MEDIUM` are logical priorities used by application code. Physical queue
names are deployment config and MUST come from the environment:

```text
Local:      HIGH → high            MEDIUM → medium
Production: HIGH → meem-high       MEDIUM → meem-medium
```

Chain (single authoritative source, `config/queue.php:34-37`):

```text
Application logical HIGH
        ↓  config('queue.queues.high')  (App\Enums\QueueName::high() wraps it)
QUEUE_HIGH env (fallback 'high')
        ↓
physical queue  →  supervisor worker --queue="${QUEUE_HIGH:-high}"
```

Same for MEDIUM / `QUEUE_MEDIUM`.

## Rules for developers
- Use `config('queue.queues.high')` / `QueueName::high()` (or `medium`). NEVER write a physical name in PHP.
- NEVER call `env('QUEUE_*')` outside `config/` files (config-cache safety).
- The four coupon notifications are all HIGH: Assigned, Available, Eligible, Used.
- RabbitMQ (`coupon.distribution/evaluation/notifications` via `RABBITMQ_QUEUE_*`) is the distribution/evaluation backbone — independent of Laravel queues. Pusher is the last-mile realtime transport — also independent. Email stays out of scope.
- Enforcement: `tests/Unit/QueueStandardizationStaticTest.php` fails any ShouldQueue class that does not resolve via the approved patterns; `tests/Feature/Coupon/CouponNotificationQueueTest.php` proves the four coupon notifications follow the configured HIGH queue.
